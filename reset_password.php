<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

define('PW_RESET_DIR', __DIR__ . '/includes/.pw_resets/');

$token   = trim($_GET['t'] ?? '');
$user_id = (int)($_GET['u'] ?? 0);
$error   = '';
$success = false;

// Validate token
$tok_data = null;
if ($token && $user_id) {
    $tok_file = PW_RESET_DIR . 'u' . $user_id . '_' . $token . '.tok';
    if (file_exists($tok_file)) {
        $tok_data = json_decode(file_get_contents($tok_file), true);
        if (!$tok_data || $tok_data['expires'] < time() || (int)$tok_data['user_id'] !== $user_id) {
            $tok_data = null;
            @unlink($tok_file);
        }
    }
}

if (!$tok_data) {
    $error = 'invalid';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tok_data) {
    $new     = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');
    if (strlen($new) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        // Delete token BEFORE updating password to prevent concurrent reuse
        @unlink(PW_RESET_DIR . 'u' . $user_id . '_' . $token . '.tok');
        db_execute("UPDATE users SET password_hash=? WHERE id=?", [$hash, $user_id], 'si');
        audit_log(
            db_fetch_one("SELECT org_id FROM users WHERE id=?", [$user_id], 'i')['org_id'] ?? 0,
            $user_id, 'user', $user_id, 'password_reset_via_link'
        );
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — HireAI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;background:#0A1628;min-height:100vh;display:flex;align-items:center;justify-content:center;-webkit-font-smoothing:antialiased}
.box{background:#112240;border:1px solid #1e3a5f;border-radius:18px;padding:44px 40px;width:100%;max-width:420px;box-shadow:0 24px 80px rgba(0,0,0,.55)}
.logo{text-align:center;margin-bottom:28px}
.logo img{height:44px}
.title{font-size:20px;font-weight:800;color:#fff;text-align:center;margin-bottom:6px}
.sub{font-size:13px;color:#8892A4;text-align:center;margin-bottom:28px;line-height:1.5}
label{display:block;color:#8892A4;font-size:12px;margin-bottom:6px;font-weight:600;letter-spacing:.4px;text-transform:uppercase}
.pw-wrap{position:relative;margin-bottom:18px}
.pw-wrap input{width:100%;padding:12px 44px 12px 16px;background:#0A1628;border:1.5px solid #1e3a5f;border-radius:10px;color:#fff;font-size:14px;font-family:inherit;outline:none;transition:border-color .2s,box-shadow .2s;margin:0}
.pw-wrap input:focus{border-color:#0066FF;box-shadow:0 0 0 3px rgba(0,102,255,.15)}
.pw-eye{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#8892A4;font-size:16px;padding:4px}
.pw-eye:hover{color:#fff}
.btn{width:100%;padding:13px;background:#0066FF;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s;margin-top:4px}
.btn:hover{background:#0052cc}
.error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#F87171;padding:11px 14px;border-radius:10px;margin-bottom:18px;font-size:13px}
.success-icon{font-size:48px;text-align:center;margin-bottom:16px}
.invalid-box{text-align:center;padding:20px 0}
.invalid-box .icon{font-size:48px;margin-bottom:16px}
.back{display:block;text-align:center;margin-top:22px;color:#4A7AFF;font-size:13px;font-weight:600;text-decoration:none}
.back:hover{color:#7ca3ff}
.hint{font-size:11.5px;color:#4A7AFF;margin-top:6px}
</style>
</head>
<body>
<div class="box">
  <div class="logo">
    <img src="https://www.avyukta.in/assets/images/logoo.png" alt="Avyukta">
  </div>

  <?php if ($success): ?>
    <div class="success-icon">✅</div>
    <div class="title">Password Updated</div>
    <div class="sub">Your password has been reset successfully. You can now sign in with your new password.</div>
    <a href="/" class="back" style="display:block;text-align:center;margin-top:22px;padding:13px;background:#0066FF;color:#fff;border-radius:10px;font-size:15px;font-weight:700;text-decoration:none">Sign In →</a>

  <?php elseif ($error === 'invalid'): ?>
    <div class="invalid-box">
      <div class="icon">🔗</div>
      <div class="title">Link Invalid or Expired</div>
      <div class="sub">This password reset link has expired or already been used. Links are valid for 1 hour.</div>
      <a href="/forgot_password.php" class="back">Request a new reset link →</a>
    </div>

  <?php else: ?>
    <div class="title">Set New Password</div>
    <div class="sub">Choose a strong password for your account.</div>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <label>New Password</label>
      <div class="pw-wrap">
        <input type="password" name="password" id="pw1" placeholder="At least 8 characters" required autocomplete="new-password">
        <button type="button" class="pw-eye" onclick="togglePw('pw1',this)" title="Show/hide">👁</button>
      </div>
      <div class="hint" style="margin-bottom:16px">Min. 8 characters</div>
      <label>Confirm New Password</label>
      <div class="pw-wrap">
        <input type="password" name="confirm" id="pw2" placeholder="Repeat password" required autocomplete="new-password">
        <button type="button" class="pw-eye" onclick="togglePw('pw2',this)" title="Show/hide">👁</button>
      </div>
      <button type="submit" class="btn">Update Password →</button>
    </form>
    <a href="/" class="back">← Back to Sign In</a>
  <?php endif; ?>
</div>
<script>
function togglePw(id, btn) {
  const i = document.getElementById(id);
  i.type = i.type === 'password' ? 'text' : 'password';
  btn.textContent = i.type === 'password' ? '👁' : '🙈';
}
</script>
</body>
</html>
