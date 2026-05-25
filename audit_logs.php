<?php
require_once __DIR__ . '/includes/auth_check.php';

$role_key = strtolower(str_replace([' ', '-'], '_', trim((string)($user['role'] ?? ''))));
if ($role_key !== 'super_admin') {
    http_response_code(403);
    exit('Only Super Admin can view audit logs.');
}

$entity_filter = trim((string)($_GET['entity'] ?? ''));
$action_filter = trim((string)($_GET['action_filter'] ?? ''));
$actor_filter  = (int)($_GET['actor_id'] ?? 0);
$q             = trim((string)($_GET['q'] ?? ''));

$where = "al.org_id=?";
$params = [$user['org_id']];
$types = 'i';
if ($entity_filter !== '') {
    $where .= " AND al.entity_type=?";
    $params[] = $entity_filter;
    $types .= 's';
}
if ($action_filter !== '') {
    $where .= " AND al.action=?";
    $params[] = $action_filter;
    $types .= 's';
}
if ($actor_filter > 0) {
    $where .= " AND al.user_id=?";
    $params[] = $actor_filter;
    $types .= 'i';
}
if ($q !== '') {
    $like = "%$q%";
    $where .= " AND (al.action LIKE ? OR al.entity_type LIKE ? OR CAST(al.entity_id AS CHAR) LIKE ? OR CAST(al.details AS CHAR) LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like, $like);
    $types .= 'ssssss';
}

$audit_page = pagination_page('audit_page');
$audit_per_page = pagination_per_page('audit_per_page', 10);
$total_row = db_fetch_one(
    "SELECT COUNT(*) cnt FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id WHERE $where",
    $params,
    $types
);
$audit_total = (int)($total_row['cnt'] ?? 0);
$audit_total_pages = max(1, (int)ceil($audit_total / $audit_per_page));
$audit_page = min($audit_page, $audit_total_pages);
$audit_offset = ($audit_page - 1) * $audit_per_page;

$audit_logs = db_fetch_all(
    "SELECT al.*, COALESCE(u.name, 'System') actor_name, u.email actor_email
     FROM audit_logs al
     LEFT JOIN users u ON u.id=al.user_id
     WHERE $where
     ORDER BY al.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$audit_per_page, $audit_offset]),
    $types . 'ii'
);
$actors = db_fetch_all(
    "SELECT DISTINCT u.id,u.name,u.email FROM users u JOIN audit_logs al ON al.user_id=u.id WHERE al.org_id=? ORDER BY u.name",
    [$user['org_id']],
    'i'
);
$entities = db_fetch_all("SELECT entity_type, COUNT(*) cnt FROM audit_logs WHERE org_id=? GROUP BY entity_type ORDER BY cnt DESC", [$user['org_id']], 'i');
$actions = db_fetch_all("SELECT action, COUNT(*) cnt FROM audit_logs WHERE org_id=? GROUP BY action ORDER BY cnt DESC LIMIT 80", [$user['org_id']], 'i');
$stats = db_fetch_one(
    "SELECT COUNT(*) total,
            SUM(created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) today,
            COUNT(DISTINCT user_id) actors,
            COUNT(DISTINCT entity_type) entity_types
     FROM audit_logs WHERE org_id=?",
    [$user['org_id']],
    'i'
) ?: ['total'=>0,'today'=>0,'actors'=>0,'entity_types'=>0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Audit Logs — HireAI</title>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
.audit-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:18px}
.audit-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
.audit-stat{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:16px 18px;box-shadow:0 1px 8px rgba(15,23,42,.05)}
.audit-stat b{display:block;font-size:26px;line-height:1;color:#0F172A}
.audit-stat span{display:block;margin-top:4px;font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.6px}
.audit-filters{display:grid;grid-template-columns:1fr 180px 220px 220px auto;gap:10px;align-items:end;margin-bottom:16px}
.audit-filters .form-control{font-size:13px;padding:9px 12px}
.audit-table td{vertical-align:top}
.audit-action{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;background:#EEF2FF;color:#4338CA;font-size:12px;font-weight:800}
.audit-details{max-width:520px;color:#64748B;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.actor-email{font-size:11px;color:#94A3B8;margin-top:2px}
.entity-pill{font-size:12px;color:#475569;background:#F1F5F9;border-radius:999px;padding:4px 9px;font-weight:700}
@media(max-width:1000px){.audit-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.audit-filters{grid-template-columns:1fr 1fr}.audit-filters button,.audit-filters a{width:100%}}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">
  <div class="audit-hero">
    <div class="page-header" style="margin:0">
      <h2>Audit Logs</h2>
      <p>Track important actions across campaigns, candidates, admins, outreach, and scoring.</p>
    </div>
  </div>

  <div class="audit-stats">
    <div class="audit-stat"><b><?= (int)$stats['total'] ?></b><span>Total Events</span></div>
    <div class="audit-stat"><b><?= (int)$stats['today'] ?></b><span>Last 24 Hours</span></div>
    <div class="audit-stat"><b><?= (int)$stats['actors'] ?></b><span>Actors</span></div>
    <div class="audit-stat"><b><?= (int)$stats['entity_types'] ?></b><span>Entity Types</span></div>
  </div>

  <div class="card">
    <div class="card-header"><h3><i class="fa-solid fa-shield-halved" style="color:#7C3AED"></i> Activity Trail <span style="color:#94A3B8;font-size:13px;font-weight:500">(<?= $audit_total ?>)</span></h3></div>
    <form method="GET" class="audit-filters">
      <div>
        <label class="form-label">Search</label>
        <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search action, user, details...">
      </div>
      <div>
        <label class="form-label">Entity</label>
        <select class="form-control" name="entity">
          <option value="">All entities</option>
          <?php foreach ($entities as $e): ?>
          <option value="<?= htmlspecialchars($e['entity_type']) ?>" <?= $entity_filter === $e['entity_type'] ? 'selected' : '' ?>><?= htmlspecialchars($e['entity_type']) ?> (<?= (int)$e['cnt'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Action</label>
        <select class="form-control" name="action_filter">
          <option value="">All actions</option>
          <?php foreach ($actions as $a): ?>
          <option value="<?= htmlspecialchars($a['action']) ?>" <?= $action_filter === $a['action'] ? 'selected' : '' ?>><?= htmlspecialchars(str_replace('_', ' ', $a['action'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Actor</label>
        <select class="form-control" name="actor_id">
          <option value="">All actors</option>
          <?php foreach ($actors as $actor): ?>
          <option value="<?= (int)$actor['id'] ?>" <?= $actor_filter === (int)$actor['id'] ? 'selected' : '' ?>><?= htmlspecialchars($actor['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn-primary" type="submit" style="padding:10px 14px">Filter</button>
        <a class="btn-outline" href="/audit_logs" style="padding:10px 14px;text-decoration:none">Clear</a>
      </div>
    </form>

    <div class="pager-top">Show <?= pagination_per_page_select('audit_per_page', 'audit_page', $audit_per_page) ?> entries</div>
    <table class="table audit-table">
      <thead>
        <tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th></tr>
      </thead>
      <tbody>
      <?php if (empty($audit_logs)): ?>
        <tr><td colspan="5" style="text-align:center;color:#94A3B8;padding:28px">No audit activity found.</td></tr>
      <?php else: foreach ($audit_logs as $log): ?>
        <tr>
          <td style="font-size:12px;color:#64748B;white-space:nowrap"><?= htmlspecialchars(date('d M Y, H:i', strtotime($log['created_at']))) ?></td>
          <td>
            <strong><?= htmlspecialchars($log['actor_name']) ?></strong>
            <?php if (!empty($log['actor_email'])): ?><div class="actor-email"><?= htmlspecialchars($log['actor_email']) ?></div><?php endif; ?>
          </td>
          <td><span class="audit-action"><?= htmlspecialchars(str_replace('_', ' ', $log['action'])) ?></span></td>
          <td><span class="entity-pill"><?= htmlspecialchars($log['entity_type']) ?> #<?= htmlspecialchars((string)($log['entity_id'] ?? '')) ?></span></td>
          <td><div class="audit-details" title="<?= htmlspecialchars((string)($log['details'] ?? '')) ?>"><?= htmlspecialchars((string)($log['details'] ?? '')) ?></div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <?= pagination_html('audit_page', $audit_page, $audit_total_pages, $audit_total, $audit_per_page) ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
