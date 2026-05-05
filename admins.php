<?php
require_once __DIR__ . '/includes/auth_check.php';

if (($user['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    exit('Only Super Admin can manage admin logins.');
}

$msg = $_GET['msg'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $role = $_POST['role'] ?? 'hr';
    $password = (string)($_POST['password'] ?? '');
    if (!in_array($role, ['super_admin','hr','recruiter'], true)) $role = 'hr';
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        header('Location: admins.php?msg=invalid'); exit;
    }
    $exists = db_fetch_one("SELECT id FROM users WHERE email=?", [$email], 's');
    if ($exists) {
        header('Location: admins.php?msg=exists'); exit;
    }
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $id = db_insert(
        "INSERT INTO users (org_id,name,email,password_hash,role,is_active) VALUES (?,?,?,?,?,1)",
        [$user['org_id'], $name, $email, $hash, $role],
        'issss'
    );
    audit_log($user['org_id'], $user['user_id'] ?? null, 'user', $id, 'admin_user_created', ['email' => $email, 'role' => $role]);
    header('Location: admins.php?msg=created'); exit;
}

$admins = db_fetch_all("SELECT id,name,email,role,is_active,created_at FROM users WHERE org_id=? ORDER BY created_at DESC", [$user['org_id']], 'i');
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
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:center">
    <div><h2>Admin Logins</h2><p>Create separate logins for every entity/recruiter so campaign reporting has clear ownership.</p></div>
  </div>

  <?php if ($msg): ?>
    <div class="alert <?= in_array($msg, ['invalid','exists'], true) ? 'alert-error' : 'alert-success' ?>">
      <?= $msg === 'created' ? 'Admin login created.' : ($msg === 'exists' ? 'Email already exists.' : 'Please enter valid name, email and password of at least 8 characters.') ?>
    </div>
  <?php endif; ?>

  <div class="admins-grid">
    <div class="card">
      <div class="card-header"><h3>Create Admin</h3></div>
      <form method="POST">
        <?= csrf_input() ?>
        <div class="form-group">
          <label class="form-label">Name *</label>
          <input class="form-control" name="name" required placeholder="Client Admin Name">
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

    <div class="card">
      <div class="card-header"><h3>Existing Logins</h3></div>
      <table class="table">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>
          <?php foreach ($admins as $admin): ?>
          <tr>
            <td><strong><?= htmlspecialchars($admin['name']) ?></strong></td>
            <td><?= htmlspecialchars($admin['email']) ?></td>
            <td><span class="badge badge-draft"><?= htmlspecialchars(str_replace('_', ' ', $admin['role'])) ?></span></td>
            <td><?= $admin['is_active'] ? 'Active' : 'Disabled' ?></td>
            <td><?= date('d M Y', strtotime($admin['created_at'])) ?></td>
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
