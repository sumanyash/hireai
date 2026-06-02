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

$campaign_id = (int)($_GET['campaign_id'] ?? 0);
$phone       = trim($_GET['phone'] ?? '');
$email       = strtolower(trim($_GET['email'] ?? ''));

if (!$campaign_id) {
    echo json_encode(['exists' => false]); exit;
}

// Confirm campaign exists and is active
$campaign = db_fetch_one("SELECT id, status FROM campaigns WHERE id=?", [$campaign_id], 'i');
if (!$campaign || !in_array($campaign['status'], ['active','draft'], true)) {
    echo json_encode(['exists' => false]); exit;
}

function norm($p) {
    return preg_replace('/[^0-9]/', '', (string)$p);
}

if ($phone !== '') {
    $norm = norm($phone);
    if (strlen($norm) >= 8) {
        $row = db_fetch_one(
            "SELECT id FROM candidates WHERE campaign_id=? AND REGEXP_REPLACE(phone,'[^0-9]','') LIKE ?",
            [$campaign_id, '%' . $norm],
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
        $row = db_fetch_one(
            "SELECT id FROM candidates WHERE campaign_id=? AND LOWER(email)=?",
            [$campaign_id, $email],
            'is'
        );
        if ($row) {
            echo json_encode(['exists' => true, 'field' => 'email']); exit;
        }
    }
    echo json_encode(['exists' => false]); exit;
}

echo json_encode(['exists' => false]);
