<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit;
}

require_once __DIR__ . '/../includes/db.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(['success'=>false,'error'=>'Invalid JSON']); exit;
}

function s(array $d, string $k): string {
    return isset($d[$k]) ? trim((string)$d[$k]) : '';
}

// Validate required
$errs = [];
if (s($data,'first_name')==='') $errs[]='first_name';
if (s($data,'phone')==='')      $errs[]='phone';
if (s($data,'email')==='')      $errs[]='email';
if ($errs) { echo json_encode(['success'=>false,'error'=>'Missing: '.implode(', ',$errs)]); exit; }
if (!filter_var(s($data,'email'),FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success'=>false,'error'=>'Invalid email']); exit;
}

// Campaign
$campaign_id = (int)($data['campaign_id'] ?? 0);
$campaign    = null;
if ($campaign_id) {
    $campaign = db_fetch_one("SELECT id,org_id,job_role,unique_token FROM campaigns WHERE id=?",[$campaign_id],'i');
}
$org_id = $campaign ? (int)$campaign['org_id'] : 1;

// Duplicate check
$email = s($data,'email');
if ($campaign_id) {
    $dup = db_fetch_one("SELECT id FROM candidates WHERE email=? AND campaign_id=?",[$email,$campaign_id],'si');
    if ($dup) {
        echo json_encode(['success'=>false,'error'=>'You have already applied for this position.','duplicate'=>true]); exit;
    }
}

// Save uploaded files
$upload_dir = __DIR__ . '/../uploads/';
if (!is_dir($upload_dir.'resumes')) mkdir($upload_dir.'resumes', 0755, true);
if (!is_dir($upload_dir.'videos'))  mkdir($upload_dir.'videos',  0755, true);

function save_b64(string $b64, string $name, string $dir, string $pfx, int $max_mb=20): ?string {
    $dec = base64_decode($b64, true);
    if (!$dec || strlen($dec) > $max_mb*1024*1024) return null;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
    $fn  = $pfx.'_'.bin2hex(random_bytes(5)).'_'.time().'.'.$ext;
    return file_put_contents($dir.$fn, $dec)!==false ? 'uploads/'.$dir.$fn : null;
}

$resume_path = null;
$video_path  = null;

if (!empty($data['resume_base64']) && !empty($data['resume_name'])) {
    $dec = base64_decode($data['resume_base64'], true);
    if ($dec && strlen($dec) <= 20*1024*1024) {
        $ext = strtolower(pathinfo($data['resume_name'],PATHINFO_EXTENSION)) ?: 'pdf';
        $fn  = 'resume_'.bin2hex(random_bytes(5)).'_'.time().'.'.$ext;
        if (file_put_contents($upload_dir.'resumes/'.$fn, $dec) !== false) {
            $resume_path = 'uploads/resumes/'.$fn;
        }
    }
}

if (s($data,'video_option')==='upload' && !empty($data['video_base64']) && !empty($data['video_name'])) {
    $dec = base64_decode($data['video_base64'], true);
    if ($dec && strlen($dec) <= 50*1024*1024) {
        $ext = strtolower(pathinfo($data['video_name'],PATHINFO_EXTENSION)) ?: 'mp4';
        $fn  = 'video_'.bin2hex(random_bytes(5)).'_'.time().'.'.$ext;
        if (file_put_contents($upload_dir.'videos/'.$fn, $dec) !== false) {
            $video_path = 'uploads/videos/'.$fn;
        }
    }
} elseif (s($data,'video_option')==='link') {
    $video_path = s($data,'video_link');
}

// Map experience
$years_map = ['Fresher'=>0,'0.5 Years'=>0.5,'1–2 Years'=>1.5,'2–5 Years'=>3.5,'5-7 Years'=>6,'7-10 Years'=>8.5,'10-15 Years'=>12,'15+ Years'=>15];
$exp_dec = (float)($years_map[s($data,'years_exp')] ?? 0);

$full_name     = trim(s($data,'salutation').' '.s($data,'first_name').' '.s($data,'last_name'));
$unique_token  = bin2hex(random_bytes(16));
$dob           = s($data,'dob')          ?: null;
$joining_date  = s($data,'joining_date') ?: null;

try {
    $db = get_db();

    // Only insert columns that EXIST in the table
    $sql = "INSERT INTO candidates (
        org_id, campaign_id, name, phone, email, city,
        experience_years, current_ctc, expected_ctc, source,
        unique_token, status,
        salutation, dob, relocate, relocate_time, phone_code,
        college, engagement_type, english_level, industry,
        exp_type, exp_desc, current_salary, expected_salary,
        tenure, joining_date, flex_hours, laptop, internet,
        commute, tech_skills, soft_skills, resume_path,
        video_path, portfolio, ai_test_willing
    ) VALUES (
        ?,?,?,?,?,?,
        ?,?,?,?,
        ?,'pending',
        ?,?,?,?,?,
        ?,?,?,?,
        ?,?,?,?,
        ?,?,?,?,?,?,
        ?,?,?,?,
        ?,?,?
    )";

    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception('Prepare: '.$db->error);

    // types: ii s s s s  d s s s  s  s s s s s  s s s s  s s s s  s s s s s s  s s s s  s s s
    // count: 2  4        1 1 1 1   4            4         4         6            4        3  = 37 params
    $types = 'iissss' . 'dsss' . 's' . 'sssss' . 'ssss' . 'ssss' . 'ssssss' . 'ssss' . 'sss';

    $stmt->bind_param($types,
        $org_id,                        // i
        $campaign_id,                   // i
        $full_name,                     // s
        s($data,'phone'),               // s
        $email,                         // s
        s($data,'city'),                // s
        $exp_dec,                       // d
        s($data,'current_salary'),      // s  current_ctc
        s($data,'expected_salary'),     // s  expected_ctc
        s($data,'source'),              // s
        $unique_token,                  // s
        s($data,'salutation'),          // s
        $dob,                           // s
        s($data,'relocate'),            // s
        s($data,'relocate_time'),       // s
        s($data,'phone_code'),          // s
        s($data,'college'),             // s
        s($data,'engagement_type'),     // s
        s($data,'english_level'),       // s
        s($data,'industry'),            // s
        s($data,'exp_type'),            // s
        s($data,'exp_desc'),            // s
        s($data,'current_salary'),      // s  current_salary
        s($data,'expected_salary'),     // s  expected_salary
        s($data,'tenure'),              // s
        $joining_date,                  // s
        s($data,'flex_hours'),          // s
        s($data,'laptop'),              // s
        s($data,'internet'),            // s
        s($data,'commute'),             // s
        s($data,'tech_skills'),         // s
        s($data,'soft_skills'),         // s
        $resume_path,                   // s
        $video_path,                    // s
        s($data,'portfolio'),           // s
        s($data,'ai_test_willing')      // s
    );

    if (!$stmt->execute()) throw new Exception('Execute: '.$stmt->error);
    $candidate_id = $db->insert_id;
    $stmt->close();

    // Outreach log
    if ($campaign_id && $candidate_id) {
        $lg = $db->prepare("INSERT INTO outreach_log (campaign_id,candidate_id,action,notes,created_at) VALUES (?,?,'applied','Self-applied via public form',NOW())");
        if ($lg) { $lg->bind_param('ii',$campaign_id,$candidate_id); $lg->execute(); $lg->close(); }
    }

    echo json_encode([
        'success'          => true,
        'candidate_id'     => $candidate_id,
        'interview_token'  => $campaign['unique_token'] ?? null,
    ]);

} catch (Exception $e) {
    error_log('[apply] '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
