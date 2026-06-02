<?php
require_once __DIR__ . '/includes/auth_check.php';

$user_role_key = strtolower(str_replace([' ', '-'], '_', trim((string)($user['role'] ?? ''))));
if ($user_role_key !== 'super_admin') {
    http_response_code(403);
    exit('Only Super Admin can manage admin logins.');
}

$msg = $_GET['msg'] ?? '';

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
    [$user['org_id']], 'i'
) ?: ['total'=>0,'active'=>0,'disabled'=>0,'super_admins'=>0];
$csrf = csrf_token();

$role_meta = [
    'super_admin' => ['bg'=>'#EDE9FE','color'=>'#6D28D9','label'=>'Super Admin','icon'=>'fa-crown'],
    'admin'       => ['bg'=>'#F3E8FF','color'=>'#7E22CE','label'=>'Admin',       'icon'=>'fa-shield-halved'],
    'hr'          => ['bg'=>'#DBEAFE','color'=>'#1D4ED8','label'=>'HR Admin',    'icon'=>'fa-user-tie'],
    'recruiter'   => ['bg'=>'#D1FAE5','color'=>'#065F46','label'=>'Recruiter',   'icon'=>'fa-magnifying-glass'],
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
/* ── Layout ── */
.adm-shell{display:grid;grid-template-columns:360px minmax(0,1fr);gap:22px;align-items:start}

/* ── Stats strip ── */
.adm-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.adm-stat{background:#fff;border:1px solid #E8ECF0;border-radius:16px;padding:18px 20px;box-shadow:0 1px 6px rgba(0,0,0,.05);display:flex;align-items:center;gap:14px}
.adm-stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.adm-stat-val{font-size:26px;font-weight:900;line-height:1;letter-spacing:-.5px;color:#0F172A}
.adm-stat-lbl{font-size:10.5px;font-weight:700;color:#94A3B8;text-transform:uppercase;letter-spacing:.5px;margin-top:3px}

/* ── Left panel ── */
.adm-panel{background:#fff;border:1px solid #E8ECF0;border-radius:18px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden;position:sticky;top:88px}
.adm-panel-head{padding:20px 24px 16px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;gap:10px}
.adm-panel-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.adm-panel-title{font-size:15px;font-weight:800;color:#0F172A;letter-spacing:-.2px}
.adm-panel-sub{font-size:11.5px;color:#94A3B8;margin-top:1px}
.adm-panel-body{padding:20px 24px 24px}
.adm-field{margin-bottom:16px}
.adm-label{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;display:block}
.adm-input,.adm-select{width:100%;padding:9px 12px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:13px;font-family:inherit;color:#0F172A;background:#FAFBFC;outline:none;transition:border-color .14s,box-shadow .14s}
.adm-input:focus,.adm-select:focus{border-color:#7C3AED;background:#fff;box-shadow:0 0 0 3px rgba(124,58,237,.1)}
.adm-submit{width:100%;padding:11px;background:linear-gradient(135deg,#6D28D9,#4F46E5);color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:800;cursor:pointer;transition:opacity .13s;letter-spacing:.1px}
.adm-submit:hover{opacity:.9}
.adm-divider{border:none;border-top:1px solid #EEF2F7;margin:22px 0}
.adm-submit-outline{width:100%;padding:10px;background:#fff;color:#374151;border:1.5px solid #E2E8F0;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;transition:all .13s}
.adm-submit-outline:hover{border-color:#7C3AED;color:#6D28D9}

/* ── Right panel: user cards ── */
.adm-right{background:#fff;border:1px solid #E8ECF0;border-radius:18px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden}
.adm-right-head{padding:18px 22px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.adm-right-title{font-size:15px;font-weight:800;color:#0F172A;display:flex;align-items:center;gap:8px}

/* ── User rows ── */
.adm-user{padding:16px 22px;border-bottom:1px solid #F1F5F9;transition:background .12s}
.adm-user:last-child{border-bottom:none}
.adm-user:hover{background:#FAFBFF}
.adm-user-top{display:flex;align-items:center;gap:12px}
.adm-avatar{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;color:#fff;flex-shrink:0;letter-spacing:-.5px}
.adm-user-info{flex:1;min-width:0}
.adm-user-name{font-size:14px;font-weight:800;color:#0F172A;display:flex;align-items:center;gap:7px}
.adm-you-badge{font-size:10px;background:#EDE9FE;color:#6D28D9;border-radius:4px;padding:1px 6px;font-weight:700}
.adm-user-email{font-size:12px;color:#64748B;margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.adm-user-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:2px}
.adm-role-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:99px;font-size:10.5px;font-weight:800}
.adm-status{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px}
.adm-status.on{background:#F0FDF4;color:#15803D}
.adm-status.off{background:#FFF1F2;color:#BE123C}
.adm-since{font-size:11px;color:#CBD5E1;font-weight:600}

/* ── Per-row action buttons ── */
.adm-actions{display:flex;align-items:center;gap:6px;margin-top:11px;flex-wrap:wrap}
.adm-act{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:8px;font-size:11.5px;font-weight:700;border:1.5px solid transparent;cursor:pointer;transition:all .13s;text-decoration:none;background:none}
.adm-act.ghost{border-color:#E2E8F0;color:#374151;background:#F8FAFC}
.adm-act.ghost:hover{border-color:#7C3AED;color:#6D28D9;background:#F5F3FF}
.adm-act.danger{border-color:#FECDD3;color:#BE123C;background:#FFF1F2}
.adm-act.danger:hover{background:#FFE4E6;border-color:#FCA5A5}
.adm-act.success{border-color:#BBF7D0;color:#15803D;background:#F0FDF4}
.adm-act.success:hover{background:#DCFCE7;border-color:#86EFAC}
.adm-act.purple{border-color:#DDD6FE;color:#6D28D9;background:#EDE9FE}
.adm-act.purple:hover{background:#DDD6FE}
.adm-protected{font-size:11.5px;color:#CBD5E1;font-weight:600;display:flex;align-items:center;gap:5px;padding:6px 0}

/* ── Expandable panels (reset pw + role) ── */
.adm-expand{display:none;margin-top:10px;padding:14px 16px;background:#F8FAFC;border:1px solid #E8ECF0;border-radius:12px}
.adm-expand.open{display:block}
.adm-expand-title{font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.adm-expand-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
.adm-mini-input{flex:1;min-width:130px;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:8px;font-size:12.5px;background:#fff;color:#0F172A;outline:none;transition:border-color .13s}
.adm-mini-input:focus{border-color:#7C3AED;box-shadow:0 0 0 2px rgba(124,58,237,.1)}
.adm-mini-select{padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:8px;font-size:12.5px;background:#fff;color:#0F172A;cursor:pointer;outline:none}
.adm-mini-btn{padding:7px 14px;border:none;border-radius:8px;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap}
.adm-mini-btn.primary{background:linear-gradient(135deg,#6D28D9,#4F46E5);color:#fff}
.adm-mini-btn.save{background:#EFF6FF;color:#1D4ED8;border:1.5px solid #BFDBFE}

@media(max-width:1100px){.adm-stats{grid-template-columns:1fr 1fr}}
@media(max-width:900px){.adm-shell{grid-template-columns:1fr}.adm-panel{position:static}}
@media(max-width:600px){.adm-stats{grid-template-columns:1fr 1fr}.adm-stat-val{font-size:22px}}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">

  <!-- Header -->
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
    <div>
      <h2 style="display:flex;align-items:center;gap:10px">
        <span style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#EDE9FE,#DBEAFE);display:inline-flex;align-items:center;justify-content:center;font-size:16px;color:#6D28D9"><i class="fa-solid fa-users-gear"></i></span>
        Admin Logins
      </h2>
      <p>Manage team access, roles, and credentials. Deactivate instantly when access should be revoked.</p>
    </div>
  </div>

  <!-- Alert -->
  <?php if ($msg): $is_err = in_array($msg, ['invalid','exists','password_invalid','reset_invalid'], true); ?>
  <div class="alert <?= $is_err ? 'alert-error' : 'alert-success' ?>" style="margin-bottom:20px">
    <?= match($msg) {
      'created'          => '<i class="fa-solid fa-circle-check"></i> Admin login created successfully.',
      'exists'           => '<i class="fa-solid fa-circle-xmark"></i> That email address already exists.',
      'toggled'          => '<i class="fa-solid fa-circle-check"></i> Admin status updated.',
      'password_changed' => '<i class="fa-solid fa-circle-check"></i> Your password was changed successfully.',
      'password_reset'   => '<i class="fa-solid fa-circle-check"></i> User password reset successfully.',
      'role_updated'     => '<i class="fa-solid fa-circle-check"></i> User role updated successfully.',
      'password_invalid' => '<i class="fa-solid fa-triangle-exclamation"></i> Current password incorrect, or new passwords do not match.',
      'reset_invalid'    => '<i class="fa-solid fa-triangle-exclamation"></i> Reset failed — passwords must match and be at least 8 characters.',
      'invalid'          => '<i class="fa-solid fa-triangle-exclamation"></i> Please fill all fields correctly (password min 8 chars).',
      default            => htmlspecialchars(str_replace('_',' ',$msg)),
    } ?>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="adm-stats">
    <div class="adm-stat">
      <div class="adm-stat-icon" style="background:#EDE9FE;color:#6D28D9"><i class="fa-solid fa-users"></i></div>
      <div><div class="adm-stat-val"><?= (int)$admin_stats['total'] ?></div><div class="adm-stat-lbl">Total Logins</div></div>
    </div>
    <div class="adm-stat">
      <div class="adm-stat-icon" style="background:#D1FAE5;color:#059669"><i class="fa-solid fa-circle-check"></i></div>
      <div><div class="adm-stat-val" style="color:#059669"><?= (int)$admin_stats['active'] ?></div><div class="adm-stat-lbl">Active</div></div>
    </div>
    <div class="adm-stat">
      <div class="adm-stat-icon" style="background:#FEE2E2;color:#DC2626"><i class="fa-solid fa-ban"></i></div>
      <div><div class="adm-stat-val" style="color:#DC2626"><?= (int)$admin_stats['disabled'] ?></div><div class="adm-stat-lbl">Disabled</div></div>
    </div>
    <div class="adm-stat">
      <div class="adm-stat-icon" style="background:#FEF3C7;color:#D97706"><i class="fa-solid fa-crown"></i></div>
      <div><div class="adm-stat-val" style="color:#D97706"><?= (int)$admin_stats['super_admins'] ?></div><div class="adm-stat-lbl">Super Admins</div></div>
    </div>
  </div>

  <div class="adm-shell">

    <!-- ── LEFT: Create + Change Password ── -->
    <div>
      <!-- Create Admin -->
      <div class="adm-panel" style="margin-bottom:18px">
        <div class="adm-panel-head">
          <div class="adm-panel-icon" style="background:linear-gradient(135deg,#EDE9FE,#DBEAFE);color:#6D28D9"><i class="fa-solid fa-user-plus"></i></div>
          <div>
            <div class="adm-panel-title">Create Admin Login</div>
            <div class="adm-panel-sub">Invite a new team member</div>
          </div>
        </div>
        <div class="adm-panel-body">
          <form method="POST" autocomplete="off">
            <?= csrf_input() ?>
            <input type="hidden" name="admin_action" value="create_admin">
            <div class="adm-field">
              <label class="adm-label">Full Name *</label>
              <input class="adm-input" name="name" required placeholder="e.g. Priya Sharma">
            </div>
            <div class="adm-field">
              <label class="adm-label">Email Address *</label>
              <input class="adm-input" name="email" type="email" required placeholder="recruiter@company.com">
            </div>
            <div class="adm-field">
              <label class="adm-label">Role</label>
              <select class="adm-select" name="role">
                <option value="recruiter">Recruiter — view &amp; manage candidates</option>
                <option value="hr">HR Admin — + outreach &amp; export</option>
                <option value="admin">Admin — + campaigns &amp; settings</option>
                <option value="super_admin">Super Admin — full access</option>
              </select>
            </div>
            <div class="adm-field" style="margin-bottom:20px">
              <label class="adm-label">Temporary Password *</label>
              <input class="adm-input" name="password" type="password" required minlength="8" placeholder="Min. 8 characters" autocomplete="new-password">
            </div>
            <button class="adm-submit" type="submit"><i class="fa-solid fa-plus fa-xs"></i> Create Login</button>
          </form>
        </div>
      </div>

      <!-- Change My Password -->
      <div class="adm-panel">
        <div class="adm-panel-head">
          <div class="adm-panel-icon" style="background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#B45309"><i class="fa-solid fa-key"></i></div>
          <div>
            <div class="adm-panel-title">Change My Password</div>
            <div class="adm-panel-sub">Update your own login credentials</div>
          </div>
        </div>
        <div class="adm-panel-body">
          <form method="POST" autocomplete="off">
            <?= csrf_input() ?>
            <input type="hidden" name="admin_action" value="change_own_password">
            <div class="adm-field">
              <label class="adm-label">Current Password</label>
              <input class="adm-input" name="old_password" type="password" autocomplete="current-password" required>
            </div>
            <div class="adm-field">
              <label class="adm-label">New Password</label>
              <input class="adm-input" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="adm-field" style="margin-bottom:20px">
              <label class="adm-label">Confirm New Password</label>
              <input class="adm-input" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
            </div>
            <button class="adm-submit-outline" type="submit"><i class="fa-solid fa-lock fa-xs"></i> Update Password</button>
          </form>
        </div>
      </div>
    </div>

    <!-- ── RIGHT: User list ── -->
    <div class="adm-right">
      <div class="adm-right-head">
        <div class="adm-right-title">
          <i class="fa-solid fa-id-card" style="color:#7C3AED;font-size:14px"></i>
          Team Members
          <span style="background:#EDE9FE;color:#6D28D9;border-radius:99px;padding:1px 10px;font-size:11px;font-weight:800"><?= $admin_total ?></span>
        </div>
        <div style="font-size:12px;color:#94A3B8;font-weight:600">
          Show <?= pagination_per_page_select('admin_per_page', 'admin_page', $admin_per_page) ?>
        </div>
      </div>

      <?php foreach ($admins as $a):
        $rm  = $role_meta[$a['role']] ?? ['bg'=>'#F1F5F9','color'=>'#64748B','label'=>ucfirst($a['role']),'icon'=>'fa-user'];
        $own = ((int)$a['id'] === (int)$user['user_id']);
        $initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', trim($a['name'])), 0, 2)));
        $avatarColors = ['#6D28D9','#2563EB','#059669','#D97706','#DC2626','#7C3AED','#0891B2'];
        $avatarBg = $avatarColors[crc32($a['email']) % count($avatarColors)];
      ?>
      <div class="adm-user">
        <div class="adm-user-top">
          <div class="adm-avatar" style="background:<?= $avatarBg ?>"><?= htmlspecialchars($initials ?: '?') ?></div>
          <div class="adm-user-info">
            <div class="adm-user-name">
              <?= htmlspecialchars($a['name']) ?>
              <?php if ($own): ?><span class="adm-you-badge">You</span><?php endif; ?>
            </div>
            <div class="adm-user-email"><?= htmlspecialchars($a['email']) ?></div>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0">
            <span class="adm-role-pill" style="background:<?= $rm['bg'] ?>;color:<?= $rm['color'] ?>">
              <i class="fa-solid <?= $rm['icon'] ?> fa-xs"></i> <?= $rm['label'] ?>
            </span>
            <span class="adm-status <?= $a['is_active'] ? 'on' : 'off' ?>">
              <i class="fa-solid fa-circle" style="font-size:7px"></i>
              <?= $a['is_active'] ? 'Active' : 'Disabled' ?>
            </span>
          </div>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;flex-wrap:wrap;gap:6px">
          <?php if (!$own): ?>
          <div class="adm-actions">
            <!-- Toggle active -->
            <a href="admins.php?action=toggle_active&uid=<?= $a['id'] ?>&csrf_token=<?= urlencode($csrf) ?>"
               class="adm-act <?= $a['is_active'] ? 'danger' : 'success' ?>"
               onclick="return confirm('<?= $a['is_active'] ? 'Deactivate' : 'Re-activate' ?> <?= htmlspecialchars(addslashes($a['name'])) ?>?')">
              <i class="fa-solid fa-<?= $a['is_active'] ? 'user-slash' : 'user-check' ?> fa-xs"></i>
              <?= $a['is_active'] ? 'Deactivate' : 'Activate' ?>
            </a>
            <!-- Reset password toggle -->
            <button type="button" class="adm-act ghost" onclick="toggleExpand('reset-<?= (int)$a['id'] ?>')">
              <i class="fa-solid fa-key fa-xs"></i> Reset Password
            </button>
            <!-- Role change toggle -->
            <button type="button" class="adm-act purple" onclick="toggleExpand('role-<?= (int)$a['id'] ?>')">
              <i class="fa-solid fa-user-pen fa-xs"></i> Change Role
            </button>
          </div>
          <?php else: ?>
          <div class="adm-protected"><i class="fa-solid fa-shield-check fa-xs"></i> Your session — protected from modification</div>
          <?php endif; ?>
          <span class="adm-since"><i class="fa-solid fa-calendar-days fa-xs" style="margin-right:3px"></i><?= date('d M Y', strtotime($a['created_at'])) ?></span>
        </div>

        <?php if (!$own): ?>
        <!-- Reset password expand -->
        <div class="adm-expand" id="reset-<?= (int)$a['id'] ?>">
          <div class="adm-expand-title"><i class="fa-solid fa-key fa-xs"></i> Reset Password for <?= htmlspecialchars($a['name']) ?></div>
          <form method="POST" onsubmit="return confirm('Reset password for <?= htmlspecialchars(addslashes($a['name'])) ?>?')">
            <?= csrf_input() ?>
            <input type="hidden" name="admin_action" value="reset_password">
            <input type="hidden" name="uid" value="<?= (int)$a['id'] ?>">
            <div class="adm-expand-row">
              <input class="adm-mini-input" name="new_password" type="password" minlength="8" placeholder="New password" autocomplete="new-password" required>
              <input class="adm-mini-input" name="confirm_password" type="password" minlength="8" placeholder="Confirm password" autocomplete="new-password" required>
              <button class="adm-mini-btn primary" type="submit"><i class="fa-solid fa-check fa-xs"></i> Reset</button>
            </div>
          </form>
        </div>
        <!-- Role change expand -->
        <div class="adm-expand" id="role-<?= (int)$a['id'] ?>">
          <div class="adm-expand-title"><i class="fa-solid fa-user-pen fa-xs"></i> Change Role for <?= htmlspecialchars($a['name']) ?></div>
          <form method="POST" onsubmit="return confirm('Change role for <?= htmlspecialchars(addslashes($a['name'])) ?>?')">
            <?= csrf_input() ?>
            <input type="hidden" name="admin_action" value="update_role">
            <input type="hidden" name="uid" value="<?= (int)$a['id'] ?>">
            <div class="adm-expand-row">
              <select name="role" class="adm-mini-select">
                <?php foreach (['recruiter'=>'Recruiter','hr'=>'HR Admin','admin'=>'Admin','super_admin'=>'Super Admin'] as $rk => $rl): ?>
                <option value="<?= $rk ?>" <?= $a['role'] === $rk ? 'selected' : '' ?>><?= $rl ?></option>
                <?php endforeach; ?>
              </select>
              <button class="adm-mini-btn save" type="submit"><i class="fa-solid fa-check fa-xs"></i> Save Role</button>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <div style="padding:14px 22px">
        <?= pagination_html('admin_page', $admin_page, $admin_total_pages, $admin_total, $admin_per_page) ?>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script>
function toggleExpand(id) {
  const el = document.getElementById(id);
  if (!el) return;
  // Close all other expands in the same user row first
  el.closest('.adm-user').querySelectorAll('.adm-expand').forEach(e => {
    if (e !== el) e.classList.remove('open');
  });
  el.classList.toggle('open');
}
</script>
</body>
</html>
