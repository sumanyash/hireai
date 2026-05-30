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
function apply_fail($error, $extra = []) {
    ob_end_clean();
    echo json_encode(array_merge(['success'=>false,'error'=>$error], $extra));
    exit;
}
function norm_phone_apply($phone){ return preg_replace('/[^0-9]/', '', (string)$phone); }
function strict_email_apply($email) {
    $email = trim((string)$email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (!preg_match('/^[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}$/i', $email)) return false;
    [$local, $domain] = explode('@', $email, 2);
    if ($local === '' || $domain === '' || str_contains($local, '..') || str_contains($domain, '..')) return false;
    foreach (explode('.', $domain) as $part) {
        if ($part === '' || str_starts_with($part, '-') || str_ends_with($part, '-')) return false;
    }
    return true;
}
function strict_date_apply($value) {
    $value = trim((string)$value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) return false;
    return (int)$dt->format('Y') >= 1900;
}
function money_apply($value) {
    $cleaned = preg_replace('/[^\d.]/', '', str_replace(',', '', (string)$value));
    if ($cleaned === '' || $cleaned === '.') return null;
    return is_numeric($cleaned) ? (float)$cleaned : null;
}
function apply_answer_key_slug($value) {
    return preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string)$value)));
}
function yesish_apply($value) {
    $value = strtolower(trim((string)$value));
    return in_array($value, ['yes', 'y', '1', 'true', 'agree', 'agreed', 'willing'], true);
}
function dynamic_answer_empty($value) {
    if (is_array($value)) return count(array_filter($value, fn($v) => trim((string)$v) !== '')) === 0;
    return trim((string)$value) === '';
}
function dynamic_answer_by_keys($answers, $keys) {
    $keys = array_map('apply_answer_key_slug', $keys);
    foreach ($answers as $answer) {
        $key = apply_answer_key_slug($answer['key'] ?? '');
        $label = apply_answer_key_slug($answer['label'] ?? '');
        if (in_array($key, $keys, true) || in_array($label, $keys, true)) {
            $value = $answer['value'] ?? '';
            return is_array($value) ? trim(implode(', ', $value)) : trim((string)$value);
        }
    }
    return '';
}
function default_apply_field_keys() {
    return [
        'salutation', 'first_name', 'last_name', 'dob', 'city', 'relocate', 'relocate_time',
        'phone_code', 'other_country_code', 'phone', 'email', 'college', 'college_other',
        'source', 'source_other', 'role_applied', 'engagement_type', 'english_level',
        'years_exp', 'industry', 'industry_other', 'exp_type', 'exp_desc', 'current_salary',
        'expected_salary', 'tenure', 'joining_date', 'flex_hours', 'laptop', 'internet',
        'location', 'commute', 'resume', 'photo', 'video_option', 'video_link', 'video_file',
        'portfolio', 'ai_test_willing', 'declaration_confirmation'
    ];
}
function default_apply_payload_value($data, $key) {
    $map = [
        'phone' => 'phone',
        'email' => 'email',
        'salutation' => 'salutation',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'dob' => 'dob',
        'city' => 'city',
        'relocate' => 'relocate',
        'relocate_time' => 'relocate_time',
        'phone_code' => 'phone_code',
        'college' => 'college',
        'source' => 'source',
        'role_applied' => 'role_applied',
        'engagement_type' => 'engagement_type',
        'english_level' => 'english_level',
        'years_exp' => 'years_exp',
        'industry' => 'industry',
        'exp_type' => 'exp_type',
        'exp_desc' => 'exp_desc',
        'current_salary' => 'current_salary',
        'expected_salary' => 'expected_salary',
        'tenure' => 'tenure',
        'joining_date' => 'joining_date',
        'flex_hours' => 'flex_hours',
        'laptop' => 'laptop',
        'internet' => 'internet',
        'location' => 'location',
        'commute' => 'commute',
        'portfolio' => 'portfolio',
        'ai_test_willing' => 'ai_test_willing',
    ];
    if ($key === 'college_other') return s($data, 'college') === 'Other - specify' || s($data, 'college') === 'Other – specify' ? s($data, 'college') : '';
    if ($key === 'source_other') return s($data, 'source') === 'Other - specify' || s($data, 'source') === 'Other – specify' ? s($data, 'source') : '';
    if ($key === 'industry_other') return s($data, 'industry') === 'Other' ? s($data, 'industry') : '';
    if ($key === 'declaration_confirmation') return 'I confirm that the information provided is true and accurate.';
    return isset($map[$key]) ? s($data, $map[$key]) : '';
}
function sync_candidate_application($campaign, $candidate_id, $payload) {
    $type = $campaign['integration_type'] ?? 'none';
    $endpoint = trim((string)($campaign['integration_endpoint'] ?? ''));
    if ($type === 'none' || $endpoint === '') return ['status' => 'skipped'];
    if (!preg_match('/^https?:\/\//i', $endpoint)) {
        error_log('[apply sync] Pending non-URL integration endpoint for campaign ' . ($campaign['id'] ?? ''));
        return ['status' => 'pending_endpoint'];
    }
    if (!validate_integration_endpoint($endpoint)) {
        error_log('[apply sync] Blocked unsafe integration endpoint for campaign ' . ($campaign['id'] ?? '') . ' endpoint=' . $endpoint);
        return ['status' => 'blocked'];
    }
    $body = json_encode([
        'source' => 'hireai',
        'integration_type' => $type,
        'candidate_id' => $candidate_id,
        'campaign_id' => (int)($campaign['id'] ?? 0),
        'campaign_name' => $campaign['name'] ?? '',
        'submitted_at' => date('c'),
        'candidate' => $payload,
    ]);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code < 200 || $code >= 300) {
        error_log('[apply sync] type=' . $type . ' candidate=' . $candidate_id . ' code=' . $code . ' error=' . $err . ' response=' . substr((string)$resp, 0, 300));
        return ['status' => 'failed', 'code' => $code];
    }
    return ['status' => 'sent', 'code' => $code];
}

$campaign_id = (int)($data['campaign_id'] ?? 0);
if (!$campaign_id) {
    // No campaign_id supplied — find the first active campaign (no hardcoded fallback to id=1)
    $fallback = db_fetch_one("SELECT id FROM campaigns WHERE status='active' ORDER BY id ASC LIMIT 1", [], '');
    if (!$fallback) apply_fail('No active campaign is currently accepting applications.');
    $campaign_id = (int)$fallback['id'];
}
$campaign = db_fetch_one("SELECT * FROM campaigns WHERE id=?", [$campaign_id], 'i');
if (!$campaign) apply_fail('Campaign not found.');
if (!in_array($campaign['status'] ?? '', ['active'], true)) {
    apply_fail('This campaign is not currently accepting applications.');
}
$org_id = (int)$campaign['org_id'];
$email       = s($data,'email');
$ref_token   = s($data,'ref_token');
$ref_medium  = s($data,'ref_medium') ?: 'candidate_share';
$application_answers = is_array($data['application_answers'] ?? null) ? $data['application_answers'] : [];
$ai_test_willing_value = s($data, 'ai_test_willing');
if ($ai_test_willing_value === '') {
    $ai_test_willing_value = dynamic_answer_by_keys($application_answers, [
        'ai_test_willing',
        'willing_to_take_ai_test',
        'willing_to_take_the_ai_test',
        'ai_test',
        'willing_to_take_the_ai_test?',
    ]);
}
$data['ai_test_willing'] = $ai_test_willing_value;
$ai_test_required = yesish_apply($ai_test_willing_value);
if ($email === '') $email = dynamic_answer_by_keys($application_answers, ['email','email_id','email_address']);
if (s($data,'phone') === '') {
    $data['phone'] = dynamic_answer_by_keys($application_answers, ['phone','mobile','mobile_number','phone_number','whatsapp','whatsapp_number']);
}
if (s($data,'first_name') === '' && s($data,'last_name') === '') {
    $full_name = dynamic_answer_by_keys($application_answers, ['name','full_name','candidate_name']);
    if ($full_name !== '') {
        $parts = preg_split('/\s+/', $full_name);
        $data['first_name'] = array_shift($parts) ?: $full_name;
        $data['last_name'] = implode(' ', $parts);
    }
}
if (!s($data,'phone')) {
    apply_fail('Phone field is required for candidate tracking and WhatsApp outreach. Please add a Phone field in this campaign form.');
}
if ($email === '' || !strict_email_apply($email)) {
    apply_fail('Valid email is required.');
}
$dob = s($data, 'dob');
if ($dob === '') $dob = dynamic_answer_by_keys($application_answers, ['dob','date_of_birth','birth_date']);
// DOB and joining date are optional — only validate format if provided
if ($dob !== '' && (!strict_date_apply($dob) || $dob > date('Y-m-d'))) {
    apply_fail('Date of birth must be a valid date in YYYY-MM-DD format and cannot be in the future.');
}
$data['dob'] = $dob;
$joining_date = s($data, 'joining_date');
if ($joining_date === '') $joining_date = dynamic_answer_by_keys($application_answers, ['joining_date','preferred_joining_date','available_from']);
if ($joining_date !== '' && (!strict_date_apply($joining_date) || $joining_date < date('Y-m-d'))) {
    apply_fail('Preferred joining date must be a valid date in YYYY-MM-DD format and cannot be before today.');
}
$data['joining_date'] = $joining_date;
$current_salary_value = s($data, 'current_salary');
if ($current_salary_value === '') $current_salary_value = dynamic_answer_by_keys($application_answers, ['current_salary','current_ctc','current_stipend']);
$expected_salary_value = s($data, 'expected_salary');
if ($expected_salary_value === '') $expected_salary_value = dynamic_answer_by_keys($application_answers, ['expected_salary','expected_ctc','expected_stipend']);
$current_salary_num = money_apply($current_salary_value);
$expected_salary_num = money_apply($expected_salary_value);
// Salary validation: only if expected salary was actually submitted
if ($expected_salary_value !== '' && $expected_salary_num === null) {
    apply_fail('Expected salary / stipend must be a valid number.');
}
if ($expected_salary_value !== '' && $current_salary_num !== null && $expected_salary_num !== null && $expected_salary_num < $current_salary_num) {
    apply_fail('Expected salary / stipend cannot be lower than current salary / stipend.');
}
$data['current_salary'] = $current_salary_value;
$data['expected_salary'] = $expected_salary_value;
$referred_by_candidate_id = null;
if ($ref_token !== '') {
    $referrer = db_fetch_one("SELECT id,campaign_id FROM candidates WHERE unique_token=?", [$ref_token], 's');
    if ($referrer && (!$campaign_id || (int)$referrer['campaign_id'] === $campaign_id)) {
        $referred_by_candidate_id = (int)$referrer['id'];
    }
}

if ($campaign_id) {
    $phone_norm = norm_phone_apply(s($data,'phone'));
    $existing_rows = db_fetch_all("SELECT id,phone,email FROM candidates WHERE campaign_id=?", [$campaign_id], 'i');
    foreach ($existing_rows as $row) {
        if ($email !== '' && strtolower(trim($row['email'] ?? '')) === strtolower($email)) {
            ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Already applied with this email.','duplicate'=>true]); exit;
        }
        if ($phone_norm !== '' && norm_phone_apply($row['phone'] ?? '') === $phone_norm) {
            ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Already applied with this phone number.','duplicate'=>true]); exit;
        }
    }
}

$application_fields = $campaign_id ? db_fetch_all("SELECT id,field_key,field_label,field_type,is_required FROM application_fields WHERE campaign_id=? AND is_active=1 ORDER BY order_no,id", [$campaign_id], 'i') : [];
$default_apply_keys = default_apply_field_keys();
$clean_application_answers = [];
foreach ($application_fields as $field) {
    $fid = (string)$field['id'];
    $field_key = strtolower(trim((string)($field['field_key'] ?? '')));
    $is_default_field = in_array($field_key, $default_apply_keys, true);
    $answer = $application_answers[$fid]['value'] ?? $application_answers[(int)$field['id']]['value'] ?? null;
    if ($is_default_field && dynamic_answer_empty($answer)) {
        $answer = default_apply_payload_value($data, $field_key);
    }
    if (!$is_default_field && !empty($field['is_required']) && dynamic_answer_empty($answer)) {
        ob_end_clean(); echo json_encode(['success'=>false,'error'=>$field['field_label'].' is required']); exit;
    }
    $clean_application_answers[$fid] = [
        'key' => $field['field_key'],
        'label' => $field['field_label'],
        'type' => $field['field_type'],
        'value' => is_array($answer) ? array_values(array_map('trim', array_map('strval', $answer))) : trim((string)$answer),
    ];
}
$clean_application_answers['_recording_consent'] = [
    'key' => 'recording_consent',
    'label' => 'Voice/video recording consent',
    'type' => 'checkbox',
    'value' => !empty($data['recording_consent']) ? 'Yes' : 'No',
];

$udir = __DIR__.'/../uploads/';
@mkdir($udir.'resumes',0755,true);
@mkdir($udir.'videos',0755,true);
@mkdir($udir.'application',0755,true);
$resume_path = '';
$video_path  = '';
$photo_path  = '';

foreach ($clean_application_answers as $fid => &$answer) {
    if (($answer['type'] ?? '') !== 'file') continue;
    $file = $application_answers[$fid]['file'] ?? $application_answers[(int)$fid]['file'] ?? null;
    if (!is_array($file) || empty($file['base64']) || empty($file['name'])) continue;
    $dec = base64_decode($file['base64'], true);
    if (!$dec || strlen($dec) > 20 * 1024 * 1024) continue;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'bin';
    $safe_ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
    $fn = 'app_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $safe_ext;
    if (file_put_contents($udir . 'application/' . $fn, $dec) !== false) {
        $path = 'uploads/application/' . $fn;
        $answer['value'] = $path;
        $answer['original_name'] = $file['name'];
        $answer['mime_type'] = $file['type'] ?? '';
        $key = strtolower((string)($answer['key'] ?? ''));
        if ($resume_path === '' && (str_contains($key, 'resume') || str_contains($key, 'cv'))) $resume_path = $path;
        if ($video_path === '' && str_contains($key, 'video')) $video_path = $path;
        if ($photo_path === '' && (str_contains($key, 'photo') || str_contains($key, 'image') || str_contains($key, 'picture'))) $photo_path = $path;
    }
}
unset($answer);

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
if ($name === '') $name = 'Candidate';
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
        'resume_path','video_path','portfolio','ai_test_willing','application_answers_json','referred_by_candidate_id','referred_medium','link_expires_at'
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
        $resume_path, $video_path, s($data,'portfolio'), $ai_test_willing_value, json_encode($clean_application_answers), $referred_by_candidate_id, $ref_medium, date('Y-m-d H:i:s', time() + 86400),
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
            "INSERT INTO reminder_jobs (candidate_id,campaign_id,channel,scheduled_at) VALUES (?,?,'whatsapp',DATE_ADD(NOW(), INTERVAL 12 HOUR))",
            [$cid, $campaign_id], 'ii'
        );
    }

    // ── AUTO WHATSAPP + outreach_sent ─────────────────────────
    $wa_sent = false;
    if ($ai_test_required && $cid && s($data,'phone')) {
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

            $result  = send_whatsapp(s($data,'phone'), $msg, [
                'org_id' => $org_id,
                'candidate_id' => $cid,
                'campaign_id' => $campaign_id,
                'reason' => 'application_interview_invite',
            ]);
            $wa_sent = isset($result['code']) && $result['code'] >= 200 && $result['code'] < 300;
            $status  = $wa_sent ? 'sent' : 'failed';

            // Update candidate status only when outreach actually went out.
            if ($wa_sent) {
                db_execute("UPDATE candidates SET status='outreach_sent', updated_at=NOW() WHERE id=?", [$cid], 'i');
            }

            // Log WA outreach
            db_execute(
                "INSERT INTO outreach_log (candidate_id,campaign_id,channel,status) VALUES (?,?,'whatsapp',?)",
                [$cid, $campaign_id, $status], 'iis'
            );

        } catch(Exception $we) {
            error_log('[apply WA] '.$we->getMessage());
            // Keep candidate pending so recruiters can retry outreach manually.
        }
    }
    // ─────────────────────────────────────────────────────────

    $sync_status = sync_candidate_application($campaign ?: [], $cid, [
        'name' => $name,
        'phone' => s($data,'phone'),
        'email' => $email,
        'city' => s($data,'city'),
        'resume_path' => $resume_path,
        'video_path' => $video_path,
        'answers' => $clean_application_answers,
        'referred_by_candidate_id' => $referred_by_candidate_id,
        'referred_medium' => $ref_medium,
    ]);

    ob_end_clean(); echo json_encode([
        'success'         => true,
        'candidate_id'    => $cid,
        'wa_sent'         => $wa_sent,
        'sync_status'     => $sync_status,
        'ai_test_required'=> $ai_test_required,
        'interview_token' => $ai_test_required ? $tok : null,
        'campaign_id'     => $campaign_id,
        'referral_link'   => BASE_URL . '/apply.php?campaign_id=' . (int)$campaign_id . '&ref=' . urlencode($tok),
    ]);

} catch(Exception $e) {
    error_log('[apply] '.$e->getMessage());
    ob_end_clean(); echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
