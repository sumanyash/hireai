<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }

require_once __DIR__ . '/../includes/db.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { echo json_encode(['success'=>false,'error'=>'Invalid JSON']); exit; }

function s($d,$k){ return isset($d[$k]) ? trim((string)$d[$k]) : ''; }

if (!s($data,'phone') || !s($data,'email')) {
    echo json_encode(['success'=>false,'error'=>'Missing required fields']); exit;
}

$campaign_id = (int)($data['campaign_id'] ?? 0);
$campaign    = $campaign_id ? db_fetch_one("SELECT * FROM campaigns WHERE id=?",[$campaign_id],'i') : null;
$org_id      = $campaign ? (int)$campaign['org_id'] : 1;
$email       = s($data,'email');

if ($campaign_id) {
    $dup = db_fetch_one("SELECT id FROM candidates WHERE email=? AND campaign_id=?",[$email,$campaign_id],'si');
    if ($dup) { echo json_encode(['success'=>false,'error'=>'Already applied.','duplicate'=>true]); exit; }
}

// Save resume
$udir = __DIR__.'/../uploads/';
@mkdir($udir.'resumes',0755,true);
@mkdir($udir.'videos',0755,true);
$resume_path = '';
$video_path  = '';

if (!empty($data['resume_base64']) && !empty($data['resume_name'])) {
    $dec = base64_decode($data['resume_base64'],true);
    if ($dec && strlen($dec) <= 20*1024*1024) {
        $ext = strtolower(pathinfo($data['resume_name'],PATHINFO_EXTENSION)) ?: 'pdf';
        $fn  = 'resume_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
        if (file_put_contents($udir.'resumes/'.$fn, $dec) !== false)
            $resume_path = 'uploads/resumes/'.$fn;
    }
}
if (s($data,'video_option') === 'link') {
    $video_path = s($data,'video_link');
} elseif (s($data,'video_option') === 'upload' && !empty($data['video_base64'])) {
    $dec = base64_decode($data['video_base64'],true);
    if ($dec && strlen($dec) <= 50*1024*1024) {
        $ext = strtolower(pathinfo($data['video_name'] ?? 'v.mp4',PATHINFO_EXTENSION)) ?: 'mp4';
        $fn  = 'video_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
        if (file_put_contents($udir.'videos/'.$fn, $dec) !== false)
            $video_path = 'uploads/videos/'.$fn;
    }
}

$ymap = ['Fresher'=>0,'0.5 Years'=>0.5,'1–2 Years'=>1.5,'2–5 Years'=>3.5,'5-7 Years'=>6,'7-10 Years'=>8.5,'10-15 Years'=>12,'15+ Years'=>15];
$exp  = (float)($ymap[s($data,'years_exp')] ?? 0);
$name = trim(s($data,'salutation').' '.s($data,'first_name').' '.s($data,'last_name'));
$tok  = bin2hex(random_bytes(16));
$dob  = s($data,'dob')          ?: null;
$jd   = s($data,'joining_date') ?: null;

try {
    $db = get_db();

    // Count columns manually to verify: use individual INSERT per column
    $cols = [
        'org_id','campaign_id','name','phone','email','city',
        'experience_years','current_ctc','expected_ctc','source',
        'unique_token','status',
        'salutation','dob','relocate','relocate_time','phone_code',
        'college','engagement_type','english_level','industry',
        'exp_type','exp_desc','current_salary','expected_salary',
        'tenure','joining_date','flex_hours','laptop','internet',
        'commute','tech_skills','soft_skills',
        'resume_path','video_path','portfolio','ai_test_willing'
    ];

    $vals = [
        $org_id,                        // org_id          int
        $campaign_id,                   // campaign_id     int
        $name,                          // name
        s($data,'phone'),               // phone
        $email,                         // email
        s($data,'city'),                // city
        $exp,                           // experience_years decimal
        s($data,'current_salary'),      // current_ctc
        s($data,'expected_salary'),     // expected_ctc
        s($data,'source'),              // source
        $tok,                           // unique_token
        'pending',                      // status
        s($data,'salutation'),          // salutation
        $dob,                           // dob
        s($data,'relocate'),            // relocate
        s($data,'relocate_time'),       // relocate_time
        s($data,'phone_code'),          // phone_code
        s($data,'college'),             // college
        s($data,'engagement_type'),     // engagement_type
        s($data,'english_level'),       // english_level
        s($data,'industry'),            // industry
        s($data,'exp_type'),            // exp_type
        s($data,'exp_desc'),            // exp_desc
        s($data,'current_salary'),      // current_salary
        s($data,'expected_salary'),     // expected_salary
        s($data,'tenure'),              // tenure
        $jd,                            // joining_date
        s($data,'flex_hours'),          // flex_hours
        s($data,'laptop'),              // laptop
        s($data,'internet'),            // internet
        s($data,'commute'),             // commute
        s($data,'tech_skills'),         // tech_skills
        s($data,'soft_skills'),         // soft_skills
        $resume_path,                   // resume_path
        $video_path,                    // video_path
        s($data,'portfolio'),           // portfolio
        s($data,'ai_test_willing'),     // ai_test_willing
    ];

    // Build query dynamically — cols and placeholders always match
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $col_list     = implode(',', $cols);
    $sql = "INSERT INTO candidates ($col_list) VALUES ($placeholders)";

    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception('Prepare: '.$db->error);

    // Build types string dynamically
    $types = '';
    foreach ($vals as $v) {
        if (is_int($v))   $types .= 'i';
        elseif (is_float($v)) $types .= 'd';
        else              $types .= 's';
    }

    $stmt->bind_param($types, ...$vals);
    if (!$stmt->execute()) throw new Exception('Execute: '.$stmt->error);
    $cid = $db->insert_id;
    $stmt->close();

    // Outreach log
    if ($campaign_id && $cid) {
        $lg = $db->prepare("INSERT INTO outreach_log (campaign_id,candidate_id,action,notes,created_at) VALUES (?,?,'applied','Self-applied via public form',NOW())");
        if ($lg) { $lg->bind_param('ii',$campaign_id,$cid); $lg->execute(); $lg->close(); }
    }

    echo json_encode(['success'=>true,'candidate_id'=>$cid,'interview_token'=>$campaign['unique_token']??null]);

} catch(Exception $e) {
    error_log('[apply] '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
