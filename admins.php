<?php
require_once __DIR__ . '/includes/auth_check.php';

if (($user['role'] ?? '') !== 'super_admin') {
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
    $name     = trim($_POST['name'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $role     = $_POST['role'] ?? 'hr';
    $password = (string)($_POST['password'] ?? '');
    if (!in_array($role, ['super_admin','hr','recruiter'], true)) $role = 'hr';
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

$admins = db_fetch_all("SELECT id,name,email,role,is_active,created_at FROM users WHERE org_id=? ORDER BY created_at DESC", [$user['org_id']], 'i');
$csrf   = csrf_token();

$role_colors = [
    'super_admin' => ['bg'=>'#EDE9FE','color'=>'#6D28D9','label'=>'Super Admin'],
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
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:center">
    <div><h2>Admin Logins</h2><p>Create and manage logins for each recruiter. Deactivate instantly when access should be revoked.</p></div>
  </div>

  <?php if ($msg): ?>
    <?php $is_err = in_array($msg, ['invalid','exists'], true); ?>
    <div class="alert <?= $is_err ? 'alert-error' : 'alert-success' ?>">
      <?php
        echo match($msg) {
          'created' => '✅ Admin login created successfully.',
          'exists'  => '❌ That email address already exists.',
          'toggled' => '✅ Admin status updated.',
          'invalid' => '⚠️ Please enter a valid name, email, and password of at least 8 characters.',
          default   => htmlspecialchars(str_replace('_',' ',$msg)),
        };
      ?>
    </div>
  <?php endif; ?>

  <div class="admins-grid">
    <!-- CREATE FORM -->
    <div class="card">
      <div class="card-header"><h3>Create Admin</h3></div>
      <form method="POST">
        <?= csrf_input() ?>
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
    </div>

    <!-- EXISTING LOGINS -->
    <div class="card">
      <div class="card-header"><h3>Existing Logins <span style="color:#94A3B8;font-size:13px;font-weight:500">(<?= count($admins) ?>)</span></h3></div>
      <table class="table">
        <thead>
          <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Since</th><th></th></tr>
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
              <?php if (!$own): ?>
                <a href="admins.php?action=toggle_active&uid=<?= $a['id'] ?>&csrf_token=<?= urlencode($csrf) ?>"
                   class="toggle-btn <?= $a['is_active'] ? 'deactivate' : 'activate' ?>"
                   onclick="return confirm('<?= $a['is_active'] ? 'Deactivate this admin?' : 'Re-activate this admin?' ?>')">
                  <?= $a['is_active'] ? 'Deactivate' : 'Activate' ?>
                </a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
