<?php
require_once __DIR__ . '/includes/auth_check.php';
$oid = $user['org_id'];
$campaigns = db_fetch_all("SELECT id,name FROM campaigns WHERE org_id=? ORDER BY created_at DESC", [$oid], 'i');
$campaign_id = (int)($_GET['campaign_id'] ?? 0);
$where = "c.org_id=?";
$params = [$oid];
$types = 'i';
if ($campaign_id) { $where .= " AND c.campaign_id=?"; $params[] = $campaign_id; $types .= 'i'; }

$statusRows = db_fetch_all("SELECT c.status, COUNT(*) cnt FROM candidates c WHERE $where GROUP BY c.status", $params, $types);
$counts = [];
foreach ($statusRows as $r) $counts[$r['status']] = (int)$r['cnt'];
$total = array_sum($counts);
$invited = ($counts['outreach_sent'] ?? 0) + ($counts['interview_started'] ?? 0) + ($counts['interview_completed'] ?? 0) + ($counts['shortlisted'] ?? 0) + ($counts['rejected'] ?? 0);
$started = ($counts['interview_started'] ?? 0) + ($counts['interview_completed'] ?? 0) + ($counts['shortlisted'] ?? 0) + ($counts['rejected'] ?? 0);
$completed = ($counts['interview_completed'] ?? 0) + ($counts['shortlisted'] ?? 0) + ($counts['rejected'] ?? 0);
$shortlisted = $counts['shortlisted'] ?? 0;
$rejected = $counts['rejected'] ?? 0;

$lowScores = db_fetch_all(
    "SELECT s.parameter_label, ROUND(AVG(s.ai_score / GREATEST(s.max_marks,1) * 100),1) avg_pct, COUNT(*) samples
     FROM scores s
     JOIN candidates c ON s.candidate_id=c.id
     WHERE $where
     GROUP BY s.parameter_label
     HAVING samples>0
     ORDER BY avg_pct ASC
     LIMIT 5",
    $params, $types
);

$dropInsight = $invited > 0 ? round(($invited - $completed) / $invited * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Analytics — HireAI</title>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
.funnel{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px}
.fstep{background:#fff;border-radius:16px;padding:18px;box-shadow:var(--card-shadow);border:1px solid rgba(0,0,0,.04)}
.fnum{font-size:30px;font-weight:900;color:var(--blue);line-height:1}
.flbl{font-size:12px;color:var(--gray2);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-top:5px}
.bar{height:8px;background:#E2E8F0;border-radius:20px;margin-top:12px;overflow:hidden}
.fill{height:8px;background:linear-gradient(90deg,var(--blue),var(--accent));border-radius:20px}
@media(max-width:900px){.funnel{grid-template-columns:1fr 1fr}.funnel .fstep:last-child{grid-column:1/-1}}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">
  <div class="page-header" style="display:flex;justify-content:space-between;gap:12px;align-items:center">
    <div><h2>Analytics</h2><p>Funnel, drop-off, and scoring insights</p></div>
    <form method="GET">
      <select name="campaign_id" class="form-control" onchange="this.form.submit()">
        <option value="">All campaigns</option>
        <?php foreach ($campaigns as $camp): ?>
        <option value="<?= $camp['id'] ?>" <?= $campaign_id === (int)$camp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($camp['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php $steps = [['Imported',$total],['Invited',$invited],['Started',$started],['Completed',$completed],['Shortlisted',$shortlisted]]; ?>
  <div class="funnel">
    <?php foreach ($steps as [$label,$value]): $pct = $total ? round($value/$total*100) : 0; ?>
    <div class="fstep animate-in">
      <div class="fnum"><?= $value ?></div>
      <div class="flbl"><?= $label ?></div>
      <div class="bar"><div class="fill" style="width:<?= $pct ?>%"></div></div>
      <div style="font-size:12px;color:var(--gray);margin-top:7px"><?= $pct ?>% of imported</div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="grid-2">
    <div class="card">
      <div class="card-header"><h3><i class="fa-solid fa-lightbulb" style="color:var(--orange)"></i> AI Insights</h3></div>
      <div class="alert alert-info">Completion drop-off is <strong><?= $dropInsight ?>%</strong> from invite to completion.</div>
      <div class="alert <?= $shortlisted >= $rejected ? 'alert-success' : 'alert-error' ?>">
        Selection result: <?= $shortlisted ?> shortlisted and <?= $rejected ?> rejected.
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><i class="fa-solid fa-arrow-trend-down" style="color:var(--red)"></i> Weakest Parameters</h3></div>
      <?php if (empty($lowScores)): ?>
      <p style="color:var(--gray)">No scored interviews yet.</p>
      <?php else: foreach ($lowScores as $row): ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700">
          <span><?= htmlspecialchars($row['parameter_label']) ?></span>
          <span><?= $row['avg_pct'] ?>%</span>
        </div>
        <div class="bar"><div class="fill" style="width:<?= max(3,(int)$row['avg_pct']) ?>%;background:linear-gradient(90deg,#EF4444,#F59E0B)"></div></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
