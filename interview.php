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
if (!empty($candidate['link_expires_at']) && strtotime($candidate['link_expires_at']) < time() && !in_array($candidate['status'], ['interview_completed','shortlisted','rejected','on_hold'])) {
    http_response_code(410);
    die('This interview link has expired. Please contact the recruiter for a fresh link.');
}
$already_done    = in_array($candidate['status'], ['interview_completed','shortlisted','rejected','on_hold']);
$already_started = $candidate['status'] === 'interview_started';

// Count how many answers already recorded (to show on locked screen)
$_answered_count = 0;
if ($already_started) {
    $_row = db_fetch_one(
        "SELECT COUNT(*) cnt FROM interview_answers ia
         JOIN interview_sessions s ON ia.session_id=s.id
         WHERE s.candidate_id=? AND s.status='in_progress'",
        [$candidate['id']], 'i'
    );
    $_answered_count = (int)($_row['cnt'] ?? 0);
}

$questions = db_fetch_all("SELECT * FROM questions WHERE campaign_id=? ORDER BY order_no ASC", [$candidate['campaign_id']], 'i');
if (!$already_done && !$already_started && empty($questions)) { die('No questions configured. Please contact the recruiter.'); }
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
  --bg:#F0F4F8;--surface:#FFFFFF;--surface2:#F8FAFC;--border:#E2E8F0;
  --blue:#2563EB;--blue2:#3B82F6;--cyan:#0891B2;--green:#059669;--red:#DC2626;
  --orange:#D97706;--text:#0F172A;--muted:#64748B;--muted2:#94A3B8;
  --radius:14px;--shadow:0 4px 24px rgba(0,0,0,.09);
}
html,body{height:100%;overflow:hidden}
body{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);display:flex;flex-direction:column}

/* ══ HEADER ══════════════════════════════════════════════════════════════════ */
.hdr{
  background:#fff;border-bottom:1px solid var(--border);
  padding:0 24px;height:58px;display:flex;align-items:center;justify-content:space-between;
  flex-shrink:0;z-index:100;box-shadow:0 1px 4px rgba(0,0,0,.06);
}
.hdr-logo{font-size:18px;font-weight:800;letter-spacing:-.3px;color:var(--text)}
.hdr-logo span{color:var(--blue)}
.hdr-meta{display:flex;flex-direction:column;align-items:flex-end;gap:1px}
.hdr-campaign{font-size:12px;font-weight:700;color:var(--text)}
.hdr-role{font-size:11px;color:var(--muted)}
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
  width:260px;flex-shrink:0;background:#fff;border-left:1.5px solid var(--border);
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
  background:#fff;border-bottom:1px solid var(--border);
  padding:10px 24px;flex-shrink:0;
}
.progress-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.progress-label{font-size:12px;font-weight:700;color:var(--text)}
.progress-pct{font-size:11px;color:var(--muted2)}
.progress-track{background:#E2E8F0;border-radius:99px;height:5px;overflow:hidden}
.progress-fill{height:4px;border-radius:99px;background:linear-gradient(90deg,var(--blue),var(--cyan));transition:width .5s ease}
.step-dots{display:flex;gap:5px;margin-top:8px}
.step-dot{width:8px;height:8px;border-radius:50%;background:var(--border);transition:all .3s;flex-shrink:0}
.step-dot.done{background:var(--green)}
.step-dot.active{background:var(--blue);width:20px;border-radius:4px}

/* ══ QUESTION CARD ═══════════════════════════════════════════════════════════ */
.q-card{
  background:#fff;border:1.5px solid var(--border);border-radius:var(--radius);
  overflow:hidden;margin-bottom:16px;box-shadow:var(--shadow);
}
.q-card-top{
  background:linear-gradient(135deg,#EFF6FF,#F0F9FF);
  border-bottom:1px solid #DBEAFE;padding:18px 20px;
}
.q-meta{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.q-num-badge{background:var(--blue);color:#fff;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;letter-spacing:.5px}
.q-param-badge{background:rgba(6,182,212,.15);color:var(--cyan);border:1px solid rgba(6,182,212,.25);font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px}
.q-text{font-size:17px;font-weight:600;line-height:1.55;color:var(--text)}
@media(max-width:480px){.q-text{font-size:15px}}
.q-card-body{padding:20px}

/* ══ TIMER ═══════════════════════════════════════════════════════════════════ */
.timer-row{display:flex;align-items:center;gap:14px;margin-bottom:20px;padding:12px 16px;background:#F8FAFC;border-radius:10px;border:1px solid var(--border)}
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
.answer-tabs{display:flex;gap:6px;margin-bottom:14px;background:#F1F5F9;padding:4px;border-radius:10px;border:1px solid var(--border)}
.atab{flex:1;padding:8px 12px;border-radius:7px;border:none;background:transparent;color:var(--muted);cursor:pointer;font-size:13px;font-weight:600;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:5px}
.atab.active{background:var(--blue);color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.35)}

/* ══ VOICE RECORDER ══════════════════════════════════════════════════════════ */
.voice-panel{background:#F8FAFC;border-radius:12px;border:1.5px solid var(--border);padding:24px;text-align:center}
.voice-btn-wrap{position:relative;width:72px;height:72px;margin:0 auto 14px}
.voice-btn{width:72px;height:72px;border-radius:50%;border:none;background:var(--blue);color:#fff;font-size:28px;cursor:pointer;transition:all .25s;display:flex;align-items:center;justify-content:center;position:relative;z-index:1}
.voice-btn:hover{transform:scale(1.06);background:#1D4ED8}
.voice-btn.recording{background:var(--red);animation:pulse-btn 1.2s infinite}
.voice-btn.idle-pulse{animation:idle-pulse-btn 1.8s infinite}
@keyframes idle-pulse-btn{0%,100%{box-shadow:0 0 0 0 rgba(37,99,235,.5)}60%{box-shadow:0 0 0 18px rgba(37,99,235,0)}}
@keyframes pulse-btn{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)}50%{box-shadow:0 0 0 14px rgba(239,68,68,0)}}
.voice-ripple{position:absolute;inset:-8px;border-radius:50%;border:2px solid var(--red);opacity:0;animation:ripple 1.2s infinite}
.voice-ripple2{animation-delay:.4s}
/* Idle ripples (blue) shown before recording starts on audio questions */
.voice-ripple-idle{position:absolute;inset:-8px;border-radius:50%;border:2px solid var(--blue2);opacity:0;animation:ripple-idle 1.8s infinite}
.voice-ripple-idle2{animation-delay:.6s}
@keyframes ripple-idle{0%{transform:scale(.9);opacity:.55}100%{transform:scale(1.5);opacity:0}}
@keyframes ripple{0%{transform:scale(.9);opacity:.6}100%{transform:scale(1.4);opacity:0}}
.voice-wave{display:flex;align-items:center;justify-content:center;gap:3px;height:30px;margin-bottom:8px}
.voice-wave span{display:inline-block;width:3px;background:var(--blue2);border-radius:2px;animation:wave .8s ease-in-out infinite}
.voice-wave span:nth-child(2){animation-delay:.1s;background:var(--cyan)}
.voice-wave span:nth-child(3){animation-delay:.2s}
.voice-wave span:nth-child(4){animation-delay:.3s;background:var(--cyan)}
.voice-wave span:nth-child(5){animation-delay:.4s}
@keyframes wave{0%,100%{height:4px}50%{height:24px}}
.voice-status{font-size:12px;color:var(--muted2);margin-bottom:8px}
/* Tap-to-record banner */
.autostart-banner{background:#EFF6FF;border:1.5px solid #BFDBFE;border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:13px;font-weight:700;color:#1D4ED8;display:flex;align-items:center;gap:8px;justify-content:center}
.autostart-banner.hidden{display:none}
/* Locked tab (audio-only questions hide text tab) */
.atab.locked{display:none}
.audio-preview{width:100%;margin-top:10px;border-radius:8px;display:none;filter:invert(1) hue-rotate(180deg);opacity:.8}

/* ══ TEXT ANSWER ═════════════════════════════════════════════════════════════ */
.text-answer{
  width:100%;background:#fff;border:1.5px solid var(--border);
  border-radius:10px;color:var(--text);padding:14px;font-size:14px;resize:none;
  min-height:120px;outline:none;font-family:inherit;transition:border-color .2s,box-shadow .2s;line-height:1.6;
}
.text-answer:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.text-meta{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:6px}
.dynamic-answer{display:flex;flex-direction:column;gap:10px}
.dynamic-answer > input,.dynamic-answer select{
  width:100%;background:#fff;border:1.5px solid var(--border);
  border-radius:10px;color:var(--text);padding:13px 14px;font-size:14px;outline:none;font-family:inherit;
}
.dynamic-answer select option{color:#111827}
.choice-list{display:flex;flex-direction:column;gap:8px}
.choice-item{display:flex;align-items:center;gap:10px;background:#fff;border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;font-size:14px;color:var(--text);cursor:pointer;transition:border-color .15s,background .15s}
.choice-item:hover{border-color:#93C5FD;background:#EFF6FF}
.choice-item input{width:18px;height:18px;flex:0 0 18px;accent-color:var(--blue)}
.choice-item:has(input:checked){border-color:var(--blue);background:#EFF6FF}
.choice-prefix{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;border-radius:999px;background:#DBEAFE;color:#1D4ED8;font-weight:800;font-size:12px}
.choice-empty{border:1px dashed var(--border);border-radius:12px;padding:12px;color:var(--muted);background:#F8FAFC}
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
.btn-next:disabled{background:#CBD5E1;box-shadow:none;cursor:not-allowed;transform:none;color:#94A3B8}

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
.q-nav{padding:14px;border-top:1.5px solid var(--border)}
.q-nav-title{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.q-nav-dots{display:flex;flex-wrap:wrap;gap:5px}
.q-nav-dot{width:28px;height:28px;border-radius:8px;background:#F1F5F9;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--muted);border:1.5px solid var(--border);cursor:default}
.q-nav-dot.done{background:#DCFCE7;color:#15803D;border-color:#86EFAC}
.q-nav-dot.active{background:var(--blue);color:#fff;border-color:var(--blue)}

/* ══ NETWORK TOAST ═══════════════════════════════════════════════════════════ */
.net-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#0F172A;color:#fff;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;pointer-events:none;transition:opacity .4s;white-space:nowrap;box-shadow:0 4px 20px rgba(0,0,0,.25)}
.net-warn{background:#B45309}
.net-info{background:#1D4ED8}

/* ══ PERMISSION SCREEN — full-page bright ════════════════════════════════════ */
.perm-screen{flex:1;display:flex;overflow-y:auto;background:var(--bg)}
.perm-layout{display:flex;width:100%;min-height:100%}
/* Left panel */
.perm-left{
  flex:1;display:flex;flex-direction:column;justify-content:center;
  padding:48px 52px;max-width:580px;
}
/* Right panel — instructions */
.perm-right{
  flex:1;background:#fff;border-left:1.5px solid var(--border);
  display:flex;flex-direction:column;justify-content:center;padding:48px 52px;
}
@media(max-width:860px){
  .perm-layout{flex-direction:column}
  .perm-right{border-left:none;border-top:1.5px solid var(--border);padding:32px 24px}
  .perm-left{padding:32px 24px;max-width:100%}
}
.perm-icon{
  width:64px;height:64px;border-radius:18px;
  display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#EFF6FF,#DBEAFE);
  border:1.5px solid #BFDBFE;font-size:30px;margin-bottom:18px;
  box-shadow:0 4px 16px rgba(37,99,235,.15)
}
.perm-kicker{
  display:inline-flex;align-items:center;gap:6px;margin-bottom:12px;
  padding:5px 12px;border-radius:999px;background:#FEF9C3;
  border:1px solid #FDE68A;color:#92400E;font-size:11px;
  font-weight:800;letter-spacing:.08em;text-transform:uppercase
}
.perm-title{font-size:28px;font-weight:900;letter-spacing:-.5px;color:var(--text);margin-bottom:8px}
.perm-desc{font-size:14px;color:var(--muted);line-height:1.7;margin-bottom:20px}
.perm-must{
  background:#FFF1F2;border:1.5px solid #FECDD3;
  color:#9F1239;border-radius:12px;padding:12px 14px;font-size:12.5px;
  line-height:1.6;margin-bottom:16px;
}
.perm-checks{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px}
.perm-check{
  display:flex;align-items:center;gap:12px;padding:12px 14px;
  background:#F8FAFC;border:1.5px solid var(--border);border-radius:12px;transition:all .3s;
}
.perm-check.ok{background:#ECFDF5;border-color:#86EFAC}
.perm-check.err{background:#FEF2F2;border-color:#FECACA}
.perm-check-icon{font-size:20px;width:28px;text-align:center;flex-shrink:0}
.perm-check-text{font-size:13px;font-weight:700;color:var(--text)}
.perm-check-sub{font-size:11px;color:var(--muted);margin-top:1px}
.perm-error{color:#DC2626;font-size:12px;min-height:18px;margin:8px 0;font-weight:600}
.mobile-permission-note{display:none;background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:12px 14px;font-size:12px;line-height:1.5;margin-bottom:14px}
.consent-row{display:flex;gap:10px;background:#F8FAFC;border:1.5px solid var(--border);border-radius:12px;padding:13px 14px;font-size:12.5px;color:var(--muted);line-height:1.5;margin:14px 0;cursor:pointer}
.consent-row input{accent-color:var(--blue);width:16px;height:16px;flex-shrink:0;margin-top:1px}
.btn-allow{
  width:100%;padding:16px;background:linear-gradient(135deg,#2563EB,#1D4ED8);
  color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:800;
  cursor:pointer;transition:all .2s;box-shadow:0 4px 20px rgba(37,99,235,.35);
  letter-spacing:-.2px;
}
.btn-allow:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(37,99,235,.45)}
.btn-allow:disabled{background:#CBD5E1;box-shadow:none;cursor:not-allowed;transform:none;color:#94A3B8}
/* Right panel instructions */
.instructions-box{background:transparent;border:none;padding:0;text-align:left}
.instructions-title{font-size:13px;font-weight:800;color:var(--blue);text-transform:uppercase;letter-spacing:.6px;margin-bottom:16px;display:flex;align-items:center;gap:7px}
.instructions-list{list-style:none;display:flex;flex-direction:column;gap:12px}
.instructions-list li{font-size:14px;color:var(--text);display:flex;align-items:flex-start;gap:10px;line-height:1.55;padding:10px 14px;background:#F8FAFC;border-radius:10px;border:1px solid var(--border)}
.instructions-list li::before{content:'✓';color:var(--green);font-weight:900;flex-shrink:0;margin-top:1px}
.instructions-list li.warn{background:#FEF9C3;border-color:#FDE68A}
.instructions-list li.warn::before{content:'⚠';color:var(--orange)}

/* ══ COMPLETION SCREEN ════════════════════════════════════════════════════════ */
.done-screen{
  flex:1;display:flex;align-items:center;justify-content:center;padding:24px;
}
.done-card{
  background:#fff;border:1.5px solid var(--border);border-radius:20px;
  padding:40px 32px;max-width:480px;width:100%;text-align:center;
  box-shadow:0 8px 40px rgba(0,0,0,.1);
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
@keyframes faceAlertIn{from{opacity:0;transform:translate(-50%,-50%) scale(.9)}to{opacity:1;transform:translate(-50%,-50%) scale(1)}}
/* Face gate modal */
#face-gate{position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:8000;display:none;align-items:center;justify-content:center;padding:20px}
#face-gate.active{display:flex;animation:fadeIn .3s ease}
.fg-card{background:#fff;border:1.5px solid var(--border);border-radius:20px;padding:32px 28px;max-width:540px;width:100%;text-align:center;box-shadow:0 12px 48px rgba(0,0,0,.12)}
.fg-video-wrap{position:relative;width:100%;aspect-ratio:4/3;background:#0F172A;border-radius:14px;overflow:hidden;margin-bottom:18px;border:2px solid #E2E8F0}
.fg-video-wrap video{width:100%;height:100%;object-fit:cover;transform:scaleX(-1)}
.fg-status-badge{position:absolute;bottom:10px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.65);border:1.5px solid rgba(255,255,255,.2);border-radius:99px;padding:5px 14px;font-size:12px;font-weight:700;color:#fff;white-space:nowrap;backdrop-filter:blur(8px);transition:all .3s}
.fg-status-badge.checking{border-color:#93C5FD;color:#BFDBFE}
.fg-status-badge.fail{border-color:#FCA5A5;color:#FECACA}
.fg-status-badge.ok{border-color:#86EFAC;color:#DCFCE7}
.fg-warning{background:#FFF1F2;border:1.5px solid #FECDD3;border-radius:12px;padding:14px 18px;margin-bottom:18px;font-size:13px;color:#9F1239;line-height:1.6;text-align:left}
.fg-warning strong{color:#BE123C;display:block;margin-bottom:4px;font-size:14px}
.fg-btn{width:100%;padding:15px;border-radius:12px;border:none;font-size:15px;font-weight:800;cursor:pointer;transition:all .2s;letter-spacing:-.2px}
.fg-btn:disabled{background:#F1F5F9;color:#94A3B8;cursor:not-allowed;border:1.5px solid #E2E8F0}
.fg-btn.ready{background:linear-gradient(135deg,#059669,#10B981);color:#fff;box-shadow:0 6px 24px rgba(5,150,105,.35)}
.fg-btn.ready:hover{transform:translateY(-2px);box-shadow:0 8px 32px rgba(5,150,105,.5)}

/* ══ MEDIA QUERIES ═══════════════════════════════════════════════════════════ */
@media(max-width:680px){
  .q-text{font-size:15px}
  .cam-col{height:180px}
  .cam-info{display:none}
  .cam-video-wrap{flex:1;height:100%;aspect-ratio:unset}
  .main-scroll{padding:14px}
  .q-nav{display:none}
  .mobile-permission-note{display:block}
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
    <div class="done-title">Interview Completed</div>
    <div class="done-sub">Hi <?= htmlspecialchars($candidate['name'] ?: 'there') ?>, you have already completed this interview.<br>Our team will review your responses and contact you shortly.</div>
    <div class="powered-by">Powered by <strong>HireAI</strong> — Avyukta Intellicall</div>
  </div>
</div>

<?php elseif ($already_started): ?>
<!-- ══ INTERVIEW LOCKED (started but not completed) ══════════════════════════ -->
<div class="already-done-screen">
  <div class="done-card">
    <div class="done-icon">🔒</div>
    <div class="done-title">Interview Attempt Locked</div>
    <div class="done-sub">
      Hi <?= htmlspecialchars($candidate['name'] ?: 'there') ?>, you have already started this interview
      <?php if ($_answered_count > 0): ?>
        and <strong><?= $_answered_count ?> answer<?= $_answered_count !== 1 ? 's' : '' ?></strong> have been recorded
      <?php endif; ?>.
      <br><br>
      For fairness, only <strong>one attempt is allowed</strong> per candidate. You cannot restart the interview.
      <br><br>
      Your recorded responses will be evaluated by our team and we will reach out to you shortly.
    </div>
    <div class="powered-by">Powered by <strong>HireAI</strong> — Avyukta Intellicall</div>
  </div>
</div>

<?php else: ?>

<!-- ══ TERMINATION SCREEN ════════════════════════════════════════════════════ -->
<div class="app-body" id="termination-screen" style="display:none;align-items:center;justify-content:center;background:var(--bg)">
  <div style="text-align:center;max-width:480px;padding:40px 24px">
    <div style="font-size:64px;margin-bottom:20px">🚫</div>
    <div style="font-size:22px;font-weight:800;color:#EF4444;margin-bottom:10px">Interview Terminated</div>
    <div style="font-size:14px;color:#94A3B8;line-height:1.7;margin-bottom:8px" id="termination-reason">
      Your face was not detected during the interview.
    </div>
    <div style="font-size:13px;color:#64748B;line-height:1.6;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:12px;padding:14px 18px;margin-top:16px">
      This interview session has been marked as <strong style="color:#FCA5A5">terminated due to integrity violation</strong>.
      If you believe this is an error, please contact the recruiter directly.
    </div>
  </div>
</div>

<!-- ══ FACE GATE MODAL ════════════════════════════════════════════════════════ -->
<div id="face-gate">
  <div class="fg-card">
    <div style="font-size:12px;font-weight:800;color:#059669;letter-spacing:.6px;text-transform:uppercase;margin-bottom:10px">IDENTITY VERIFICATION</div>
    <div style="font-size:21px;font-weight:900;color:#0F172A;margin-bottom:6px">Position Your Face in the Camera</div>
    <div style="font-size:13px;color:#64748B;margin-bottom:16px">We need to verify you are present before the test begins</div>
    <div class="fg-video-wrap">
      <video id="fg-video" autoplay muted playsinline></video>
      <div class="fg-status-badge checking" id="fg-badge">🔍 Verifying face…</div>
    </div>
    <div class="fg-warning">
      <strong>⚠️ Critical Notice</strong>
      Your face must be <strong>clearly visible and centered</strong> in the camera throughout the entire test.
      Candidates found absent or not in front of the camera will be <strong>automatically disqualified</strong>
      and their test will be marked as rejected. Face checks happen after every question.
    </div>
    <button class="fg-btn" id="fg-start-btn" disabled onclick="faceGateProceed()">
      Verifying face — please wait…
    </button>
    <div style="font-size:11px;color:#94A3B8;margin-top:10px">Make sure your face is fully visible, well-lit, and centered before proceeding</div>
  </div>
</div>

<!-- ══ PERMISSION SCREEN ═════════════════════════════════════════════════════ -->
<div class="app-body" id="perm-screen">
  <div class="perm-screen">
    <div class="perm-layout">

      <!-- LEFT: permissions + consent + button -->
      <div class="perm-left">
        <div class="perm-icon">🎙️</div>
        <div class="perm-kicker">🔒 Mandatory AI Test</div>
        <div class="perm-title">Before We Begin</div>
        <div class="perm-desc">Complete this AI interview to finish your hiring process. Camera and microphone are required throughout the test.</div>

        <div class="perm-must">
          <strong>📸 Camera &amp; microphone permission is compulsory.</strong><br>
          The test will not start until both are allowed and your face is verified.
        </div>
        <div class="mobile-permission-note">On mobile, tap <strong>Allow</strong> when your browser asks for camera &amp; microphone access.</div>

        <div class="perm-checks">
          <div class="perm-check" id="pc-camera">
            <div class="perm-check-icon">📷</div>
            <div>
              <div class="perm-check-text">Camera Access</div>
              <div class="perm-check-sub">Identity verification</div>
            </div>
          </div>
          <div class="perm-check" id="pc-mic">
            <div class="perm-check-icon">🎤</div>
            <div>
              <div class="perm-check-text">Microphone Access</div>
              <div class="perm-check-sub">Voice answers</div>
            </div>
          </div>
        </div>

        <label class="consent-row">
          <input type="checkbox" id="recording-consent">
          <span>I consent to voice/video recording and understand my responses may be reviewed by the hiring team for recruitment evaluation.</span>
        </label>
        <div class="perm-error" id="perm-error"></div>
        <button class="btn-allow" id="allow-btn" onclick="requestPermissions()">
          Allow Camera &amp; Mic — Start Test →
        </button>

        <div style="margin-top:14px;font-size:12px;color:var(--muted);text-align:center;display:flex;align-items:center;justify-content:center;gap:6px">
          <svg width="14" height="14" viewBox="0 0 32 32" fill="none"><rect width="32" height="32" rx="6" fill="url(#pg)"/><text x="16" y="22" font-family='Arial Black' font-size="14" font-weight="900" fill="white" text-anchor="middle">A</text><defs><linearGradient id="pg" x1="0" y1="0" x2="32" y2="32"><stop offset="0%" stop-color="#6B21A8"/><stop offset="100%" stop-color="#2563EB"/></linearGradient></defs></svg>
          Powered by <strong style="color:var(--blue)">Avyukta Intellicall</strong>
        </div>
      </div>

      <!-- RIGHT: interview instructions -->
      <div class="perm-right">
        <div class="instructions-box">
          <div class="instructions-title">📋 Interview Instructions</div>
          <ul class="instructions-list">
            <li><?= $total_q ?> questions — 3 minutes each to answer</li>
            <li>Answer by <strong>voice 🎤</strong> or <strong>typing ⌨️</strong> — tap the button to start recording</li>
            <li>Sit directly in front of the camera with your <strong>face clearly visible</strong></li>
            <li>Find a <strong>quiet, well-lit place</strong> before starting</li>
            <li>Your session is being recorded end-to-end</li>
            <li>Face detection runs after every question — absence will <strong>terminate your test</strong></li>
            <li class="warn">Do not copy-paste answers — all paste actions are logged and flagged for integrity review</li>
          </ul>
        </div>
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
              <!-- Auto-start countdown banner (shown only on audio-type questions) -->
              <div class="autostart-banner hidden" id="autostart-banner">
                <span id="autostart-text">🎙️ Recording will start automatically…</span>
              </div>
              <div class="voice-wave" id="voice-wave" style="display:none">
                <span></span><span></span><span></span><span></span><span></span>
              </div>
              <div class="voice-btn-wrap">
                <!-- Red ripples (recording) -->
                <div class="voice-ripple" id="ripple1" style="display:none"></div>
                <div class="voice-ripple voice-ripple2" id="ripple2" style="display:none"></div>
                <!-- Blue idle ripples (waiting to record on audio questions) -->
                <div class="voice-ripple-idle" id="idle-ripple1" style="display:none"></div>
                <div class="voice-ripple-idle voice-ripple-idle2" id="idle-ripple2" style="display:none"></div>
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
const SHARE_TEXT = <?= json_encode('I have completed my HireAI interview. You can apply using this campaign link: ') ?> + CAMPAIGN_LINK;
const TIMER_S = 180;
const CIRC    = 2 * Math.PI * 22; // SVG arc length ≈ 138.2

let currentQ = 0, timerInt = null, timeLeft = TIMER_S;
let mediaRecorder = null, audioChunks = [], mediaStream = null;
let videoRecorder = null, videoChunks = [];
let isRecording = false, sessionId = null, interviewStartTime = Date.now();
let answers = [], currentMode = 'voice';
let copyCount = 0, tabSwitchCount = 0, cheatLog = [];
let _faceFailCount = 0, _faceRecheckTimer = null, _interviewTerminated = false;

function pickSupportedMime(candidates) {
  if (!window.MediaRecorder) return '';
  for (const mime of candidates) {
    if (MediaRecorder.isTypeSupported(mime)) return mime;
  }
  return '';
}

// ── PERMISSIONS ─────────────────────────────────────────────────────────────
async function requestPermissions() {
  const btn = document.getElementById('allow-btn');
  const err = document.getElementById('perm-error');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span> Requesting access…';
  err.textContent = '';
  if (!document.getElementById('recording-consent').checked) {
    err.textContent = 'Please provide recording consent before starting.';
    btn.disabled = false;
    btn.textContent = 'Allow Camera & Mic - Start Test →';
    return;
  }
  try {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
      throw new Error('This browser does not support secure camera/microphone recording. Please use latest Chrome, Edge, or Safari over HTTPS.');
    }
    mediaStream = await navigator.mediaDevices.getUserMedia({
      video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
      audio: { echoCancellation: true, noiseSuppression: true, sampleRate: 44100 }
    });
    const hasCamera = mediaStream.getVideoTracks().some(t => t.readyState === 'live');
    const hasMic = mediaStream.getAudioTracks().some(t => t.readyState === 'live');
    if (!hasCamera) throw new Error('Camera is required to start the test. Please allow camera permission and try again.');
    if (!hasMic) throw new Error('Microphone is required. Please allow microphone permission and try again.');
    document.getElementById('video-el').srcObject = mediaStream;
    // Detect camera being turned off / blocked mid-interview
    mediaStream.getVideoTracks().forEach(track => {
      track.addEventListener('ended', () => {
        logCheat('Camera track ended — camera disconnected or blocked');
        terminateInterview('Camera was disconnected or blocked during the test.');
      });
    });
    document.getElementById('pc-camera').className = 'perm-check ok';
    document.getElementById('pc-mic').className    = 'perm-check ok';
    document.getElementById('rec-badge').style.display = 'flex';
    if (!startVideoRecording()) {
      throw new Error('Video recording could not start. Please use latest Chrome/Edge and allow camera permission.');
    }
    await createSession();
    // Show face gate — mirror stream to gate video, verify face before starting
    const fgVideo = document.getElementById('fg-video');
    fgVideo.srcObject = mediaStream;
    document.getElementById('perm-screen').style.display = 'none';
    document.getElementById('face-gate').classList.add('active');
    startFaceGateCheck();
  } catch (e) {
    err.textContent = e.name === 'NotAllowedError'
      ? '❌ Permission denied. Please allow camera & microphone in your browser settings.'
      : '❌ ' + e.message;
    document.getElementById('pc-camera').className = 'perm-check err';
    document.getElementById('pc-mic').className    = 'perm-check err';
    btn.disabled = false;
    btn.textContent = 'Try Again - Allow Camera & Mic';
  }
}

async function createSession() {
  try {
    const r = await fetch('api/interview.php?action=create_session&t=' + TOKEN);
    const d = await r.json();
    sessionId = d.session_id;
  } catch(e) {}
}

let _videoCheckpointTimer = null;
let _videoCheckpointMime  = 'video/webm';

function startVideoRecording() {
  try {
    videoChunks = [];
    const mt = pickSupportedMime(['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm', 'video/mp4']);
    _videoCheckpointMime = mt || 'video/webm';
    const opts = { videoBitsPerSecond: 128000, audioBitsPerSecond: 48000 };
    if (mt) opts.mimeType = mt;
    videoRecorder = new MediaRecorder(mediaStream, opts);
    videoRecorder.ondataavailable = e => { if (e.data.size > 0) videoChunks.push(e.data); };
    videoRecorder.start(5000);

    // Checkpoint every 2 minutes: upload accumulated chunks silently.
    // If the browser closes before the final upload, the last checkpoint is preserved.
    if (_videoCheckpointTimer) clearInterval(_videoCheckpointTimer);
    _videoCheckpointTimer = setInterval(async () => {
      if (videoChunks.length < 4) return; // skip if < 20s recorded
      try {
        const blob = new Blob(videoChunks, { type: _videoCheckpointMime });
        if (blob.size < 50000) return; // skip tiny blobs
        const fd = new FormData();
        fd.append('video', blob, 'session_' + TOKEN + '_partial.webm');
        fd.append('token', TOKEN);
        fd.append('session_id', sessionId || '');
        fd.append('is_partial', '1');
        await fetch('api/upload_video.php', { method: 'POST', body: fd });
        // No-op on failure — final upload will overwrite if it succeeds
      } catch(e) { /* silent — checkpoint is best-effort */ }
    }, 120000); // every 2 minutes

    return videoRecorder.state === 'recording';
  } catch(e) { console.warn('Video recording unavailable:', e); return false; }
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
  // Clear any pending auto-start timer
  if (window._autoStartTimer) { clearTimeout(window._autoStartTimer); window._autoStartTimer = null; }
  if (window._autoStartCountdown) { clearInterval(window._autoStartCountdown); window._autoStartCountdown = null; }

  const type = q.question_type || 'textarea';
  const isAudioQ = ['audio','video'].includes(type);

  // Lock / unlock text tab
  const tabText = document.getElementById('tab-text');
  tabText.classList.toggle('locked', isAudioQ);

  // Reset idle ripples & banner
  document.getElementById('idle-ripple1').style.display = 'none';
  document.getElementById('idle-ripple2').style.display = 'none';
  document.getElementById('voice-btn').classList.remove('idle-pulse');
  document.getElementById('autostart-banner').classList.add('hidden');

  if (isAudioQ) {
    switchTab('voice');
    // Show idle ripples — user taps button to start recording (no auto-start)
    document.getElementById('idle-ripple1').style.display = 'block';
    document.getElementById('idle-ripple2').style.display = 'block';
    document.getElementById('voice-btn').classList.add('idle-pulse');
    const banner = document.getElementById('autostart-text');
    banner.textContent = '🎙️ Tap the button below to start recording';
    document.getElementById('autostart-banner').classList.remove('hidden');
  } else {
    switchTab('text');
  }

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
    if (timeLeft <= 0) { clearInterval(timerInt); logCheat('Time expired'); nextQuestion(true); }
  }, 1000);
}

function parseQuestionOptions(q) {
  const normalize = (options) => Array.isArray(options)
    ? options.map(o => String(o || '').trim()).filter(Boolean)
    : [];
  const inlineChoices = () => {
    const text = q.question_text || '';
    const idx = String(text).search(/choices\s*:/i);
    if (idx < 0) return [];
    const raw = String(text).slice(idx).replace(/^choices\s*:\s*/i, '').trim();
    const markers = [...raw.matchAll(/(?:^|\s)([A-Z]|\d+)[).]\s*/g)];
    if (markers.length) {
      return markers.map((m, i) => {
        const start = (m.index || 0) + m[0].length;
        const end = i + 1 < markers.length ? markers[i + 1].index : raw.length;
        const option = raw.slice(start, end).trim();
        return option || '';
      }).filter(Boolean);
    }
    return raw.split(/\r?\n|,/).map(o => o.trim()).filter(Boolean);
  };
  try {
    if (!q.options_json) return inlineChoices();
    const parsed = typeof q.options_json === 'string' ? JSON.parse(q.options_json) : q.options_json;
    const normalized = normalize(parsed);
    return normalized.length ? normalized : inlineChoices();
  } catch(e) { return inlineChoices(); }
}

function renderDynamicAnswer(q) {
  const wrap = document.getElementById('dynamic-answer');
  const type = q.question_type || 'textarea';
  const options = parseQuestionOptions(q);
  const optionLabel = (i) => String.fromCharCode(65 + i);
  const displayOption = (option, i) => /^\s*([A-Z]|\d+)[).]\s*/.test(String(option)) ? option : `${optionLabel(i)}) ${option}`;
  const choiceHtml = (inputType, multi = false) => {
    if (!options.length) return `<div id="text-answer" class="choice-empty" data-choice-group="1">No choices configured for this question. Please type your answer below.</div><textarea class="text-answer" id="text-answer-fallback" placeholder="Type your answer here..." maxlength="2000"></textarea>`;
    return `<div id="text-answer" class="choice-list" ${multi ? 'data-multi="1"' : 'data-choice-group="1"'}>${options.map((o, i) => `<label class="choice-item"><input type="${inputType}" name="answer_choice" value="${escapeHtml(o)}"><span class="choice-prefix">${optionLabel(i)}</span><span>${escapeHtml(displayOption(o, i))}</span></label>`).join('')}</div>`;
  };
  if (type === 'dropdown') {
    wrap.innerHTML = options.length
      ? `<div id="text-answer" class="choice-list" data-choice-group="1">${options.map((o, i) => `<label class="choice-item"><input type="radio" name="answer_choice" value="${escapeHtml(o)}"><span class="choice-prefix">${optionLabel(i)}</span><span>${escapeHtml(o)}</span></label>`).join('')}</div>`
      : `<select id="text-answer"><option value="">Select an option...</option></select>`;
  } else if (type === 'multi_select') {
    wrap.innerHTML = choiceHtml('checkbox', true);
  } else if (type === 'checkbox') {
    wrap.innerHTML = choiceHtml('checkbox', true);
  } else if (type === 'rating') {
    const ratingOptions = options.length ? options : ['1 - Poor','2 - Fair','3 - Good','4 - Very Good','5 - Excellent'];
    wrap.innerHTML = `<div id="text-answer" class="choice-list" data-choice-group="1">${ratingOptions.map((o, i) => `<label class="choice-item"><input type="radio" name="answer_choice" value="${escapeHtml(o)}"><span class="choice-prefix">${i + 1}</span><span>${escapeHtml(o)}</span></label>`).join('')}</div>`;
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
  } else if (type === 'audio' || type === 'video') {
    wrap.innerHTML = `<textarea class="text-answer" id="text-answer" placeholder="Optional note for your recorded answer..." maxlength="2000"></textarea>`;
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
  if (el.dataset && el.dataset.choiceGroup) {
    const checked = el.querySelector('input:checked');
    return checked ? checked.value : ((document.getElementById('text-answer-fallback') || {}).value || '').trim();
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
  // Cancel auto-start countdown if user taps manually first
  if (window._autoStartTimer) { clearTimeout(window._autoStartTimer); window._autoStartTimer = null; }
  if (window._autoStartCountdown) { clearInterval(window._autoStartCountdown); window._autoStartCountdown = null; }
  document.getElementById('idle-ripple1').style.display = 'none';
  document.getElementById('idle-ripple2').style.display = 'none';
  document.getElementById('voice-btn').classList.remove('idle-pulse');
  document.getElementById('autostart-banner').classList.add('hidden');
  audioChunks = [];
  const aStream = new MediaStream(mediaStream.getAudioTracks());
  if (!aStream.getAudioTracks().length) {
    alert('Microphone track is not available. Please allow microphone permission and restart the test.');
    return;
  }
  const mt = pickSupportedMime(['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus']);
  mediaRecorder = new MediaRecorder(aStream, mt ? { mimeType: mt } : undefined);
  mediaRecorder.ondataavailable = e => { if (e.data && e.data.size > 0) audioChunks.push(e.data); };
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

function stopRecordingAndWait() {
  return new Promise(resolve => {
    if (!mediaRecorder || mediaRecorder.state === 'inactive') {
      isRecording = false;
      resolve();
      return;
    }
    const previousOnStop = mediaRecorder.onstop;
    mediaRecorder.onstop = ev => {
      if (typeof previousOnStop === 'function') previousOnStop(ev);
      isRecording = false;
      resolve();
    };
    try { mediaRecorder.requestData(); } catch(e) {}
    stopRecording();
    setTimeout(resolve, 1200);
  });
}

// ── NEXT QUESTION ────────────────────────────────────────────────────────────
async function nextQuestion(allowBlank = false) {
  const q = QUESTIONS[currentQ];
  const textAnswerBeforeSave = getCurrentAnswerValue();
  const hasVoiceAnswer = currentMode === 'voice' && (audioChunks.length > 0 || isRecording);
  if (!allowBlank && String(q.is_required ?? '1') !== '0' && !textAnswerBeforeSave && !hasVoiceAnswer) {
    alert('Please answer this mandatory question before continuing.');
    return;
  }

  const btn = document.getElementById('next-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span> Saving…';
  clearInterval(timerInt);
  if (isRecording) await stopRecordingAndWait();
  else await new Promise(r => setTimeout(r, 150));

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
    const mt   = audioChunks[0].type || pickSupportedMime(['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus']) || 'audio/webm';
    const blob = new Blob(audioChunks, { type: mt });
    const fd   = new FormData();
    fd.append('audio', blob, 'q' + (currentQ + 1) + '_' + TOKEN + '.webm');
    fd.append('token', TOKEN);
    fd.append('session_id', sessionId || '');
    fd.append('question_no', currentQ + 1);
    try {
      const r = await fetch('api/upload_audio.php', { method: 'POST', body: fd });
      const d = await r.json();
      answer.audio_url = d.url || '';
      if (!answer.audio_url) answer.upload_error = d.error || 'Audio upload failed';
    } catch(e) { answer.upload_error = e.message || 'Audio upload failed'; }
  }
  if (answer.has_voice && !answer.audio_url && !answer.text_answer) {
    answer.text_answer = '[Voice answer recorded but upload failed: ' + (answer.upload_error || 'unknown error') + ']';
  }

  answers.push(answer);
  await saveAnswer(answer);
  copyCount = 0;

  // Face check after each question — terminate on consecutive failure
  const qNoForFace = currentQ + 1;
  checkFaceOrTerminate(qNoForFace, false);

  btn.disabled = false;
  loadQuestion(resolveNextQuestionIndex(textAnswer));
}

function showNetToast(msg, type) {
  const existing = document.querySelectorAll('.net-toast');
  existing.forEach(t => t.remove());
  const t = document.createElement('div');
  t.className = 'net-toast' + (type === 'warn' ? ' net-warn' : type === 'info' ? ' net-info' : '');
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 3500);
}

async function saveAnswer(answer) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const r = await fetch('api/interview.php?action=save_answer', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: TOKEN, session_id: sessionId, answer, cheat_log: cheatLog }),
      });
      if (r.ok) return;
      throw new Error('HTTP ' + r.status);
    } catch(e) {
      if (attempt === 3) {
        showNetToast('Answer saved locally — will retry on reconnect.', 'warn');
      } else {
        await new Promise(res => setTimeout(res, 1000 * attempt));
      }
    }
  }
}

// ── FINISH ───────────────────────────────────────────────────────────────────
async function finishInterview() {
  // Stop checkpoint timer — final upload handles the last chunk
  if (_videoCheckpointTimer) { clearInterval(_videoCheckpointTimer); _videoCheckpointTimer = null; }
  document.getElementById('interview-screen').style.display = 'none';
  document.getElementById('completion-screen').style.display = 'flex';
  document.getElementById('rec-badge').style.display = 'none';

  const interviewEndTime = Date.now();
  const durationSeconds  = Math.round((interviewEndTime - interviewStartTime) / 1000);

  // ── STEP 1: complete_interview FIRST (status + score trigger) ──────────────
  // Must happen before video upload wait — if user closes browser during upload,
  // status would otherwise never update (the old bug that caused stuck sessions).
  const completePayload = JSON.stringify({
    token: TOKEN, session_id: sessionId, answers,
    duration_seconds: durationSeconds,
    cheat_summary: {
      tab_switches : tabSwitchCount,
      face_away    : 0,
      copy_paste   : answers.reduce((s, a) => s + (a.copy_count || 0), 0),
      total_flags  : cheatLog.length,
    },
  });
  // Use sendBeacon as primary (survives page close); fetch as fallback
  let beaconSent = false;
  if (navigator.sendBeacon) {
    const blob = new Blob([completePayload], { type: 'application/json' });
    beaconSent = navigator.sendBeacon('api/interview.php?action=complete_interview', blob);
  }
  if (!beaconSent) {
    try {
      await fetch('api/interview.php?action=complete_interview', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: completePayload,
      });
    } catch(e) { console.warn('complete_interview fetch failed', e); }
  }

  // ── STEP 2: video upload is best-effort background (does not block status update) ──
  if (videoRecorder && videoRecorder.state !== 'inactive') {
    const videoUploadPromise = new Promise(resolve => {
      videoRecorder.onstop = async () => { await uploadVideo(); resolve(); };
      setTimeout(resolve, 45000);
    });
    videoRecorder.stop();
    await videoUploadPromise;
  }
  if (mediaStream) mediaStream.getTracks().forEach(t => t.stop());
  document.getElementById('share-wa').href = 'https://wa.me/?text=' + encodeURIComponent(SHARE_TEXT);
  document.getElementById('share-mail').href = 'mailto:?subject=' + encodeURIComponent('HireAI campaign referral') + '&body=' + encodeURIComponent(SHARE_TEXT);
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
  if (blob.size > 98 * 1024 * 1024) {
    console.warn('Interview recording too large to upload (' + Math.round(blob.size/1024/1024) + 'MB), skipped.');
    return;
  }
  const fd = new FormData();
  fd.append('video', blob, 'session_' + TOKEN + '.webm');
  fd.append('token', TOKEN);
  fd.append('session_id', sessionId || '');
  try {
    const r = await fetch('api/upload_video.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.url) console.warn('Video upload failed:', d.error || d);
  } catch(e) {
    console.warn('Video upload failed:', e);
  }
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
  let lastFaceLogQ = -1, lastFaceLogTime = 0;
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
      // Log at most once per 30s per question to avoid flooding the cheat log
      const now = Date.now();
      if (currentQ !== lastFaceLogQ || now - lastFaceLogTime > 30000) {
        logCheat('Low light / face not visible');
        lastFaceLogQ = currentQ;
        lastFaceLogTime = now;
      }
      if (fs) fs.textContent = '⚠️ Too dark — adjust lighting';
    } else {
      if (fs) fs.textContent = '✅ Face detected';
    }
  }, 4000);
}

function logCheat(msg) {
  cheatLog.push({ time: new Date().toISOString(), msg, question: currentQ + 1 });
}

let _faceGateTimer = null;

function startFaceGateCheck() {
  const badge  = document.getElementById('fg-badge');
  const btn    = document.getElementById('fg-start-btn');
  let attempts = 0;

  async function check() {
    attempts++;
    badge.className = 'fg-status-badge checking';
    badge.textContent = '🔍 Verifying face…';
    btn.disabled = true;
    btn.textContent = 'Verifying face — please wait…';

    const frame = captureFrame(document.getElementById('fg-video'));
    if (!frame) {
      // Video not ready yet — retry
      _faceGateTimer = setTimeout(check, 2000);
      return;
    }
    // Brightness pre-filter: dark frame = no face, don't waste an API call
    if (frame.brightness < 25) {
      badge.className = 'fg-status-badge fail';
      badge.textContent = '💡 Too dark — improve lighting';
      btn.disabled = true;
      btn.textContent = 'Too dark — please improve lighting';
      _faceGateTimer = setTimeout(check, 2500);
      return;
    }
    const fd = new FormData();
    fd.append('image', frame.dataUrl);
    fd.append('token', TOKEN);
    fd.append('question_no', 0);
    try {
      const r = await fetch('api/check_face.php', { method: 'POST', body: fd });
      const d = r.ok ? await r.json() : null;
      if (d && d.face === true) {
        badge.className = 'fg-status-badge ok';
        badge.textContent = '✅ Face detected — ready!';
        btn.disabled = false;
        btn.className = 'fg-btn ready';
        btn.textContent = '✅ Face Verified — Start Interview →';
      } else {
        badge.className = 'fg-status-badge fail';
        badge.textContent = '❌ Face not detected — adjust position';
        btn.disabled = true;
        btn.textContent = 'Face not detected — adjusting…';
        _faceGateTimer = setTimeout(check, 3000);
      }
    } catch(e) {
      // On network error fallback after 2 retries — don't block forever
      if (attempts >= 3) {
        badge.className = 'fg-status-badge ok';
        badge.textContent = '⚠️ Verification skipped';
        btn.disabled = false;
        btn.className = 'fg-btn ready';
        btn.textContent = 'Start Interview →';
      } else {
        _faceGateTimer = setTimeout(check, 2500);
      }
    }
  }
  // Wait for video to be ready, then start checking
  setTimeout(check, 1200);
}

function faceGateProceed() {
  clearTimeout(_faceGateTimer);
  document.getElementById('face-gate').classList.remove('active');
  document.getElementById('interview-screen').style.display = 'flex';
  loadQuestion(0);
  startAntiCheat();
  startFaceDetection();
}

function frameBrightness(canvas) {
  // Sample centre 50%×60% of frame — where a face would be
  const ctx = canvas.getContext('2d');
  const x = Math.floor(canvas.width * 0.25), y = Math.floor(canvas.height * 0.1);
  const w = Math.floor(canvas.width * 0.5),  h = Math.floor(canvas.height * 0.6);
  const d = ctx.getImageData(x, y, w, h).data;
  let sum = 0;
  for (let i = 0; i < d.length; i += 16) sum += (d[i] + d[i+1] + d[i+2]) / 3;
  return sum / (d.length / 16);
}

// Returns { dataUrl, brightness } or null if video not ready
function captureFrame(videoEl) {
  try {
    if (!videoEl || !videoEl.videoWidth) return null;
    const c = document.createElement('canvas');
    c.width = 320; c.height = 240;
    c.getContext('2d').drawImage(videoEl, 0, 0, 320, 240);
    return { dataUrl: c.toDataURL('image/jpeg', 0.7), brightness: frameBrightness(c) };
  } catch(e) { return null; }
}

function captureFaceFrameFrom(videoEl) {
  return Promise.resolve(captureFrame(videoEl)?.dataUrl ?? null);
}

function captureFaceFrame() {
  return captureFaceFrameFrom(document.getElementById('video-el'));
}

async function checkFaceOrTerminate(qNo, isRecheck) {
  if (_interviewTerminated) return;
  const frame = captureFrame(document.getElementById('video-el'));
  if (!frame) return; // no video — skip silently

  // Dark frame = face definitely not visible — treat same as API failure
  if (frame.brightness < 25) {
    logCheat('Dark frame (brightness ' + Math.round(frame.brightness) + ') after Q' + qNo);
    _faceFailCount++;
    if (isRecheck) {
      terminateInterview('Your camera appeared blocked or too dark on consecutive checks. The interview has been terminated.');
    } else {
      showFaceWarning(qNo);
    }
    return;
  }

  const fd = new FormData();
  fd.append('token', TOKEN);
  fd.append('image', frame.dataUrl);
  fd.append('question_no', qNo);
  try {
    const r = await fetch('api/check_face.php', { method: 'POST', body: fd });
    const d = r.ok ? await r.json() : null;
    if (d && d.face === false) {
      logCheat((isRecheck ? 'Re-check failed' : 'Face not visible') + ' after Q' + qNo);
      _faceFailCount++;
      if (isRecheck) {
        // Second consecutive failure → terminate
        terminateInterview('Your face was not visible on two consecutive checks. The interview has been terminated to ensure test integrity.');
      } else {
        // First failure → show warning with re-check countdown
        showFaceWarning(qNo);
      }
    } else {
      _faceFailCount = 0;
      // Clear any existing face warning
      document.querySelectorAll('.face-alert').forEach(el => el.remove());
    }
  } catch(e) {
    _faceFailCount = 0; // network error — don't penalise
  }
}

function showFaceWarning(qNo) {
  document.querySelectorAll('.face-alert').forEach(el => el.remove());
  const el = document.createElement('div');
  el.className = 'face-alert';
  el.style.cssText = `position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
    background:#0D1B2E;border:2px solid #EF4444;border-radius:18px;
    padding:28px 36px;z-index:9999;text-align:center;max-width:420px;width:90%;
    box-shadow:0 24px 64px rgba(0,0,0,.9);animation:faceAlertIn .25s cubic-bezier(.4,0,.2,1)`;
  let secs = 15;
  el.innerHTML = `
    <div style="font-size:42px;margin-bottom:12px">🚨</div>
    <div style="font-size:17px;font-weight:800;color:#EF4444;margin-bottom:8px">Face Not Detected!</div>
    <div style="font-size:13px;color:#94A3B8;line-height:1.7;margin-bottom:12px">
      Your face was <strong style="color:#FCA5A5">not visible</strong> during the last question.<br>
      Please sit directly in front of the camera now.<br><br>
      <strong style="color:#FCA5A5">⚠️ If your face is not detected again, your interview will be automatically terminated.</strong>
    </div>
    <div style="font-size:24px;font-weight:900;color:#EF4444;margin-bottom:14px" id="face-warn-countdown">${secs}s</div>
    <div style="font-size:11px;color:#64748B">Re-checking automatically in ${secs} seconds…</div>`;
  document.body.appendChild(el);
  const tick = setInterval(() => {
    secs--;
    const cd = document.getElementById('face-warn-countdown');
    if (cd) cd.textContent = secs + 's';
    if (secs <= 0) {
      clearInterval(tick);
      el.remove();
      checkFaceOrTerminate(qNo, true); // re-check
    }
  }, 1000);
}

async function terminateInterview(reason) {
  if (_interviewTerminated) return;
  _interviewTerminated = true;
  clearTimeout(_faceRecheckTimer);
  clearInterval(timerInt);
  document.querySelectorAll('.face-alert').forEach(el => el.remove());

  // Show termination screen
  document.getElementById('interview-screen').style.display = 'none';
  document.getElementById('face-gate').classList.remove('active');
  const ts = document.getElementById('termination-screen');
  ts.style.display = 'flex';
  if (reason) document.getElementById('termination-reason').textContent = reason;

  // Complete session on backend with termination flag
  logCheat('TERMINATED: ' + (reason || 'face not detected'));
  const payload = JSON.stringify({
    token: TOKEN, session_id: sessionId, answers,
    duration_seconds: Math.round((Date.now() - interviewStartTime) / 1000),
    cheat_summary: {
      tab_switches      : tabSwitchCount,
      face_away         : _faceFailCount,
      copy_paste        : answers.reduce((s, a) => s + (a.copy_count || 0), 0),
      total_flags       : cheatLog.length,
      terminated        : true,
      termination_reason: 'face_not_detected',
      cheat_log         : cheatLog,
    },
  });
  if (navigator.sendBeacon) {
    navigator.sendBeacon('api/interview.php?action=complete_interview', new Blob([payload], { type: 'application/json' }));
  } else {
    fetch('api/interview.php?action=complete_interview', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload
    }).catch(() => {});
  }

  // Stop recording and camera
  if (isRecording) { try { if (videoRecorder?.state !== 'inactive') videoRecorder.stop(); } catch(e) {} }
  if (mediaStream) mediaStream.getTracks().forEach(t => t.stop());
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
