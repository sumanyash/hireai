<?php
require_once __DIR__ . '/includes/auth_check.php';

$user_role_key = strtolower(str_replace([' ', '-'], '_', trim((string)($user['role'] ?? ''))));
if ($user_role_key !== 'super_admin') {
    http_response_code(403);
    exit('Only Super Admin can manage admin logins.');
}

$msg = $_GET['msg'] ?? '';

// Toggle active status (CSRF-protected GET)
if (isset($_GET['action']) && $_GET['action'] === 'toggle_active') {
    $sent = $_GET['csrf_token'] ?? '';
    if (!$sent || !hash_equals(csrf_token(), $sent)) { http_response_code(419); exit('Invalid token.'); }
    $tid = (int)($_GET['uid'] ?? 0);
    if ($tid && $tid !== (int)$user['user_id']) {
        $row = db_fetch_one("SELECT is_active FROM users WHERE id=? AND org_id=?", [$tid, $user['org_id']], 'ii');
        if ($row) {
            $new = $row['is_active'] ? 0 : 1;
            db_execute("UPDATE users SET is_active=? WHERE id=? AND org_id=?", [$new, $tid, $user['org_id']], 'iii');
            audit_log($user['org_id'], $user['user_id'] ?? null, 'user', $tid, $new ? 'admin_activated' : 'admin_deactivated');
        }
    }
    header('Location: admins.php?msg=toggled'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    $post_action = $_POST['admin_action'] ?? 'create_admin';

    if ($post_action === 'change_own_password') {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $row = db_fetch_one("SELECT password_hash FROM users WHERE id=? AND org_id=?", [$user['user_id'], $user['org_id']], 'ii');
        if (!$row || !password_verify($old, $row['password_hash']) || strlen($new) < 8 || $new !== $confirm) {
            header('Location: admins.php?msg=password_invalid'); exit;
        }
        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        db_execute("UPDATE users SET password_hash=? WHERE id=? AND org_id=?", [$hash, $user['user_id'], $user['org_id']], 'sii');
        audit_log($user['org_id'], $user['user_id'] ?? null, 'user', $user['user_id'], 'own_password_changed');
        header('Location: admins.php?msg=password_changed'); exit;
    }

    if ($post_action === 'reset_password') {
        $target_id = (int)($_POST['uid'] ?? 0);
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $target = db_fetch_one("SELECT id,email,name FROM users WHERE id=? AND org_id=?", [$target_id, $user['org_id']], 'ii');
        if (!$target || strlen($new) < 8 || $new !== $confirm) {
            header('Location: admins.php?msg=reset_invalid'); exit;
        }
        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        db_execute("UPDATE users SET password_hash=? WHERE id=? AND org_id=?", [$hash, $target_id, $user['org_id']], 'sii');
        audit_log($user['org_id'], $user['user_id'] ?? null, 'user', $target_id, 'admin_password_reset', ['email' => $target['email']]);
        header('Location: admins.php?msg=password_reset'); exit;
    }

    if ($post_action === 'update_role') {
        $target_id = (int)($_POST['uid'] ?? 0);
        $role = $_POST['role'] ?? 'hr';
        if (!in_array($role, ['super_admin','admin','hr','recruiter'], true)) $role = 'hr';
        if ($target_id && $target_id !== (int)$user['user_id']) {
            $target = db_fetch_one("SELECT id,email FROM users WHERE id=? AND org_id=?", [$target_id, $user['org_id']], 'ii');
            if ($target) {
                db_execute("UPDATE users SET role=? WHERE id=? AND org_id=?", [$role, $target_id, $user['org_id']], 'sii');
                audit_log($user['org_id'], $user['user_id'] ?? null, 'user', $target_id, 'admin_role_updated', ['email' => $target['email'], 'role' => $role]);
            }
        }
        header('Location: admins.php?msg=role_updated'); exit;
    }

    if ($post_action === 'create_admin') {
        $name     = trim($_POST['name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $role     = $_POST['role'] ?? 'hr';
        $password = (string)($_POST['password'] ?? '');
        if (!in_array($role, ['super_admin','admin','hr','recruiter'], true)) $role = 'hr';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            header('Location: admins.php?msg=invalid'); exit;
        }
        $exists = db_fetch_one("SELECT id FROM users WHERE email=?", [$email], 's');
        if ($exists) { header('Location: admins.php?msg=exists'); exit; }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $id   = db_insert(
            "INSERT INTO users (org_id,name,email,password_hash,role,is_active) VALUES (?,?,?,?,?,1)",
            [$user['org_id'], $name, $email, $hash, $role], 'issss'
        );
        audit_log($user['org_id'], $user['user_id'] ?? null, 'user', $id, 'admin_user_created', ['email' => $email, 'role' => $role]);
        header('Location: admins.php?msg=created'); exit;
    }

    header('Location: admins.php?msg=invalid'); exit;
}

$admin_page = pagination_page('admin_page');
$admin_per_page = pagination_per_page('admin_per_page', 10);
$admin_total_row = db_fetch_one("SELECT COUNT(*) cnt FROM users WHERE org_id=?", [$user['org_id']], 'i');
$admin_total = (int)($admin_total_row['cnt'] ?? 0);
$admin_total_pages = max(1, (int)ceil($admin_total / $admin_per_page));
$admin_page = min($admin_page, $admin_total_pages);
$admin_offset = ($admin_page - 1) * $admin_per_page;
$admins = db_fetch_all(
    "SELECT id,name,email,role,is_active,created_at FROM users WHERE org_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?",
    [$user['org_id'], $admin_per_page, $admin_offset],
    'iii'
);
$admin_stats = db_fetch_one(
    "SELECT COUNT(*) total,
            SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) active,
            SUM(CASE WHEN is_active=0 THEN 1 ELSE 0 END) disabled,
            SUM(CASE WHEN role='super_admin' THEN 1 ELSE 0 END) super_admins
     FROM users WHERE org_id=?",
    [$user['org_id']],
    'i'
) ?: ['total'=>0,'active'=>0,'disabled'=>0,'super_admins'=>0];
$audit_page = pagination_page('audit_page');
$audit_per_page = pagination_per_page('audit_per_page', 10);
$audit_total_row = db_fetch_one("SELECT COUNT(*) cnt FROM audit_logs WHERE org_id=?", [$user['org_id']], 'i');
$audit_total = (int)($audit_total_row['cnt'] ?? 0);
$audit_total_pages = max(1, (int)ceil($audit_total / $audit_per_page));
$audit_page = min($audit_page, $audit_total_pages);
$audit_offset = ($audit_page - 1) * $audit_per_page;
$audit_logs = db_fetch_all(
    "SELECT al.*, COALESCE(u.name, 'System') actor_name, u.email actor_email
     FROM audit_logs al
     LEFT JOIN users u ON u.id=al.user_id
     WHERE al.org_id=?
     ORDER BY al.created_at DESC
     LIMIT ? OFFSET ?",
    [$user['org_id'], $audit_per_page, $audit_offset],
    'iii'
);
$csrf   = csrf_token();

$role_colors = [
    'super_admin' => ['bg'=>'#EDE9FE','color'=>'#6D28D9','label'=>'Super Admin'],
    'admin'       => ['bg'=>'#F3E8FF','color'=>'#7E22CE','label'=>'Admin'],
    'hr'          => ['bg'=>'#DBEAFE','color'=>'#1D4ED8','label'=>'HR Admin'],
    'recruiter'   => ['bg'=>'#D1FAE5','color'=>'#065F46','label'=>'Recruiter'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admins — HireAI</title>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
  .admins-grid{display:grid;grid-template-columns:380px minmax(0,1fr);gap:18px;align-items:start}
  @media(max-width:900px){.admins-grid{grid-template-columns:1fr}.admins-grid .card{overflow-x:auto}}
  .role-pill{display:inline-flex;align-items:center;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800;letter-spacing:.3px}
  .active-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700}
  .active-badge.on{background:#D1FAE5;color:#065F46}
  .active-badge.off{background:#FEE2E2;color:#991B1B}
  .toggle-btn{border:none;background:transparent;cursor:pointer;font-size:12px;font-weight:700;padding:5px 10px;border-radius:8px;transition:background .15s}
  .toggle-btn.deactivate{color:#DC2626;background:#FEE2E2}
  .toggle-btn.deactivate:hover{background:#FECACA}
  .toggle-btn.activate{color:#16A34A;background:#DCFCE7}
  .toggle-btn.activate:hover{background:#BBF7D0}
  .own-badge{font-size:10px;color:#94A3B8;margin-left:4px}
  .admin-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}
  .admin-stat{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:16px 18px;box-shadow:0 1px 8px rgba(15,23,42,.05)}
  .admin-stat b{display:block;font-size:26px;line-height:1;color:#0F172A}
  .admin-stat span{display:block;margin-top:4px;font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.6px}
  .security-card{margin-top:18px}
  .mini-form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .mini-input,.mini-select{height:34px;border:1.5px solid #E2E8F0;border-radius:8px;padding:6px 9px;font-size:12px;background:#fff;color:#0F172A;min-width:150px}
  .mini-btn{height:34px;border:none;border-radius:8px;padding:0 12px;font-size:12px;font-weight:800;cursor:pointer;background:#7C3AED;color:#fff}
  .mini-btn.secondary{background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE}
  .row-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .reset-box{margin-top:8px;padding:10px;border:1px solid #E2E8F0;border-radius:10px;background:#F8FAFC;display:none}
  .reset-box.active{display:block}
  .audit-table td{vertical-align:top}
  .audit-action{font-weight:800;color:#4338CA}
  .audit-details{max-width:420px;color:#64748B;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  @media(max-width:900px){.admin-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.mini-input,.mini-select{min-width:120px}}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:center">
    <div><h2>Admin Logins</h2><p>Create and manage logins for each recruiter. Deactivate instantly when access should be revoked.</p></div>
  </div>

  <?php if ($msg): ?>
    <?php $is_err = in_array($msg, ['invalid','exists','password_invalid','reset_invalid'], true); ?>
    <div class="alert <?= $is_err ? 'alert-error' : 'alert-success' ?>">
      <?php
        echo match($msg) {
          'created' => '✅ Admin login created successfully.',
          'exists'  => '❌ That email address already exists.',
          'toggled' => '✅ Admin status updated.',
          'password_changed' => '✅ Your password was changed successfully.',
          'password_reset' => '✅ User password reset successfully.',
          'role_updated' => '✅ User role updated successfully.',
          'password_invalid' => '⚠️ Current password is incorrect, or the new passwords do not match.',
          'reset_invalid' => '⚠️ Password reset failed. Use matching passwords of at least 8 characters.',
          'invalid' => '⚠️ Please enter a valid name, email, and password of at least 8 characters.',
          default   => htmlspecialchars(str_replace('_',' ',$msg)),
        };
      ?>
    </div>
  <?php endif; ?>

  <div class="admin-stats">
    <div class="admin-stat"><b><?= (int)$admin_stats['total'] ?></b><span>Total Logins</span></div>
    <div class="admin-stat"><b><?= (int)$admin_stats['active'] ?></b><span>Active</span></div>
    <div class="admin-stat"><b><?= (int)$admin_stats['disabled'] ?></b><span>Disabled</span></div>
    <div class="admin-stat"><b><?= (int)$admin_stats['super_admins'] ?></b><span>Super Admins</span></div>
  </div>

  <div class="admins-grid">
    <!-- CREATE FORM -->
    <div class="card">
      <div class="card-header"><h3>Create Admin</h3></div>
      <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="admin_action" value="create_admin">
        <div class="form-group">
          <label class="form-label">Name *</label>
          <input class="form-control" name="name" required placeholder="Recruiter Name">
        </div>
        <div class="form-group">
          <label class="form-label">Email *</label>
          <input class="form-control" name="email" type="email" required placeholder="admin@client.com">
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <select class="form-control" name="role">
            <option value="hr">HR Admin</option>
            <option value="admin">Admin</option>
            <option value="recruiter">Recruiter</option>
            <option value="super_admin">Super Admin</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Temporary Password *</label>
          <input class="form-control" name="password" type="password" required minlength="8" placeholder="At least 8 characters">
        </div>
        <button class="btn-primary" type="submit">Create Login</button>
      </form>

      <div class="security-card">
        <div class="card-header" style="padding:0 0 12px;margin-top:22px"><h3><i class="fa-solid fa-key" style="color:#7C3AED"></i> Change My Password</h3></div>
        <form method="POST">
          <?= csrf_input() ?>
          <input type="hidden" name="admin_action" value="change_own_password">
          <div class="form-group">
            <label class="form-label">Current Password</label>
            <input class="form-control" name="old_password" type="password" autocomplete="current-password" required>
          </div>
          <div class="form-group">
            <label class="form-label">New Password</label>
            <input class="form-control" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm New Password</label>
            <input class="form-control" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
          </div>
          <button class="btn-outline" type="submit" style="padding:10px 14px">Update Password</button>
        </form>
      </div>
    </div>

    <!-- EXISTING LOGINS -->
    <div class="card">
      <div class="card-header"><h3>Existing Logins <span style="color:#94A3B8;font-size:13px;font-weight:500">(<?= $admin_total ?>)</span></h3></div>
      <div class="pager-top">Show <?= pagination_per_page_select('admin_per_page', 'admin_page', $admin_per_page) ?> entries</div>
      <table class="table">
        <thead>
          <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Since</th><th>Security Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($admins as $a):
            $rc  = $role_colors[$a['role']] ?? ['bg'=>'#F1F5F9','color'=>'#64748B','label'=>ucfirst($a['role'])];
            $own = ((int)$a['id'] === (int)$user['user_id']);
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($a['name']) ?></strong><?php if($own):?><span class="own-badge">(you)</span><?php endif;?></td>
            <td style="font-size:13px;color:#64748B"><?= htmlspecialchars($a['email']) ?></td>
            <td>
              <span class="role-pill" style="background:<?= $rc['bg'] ?>;color:<?= $rc['color'] ?>">
                <?= htmlspecialchars($rc['label']) ?>
              </span>
            </td>
            <td>
              <span class="active-badge <?= $a['is_active'] ? 'on' : 'off' ?>">
                <?= $a['is_active'] ? '● Active' : '○ Disabled' ?>
              </span>
            </td>
            <td style="font-size:12px;color:#94A3B8"><?= date('d M Y', strtotime($a['created_at'])) ?></td>
            <td>
              <div class="row-actions">
                <?php if (!$own): ?>
                  <a href="admins.php?action=toggle_active&uid=<?= $a['id'] ?>&csrf_token=<?= urlencode($csrf) ?>"
                     class="toggle-btn <?= $a['is_active'] ? 'deactivate' : 'activate' ?>"
                     onclick="return confirm('<?= $a['is_active'] ? 'Deactivate this admin?' : 'Re-activate this admin?' ?>')">
                    <?= $a['is_active'] ? 'Deactivate' : 'Activate' ?>
                  </a>
                  <button type="button" class="toggle-btn activate" onclick="toggleResetBox(<?= (int)$a['id'] ?>)">Reset Password</button>
                  <form method="POST" class="mini-form" onsubmit="return confirm('Change role for <?= htmlspecialchars(addslashes($a['name'])) ?>?')">
                    <?= csrf_input() ?>
                    <input type="hidden" name="admin_action" value="update_role">
                    <input type="hidden" name="uid" value="<?= (int)$a['id'] ?>">
                    <select name="role" class="mini-select">
                      <?php foreach (['hr'=>'HR Admin','admin'=>'Admin','recruiter'=>'Recruiter','super_admin'=>'Super Admin'] as $rk => $rl): ?>
                      <option value="<?= $rk ?>" <?= $a['role'] === $rk ? 'selected' : '' ?>><?= $rl ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="mini-btn secondary" type="submit">Save Role</button>
                  </form>
                <?php else: ?>
                  <span class="own-badge">Protected current session</span>
                <?php endif; ?>
              </div>
              <?php if (!$own): ?>
              <form method="POST" class="reset-box" id="resetBox-<?= (int)$a['id'] ?>" onsubmit="return confirm('Reset password for <?= htmlspecialchars(addslashes($a['name'])) ?>?')">
                <?= csrf_input() ?>
                <input type="hidden" name="admin_action" value="reset_password">
                <input type="hidden" name="uid" value="<?= (int)$a['id'] ?>">
                <input class="mini-input" name="new_password" type="password" minlength="8" placeholder="New password" autocomplete="new-password" required>
                <input class="mini-input" name="confirm_password" type="password" minlength="8" placeholder="Confirm password" autocomplete="new-password" required>
                <button class="mini-btn" type="submit">Reset</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?= pagination_html('admin_page', $admin_page, $admin_total_pages, $admin_total, $admin_per_page) ?>
    </div>
  </div>

  <div class="card" style="margin-top:18px">
    <div class="card-header">
      <h3><i class="fa-solid fa-shield-halved" style="color:#7C3AED"></i> Audit Logs <span style="color:#94A3B8;font-size:13px;font-weight:500">(<?= $audit_total ?>)</span></h3>
    </div>
    <div class="pager-top">Show <?= pagination_per_page_select('audit_per_page', 'audit_page', $audit_per_page) ?> entries</div>
    <table class="table audit-table">
      <thead>
        <tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th></tr>
      </thead>
      <tbody>
        <?php if (empty($audit_logs)): ?>
        <tr><td colspan="5" style="text-align:center;color:#94A3B8;padding:24px">No audit activity yet.</td></tr>
        <?php else: foreach ($audit_logs as $log): ?>
        <tr>
          <td style="font-size:12px;color:#64748B"><?= htmlspecialchars(date('d M Y, H:i', strtotime($log['created_at']))) ?></td>
          <td>
            <strong><?= htmlspecialchars($log['actor_name']) ?></strong>
            <?php if (!empty($log['actor_email'])): ?><div style="font-size:11px;color:#94A3B8"><?= htmlspecialchars($log['actor_email']) ?></div><?php endif; ?>
          </td>
          <td><span class="audit-action"><?= htmlspecialchars(str_replace('_', ' ', $log['action'])) ?></span></td>
          <td style="font-size:12px;color:#64748B"><?= htmlspecialchars($log['entity_type']) ?> #<?= htmlspecialchars((string)($log['entity_id'] ?? '')) ?></td>
          <td><div class="audit-details" title="<?= htmlspecialchars((string)($log['details'] ?? '')) ?>"><?= htmlspecialchars((string)($log['details'] ?? '')) ?></div></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    <?= pagination_html('audit_page', $audit_page, $audit_total_pages, $audit_total, $audit_per_page) ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
<script>
function toggleResetBox(id) {
  const box = document.getElementById('resetBox-' + id);
  if (box) box.classList.toggle('active');
}
</script>
</body>
</html>
