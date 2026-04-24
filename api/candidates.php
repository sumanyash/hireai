<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

// Allow CORS for same-origin AJAX
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$user = verify_jwt();
if (!$user) json_response(['error' => 'Unauthorized'], 401);

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? '';

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
    if ($phone) {
        $dup = db_fetch_one("SELECT id FROM candidates WHERE campaign_id=? AND phone=?", [$campaign_id, $phone], 'is');
        if ($dup) json_response(['error' => 'Candidate with this phone already exists in campaign'], 409);
    }
    if ($email) {
        $dup = db_fetch_one("SELECT id FROM candidates WHERE campaign_id=? AND email=?", [$campaign_id, $email], 'is');
        if ($dup) json_response(['error' => 'Candidate with this email already exists in campaign'], 409);
    }

    $token = bin2hex(random_bytes(16));
    $id = db_insert(
        "INSERT INTO candidates (org_id, campaign_id, name, phone, email, city, experience_years, current_ctc, source, unique_token, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())",
        [$user['org_id'], $campaign_id, $name, $phone, $email, $city, $experience_years, $current_ctc, $source, $token],
        'iissssssss'
    );

    if (!$id) json_response(['error' => 'Failed to add candidate. Please try again.'], 500);

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

    if (!$campaign_id) json_response(['error' => 'Campaign is required'], 400);
    $campaign = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id, $user['org_id']], 'ii');
    if (!$campaign) json_response(['error' => 'Campaign not found'], 404);

    $added = $dupes = $errors = 0;
    foreach ($rows as $row) {
        $name  = trim($row['name'] ?? '');
        $phone = trim($row['phone'] ?? '');
        $email = trim($row['email'] ?? '');
        if (!$name) { $errors++; continue; }

        // Duplicate check
        if ($phone) {
            $dup = db_fetch_one("SELECT id FROM candidates WHERE campaign_id=? AND phone=?", [$campaign_id, $phone], 'is');
            if ($dup) { $dupes++; continue; }
        }
        if ($email) {
            $dup = db_fetch_one("SELECT id FROM candidates WHERE campaign_id=? AND email=?", [$campaign_id, $email], 'is');
            if ($dup) { $dupes++; continue; }
        }

        $token = bin2hex(random_bytes(16));
        $r = db_insert(
            "INSERT INTO candidates (org_id, campaign_id, name, phone, email, unique_token, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())",
            [$user['org_id'], $campaign_id, $name, $phone, $email, $token],
            'iissss'
        );
        $r ? $added++ : $errors++;
    }
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

    json_response(['success' => true, 'message' => 'Candidate deleted successfully']);
}

// ── UPDATE STATUS ─────────────────────────────────────────────────────────────
if ($action === 'update_status' && $method === 'POST') {
    $candidate_id = (int)($input['candidate_id'] ?? 0);
    $status       = trim($input['status'] ?? '');
    $allowed      = ['pending','outreach_sent','interview_started','interview_completed','shortlisted','rejected','on_hold'];
    if (!$candidate_id || !in_array($status, $allowed)) json_response(['error' => 'Invalid request'], 400);
    $c = db_fetch_one("SELECT id FROM candidates WHERE id=? AND org_id=?", [$candidate_id, $user['org_id']], 'ii');
    if (!$c) json_response(['error' => 'Not found'], 404);
    db_execute("UPDATE candidates SET status=? WHERE id=?", [$status, $candidate_id], 'si');
    json_response(['success' => true]);
}

json_response(['error' => 'Unknown action'], 400);
