<?php
require_once __DIR__ . '/includes/auth_check.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: candidates.php'); exit; }

$c = db_fetch_one(
    "SELECT c.*, camp.name campaign_name, camp.id campaign_id, camp.job_role, camp.passing_score
     FROM candidates c LEFT JOIN campaigns camp ON c.campaign_id=camp.id
     WHERE c.id=? AND c.org_id=?",
    [$id, $user['org_id']], 'ii'
);
if (!$c) { header('Location: candidates.php'); exit; }

$session   = db_fetch_one("SELECT * FROM interview_sessions WHERE candidate_id=? ORDER BY id DESC LIMIT 1", [$id], 'i');
$result    = db_fetch_one("SELECT * FROM interview_results  WHERE candidate_id=? ORDER BY id DESC LIMIT 1", [$id], 'i');
$scores    = db_fetch_all("SELECT * FROM scores WHERE candidate_id=? ORDER BY id", [$id], 'i');
$notes_db  = db_fetch_all(
    "SELECT rn.*, u.name recruiter_name FROM recruiter_notes rn
     JOIN users u ON rn.user_id=u.id WHERE rn.candidate_id=? ORDER BY rn.created_at DESC",
    [$id], 'i'
);
$answers   = db_fetch_all(
    "SELECT ia.*, iq.question_text, iq.question_number
     FROM interview_answers ia LEFT JOIN interview_questions iq ON ia.question_id=iq.id
     WHERE ia.candidate_id=? ORDER BY iq.question_number ASC",
    [$id], 'i'
);
$questions = db_fetch_all(
    "SELECT * FROM interview_questions WHERE campaign_id=? ORDER BY question_number ASC",
    [$c['campaign_id']], 'i'
);

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_note'])) {
        db_insert("INSERT INTO recruiter_notes (candidate_id,user_id,note) VALUES (?,?,?)",
            [$id, $user['user_id'], $_POST['note']], 'iis');
        header("Location: candidate_detail.php?id=$id&toast=note_added"); exit;
    }
    if (isset($_POST['update_status'])) {
        db_execute("UPDATE candidates SET status=?, updated_at=NOW() WHERE id=?",
            [$_POST['status'], $id], 'si');
        header("Location: candidate_detail.php?id=$id&toast=status_updated"); exit;
    }
    if (isset($_POST['override_score'])) {
        db_execute(
            "UPDATE interview_results SET recruiter_override_score=?,recruiter_override_reason=?,overridden_by=? WHERE candidate_id=?",
            [(int)$_POST['override_score'], $_POST['reason'], $user['user_id'], $id], 'isii'
        );
        header("Location: candidate_detail.php?id=$id&toast=score_updated"); exit;
    }
}

$display_score = $result['recruiter_override_score'] ?? $result['total_score'] ?? null;
$pf            = $result['pass_fail'] ?? null;
$scoreColor    = $pf === 'pass' ? '#10B981' : ($pf === 'fail' ? '#EF4444' : '#94A3B8');
$scoreBg       = $pf === 'pass' ? '#ECFDF5' : ($pf === 'fail' ? '#FEF2F2' : '#F8FAFC');
$recUrl        = $session['recording_url'] ?? $session['video_url'] ?? $session['audio_url'] ?? null;
$breakdown     = !empty($result['score_breakdown']) ? json_decode($result['score_breakdown'], true) : null;
$cheat         = !empty($session['cheat_summary'])  ? json_decode($session['cheat_summary'],  true) : null;
$interviewLink = defined('INTERVIEW_URL') ? INTERVIEW_URL . '?t=' . htmlspecialchars($c['unique_token'] ?? '') : '';
$toast         = $_GET['toast'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title><?= htmlspecialchars($c['name']) ?> — HireAI</title>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
/* TOAST */
.toast{position:fixed;bottom:28px;right:28px;z-index:9999;padding:14px 22px;border-radius:14px;font-size:14px;font-weight:600;color:#fff;display:flex;align-items:center;gap:10px;box-shadow:0 8px 40px rgba(0,0,0,.25);animation:toastIn .3s cubic-bezier(.4,0,.2,1);max-width:360px;pointer-events:none}
.toast-success{background:linear-gradient(135deg,#059669,#10B981)}
.toast-error{background:linear-gradient(135deg,#DC2626,#EF4444)}
.toast-info{background:linear-gradient(135deg,#1D4ED8,#3B82F6)}
@keyframes toastIn{from{opacity:0;transform:translateY(20px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes toastOut{to{opacity:0;transform:translateY(20px) scale(.95)}}

/* LAYOUT */
.detail-grid{display:grid;grid-template-columns:310px 1fr;gap:20px;align-items:start}
@media(max-width:1024px){.detail-grid{grid-template-columns:1fr}}

/* INFO */
.info-row{display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-bottom:1px solid #F1F5F9}
.info-row:last-child{border-bottom:none}
.info-key{font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.5px;width:90px;flex-shrink:0;padding-top:2px}
.info-val{font-size:14px;font-weight:500;color:var(--text);word-break:break-all}

/* SCORE */
.score-circle{width:124px;height:124px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;margin:0 auto 16px;border:6px solid currentColor;position:relative}
.score-big{font-size:36px;font-weight:900;line-height:1;letter-spacing:-1px}
.score-sub{font-size:10px;font-weight:700;opacity:.6;letter-spacing:.8px;text-transform:uppercase}

/* SCORE BARS */
.sbar-wrap{background:#E2E8F0;border-radius:99px;height:7px;overflow:hidden;margin-top:4px}
.sbar-fill{height:7px;border-radius:99px;transition:width 1.1s cubic-bezier(.4,0,.2,1)}

/* TABS */
.tabs{display:flex;gap:2px;background:#F1F5F9;padding:4px;border-radius:12px;margin-bottom:20px}
.tab-btn{flex:1;padding:9px 10px;border-radius:9px;border:none;background:transparent;font-size:13px;font-weight:600;color:var(--gray2);cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:5px}
.tab-btn.active{background:#fff;color:var(--text);box-shadow:0 1px 6px rgba(0,0,0,.1)}
.tab-panel{display:none}
.tab-panel.active{display:block}
.tab-badge{background:var(--blue);color:#fff;padding:1px 7px;border-radius:99px;font-size:10px;font-weight:800}
.tab-badge-orange{background:var(--orange)}

/* Q&A */
.qa-item{background:#F8FAFC;border-radius:14px;padding:16px;margin-bottom:10px;border:1.5px solid #F1F5F9;transition:all .2s}
.qa-item:hover{border-color:rgba(37,99,235,.2);box-shadow:0 4px 18px rgba(37,99,235,.07)}
.qa-q{font-size:13px;font-weight:700;color:var(--text2);margin-bottom:10px;display:flex;align-items:flex-start;gap:8px}
.q-num{background:linear-gradient(135deg,var(--blue),var(--accent));color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;box-shadow:0 2px 8px rgba(37,99,235,.3)}
.qa-a{font-size:14px;color:var(--text);line-height:1.7;padding:12px 14px;background:#fff;border-radius:10px;border:1.5px solid var(--light)}
.qa-meta{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;align-items:center}
.qa-tag{font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;background:#EFF6FF;color:#1E40AF;display:inline-flex;align-items:center;gap:4px}

/* INLINE AUDIO — no new tab */
.qa-audio-wrap{margin-top:10px;background:#EFF6FF;border-radius:10px;padding:10px 12px;border:1.5px solid #DBEAFE}
.qa-audio-wrap audio{width:100%;height:34px;display:block;border-radius:6px;accent-color:#1D4ED8}

/* VIDEO */
.video-frame{background:#060E1D;border-radius:14px;overflow:hidden;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center}
.video-frame video,.video-frame audio{width:100%;max-height:100%}
.no-media{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:rgba(255,255,255,.3);padding:40px;text-align:center}
.no-media i{font-size:36px}

/* ACTION BAR */
.action-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;padding:14px 18px;background:#fff;border-radius:16px;box-shadow:var(--card-shadow);border:1px solid rgba(0,0,0,.04);align-items:center}

/* NOTES */
.note-item{padding:12px 14px;background:#F8FAFC;border-radius:10px;margin-bottom:8px;border-left:3px solid var(--blue)}
.note-text{font-size:14px;color:var(--text);line-height:1.5}
.note-meta{font-size:11px;color:var(--gray);margin-top:5px;display:flex;gap:12px}

/* INTEGRITY */
.int-flag{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;margin-bottom:7px;border:1px solid}
.int-clean{background:#ECFDF5;border-color:#A7F3D0}
.int-warn{background:#FEF2F2;border-color:#FECACA}

/* CONFIRM MODAL */
.confirm-overlay{display:none;position:fixed;inset:0;background:rgba(8,15,30,.75);backdrop-filter:blur(10px);z-index:3000;align-items:center;justify-content:center;padding:20px}
.confirm-overlay.active{display:flex;animation:fadeIn .2s}
.confirm-box{background:#fff;border-radius:20px;padding:36px 32px;max-width:420px;width:100%;text-align:center;box-shadow:0 24px 80px rgba(0,0,0,.3);animation:slideUp .25s cubic-bezier(.4,0,.2,1)}
.confirm-icon{font-size:52px;margin-bottom:16px}
.confirm-title{font-size:20px;font-weight:800;color:var(--text);margin-bottom:8px}
.confirm-msg{font-size:14px;color:var(--gray2);margin-bottom:28px;line-height:1.6}
.confirm-btns{display:flex;gap:12px;justify-content:center}

/* KEYBOARD HINT */
.kb-hint{font-size:10px;background:#F1F5F9;color:var(--gray);padding:2px 7px;border-radius:5px;font-family:monospace;margin-left:4px}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">

<?php if ($toast): ?>
<div class="toast toast-success" id="toastEl">
  <i class="fa-solid fa-circle-check"></i>
  <?= match($toast) {
    'note_added'     => 'Note added successfully',
    'status_updated' => 'Status updated',
    'score_updated'  => 'Score override saved',
    default          => 'Changes saved'
  } ?>
</div>
<?php endif; ?>

<!-- BREADCRUMB -->
<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--gray);margin-bottom:14px">
  <a href="dashboard.php" style="color:var(--gray)"><i class="fa-solid fa-gauge-high fa-xs"></i> Dashboard</a>
  <i class="fa-solid fa-chevron-right fa-xs"></i>
  <a href="candidates.php" style="color:var(--gray)">Candidates</a>
  <i class="fa-solid fa-chevron-right fa-xs"></i>
  <span style="color:var(--text);font-weight:600"><?= htmlspecialchars($c['name']) ?></span>
</div>

<!-- ACTION BAR -->
<div class="action-bar animate-in">
  <div style="flex:1;min-width:0">
    <div style="font-size:20px;font-weight:800;letter-spacing:-.3px"><?= htmlspecialchars($c['name']) ?></div>
    <div style="font-size:12px;color:var(--gray);margin-top:2px">
      <?= htmlspecialchars($c['phone'] ?? '') ?> &nbsp;·&nbsp; <?= htmlspecialchars($c['campaign_name'] ?? '—') ?>
    </div>
  </div>
  <span class="badge badge-<?= $c['status'] ?>" style="font-size:12px;padding:5px 14px">
    <?= ucfirst(str_replace('_', ' ', $c['status'])) ?>
  </span>
  <?php if (!empty($c['phone'])): ?>
  <a href="tel:<?= htmlspecialchars($c['phone']) ?>" class="btn-green" style="padding:8px 16px;font-size:13px">
    <i class="fa-solid fa-phone fa-sm"></i> Call
  </a>
  <?php endif; ?>
  <?php if ($interviewLink): ?>
  <button onclick="copyLink()" class="btn-purple" style="padding:8px 16px;font-size:13px">
    <i class="fa-solid fa-link fa-sm"></i> Copy Link
  </button>
  <?php endif; ?>
  <button onclick="openStatusModal()" class="btn-primary" style="padding:8px 16px;font-size:13px">
    <i class="fa-solid fa-pen fa-sm"></i> Update Status
  </button>
  <a href="export_candidate.php?id=<?= $c['id'] ?>" target="_blank" class="btn-primary" style="padding:8px 16px;font-size:13px;background:linear-gradient(135deg,#6B21A8,#7C3AED);text-decoration:none">
    <i class="fa-solid fa-file-export fa-sm"></i> Export PDF
  </a>
  <button onclick="confirmDelete(<?= $c['id'] ?>,'<?= addslashes(htmlspecialchars($c['name'])) ?>')"
    class="btn-danger" style="padding:8px 14px;font-size:13px" title="Delete candidate">
    <i class="fa-solid fa-trash fa-sm"></i>
  </button>
</div>

<div class="detail-grid">

<!-- ═══ LEFT SIDEBAR ═══ -->
<div>

  <!-- SCORE CARD -->
  <div class="card animate-in" style="text-align:center;padding:24px 20px">
    <?php if ($display_score !== null):
      $maxScore = $result['max_score'] ?? 100; ?>
    <div class="score-circle" style="color:<?= $scoreColor ?>;background:<?= $scoreBg ?>">
      <div class="score-big" style="color:<?= $scoreColor ?>"><?= $display_score ?></div>
      <div class="score-sub" style="color:<?= $scoreColor ?>">/ <?= $maxScore ?></div>
    </div>
    <div style="display:inline-flex;align-items:center;gap:6px;padding:6px 20px;border-radius:20px;
                background:<?= $scoreBg ?>;border:1.5px solid <?= $scoreColor ?>40;
                font-size:14px;font-weight:800;color:<?= $scoreColor ?>;margin-bottom:14px">
      <?= $pf === 'pass'
        ? '<i class="fa-solid fa-circle-check"></i> PASSED'
        : '<i class="fa-solid fa-circle-xmark"></i> FAILED' ?>
    </div>
    <?php if ($result['recruiter_override_score']): ?>
    <div style="font-size:11px;color:var(--orange);background:#FFFBEB;padding:4px 12px;border-radius:20px;display:inline-flex;align-items:center;gap:4px;margin-bottom:12px">
      <i class="fa-solid fa-bolt fa-xs"></i> Recruiter Override
    </div>
    <?php endif; ?>

    <!-- Score Bars -->
    <?php if (!empty($scores)): ?>
    <div style="text-align:left;margin-top:8px">
    <?php foreach ($scores as $s):
      $ps  = round($s['ai_score'] / max(1, $s['max_marks']) * 100);
      $sc2 = $ps >= 70 ? '#10B981' : ($ps >= 50 ? '#F59E0B' : '#EF4444'); ?>
    <div style="margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:600;margin-bottom:3px">
        <span style="color:var(--text2)"><?= htmlspecialchars($s['parameter_label'] ?? '') ?></span>
        <span style="color:<?= $sc2 ?>"><?= $s['ai_score'] ?>/<?= $s['max_marks'] ?></span>
      </div>
      <div class="sbar-wrap"><div class="sbar-fill" style="width:0;background:<?= $sc2 ?>" data-w="<?= $ps ?>"></div></div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php elseif ($breakdown): ?>
    <div style="text-align:left;margin-top:8px">
    <?php foreach ($breakdown as $cat => $val):
      $pct3 = min(100, max(0, (int)$val)); ?>
    <div style="margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:600;margin-bottom:3px">
        <span><?= htmlspecialchars($cat) ?></span>
        <span style="color:var(--blue)"><?= $val ?></span>
      </div>
      <div class="sbar-wrap"><div class="sbar-fill" style="width:0;background:var(--blue)" data-w="<?= $pct3 ?>"></div></div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Override -->
    <?php if ($result): ?>
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid #F1F5F9;text-align:left">
      <div style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">
        <i class="fa-solid fa-bolt fa-xs"></i> Override Score
      </div>
      <form method="POST" style="display:flex;gap:6px;align-items:flex-end">
        <input type="number" name="override_score" class="form-control"
          style="width:70px;padding:7px 10px;font-size:13px"
          placeholder="Score" min="0" max="100" value="<?= $result['recruiter_override_score'] ?? '' ?>">
        <input type="text" name="reason" class="form-control"
          placeholder="Reason..." required style="font-size:13px;padding:7px 10px">
        <button type="submit" name="override_score" class="btn-sm" style="white-space:nowrap">Save</button>
      </form>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div style="padding:24px 0">
      <i class="fa-regular fa-clock fa-2x" style="color:var(--gray);margin-bottom:10px;display:block;opacity:.5"></i>
      <div style="font-size:14px;font-weight:600;color:var(--gray)">Score Pending</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- CANDIDATE INFO -->
  <div class="card animate-in">
    <div class="card-header"><h3><i class="fa-solid fa-id-card" style="color:var(--blue)"></i> Info</h3></div>
    <?php
    $fields = ['phone'=>'Phone','email'=>'Email','city'=>'City','experience_years'=>'Exp','current_ctc'=>'CTC','source'=>'Source','job_role'=>'Role','campaign_name'=>'Campaign'];
    foreach ($fields as $k => $l): if (!empty($c[$k])): ?>
    <div class="info-row">
      <div class="info-key"><?= $l ?></div>
      <div class="info-val"><?= htmlspecialchars($c[$k]) ?><?= $k === 'experience_years' ? ' yrs' : '' ?></div>
    </div>
    <?php endif; endforeach; ?>
    <div class="info-row">
      <div class="info-key">Applied</div>
      <div class="info-val"><?= $c['created_at'] ? date('d M Y', strtotime($c['created_at'])) : '—' ?></div>
    </div>
  </div>

  <!-- INTEGRITY -->
  <?php
  $flags = [];
  if (!empty($session['tab_switch_count']) && (int)$session['tab_switch_count'] > 0)
    $flags[] = ['Tab Switches', (int)$session['tab_switch_count'], 'fa-window-restore', '#F59E0B'];
  if (!empty($session['copy_count']) && (int)$session['copy_count'] > 0)
    $flags[] = ['Copy Attempts', (int)$session['copy_count'], 'fa-copy', '#EF4444'];
  if (!empty($session['face_not_detected_count']) && (int)$session['face_not_detected_count'] > 0)
    $flags[] = ['Face Not Detected', (int)$session['face_not_detected_count'], 'fa-face-frown', '#DC2626'];
  if (is_array($cheat))
    foreach ($cheat as $k => $v) if ($v > 0)
      $flags[] = [ucwords(str_replace('_', ' ', $k)), $v, 'fa-triangle-exclamation', '#F59E0B'];
  ?>
  <div class="card animate-in">
    <div class="card-header"><h3><i class="fa-solid fa-shield-halved" style="color:var(--orange)"></i> Integrity</h3></div>
    <?php if (empty($flags)): ?>
    <div class="int-flag int-clean">
      <i class="fa-solid fa-shield-check" style="color:#10B981;font-size:20px"></i>
      <div>
        <div style="font-size:13px;font-weight:700;color:#065F46">Clean Interview</div>
        <div style="font-size:11px;color:#047857">No flags detected</div>
      </div>
    </div>
    <?php else: foreach ($flags as [$lbl, $cnt, $ico, $clr]): ?>
    <div class="int-flag int-warn">
      <i class="fa-solid <?= $ico ?>" style="color:<?= $clr ?>;font-size:16px;width:18px;text-align:center"></i>
      <div style="flex:1"><div style="font-size:13px;font-weight:700;color:#991B1B"><?= htmlspecialchars($lbl) ?></div></div>
      <div style="font-size:16px;font-weight:900;color:<?= $clr ?>"><?= $cnt ?>×</div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- INTERVIEW LINK -->
  <?php if ($interviewLink): ?>
  <div class="card animate-in">
    <div class="card-header"><h3><i class="fa-solid fa-link" style="color:var(--accent)"></i> Interview Link</h3></div>
    <div style="background:#EFF6FF;border-radius:10px;padding:10px 12px;font-size:12px;word-break:break-all;color:var(--blue);margin-bottom:8px">
      <?= htmlspecialchars($interviewLink) ?>
    </div>
    <button onclick="copyLink()" class="btn-sm" style="width:100%;justify-content:center">
      <i class="fa-solid fa-copy fa-xs"></i> Copy Link
    </button>
  </div>
  <?php endif; ?>

</div><!-- /sidebar -->

<!-- ═══ RIGHT MAIN ═══ -->
<div>

  <!-- TABS -->
  <div class="tabs animate-in">
    <button class="tab-btn active" onclick="switchTab('recording',this)">
      <i class="fa-solid fa-video fa-sm"></i> Recording <span class="kb-hint">1</span>
    </button>
    <button class="tab-btn" onclick="switchTab('qa',this)">
      <i class="fa-solid fa-comments fa-sm"></i> Q&A
      <?php if (count($answers)): ?><span class="tab-badge"><?= count($answers) ?></span><?php endif; ?>
      <span class="kb-hint">2</span>
    </button>
    <button class="tab-btn" onclick="switchTab('transcript',this)">
      <i class="fa-solid fa-scroll fa-sm"></i> Transcript <span class="kb-hint">3</span>
    </button>
    <button class="tab-btn" onclick="switchTab('notes',this)">
      <i class="fa-solid fa-note-sticky fa-sm"></i> Notes
      <?php if (count($notes_db)): ?><span class="tab-badge tab-badge-orange"><?= count($notes_db) ?></span><?php endif; ?>
      <span class="kb-hint">4</span>
    </button>
  </div>

  <!-- TAB: RECORDING -->
  <div class="tab-panel active" id="tab-recording">
    <div class="card animate-in">
      <div class="card-header">
        <h3><i class="fa-solid fa-video" style="color:var(--purple)"></i> Interview Recording</h3>
        <?php if ($recUrl): ?>
        <a href="<?= htmlspecialchars($recUrl) ?>" download class="btn-sm">
          <i class="fa-solid fa-download fa-xs"></i> Download
        </a>
        <?php endif; ?>
      </div>
      <div class="video-frame">
        <?php
        $ext = strtolower(pathinfo(strtok($recUrl ?? '', '?'), PATHINFO_EXTENSION));
        if ($recUrl && in_array($ext, ['mp4','webm','mov','mkv'])): ?>
        <video controls preload="metadata" style="width:100%;height:100%;object-fit:contain">
          <source src="<?= htmlspecialchars($recUrl) ?>">
        </video>
        <?php elseif ($recUrl && in_array($ext, ['mp3','wav','ogg','m4a','webm'])): ?>
        <div style="width:100%;padding:32px;text-align:center">
          <i class="fa-solid fa-waveform-lines fa-3x" style="color:var(--accent);margin-bottom:16px;display:block"></i>
          <audio controls style="width:100%;border-radius:8px">
            <source src="<?= htmlspecialchars($recUrl) ?>">
          </audio>
        </div>
        <?php elseif ($recUrl): ?>
        <div style="width:100%;padding:32px;text-align:center">
          <audio controls style="width:100%;border-radius:8px">
            <source src="<?= htmlspecialchars($recUrl) ?>">
          </audio>
        </div>
        <?php else: ?>
        <div class="no-media">
          <i class="fa-solid fa-video-slash"></i>
          <div style="font-weight:600;font-size:14px">No Recording Yet</div>
          <div style="font-size:12px">Available after interview completion</div>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($session): ?>
      <div style="display:flex;gap:16px;margin-top:12px;flex-wrap:wrap">
        <?php if (!empty($session['started_at'])): ?>
        <span style="font-size:12px;color:var(--gray)">
          <i class="fa-regular fa-clock fa-xs"></i> Started: <?= date('d M Y, h:i A', strtotime($session['started_at'])) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($session['completed_at'])): ?>
        <span style="font-size:12px;color:var(--gray)">
          <i class="fa-solid fa-check fa-xs" style="color:var(--green)"></i> Done: <?= date('d M Y, h:i A', strtotime($session['completed_at'])) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($session['duration_seconds'])): ?>
        <span style="font-size:12px;color:var(--gray)">
          <i class="fa-solid fa-stopwatch fa-xs"></i> <?= round($session['duration_seconds'] / 60, 1) ?> min
        </span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- TAB: Q&A -->
  <div class="tab-panel" id="tab-qa">
    <div class="card animate-in">
      <div class="card-header">
        <h3><i class="fa-solid fa-comments" style="color:var(--green)"></i> Interview Q&A</h3>
        <span style="font-size:12px;color:var(--gray);background:#F1F5F9;padding:3px 10px;border-radius:20px">
          <?= count($answers) ?> answers
        </span>
      </div>
      <?php if (!empty($answers)): foreach ($answers as $i => $a):
        $qText   = $a['question_text'] ?? 'Question ' . ($i + 1);
        $ansText = $a['text_answer'] ?? '';
        $hasAudio= !empty($a['audio_url']);
        $tt      = (int)($a['time_taken'] ?? 0);
        $cp      = (int)($a['copy_count'] ?? 0);
      ?>
      <div class="qa-item">
        <div class="qa-q">
          <div class="q-num"><?= $a['question_number'] ?? ($i + 1) ?></div>
          <?= htmlspecialchars($qText) ?>
        </div>
        <?php if ($ansText): ?>
        <div class="qa-a"><?= nl2br(htmlspecialchars($ansText)) ?></div>
        <?php elseif ($hasAudio): ?>
        <div class="qa-a" style="background:#F5F3FF;border-color:#DDD6FE;color:var(--purple)">
          <i class="fa-solid fa-microphone fa-xs"></i> Voice response recorded
        </div>
        <?php else: ?>
        <div class="qa-a" style="color:var(--gray);font-style:italic">No response recorded</div>
        <?php endif; ?>

        <?php if ($hasAudio): ?>
        <!-- ✅ INLINE AUDIO — plays right here, no new tab -->
        <div class="qa-audio-wrap">
          <div style="font-size:11px;font-weight:600;color:#1E40AF;margin-bottom:6px;display:flex;align-items:center;gap:5px">
            <i class="fa-solid fa-microphone fa-xs"></i> Voice Answer
          </div>
          <audio controls preload="none" style="width:100%;border-radius:6px">
            <source src="<?= htmlspecialchars($a['audio_url']) ?>">
            Your browser does not support audio.
          </audio>
        </div>
        <?php endif; ?>

        <div class="qa-meta">
          <span class="qa-tag">
            <i class="fa-solid fa-<?= ($a['answer_mode'] ?? '') === 'voice' ? 'microphone' : 'keyboard' ?> fa-xs"></i>
            <?= ucfirst($a['answer_mode'] ?? 'text') ?>
          </span>
          <?php if ($tt > 0): ?>
          <span class="qa-tag" style="background:#ECFDF5;color:#065F46">
            <i class="fa-solid fa-stopwatch fa-xs"></i> <?= $tt ?>s
          </span>
          <?php endif; ?>
          <?php if ($cp > 0): ?>
          <span class="qa-tag" style="background:#FEE2E2;color:#991B1B">
            <i class="fa-solid fa-copy fa-xs"></i> <?= $cp ?> copies
          </span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach;
      elseif (!empty($questions)): foreach ($questions as $i => $q): ?>
      <div class="qa-item">
        <div class="qa-q">
          <div class="q-num"><?= $q['question_number'] ?? ($i + 1) ?></div>
          <?= htmlspecialchars($q['question_text'] ?? '') ?>
        </div>
        <div style="font-size:13px;color:var(--gray);font-style:italic;padding:8px 10px;background:#fff;border-radius:8px">
          <i class="fa-regular fa-hourglass fa-xs"></i> Awaiting response...
        </div>
      </div>
      <?php endforeach;
      else: ?>
      <div style="text-align:center;padding:40px;color:var(--gray)">
        <i class="fa-regular fa-comment-dots fa-2x" style="margin-bottom:12px;display:block;opacity:.3"></i>
        <div style="font-weight:600">No answers yet</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- TAB: TRANSCRIPT -->
  <div class="tab-panel" id="tab-transcript">
    <div class="card animate-in">
      <div class="card-header">
        <h3><i class="fa-solid fa-scroll" style="color:var(--blue)"></i> Full Transcript</h3>
      </div>
      <?php if (!empty($session['full_transcript'])): ?>
      <div style="background:#F8FAFC;border-radius:10px;padding:16px;max-height:500px;overflow-y:auto;font-size:14px;line-height:1.8;white-space:pre-wrap;color:var(--text2)">
        <?= htmlspecialchars($session['full_transcript']) ?>
      </div>
      <?php else: ?>
      <div style="text-align:center;padding:40px;color:var(--gray)">
        <i class="fa-solid fa-scroll fa-2x" style="margin-bottom:12px;display:block;opacity:.3"></i>
        <div style="font-weight:600">No transcript available</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- TAB: NOTES -->
  <div class="tab-panel" id="tab-notes">
    <div class="card animate-in">
      <div class="card-header">
        <h3><i class="fa-solid fa-note-sticky" style="color:var(--orange)"></i> Recruiter Notes</h3>
      </div>
      <form method="POST" style="display:flex;gap:8px;margin-bottom:16px">
        <input type="text" name="note" class="form-control" placeholder="Add a note about this candidate..." required>
        <button type="submit" name="add_note" class="btn-primary" style="white-space:nowrap;padding:10px 18px">
          <i class="fa-solid fa-plus fa-sm"></i> Add
        </button>
      </form>
      <?php if (!empty($notes_db)): foreach ($notes_db as $n): ?>
      <div class="note-item">
        <div class="note-text"><?= htmlspecialchars($n['note']) ?></div>
        <div class="note-meta">
          <span><i class="fa-solid fa-user fa-xs"></i> <?= htmlspecialchars($n['recruiter_name'] ?? 'Admin') ?></span>
          <span><i class="fa-regular fa-clock fa-xs"></i> <?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></span>
        </div>
      </div>
      <?php endforeach;
      else: ?>
      <div style="text-align:center;padding:32px;color:var(--gray)">
        <i class="fa-regular fa-note-sticky fa-2x" style="margin-bottom:12px;display:block;opacity:.3"></i>
        <div style="font-weight:600">No notes yet</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /right -->
</div><!-- /detail-grid -->

<!-- STATUS MODAL -->
<div class="modal-overlay" id="statusModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Update Candidate Status</h3>
      <button class="modal-close" onclick="closeModal('statusModal')">✕</button>
    </div>
    <div class="form-group">
      <label class="form-label">Status</label>
      <select class="form-control" id="newStatus">
        <?php foreach (['pending','outreach_sent','interview_started','interview_completed','shortlisted','rejected','on_hold'] as $s): ?>
        <option value="<?= $s ?>" <?= $c['status'] === $s ? 'selected' : '' ?>>
          <?= ucfirst(str_replace('_', ' ', $s)) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Notes (optional)</label>
      <textarea class="form-control" id="statusNotes" placeholder="Any comments..."><?= htmlspecialchars($c['notes'] ?? '') ?></textarea>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="btn-outline" onclick="closeModal('statusModal')">Cancel</button>
      <button class="btn-primary" onclick="saveStatus()">
        <i class="fa-solid fa-floppy-disk fa-sm"></i> Save
      </button>
    </div>
  </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="confirm-overlay" id="deleteModal">
  <div class="confirm-box">
    <div class="confirm-icon">🗑️</div>
    <div class="confirm-title">Delete Candidate?</div>
    <div class="confirm-msg">
      This will permanently delete <strong id="delName"></strong> and all their interview data.<br>
      <span style="color:#EF4444;font-weight:700">This cannot be undone.</span>
    </div>
    <div class="confirm-btns">
      <button class="btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
      <button class="btn-danger" id="confirmDeleteBtn">
        <i class="fa-solid fa-trash fa-sm"></i> Delete Permanently
      </button>
    </div>
  </div>
</div>

<script>
// ── TABS ─────────────────────────────────────────────────────
const tabBtns   = document.querySelectorAll('.tab-btn');
const tabPanels = document.querySelectorAll('.tab-panel');

function switchTab(name, btn) {
  tabPanels.forEach(p => p.classList.remove('active'));
  tabBtns.forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
  // Pause all audio/video when switching tabs (prevents background playback)
  document.querySelectorAll('audio, video').forEach(m => m.pause());
}

// ── SCORE BARS ANIMATE ────────────────────────────────────────
window.addEventListener('load', () => {
  setTimeout(() => {
    document.querySelectorAll('.sbar-fill').forEach(el => {
      el.style.width = el.dataset.w + '%';
    });
  }, 300);
});

// ── TOAST (from PHP redirect) ────────────────────────────────
const toastEl = document.getElementById('toastEl');
if (toastEl) {
  setTimeout(() => {
    toastEl.style.animation = 'toastOut .3s forwards';
    setTimeout(() => toastEl.remove(), 300);
  }, 3500);
}

// ── TOAST HELPER ─────────────────────────────────────────────
function showToast(msg, type = 'success') {
  const icons = { success: 'circle-check', error: 'circle-xmark', info: 'circle-info' };
  const t = document.createElement('div');
  t.className = 'toast toast-' + type;
  t.innerHTML = `<i class="fa-solid fa-${icons[type] || 'circle-check'}"></i>${msg}`;
  document.body.appendChild(t);
  setTimeout(() => {
    t.style.animation = 'toastOut .3s forwards';
    setTimeout(() => t.remove(), 300);
  }, 3000);
}

// ── MODALS ────────────────────────────────────────────────────
function openStatusModal() { document.getElementById('statusModal').classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

document.querySelectorAll('.modal-overlay, .confirm-overlay').forEach(m => {
  m.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('active');
  });
});

// ── DELETE ────────────────────────────────────────────────────
let _deleteId = null;
function confirmDelete(id, name) {
  _deleteId = id;
  document.getElementById('delName').textContent = name;
  document.getElementById('deleteModal').classList.add('active');
}

document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {
  const btn = document.getElementById('confirmDeleteBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-xs"></i> Deleting...';
  try {
    const r = await fetch('/api/candidates.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', candidate_id: _deleteId })
    });
    const d = await r.json();
    if (d.success) {
      showToast('Candidate deleted successfully', 'success');
      setTimeout(() => location.href = 'candidates.php', 900);
    } else {
      showToast('Error: ' + (d.error || 'Delete failed'), 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-trash fa-sm"></i> Delete Permanently';
    }
  } catch (e) {
    showToast('Network error. Try again.', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-trash fa-sm"></i> Delete Permanently';
  }
});

// ── STATUS UPDATE ─────────────────────────────────────────────
async function saveStatus() {
  const status = document.getElementById('newStatus').value;
  const notes  = document.getElementById('statusNotes').value;
  const btn    = document.querySelector('#statusModal .btn-primary');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-xs"></i> Saving...';
  try {
    const r = await fetch('/api/candidates.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update_status', candidate_id: <?= $c['id'] ?>, status, notes })
    });
    const d = await r.json();
    if (d.success) {
      closeModal('statusModal');
      showToast('Status updated!', 'success');
      setTimeout(() => location.reload(), 700);
    } else {
      showToast('Error: ' + (d.error || 'Failed'), 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-floppy-disk fa-sm"></i> Save';
    }
  } catch (e) {
    showToast('Network error', 'error');
  }
}

// ── COPY LINK ─────────────────────────────────────────────────
function copyLink() {
  const link = '<?= $interviewLink ?>';
  if (!link) return;
  navigator.clipboard.writeText(link)
    .then(() => showToast('Interview link copied!', 'info'))
    .catch(() => {
      // fallback
      const ta = document.createElement('textarea');
      ta.value = link; document.body.appendChild(ta);
      ta.select(); document.execCommand('copy');
      ta.remove(); showToast('Link copied!', 'info');
    });
}

// ── KEYBOARD SHORTCUTS ────────────────────────────────────────
document.addEventListener('keydown', e => {
  if (e.target.matches('input, textarea, select')) return;
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.active, .confirm-overlay.active')
      .forEach(m => m.classList.remove('active'));
  }
  if (e.key === '1') switchTab('recording', tabBtns[0]);
  if (e.key === '2') switchTab('qa',        tabBtns[1]);
  if (e.key === '3') switchTab('transcript',tabBtns[2]);
  if (e.key === '4') switchTab('notes',     tabBtns[3]);
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
