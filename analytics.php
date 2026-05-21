<?php
require_once __DIR__ . '/includes/auth_check.php';
$oid = $user['org_id'];

$campaigns   = db_fetch_all("SELECT id,name FROM campaigns WHERE org_id=? ORDER BY created_at DESC", [$oid], 'i');
$campaign_id = (int)($_GET['campaign_id'] ?? 0);
$days_filter = (int)($_GET['days'] ?? 0); // 0=all, 7, 30, 90

$where  = "c.org_id=?";
$params = [$oid];
$types  = 'i';
if ($campaign_id) { $where .= " AND c.campaign_id=?"; $params[] = $campaign_id; $types .= 'i'; }
if ($days_filter > 0) { $where .= " AND c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"; $params[] = $days_filter; $types .= 'i'; }

// Funnel counts
$statusRows = db_fetch_all("SELECT c.status, COUNT(*) cnt FROM candidates c WHERE $where GROUP BY c.status", $params, $types);
$counts = [];
foreach ($statusRows as $r) $counts[$r['status']] = (int)$r['cnt'];
$total       = array_sum($counts);
$invited     = ($counts['outreach_sent']??0) + ($counts['interview_started']??0) + ($counts['interview_completed']??0) + ($counts['shortlisted']??0) + ($counts['rejected']??0);
$started     = ($counts['interview_started']??0) + ($counts['interview_completed']??0) + ($counts['shortlisted']??0) + ($counts['rejected']??0);
$completed   = ($counts['interview_completed']??0) + ($counts['shortlisted']??0) + ($counts['rejected']??0);
$shortlisted = $counts['shortlisted'] ?? 0;
$rejected    = $counts['rejected'] ?? 0;

// Weakest parameters
$lowScores = db_fetch_all(
    "SELECT s.parameter_label, ROUND(AVG(s.ai_score / GREATEST(s.max_marks,1) * 100),1) avg_pct, COUNT(*) samples
     FROM scores s JOIN candidates c ON s.candidate_id=c.id
     WHERE $where GROUP BY s.parameter_label HAVING samples>0 ORDER BY avg_pct ASC LIMIT 6",
    $params, $types
);

// Score distribution buckets (0-19, 20-39, 40-59, 60-79, 80-100)
$scoreBuckets = db_fetch_all(
    "SELECT
       SUM(ir.total_score < 20) b0,
       SUM(ir.total_score BETWEEN 20 AND 39) b20,
       SUM(ir.total_score BETWEEN 40 AND 59) b40,
       SUM(ir.total_score BETWEEN 60 AND 79) b60,
       SUM(ir.total_score >= 80) b80,
       AVG(ir.total_score) avg_score,
       MAX(ir.total_score) max_score,
       MIN(ir.total_score) min_score
     FROM interview_results ir JOIN candidates c ON ir.candidate_id=c.id
     WHERE $where",
    $params, $types
);
$sb       = $scoreBuckets[0] ?? [];
$avgScore = $sb['avg_score'] !== null ? round((float)$sb['avg_score'], 1) : null;
$chartData = [(int)($sb['b0']??0),(int)($sb['b20']??0),(int)($sb['b40']??0),(int)($sb['b60']??0),(int)($sb['b80']??0)];

// Daily completed trend (14 days)
$daily = db_fetch_all("SELECT DATE(c.updated_at) day, COUNT(*) cnt FROM candidates c WHERE $where AND c.status IN ('interview_completed','shortlisted','rejected') AND c.updated_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) GROUP BY DATE(c.updated_at) ORDER BY day ASC", $params, $types);
$trendLabels = []; $trendData = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('d M', strtotime($d));
    $f = array_values(array_filter($daily, fn($r) => $r['day'] === $d));
    $trendData[] = $f ? (int)$f[0]['cnt'] : 0;
}

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
.fnum{font-size:30px;font-weight:900;color:var(--accent);line-height:1}
.flbl{font-size:12px;color:var(--gray2);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-top:5px}
.bar{height:8px;background:#E2E8F0;border-radius:20px;margin-top:12px;overflow:hidden}
.fill{height:8px;background:linear-gradient(90deg,var(--blue),var(--accent));border-radius:20px}
.metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.metric-card{background:#fff;border-radius:14px;padding:16px 18px;border:1px solid rgba(0,0,0,.05);box-shadow:var(--card-shadow);text-align:center}
.metric-val{font-size:26px;font-weight:900;line-height:1}
.metric-lbl{font-size:11px;color:var(--gray);font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-top:4px}
.chart-wrap{position:relative;height:220px}
.filter-tabs{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.ftab{padding:6px 14px;border-radius:99px;font-size:12px;font-weight:700;cursor:pointer;border:1.5px solid #E2E8F0;background:#F8FAFC;color:var(--gray2);text-decoration:none;transition:all .15s}
.ftab.active,.ftab:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
@media(max-width:900px){.funnel{grid-template-columns:1fr 1fr}.funnel .fstep:last-child{grid-column:1/-1}.metric-grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">
  <div class="page-header" style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
    <div><h2>Analytics</h2><p>Funnel, scoring, and drop-off insights</p></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <!-- Campaign filter -->
      <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if ($days_filter): ?><input type="hidden" name="days" value="<?= $days_filter ?>"><?php endif; ?>
        <select name="campaign_id" class="form-control" style="font-size:13px;padding:7px 12px" onchange="this.form.submit()">
          <option value="">All campaigns</option>
          <?php foreach ($campaigns as $camp): ?>
          <option value="<?= $camp['id'] ?>" <?= $campaign_id === (int)$camp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($camp['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <!-- Time filter tabs -->
      <div class="filter-tabs">
        <?php $base = 'analytics.php' . ($campaign_id ? "?campaign_id=$campaign_id&" : '?'); ?>
        <a class="ftab <?= !$days_filter ? 'active' : '' ?>" href="<?= $base ?>days=0">All time</a>
        <a class="ftab <?= $days_filter===7  ? 'active' : '' ?>" href="<?= $base ?>days=7">7d</a>
        <a class="ftab <?= $days_filter===30 ? 'active' : '' ?>" href="<?= $base ?>days=30">30d</a>
        <a class="ftab <?= $days_filter===90 ? 'active' : '' ?>" href="<?= $base ?>days=90">90d</a>
      </div>
    </div>
  </div>

  <!-- Score KPI metrics -->
  <?php if ($avgScore !== null): ?>
  <div class="metric-grid">
    <div class="metric-card">
      <div class="metric-val" style="color:var(--accent)"><?= $avgScore ?></div>
      <div class="metric-lbl">Avg Score /100</div>
    </div>
    <div class="metric-card">
      <div class="metric-val" style="color:#10B981"><?= (int)($sb['max_score']??0) ?></div>
      <div class="metric-lbl">Highest Score</div>
    </div>
    <div class="metric-card">
      <div class="metric-val" style="color:#EF4444"><?= (int)($sb['min_score']??0) ?></div>
      <div class="metric-lbl">Lowest Score</div>
    </div>
    <div class="metric-card">
      <div class="metric-val" style="color:#F59E0B"><?= $shortlisted + $rejected > 0 ? round($shortlisted / ($shortlisted + $rejected) * 100) : 0 ?>%</div>
      <div class="metric-lbl">Selection Rate</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Funnel -->
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
    <!-- Score Distribution Chart -->
    <div class="card">
      <div class="card-header"><h3><i class="fa-solid fa-chart-bar" style="color:var(--accent)"></i> Score Distribution</h3>
        <?php if ($avgScore !== null): ?><span style="font-size:12px;color:var(--gray)">Avg: <?= $avgScore ?>/100</span><?php endif; ?>
      </div>
      <?php if (array_sum($chartData) > 0): ?>
      <div class="chart-wrap"><canvas id="scoreChart"></canvas></div>
      <?php else: ?>
      <p style="color:var(--gray);padding:20px 0">No scored interviews yet.</p>
      <?php endif; ?>
    </div>

    <!-- Completion Trend Chart -->
    <div class="card">
      <div class="card-header"><h3><i class="fa-solid fa-chart-line" style="color:var(--green)"></i> Completions (14 days)</h3></div>
      <?php if (array_sum($trendData) > 0): ?>
      <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
      <?php else: ?>
      <p style="color:var(--gray);padding:20px 0">No completions in this period.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid-2">
    <!-- AI Insights -->
    <div class="card">
      <div class="card-header"><h3><i class="fa-solid fa-lightbulb" style="color:var(--orange)"></i> AI Insights</h3></div>
      <div class="alert alert-info">Completion drop-off is <strong><?= $dropInsight ?>%</strong> from invite to completion.</div>
      <div class="alert <?= $shortlisted >= $rejected ? 'alert-success' : 'alert-error' ?>">
        Selection result: <strong><?= $shortlisted ?> shortlisted</strong> and <?= $rejected ?> rejected.
      </div>
      <?php if ($avgScore !== null && $avgScore < 50): ?>
      <div class="alert alert-error">Average score is below 50 — consider adjusting passing threshold or campaign questions.</div>
      <?php elseif ($avgScore !== null && $avgScore >= 75): ?>
      <div class="alert alert-success">Strong cohort — average score <?= $avgScore ?>/100.</div>
      <?php endif; ?>
    </div>

    <!-- Weakest Parameters -->
    <div class="card">
      <div class="card-header"><h3><i class="fa-solid fa-arrow-trend-down" style="color:var(--red)"></i> Weakest Parameters</h3></div>
      <?php if (empty($lowScores)): ?>
      <p style="color:var(--gray)">No scored interviews yet.</p>
      <?php else: foreach ($lowScores as $row): ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700">
          <span><?= htmlspecialchars($row['parameter_label']) ?></span>
          <span style="color:<?= $row['avg_pct']<40?'#EF4444':($row['avg_pct']<65?'#F59E0B':'#10B981') ?>"><?= $row['avg_pct'] ?>%</span>
        </div>
        <div class="bar"><div class="fill" style="width:<?= max(3,(int)$row['avg_pct']) ?>%;background:linear-gradient(90deg,<?= $row['avg_pct']<40?'#EF4444,#F59E0B':($row['avg_pct']<65?'#F59E0B,#FBBF24':'#10B981,#34D399') ?>)"></div></div>
        <div style="font-size:11px;color:var(--gray);margin-top:3px"><?= $row['samples'] ?> interview(s)</div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.color = '#64748B';

<?php if (array_sum($chartData) > 0): ?>
new Chart(document.getElementById('scoreChart'), {
  type: 'bar',
  data: {
    labels: ['0–19','20–39','40–59','60–79','80–100'],
    datasets: [{
      label: 'Candidates',
      data: <?= json_encode($chartData) ?>,
      backgroundColor: ['#FCA5A5','#FCD34D','#93C5FD','#6EE7B7','#34D399'],
      borderRadius: 8,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#F1F5F9' } },
      x: { grid: { display: false } }
    }
  }
});
<?php endif; ?>

<?php if (array_sum($trendData) > 0): ?>
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($trendLabels) ?>,
    datasets: [{
      label: 'Completed',
      data: <?= json_encode($trendData) ?>,
      borderColor: '#10B981',
      backgroundColor: 'rgba(16,185,129,.1)',
      tension: 0.4,
      fill: true,
      pointRadius: 4,
      pointBackgroundColor: '#10B981',
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#F1F5F9' } },
      x: { grid: { display: false }, ticks: { maxTicksLimit: 7 } }
    }
  }
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
