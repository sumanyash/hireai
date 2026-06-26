<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

// POST /api/auth.php?action=login
$action = $_GET['action'] ?? '';

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $email    = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    // Rate limiting — same logic as index.php
    $lock_state = login_lock_state($email);
    if (time() < (int)($lock_state['locked_until'] ?? 0)) {
        $mins = max(1, (int)ceil(($lock_state['locked_until'] - time()) / 60));
        json_response(['error' => "Too many failed attempts. Try again in $mins minute(s)."], 429);
    }

    $user = db_fetch_one("SELECT * FROM users WHERE email=? AND is_active=1", [$email], 's');
    if (!$user || !password_verify($password, $user['password_hash'])) {
        $failure = login_lock_register_failure($email);
        $left = (int)$failure['left'];
        $msg = 'Invalid credentials.' . ($left <= 2 && $left > 0 ? " $left attempt(s) left before lockout." : '');
        json_response(['error' => $msg], 401);
    }
    login_lock_clear($email);
    $token = make_jwt($user['id'], $user['role'], $user['org_id']);
    json_response(['token' => $token, 'user' => ['id'=>$user['id'],'name'=>$user['name'],'role'=>$user['role']]]);
}

json_response(['error' => 'Not found'], 404);
