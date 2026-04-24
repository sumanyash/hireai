<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $user = db_fetch_one("SELECT * FROM users WHERE email=? AND is_active=1", [$email], 's');
    if ($user && password_verify($password, $user['password_hash'])) {
        $token = make_jwt($user['id'], $user['role'], $user['org_id']);
        $_SESSION['token'] = $token;
        $_SESSION['user'] = $user;
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
    $error = 'Invalid email or password';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HireAI — Login</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', sans-serif; background: #0A1628; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.login-box { background: #112240; border: 1px solid #1e3a5f; border-radius: 16px; padding: 48px 40px; width: 100%; max-width: 420px; }
.logo { text-align: center; margin-bottom: 32px; }
.logo h1 { font-size: 36px; font-weight: 800; color: #fff; letter-spacing: -1px; }
.logo h1 span { color: #0066FF; }
.logo p { color: #8892A4; font-size: 14px; margin-top: 4px; }
.form-group { margin-bottom: 20px; }
label { display: block; color: #8892A4; font-size: 13px; margin-bottom: 6px; font-weight: 500; }
input { width: 100%; padding: 12px 16px; background: #0A1628; border: 1px solid #1e3a5f; border-radius: 8px; color: #fff; font-size: 15px; outline: none; transition: border-color 0.2s; }
input:focus { border-color: #0066FF; }
.btn { width: 100%; padding: 13px; background: #0066FF; color: #fff; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
.btn:hover { background: #0052cc; }
.error { background: #2d1515; border: 1px solid #ff4444; color: #ff6b6b; padding: 10px 14px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
.hint { color: #8892A4; font-size: 12px; text-align: center; margin-top: 20px; }
</style>
</head>
<body>
<div class="login-box">
  <div class="logo">
    <h1>Hire<span>AI</span></h1>
    <p>Enterprise Recruitment Platform</p>
  </div>
  <?php if (!empty($error)): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <div class="form-group">
      <label>Email Address</label>
      <input type="email" name="email" placeholder="admin@hireai.in" required>
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" name="password" placeholder="••••••••" required>
    </div>
    <button type="submit" class="btn">Sign In →</button>
  </form>
  <p class="hint">Powered by Avyukta Intellicall</p>
</div>
</body>
</html>
