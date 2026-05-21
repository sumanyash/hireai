<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$user = verify_jwt();
if (!$user) { json_response(['error' => 'Unauthorized'], 401); }

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$old = (string)($input['old_password'] ?? '');
$new = (string)($input['new_password'] ?? '');

if ($old === '' || $new === '') {
    json_response(['error' => 'Both fields are required.'], 400);
}
if (strlen($new) < 8) {
    json_response(['error' => 'New password must be at least 8 characters.'], 400);
}

$row = db_fetch_one("SELECT password_hash FROM users WHERE id=?", [$user['user_id']], 'i');
if (!$row || !password_verify($old, $row['password_hash'])) {
    json_response(['error' => 'Current password is incorrect.'], 403);
}

$hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
db_execute("UPDATE users SET password_hash=? WHERE id=?", [$hash, $user['user_id']], 'si');

json_response(['success' => true]);
