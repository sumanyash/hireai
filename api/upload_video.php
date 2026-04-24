<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$token = $_POST['token'] ?? '';
$sid   = (int)($_POST['session_id'] ?? 0);

if (!$token) { echo json_encode(['error' => 'Token required']); exit; }

if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['video']['error'] ?? -1;
    $errMsgs = [
        1 => 'File too large (php.ini)',
        2 => 'File too large (form)',
        3 => 'Partial upload',
        4 => 'No file uploaded',
        6 => 'No temp folder',
        7 => 'Cannot write to disk',
    ];
    echo json_encode(['error' => $errMsgs[$errCode] ?? "Upload error code: $errCode"]);
    exit;
}

$c = db_fetch_one("SELECT id FROM candidates WHERE unique_token=?", [$token], 's');
if (!$c) { echo json_encode(['error' => 'Invalid token']); exit; }

$dir = __DIR__ . '/../uploads/video/';
if (!is_dir($dir)) mkdir($dir, 0777, true);

$fname = 'session_' . $c['id'] . '_' . time() . '.webm';
$fpath = $dir . $fname;
$url   = BASE_URL . '/uploads/video/' . $fname;

if (move_uploaded_file($_FILES['video']['tmp_name'], $fpath)) {
    // Update session recording_url
    if ($sid) {
        db_execute("UPDATE interview_sessions SET recording_url=?, video_url=? WHERE id=?",
            [$url, $url, $sid], 'ssi');
    } else {
        // Find latest session for this candidate
        db_execute("UPDATE interview_sessions SET recording_url=?, video_url=? WHERE candidate_id=? ORDER BY id DESC LIMIT 1",
            [$url, $url, $c['id']], 'ssi');
    }
    // Also update candidate
    db_execute("UPDATE candidates SET status='interview_completed' WHERE id=? AND status='interview_started'",
        [$c['id']], 'i');

    echo json_encode(['success' => true, 'url' => $url, 'filename' => $fname]);
} else {
    echo json_encode(['error' => 'Upload failed - check folder permissions: ' . $dir]);
}
