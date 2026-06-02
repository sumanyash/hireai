<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

define('PW_RESET_DIR', __DIR__ . '/includes/.pw_resets/');
if (!is_dir(PW_RESET_DIR)) mkdir(PW_RESET_DIR, 0700, true);

$msg = '';
$error = '';
$step = 'request'; // request | sent

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $user = db_fetch_one("SELECT id, name, email FROM users WHERE email=? AND is_active=1", [$email], 's');
        // Always show "sent" message even if email not found — prevents enumeration
        if ($user) {
            // Purge any existing token for this user
            foreach (glob(PW_RESET_DIR . 'u' . $user['id'] . '_*.tok') ?: [] as $f) @unlink($f);
            // Generate new token
            $token = bin2hex(random_bytes(32));
            $expires = time() + 3600; // 1 hour
            file_put_contents(PW_RESET_DIR . 'u' . $user['id'] . '_' . $token . '.tok', json_encode([
                'user_id' => $user['id'],
                'email'   => $user['email'],
                'expires' => $expires,
            ]));
            $reset_link = BASE_URL . '/reset_password.php?t=' . urlencode($token) . '&u=' . $user['id'];
            $subject = 'Reset Your HireAI Password';
            $body  = "Hi {$user['name']},\n\n";
            $body .= "You requested a password reset for your HireAI account.\n\n";
            $body .= "Click the link below to set a new password (valid for 1 hour):\n";
            $body .= "$reset_link\n\n";
            $body .= "If you did not request this, please ignore this email.\n\n";
            $body .= "— HireAI · Avyukta Intellicall";
            $headers = "From: noreply@avyukta.in\r\nX-Mailer: PHP/" . phpversion();
            @mail($user['email'], $subject, $body, $headers);
        }
        $step = 'sent';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — HireAI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;background:#0A1628;min-height:100vh;display:flex;align-items:center;justify-content:center;-webkit-font-smoothing:antialiased}
.box{background:#112240;border:1px solid #1e3a5f;border-radius:18px;padding:44px 40px;width:100%;max-width:420px;box-shadow:0 24px 80px rgba(0,0,0,.55)}
.logo{text-align:center;margin-bottom:28px}
.logo img{height:44px;margin-bottom:8px}
.title{font-size:20px;font-weight:800;color:#fff;text-align:center;margin-bottom:6px}
.sub{font-size:13px;color:#8892A4;text-align:center;margin-bottom:28px;line-height:1.5}
label{display:block;color:#8892A4;font-size:12px;margin-bottom:6px;font-weight:600;letter-spacing:.4px;text-transform:uppercase}
input{width:100%;padding:12px 16px;background:#0A1628;border:1.5px solid #1e3a5f;border-radius:10px;color:#fff;font-size:14px;font-family:inherit;outline:none;transition:border-color .2s,box-shadow .2s;margin-bottom:18px}
input:focus{border-color:#0066FF;box-shadow:0 0 0 3px rgba(0,102,255,.15)}
.btn{width:100%;padding:13px;background:#0066FF;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s}
.btn:hover{background:#0052cc}
.error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#F87171;padding:11px 14px;border-radius:10px;margin-bottom:18px;font-size:13px}
.success-icon{font-size:48px;text-align:center;margin-bottom:16px}
.back{display:block;text-align:center;margin-top:22px;color:#4A7AFF;font-size:13px;font-weight:600;text-decoration:none}
.back:hover{color:#7ca3ff}
</style>
</head>
<body>
<div class="box">
  <div class="logo">
    <img src="https://www.avyukta.in/assets/images/logoo.png" alt="Avyukta">
  </div>

  <?php if ($step === 'sent'): ?>
    <div class="success-icon">📬</div>
    <div class="title">Check Your Email</div>
    <div class="sub">If an account exists for that email address, we've sent a password reset link. The link is valid for <strong style="color:#fff">1 hour</strong>.<br><br>Don't see it? Check your spam folder.</div>
    <a href="/" class="back">← Back to Sign In</a>
  <?php else: ?>
    <div class="title">Forgot Password?</div>
    <div class="sub">Enter your account email and we'll send you a link to reset your password.</div>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <label>Email Address</label>
      <input type="email" name="email" placeholder="your@email.com" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      <button type="submit" class="btn">Send Reset Link →</button>
    </form>
    <a href="/" class="back">← Back to Sign In</a>
  <?php endif; ?>
</div>
</body>
</html>
