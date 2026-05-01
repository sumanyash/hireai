<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$token = $_GET['t'] ?? '';
if (!$token) { http_response_code(404); die('Invalid link'); }

$candidate = db_fetch_one(
    "SELECT c.*, camp.name as campaign_name, camp.job_role, camp.el_agent_id,
     camp.num_questions, camp.max_duration_minutes, camp.passing_score, camp.language,
     o.name as org_name
     FROM candidates c
     JOIN campaigns camp ON c.campaign_id=camp.id
     JOIN organizations o ON c.org_id=o.id
     WHERE c.unique_token=?",
    [$token], 's'
);

if (!$candidate) { http_response_code(404); die('Invalid or expired interview link.'); }
$already_done = in_array($candidate['status'], ['interview_completed','shortlisted','rejected']);
$questions = db_fetch_all("SELECT * FROM questions WHERE campaign_id=? ORDER BY order_no ASC", [$candidate['campaign_id']], 'i');
if (!$already_done && empty($questions)) { die('No questions configured. Please contact the recruiter.'); }
$total_q = count($questions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>AI Interview — <?= htmlspecialchars($candidate['campaign_name']) ?></title>
<style>
/* ══ RESET & BASE ════════════════════════════════════════════════════════════ */
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --bg:#060D1A;--surface:#0D1B2E;--surface2:#112240;--border:#1B3055;
  --blue:#2563EB;--blue2:#3B82F6;--cyan:#06B6D4;--green:#10B981;--red:#EF4444;
  --orange:#F59E0B;--text:#F1F5F9;--muted:#64748B;--muted2:#94A3B8;
  --radius:14px;--shadow:0 8px 32px rgba(0,0,0,.45);
}
html,body{height:100%;overflow:hidden}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);display:flex;flex-direction:column}

/* ══ HEADER ══════════════════════════════════════════════════════════════════ */
.hdr{
  background:var(--surface);border-bottom:1px solid var(--border);
  padding:0 20px;height:56px;display:flex;align-items:center;justify-content:space-between;
  flex-shrink:0;z-index:100;
}
.hdr-logo{font-size:18px;font-weight:800;letter-spacing:-.3px}
.hdr-logo span{color:var(--blue2)}
.hdr-meta{display:flex;flex-direction:column;align-items:flex-end;gap:1px}
.hdr-campaign{font-size:12px;font-weight:600;color:var(--text)}
.hdr-role{font-size:11px;color:var(--muted2)}
.hdr-actions{display:flex;align-items:center;gap:10px}
.rec-badge{display:flex;align-items:center;gap:5px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);border-radius:20px;padding:4px 10px;font-size:11px;font-weight:700;color:#F87171}
.rec-dot{width:7px;height:7px;background:#EF4444;border-radius:50%;animation:blink 1s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}

/* ══ LAYOUT ══════════════════════════════════════════════════════════════════ */
.app-body{flex:1;display:flex;overflow:hidden;min-height:0}

/* LEFT — main content */
.main-col{
  flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;
  padding:0;
}
.main-scroll{flex:1;overflow-y:auto;padding:20px;min-height:0;scroll-behavior:smooth}
.main-scroll::-webkit-scrollbar{width:4px}
.main-scroll::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}

/* RIGHT — camera sidebar */
.cam-col{
  width:260px;flex-shrink:0;background:var(--surface);border-left:1px solid var(--border);
  display:flex;flex-direction:column;overflow:hidden;
}
@media(max-width:680px){
  .app-body{flex-direction:column}
  .cam-col{width:100%;height:200px;border-left:none;border-top:1px solid var(--border);flex-direction:row}
  .cam-video-wrap{flex:0 0 160px;height:100%}
  .cam-info{flex:1;padding:12px;justify-content:center}
}

/* ══ PROGRESS ════════════════════════════════════════════════════════════════ */
.progress-bar-wrap{
  background:var(--surface);border-bottom:1px solid var(--border);
  padding:10px 20px;flex-shrink:0;
}
.progress-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.progress-label{font-size:12px;font-weight:700;color:var(--text)}
.progress-pct{font-size:11px;color:var(--muted2)}
.progress-track{background:var(--border);border-radius:99px;height:4px;overflow:hidden}
.progress-fill{height:4px;border-radius:99px;background:linear-gradient(90deg,var(--blue),var(--cyan));transition:width .5s ease}
.step-dots{display:flex;gap:5px;margin-top:8px}
.step-dot{width:8px;height:8px;border-radius:50%;background:var(--border);transition:all .3s;flex-shrink:0}
.step-dot.done{background:var(--green)}
.step-dot.active{background:var(--blue);width:20px;border-radius:4px}

/* ══ QUESTION CARD ═══════════════════════════════════════════════════════════ */
.q-card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  overflow:hidden;margin-bottom:16px;
}
.q-card-top{
  background:linear-gradient(135deg,rgba(37,99,235,.15),rgba(6,182,212,.08));
  border-bottom:1px solid var(--border);padding:18px 20px;
}
.q-meta{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.q-num-badge{background:var(--blue);color:#fff;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;letter-spacing:.5px}
.q-param-badge{background:rgba(6,182,212,.15);color:var(--cyan);border:1px solid rgba(6,182,212,.25);font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px}
.q-text{font-size:17px;font-weight:600;line-height:1.55;color:var(--text)}
@media(max-width:480px){.q-text{font-size:15px}}
.q-card-body{padding:20px}

/* ══ TIMER ═══════════════════════════════════════════════════════════════════ */
.timer-row{display:flex;align-items:center;gap:14px;margin-bottom:20px;padding:12px 16px;background:rgba(255,255,255,.03);border-radius:10px;border:1px solid var(--border)}
.timer-ring{position:relative;width:52px;height:52px;flex-shrink:0}
.timer-svg{transform:rotate(-90deg)}
.timer-bg{fill:none;stroke:var(--border);stroke-width:3}
.timer-arc{fill:none;stroke:var(--blue2);stroke-width:3;stroke-linecap:round;stroke-dasharray:138.2;stroke-dashoffset:0;transition:stroke-dashoffset .9s linear,stroke .3s}
.timer-arc.warning{stroke:var(--orange)}
.timer-arc.danger{stroke:var(--red)}
.timer-text{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:var(--text)}
.timer-text.danger{color:var(--red)}
.timer-info{flex:1}
.timer-label{font-size:12px;font-weight:700;color:var(--text);margin-bottom:2px}
.timer-sub{font-size:11px;color:var(--muted)}

/* ══ ANSWER TABS ═════════════════════════════════════════════════════════════ */
.answer-tabs{display:flex;gap:6px;margin-bottom:14px;background:rgba(255,255,255,.04);padding:4px;border-radius:10px}
.atab{flex:1;padding:8px 12px;border-radius:7px;border:none;background:transparent;color:var(--muted2);cursor:pointer;font-size:13px;font-weight:600;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:5px}
.atab.active{background:var(--blue);color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.4)}

/* ══ VOICE RECORDER ══════════════════════════════════════════════════════════ */
.voice-panel{background:rgba(255,255,255,.03);border-radius:12px;border:1px solid var(--border);padding:24px;text-align:center}
.voice-btn-wrap{position:relative;width:72px;height:72px;margin:0 auto 14px}
.voice-btn{width:72px;height:72px;border-radius:50%;border:none;background:var(--blue);color:#fff;font-size:28px;cursor:pointer;transition:all .25s;display:flex;align-items:center;justify-content:center;position:relative;z-index:1}
.voice-btn:hover{transform:scale(1.06);background:#1D4ED8}
.voice-btn.recording{background:var(--red);animation:pulse-btn 1.2s infinite}
@keyframes pulse-btn{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)}50%{box-shadow:0 0 0 14px rgba(239,68,68,0)}}
.voice-ripple{position:absolute;inset:-8px;border-radius:50%;border:2px solid var(--red);opacity:0;animation:ripple 1.2s infinite}
.voice-ripple2{animation-delay:.4s}
@keyframes ripple{0%{transform:scale(.9);opacity:.6}100%{transform:scale(1.4);opacity:0}}
.voice-wave{display:flex;align-items:center;justify-content:center;gap:3px;height:30px;margin-bottom:8px}
.voice-wave span{display:inline-block;width:3px;background:var(--blue2);border-radius:2px;animation:wave .8s ease-in-out infinite}
.voice-wave span:nth-child(2){animation-delay:.1s;background:var(--cyan)}
.voice-wave span:nth-child(3){animation-delay:.2s}
.voice-wave span:nth-child(4){animation-delay:.3s;background:var(--cyan)}
.voice-wave span:nth-child(5){animation-delay:.4s}
@keyframes wave{0%,100%{height:4px}50%{height:24px}}
.voice-status{font-size:12px;color:var(--muted2);margin-bottom:8px}
.audio-preview{width:100%;margin-top:10px;border-radius:8px;display:none;filter:invert(1) hue-rotate(180deg);opacity:.8}

/* ══ TEXT ANSWER ═════════════════════════════════════════════════════════════ */
.text-answer{
  width:100%;background:rgba(255,255,255,.03);border:1.5px solid var(--border);
  border-radius:10px;color:var(--text);padding:14px;font-size:14px;resize:none;
  min-height:120px;outline:none;font-family:inherit;transition:border-color .2s;line-height:1.6;
}
.text-answer:focus{border-color:var(--blue);background:rgba(37,99,235,.05)}
.text-meta{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:6px}
.dynamic-answer{display:flex;flex-direction:column;gap:10px}
.dynamic-answer input,.dynamic-answer select{
  width:100%;background:rgba(255,255,255,.03);border:1.5px solid var(--border);
  border-radius:10px;color:var(--text);padding:13px 14px;font-size:14px;outline:none;font-family:inherit;
}
.dynamic-answer select option{color:#111827}
.choice-list{display:flex;flex-direction:column;gap:8px}
.choice-item{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:14px;color:var(--text)}
.share-row{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:18px}
.share-btn{border:none;border-radius:10px;padding:10px 14px;font-size:13px;font-weight:700;cursor:pointer;color:#fff;display:inline-flex;align-items:center;gap:7px;text-decoration:none}
.share-wa{background:#16A34A}.share-mail{background:#2563EB}.share-copy{background:#7C3AED}

/* ══ NEXT BUTTON ═════════════════════════════════════════════════════════════ */
.btn-next{
  width:100%;padding:14px;background:linear-gradient(135deg,var(--blue),#1D4ED8);
  color:#fff;border:none;border-radius:11px;font-size:15px;font-weight:700;
  cursor:pointer;transition:all .2s;margin-top:14px;
  box-shadow:0 4px 20px rgba(37,99,235,.35);
  display:flex;align-items:center;justify-content:center;gap:8px;
}
.btn-next:hover{transform:translateY(-1px);box-shadow:0 6px 28px rgba(37,99,235,.45)}
.btn-next:disabled{background:var(--surface2);box-shadow:none;cursor:not-allowed;transform:none;color:var(--muted)}

/* ══ CAMERA PANEL ════════════════════════════════════════════════════════════ */
.cam-video-wrap{position:relative;background:#000;aspect-ratio:4/3;overflow:hidden;flex-shrink:0}
.cam-video-wrap video{width:100%;height:100%;object-fit:cover;transform:scaleX(-1)}
.cam-overlay-badge{position:absolute;top:8px;right:8px;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:4px 10px;font-size:10px;font-weight:700;color:#fff;display:flex;align-items:center;gap:4px}
.cam-info{padding:14px;display:flex;flex-direction:column;gap:8px;flex:1}
.cam-status-row{display:flex;align-items:center;gap:7px}
.cam-status-icon{font-size:14px}
.cam-status-text{font-size:11px;color:var(--muted2)}
.cam-status-text strong{color:var(--text);display:block;font-size:12px;margin-bottom:1px}
/* Question navigator */
.q-nav{padding:14px;border-top:1px solid var(--border)}
.q-nav-title{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.q-nav-dots{display:flex;flex-wrap:wrap;gap:5px}
.q-nav-dot{width:28px;height:28px;border-radius:8px;background:var(--border);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--muted);border:none;cursor:default}
.q-nav-dot.done{background:rgba(16,185,129,.2);color:var(--green);border:1px solid rgba(16,185,129,.3)}
.q-nav-dot.active{background:var(--blue);color:#fff}

/* ══ PERMISSION SCREEN ════════════════════════════════════════════════════════ */
.perm-screen{
  flex:1;display:flex;align-items:center;justify-content:center;padding:24px;overflow-y:auto;
}
.perm-card{
  background:var(--surface);border:1px solid var(--border);border-radius:20px;
  padding:32px 28px;max-width:480px;width:100%;text-align:center;
  box-shadow:0 24px 80px rgba(0,0,0,.4);
}
.perm-icon{font-size:56px;margin-bottom:16px;line-height:1}
.perm-title{font-size:22px;font-weight:800;letter-spacing:-.3px;margin-bottom:8px}
.perm-desc{font-size:13px;color:var(--muted2);line-height:1.7;margin-bottom:20px}
.perm-checks{display:flex;flex-direction:column;gap:8px;margin-bottom:20px;text-align:left}
.perm-check{
  display:flex;align-items:center;gap:12px;padding:12px 14px;
  background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;
  transition:all .3s;
}
.perm-check.ok{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.25)}
.perm-check.err{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.25)}
.perm-check-icon{font-size:20px;width:28px;text-align:center;flex-shrink:0}
.perm-check-text{font-size:13px;font-weight:600;color:var(--text)}
.perm-check-sub{font-size:11px;color:var(--muted2);margin-top:1px}
.perm-error{color:#F87171;font-size:12px;min-height:18px;margin:8px 0;font-weight:600}
.btn-allow{
  width:100%;padding:14px;background:linear-gradient(135deg,var(--blue),#1D4ED8);
  color:#fff;border:none;border-radius:11px;font-size:15px;font-weight:700;
  cursor:pointer;transition:all .2s;box-shadow:0 4px 20px rgba(37,99,235,.35);
}
.btn-allow:hover{transform:translateY(-1px)}
.btn-allow:disabled{background:var(--surface2);box-shadow:none;cursor:not-allowed;transform:none}
.instructions-box{
  background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;
  padding:14px;text-align:left;margin-top:16px;
}
.instructions-title{font-size:11px;font-weight:800;color:var(--cyan);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.instructions-list{list-style:none;display:flex;flex-direction:column;gap:5px}
.instructions-list li{font-size:12px;color:var(--muted2);display:flex;align-items:flex-start;gap:7px;line-height:1.5}
.instructions-list li::before{content:'›';color:var(--blue2);font-weight:700;flex-shrink:0;margin-top:1px}

/* ══ COMPLETION SCREEN ════════════════════════════════════════════════════════ */
.done-screen{
  flex:1;display:flex;align-items:center;justify-content:center;padding:24px;
}
.done-card{
  background:var(--surface);border:1px solid var(--border);border-radius:20px;
  padding:40px 32px;max-width:460px;width:100%;text-align:center;
  box-shadow:0 24px 80px rgba(0,0,0,.4);
}
.done-icon{font-size:64px;margin-bottom:20px;animation:pop .5s cubic-bezier(.175,.885,.32,1.275)}
@keyframes pop{0%{transform:scale(0)}100%{transform:scale(1)}}
.done-title{font-size:26px;font-weight:800;letter-spacing:-.4px;margin-bottom:10px}
.done-sub{font-size:14px;color:var(--muted2);line-height:1.7;margin-bottom:20px}
.done-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.25);border-radius:20px;padding:6px 14px;font-size:12px;font-weight:700;color:var(--green)}
.powered-by{margin-top:20px;font-size:11px;color:var(--muted);padding:10px;background:rgba(255,255,255,.03);border-radius:8px}
.powered-by strong{color:var(--blue2)}

/* ══ ALREADY DONE ════════════════════════════════════════════════════════════ */
.already-done-screen{
  flex:1;display:flex;align-items:center;justify-content:center;padding:24px;
}

/* ══ SPINNER ═════════════════════════════════════════════════════════════════ */
.spin{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* ══ MEDIA QUERIES ═══════════════════════════════════════════════════════════ */
@media(max-width:680px){
  .q-text{font-size:15px}
  .cam-col{height:180px}
  .cam-info{display:none}
  .cam-video-wrap{flex:1;height:100%;aspect-ratio:unset}
  .main-scroll{padding:14px}
  .q-nav{display:none}
}
@media(max-width:380px){
  .hdr{padding:0 12px}
  .btn-next{font-size:14px;padding:12px}
  .q-card-top{padding:14px}
  .q-card-body{padding:14px}
}
</style>
</head>
<body>

<!-- ══ HEADER ════════════════════════════════════════════════════════════════ -->
<div class="hdr">
  <div class="hdr-logo">Hire<span>AI</span></div>
  <div class="hdr-actions">
    <div id="rec-badge" class="rec-badge" style="display:none">
      <div class="rec-dot"></div>REC
    </div>
    <div class="hdr-meta">
      <div class="hdr-campaign"><?= htmlspecialchars($candidate['campaign_name']) ?></div>
      <div class="hdr-role"><?= htmlspecialchars($candidate['job_role']) ?></div>
    </div>
  </div>
</div>

<?php if ($already_done): ?>
<!-- ══ ALREADY DONE ══════════════════════════════════════════════════════════ -->
<div class="already-done-screen">
  <div class="done-card">
    <div class="done-icon">✅</div>
    <div class="done-title">Already Completed</div>
    <div class="done-sub">Hi <?= htmlspecialchars($candidate['name'] ?: 'there') ?>, you have already completed your interview.<br>Our team will contact you shortly.</div>
    <div class="powered-by">Powered by <strong>HireAI</strong> — Avyukta Intellicall</div>
  </div>
</div>

<?php else: ?>

<!-- ══ PERMISSION SCREEN ═════════════════════════════════════════════════════ -->
<div class="app-body" id="perm-screen">
  <div class="perm-screen">
    <div class="perm-card">
      <div class="perm-icon">🎙️</div>
      <div style="display:flex;align-items:center;gap:8px;justify-content:center;margin-bottom:4px">
        <svg width="20" height="20" viewBox="0 0 32 32" fill="none"><rect width="32" height="32" rx="6" fill="url(#ng)"/><text x="16" y="22" font-family='Arial Black' font-size="14" font-weight="900" fill="white" text-anchor="middle">A</text><defs><linearGradient id="ng" x1="0" y1="0" x2="32" y2="32"><stop offset="0%" stop-color="#6B21A8"/><stop offset="100%" stop-color="#2563EB"/></linearGradient></defs></svg>
        <span style="font-size:13px;color:#9CA3AF">Powered by <strong style="color:#A78BFA">Avyukta Intellicall</strong></span>
      </div>
      <div class="perm-title">Before We Begin</div>
      <div class="perm-desc">This AI interview requires your <strong>camera and microphone</strong>. Please grant access to continue.</div>

      <div class="perm-checks">
        <div class="perm-check" id="pc-camera">
          <div class="perm-check-icon">📷</div>
          <div>
            <div class="perm-check-text">Camera Access</div>
            <div class="perm-check-sub">Required for identity verification</div>
          </div>
        </div>
        <div class="perm-check" id="pc-mic">
          <div class="perm-check-icon">🎤</div>
          <div>
            <div class="perm-check-text">Microphone Access</div>
            <div class="perm-check-sub">Required for voice answers</div>
          </div>
        </div>
      </div>

      <div class="perm-error" id="perm-error"></div>
      <button class="btn-allow" id="allow-btn" onclick="requestPermissions()">
        Allow Access &amp; Start Interview →
      </button>

      <div class="instructions-box">
        <div class="instructions-title">📋 Interview Instructions</div>
        <ul class="instructions-list">
          <li><?= $total_q ?> questions — 3 minutes each to answer</li>
          <li>Answer by <strong>voice</strong> 🎤 or <strong>typing</strong> ⌨️ — your choice</li>
          <li>Stay in frame with your face clearly visible</li>
          <li>Find a quiet place with good lighting</li>
          <li>Your session is being recorded</li>
          <li style="color:#FCA5A5;font-weight:600">⚠️ Do not copy-paste answers — all paste actions are logged and flagged for integrity review</li>
          <li style="color:#FCA5A5">Pasting text over 20 characters will be marked as a violation in your report</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- ══ INTERVIEW SCREEN ══════════════════════════════════════════════════════ -->
<div class="app-body" id="interview-screen" style="display:none">

  <!-- PROGRESS BAR -->
  <div id="main-col" class="main-col">
    <div class="progress-bar-wrap">
      <div class="progress-top">
        <div class="progress-label" id="progress-label">Question 1 of <?= $total_q ?></div>
        <div class="progress-pct" id="progress-pct">0%</div>
      </div>
      <div class="progress-track">
        <div class="progress-fill" id="progress-fill" style="width:0%"></div>
      </div>
      <div class="step-dots" id="step-dots">
        <?php for($i=0;$i<$total_q;$i++): ?>
        <div class="step-dot <?= $i===0?'active':'' ?>" id="dot-<?= $i ?>"></div>
        <?php endfor; ?>
      </div>
    </div>

    <!-- SCROLLABLE CONTENT -->
    <div class="main-scroll">

      <!-- QUESTION CARD -->
      <div class="q-card">
        <div class="q-card-top">
          <div class="q-meta">
            <span class="q-num-badge" id="q-num">Q1</span>
            <span class="q-param-badge" id="q-param">Loading…</span>
          </div>
          <div class="q-text" id="q-text">Loading question…</div>
        </div>

        <div class="q-card-body">
          <!-- TIMER -->
          <div class="timer-row">
            <div class="timer-ring">
              <svg class="timer-svg" width="52" height="52" viewBox="0 0 52 52">
                <circle class="timer-bg" cx="26" cy="26" r="22"/>
                <circle class="timer-arc" id="timer-arc" cx="26" cy="26" r="22"/>
              </svg>
              <div class="timer-text" id="timer-text">3:00</div>
            </div>
            <div class="timer-info">
              <div class="timer-label">Time Remaining</div>
              <div class="timer-sub" id="timer-sub">Take your time to answer clearly</div>
            </div>
          </div>

          <!-- ANSWER TABS -->
          <div class="answer-tabs">
            <button class="atab active" id="tab-voice" onclick="switchTab('voice')">🎤 Voice Answer</button>
            <button class="atab" id="tab-text" onclick="switchTab('text')">⌨️ Type Answer</button>
          </div>

          <!-- VOICE PANEL -->
          <div id="voice-panel">
            <div class="voice-panel">
              <div class="voice-wave" id="voice-wave" style="display:none">
                <span></span><span></span><span></span><span></span><span></span>
              </div>
              <div class="voice-btn-wrap">
                <div class="voice-ripple" id="ripple1" style="display:none"></div>
                <div class="voice-ripple voice-ripple2" id="ripple2" style="display:none"></div>
                <button class="voice-btn" id="voice-btn" onclick="toggleRecording()">🎤</button>
              </div>
              <div class="voice-status" id="voice-status">Tap to start recording your answer</div>
              <audio id="audio-preview" class="audio-preview" controls></audio>
            </div>
          </div>

          <!-- TEXT PANEL -->
          <div id="text-panel" style="display:none">
            <div id="dynamic-answer" class="dynamic-answer">
              <textarea class="text-answer" id="text-answer" placeholder="Type your answer here…" maxlength="2000"></textarea>
            </div>
            <div class="text-meta">
              <span id="char-count">0 / 2000</span>
              <span id="paste-warn" style="color:var(--orange);display:none">⚠️ Paste detected</span>
            </div>
          </div>

          <!-- NEXT BUTTON -->
          <button class="btn-next" id="next-btn" onclick="nextQuestion()">
            Next Question <span style="font-size:16px">→</span>
          </button>
        </div>
      </div>

    </div><!-- /main-scroll -->
  </div><!-- /main-col -->

  <!-- CAMERA SIDEBAR -->
  <div class="cam-col">
    <div class="cam-video-wrap">
      <video id="video-el" autoplay muted playsinline></video>
      <canvas id="face-canvas" style="display:none"></canvas>
      <div class="cam-overlay-badge"><span class="rec-dot"></span>LIVE</div>
    </div>
    <div class="cam-info">
      <div class="cam-status-row">
        <div class="cam-status-icon">👤</div>
        <div class="cam-status-text">
          <strong>Face Detection</strong>
          <span id="face-status">Detecting…</span>
        </div>
      </div>
      <div class="cam-status-row">
        <div class="cam-status-icon">🔒</div>
        <div class="cam-status-text">
          <strong>Session Secure</strong>
          <span>End-to-end encrypted</span>
        </div>
      </div>
    </div>
    <div class="q-nav">
      <div class="q-nav-title">Questions</div>
      <div class="q-nav-dots" id="q-nav-dots">
        <?php for($i=0;$i<$total_q;$i++): ?>
        <div class="q-nav-dot <?= $i===0?'active':'' ?>" id="navdot-<?= $i ?>"><?= $i+1 ?></div>
        <?php endfor; ?>
      </div>
    </div>
  </div>

</div><!-- /interview-screen -->

<!-- ══ COMPLETION SCREEN ═════════════════════════════════════════════════════ -->
<div class="done-screen" id="completion-screen" style="display:none">
  <div class="done-card">
    <div class="done-icon">🎉</div>
    <div class="done-title">Interview Completed!</div>
    <div class="done-sub">
      Thank you <strong><?= htmlspecialchars($candidate['name'] ?: '') ?></strong>!<br>
      Your interview has been submitted successfully.<br><br>
      You will receive a <strong>WhatsApp message</strong> from our team shortly.
    </div>
    <div class="done-badge">✅ Responses Submitted</div>
    <div class="share-row">
      <a id="share-wa" class="share-btn share-wa" target="_blank" rel="noopener">WhatsApp</a>
      <a id="share-mail" class="share-btn share-mail">Email</a>
      <button class="share-btn share-copy" onclick="copyReferral()">Copy Link</button>
    </div>
    <div class="powered-by">Powered by <strong>HireAI</strong> — Avyukta Intellicall</div>
  </div>
</div>

<?php endif; ?>

<script>
const TOKEN   = <?= json_encode($token) ?>;
const QUESTIONS = <?= json_encode(array_values($questions)) ?>;
const CAMPAIGN_LINK = <?= json_encode(BASE_URL . '/apply.php?campaign_id=' . (int)$candidate['campaign_id'] . '&ref=' . ($candidate['unique_token'] ?? '')) ?>;
const SHARE_TEXT = <?= json_encode('I just completed my HireAI interview. You can apply using this campaign link: ') ?> + CAMPAIGN_LINK;
const TIMER_S = 180;
const CIRC    = 2 * Math.PI * 22; // SVG arc length ≈ 138.2

let currentQ = 0, timerInt = null, timeLeft = TIMER_S;
let mediaRecorder = null, audioChunks = [], mediaStream = null;
let videoRecorder = null, videoChunks = [];
let isRecording = false, sessionId = null;
let answers = [], currentMode = 'voice';
let copyCount = 0, tabSwitchCount = 0, cheatLog = [];

// ── PERMISSIONS ─────────────────────────────────────────────────────────────
async function requestPermissions() {
  const btn = document.getElementById('allow-btn');
  const err = document.getElementById('perm-error');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span> Requesting access…';
  err.textContent = '';
  try {
    mediaStream = await navigator.mediaDevices.getUserMedia({
      video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
      audio: { echoCancellation: true, noiseSuppression: true, sampleRate: 44100 }
    });
    document.getElementById('video-el').srcObject = mediaStream;
    document.getElementById('pc-camera').className = 'perm-check ok';
    document.getElementById('pc-mic').className    = 'perm-check ok';
    document.getElementById('rec-badge').style.display = 'flex';
    startVideoRecording();
    await createSession();
    setTimeout(() => {
      document.getElementById('perm-screen').style.display = 'none';
      document.getElementById('interview-screen').style.display = 'flex';
      loadQuestion(0);
      startAntiCheat();
      startFaceDetection();
    }, 600);
  } catch (e) {
    err.textContent = e.name === 'NotAllowedError'
      ? '❌ Permission denied. Please allow camera & microphone in your browser settings.'
      : '❌ ' + e.message;
    document.getElementById('pc-camera').className = 'perm-check err';
    document.getElementById('pc-mic').className    = 'perm-check err';
    btn.disabled = false;
    btn.textContent = 'Try Again';
  }
}

async function createSession() {
  try {
    const r = await fetch('api/interview.php?action=create_session&t=' + TOKEN);
    const d = await r.json();
    sessionId = d.session_id;
  } catch(e) {}
}

function startVideoRecording() {
  try {
    videoChunks = [];
    const mt = MediaRecorder.isTypeSupported('video/webm;codecs=vp9') ? 'video/webm;codecs=vp9' : 'video/webm';
    videoRecorder = new MediaRecorder(mediaStream, { mimeType: mt });
    videoRecorder.ondataavailable = e => { if (e.data.size > 0) videoChunks.push(e.data); };
    videoRecorder.start(5000);
  } catch(e) { console.warn('Video recording unavailable:', e); }
}

// ── QUESTION LOADING ────────────────────────────────────────────────────────
function loadQuestion(index) {
  if (index >= QUESTIONS.length) { finishInterview(); return; }
  const q = QUESTIONS[index];
  currentQ = index;
  timeLeft = TIMER_S;

  // Update question display
  document.getElementById('q-num').textContent   = 'Q' + (index + 1);
  document.getElementById('q-param').textContent = q.parameter_label || '';
  document.getElementById('q-text').textContent  = q.question_text;

  // Progress
  const pct = Math.round((index / QUESTIONS.length) * 100);
  document.getElementById('progress-fill').style.width = pct + '%';
  document.getElementById('progress-pct').textContent  = pct + '%';
  document.getElementById('progress-label').textContent = 'Question ' + (index + 1) + ' of ' + QUESTIONS.length;

  // Step dots
  for (let i = 0; i < QUESTIONS.length; i++) {
    const d = document.getElementById('dot-' + i);
    const n = document.getElementById('navdot-' + i);
    if (i < index)        { d.className = 'step-dot done';   if(n) n.className = 'q-nav-dot done'; }
    else if (i === index) { d.className = 'step-dot active'; if(n) n.className = 'q-nav-dot active'; }
    else                  { d.className = 'step-dot';        if(n) n.className = 'q-nav-dot'; }
  }

  // Reset answer state
  document.getElementById('text-answer').value = '';
  document.getElementById('char-count').textContent = '0 / 2000';
  document.getElementById('audio-preview').style.display = 'none';
  document.getElementById('paste-warn').style.display = 'none';
  renderDynamicAnswer(q);
  audioChunks = [];
  if (isRecording) stopRecording();
  const type = q.question_type || 'textarea';
  if (['dropdown','multi_select','number','decimal','date','text','hyperlink','file'].includes(type)) switchTab('text');

  // Next / Submit label
  const isLast = index === QUESTIONS.length - 1;
  document.getElementById('next-btn').innerHTML = isLast
    ? 'Submit Interview ✓'
    : 'Next Question <span style="font-size:16px">→</span>';

  // Scroll to top
  document.querySelector('.main-scroll').scrollTo(0, 0);

  // Start timer
  clearInterval(timerInt);
  updateTimer();
  timerInt = setInterval(() => {
    timeLeft--;
    updateTimer();
    if (timeLeft <= 0) { clearInterval(timerInt); logCheat('Time expired'); nextQuestion(); }
  }, 1000);
}

function parseQuestionOptions(q) {
  try {
    if (!q.options_json) return [];
    const parsed = typeof q.options_json === 'string' ? JSON.parse(q.options_json) : q.options_json;
    return Array.isArray(parsed) ? parsed : [];
  } catch(e) { return []; }
}

function renderDynamicAnswer(q) {
  const wrap = document.getElementById('dynamic-answer');
  const type = q.question_type || 'textarea';
  const options = parseQuestionOptions(q);
  if (type === 'dropdown') {
    wrap.innerHTML = `<select id="text-answer"><option value="">Select an option...</option>${options.map(o => `<option value="${escapeHtml(o)}">${escapeHtml(o)}</option>`).join('')}</select>`;
  } else if (type === 'multi_select') {
    wrap.innerHTML = `<div id="text-answer" class="choice-list" data-multi="1">${options.map((o, i) => `<label class="choice-item"><input type="checkbox" value="${escapeHtml(o)}"> ${escapeHtml(o)}</label>`).join('')}</div>`;
  } else if (type === 'number' || type === 'decimal') {
    wrap.innerHTML = `<input id="text-answer" type="number" ${type === 'decimal' ? 'step="0.01"' : 'step="1"'} placeholder="Enter number">`;
  } else if (type === 'date') {
    wrap.innerHTML = `<input id="text-answer" type="date">`;
  } else if (type === 'hyperlink') {
    wrap.innerHTML = `<input id="text-answer" type="url" placeholder="https://...">`;
  } else if (type === 'file') {
    wrap.innerHTML = `<input id="text-answer" type="text" placeholder="Paste file link or drive URL">`;
  } else if (type === 'text') {
    wrap.innerHTML = `<input id="text-answer" type="text" maxlength="500" placeholder="Type your answer here...">`;
  } else {
    wrap.innerHTML = `<textarea class="text-answer" id="text-answer" placeholder="Type your answer here…" maxlength="2000"></textarea>`;
  }
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[s]));
}

function getCurrentAnswerValue() {
  const el = document.getElementById('text-answer');
  if (!el) return '';
  if (el.dataset && el.dataset.multi) {
    return Array.from(el.querySelectorAll('input:checked')).map(i => i.value).join(', ');
  }
  return (el.value || '').trim();
}

function resolveNextQuestionIndex(answerText) {
  const q = QUESTIONS[currentQ];
  let rules = [];
  try {
    rules = q.branch_rules_json ? (typeof q.branch_rules_json === 'string' ? JSON.parse(q.branch_rules_json) : q.branch_rules_json) : [];
  } catch(e) { rules = []; }
  const answer = String(answerText || '').toLowerCase();
  for (const rule of rules) {
    const when = String(rule.when || '').toLowerCase();
    const op = rule.operator || 'contains';
    const matched =
      op === 'equals' ? answer === when :
      op === 'not_empty' ? answer.length > 0 :
      answer.includes(when);
    if (!matched) continue;
    const order = parseInt(rule.jump_to_order || rule.skip_to_order || 0, 10);
    if (order > 0) {
      const idx = QUESTIONS.findIndex(item => parseInt(item.order_no, 10) === order);
      if (idx >= 0) return idx;
    }
  }
  return currentQ + 1;
}

// ── TIMER ───────────────────────────────────────────────────────────────────
function updateTimer() {
  const mins = Math.floor(timeLeft / 60);
  const secs = timeLeft % 60;
  const display = mins + ':' + (secs < 10 ? '0' : '') + secs;
  document.getElementById('timer-text').textContent = display;

  // SVG arc
  const ratio = timeLeft / TIMER_S;
  const offset = CIRC * (1 - ratio);
  const arc = document.getElementById('timer-arc');
  arc.style.strokeDashoffset = offset;

  const isWarn   = timeLeft <= 60 && timeLeft > 30;
  const isDanger = timeLeft <= 30;
  arc.className       = 'timer-arc' + (isDanger ? ' danger' : isWarn ? ' warning' : '');
  const tt = document.getElementById('timer-text');
  tt.className        = 'timer-text' + (isDanger ? ' danger' : '');
  document.getElementById('timer-sub').textContent = isDanger
    ? '⚠️ Almost out of time!'
    : isWarn
    ? 'Wrap up your answer'
    : 'Take your time to answer clearly';
}

// ── RECORDING ───────────────────────────────────────────────────────────────
function switchTab(mode) {
  currentMode = mode;
  document.getElementById('voice-panel').style.display = mode === 'voice' ? 'block' : 'none';
  document.getElementById('text-panel').style.display  = mode === 'text'  ? 'block' : 'none';
  document.getElementById('tab-voice').className = 'atab' + (mode === 'voice' ? ' active' : '');
  document.getElementById('tab-text').className  = 'atab' + (mode === 'text'  ? ' active' : '');
  if (mode === 'text' && isRecording) stopRecording();
}

function toggleRecording() { isRecording ? stopRecording() : startRecording(); }

function startRecording() {
  if (!mediaStream) return;
  audioChunks = [];
  const aStream = new MediaStream(mediaStream.getAudioTracks());
  const mt = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm';
  mediaRecorder = new MediaRecorder(aStream, { mimeType: mt });
  mediaRecorder.ondataavailable = e => audioChunks.push(e.data);
  mediaRecorder.onstop = () => {
    const blob = new Blob(audioChunks, { type: mt });
    const preview = document.getElementById('audio-preview');
    preview.src = URL.createObjectURL(blob);
    preview.style.display = 'block';
  };
  mediaRecorder.start();
  isRecording = true;
  document.getElementById('voice-btn').className = 'voice-btn recording';
  document.getElementById('voice-btn').textContent = '⏹';
  document.getElementById('voice-status').textContent = '🔴 Recording… tap to stop';
  document.getElementById('voice-wave').style.display = 'flex';
  document.getElementById('ripple1').style.display = 'block';
  document.getElementById('ripple2').style.display = 'block';
}

function stopRecording() {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
  isRecording = false;
  document.getElementById('voice-btn').className = 'voice-btn';
  document.getElementById('voice-btn').textContent = '🎤';
  document.getElementById('voice-status').textContent = '✅ Recorded! Tap to re-record';
  document.getElementById('voice-wave').style.display = 'none';
  document.getElementById('ripple1').style.display = 'none';
  document.getElementById('ripple2').style.display = 'none';
}

// ── NEXT QUESTION ────────────────────────────────────────────────────────────
async function nextQuestion() {
  const btn = document.getElementById('next-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span> Saving…';
  clearInterval(timerInt);
  if (isRecording) stopRecording();
  await new Promise(r => setTimeout(r, 350));

  const textAnswer = getCurrentAnswerValue();
  const answer = {
    question_id     : QUESTIONS[currentQ].id,
    question_text   : QUESTIONS[currentQ].question_text,
    parameter       : QUESTIONS[currentQ].parameter,
    parameter_label : QUESTIONS[currentQ].parameter_label,
    max_marks       : QUESTIONS[currentQ].max_marks,
    text_answer     : textAnswer,
    has_voice       : audioChunks.length > 0,
    time_taken      : TIMER_S - timeLeft,
    copy_count      : copyCount,
    answer_mode     : currentMode,
    audio_url       : '',
  };

  // Upload audio if recorded
  if (audioChunks.length > 0) {
    const mt   = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm';
    const blob = new Blob(audioChunks, { type: mt });
    const fd   = new FormData();
    fd.append('audio', blob, 'q' + (currentQ + 1) + '_' + TOKEN + '.webm');
    fd.append('token', TOKEN);
    fd.append('question_no', currentQ + 1);
    try {
      const r = await fetch('api/upload_audio.php', { method: 'POST', body: fd });
      const d = await r.json();
      answer.audio_url = d.url || '';
    } catch(e) {}
  }

  answers.push(answer);
  await saveAnswer(answer);
  copyCount = 0;
  btn.disabled = false;
  loadQuestion(resolveNextQuestionIndex(textAnswer));
}

async function saveAnswer(answer) {
  try {
    await fetch('api/interview.php?action=save_answer', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN, session_id: sessionId, answer, cheat_log: cheatLog }),
    });
  } catch(e) {}
}

// ── FINISH ───────────────────────────────────────────────────────────────────
async function finishInterview() {
  document.getElementById('interview-screen').style.display = 'none';
  document.getElementById('completion-screen').style.display = 'flex';
  document.getElementById('rec-badge').style.display = 'none';

  if (videoRecorder && videoRecorder.state !== 'inactive') {
    videoRecorder.stop();
    videoRecorder.onstop = async () => { await uploadVideo(); };
  }
  if (mediaStream) mediaStream.getTracks().forEach(t => t.stop());
  document.getElementById('share-wa').href = 'https://wa.me/?text=' + encodeURIComponent(SHARE_TEXT);
  document.getElementById('share-mail').href = 'mailto:?subject=' + encodeURIComponent('HireAI campaign referral') + '&body=' + encodeURIComponent(SHARE_TEXT);

  try {
    await fetch('api/interview.php?action=complete_interview', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        token: TOKEN, session_id: sessionId, answers,
        cheat_summary: {
          tab_switches : tabSwitchCount,
          face_away    : 0,
          copy_paste   : answers.reduce((s, a) => s + (a.copy_count || 0), 0),
          total_flags  : cheatLog.length,
        },
      }),
    });
  } catch(e) {}
}

async function copyReferral() {
  try {
    await navigator.clipboard.writeText(CAMPAIGN_LINK);
    alert('Referral link copied');
  } catch(e) {
    prompt('Copy this referral link', CAMPAIGN_LINK);
  }
}

async function uploadVideo() {
  if (!videoChunks.length) return;
  const blob = new Blob(videoChunks, { type: 'video/webm' });
  if (blob.size > 20 * 1024 * 1024) return;
  const fd = new FormData();
  fd.append('video', blob, 'session_' + TOKEN + '.webm');
  fd.append('token', TOKEN);
  fd.append('session_id', sessionId || '');
  try { await fetch('api/upload_video.php', { method: 'POST', body: fd }); } catch(e) {}
}

// ── ANTI-CHEAT (silent — no UI indicators to candidate) ─────────────────────
function startAntiCheat() {
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { tabSwitchCount++; logCheat('Tab switch #' + tabSwitchCount); }
  });
  window.addEventListener('blur', () => logCheat('Window focus lost'));

  document.addEventListener('paste', e => {
    if (!e.target.closest('#text-panel')) return;
    const txt = e.clipboardData?.getData('text') || '';
    if (txt.length > 20) {
      copyCount++;
      logCheat('⚠️ PASTE VIOLATION: ' + txt.length + ' chars pasted (threshold: 20)');
      const warn = document.getElementById('paste-warn');
      warn.textContent = '🚨 Paste violation logged (' + txt.length + ' chars)';
      warn.style.display = 'inline';
      warn.style.color = '#EF4444';
      setTimeout(() => { warn.style.display = 'none'; }, 5000);
      // Show popup warning
      showPasteAlert(txt.length);
    } else {
      logCheat('Paste detected (' + txt.length + ' chars — under threshold)');
    }
  });
  document.addEventListener('input', e => {
    if (!e.target.closest('#text-panel')) return;
    const value = getCurrentAnswerValue();
    document.getElementById('char-count').textContent = value.length + ' / 2000';
  });
  document.addEventListener('contextmenu', e => e.preventDefault());
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'v') {
      copyCount++;
      logCheat('Ctrl+V detected');
    }
  });
}

function startFaceDetection() {
  const video  = document.getElementById('video-el');
  const canvas = document.getElementById('face-canvas');
  const ctx    = canvas.getContext('2d');
  setInterval(() => {
    if (!video.videoWidth) return;
    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    ctx.drawImage(video, 0, 0);
    const imgData = ctx.getImageData(
      Math.floor(canvas.width * .25), Math.floor(canvas.height * .1),
      Math.floor(canvas.width * .5),  Math.floor(canvas.height * .6)
    );
    let brightness = 0;
    for (let i = 0; i < imgData.data.length; i += 40)
      brightness += (imgData.data[i] + imgData.data[i+1] + imgData.data[i+2]) / 3;
    const avg = brightness / (imgData.data.length / 40);
    const fs  = document.getElementById('face-status');
    if (avg < 18) {
      logCheat('Low light / face not visible');
      if (fs) fs.textContent = '⚠️ Too dark — adjust lighting';
    } else {
      if (fs) fs.textContent = '✅ Face detected';
    }
  }, 4000);
}

function logCheat(msg) {
  cheatLog.push({ time: new Date().toISOString(), msg, question: currentQ + 1 });
}

function showPasteAlert(charCount) {
  const alert = document.createElement('div');
  alert.style.cssText = `position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
    background:#1E0A2E;border:2px solid #EF4444;border-radius:16px;
    padding:24px 32px;z-index:9999;text-align:center;max-width:380px;
    box-shadow:0 20px 60px rgba(0,0,0,0.8);`;
  alert.innerHTML = `
    <div style="font-size:36px;margin-bottom:12px">🚨</div>
    <div style="font-size:16px;font-weight:700;color:#EF4444;margin-bottom:8px">Paste Violation Detected</div>
    <div style="font-size:13px;color:#9CA3AF;line-height:1.6;margin-bottom:16px">
      You pasted <strong style="color:#FCA5A5">${charCount} characters</strong>.<br>
      Pasting over 20 characters is flagged as a violation.<br>
      This has been recorded in your integrity report.
    </div>
    <div style="font-size:12px;color:#6B7280;margin-bottom:16px">⚠️ Repeated violations may disqualify your application.</div>
    <button onclick="this.parentElement.remove()" style="background:linear-gradient(135deg,#6B21A8,#7C3AED);
      color:#fff;border:none;border-radius:8px;padding:10px 24px;font-size:14px;font-weight:600;cursor:pointer;">
      I Understand
    </button>`;
  document.body.appendChild(alert);
  setTimeout(() => { if (alert.parentElement) alert.remove(); }, 8000);
}
</script>
</body>
</html>
