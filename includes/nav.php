<?php
$current = basename($_SERVER['PHP_SELF']);
$initials = strtoupper(substr($user['name'] ?? 'A', 0, 1));
$nav_role_key = strtolower(str_replace([' ', '-'], '_', trim((string)($user['role'] ?? ''))));
?>
<nav class="navbar">
  <div class="nav-logo">
    <img src="https://www.avyukta.in/assets/images/logoo.png" alt="Avyukta" style="height:38px;width:auto;filter:brightness(0) invert(1);">
  </div>
  <div class="nav-links">
    <a href="/dashboard" class="<?= $current==='dashboard.php'?'active':'' ?>"><i class="fa-solid fa-gauge-high fa-sm"></i> Dashboard</a>
    <a href="/campaigns" class="<?= $current==='campaigns.php'?'active':'' ?>"><i class="fa-solid fa-rocket fa-sm"></i> Campaigns</a>
    <a href="/candidates" class="<?= $current==='candidates.php'?'active':'' ?>"><i class="fa-solid fa-users fa-sm"></i> Candidates</a>
    <a href="/analytics" class="<?= $current==='analytics.php'?'active':'' ?>"><i class="fa-solid fa-chart-line fa-sm"></i> Analytics</a>
    <a href="/outreach" class="<?= $current==='outreach.php'?'active':'' ?>"><i class="fa-solid fa-paper-plane fa-sm"></i> Outreach</a>
    <a href="/credits" class="<?= $current==='credits.php'?'active':'' ?>"><i class="fa-solid fa-coins fa-sm"></i> Credits</a>
    <?php if ($nav_role_key === 'super_admin'): ?>
    <a href="/audit_logs" class="<?= $current==='audit_logs.php'?'active':'' ?>"><i class="fa-solid fa-shield-halved fa-sm"></i> Audit Logs</a>
    <a href="/training" class="<?= $current==='training.php'?'active':'' ?>"><i class="fa-solid fa-book-open fa-sm"></i> Guide</a>
    <a href="/admins" class="<?= $current==='admins.php'?'active':'' ?>"><i class="fa-solid fa-user-shield fa-sm"></i> Admins</a>
    <?php endif; ?>
  </div>
  <div class="nav-right">
    <!-- User dropdown -->
    <div class="nav-user-wrap" id="navUserWrap">
      <button class="nav-user" onclick="toggleUserMenu(event)" title="Account">
        <div class="nav-avatar"><?= htmlspecialchars($initials) ?></div>
        <span><?= htmlspecialchars($user['name'] ?? 'Admin') ?></span>
        <i class="fa-solid fa-chevron-down fa-xs" style="margin-left:4px;opacity:.6"></i>
      </button>
      <div class="nav-user-dropdown" id="navUserDropdown">
        <div class="nav-dd-header">
          <div style="font-weight:700;color:#fff;font-size:13px"><?= htmlspecialchars($user['name'] ?? 'Admin') ?></div>
          <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:2px"><?= htmlspecialchars($user['email'] ?? '') ?></div>
          <div style="font-size:10px;color:rgba(255,255,255,.3);margin-top:1px;text-transform:uppercase;letter-spacing:.5px"><?= htmlspecialchars($user['role'] ?? 'hr') ?></div>
        </div>
        <div class="nav-dd-divider"></div>
        <button class="nav-dd-item" onclick="openChangePw()">
          <i class="fa-solid fa-key fa-sm"></i> Change Password
        </button>
        <div class="nav-dd-divider"></div>
        <a href="/logout" class="nav-dd-item nav-dd-logout">
          <i class="fa-solid fa-right-from-bracket fa-sm"></i> Logout
        </a>
      </div>
    </div>
  </div>
</nav>

<!-- ── Change Password Modal ───────────────────────────────── -->
<div id="changePwOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:9999;display:none;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:20px;padding:32px;width:100%;max-width:400px;box-shadow:0 24px 80px rgba(0,0,0,.25);margin:16px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px">
      <h3 style="font-size:18px;font-weight:800;color:#0F172A;margin:0"><i class="fa-solid fa-key" style="color:#3B82F6;margin-right:8px"></i>Change Password</h3>
      <button onclick="closeChangePw()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#94A3B8;line-height:1;padding:4px">✕</button>
    </div>
    <div id="changePwAlert" style="display:none;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:16px"></div>
    <form id="changePwForm" onsubmit="submitChangePw(event)">
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:12px;font-weight:700;color:#64748B;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px">Current Password</label>
        <div style="position:relative">
          <input type="password" id="cpOld" required placeholder="Enter current password"
            style="width:100%;padding:10px 40px 10px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;box-sizing:border-box;transition:border-color .2s"
            onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#E2E8F0'">
          <button type="button" onclick="togglePw('cpOld',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94A3B8;font-size:13px"><i class="fa-regular fa-eye"></i></button>
        </div>
      </div>
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:12px;font-weight:700;color:#64748B;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px">New Password</label>
        <div style="position:relative">
          <input type="password" id="cpNew" required minlength="8" placeholder="At least 8 characters"
            style="width:100%;padding:10px 40px 10px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;box-sizing:border-box;transition:border-color .2s"
            onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#E2E8F0'">
          <button type="button" onclick="togglePw('cpNew',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94A3B8;font-size:13px"><i class="fa-regular fa-eye"></i></button>
        </div>
      </div>
      <div style="margin-bottom:24px">
        <label style="display:block;font-size:12px;font-weight:700;color:#64748B;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px">Confirm New Password</label>
        <div style="position:relative">
          <input type="password" id="cpConfirm" required placeholder="Repeat new password"
            style="width:100%;padding:10px 40px 10px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;box-sizing:border-box;transition:border-color .2s"
            onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#E2E8F0'">
          <button type="button" onclick="togglePw('cpConfirm',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94A3B8;font-size:13px"><i class="fa-regular fa-eye"></i></button>
        </div>
      </div>
      <button type="submit" id="cpSubmitBtn"
        style="width:100%;padding:12px;background:linear-gradient(135deg,#2563EB,#3B82F6);color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;transition:opacity .2s">
        Update Password
      </button>
    </form>
  </div>
</div>

<style>
.nav-user-wrap{position:relative}
.nav-user{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);padding:6px 14px 6px 8px;border-radius:9px;font-size:13px;color:rgba(255,255,255,.7);cursor:pointer;transition:background .2s}
.nav-user:hover{background:rgba(255,255,255,.1)}
.nav-user-dropdown{display:none;position:absolute;top:calc(100% + 8px);right:0;background:#1A1F2E;border:1px solid rgba(255,255,255,.1);border-radius:14px;min-width:220px;box-shadow:0 16px 48px rgba(0,0,0,.5);z-index:2000;overflow:hidden;animation:ddIn .15s ease}
.nav-user-dropdown.open{display:block}
@keyframes ddIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.nav-dd-header{padding:14px 16px}
.nav-dd-divider{height:1px;background:rgba(255,255,255,.08);margin:0}
.nav-dd-item{display:flex;align-items:center;gap:10px;width:100%;padding:12px 16px;font-size:13px;font-weight:500;color:rgba(255,255,255,.7);background:none;border:none;cursor:pointer;text-decoration:none;transition:background .15s;text-align:left}
.nav-dd-item:hover{background:rgba(255,255,255,.07);color:#fff}
.nav-dd-logout{color:#F87171!important}
.nav-dd-logout:hover{background:rgba(239,68,68,.1)!important}
</style>

<script>
function toggleUserMenu(e) {
  e.stopPropagation();
  document.getElementById('navUserDropdown').classList.toggle('open');
}
document.addEventListener('click', function() {
  document.getElementById('navUserDropdown')?.classList.remove('open');
});

function openChangePw() {
  document.getElementById('navUserDropdown').classList.remove('open');
  const ov = document.getElementById('changePwOverlay');
  ov.style.display = 'flex';
  document.getElementById('cpOld').value = '';
  document.getElementById('cpNew').value = '';
  document.getElementById('cpConfirm').value = '';
  hideAlert();
  setTimeout(() => document.getElementById('cpOld').focus(), 80);
}
function closeChangePw() {
  document.getElementById('changePwOverlay').style.display = 'none';
}
document.getElementById('changePwOverlay')?.addEventListener('click', function(e) {
  if (e.target === this) closeChangePw();
});

function togglePw(id, btn) {
  const inp = document.getElementById(id);
  const showing = inp.type === 'text';
  inp.type = showing ? 'password' : 'text';
  btn.innerHTML = showing ? '<i class="fa-regular fa-eye"></i>' : '<i class="fa-regular fa-eye-slash"></i>';
}

function showAlert(msg, ok) {
  const el = document.getElementById('changePwAlert');
  el.style.display = 'block';
  el.style.background = ok ? '#ECFDF5' : '#FEF2F2';
  el.style.color = ok ? '#065F46' : '#991B1B';
  el.style.border = '1px solid ' + (ok ? '#A7F3D0' : '#FECACA');
  el.textContent = msg;
}
function hideAlert() { document.getElementById('changePwAlert').style.display = 'none'; }

async function submitChangePw(e) {
  e.preventDefault();
  const old = document.getElementById('cpOld').value;
  const nw  = document.getElementById('cpNew').value;
  const cnf = document.getElementById('cpConfirm').value;
  if (nw !== cnf) { showAlert('New passwords do not match.', false); return; }
  if (nw.length < 8) { showAlert('Password must be at least 8 characters.', false); return; }
  const btn = document.getElementById('cpSubmitBtn');
  btn.disabled = true; btn.textContent = 'Updating…';
  try {
    const r = await fetch('/api/change_password.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({old_password: old, new_password: nw})
    });
    const d = await r.json();
    if (d.success) {
      showAlert('Password updated successfully!', true);
      setTimeout(closeChangePw, 1400);
    } else {
      showAlert(d.error || 'Failed to update password.', false);
    }
  } catch(err) { showAlert('Network error. Please try again.', false); }
  btn.disabled = false; btn.textContent = 'Update Password';
}
</script>
