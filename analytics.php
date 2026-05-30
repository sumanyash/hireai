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

// Score distribution buckets
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
$sb        = $scoreBuckets[0] ?? [];
$avgScore  = $sb['avg_score'] !== null ? round((float)$sb['avg_score'], 1) : null;
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

$dropInsight    = $invited > 0 ? round(($invited - $completed) / $invited * 100) : 0;
$selectionRate  = ($shortlisted + $rejected) > 0 ? round($shortlisted / ($shortlisted + $rejected) * 100) : 0;
$completionRate = $invited > 0 ? round($completed / $invited * 100) : 0;
$startRate      = $invited > 0 ? round($started / $invited * 100) : 0;

// Conv rates between funnel steps
$conv = [
    $total   > 0 ? round($invited/$total*100)    : 0,
    $invited > 0 ? round($started/$invited*100)  : 0,
    $started > 0 ? round($completed/$started*100): 0,
    $completed>0 ? round($shortlisted/$completed*100):0,
];

$activeCampaignName = '';
if ($campaign_id) {
    foreach ($campaigns as $c) { if ((int)$c['id'] === $campaign_id) { $activeCampaignName = $c['name']; break; } }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Analytics — HireAI</title>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
/* ── Page shell ── */
.an-shell{display:flex;flex-direction:column;gap:0}

/* ── Hero banner ── */
.an-hero{background:linear-gradient(135deg,#0D1B2E 0%,#1A2744 50%,#2D1B69 100%);border-radius:20px;padding:28px 32px 32px;margin-bottom:28px;position:relative;overflow:hidden;color:#fff}
.an-hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");pointer-events:none}
.an-hero>*{position:relative}
.an-hero-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px}
.an-hero-title{font-size:26px;font-weight:900;letter-spacing:-.5px;margin-bottom:4px}
.an-hero-sub{font-size:13.5px;color:rgba(255,255,255,.65);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.an-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:99px;padding:3px 10px;font-size:11.5px;font-weight:700}
.an-filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.an-camp-select{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#fff;border-radius:10px;padding:8px 12px;font-size:12.5px;font-weight:600;cursor:pointer;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}
.an-camp-select option{background:#1A2744;color:#fff}
.an-camp-select:focus{outline:none;border-color:rgba(255,255,255,.4)}
.an-dtab{padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:1.5px solid rgba(255,255,255,.15);background:transparent;color:rgba(255,255,255,.6);text-decoration:none;transition:all .14s;white-space:nowrap}
.an-dtab.active,.an-dtab:hover{background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.3)}

/* ── Hero KPI strip ── */
.an-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.an-kpi{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:16px 18px;backdrop-filter:blur(4px)}
.an-kpi-val{font-size:32px;font-weight:900;line-height:1;letter-spacing:-1px}
.an-kpi-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:rgba(255,255,255,.55);margin-top:5px}
.an-kpi-sub{font-size:11.5px;margin-top:6px;opacity:.7}

/* ── Section headers ── */
.an-section{margin-bottom:28px}
.an-section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.an-section-title{font-size:13px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.7px;display:flex;align-items:center;gap:7px}
.an-section-title i{font-size:12px}

/* ── Funnel ── */
.an-funnel{display:grid;grid-template-columns:repeat(5,1fr) 0;gap:0;background:#fff;border:1px solid #E8ECF0;border-radius:18px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06)}
.an-fstep{padding:22px 18px;position:relative;border-right:1px solid #EEF2F7;transition:background .15s}
.an-fstep:last-child{border-right:none}
.an-fstep:hover{background:#FAFBFF}
.an-fstep-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;margin-bottom:12px}
.an-fstep-num{font-size:28px;font-weight:900;line-height:1;letter-spacing:-1px;color:#0F172A}
.an-fstep-lbl{font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;margin-top:4px}
.an-fbar{height:5px;background:#EEF2F7;border-radius:99px;margin-top:12px;overflow:hidden}
.an-fbar-fill{height:5px;border-radius:99px;transition:width .6s ease}
.an-fstep-pct{font-size:11px;color:#94A3B8;margin-top:6px;font-weight:600}
.an-farrow{position:absolute;right:-12px;top:50%;transform:translateY(-50%);z-index:2;width:24px;height:24px;background:#fff;border:1px solid #E8ECF0;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;color:#94A3B8;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.an-conv-badge{display:inline-flex;align-items:center;gap:3px;background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;border-radius:6px;padding:2px 7px;font-size:10.5px;font-weight:800;margin-top:8px}
.an-conv-badge.low{background:#FFF7ED;border-color:#FED7AA;color:#C2410C}

/* ── Cards ── */
.an-card{background:#fff;border:1px solid #E8ECF0;border-radius:18px;box-shadow:0 2px 12px rgba(0,0,0,.05);overflow:hidden}
.an-card-head{padding:18px 22px 14px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;justify-content:space-between}
.an-card-title{font-size:14px;font-weight:800;color:#0F172A;display:flex;align-items:center;gap:8px}
.an-card-body{padding:20px 22px}
.an-chart-wrap{position:relative;height:240px;padding:4px 4px 0}
.an-chart-wrap-tall{position:relative;height:280px;padding:4px 4px 0}

/* ── Two column grid ── */
.an-grid2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
.an-grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-bottom:28px}

/* ── Insight cards ── */
.an-insight{border-radius:12px;padding:14px 16px;display:flex;align-items:flex-start;gap:12px;margin-bottom:10px;border:1px solid transparent}
.an-insight:last-child{margin-bottom:0}
.an-insight-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.an-insight-text{font-size:13px;font-weight:600;color:#0F172A;line-height:1.5}
.an-insight-text strong{font-weight:800}
.an-insight.green{background:#F0FDF4;border-color:#BBF7D0}.an-insight.green .an-insight-icon{background:#DCFCE7;color:#15803D}
.an-insight.red{background:#FFF1F2;border-color:#FECDD3}.an-insight.red .an-insight-icon{background:#FFE4E6;color:#BE123C}
.an-insight.amber{background:#FFFBEB;border-color:#FDE68A}.an-insight.amber .an-insight-icon{background:#FEF3C7;color:#B45309}
.an-insight.blue{background:#EFF6FF;border-color:#BFDBFE}.an-insight.blue .an-insight-icon{background:#DBEAFE;color:#1D4ED8}

/* ── Param bars ── */
.an-param{margin-bottom:14px}
.an-param:last-child{margin-bottom:0}
.an-param-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.an-param-name{font-size:13px;font-weight:700;color:#1E293B;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.an-param-right{display:flex;align-items:center;gap:8px}
.an-param-pct{font-size:13px;font-weight:800}
.an-param-samples{font-size:10.5px;color:#94A3B8;font-weight:600}
.an-pbar{height:8px;background:#EEF2F7;border-radius:99px;overflow:hidden}
.an-pbar-fill{height:8px;border-radius:99px;transition:width .5s ease}

/* ── Empty state ── */
.an-empty{text-align:center;padding:48px 24px;color:#94A3B8}
.an-empty i{font-size:40px;margin-bottom:14px;display:block;color:#CBD5E1}
.an-empty p{font-size:14px;font-weight:700;color:#475569;margin-bottom:5px}
.an-empty span{font-size:12.5px}

@media(max-width:1100px){
  .an-funnel{grid-template-columns:1fr 1fr;border-radius:16px}
  .an-fstep:nth-child(2){border-right:none}.an-fstep:nth-child(4){border-right:none}
  .an-kpis{grid-template-columns:1fr 1fr}
  .an-grid2,.an-grid3{grid-template-columns:1fr}
}
@media(max-width:640px){
  .an-hero{padding:20px 18px 24px}
  .an-hero-title{font-size:20px}
  .an-kpis{grid-template-columns:1fr 1fr}
  .an-kpi-val{font-size:26px}
  .an-funnel{grid-template-columns:1fr}
  .an-fstep{border-right:none;border-bottom:1px solid #EEF2F7}
}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">

<!-- ══ HERO ══════════════════════════════════════════════ -->
<div class="an-hero">
  <div class="an-hero-top">
    <div>
      <div class="an-hero-title">
        <i class="fa-solid fa-chart-mixed" style="color:#A78BFA;margin-right:8px;font-size:22px"></i>Analytics Dashboard
      </div>
      <div class="an-hero-sub">
        <?php if ($activeCampaignName): ?>
        <span class="an-badge"><i class="fa-solid fa-briefcase fa-xs"></i> <?= htmlspecialchars($activeCampaignName) ?></span>
        <?php else: ?>
        <span class="an-badge"><i class="fa-solid fa-layer-group fa-xs"></i> All Campaigns</span>
        <?php endif; ?>
        <?php if ($days_filter): ?>
        <span class="an-badge"><i class="fa-solid fa-clock fa-xs"></i> Last <?= $days_filter ?> days</span>
        <?php else: ?>
        <span class="an-badge"><i class="fa-solid fa-infinity fa-xs"></i> All time</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="an-filters">
      <form method="GET" style="display:flex;gap:8px;align-items:center">
        <?php if ($days_filter): ?><input type="hidden" name="days" value="<?= $days_filter ?>"><?php endif; ?>
        <select name="campaign_id" class="an-camp-select" onchange="this.form.submit()">
          <option value="">All campaigns</option>
          <?php foreach ($campaigns as $camp): ?>
          <option value="<?= $camp['id'] ?>" <?= $campaign_id === (int)$camp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($camp['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php $base = 'analytics.php' . ($campaign_id ? "?campaign_id=$campaign_id&" : '?'); ?>
      <a class="an-dtab <?= !$days_filter ? 'active' : '' ?>" href="<?= $base ?>days=0">All</a>
      <a class="an-dtab <?= $days_filter===7  ? 'active' : '' ?>" href="<?= $base ?>days=7">7d</a>
      <a class="an-dtab <?= $days_filter===30 ? 'active' : '' ?>" href="<?= $base ?>days=30">30d</a>
      <a class="an-dtab <?= $days_filter===90 ? 'active' : '' ?>" href="<?= $base ?>days=90">90d</a>
    </div>
  </div>

  <!-- KPI strip -->
  <div class="an-kpis">
    <div class="an-kpi">
      <div class="an-kpi-val" style="color:#A78BFA"><?= $total ?></div>
      <div class="an-kpi-lbl">Total Candidates</div>
      <div class="an-kpi-sub"><?= $invited ?> received invite</div>
    </div>
    <div class="an-kpi">
      <div class="an-kpi-val" style="color:#34D399"><?= $avgScore !== null ? $avgScore : '—' ?></div>
      <div class="an-kpi-lbl">Avg Score /100</div>
      <div class="an-kpi-sub"><?= $avgScore !== null ? ($avgScore >= 60 ? 'Above passing threshold' : 'Below passing threshold') : 'No scored interviews' ?></div>
    </div>
    <div class="an-kpi">
      <div class="an-kpi-val" style="color:#60A5FA"><?= $completionRate ?>%</div>
      <div class="an-kpi-lbl">Completion Rate</div>
      <div class="an-kpi-sub"><?= $completed ?> of <?= $invited ?> completed</div>
    </div>
    <div class="an-kpi">
      <div class="an-kpi-val" style="color:#FBBF24"><?= $selectionRate ?>%</div>
      <div class="an-kpi-lbl">Selection Rate</div>
      <div class="an-kpi-sub"><?= $shortlisted ?> shortlisted · <?= $rejected ?> rejected</div>
    </div>
  </div>
</div>

<!-- ══ FUNNEL ════════════════════════════════════════════ -->
<div class="an-section">
  <div class="an-section-head">
    <div class="an-section-title"><i class="fa-solid fa-filter"></i> Candidate Funnel</div>
    <span style="font-size:11.5px;color:#94A3B8;font-weight:600"><?= $dropInsight ?>% drop-off from invite to completion</span>
  </div>
  <div class="an-funnel">
    <?php
    $fsteps = [
      ['Imported',   $total,       'fa-users',          '#EDE9FE','#7C3AED', null],
      ['Invited',    $invited,     'fa-paper-plane',    '#DBEAFE','#2563EB', $conv[0]],
      ['Started',    $started,     'fa-play-circle',    '#D1FAE5','#059669', $conv[1]],
      ['Completed',  $completed,   'fa-circle-check',   '#FEF3C7','#D97706', $conv[2]],
      ['Shortlisted',$shortlisted, 'fa-star',           '#FCE7F3','#BE185D', $conv[3]],
    ];
    foreach ($fsteps as $i => [$lbl,$val,$ico,$ibg,$icol,$conv_rate]):
      $pct = $total ? round($val/$total*100) : 0;
      $isLow = $conv_rate !== null && $conv_rate < 50;
    ?>
    <div class="an-fstep" style="<?= $i < count($fsteps)-1 ? '' : '' ?>">
      <?php if ($i < count($fsteps)-1): ?>
      <div class="an-farrow"><i class="fa-solid fa-chevron-right"></i></div>
      <?php endif; ?>
      <div class="an-fstep-icon" style="background:<?= $ibg ?>;color:<?= $icol ?>"><i class="fa-solid <?= $ico ?>"></i></div>
      <div class="an-fstep-num"><?= $val ?></div>
      <div class="an-fstep-lbl"><?= $lbl ?></div>
      <div class="an-fbar"><div class="an-fbar-fill" style="width:<?= $pct ?>%;background:linear-gradient(90deg,<?= $icol ?>,<?= $icol ?>88)"></div></div>
      <div class="an-fstep-pct"><?= $pct ?>% of total</div>
      <?php if ($conv_rate !== null): ?>
      <div class="an-conv-badge <?= $isLow ? 'low' : '' ?>">
        <i class="fa-solid fa-arrow-<?= $isLow ? 'down' : 'up' ?>" style="font-size:8px"></i>
        <?= $conv_rate ?>% conv
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══ CHARTS ════════════════════════════════════════════ -->
<div class="an-grid2">
  <!-- Score Distribution -->
  <div class="an-card">
    <div class="an-card-head">
      <div class="an-card-title">
        <i class="fa-solid fa-chart-bar" style="color:#7C3AED;font-size:13px"></i>
        Score Distribution
      </div>
      <?php if ($avgScore !== null): ?>
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:11px;color:#94A3B8;font-weight:600">High: <strong style="color:#10B981"><?= (int)($sb['max_score']??0) ?></strong></span>
        <span style="font-size:11px;color:#94A3B8;font-weight:600">Low: <strong style="color:#EF4444"><?= (int)($sb['min_score']??0) ?></strong></span>
        <span style="background:#EDE9FE;color:#6D28D9;border-radius:6px;padding:3px 9px;font-size:11px;font-weight:800">Avg <?= $avgScore ?></span>
      </div>
      <?php endif; ?>
    </div>
    <?php if (array_sum($chartData) > 0): ?>
    <div class="an-card-body">
      <div class="an-chart-wrap-tall"><canvas id="scoreChart"></canvas></div>
    </div>
    <?php else: ?>
    <div class="an-empty"><i class="fa-solid fa-chart-bar"></i><p>No scored interviews yet</p><span>Scores will appear once candidates complete interviews.</span></div>
    <?php endif; ?>
  </div>

  <!-- Completion Trend -->
  <div class="an-card">
    <div class="an-card-head">
      <div class="an-card-title">
        <i class="fa-solid fa-chart-line" style="color:#10B981;font-size:13px"></i>
        Completion Trend
      </div>
      <span style="font-size:11px;color:#94A3B8;font-weight:600;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:6px;padding:3px 8px">Last 14 days</span>
    </div>
    <?php if (array_sum($trendData) > 0): ?>
    <div class="an-card-body">
      <div class="an-chart-wrap-tall"><canvas id="trendChart"></canvas></div>
    </div>
    <?php else: ?>
    <div class="an-empty"><i class="fa-solid fa-chart-line"></i><p>No completions in this period</p><span>Trend data will appear as candidates complete interviews.</span></div>
    <?php endif; ?>
  </div>
</div>

<!-- ══ INSIGHTS + WEAKEST ════════════════════════════════ -->
<div class="an-grid2">
  <!-- AI Insights -->
  <div class="an-card">
    <div class="an-card-head">
      <div class="an-card-title">
        <i class="fa-solid fa-wand-magic-sparkles" style="color:#F59E0B;font-size:13px"></i>
        AI Insights
      </div>
      <span style="font-size:10.5px;font-weight:700;color:#94A3B8;background:#FAFBFC;border:1px solid #E2E8F0;border-radius:6px;padding:3px 8px">Auto-generated</span>
    </div>
    <div class="an-card-body" style="display:flex;flex-direction:column;gap:0">
      <!-- Drop-off -->
      <?php $dropClass = $dropInsight > 60 ? 'red' : ($dropInsight > 30 ? 'amber' : 'green'); ?>
      <div class="an-insight <?= $dropClass ?>">
        <div class="an-insight-icon"><i class="fa-solid fa-<?= $dropInsight > 60 ? 'arrow-trend-down' : ($dropInsight > 30 ? 'triangle-exclamation' : 'arrow-trend-up') ?>"></i></div>
        <div class="an-insight-text">
          Drop-off from invite to completion is <strong><?= $dropInsight ?>%</strong>.
          <?= $dropInsight > 60 ? 'High drop-off — review outreach messaging or interview length.' : ($dropInsight > 30 ? 'Moderate drop-off — consider sending reminder nudges.' : 'Excellent engagement rate.') ?>
        </div>
      </div>
      <!-- Selection -->
      <?php $selClass = $shortlisted >= $rejected ? 'green' : 'amber'; ?>
      <div class="an-insight <?= $selClass ?>">
        <div class="an-insight-icon"><i class="fa-solid fa-<?= $shortlisted >= $rejected ? 'user-check' : 'user-clock' ?>"></i></div>
        <div class="an-insight-text"><strong><?= $shortlisted ?> shortlisted</strong> and <?= $rejected ?> rejected
          <?= ($shortlisted + $rejected) > 0 ? ' — ' . $selectionRate . '% selection rate.' : '.' ?>
        </div>
      </div>
      <!-- Score -->
      <?php if ($avgScore !== null): ?>
      <?php $scoreClass = $avgScore < 40 ? 'red' : ($avgScore < 60 ? 'amber' : 'green'); ?>
      <div class="an-insight <?= $scoreClass ?>">
        <div class="an-insight-icon"><i class="fa-solid fa-<?= $avgScore < 50 ? 'circle-exclamation' : 'circle-check' ?>"></i></div>
        <div class="an-insight-text">
          Average score is <strong><?= $avgScore ?>/100</strong>.
          <?php if ($avgScore < 40): ?>
          Cohort is scoring very low — review question difficulty or passing threshold.
          <?php elseif ($avgScore < 60): ?>
          Below average cohort — consider adjusting campaign questions for clarity.
          <?php elseif ($avgScore >= 75): ?>
          Strong cohort performance across the board.
          <?php else: ?>
          Candidates are performing at a satisfactory level.
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <!-- Completion rate -->
      <div class="an-insight blue">
        <div class="an-insight-icon"><i class="fa-solid fa-bullseye"></i></div>
        <div class="an-insight-text">
          Completion rate is <strong><?= $completionRate ?>%</strong>
          <?= $completed > 0 ? " — $completed candidate" . ($completed !== 1 ? 's' : '') . " completed the full interview." : ' — no completions yet.' ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Weakest Parameters -->
  <div class="an-card">
    <div class="an-card-head">
      <div class="an-card-title">
        <i class="fa-solid fa-arrow-trend-down" style="color:#EF4444;font-size:13px"></i>
        Weakest Parameters
      </div>
      <span style="font-size:10.5px;font-weight:700;color:#94A3B8;background:#FFF1F2;border:1px solid #FECDD3;border-radius:6px;padding:3px 8px">Lowest avg score</span>
    </div>
    <div class="an-card-body">
      <?php if (empty($lowScores)): ?>
      <div class="an-empty" style="padding:28px 0"><i class="fa-solid fa-chart-bar"></i><p>No data yet</p><span>Parameter scores appear after interviews are scored.</span></div>
      <?php else: foreach ($lowScores as $row):
        $pc = (float)$row['avg_pct'];
        if ($pc < 40)       { $col = '#EF4444'; $bg = 'linear-gradient(90deg,#EF4444,#F87171)'; }
        elseif ($pc < 65)   { $col = '#F59E0B'; $bg = 'linear-gradient(90deg,#F59E0B,#FBBF24)'; }
        else                { $col = '#10B981'; $bg = 'linear-gradient(90deg,#10B981,#34D399)'; }
      ?>
      <div class="an-param">
        <div class="an-param-row">
          <span class="an-param-name" title="<?= htmlspecialchars($row['parameter_label']) ?>"><?= htmlspecialchars($row['parameter_label']) ?></span>
          <div class="an-param-right">
            <span class="an-param-samples"><?= $row['samples'] ?> interviews</span>
            <span class="an-param-pct" style="color:<?= $col ?>"><?= $pc ?>%</span>
          </div>
        </div>
        <div class="an-pbar"><div class="an-pbar-fill" style="width:<?= max(3,(int)$pc) ?>%;background:<?= $bg ?>"></div></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.color = '#64748B';

<?php if (array_sum($chartData) > 0): ?>
new Chart(document.getElementById('scoreChart'), {
  type: 'bar',
  data: {
    labels: ['0 – 19','20 – 39','40 – 59','60 – 79','80 – 100'],
    datasets: [{
      label: 'Candidates',
      data: <?= json_encode($chartData) ?>,
      backgroundColor: [
        'rgba(239,68,68,.85)',
        'rgba(251,146,60,.85)',
        'rgba(250,204,21,.85)',
        'rgba(52,211,153,.85)',
        'rgba(16,185,129,.85)'
      ],
      borderRadius: 10,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#0F172A',
        titleFont: { weight: '800', size: 12 },
        bodyFont: { size: 12 },
        padding: 10,
        cornerRadius: 8,
        callbacks: {
          title: (items) => 'Score ' + items[0].label,
          label: (item) => ' ' + item.raw + ' candidate' + (item.raw !== 1 ? 's' : '')
        }
      }
    },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0, font: { weight: '700', size: 11 } }, grid: { color: '#F1F5F9' }, border: { display: false } },
      x: { grid: { display: false }, ticks: { font: { weight: '700', size: 11 } }, border: { display: false } }
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
      borderColor: '#6D28D9',
      backgroundColor: (ctx) => {
        const gradient = ctx.chart.ctx.createLinearGradient(0, 0, 0, 260);
        gradient.addColorStop(0, 'rgba(109,40,217,.25)');
        gradient.addColorStop(1, 'rgba(109,40,217,.02)');
        return gradient;
      },
      tension: 0.45,
      fill: true,
      pointRadius: 5,
      pointBackgroundColor: '#6D28D9',
      pointBorderColor: '#fff',
      pointBorderWidth: 2,
      borderWidth: 2.5,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#0F172A',
        titleFont: { weight: '800', size: 12 },
        bodyFont: { size: 12 },
        padding: 10,
        cornerRadius: 8,
        callbacks: {
          label: (item) => ' ' + item.raw + ' completion' + (item.raw !== 1 ? 's' : '')
        }
      }
    },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0, font: { weight: '700', size: 11 } }, grid: { color: '#F1F5F9' }, border: { display: false } },
      x: { grid: { display: false }, ticks: { maxTicksLimit: 7, font: { weight: '600', size: 11 } }, border: { display: false } }
    }
  }
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
