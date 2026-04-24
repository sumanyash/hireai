<?php
require_once __DIR__ . '/functions.php';
$user = require_auth();
// Enrich with DB name if not in JWT
if (empty($user['name'])) {
    $u = db_fetch_one("SELECT name, email FROM users WHERE id=?", [$user['user_id']], 'i');
    $user['name']  = $u['name']  ?? 'Admin';
    $user['email'] = $u['email'] ?? '';
}
