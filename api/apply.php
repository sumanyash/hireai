<?php
ob_start();
if ($_SERVER["CONTENT_LENGTH"] > 20971520) { header("Content-Type: application/json"); echo json_encode(["success"=>false,"error"=>"File too large. Max 20MB allowed."]); exit; }
error_reporting(E_ALL);
ini_set("display_errors", 0);
ini_set("log_errors", 1);
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $origin = rtrim($_SERVER['HTTP_ORIGIN'], '/');
    if ($origin === BASE_URL) header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Invalid JSON']); exit; }

function s($d,$k){ return isset($d[$k]) ? trim((string)$d[$k]) : ''; }

if (!s($data,'phone') || !s($data,'email')) {
    ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Missing required fields']); exit;
}

$campaign_id = (int)($data['campaign_id'] ?? 0);
$campaign_id = $campaign_id ?: (int)((db_fetch_one("SELECT id FROM campaigns WHERE status='active' ORDER BY id ASC LIMIT 1", [], '') ?: ['id'=>1])['id']);
$campaign    = $campaign_id ? db_fetch_one("SELECT * FROM campaigns WHERE id=?",[$campaign_id],'i') : null;
$org_id      = $campaign ? (int)$campaign['org_id'] : 1;
$email       = s($data,'email');
$ref_token   = s($data,'ref_token');
$referred_by_candidate_id = null;
if ($ref_token !== '') {
    $referrer = db_fetch_one("SELECT id,campaign_id FROM candidates WHERE unique_token=?", [$ref_token], 's');
    if ($referrer && (!$campaign_id || (int)$referrer['campaign_id'] === $campaign_id)) {
        $referred_by_candidate_id = (int)$referrer['id'];
    }
}

if ($campaign_id) {
    $dup = db_fetch_one("SELECT id FROM candidates WHERE email=? AND campaign_id=?",[$email,$campaign_id],'si');
    if ($dup) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Already applied.','duplicate'=>true]); exit; }
}

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
        if (file_put_contents($udir.'resumes/'.$fn,$dec) !== false)
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
        if (file_put_contents($udir.'videos/'.$fn,$dec) !== false)
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

    $cols = [
        'org_id','campaign_id','name','phone','email','city',
        'experience_years','current_ctc','expected_ctc','source',
        'unique_token','status',
        'salutation','dob','relocate','relocate_time','phone_code',
        'college','engagement_type','english_level','industry',
        'exp_type','exp_desc','current_salary','expected_salary',
        'tenure','joining_date','flex_hours','laptop','internet',
        'commute','tech_skills','soft_skills',
        'resume_path','video_path','portfolio','ai_test_willing','referred_by_candidate_id'
    ];

    $vals = [
        $org_id, $campaign_id,
        $name, s($data,'phone'), $email, s($data,'city'),
        $exp,
        s($data,'current_salary'), s($data,'expected_salary'), s($data,'source'),
        $tok, 'pending',
        s($data,'salutation'), $dob, s($data,'relocate'), s($data,'relocate_time'), s($data,'phone_code'),
        s($data,'college'), s($data,'engagement_type'), s($data,'english_level'), s($data,'industry'),
        s($data,'exp_type'), s($data,'exp_desc'), s($data,'current_salary'), s($data,'expected_salary'),
        s($data,'tenure'), $jd, s($data,'flex_hours'), s($data,'laptop'), s($data,'internet'),
        s($data,'commute'), s($data,'tech_skills'), s($data,'soft_skills'),
        $resume_path, $video_path, s($data,'portfolio'), s($data,'ai_test_willing'), $referred_by_candidate_id,
    ];

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $col_list     = implode(',', $cols);
    $sql = "INSERT INTO candidates ($col_list) VALUES ($placeholders)";

    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception('Prepare: '.$db->error);

    $types = '';
    foreach ($vals as $v) {
        if (is_int($v))       $types .= 'i';
        elseif (is_float($v)) $types .= 'd';
        else                  $types .= 's';
    }

    $stmt->bind_param($types, ...$vals);
    if (!$stmt->execute()) throw new Exception('Execute: '.$stmt->error);
    $cid = $db->insert_id;
    $stmt->close();

    // Applied log
    if ($campaign_id && $cid) {
        $lg = $db->prepare("INSERT INTO outreach_log (candidate_id,campaign_id,channel,status) VALUES (?,?,'whatsapp','sent')");
        if ($lg) { $lg->bind_param('ii',$cid,$campaign_id); $lg->execute(); $lg->close(); }
        db_insert(
            "INSERT INTO reminder_jobs (candidate_id,campaign_id,channel,scheduled_at) VALUES (?,?,'whatsapp',DATE_ADD(NOW(), INTERVAL 24 HOUR))",
            [$cid, $campaign_id], 'ii'
        );
    }

    // ── AUTO WHATSAPP + outreach_sent ─────────────────────────
    $wa_sent = false;
    if ($cid && s($data,'phone')) {
        try {
            $interview_link = INTERVIEW_URL . '?t=' . $tok;
            $camp_name = $campaign['name'] ?? 'our company';
            $job_role  = $campaign['job_role'] ?? 'the position';

            $msg = "🎯 *Interview Invitation — {$camp_name}*\n\n"
                 . "Hi {$name}! 👋\n\n"
                 . "Thank you for applying for *{$job_role}*.\n\n"
                 . "Please complete your AI interview here:\n"
                 . "🔗 {$interview_link}\n\n"
                 . "⏱ Duration: ~15 min | 🎤 Mic required\n\n"
                 . "*HireAI — Avyukta Intellicall*";

            $result  = send_whatsapp(s($data,'phone'), $msg);
            $wa_sent = isset($result['code']) && $result['code'] >= 200 && $result['code'] < 300;
            $status  = $wa_sent ? 'sent' : 'failed';

            // Update candidate status
            db_execute("UPDATE candidates SET status='outreach_sent', updated_at=NOW() WHERE id=?", [$cid], 'i');

            // Log WA outreach
            db_execute(
                "INSERT INTO outreach_log (candidate_id,campaign_id,channel,status) VALUES (?,?,'whatsapp',?)",
                [$cid, $campaign_id, $status], 'iis'
            );

        } catch(Exception $we) {
            error_log('[apply WA] '.$we->getMessage());
            // Still update status even if WA fails
            db_execute("UPDATE candidates SET status='outreach_sent', updated_at=NOW() WHERE id=?", [$cid], 'i');
        }
    }
    // ─────────────────────────────────────────────────────────

    ob_end_clean(); echo json_encode([
        'success'         => true,
        'candidate_id'    => $cid,
        'wa_sent'         => $wa_sent,
        'interview_token' => $tok,
    ]);

} catch(Exception $e) {
    error_log('[apply] '.$e->getMessage());
    ob_end_clean(); echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
