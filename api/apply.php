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

if (!s($data,'first_name')||!s($data,'phone')||!s($data,'email')) {
    echo json_encode(['success'=>false,'error'=>'Missing required fields']); exit;
}
if (!filter_var(s($data,'email'),FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success'=>false,'error'=>'Invalid email']); exit;
}

$campaign_id = (int)($data['campaign_id'] ?? 0);
$campaign    = $campaign_id ? db_fetch_one("SELECT * FROM campaigns WHERE id=?",[$campaign_id],'i') : null;
$org_id      = $campaign ? (int)$campaign['org_id'] : 1;
$email       = s($data,'email');

if ($campaign_id) {
    $dup = db_fetch_one("SELECT id FROM candidates WHERE email=? AND campaign_id=?",[$email,$campaign_id],'si');
    if ($dup) { echo json_encode(['success'=>false,'error'=>'Already applied.','duplicate'=>true]); exit; }
}

$udir = __DIR__.'/../uploads/';
@mkdir($udir.'resumes',0755,true);
@mkdir($udir.'videos',0755,true);

$resume_path = null;
$video_path  = null;

if (!empty($data['resume_base64']) && !empty($data['resume_name'])) {
    $dec = base64_decode($data['resume_base64'],true);
    if ($dec && strlen($dec)<=20*1024*1024) {
        $ext = strtolower(pathinfo($data['resume_name'],PATHINFO_EXTENSION))?:'pdf';
        $fn  = 'resume_'.bin2hex(random_bytes(5)).'_'.time().'.'.$ext;
        if (file_put_contents($udir.'resumes/'.$fn,$dec)!==false)
            $resume_path = 'uploads/resumes/'.$fn;
    }
}
if (s($data,'video_option')==='link') {
    $video_path = s($data,'video_link');
} elseif (s($data,'video_option')==='upload' && !empty($data['video_base64'])) {
    $dec = base64_decode($data['video_base64'],true);
    if ($dec && strlen($dec)<=50*1024*1024) {
        $ext = strtolower(pathinfo($data['video_name']??'v.mp4',PATHINFO_EXTENSION))?:'mp4';
        $fn  = 'video_'.bin2hex(random_bytes(5)).'_'.time().'.'.$ext;
        if (file_put_contents($udir.'videos/'.$fn,$dec)!==false)
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

    // 37 explicit columns (skipping: id, call_id, created_at, updated_at = auto)
    $sql = "INSERT INTO candidates (
        org_id, campaign_id, name, phone, email, city,
        experience_years, current_ctc, expected_ctc, source,
        unique_token, status,
        salutation, dob, relocate, relocate_time, phone_code,
        college, engagement_type, english_level, industry,
        exp_type, exp_desc, current_salary, expected_salary,
        tenure, joining_date, flex_hours, laptop, internet,
        commute, tech_skills, soft_skills,
        resume_path, video_path, portfolio, ai_test_willing
    ) VALUES (
        ?,?,?,?,?,?,
        ?,?,?,?,
        ?,'pending',
        ?,?,?,?,?,
        ?,?,?,?,
        ?,?,?,?,
        ?,?,?,?,?,?,
        ?,?,?,
        ?,?,?,?
    )";

    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: '.$db->error);

    // 37 placeholders, types string = 37 chars
    // ii ssss d sss s  sssss ssss ssss ssssss sss ssss
    //  2    4 1   3 1    5    4    4    6      3    4  = 37
    $types = 'iissss' . 'dsss' . 's' . 'sssss' . 'ssss' . 'ssss' . 'ssssss' . 'sss' . 'ssss';

    $p1  = $org_id;
    $p2  = $campaign_id;
    $p3  = $name;
    $p4  = s($data,'phone');
    $p5  = $email;
    $p6  = s($data,'city');
    $p7  = $exp;
    $p8  = s($data,'current_salary');   // current_ctc
    $p9  = s($data,'expected_salary');  // expected_ctc
    $p10 = s($data,'source');
    $p11 = $tok;                        // unique_token
    // status = 'pending' hardcoded
    $p12 = s($data,'salutation');
    $p13 = $dob;
    $p14 = s($data,'relocate');
    $p15 = s($data,'relocate_time');
    $p16 = s($data,'phone_code');
    $p17 = s($data,'college');
    $p18 = s($data,'engagement_type');
    $p19 = s($data,'english_level');
    $p20 = s($data,'industry');
    $p21 = s($data,'exp_type');
    $p22 = s($data,'exp_desc');
    $p23 = s($data,'current_salary');
    $p24 = s($data,'expected_salary');
    $p25 = s($data,'tenure');
    $p26 = $jd;
    $p27 = s($data,'flex_hours');
    $p28 = s($data,'laptop');
    $p29 = s($data,'internet');
    $p30 = s($data,'commute');
    $p31 = s($data,'tech_skills');
    $p32 = s($data,'soft_skills');
    $p33 = $resume_path;
    $p34 = $video_path;
    $p35 = s($data,'portfolio');
    $p36 = s($data,'ai_test_willing');

    $stmt->bind_param($types,
        $p1,$p2,$p3,$p4,$p5,$p6,
        $p7,$p8,$p9,$p10,
        $p11,
        $p12,$p13,$p14,$p15,$p16,
        $p17,$p18,$p19,$p20,
        $p21,$p22,$p23,$p24,
        $p25,$p26,$p27,$p28,$p29,$p30,
        $p31,$p32,
        $p33,$p34,$p35,$p36
    );

    if (!$stmt->execute()) throw new Exception('Execute failed: '.$stmt->error);
    $cid = $db->insert_id;
    $stmt->close();

    if ($campaign_id && $cid) {
        $lg = $db->prepare("INSERT INTO outreach_log (campaign_id,candidate_id,action,notes,created_at) VALUES (?,?,'applied','Self-applied via public form',NOW())");
        if ($lg) { $lg->bind_param('ii',$campaign_id,$cid); $lg->execute(); $lg->close(); }
    }

    echo json_encode(['success'=>true,'candidate_id'=>$cid,'interview_token'=>$campaign['unique_token']??null]);

} catch(Exception $e) {
    error_log('[apply] '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
