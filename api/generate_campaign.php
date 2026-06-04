<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_response(['error'=>'POST required'],405); }

$input = json_decode(file_get_contents('php://input'),true) ?? [];
$jd_text = trim((string)($input['jd_text'] ?? ''));
if (strlen($jd_text) < 30) json_response(['error'=>'Job description too short — paste at least 30 characters.'],400);
if (strlen($jd_text) > 15000) json_response(['error'=>'Job description too long — max 15,000 characters.'],400);

$credential = defined('GEMINI_API_KEY') && GEMINI_API_KEY ? GEMINI_API_KEY : '';
$model = defined('GEMINI_MODEL') && GEMINI_MODEL ? GEMINI_MODEL : 'gemini-2.0-flash';
if (!$credential) json_response(['error'=>'GEMINI_API_KEY not configured in .env'],500);

function jdai_b64url(string $d): string { return rtrim(strtr(base64_encode($d),'+/','-_'),'='); }

function jdai_service_token(string $json_path): ?string {
    if (!is_readable($json_path)) return null;
    $sa = json_decode(file_get_contents($json_path),true);
    if (!isset($sa['client_email'],$sa['private_key'])) return null;
    $now = time();
    $h = jdai_b64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));
    $p = jdai_b64url(json_encode(['iss'=>$sa['client_email'],'scope'=>'https://www.googleapis.com/auth/cloud-platform','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600]));
    $pkey = openssl_pkey_get_private($sa['private_key']);
    if (!$pkey) return null;
    $sig = '';
    if (!openssl_sign("$h.$p",$sig,$pkey,'SHA256')) return null;
    $jwt = "$h.$p.".jdai_b64url($sig);
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $resp=curl_exec($ch);curl_close($ch);
    return json_decode($resp,true)['access_token']??null;
}

function jdai_gemini_call(string $prompt,string $credential,string $model): ?string {
    $payload=['contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],'generationConfig'=>['temperature'=>0.3,'maxOutputTokens'=>16384]];
    if (is_readable($credential)) {
        $token=jdai_service_token($credential);
        if (!$token) return null;
        $sa=json_decode(file_get_contents($credential),true);
        $project=defined('VERTEX_AI_PROJECT')&&VERTEX_AI_PROJECT?VERTEX_AI_PROJECT:($sa['project_id']??'');
        $location=defined('VERTEX_AI_LOCATION')&&VERTEX_AI_LOCATION?VERTEX_AI_LOCATION:'us-central1';
        $vmodel=defined('VERTEX_AI_MODEL')&&VERTEX_AI_MODEL?VERTEX_AI_MODEL:$model;
        if (!$project) return null;
        $url="https://{$location}-aiplatform.googleapis.com/v1/projects/".rawurlencode($project)."/locations/".rawurlencode($location)."/publishers/google/models/".rawurlencode($vmodel).":generateContent";
        $headers=['Content-Type: application/json','Authorization: Bearer '.$token];
    } else {
        $url="https://generativelanguage.googleapis.com/v1beta/models/".rawurlencode($model).":generateContent?key=".urlencode($credential);
        $headers=['Content-Type: application/json'];
    }
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $resp=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if ($code!==200) return null;
    $data=json_decode($resp,true);
    return trim((string)($data['candidates'][0]['content']['parts'][0]['text']??''))?:null;
}

$prompt = "You are an expert HR recruiter and AI interview campaign designer.\n\nAnalyze this Job Description and create a complete interview campaign. Return ONLY valid JSON, no markdown fences, no explanation.\n\nJOB DESCRIPTION:\n---\n{$jd_text}\n---\n\nReturn this exact JSON structure:\n{\n  \"campaign_name\": \"Role Name – Batch/Year (max 60 chars)\",\n  \"job_role\": \"Exact job title from JD (max 60 chars)\",\n  \"description\": \"2-3 sentence candidate-facing summary (max 280 chars)\",\n  \"passing_score\": 70,\n  \"num_questions\": 10,\n  \"language\": \"english\",\n  \"questions\": [\n    {\n      \"parameter\": \"snake_case_key\",\n      \"parameter_label\": \"Human Readable Label\",\n      \"question_type\": \"mcq\",\n      \"weight\": 10,\n      \"max_marks\": 10,\n      \"question_text\": \"Question text here\",\n      \"options\": [\"Option A text\", \"Option B text\", \"Option C text\", \"Option D text\"],\n      \"correct_answer\": \"Option A text\",\n      \"ideal_answer_hint\": \"Why the correct answer is correct\",\n      \"order_no\": 1\n    }\n  ],\n  \"application_fields\": [\n    {\n      \"field_label\": \"GitHub Profile URL\",\n      \"field_key\": \"github_url\",\n      \"field_type\": \"url\",\n      \"placeholder\": \"https://github.com/username\",\n      \"is_required\": 0,\n      \"order_no\": 1,\n      \"options_json\": null\n    }\n  ]\n}\n\nQUESTION TYPE RULES (MANDATORY MIX — exactly 10 questions, do not deviate):\n- Q1: question_type=\"mcq\" — role-knowledge MCQ with exactly 4 options and 1 correct_answer\n- Q2: question_type=\"mcq\" — technical MCQ specific to this role with exactly 4 options and 1 correct_answer\n- Q3: question_type=\"mcq\" — another domain/tool MCQ with exactly 4 options and 1 correct_answer\n- Q4: question_type=\"mcq\" — situational/scenario MCQ with exactly 4 options and 1 correct_answer\n- Q5: question_type=\"short_answer\" — short written response (candidate types 2-4 sentences)\n- Q6: question_type=\"short_answer\" — experience or scenario-based written question\n- Q7: question_type=\"short_answer\" — problem-solving or approach-based written question\n- Q8: question_type=\"voice_note\" — open-ended spoken question (60-90 second voice answer)\n- Q9: question_type=\"voice_note\" — behavioural or situational spoken question\n- Q10: question_type=\"voice_note\" — motivation/culture-fit spoken question\n- For question_type=\"mcq\": MUST include \"options\" array with exactly 4 strings and \"correct_answer\" matching one option exactly\n- For question_type=\"short_answer\" or \"voice_note\": omit \"options\" and \"correct_answer\" fields\n\nOTHER STRICT RULES:\n1. Exactly 10 questions. All weights must sum to exactly 100. max_marks equals weight for each question. Distribute weights evenly (10 each) or vary slightly per importance.\n2. passing_score: 60 for fresher/entry, 65 for junior, 70 for mid-level, 75 for senior/lead\n3. language: english by default; hinglish if India context; hindi if JD is in Hindi\n4. application_fields must ONLY contain role-specific custom fields not in the standard form. DO NOT include: name, full_name, first_name, last_name, phone, email, city, dob, salutation, college, experience_years, years_exp, current_ctc, current_salary, expected_ctc, expected_salary, relocate, tech_skills, soft_skills, resume, portfolio. Generate ONLY 2-5 role-specific fields unique to this job (e.g., github_url, notice_period, driving_license, certifications, tools_used, shift_preference).\n5. For dropdown field_type: set options_json as array of strings\n6. All field_key values must be unique snake_case, never matching the excluded list above";

$raw = jdai_gemini_call($prompt, $credential, $model);
if (!$raw) json_response(['error'=>'AI generation failed — check Vertex AI credentials and model access.'],500);

$clean = preg_replace('/```json|```/','',$raw);
$clean = trim($clean);
$s=strpos($clean,'{');$e=strrpos($clean,'}');
if($s!==false&&$e!==false)$clean=substr($clean,$s,$e-$s+1);
$data=json_decode($clean,true);

if (!is_array($data)||empty($data['campaign_name'])||empty($data['questions'])) {
    json_response(['error'=>'AI returned unexpected format. Please try again.','raw'=>substr($raw,0,400)],500);
}

// Normalize questions + fix weights to sum to 100
$type_map=['mcq'=>'dropdown','short_answer'=>'textarea','voice_note'=>'audio'];
$questions=[];$total_w=0;
foreach(($data['questions']??[]) as $i=>$q){
    $w=max(5,min(50,(int)($q['weight']??15)));
    $raw_type=strtolower(trim($q['question_type']??'voice_note'));
    $db_type=$type_map[$raw_type]??'audio';
    $options=($db_type==='dropdown'&&is_array($q['options']??null))?array_values(array_map('strval',$q['options'])):[];
    $correct=($db_type==='dropdown')?trim($q['correct_answer']??''):'';
    $questions[]=['parameter'=>preg_replace('/[^a-z0-9_]/','_',strtolower(trim($q['parameter']??'q'.($i+1)))),
        'parameter_label'=>trim($q['parameter_label']??'Question '.($i+1)),
        'weight'=>$w,'max_marks'=>$w,
        'question_text'=>trim($q['question_text']??''),'ideal_answer_hint'=>trim($q['ideal_answer_hint']??''),
        'question_type'=>$db_type,'raw_type'=>$raw_type,
        'options'=>$options,'correct_answer'=>$correct,'order_no'=>$i+1];
    $total_w+=$w;
}
if ($total_w!==100&&$total_w>0&&count($questions)>0) {
    $factor=100/$total_w;$running=0;
    foreach($questions as $i=>&$q){
        if($i<count($questions)-1){$q['weight']=$q['max_marks']=max(5,(int)round($q['weight']*$factor));$running+=$q['weight'];}
        else{$q['weight']=$q['max_marks']=max(5,100-$running);}
    }unset($q);
}

// Normalize application fields
$valid_types=['text','textarea','number','decimal','date','dropdown','multi_select','checkbox','email','phone','url','file'];
$app_fields=[];
foreach(($data['application_fields']??[]) as $i=>$f){
    $ft=in_array($f['field_type']??'',$valid_types)?$f['field_type']:'text';
    $app_fields[]=['field_label'=>trim($f['field_label']??'Field '.($i+1)),
        'field_key'=>preg_replace('/[^a-z0-9_]/','_',strtolower(trim($f['field_key']??'field_'.($i+1)))),
        'field_type'=>$ft,'placeholder'=>trim($f['placeholder']??''),
        'is_required'=>(int)($f['is_required']??0),'order_no'=>$i+1,
        'options_json'=>is_array($f['options_json']??null)?$f['options_json']:null];
}

json_response(['success'=>true,
    'campaign_name'=>trim($data['campaign_name']??''),
    'job_role'=>trim($data['job_role']??''),
    'description'=>trim($data['description']??''),
    'passing_score'=>max(50,min(90,(int)($data['passing_score']??70))),
    'num_questions'=>count($questions),
    'language'=>in_array($data['language']??'',['english','hinglish','hindi'])?$data['language']:'english',
    'questions'=>$questions,'application_fields'=>$app_fields,
]);
