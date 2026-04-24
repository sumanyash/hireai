<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$user   = verify_jwt();
if (!$user) json_response(['error' => 'Unauthorized'], 401);

$action = $_GET['action'] ?? '';

if ($action === 'get') {
    $candidate_id = (int)($_GET['candidate_id'] ?? 0);
    $scores = db_fetch_all("SELECT * FROM scores WHERE candidate_id=? ORDER BY id", [$candidate_id], 'i');
    $result = db_fetch_one("SELECT * FROM interview_results WHERE candidate_id=?", [$candidate_id], 'i');
    json_response(['scores' => $scores, 'result' => $result]);
}

if ($action === 'override' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input        = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $candidate_id = (int)($input['candidate_id'] ?? 0);
    $score        = (int)($input['score'] ?? 0);
    $reason       = $input['reason'] ?? '';
    db_execute(
        "UPDATE interview_results SET recruiter_override_score=?,recruiter_override_reason=?,overridden_by=? WHERE candidate_id=?",
        [$score,$reason,$user['user_id'],$candidate_id], 'isii'
    );
    json_response(['success' => true]);
}

json_response(['error' => 'Unknown action'], 400);
