<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';

$user = require_auth();
$org_id  = (int)$user['org_id'];
$user_id = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_response(['error'=>'POST required'],405); }

$input = json_decode(file_get_contents('php://input'),true) ?? [];

$name         = trim((string)($input['campaign_name'] ?? ''));
$job_role     = trim((string)($input['job_role'] ?? ''));
$description  = trim((string)($input['description'] ?? ''));
$passing_score= max(50,min(100,(int)($input['passing_score']??70)));
$language     = in_array($input['language']??'',['english','hinglish','hindi'])?$input['language']:'english';
$el_agent_id  = trim((string)($input['el_agent_id']??''));
$questions    = (array)($input['questions']??[]);
$app_fields   = (array)($input['application_fields']??[]);
$num_questions= max(1,count($questions));

if (!$name || !$job_role) json_response(['error'=>'Campaign name and job role are required'],400);
if (count($questions) < 1) json_response(['error'=>'At least one question is required'],400);

$share_token = bin2hex(random_bytes(12));
$db = get_db();
$db->begin_transaction();
try {
    // Insert campaign
    $campaign_id = db_insert(
        "INSERT INTO campaigns (org_id,created_by,name,job_role,description,share_token,integration_type,integration_endpoint,el_agent_id,passing_score,max_duration_minutes,num_questions,language,status) VALUES (?,?,?,?,?,?,'none','',?,?,15,?,?,'draft')",
        [$org_id,$user_id,$name,$job_role,$description,$share_token,$el_agent_id,$passing_score,$num_questions,$language],
        'iisssssiis'
    );
    if (!$campaign_id) throw new RuntimeException('Campaign insert failed');

    // Insert questions
    $valid_q_types=['text','textarea','number','decimal','date','dropdown','multi_select','rating','file','audio','video','hyperlink'];
    $type_map=['mcq'=>'dropdown','short_answer'=>'textarea','voice_note'=>'audio'];
    foreach ($questions as $i => $q) {
        $q_text = trim($q['question_text']??'');
        if (!$q_text) continue;
        $param   = preg_replace('/[^a-z0-9_]/','_',strtolower(trim($q['parameter']??'q'.($i+1))));
        $plabel  = trim($q['parameter_label']??'Question '.($i+1));
        $weight  = max(1,min(50,(int)($q['weight']??15)));
        $marks   = max(1,min(50,(int)($q['max_marks']??$weight)));
        $hint    = trim($q['ideal_answer_hint']??'');
        // Map AI type aliases to DB enum values
        $raw_type = strtolower(trim($q['question_type']??'audio'));
        $qtype    = $type_map[$raw_type] ?? (in_array($raw_type,$valid_q_types) ? $raw_type : 'audio');
        $order    = (int)($q['order_no']??$i+1);
        // Build options_json for MCQ/dropdown
        $options_json = null;
        if ($qtype === 'dropdown' && !empty($q['options']) && is_array($q['options'])) {
            $clean_opts = array_values(array_filter(array_map('strval',$q['options'])));
            if ($clean_opts) $options_json = json_encode($clean_opts);
        }
        // Prepend correct answer to hint for MCQ so AI scorer knows
        if ($qtype === 'dropdown' && !empty($q['correct_answer'])) {
            $correct = trim($q['correct_answer']);
            $hint = $hint ? "Correct: $correct. $hint" : "Correct answer: $correct";
        }
        db_insert(
            "INSERT INTO questions (campaign_id,parameter,parameter_label,weight,max_marks,question_text,ideal_answer_hint,question_type,options_json,is_required,order_no) VALUES (?,?,?,?,?,?,?,?,?,1,?)",
            [$campaign_id,$param,$plabel,$weight,$marks,$q_text,$hint,$qtype,$options_json,$order],
            'issiissssi'
        );
    }

    // Insert application fields (only enabled ones, skip standard keys already in apply.php)
    $standard_keys = ['salutation','first_name','last_name','full_name','name','candidate_name','dob','date_of_birth','birth_date','city','current_city','location','current_location','hometown','relocate','relocate_time','phone_code','phone','phone_number','mobile','mobile_number','contact','email','email_id','email_address','college','source','engagement_type','english_level','years_exp','experience_years','years_of_experience','exp_years','experience','total_experience','industry','exp_type','exp_desc','current_salary','current_ctc','ctc','expected_salary','expected_ctc','tenure','joining_date','flex_hours','laptop','internet','commute','resume','photo','video_option','video_link','video_file','portfolio','ai_test_willing','declaration_confirmation'];
    $valid_ftypes=['text','textarea','number','decimal','date','dropdown','multi_select','checkbox','email','phone','url','file'];
    $field_order = 1;
    foreach ($app_fields as $f) {
        if (empty($f['enabled'])) continue;
        $flabel   = trim($f['field_label']??'');
        $fkey     = preg_replace('/[^a-z0-9_]/','_',strtolower(trim($f['field_key']??'')));
        if (in_array($fkey, $standard_keys, true)) continue; // skip duplicate of standard form
        $ftype    = in_array($f['field_type']??'',$valid_ftypes)?$f['field_type']:'text';
        $fph      = trim($f['placeholder']??'');
        $fhelp    = trim($f['help_text']??'');
        $freq     = (int)($f['is_required']??0);
        $opts     = is_array($f['options_json']??null)?json_encode($f['options_json']):null;
        if (!$flabel||!$fkey) continue;
        db_insert(
            "INSERT INTO application_fields (campaign_id,field_key,field_label,field_type,placeholder,help_text,options_json,is_required,order_no,is_active) VALUES (?,?,?,?,?,?,?,?,?,1)",
            [$campaign_id,$fkey,$flabel,$ftype,$fph,$fhelp,$opts,$freq,$field_order],
            'issssssii'
        );
        $field_order++;
    }

    $db->commit();
    audit_log($org_id,$user_id,'campaign',$campaign_id,'ai_created_from_jd',['name'=>$name,'questions'=>count($questions),'fields'=>$field_order-1]);
    json_response(['success'=>true,'campaign_id'=>$campaign_id,'redirect'=>BASE_URL.'/campaigns?action=questions&id='.$campaign_id.'&msg=jd_created']);
} catch (Throwable $e) {
    $db->rollback();
    json_response(['error'=>'Save failed: '.$e->getMessage()],500);
}
