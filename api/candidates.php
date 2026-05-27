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

function valid_candidate_email($email) {
    $email = trim((string)$email);
    if ($email === '') return true;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (!preg_match('/^[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}$/i', $email)) return false;
    [$local, $domain] = explode('@', $email, 2);
    if ($local === '' || $domain === '' || str_contains($local, '..') || str_contains($domain, '..')) return false;
    foreach (explode('.', $domain) as $part) {
        if ($part === '' || str_starts_with($part, '-') || str_ends_with($part, '-')) return false;
    }
    return true;
}

function candidate_money_value($value) {
    $cleaned = preg_replace('/[^\d.]/', '', str_replace(',', '', (string)$value));
    if ($cleaned === '' || $cleaned === '.') return null;
    return is_numeric($cleaned) ? (float)$cleaned : null;
}

function candidate_experience_value($value) {
    $cleaned = preg_replace('/[^\d.]/', '', str_replace(',', '', (string)$value));
    if ($cleaned === '' || $cleaned === '.') return 0.0;
    return is_numeric($cleaned) ? (float)$cleaned : 0.0;
}

function safe_candidate_audit($org_id, $user_id, $entity_type, $entity_id, $action, $details = []) {
    try {
        audit_log($org_id, $user_id, $entity_type, $entity_id, $action, $details);
    } catch (Throwable $e) {
        error_log('[candidate audit] ' . $e->getMessage());
    }
}

function safe_candidate_reminder($candidate_id, $campaign_id) {
    try {
        db_insert(
            "INSERT INTO reminder_jobs (candidate_id,campaign_id,channel,scheduled_at) VALUES (?,?,'whatsapp',DATE_ADD(NOW(), INTERVAL 12 HOUR))",
            [$candidate_id, $campaign_id], 'ii'
        );
    } catch (Throwable $e) {
        error_log('[candidate reminder] ' . $e->getMessage());
    }
}

function candidate_duplicate_exists($campaign_id, $phone, $email) {
    $phone = normalize_phone($phone);
    $email = strtolower(trim((string)$email));
    $conditions = [];
    $params = [];
    $types = '';
    if ($phone !== '') { $conditions[] = "phone=?"; $params[] = $phone; $types .= 's'; }
    if ($email !== '') { $conditions[] = "LOWER(email)=?"; $params[] = $email; $types .= 's'; }
    if (empty($conditions)) return false;
    $where = implode(' OR ', $conditions);
    $row = db_fetch_one("SELECT id FROM candidates WHERE campaign_id=? AND ($where) LIMIT 1", array_merge([$campaign_id], $params), 'i' . $types);
    return $row !== null;
}

function candidate_duplicate_exists_for_update($campaign_id, $candidate_id, $phone, $email) {
    $phone = normalize_phone($phone);
    $email = strtolower(trim((string)$email));
    $conditions = [];
    $params = [];
    $types = '';
    if ($phone !== '') { $conditions[] = "phone=?"; $params[] = $phone; $types .= 's'; }
    if ($email !== '') { $conditions[] = "LOWER(email)=?"; $params[] = $email; $types .= 's'; }
    if (empty($conditions)) return false;
    $where = implode(' OR ', $conditions);
    $row = db_fetch_one("SELECT id FROM candidates WHERE campaign_id=? AND id<>? AND ($where) LIMIT 1", array_merge([$campaign_id, $candidate_id], $params), 'ii' . $types);
    return $row !== null;
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
        'referred_by_name' => $get(['referral', 'referral name', 'referred by', 'referred by name']),
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
    $experience_years = candidate_experience_value($input['experience_years'] ?? '');
    $current_ctc      = trim($input['current_ctc'] ?? '');
    $source           = trim($input['source'] ?? '');
    $referred_by_name = trim($input['referred_by_name'] ?? '');

    if (!$campaign_id) json_response(['error' => 'Campaign is required'], 400);
    if (!$name)        json_response(['error' => 'Name is required'], 400);
    if ($phone === '' && $email === '') json_response(['error' => 'Phone or email is required'], 400);
    if (!valid_candidate_email($email)) json_response(['error' => 'Valid email is required'], 400);
    $current_num = candidate_money_value($current_ctc);
    $expected_num = candidate_money_value(trim($input['expected_ctc'] ?? ''));
    if ($current_num !== null && $expected_num !== null && $expected_num < $current_num) {
        json_response(['error' => 'Expected CTC cannot be lower than current CTC'], 400);
    }

    // Verify campaign belongs to this org
    $campaign = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id, $user['org_id']], 'ii');
    if (!$campaign) json_response(['error' => 'Campaign not found'], 404);

    // Check duplicate by phone or email within campaign
    if (candidate_duplicate_exists($campaign_id, $phone, $email)) {
        json_response(['error' => 'Candidate with this phone or email already exists in campaign'], 409);
    }

    $token = bin2hex(random_bytes(16));
    try {
        $id = db_insert(
            "INSERT INTO candidates (org_id, campaign_id, name, phone, email, city, experience_years, current_ctc, expected_ctc, source, referred_by_name, unique_token, link_expires_at, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), 'pending', NOW())",
            [$user['org_id'], $campaign_id, $name, $phone, $email, $city, $experience_years, $current_ctc, trim($input['expected_ctc'] ?? ''), $source, $referred_by_name, $token],
            'iissssdsssss'
        );
    } catch (Throwable $e) {
        error_log('[candidate add] ' . $e->getMessage());
        json_response(['error' => 'Failed to add candidate. Please check database columns and try again.'], 500);
    }

    if (!$id) json_response(['error' => 'Failed to add candidate. Please try again.'], 500);
    safe_candidate_audit($user['org_id'], $user['user_id'] ?? null, 'candidate', $id, 'candidate_added', ['source' => $source, 'campaign_id' => $campaign_id, 'referred_by_name' => $referred_by_name]);
    safe_candidate_reminder($id, $campaign_id);

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
        if ($phone === '' && $email === '') { $errors++; continue; }
        if (!valid_candidate_email($email)) { $errors++; continue; }
        $current_num = candidate_money_value($row['current_ctc'] ?? '');
        $expected_num = candidate_money_value($row['expected_ctc'] ?? '');
        if ($current_num !== null && $expected_num !== null && $expected_num < $current_num) { $errors++; continue; }

        $dedupe_key = strtolower($email ?: normalize_phone($phone));
        if ($dedupe_key && isset($seen[$dedupe_key])) { $dupes++; continue; }
        if ($dedupe_key) $seen[$dedupe_key] = true;
        if (candidate_duplicate_exists($campaign_id, $phone, $email)) { $dupes++; continue; }

        $token = bin2hex(random_bytes(16));
        try {
            $r = db_insert(
                "INSERT INTO candidates (org_id, campaign_id, name, phone, email, city, experience_years, current_ctc, expected_ctc, source, referred_by_name, unique_token, link_expires_at, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), 'pending', NOW())",
                [$user['org_id'], $campaign_id, $name, $phone, $email, $row['city'], candidate_experience_value($row['experience_years'] ?? ''), $row['current_ctc'], $row['expected_ctc'], $row['source'], $row['referred_by_name'] ?? '', $token],
                'iissssdsssss'
            );
        } catch (Throwable $e) {
            error_log('[candidate bulk_import] ' . $e->getMessage());
            $errors++;
            continue;
        }
        if ($r) {
            $added++;
            safe_candidate_reminder($r, $campaign_id);
        } else {
            $errors++;
        }
    }
    safe_candidate_audit($user['org_id'], $user['user_id'] ?? null, 'candidate', null, 'bulk_import', ['campaign_id' => $campaign_id, 'added' => $added, 'dupes' => $dupes, 'errors' => $errors]);
    json_response(['success' => true, 'added' => $added, 'dupes' => $dupes, 'errors' => $errors]);
}

// ── UPDATE CANDIDATE DETAILS ────────────────────────────────────────────────
if ($action === 'update' && $method === 'POST') {
    $candidate_id = (int)($input['candidate_id'] ?? 0);
    if (!$candidate_id) json_response(['error' => 'Candidate ID required'], 400);
    $existing = db_fetch_one("SELECT * FROM candidates WHERE id=? AND org_id=?", [$candidate_id, $user['org_id']], 'ii');
    if (!$existing) json_response(['error' => 'Candidate not found'], 404);

    $campaign_id = (int)($input['campaign_id'] ?? $existing['campaign_id']);
    $name = trim($input['name'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $email = trim($input['email'] ?? '');
    $city = trim($input['city'] ?? '');
    $experience_years = candidate_experience_value($input['experience_years'] ?? '');
    $current_ctc = trim($input['current_ctc'] ?? '');
    $expected_ctc = trim($input['expected_ctc'] ?? '');
    $source = trim($input['source'] ?? '');
    $referred_by_name = trim($input['referred_by_name'] ?? '');

    $campaign = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id, $user['org_id']], 'ii');
    if (!$campaign) json_response(['error' => 'Campaign not found'], 404);
    if (candidate_duplicate_exists_for_update($campaign_id, $candidate_id, $phone, $email)) {
        json_response(['error' => 'Another candidate with this phone or email already exists in the selected campaign'], 409);
    }

    db_execute(
        "UPDATE candidates SET campaign_id=?,name=?,phone=?,email=?,city=?,experience_years=?,current_ctc=?,expected_ctc=?,source=?,referred_by_name=?,updated_at=NOW() WHERE id=? AND org_id=?",
        [$campaign_id,$name,$phone,$email,$city,$experience_years,$current_ctc,$expected_ctc,$source,$referred_by_name,$candidate_id,$user['org_id']],
        'issssdssssii'
    );
    audit_log($user['org_id'], $user['user_id'] ?? null, 'candidate', $candidate_id, 'candidate_updated', ['campaign_id' => $campaign_id, 'referred_by_name' => $referred_by_name]);
    json_response(['success' => true, 'message' => 'Candidate updated']);
}

// ── DELETE CANDIDATE ──────────────────────────────────────────────────────────
if ($action === 'bulk_delete' && $method === 'POST') {
    $ids = array_map('intval', $input['candidate_ids'] ?? []);
    $ids = array_filter($ids);
    if (empty($ids)) json_response(['error' => 'No candidate IDs provided'], 400);
    $deleted = 0;
    $db = get_db();
    foreach ($ids as $cid) {
        $c = db_fetch_one("SELECT id FROM candidates WHERE id=? AND org_id=?", [$cid, $user['org_id']], 'ii');
        if (!$c) continue;
        $db->begin_transaction();
        try {
            db_execute("DELETE FROM interview_answers WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM interview_sessions WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM interview_results WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM scores WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM outreach_log WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM reminder_jobs WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM recruiter_notes WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM ai_call_results WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM candidates WHERE id=? AND org_id=?", [$cid, $user['org_id']], 'ii');
            $db->commit();
            audit_log($user['org_id'], $user['user_id'] ?? null, 'candidate', $cid, 'candidate_deleted');
            $deleted++;
        } catch (Throwable $e) {
            $db->rollback();
            error_log('[bulk_delete] candidate ' . $cid . ': ' . $e->getMessage());
        }
    }
    json_response(['success' => true, 'deleted' => $deleted]);
}

if ($action === 'delete' && $method === 'POST') {
    $candidate_id = (int)($input['candidate_id'] ?? 0);
    if (!$candidate_id) json_response(['error' => 'Candidate ID required'], 400);
    $c = db_fetch_one("SELECT id FROM candidates WHERE id=? AND org_id=?", [$candidate_id, $user['org_id']], 'ii');
    if (!$c) json_response(['error' => 'Candidate not found'], 404);

    $db = get_db();
    $db->begin_transaction();
    try {
        db_execute("DELETE FROM interview_answers WHERE candidate_id=?", [$candidate_id], 'i');
        db_execute("DELETE FROM interview_sessions WHERE candidate_id=?", [$candidate_id], 'i');
        db_execute("DELETE FROM interview_results WHERE candidate_id=?", [$candidate_id], 'i');
        db_execute("DELETE FROM scores WHERE candidate_id=?", [$candidate_id], 'i');
        db_execute("DELETE FROM outreach_log WHERE candidate_id=?", [$candidate_id], 'i');
        db_execute("DELETE FROM reminder_jobs WHERE candidate_id=?", [$candidate_id], 'i');
        db_execute("DELETE FROM recruiter_notes WHERE candidate_id=?", [$candidate_id], 'i');
        db_execute("DELETE FROM ai_call_results WHERE candidate_id=?", [$candidate_id], 'i');
        db_execute("DELETE FROM candidates WHERE id=? AND org_id=?", [$candidate_id, $user['org_id']], 'ii');
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        error_log('[delete candidate] ' . $e->getMessage());
        json_response(['error' => 'Failed to delete candidate'], 500);
    }
    audit_log($user['org_id'], $user['user_id'] ?? null, 'candidate', $candidate_id, 'candidate_deleted');

    json_response(['success' => true, 'message' => 'Candidate deleted successfully']);
}

// ── BULK STATUS UPDATE ────────────────────────────────────────────────────────
if ($action === 'bulk_status_update' && $method === 'POST') {
    $ids    = array_map('intval', $input['candidate_ids'] ?? []);
    $ids    = array_filter($ids);
    $status = trim($input['status'] ?? '');
    $allowed = ['pending','outreach_sent','interview_started','interview_completed','shortlisted','rejected','on_hold'];
    if (empty($ids) || !in_array($status, $allowed)) json_response(['error' => 'Invalid request'], 400);
    $updated = 0;
    foreach ($ids as $cid) {
        $c = db_fetch_one("SELECT id FROM candidates WHERE id=? AND org_id=?", [$cid, $user['org_id']], 'ii');
        if (!$c) continue;
        db_execute("UPDATE candidates SET status=? WHERE id=?", [$status, $cid], 'si');
        audit_log($user['org_id'], $user['user_id'] ?? null, 'candidate', $cid, 'status_updated', ['status' => $status, 'bulk' => true]);
        $updated++;
    }
    json_response(['success' => true, 'updated' => $updated]);
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
