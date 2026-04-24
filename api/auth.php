<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

// POST /api/auth.php?action=login
$action = $_GET['action'] ?? '';

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $email    = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $user = db_fetch_one("SELECT * FROM users WHERE email=? AND is_active=1", [$email], 's');
    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_response(['error' => 'Invalid credentials'], 401);
    }
    $token = make_jwt($user['id'], $user['role'], $user['org_id']);
    json_response(['token' => $token, 'user' => ['id'=>$user['id'],'name'=>$user['name'],'role'=>$user['role']]]);
}

json_response(['error' => 'Not found'], 404);
