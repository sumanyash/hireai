<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Fallback router for extensionless URLs when nginx has not reloaded yet.
// Example: /dashboard should still serve dashboard.php after login.
$request_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$route_map = [
    'dashboard' => 'dashboard.php',
    'campaigns' => 'campaigns.php',
    'candidates' => 'candidates.php',
    'candidate_detail' => 'candidate_detail.php',
    'analytics' => 'analytics.php',
    'outreach' => 'outreach.php',
    'credits' => 'credits.php',
    'admins' => 'admins.php',
    'logout' => 'logout.php',
    'video_view' => 'video_view.php',
];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($route_map[$request_path]) && !empty($_SESSION['token'])) {
    $_SERVER['PHP_SELF'] = '/' . $route_map[$request_path];
    $_SERVER['SCRIPT_NAME'] = '/' . $route_map[$request_path];
    require __DIR__ . '/' . $route_map[$request_path];
    exit;
}

// Session-based rate limiting
$attempts     = $_SESSION['login_attempts'] ?? 0;
$locked_until = $_SESSION['login_locked_until'] ?? 0;
$error = '';

if (time() < $locked_until) {
    $mins  = max(1, (int)ceil(($locked_until - time()) / 60));
    $error = "Too many failed attempts. Try again in $mins minute(s).";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $user     = db_fetch_one("SELECT * FROM users WHERE email=? AND is_active=1", [$email], 's');
    if ($user && password_verify($password, $user['password_hash'])) {
        unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
        $token = make_jwt($user['id'], $user['role'], $user['org_id']);
        $_SESSION['token'] = $token;
        $_SESSION['user']  = $user;
        header('Location: ' . BASE_URL . '/dashboard');
        exit;
    }
    $attempts++;
    $_SESSION['login_attempts'] = $attempts;
    if ($attempts >= 5) {
        $_SESSION['login_locked_until'] = time() + 900;
        $error = 'Too many failed attempts. Account locked for 15 minutes.';
    } else {
        $left  = 5 - $attempts;
        $error = 'Invalid email or password.' . ($left <= 2 ? " $left attempt(s) left before lockout." : '');
    }
}
$is_locked = (time() < $locked_until);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Avyukta Intellicall AI Hire</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;background:#0A1628;min-height:100vh;display:flex;align-items:center;justify-content:center;-webkit-font-smoothing:antialiased}
.login-box{background:#112240;border:1px solid #1e3a5f;border-radius:18px;padding:44px 40px;width:100%;max-width:420px;box-shadow:0 24px 80px rgba(0,0,0,.55)}
.logo{text-align:center;margin-bottom:32px}
.logo img{height:50px;margin-bottom:10px}
.logo p{color:#8892A4;font-size:13px;margin-top:4px;font-weight:500}
.form-group{margin-bottom:18px}
label{display:block;color:#8892A4;font-size:12px;margin-bottom:6px;font-weight:600;letter-spacing:.4px;text-transform:uppercase}
input[type=email],input[type=password]{width:100%;padding:12px 16px;background:#0A1628;border:1.5px solid #1e3a5f;border-radius:10px;color:#fff;font-size:14px;font-family:inherit;outline:none;transition:border-color .2s,box-shadow .2s}
input:focus{border-color:#0066FF;box-shadow:0 0 0 3px rgba(0,102,255,.15)}
.btn{width:100%;padding:13px;background:#0066FF;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s,transform .1s;display:flex;align-items:center;justify-content:center;gap:8px}
.btn:hover:not(:disabled){background:#0052cc}
.btn:active:not(:disabled){transform:scale(.98)}
.btn:disabled{background:#1e3a5f;cursor:not-allowed;color:#8892A4}
.error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#F87171;padding:11px 14px;border-radius:10px;margin-bottom:18px;font-size:13px;font-weight:500}
.hint{color:#8892A4;font-size:12px;text-align:center;margin-top:20px;font-weight:500}
.spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;flex-shrink:0}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div class="login-box">
  <div class="logo">
    <img src="https://www.avyukta.in/assets/images/logoo.png" alt="Avyukta">
    <p>Avyukta Intellicall AI Hire</p>
  </div>
  <?php if (!empty($error)): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST" id="login-form">
    <div class="form-group">
      <label>Email Address</label>
      <input type="email" name="email" placeholder="Email address" required autocomplete="email" <?= $is_locked ? 'disabled' : '' ?>>
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" name="password" placeholder="••••••••" required autocomplete="current-password" <?= $is_locked ? 'disabled' : '' ?>>
    </div>
    <button type="submit" class="btn" id="submit-btn" <?= $is_locked ? 'disabled' : '' ?>>
      <?= $is_locked ? 'Account Locked' : 'Sign In →' ?>
    </button>
  </form>
  <p class="hint">Secure AI hiring workspace</p>
</div>
<script>
document.getElementById('login-form')?.addEventListener('submit', function() {
  const btn = document.getElementById('submit-btn');
  if (!btn || btn.disabled) return;
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner"></div> Signing in…';
});
</script>
</body>
</html>
