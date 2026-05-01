<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!empty($_SERVER['HTTP_ORIGIN']) && rtrim($_SERVER['HTTP_ORIGIN'], '/') === BASE_URL) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$user = verify_jwt();
if (!$user) json_response(['error' => 'Unauthorized'], 401);

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? '';

function normalize_phone($phone) {
    return preg_replace('/[^0-9]/', '', (string)$phone);
}

function candidate_duplicate_exists($campaign_id, $phone, $email) {
    $phone = normalize_phone($phone);
    $email = trim((string)$email);
    if ($phone !== '') {
        $dup = db_fetch_one("SELECT id FROM candidates WHERE campaign_id=? AND REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(', '') LIKE ?", [$campaign_id, '%' . $phone . '%'], 'is');
        if ($dup) return true;
    }
    if ($email !== '') {
        $dup = db_fetch_one("SELECT id FROM candidates WHERE campaign_id=? AND email=?", [$campaign_id, $email], 'is');
        if ($dup) return true;
    }
    return false;
}

function normalize_candidate_row($row) {
    $get = function($keys) use ($row) {
        foreach ($keys as $key) {
            foreach ($row as $rk => $rv) {
                $clean = strtolower(trim(str_replace([' ', '-', '_'], '', $rk)));
                $target = strtolower(trim(str_replace([' ', '-', '_'], '', $key)));
                if ($clean === $target) return trim((string)$rv);
            }
        }
        return '';
    };
    $first = $get(['first name', 'firstname', 'first']);
    $last = $get(['last name', 'lastname', 'last']);
    $name = trim($get(['name', 'full name']) ?: trim("$first $last"));
    $phone_code = $get(['phone code', 'country code']);
    $phone = trim($phone_code . ' ' . $get(['phone number', 'phone', 'mobile', 'mobile number']));
    return [
        'name' => $name,
        'phone' => $phone,
        'email' => $get(['email', 'email address']),
        'city' => $get(['city', 'location']),
        'experience_years' => $get(['experience', 'experience years', 'years exp']),
        'current_ctc' => $get(['current ctc', 'current salary']),
        'expected_ctc' => $get(['expected ctc', 'expected salary']),
        'source' => $get(['source']) ?: 'csv',
    ];
}

function parse_csv_text($csv) {
    $lines = preg_split('/\r\n|\r|\n/', trim((string)$csv));
    if (count($lines) < 2) return [];
    $headers = str_getcsv(array_shift($lines));
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $values = str_getcsv($line);
        $row = [];
        foreach ($headers as $i => $header) $row[$header] = $values[$i] ?? '';
        $rows[] = normalize_candidate_row($row);
    }
    return $rows;
}

// ── ADD SINGLE CANDIDATE ─────────────────────────────────────────────────────
if ($action === 'add' && $method === 'POST') {
    $campaign_id      = (int)($input['campaign_id'] ?? 0);
    $name             = trim($input['name'] ?? '');
    $phone            = trim($input['phone'] ?? '');
    $email            = trim($input['email'] ?? '');
    $city             = trim($input['city'] ?? '');
    $experience_years = trim($input['experience_years'] ?? '');
    $current_ctc      = trim($input['current_ctc'] ?? '');
    $source           = trim($input['source'] ?? '');

    if (!$campaign_id) json_response(['error' => 'Campaign is required'], 400);
    if (!$name)        json_response(['error' => 'Name is required'], 400);

    // Verify campaign belongs to this org
    $campaign = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id, $user['org_id']], 'ii');
    if (!$campaign) json_response(['error' => 'Campaign not found'], 404);

    // Check duplicate by phone or email within campaign
    if (candidate_duplicate_exists($campaign_id, $phone, $email)) {
        json_response(['error' => 'Candidate with this phone or email already exists in campaign'], 409);
    }

    $token = bin2hex(random_bytes(16));
    $id = db_insert(
        "INSERT INTO candidates (org_id, campaign_id, name, phone, email, city, experience_years, current_ctc, expected_ctc, source, unique_token, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())",
        [$user['org_id'], $campaign_id, $name, $phone, $email, $city, $experience_years, $current_ctc, trim($input['expected_ctc'] ?? ''), $source, $token],
        'iisssssssss'
    );

    if (!$id) json_response(['error' => 'Failed to add candidate. Please try again.'], 500);
    audit_log($user['org_id'], $user['user_id'] ?? null, 'candidate', $id, 'candidate_added', ['source' => $source, 'campaign_id' => $campaign_id]);
    db_insert(
        "INSERT INTO reminder_jobs (candidate_id,campaign_id,channel,scheduled_at) VALUES (?,?,'whatsapp',DATE_ADD(NOW(), INTERVAL 24 HOUR))",
        [$id, $campaign_id], 'ii'
    );

    json_response([
        'success'      => true,
        'message'      => 'Candidate added successfully!',
        'candidate_id' => $id,
        'token'        => $token,
    ]);
}

// ── BULK IMPORT ───────────────────────────────────────────────────────────────
if ($action === 'bulk_import' && $method === 'POST') {
    $campaign_id = (int)($input['campaign_id'] ?? 0);
    $rows        = $input['rows'] ?? [];
    if (!empty($input['csv_text'])) $rows = parse_csv_text($input['csv_text']);

    if (!$campaign_id) json_response(['error' => 'Campaign is required'], 400);
    $campaign = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id, $user['org_id']], 'ii');
    if (!$campaign) json_response(['error' => 'Campaign not found'], 404);

    $added = $dupes = $errors = 0;
    $seen = [];
    foreach ($rows as $row) {
        $row = normalize_candidate_row($row);
        $name  = trim($row['name'] ?? '');
        $phone = trim($row['phone'] ?? '');
        $email = trim($row['email'] ?? '');
        if (!$name) { $errors++; continue; }

        $dedupe_key = strtolower($email ?: normalize_phone($phone));
        if ($dedupe_key && isset($seen[$dedupe_key])) { $dupes++; continue; }
        if ($dedupe_key) $seen[$dedupe_key] = true;
        if (candidate_duplicate_exists($campaign_id, $phone, $email)) { $dupes++; continue; }

        $token = bin2hex(random_bytes(16));
        $r = db_insert(
            "INSERT INTO candidates (org_id, campaign_id, name, phone, email, city, experience_years, current_ctc, expected_ctc, source, unique_token, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())",
            [$user['org_id'], $campaign_id, $name, $phone, $email, $row['city'], $row['experience_years'], $row['current_ctc'], $row['expected_ctc'], $row['source'], $token],
            'iisssssssss'
        );
        if ($r) {
            $added++;
            db_insert(
                "INSERT INTO reminder_jobs (candidate_id,campaign_id,channel,scheduled_at) VALUES (?,?,'whatsapp',DATE_ADD(NOW(), INTERVAL 24 HOUR))",
                [$r, $campaign_id], 'ii'
            );
        } else {
            $errors++;
        }
    }
    audit_log($user['org_id'], $user['user_id'] ?? null, 'candidate', null, 'bulk_import', ['campaign_id' => $campaign_id, 'added' => $added, 'dupes' => $dupes, 'errors' => $errors]);
    json_response(['success' => true, 'added' => $added, 'dupes' => $dupes, 'errors' => $errors]);
}

// ── DELETE CANDIDATE ──────────────────────────────────────────────────────────
if ($action === 'delete' && $method === 'POST') {
    $candidate_id = (int)($input['candidate_id'] ?? 0);
    if (!$candidate_id) json_response(['error' => 'Candidate ID required'], 400);
    $c = db_fetch_one("SELECT id FROM candidates WHERE id=? AND org_id=?", [$candidate_id, $user['org_id']], 'ii');
    if (!$c) json_response(['error' => 'Candidate not found'], 404);

    // Cascade delete related data
    db_execute("DELETE FROM interview_answers WHERE candidate_id=?", [$candidate_id], 'i');
    db_execute("DELETE FROM interview_sessions WHERE candidate_id=?", [$candidate_id], 'i');
    db_execute("DELETE FROM interview_results WHERE candidate_id=?", [$candidate_id], 'i');
    db_execute("DELETE FROM outreach_log WHERE candidate_id=?", [$candidate_id], 'i');
    db_execute("DELETE FROM candidates WHERE id=? AND org_id=?", [$candidate_id, $user['org_id']], 'ii');
    audit_log($user['org_id'], $user['user_id'] ?? null, 'candidate', $candidate_id, 'candidate_deleted');

    json_response(['success' => true, 'message' => 'Candidate deleted successfully']);
}

// ── UPDATE STATUS ─────────────────────────────────────────────────────────────
if ($action === 'update_status' && $method === 'POST') {
    $candidate_id = (int)($input['candidate_id'] ?? 0);
    $status       = trim($input['status'] ?? '');
    $notes        = trim($input['notes'] ?? '');
    $allowed      = ['pending','outreach_sent','interview_started','interview_completed','shortlisted','rejected','on_hold'];
    if (!$candidate_id || !in_array($status, $allowed)) json_response(['error' => 'Invalid request'], 400);
    $c = db_fetch_one("SELECT id FROM candidates WHERE id=? AND org_id=?", [$candidate_id, $user['org_id']], 'ii');
    if (!$c) json_response(['error' => 'Not found'], 404);
    db_execute("UPDATE candidates SET status=? WHERE id=?", [$status, $candidate_id], 'si');
    if ($notes !== '') {
        db_insert("INSERT INTO recruiter_notes (candidate_id,user_id,note) VALUES (?,?,?)", [$candidate_id, $user['user_id'] ?? 0, $notes], 'iis');
    }
    audit_log($user['org_id'], $user['user_id'] ?? null, 'candidate', $candidate_id, 'status_updated', ['status' => $status]);
    json_response(['success' => true]);
}

json_response(['error' => 'Unknown action'], 400);
