<?php
require_once __DIR__ . '/includes/auth_check.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: candidates.php'); exit; }

$c = db_fetch_one(
    "SELECT c.*, camp.name campaign_name, camp.job_role, camp.passing_score
     FROM candidates c LEFT JOIN campaigns camp ON c.campaign_id=camp.id
     WHERE c.id=? AND c.org_id=?",
    [$id, $user['org_id']], 'ii'
);
if (!$c) { header('Location: candidates.php'); exit; }

$session  = db_fetch_one("SELECT * FROM interview_sessions WHERE candidate_id=? ORDER BY id DESC LIMIT 1", [$id], 'i');
$result   = db_fetch_one("SELECT * FROM interview_results WHERE candidate_id=? ORDER BY id DESC LIMIT 1", [$id], 'i');
$scores   = db_fetch_all("SELECT * FROM scores WHERE candidate_id=? ORDER BY id", [$id], 'i');
$answers  = db_fetch_all(
    "SELECT ia.*, q.question_text, q.order_no AS question_number, q.parameter_label
     FROM interview_answers ia LEFT JOIN questions q ON ia.question_id=q.id
     WHERE ia.candidate_id=? ORDER BY q.order_no ASC, ia.id ASC",
    [$id], 'i'
);
$cheat    = !empty($session['cheat_summary']) ? json_decode($session['cheat_summary'], true) : null;
$total    = $result['recruiter_override_score'] ?? $result['total_score'] ?? null;
$maxScore = $result['max_score'] ?? 100;
$passFail = $result['pass_fail'] ?? 'pending';
$passing  = $c['passing_score'] ?? 70;
$pct      = $total !== null ? round($total / $maxScore * 100) : 0;
$color    = $passFail === 'pass' ? '#059669' : ($passFail === 'fail' ? '#DC2626' : '#6B7280');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Interview Report — <?= htmlspecialchars($c['name']) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',Arial,sans-serif;background:#fff;color:#1F2937;font-size:13px;line-height:1.5}

/* PRINT */
@media print{
  .no-print{display:none!important}
  body{print-color-adjust:exact;-webkit-print-color-adjust:exact}
  .page-break{page-break-before:always}
}

.page{max-width:800px;margin:0 auto;padding:32px}

/* HEADER */
.report-header{background:linear-gradient(135deg,#1E0A2E,#2D1045);color:#fff;border-radius:16px;padding:28px 32px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center}
.company-brand{display:flex;align-items:center;gap:12px}
.brand-icon{width:44px;height:44px;background:linear-gradient(135deg,#6B21A8,#2563EB);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900;color:#fff}
.brand-name{font-size:18px;font-weight:800;color:#fff}
.brand-sub{font-size:11px;color:#A78BFA;margin-top:1px}
.report-title{text-align:right}
.report-title h1{font-size:16px;font-weight:700;color:#E9D5FF}
.report-title p{font-size:11px;color:#9CA3AF;margin-top:3px}
.report-date{font-size:11px;color:#9CA3AF;margin-top:2px}

/* CANDIDATE CARD */
.candidate-card{border:1.5px solid #E5E7EB;border-radius:14px;padding:20px 24px;margin-bottom:20px;display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center}
.cand-name{font-size:22px;font-weight:800;color:#1F2937;margin-bottom:4px}
.cand-meta{display:flex;flex-wrap:wrap;gap:12px;font-size:12px;color:#6B7280}
.cand-meta span{display:flex;align-items:center;gap:4px}

/* SCORE */
.score-badge{text-align:center;background:<?= $color ?>15;border:2px solid <?= $color ?>;border-radius:12px;padding:12px 20px;min-width:120px}
.score-num{font-size:32px;font-weight:900;color:<?= $color ?>;line-height:1}
.score-label{font-size:11px;color:#6B7280;margin-top:3px}
.score-status{font-size:13px;font-weight:700;color:<?= $color ?>;margin-top:4px;text-transform:uppercase;letter-spacing:.5px}

/* SECTIONS */
.section{margin-bottom:20px}
.section-title{font-size:13px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.8px;padding-bottom:8px;border-bottom:2px solid #F3F4F6;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.section-title .dot{width:8px;height:8px;border-radius:50%;background:linear-gradient(135deg,#6B21A8,#2563EB)}

/* INFO GRID */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.info-item{padding:10px 14px;background:#F9FAFB;border-radius:8px}
.info-item .key{font-size:10px;font-weight:700;color:#9CA3AF;text-transform:uppercase;letter-spacing:.5px}
.info-item .val{font-size:13px;font-weight:600;color:#1F2937;margin-top:2px}

/* SCORES TABLE */
.scores-table{width:100%;border-collapse:collapse}
.scores-table th{text-align:left;font-size:10px;font-weight:700;color:#9CA3AF;text-transform:uppercase;letter-spacing:.5px;padding:8px 12px;border-bottom:1px solid #F3F4F6}
.scores-table td{padding:10px 12px;border-bottom:1px solid #F9FAFB;font-size:13px;vertical-align:middle}
.scores-table tr:last-child td{border-bottom:none}
.score-bar-wrap{background:#F3F4F6;border-radius:4px;height:6px;width:100px;display:inline-block;vertical-align:middle}
.score-bar-fill{height:6px;border-radius:4px}

/* Q&A */
.qa-item{margin-bottom:14px;border:1px solid #F3F4F6;border-radius:10px;overflow:hidden}
.qa-q{background:#F9FAFB;padding:10px 14px;font-size:12px;font-weight:600;color:#374151;border-bottom:1px solid #F3F4F6}
.qa-q .q-num{display:inline-block;background:linear-gradient(135deg,#6B21A8,#2563EB);color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;margin-right:6px}
.qa-a{padding:10px 14px;font-size:12px;color:#4B5563;white-space:pre-wrap}
.qa-meta{padding:6px 14px;background:#F9FAFB;border-top:1px solid #F3F4F6;display:flex;gap:12px;font-size:11px;color:#9CA3AF}

/* INTEGRITY */
.integrity-box{background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:14px 18px}
.integrity-ok{background:#F0FDF4;border-color:#BBF7D0}
.integrity-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid rgba(0,0,0,.05);font-size:12px}
.integrity-row:last-child{border-bottom:none}
.integrity-val{font-weight:700}

/* FOOTER */
.report-footer{margin-top:28px;padding-top:16px;border-top:1px solid #F3F4F6;display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#9CA3AF}

/* PRINT BTN */
.print-bar{position:fixed;top:0;left:0;right:0;background:linear-gradient(135deg,#1E0A2E,#2D1045);padding:12px 32px;display:flex;align-items:center;justify-content:space-between;z-index:100;box-shadow:0 2px 10px rgba(0,0,0,.2)}
.print-bar-title{color:#E9D5FF;font-size:14px;font-weight:600}
.print-bar-btns{display:flex;gap:10px}
.btn-print{padding:8px 20px;background:linear-gradient(135deg,#6B21A8,#7C3AED);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}
.btn-back{padding:8px 16px;background:transparent;color:#A78BFA;border:1px solid #A78BFA;border-radius:8px;font-size:13px;cursor:pointer;text-decoration:none}
body{padding-top:60px}
@media print{.print-bar{display:none}body{padding-top:0}}
</style>
</head>
<body>

<!-- PRINT BAR -->
<div class="print-bar no-print">
  <div class="print-bar-title">📄 Interview Report — <?= htmlspecialchars($c['name']) ?></div>
  <div class="print-bar-btns">
    <a href="candidate_detail.php?id=<?= $id ?>" class="btn-back">← Back</a>
    <button onclick="window.print()" class="btn-print">🖨️ Print / Save PDF</button>
  </div>
</div>

<div class="page">

  <!-- HEADER -->
  <div class="report-header">
    <div class="company-brand">
      <div class="brand-icon">A</div>
      <div>
        <div class="brand-name">Avyukta Intellicall</div>
        <div class="brand-sub">HireAI — AI Interview Platform</div>
      </div>
    </div>
    <div class="report-title">
      <h1>Interview Assessment Report</h1>
      <p>Campaign: <?= htmlspecialchars($c['campaign_name'] ?? '—') ?></p>
      <div class="report-date">Generated: <?= date('d M Y, h:i A') ?></div>
    </div>
  </div>

  <!-- CANDIDATE + SCORE -->
  <div class="candidate-card">
    <div>
      <div class="cand-name"><?= htmlspecialchars($c['name'] ?? 'Unknown') ?></div>
      <div class="cand-meta">
        <?php if ($c['phone']): ?><span>📞 <?= htmlspecialchars($c['phone']) ?></span><?php endif; ?>
        <?php if ($c['email']): ?><span>✉ <?= htmlspecialchars($c['email']) ?></span><?php endif; ?>
        <?php if ($c['city']): ?><span>📍 <?= htmlspecialchars($c['city']) ?></span><?php endif; ?>
        <?php if ($c['experience_years']): ?><span>💼 <?= $c['experience_years'] ?> yrs exp</span><?php endif; ?>
        <span>🎯 <?= htmlspecialchars($c['job_role'] ?? '—') ?></span>
      </div>
    </div>
    <?php if ($total !== null): ?>
    <div class="score-badge">
      <div class="score-num"><?= $total ?></div>
      <div class="score-label">out of <?= $maxScore ?></div>
      <div class="score-status"><?= strtoupper($passFail) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- INTERVIEW DETAILS -->
  <div class="section">
    <div class="section-title"><span class="dot"></span>Interview Details</div>
    <div class="info-grid">
      <div class="info-item">
        <div class="key">Status</div>
        <div class="val"><?= ucfirst(str_replace('_',' ',$c['status'])) ?></div>
      </div>
      <div class="info-item">
        <div class="key">Passing Score</div>
        <div class="val"><?= $passing ?>/100</div>
      </div>
      <?php if ($session): ?>
      <div class="info-item">
        <div class="key">Interview Date</div>
        <div class="val"><?= $session['started_at'] ? date('d M Y', strtotime($session['started_at'])) : '—' ?></div>
      </div>
      <div class="info-item">
        <div class="key">Duration</div>
        <div class="val"><?= $session['duration_seconds'] ? round($session['duration_seconds']/60,1).' min' : '—' ?></div>
      </div>
      <?php endif; ?>
      <?php if ($result && $result['recruiter_override_score']): ?>
      <div class="info-item">
        <div class="key">Override Score</div>
        <div class="val"><?= $result['recruiter_override_score'] ?>/100</div>
      </div>
      <div class="info-item">
        <div class="key">Override Reason</div>
        <div class="val"><?= htmlspecialchars($result['recruiter_override_reason'] ?? '—') ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- PARAMETER SCORES -->
  <?php if (!empty($scores)): ?>
  <div class="section">
    <div class="section-title"><span class="dot"></span>Parameter-wise Scores</div>
    <table class="scores-table">
      <thead>
        <tr>
          <th>Parameter</th>
          <th>Score</th>
          <th>Max</th>
          <th>%</th>
          <th>Bar</th>
          <th>AI Reasoning</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($scores as $s):
          $sp = $s['max_marks'] > 0 ? round($s['ai_score']/$s['max_marks']*100) : 0;
          $sc = $sp >= 70 ? '#059669' : ($sp >= 50 ? '#D97706' : '#DC2626');
        ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($s['parameter_label']) ?></td>
          <td style="font-weight:700;color:<?= $sc ?>"><?= $s['ai_score'] ?></td>
          <td style="color:#9CA3AF"><?= $s['max_marks'] ?></td>
          <td style="color:<?= $sc ?>;font-weight:600"><?= $sp ?>%</td>
          <td>
            <div class="score-bar-wrap">
              <div class="score-bar-fill" style="width:<?= $sp ?>%;background:<?= $sc ?>"></div>
            </div>
          </td>
          <td style="color:#6B7280;font-size:11px;max-width:200px"><?= htmlspecialchars($s['ai_reasoning'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="background:#F9FAFB">
          <td style="font-weight:700">TOTAL</td>
          <td style="font-weight:800;font-size:15px;color:<?= $color ?>"><?= $total ?? '—' ?></td>
          <td style="color:#9CA3AF"><?= $maxScore ?></td>
          <td style="font-weight:700;color:<?= $color ?>"><?= $pct ?>%</td>
          <td></td>
          <td></td>
        </tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- AI SUMMARY -->
  <?php if (!empty($result['ai_summary'])): ?>
  <div class="section">
    <div class="section-title"><span class="dot"></span>AI Performance Summary</div>
    <div style="background:#F9FAFB;border-radius:10px;padding:14px 18px;font-size:13px;color:#374151;line-height:1.7;border-left:3px solid #6B21A8">
      <?= htmlspecialchars($result['ai_summary']) ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Q&A — Default visible, rest collapsible -->
  <?php if (!empty($answers)): ?>
  <div class="section">
    <div class="section-title"><span class="dot"></span>Interview Q&amp;A</div>
    <?php foreach ($answers as $i => $a): ?>
    <div class="qa-item">
      <div class="qa-q">
        <span class="q-num">Q<?= $a['question_number'] ?? ($i+1) ?></span>
        <?= htmlspecialchars($a['question_text'] ?? 'Question') ?>
        <?php if ($a['parameter_label']): ?>
          <span style="margin-left:8px;font-size:10px;color:#9CA3AF">[<?= htmlspecialchars($a['parameter_label']) ?>]</span>
        <?php endif; ?>
      </div>
      <?php if (!empty($a['text_answer'])): ?>
      <div class="qa-a"><?= htmlspecialchars($a['text_answer']) ?></div>
      <?php elseif (!empty($a['audio_url'])): ?>
      <div class="qa-a" style="color:#9CA3AF;font-style:italic">🎤 Voice answer recorded</div>
      <?php else: ?>
      <div class="qa-a" style="color:#9CA3AF;font-style:italic">No answer recorded</div>
      <?php endif; ?>
      <div class="qa-meta">
        <?php if ($a['answer_mode']): ?><span>Mode: <?= ucfirst($a['answer_mode']) ?></span><?php endif; ?>
        <?php if ($a['time_taken']): ?><span>⏱ <?= $a['time_taken'] ?>s</span><?php endif; ?>
        <?php if ($a['copy_count'] > 0): ?><span style="color:#EF4444">⚠️ Paste: <?= $a['copy_count'] ?>x</span><?php endif; ?>
        <?php if (!empty($a['audio_url'])): ?><span>🎤 <a href="<?= htmlspecialchars($a['audio_url']) ?>">Audio</a></span><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- INTEGRITY REPORT -->
  <?php
  $tabSwitch = $cheat['tab_switches'] ?? 0;
  $faceAway  = $cheat['face_away'] ?? 0;
  $pasteCount = $cheat['copy_paste'] ?? 0;
  $totalFlags = $cheat['total_flags'] ?? 0;
  $integrityOk = ($tabSwitch == 0 && $pasteCount == 0 && $totalFlags < 3);
  ?>
  <div class="section">
    <div class="section-title"><span class="dot"></span>Integrity Report</div>
    <div class="integrity-box <?= $integrityOk ? 'integrity-ok' : '' ?>">
      <div class="integrity-row">
        <span>Tab Switches</span>
        <span class="integrity-val" style="color:<?= $tabSwitch > 0 ? '#DC2626' : '#059669' ?>"><?= $tabSwitch ?></span>
      </div>
      <div class="integrity-row">
        <span>Paste Violations (&gt;20 chars)</span>
        <span class="integrity-val" style="color:<?= $pasteCount > 0 ? '#DC2626' : '#059669' ?>"><?= $pasteCount ?></span>
      </div>
      <div class="integrity-row">
        <span>Face Away Count</span>
        <span class="integrity-val" style="color:<?= $faceAway > 3 ? '#D97706' : '#059669' ?>"><?= $faceAway ?></span>
      </div>
      <div class="integrity-row">
        <span>Total Flags</span>
        <span class="integrity-val" style="color:<?= $totalFlags > 5 ? '#DC2626' : '#059669' ?>"><?= $totalFlags ?></span>
      </div>
      <div class="integrity-row" style="margin-top:4px;padding-top:8px;border-top:1px solid rgba(0,0,0,.08)">
        <span style="font-weight:600">Overall Integrity</span>
        <span class="integrity-val" style="color:<?= $integrityOk ? '#059669' : '#DC2626' ?>">
          <?= $integrityOk ? '✅ CLEAN' : '⚠️ FLAGGED' ?>
        </span>
      </div>
    </div>
  </div>

  <!-- FOOTER -->
  <div class="report-footer">
    <div>
      <strong style="color:#6B21A8">Avyukta Intellicall Consulting Pvt. Ltd.</strong><br>
      dialerindia.com · HireAI Platform
    </div>
    <div style="text-align:right">
      Report ID: HIRE-<?= str_pad($id, 5, '0', STR_PAD_LEFT) ?>-<?= date('Ymd') ?><br>
      Confidential — For HR Use Only
    </div>
  </div>

</div>
</body>
</html>
