<?php
/**
 * Real-time duplicate check for apply form.
 * Called on blur of phone / email fields before form submission.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}

// IP rate limiting: max 60 checks per minute
(function() {
    $ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ip   = trim(explode(',', $ip)[0]);
    $key  = 'dupchk_' . hash('sha256', $ip);
    $file = sys_get_temp_dir() . '/hireai_dupchk_rl.json';
    $fh   = fopen($file, 'c+');
    if ($fh) {
        flock($fh, LOCK_EX);
        $data = json_decode(stream_get_contents($fh) ?: '{}', true);
        if (!is_array($data)) $data = [];
        $now  = time();
        $row  = $data[$key] ?? ['count' => 0, 'window_start' => $now];
        if ($now - $row['window_start'] > 60) { $row = ['count' => 0, 'window_start' => $now]; }
        $row['count']++;
        $data[$key] = $row;
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($data));
        flock($fh, LOCK_UN); fclose($fh);
        if ($row['count'] > 60) {
            http_response_code(429);
            echo json_encode(['exists' => false]);
            exit;
        }
    }
})();

$campaign_id = (int)($_GET['campaign_id'] ?? 0);
$phone       = trim($_GET['phone'] ?? '');
$email       = strtolower(trim($_GET['email'] ?? ''));

if (!$campaign_id) {
    echo json_encode(['exists' => false]); exit;
}

// Confirm campaign exists and get org_id for org-wide duplicate check
$campaign = db_fetch_one("SELECT id, org_id, status FROM campaigns WHERE id=?", [$campaign_id], 'i');
if (!$campaign || !in_array($campaign['status'], ['active','draft'], true)) {
    echo json_encode(['exists' => false]); exit;
}
$org_id = (int)$campaign['org_id'];

function norm($p) {
    return preg_replace('/[^0-9]/', '', (string)$p);
}

if ($phone !== '') {
    $norm = norm($phone);
    if (strlen($norm) >= 8) {
        // Check org-wide (any campaign in same org)
        $row = db_fetch_one(
            "SELECT c.id FROM candidates c
             JOIN campaigns camp ON camp.id = c.campaign_id
             WHERE c.org_id=? AND REGEXP_REPLACE(c.phone,'[^0-9]','') LIKE ?
             LIMIT 1",
            [$org_id, '%' . $norm],
            'is'
        );
        if ($row) {
            echo json_encode(['exists' => true, 'field' => 'phone']); exit;
        }
    }
    echo json_encode(['exists' => false]); exit;
}

if ($email !== '') {
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Check org-wide (any campaign in same org)
        $row = db_fetch_one(
            "SELECT c.id FROM candidates c
             JOIN campaigns camp ON camp.id = c.campaign_id
             WHERE c.org_id=? AND LOWER(c.email)=?
             LIMIT 1",
            [$org_id, $email],
            'is'
        );
        if ($row) {
            echo json_encode(['exists' => true, 'field' => 'email']); exit;
        }
    }
    echo json_encode(['exists' => false]); exit;
}

echo json_encode(['exists' => false]);
