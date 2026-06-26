<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Remember Me — restore session from persistent cookie if session expired
if (empty($_SESSION['token']) && !empty($_COOKIE['hire_remember'])) {
    $remembered_token = $_COOKIE['hire_remember'];
    $remembered_user  = verify_jwt($remembered_token);
    if ($remembered_user) {
        $db_user = db_fetch_one("SELECT * FROM users WHERE id=? AND is_active=1", [$remembered_user['user_id']], 'i');
        if ($db_user) {
            session_regenerate_id(true);
            // Issue a fresh 30-day token
            $new_token = make_jwt($db_user['id'], $db_user['role'], $db_user['org_id'], 86400 * 30);
            $_SESSION['token'] = $new_token;
            $_SESSION['user']  = $db_user;
            setcookie('hire_remember', $new_token, time() + (86400 * 30), '/', '', true, true);
        } else {
            // User deactivated — clear stale cookie
            setcookie('hire_remember', '', time() - 3600, '/');
        }
    } else {
        setcookie('hire_remember', '', time() - 3600, '/');
    }
}

// Fallback router for extensionless URLs
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
    'audit_logs' => 'audit_logs.php',
    'logout' => 'logout.php',
    'video_view' => 'video_view.php',
    'jd_builder' => 'jd_builder.php',
    'training'   => 'training.php',
    'forgot_password' => 'forgot_password.php',
    'reset_password'  => 'reset_password.php',
];
if (isset($route_map[$request_path])) {
    if (!in_array($request_path, ['forgot_password','reset_password'], true) && empty($_SESSION['token'])) {
        header('Location: ' . BASE_URL . '/');
        exit;
    }
    $_SERVER['PHP_SELF']   = '/' . $route_map[$request_path];
    $_SERVER['SCRIPT_NAME'] = '/' . $route_map[$request_path];
    require __DIR__ . '/' . $route_map[$request_path];
    exit;
}

// Rate limiting
$error        = '';
$posted_email = trim($_POST['email'] ?? '');
$lock_state   = login_lock_state($posted_email);
$locked_until = (int)($lock_state['locked_until'] ?? 0);

if (time() < $locked_until) {
    $mins  = max(1, (int)ceil(($locked_until - time()) / 60));
    $error = "Too many failed attempts. Try again in $mins minute(s).";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email       = $posted_email;
    $password    = $_POST['password'] ?? '';
    $remember_me = !empty($_POST['remember_me']);
    $user        = db_fetch_one("SELECT * FROM users WHERE email=? AND is_active=1", [$email], 's');
    if ($user && password_verify($password, $user['password_hash'])) {
        login_lock_clear($email);
        session_regenerate_id(true);
        // Remember Me: 30 days, else 24 hours
        $expiry = $remember_me ? (86400 * 30) : 86400;
        $token  = make_jwt($user['id'], $user['role'], $user['org_id'], $expiry);
        $_SESSION['token'] = $token;
        $_SESSION['user']  = $user;
        if ($remember_me) {
            setcookie('hire_remember', $token, time() + (86400 * 30), '/', '', true, true);
        }
        header('Location: ' . BASE_URL . '/dashboard');
        exit;
    }
    $failure      = login_lock_register_failure($email);
    $locked_until = (int)$failure['locked_until'];
    if ($locked_until > time()) {
        $error = 'Too many failed attempts. Account locked for 15 minutes.';
    } else {
        $left  = (int)$failure['left'];
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
<title>Sign In — Avyukta Intellicall AI Hire</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;background:#060D1A;min-height:100vh;display:flex;align-items:stretch;-webkit-font-smoothing:antialiased}

/* ── LEFT HERO ──────────────────────────────────── */
.hero{flex:1;background:linear-gradient(150deg,#0A1628 0%,#0D2040 45%,#1A0D33 100%);display:flex;flex-direction:column;justify-content:center;padding:60px 64px;position:relative;overflow:hidden;min-height:100vh}
.hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' stroke='%23ffffff' stroke-opacity='0.03' stroke-width='1'%3E%3Cpath d='M0 30h60M30 0v60'/%3E%3C/g%3E%3C/svg%3E");pointer-events:none}
/* Glows must stay absolute — do NOT put them inside .hero>* */
.hero-glow,.hero-glow2,.hero::before{position:absolute;pointer-events:none}
.hero-glow{width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(99,102,241,.15) 0%,transparent 70%);top:-100px;right:-150px}
.hero-glow2{width:350px;height:350px;border-radius:50%;background:radial-gradient(circle,rgba(124,58,237,.12) 0%,transparent 70%);bottom:-60px;left:-80px}
/* Single flex child that holds all real content — glows are siblings but absolute */
.hero-content{position:relative;display:flex;flex-direction:column;justify-content:center;width:100%;max-width:520px}
.hero-logo{display:flex;align-items:center;gap:12px;margin-bottom:56px}
.hero-logo img{height:42px}
.hero-logo-text{font-size:15px;font-weight:800;color:rgba(255,255,255,.85);letter-spacing:-.2px}
.hero-headline{font-size:38px;font-weight:900;color:#fff;line-height:1.15;letter-spacing:-1px;margin-bottom:14px}
.hero-headline span{background:linear-gradient(135deg,#818CF8,#A78BFA);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-sub{font-size:15px;color:rgba(255,255,255,.55);line-height:1.65;margin-bottom:48px;max-width:380px}
.features{display:flex;flex-direction:column;gap:16px}
.feat{display:flex;align-items:flex-start;gap:14px}
.feat-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.feat-body{padding-top:1px}
.feat-title{font-size:14px;font-weight:700;color:#fff;margin-bottom:2px}
.feat-desc{font-size:12.5px;color:rgba(255,255,255,.45);line-height:1.5}
.hero-footer{margin-top:56px;font-size:12px;color:rgba(255,255,255,.25);font-weight:500}

/* ── RIGHT FORM ─────────────────────────────────── */
.form-side{width:480px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:#0A1628;padding:48px;min-height:100vh}
.login-box{width:100%;max-width:360px}
.login-title{font-size:24px;font-weight:900;color:#fff;letter-spacing:-.4px;margin-bottom:6px}
.login-sub{font-size:13.5px;color:#8892A4;margin-bottom:32px;font-weight:500}

/* form elements */
.form-group{margin-bottom:18px}
.form-label{display:block;color:#8892A4;font-size:11.5px;margin-bottom:6px;font-weight:700;letter-spacing:.4px;text-transform:uppercase}
.form-input{width:100%;padding:12px 16px;background:#112240;border:1.5px solid #1e3a5f;border-radius:10px;color:#fff;font-size:14px;font-family:inherit;outline:none;transition:border-color .2s,box-shadow .2s}
.form-input:focus{border-color:#6366F1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
.form-input::placeholder{color:#4A5568}
.pw-wrap{position:relative}
.pw-wrap .form-input{padding-right:44px}
.pw-eye{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#4A5568;transition:color .15s;padding:4px;line-height:1;font-size:15px}
.pw-eye:hover{color:#8892A4}

/* remember + forgot row */
.rf-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
.remember{display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none}
.remember input[type=checkbox]{width:16px;height:16px;accent-color:#6366F1;cursor:pointer}
.remember span{font-size:13px;color:#8892A4;font-weight:500}
.forgot{font-size:13px;color:#6366F1;font-weight:600;text-decoration:none;transition:color .15s}
.forgot:hover{color:#818CF8}

/* submit */
.btn{width:100%;padding:13px;background:linear-gradient(135deg,#6366F1,#7C3AED);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;transition:opacity .2s,transform .1s;display:flex;align-items:center;justify-content:center;gap:8px;letter-spacing:-.1px}
.btn:hover:not(:disabled){opacity:.9}
.btn:active:not(:disabled){transform:scale(.98)}
.btn:disabled{background:#1e3a5f;cursor:not-allowed;color:#8892A4}

/* error */
.error-box{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:#F87171;padding:11px 14px;border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:500;display:flex;align-items:flex-start;gap:8px}
.spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;flex-shrink:0}
@keyframes spin{to{transform:rotate(360deg)}}
.divider{border:none;border-top:1px solid #1e3a5f;margin:26px 0}
.secure-note{display:flex;align-items:center;gap:7px;justify-content:center;font-size:12px;color:#4A5568;font-weight:500}

/* mobile */
@media(max-width:900px){
  body{flex-direction:column}
  .hero{min-height:auto;padding:36px 28px 40px}
  .hero-headline{font-size:26px}
  .hero-logo{margin-bottom:28px}
  .features{display:none}
  .hero-footer{display:none}
  .form-side{width:100%;padding:32px 24px;min-height:auto}
}
</style>
</head>
<body>

<!-- ══ LEFT HERO ══════════════════════════════════════════════════════════ -->
<div class="hero">
  <!-- Decorative layers — position:absolute, no flex flow impact -->
  <div class="hero-glow"></div>
  <div class="hero-glow2"></div>

  <!-- All real content in one relative flex child so glows don't displace it -->
  <div class="hero-content">
  <div class="hero-logo">
    <img src="https://www.avyukta.in/assets/images/logoo.png" alt="Avyukta Intellicall">
  </div>

  <div class="hero-headline">AI-Powered<br>Hiring, <span>Simplified</span></div>
  <div class="hero-sub">Screen more candidates in less time. Automated AI interviews, smart scoring, and real-time analytics — all in one workspace.</div>

  <div class="features">
    <div class="feat">
      <div class="feat-icon" style="background:rgba(99,102,241,.18)">🎙️</div>
      <div class="feat-body">
        <div class="feat-title">AI Interview Engine</div>
        <div class="feat-desc">Automated text & voice interviews with question branching, anti-cheat monitoring, and recording.</div>
      </div>
    </div>
    <div class="feat">
      <div class="feat-icon" style="background:rgba(16,185,129,.14)">📊</div>
      <div class="feat-body">
        <div class="feat-title">Smart Candidate Scoring</div>
        <div class="feat-desc">Gemini AI scores every answer per parameter. Shortlist top talent automatically with pass/fail thresholds.</div>
      </div>
    </div>
    <div class="feat">
      <div class="feat-icon" style="background:rgba(245,158,11,.14)">💬</div>
      <div class="feat-body">
        <div class="feat-title">WhatsApp Outreach</div>
        <div class="feat-desc">Send interview invitations and result notifications directly via WhatsApp at scale.</div>
      </div>
    </div>
    <div class="feat">
      <div class="feat-icon" style="background:rgba(239,68,68,.14)">📈</div>
      <div class="feat-body">
        <div class="feat-title">Real-Time Analytics</div>
        <div class="feat-desc">Funnel metrics, score distributions, completion trends, and AI-generated hiring insights.</div>
      </div>
    </div>
  </div>

  <div class="hero-footer">© <?= date('Y') ?> Avyukta Intellicall · AI Hiring Platform</div>
  </div><!-- /hero-content -->
</div>

<!-- ══ RIGHT FORM ══════════════════════════════════════════════════════════ -->
<div class="form-side">
  <div class="login-box">
    <div class="login-title">Welcome back</div>
    <div class="login-sub">Sign in to your HireAI workspace</div>

    <?php if (!empty($error)): ?>
    <div class="error-box"><span>⚠</span> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="login-form">
      <div class="form-group">
        <label class="form-label">Email Address</label>
        <input type="email" name="email" class="form-input" placeholder="you@company.com"
               required autocomplete="email" <?= $is_locked ? 'disabled' : '' ?>
               value="<?= htmlspecialchars($posted_email) ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Password</label>
        <div class="pw-wrap">
          <input type="password" name="password" id="pw-field" class="form-input"
                 placeholder="••••••••" required autocomplete="current-password"
                 <?= $is_locked ? 'disabled' : '' ?>>
          <button type="button" class="pw-eye" id="pw-toggle" onclick="togglePassword()" title="Show / hide password">
            <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg id="eye-off-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
      </div>

      <div class="rf-row">
        <label class="remember">
          <input type="checkbox" name="remember_me" <?= !empty($_POST['remember_me']) ? 'checked' : '' ?>>
          <span>Remember me</span>
        </label>
        <a href="/forgot_password.php" class="forgot">Forgot password?</a>
      </div>

      <button type="submit" class="btn" id="submit-btn" <?= $is_locked ? 'disabled' : '' ?>>
        <?= $is_locked ? '🔒 Account Locked' : 'Sign In →' ?>
      </button>
    </form>

  </div>
</div>

<script>
function togglePassword() {
  const f = document.getElementById('pw-field');
  const show = f.type === 'password';
  f.type = show ? 'text' : 'password';
  document.getElementById('eye-icon').style.display     = show ? 'none'  : 'inline';
  document.getElementById('eye-off-icon').style.display = show ? 'inline': 'none';
}
document.getElementById('login-form')?.addEventListener('submit', function() {
  const btn = document.getElementById('submit-btn');
  if (!btn || btn.disabled) return;
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner"></div> Signing in…';
});
</script>
</body>
</html>
