<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth_check.php';
$current = 'training.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>User Guide — HireAI</title>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<style>
/* ── ROOT ── */
:root{
  --s-width:232px;
  --content-pad:32px;
  --blue:#2563EB;--blue-lt:#EFF6FF;--blue-border:#BFDBFE;
  --purple:#7C3AED;--purple-lt:#F5F3FF;
  --green:#059669;--green-lt:#ECFDF5;
  --orange:#D97706;--orange-lt:#FFFBEB;
  --red:#DC2626;--red-lt:#FFF1F2;
  --text:#0F172A;--muted:#64748B;--muted2:#94A3B8;
  --border:#E2E8F0;--bg:#F8FAFC;--surface:#FFFFFF;
  --radius:12px;--shadow:0 1px 4px rgba(0,0,0,.07),0 4px 16px rgba(0,0,0,.05);
}

/* ── LAYOUT ── */
.tg-root{display:flex;height:calc(100vh - 58px);overflow:hidden;background:var(--bg)}

/* ── SIDEBAR ── */
.tg-nav{
  width:var(--s-width);flex-shrink:0;
  background:var(--surface);border-right:1px solid var(--border);
  display:flex;flex-direction:column;overflow:hidden
}
.tg-nav-top{
  padding:20px 16px 14px;border-bottom:1px solid var(--border);flex-shrink:0
}
.tg-nav-title{font-size:13px;font-weight:800;color:var(--text);margin-bottom:2px}
.tg-nav-sub{font-size:11px;color:var(--muted2)}
.tg-nav-body{flex:1;overflow-y:auto;padding:10px 8px 20px}
.tg-nav-body::-webkit-scrollbar{width:0}
.tg-nav-group{margin-top:20px}
.tg-nav-group:first-child{margin-top:4px}
.tg-nav-label{
  font-size:10px;font-weight:700;color:var(--muted2);
  text-transform:uppercase;letter-spacing:.7px;
  padding:0 8px 6px
}
.tg-nav-item{
  display:flex;align-items:center;gap:8px;
  padding:7px 10px;border-radius:8px;
  font-size:12.5px;font-weight:600;color:var(--muted);
  cursor:pointer;border:none;background:none;width:100%;text-align:left;
  transition:all .15s;text-decoration:none;margin-bottom:1px
}
.tg-nav-item i{width:14px;text-align:center;font-size:11px;flex-shrink:0}
.tg-nav-item:hover{background:#F1F5F9;color:var(--text)}
.tg-nav-item.active{background:var(--blue-lt);color:var(--blue);font-weight:700}
.tg-nav-item.active i{color:var(--blue)}
.tg-nav-footer{
  padding:12px 16px;border-top:1px solid var(--border);flex-shrink:0
}
.tg-print-btn{
  display:flex;align-items:center;justify-content:center;gap:7px;
  width:100%;padding:9px;border-radius:8px;
  background:var(--bg);border:1px solid var(--border);
  font-size:12px;font-weight:700;color:var(--muted);cursor:pointer;
  transition:all .15s
}
.tg-print-btn:hover{background:var(--border);color:var(--text)}

/* ── CONTENT AREA ── */
.tg-body{flex:1;overflow-y:auto;overflow-x:hidden}
.tg-body::-webkit-scrollbar{width:5px}
.tg-body::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px}

/* ── HERO ── */
.tg-hero{
  background:linear-gradient(135deg,#1E3A8A 0%,#312E81 50%,#4C1D95 100%);
  padding:40px var(--content-pad) 36px;position:relative;overflow:hidden
}
.tg-hero::after{
  content:'';position:absolute;right:-80px;top:-80px;
  width:320px;height:320px;border-radius:50%;
  background:rgba(255,255,255,.04);pointer-events:none
}
.tg-hero-eyebrow{
  display:inline-flex;align-items:center;gap:6px;
  background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);
  border-radius:20px;padding:4px 12px;
  font-size:11px;font-weight:700;color:rgba(255,255,255,.8);
  letter-spacing:.4px;text-transform:uppercase;margin-bottom:16px
}
.tg-hero h1{
  font-size:28px;font-weight:900;color:#fff;
  letter-spacing:-.6px;line-height:1.2;margin-bottom:10px
}
.tg-hero p{
  font-size:13.5px;color:rgba(255,255,255,.7);
  line-height:1.7;max-width:500px;margin-bottom:20px
}
.tg-hero-tags{display:flex;flex-wrap:wrap;gap:8px}
.tg-hero-tag{
  background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);
  border-radius:20px;padding:5px 13px;
  font-size:11.5px;font-weight:600;color:rgba(255,255,255,.75)
}

/* ── PAGE CONTENT ── */
.tg-page{padding:36px var(--content-pad) 60px}

/* ── SECTION ── */
.tg-sec{margin-bottom:52px;scroll-margin-top:24px}
.tg-sec-hd{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.tg-sec-icon{
  width:38px;height:38px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:16px;flex-shrink:0
}
.tg-sec-title{font-size:20px;font-weight:900;color:var(--text);letter-spacing:-.3px}
.tg-sec-sub{font-size:12.5px;color:var(--muted);margin-top:2px}
.tg-divider{border:none;border-top:1.5px solid var(--border);margin:0 0 22px}

/* ── CARD ── */
.card{
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius);padding:18px 20px;margin-bottom:12px;
  box-shadow:var(--shadow)
}
.card-title{
  font-size:13px;font-weight:800;color:var(--text);
  margin-bottom:8px;display:flex;align-items:center;gap:7px
}
.card-title i{color:var(--blue);font-size:12px}
.card-body{font-size:13px;color:#475569;line-height:1.75}

/* ── STEPS ── */
.steps{display:flex;flex-direction:column;gap:10px}
.step{
  display:flex;gap:14px;
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius);padding:16px 18px;
  box-shadow:var(--shadow)
}
.step-n{
  width:28px;height:28px;border-radius:50%;flex-shrink:0;margin-top:1px;
  background:var(--blue);color:#fff;
  font-size:12px;font-weight:900;
  display:flex;align-items:center;justify-content:center
}
.step-body{flex:1;min-width:0}
.step-title{font-size:13px;font-weight:800;color:var(--text);margin-bottom:4px}
.step-desc{font-size:12.5px;color:var(--muted);line-height:1.65}
.step-note{
  display:flex;align-items:flex-start;gap:7px;
  margin-top:8px;padding:7px 10px;border-radius:7px;
  font-size:11.5px;font-weight:600;line-height:1.5
}
.note-tip{background:var(--blue-lt);border:1px solid var(--blue-border);color:#1D4ED8}
.note-warn{background:var(--orange-lt);border:1px solid #FDE68A;color:#92400E}
.note-danger{background:var(--red-lt);border:1px solid #FECDD3;color:#9F1239}

/* ── INFO BOX ── */
.info{
  display:flex;align-items:flex-start;gap:10px;
  padding:12px 14px;border-radius:10px;
  font-size:12.5px;line-height:1.65;margin:12px 0
}
.info i{flex-shrink:0;margin-top:1px}
.info-blue{background:var(--blue-lt);border:1px solid var(--blue-border);color:#1E40AF}
.info-green{background:var(--green-lt);border:1px solid #86EFAC;color:#166534}
.info-orange{background:var(--orange-lt);border:1px solid #FDE68A;color:#92400E}
.info-red{background:var(--red-lt);border:1px solid #FECDD3;color:#9F1239}

/* ── GRID ── */
.g2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
@media(max-width:700px){.g2,.g3{grid-template-columns:1fr}}

/* ── FEATURE TILE ── */
.tile{
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius);padding:18px;box-shadow:var(--shadow)
}
.tile-icon{
  width:36px;height:36px;border-radius:9px;
  display:flex;align-items:center;justify-content:center;
  font-size:15px;margin-bottom:11px
}
.tile-title{font-size:13px;font-weight:800;color:var(--text);margin-bottom:5px}
.tile-desc{font-size:12px;color:var(--muted);line-height:1.6}

/* ── TABLE ── */
.tbl{width:100%;border-collapse:collapse;font-size:12.5px;margin:10px 0;background:var(--surface);border-radius:var(--radius);overflow:hidden;border:1px solid var(--border);box-shadow:var(--shadow)}
.tbl thead th{background:#F8FAFC;color:var(--muted);font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px;padding:10px 14px;border-bottom:1.5px solid var(--border);text-align:left;white-space:nowrap}
.tbl tbody td{padding:10px 14px;border-bottom:1px solid #F1F5F9;color:var(--text);vertical-align:middle}
.tbl tbody tr:last-child td{border-bottom:none}
.tbl tbody tr:hover td{background:#FAFBFF}
.chk-y{color:var(--green);font-weight:800;font-size:14px}
.chk-n{color:var(--muted2);font-size:14px}

/* ── FLOW ── */
.flow{display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin:10px 0}
.flow-pill{
  padding:5px 12px;border-radius:20px;
  font-size:11.5px;font-weight:700
}
.fp-blue{background:var(--blue-lt);border:1px solid var(--blue-border);color:#1E40AF}
.fp-green{background:var(--green-lt);border:1px solid #86EFAC;color:#166534}
.fp-orange{background:var(--orange-lt);border:1px solid #FDE68A;color:#92400E}
.fp-gray{background:#F1F5F9;border:1px solid var(--border);color:var(--muted)}
.flow-arr{color:var(--muted2);font-size:12px;font-weight:900}

/* ── ROLE BADGE ── */
.rb{display:inline-flex;align-items:center;gap:4px;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:700}
.rb-sa{background:#FEF3C7;color:#92400E;border:1px solid #FDE68A}
.rb-hr{background:var(--green-lt);border:1px solid #86EFAC;color:#166534}
.rb-rc{background:var(--purple-lt);border:1px solid #C4B5FD;color:#5B21B6}

/* ── FAQ ── */
.faq{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:8px;overflow:hidden;box-shadow:var(--shadow)}
.faq-q{
  padding:13px 16px;font-size:13px;font-weight:700;color:var(--text);
  cursor:pointer;display:flex;justify-content:space-between;align-items:center;
  gap:12px;user-select:none
}
.faq-q i{color:var(--muted2);font-size:11px;transition:transform .2s;flex-shrink:0}
.faq.open .faq-q i{transform:rotate(180deg)}
.faq-a{display:none;padding:0 16px 14px;font-size:12.5px;color:var(--muted);line-height:1.75;border-top:1px solid #F1F5F9}
.faq.open .faq-a{display:block}

/* ── WORKFLOW CARD ── */
.wf-card{
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius);padding:20px;margin-bottom:14px;
  box-shadow:var(--shadow)
}
.wf-title{
  font-size:13px;font-weight:800;color:var(--text);
  margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:8px
}
.wf-title i{color:var(--blue)}
.wf-steps{counter-reset:wf;display:flex;flex-direction:column;gap:6px}
.wf-step{display:flex;gap:10px;font-size:12.5px;color:var(--muted);line-height:1.6}
.wf-step::before{counter-increment:wf;content:counter(wf);width:20px;height:20px;border-radius:50%;background:var(--bg);border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:var(--blue);flex-shrink:0;margin-top:1px}

/* ── KBD ── */
kbd{background:#F1F5F9;border:1px solid #CBD5E1;border-radius:4px;padding:1px 6px;font-size:11px;font-family:monospace;color:var(--muted)}

/* ── PRINT ── */
@media print{
  .tg-nav,.navbar,.tg-hero-tags{display:none!important}
  .tg-root{height:auto;overflow:visible}
  .tg-body{overflow:visible}
  .tg-sec{page-break-inside:avoid}
  .tg-page{padding:0;max-width:100%}
}

/* ── RESPONSIVE ── */
@media(max-width:768px){
  .tg-nav{display:none}
  .tg-page{padding:24px 18px 48px}
  .tg-hero{padding:28px 18px 24px}
  .tg-hero h1{font-size:22px}
}
</style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>

<div class="tg-root">

<!-- ══ SIDEBAR ══════════════════════════════════════════════════════════════ -->
<nav class="tg-nav">
  <div class="tg-nav-top">
    <div class="tg-nav-title"><i class="fa-solid fa-book-open" style="color:var(--blue);margin-right:6px"></i>User Guide</div>
    <div class="tg-nav-sub">HireAI — Avyukta Intellicall</div>
  </div>

  <div class="tg-nav-body">
    <div class="tg-nav-group">
      <div class="tg-nav-label">Getting Started</div>
      <button class="tg-nav-item active" onclick="navTo('overview',this)"><i class="fa-solid fa-map"></i> Platform Overview</button>
      <button class="tg-nav-item" onclick="navTo('quickstart',this)"><i class="fa-solid fa-bolt"></i> Quick Start</button>
      <button class="tg-nav-item" onclick="navTo('roles',this)"><i class="fa-solid fa-users"></i> User Roles</button>
    </div>
    <div class="tg-nav-group">
      <div class="tg-nav-label">Core Features</div>
      <button class="tg-nav-item" onclick="navTo('campaigns',this)"><i class="fa-solid fa-rocket"></i> Campaigns</button>
      <button class="tg-nav-item" onclick="navTo('ai_builder',this)"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Builder</button>
      <button class="tg-nav-item" onclick="navTo('apply_form',this)"><i class="fa-solid fa-clipboard-list"></i> Apply Form</button>
      <button class="tg-nav-item" onclick="navTo('candidates',this)"><i class="fa-solid fa-user"></i> Candidates</button>
      <button class="tg-nav-item" onclick="navTo('outreach',this)"><i class="fa-solid fa-paper-plane"></i> Outreach</button>
    </div>
    <div class="tg-nav-group">
      <div class="tg-nav-label">Interview</div>
      <button class="tg-nav-item" onclick="navTo('interview_flow',this)"><i class="fa-solid fa-microphone"></i> Interview Flow</button>
      <button class="tg-nav-item" onclick="navTo('face_detection',this)"><i class="fa-solid fa-camera"></i> Face Detection</button>
      <button class="tg-nav-item" onclick="navTo('results',this)"><i class="fa-solid fa-chart-bar"></i> Reviewing Results</button>
      <button class="tg-nav-item" onclick="navTo('scoring',this)"><i class="fa-solid fa-brain"></i> AI Scoring</button>
    </div>
    <div class="tg-nav-group">
      <div class="tg-nav-label">Admin</div>
      <button class="tg-nav-item" onclick="navTo('analytics',this)"><i class="fa-solid fa-chart-line"></i> Analytics</button>
      <button class="tg-nav-item" onclick="navTo('users',this)"><i class="fa-solid fa-user-shield"></i> User Management</button>
      <button class="tg-nav-item" onclick="navTo('credits',this)"><i class="fa-solid fa-coins"></i> Credits</button>
    </div>
    <div class="tg-nav-group">
      <div class="tg-nav-label">Reference</div>
      <button class="tg-nav-item" onclick="navTo('workflows',this)"><i class="fa-solid fa-repeat"></i> Workflows</button>
      <button class="tg-nav-item" onclick="navTo('faq',this)"><i class="fa-solid fa-circle-question"></i> FAQ</button>
    </div>
  </div>

  <div class="tg-nav-footer">
    <button class="tg-print-btn" onclick="window.print()">
      <i class="fa-solid fa-print"></i> Print / Save as PDF
    </button>
  </div>
</nav>

<!-- ══ SCROLLABLE BODY ════════════════════════════════════════════════════ -->
<div class="tg-body" id="tgBody">

  <!-- HERO -->
  <div class="tg-hero">
    <div class="tg-hero-eyebrow"><i class="fa-solid fa-book-open"></i> Team Training Guide</div>
    <h1>HireAI — Complete User Guide</h1>
    <p>Everything your team needs to run AI-powered hiring on Avyukta Intellicall. Click any section in the sidebar to jump directly to it.</p>
    <div class="tg-hero-tags">
      <span class="tg-hero-tag"><i class="fa-solid fa-rocket"></i> Campaign Management</span>
      <span class="tg-hero-tag"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Builder</span>
      <span class="tg-hero-tag"><i class="fa-solid fa-microphone"></i> AI Interviews</span>
      <span class="tg-hero-tag"><i class="fa-solid fa-brain"></i> Auto Scoring</span>
      <span class="tg-hero-tag"><i class="fa-brands fa-whatsapp"></i> WhatsApp Outreach</span>
    </div>
  </div>

  <div class="tg-page">

  <!-- ══ OVERVIEW ══════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="overview">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#EFF6FF;color:#2563EB"><i class="fa-solid fa-map"></i></div>
      <div>
        <div class="tg-sec-title">Platform Overview</div>
        <div class="tg-sec-sub">What is HireAI and how does it work</div>
      </div>
    </div>

    <div class="card">
      <div class="card-title"><i class="fa-solid fa-lightbulb"></i> What is HireAI?</div>
      <div class="card-body">HireAI is an AI-powered hiring platform that automates initial screening. You create a campaign for a job role, share an apply link with candidates, they fill out an application and complete an AI-conducted interview — all without any HR being present. AI automatically scores every answer and delivers ranked results.</div>
    </div>

    <div class="g3" style="margin-top:4px">
      <div class="tile">
        <div class="tile-icon" style="background:#EFF6FF;color:#2563EB"><i class="fa-solid fa-rocket"></i></div>
        <div class="tile-title">Campaigns</div>
        <div class="tile-desc">Create job openings with custom questions and an apply form. Share one link to start receiving applicants.</div>
      </div>
      <div class="tile">
        <div class="tile-icon" style="background:#F5F3FF;color:#7C3AED"><i class="fa-solid fa-microphone"></i></div>
        <div class="tile-title">AI Interview</div>
        <div class="tile-desc">Candidates answer by voice or text. Face detection ensures they're present throughout the test.</div>
      </div>
      <div class="tile">
        <div class="tile-icon" style="background:#ECFDF5;color:#059669"><i class="fa-solid fa-brain"></i></div>
        <div class="tile-title">Auto Scoring</div>
        <div class="tile-desc">AI evaluates every answer against your ideal criteria. Score out of 100 delivered within minutes.</div>
      </div>
      <div class="tile">
        <div class="tile-icon" style="background:#ECFDF5;color:#16A34A"><i class="fa-brands fa-whatsapp"></i></div>
        <div class="tile-title">WhatsApp Outreach</div>
        <div class="tile-desc">Send personalised interview links via WhatsApp — individually or in bulk with one click.</div>
      </div>
      <div class="tile">
        <div class="tile-icon" style="background:#EFF6FF;color:#0891B2"><i class="fa-solid fa-chart-line"></i></div>
        <div class="tile-title">Analytics</div>
        <div class="tile-desc">Funnel stats, score distribution, completion rates, and AI insights across all campaigns.</div>
      </div>
      <div class="tile">
        <div class="tile-icon" style="background:#FEF9C3;color:#D97706"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
        <div class="tile-title">AI Builder</div>
        <div class="tile-desc">Paste a JD — AI creates the full campaign, questions, and apply form in under 30 seconds.</div>
      </div>
    </div>

    <div class="info info-blue" style="margin-top:14px">
      <i class="fa-solid fa-circle-info"></i>
      <div><strong>Platform URL:</strong> hire.clouddialer.in — This is the admin panel. Candidates never log in here; they only visit the apply form and interview links you share with them.</div>
    </div>
  </div>

  <!-- ══ QUICK START ════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="quickstart">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#ECFDF5;color:#059669"><i class="fa-solid fa-bolt"></i></div>
      <div>
        <div class="tg-sec-title">Quick Start</div>
        <div class="tg-sec-sub">Get your first campaign live in under 10 minutes</div>
      </div>
    </div>
    <div class="steps">
      <div class="step">
        <div class="step-n">1</div>
        <div class="step-body">
          <div class="step-title">Create a Campaign</div>
          <div class="step-desc">Go to <strong>Campaigns → + New Campaign</strong>. Enter the job role name, description, and passing score. Or use the AI Builder for a faster setup.</div>
          <div class="step-note note-tip"><i class="fa-solid fa-lightbulb"></i> Have a JD? Use AI Builder — it creates everything automatically in 30 seconds.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">2</div>
        <div class="step-body">
          <div class="step-title">Add Interview Questions</div>
          <div class="step-desc">In the campaign's <strong>Questions</strong> tab, click <strong>+ Add Question</strong>. Mix voice, text, and MCQ types. All weights must sum to 100.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">3</div>
        <div class="step-body">
          <div class="step-title">Configure Apply Form</div>
          <div class="step-desc">In the <strong>Apply Form</strong> tab, toggle which standard fields to show (name, phone, DOB, city, experience, etc.).</div>
          <div class="step-note note-warn"><i class="fa-solid fa-triangle-exclamation"></i> This step is optional — the form already works with all default fields enabled.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">4</div>
        <div class="step-body">
          <div class="step-title">Activate &amp; Share</div>
          <div class="step-desc">Click <strong>Activate Campaign</strong>. Then use the <strong>Share Link</strong> or <strong>WhatsApp</strong> button to send the apply link to candidates.</div>
          <div class="step-note note-tip"><i class="fa-solid fa-link"></i> Apply link format: hire.clouddialer.in/apply.php?c=XXXXXX</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">5</div>
        <div class="step-body">
          <div class="step-title">Review Results</div>
          <div class="step-desc">Go to <strong>Candidates</strong>, filter by <strong>Completed</strong>, and review AI scores. Click any candidate to see Q&amp;A, recording, and analysis.</div>
          <div class="step-note note-tip"><i class="fa-solid fa-clock"></i> AI scores appear within 2–3 minutes of interview completion.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ ROLES ══════════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="roles">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#F5F3FF;color:#7C3AED"><i class="fa-solid fa-users"></i></div>
      <div>
        <div class="tg-sec-title">User Roles &amp; Permissions</div>
        <div class="tg-sec-sub">Who can do what on the platform</div>
      </div>
    </div>
    <table class="tbl">
      <thead>
        <tr>
          <th>Feature</th>
          <th><span class="rb rb-sa">Super Admin</span></th>
          <th><span class="rb rb-hr">Admin / HR</span></th>
          <th><span class="rb rb-rc">Recruiter</span></th>
        </tr>
      </thead>
      <tbody>
        <tr><td>Create / Edit Campaigns</td><td class="chk-y">✓</td><td class="chk-y">✓</td><td class="chk-n">—</td></tr>
        <tr><td>Activate / Deactivate Campaigns</td><td class="chk-y">✓</td><td class="chk-y">✓</td><td class="chk-n">—</td></tr>
        <tr><td>Use AI Builder</td><td class="chk-y">✓</td><td class="chk-y">✓</td><td class="chk-n">—</td></tr>
        <tr><td>View Campaigns</td><td class="chk-y">✓</td><td class="chk-y">✓</td><td class="chk-y">✓</td></tr>
        <tr><td>Add / Import Candidates</td><td class="chk-y">✓</td><td class="chk-y">✓</td><td class="chk-y">✓</td></tr>
        <tr><td>Send Outreach (WhatsApp / Calls)</td><td class="chk-y">✓</td><td class="chk-y">✓</td><td class="chk-y">✓</td></tr>
        <tr><td>View Results &amp; Scores</td><td class="chk-y">✓</td><td class="chk-y">✓</td><td class="chk-y">✓</td></tr>
        <tr><td>Shortlist / Reject / Override Scores</td><td class="chk-y">✓</td><td class="chk-y">✓</td><td class="chk-y">✓</td></tr>
        <tr><td>View Analytics</td><td class="chk-y">✓</td><td class="chk-y">✓</td><td class="chk-y">✓</td></tr>
        <tr><td>Manage Users (create, deactivate)</td><td class="chk-y">✓</td><td class="chk-n">—</td><td class="chk-n">—</td></tr>
        <tr><td>View Audit Logs</td><td class="chk-y">✓</td><td class="chk-n">—</td><td class="chk-n">—</td></tr>
      </tbody>
    </table>
    <div class="info info-blue">
      <i class="fa-solid fa-circle-info"></i>
      <div>Your role is shown in the top-right corner. Contact your Super Admin to change roles.</div>
    </div>
  </div>

  <!-- ══ CAMPAIGNS ══════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="campaigns">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#EFF6FF;color:#2563EB"><i class="fa-solid fa-rocket"></i></div>
      <div>
        <div class="tg-sec-title">Campaign Management</div>
        <div class="tg-sec-sub">Creating and managing job interview campaigns</div>
      </div>
    </div>
    <div class="card">
      <div class="card-title"><i class="fa-solid fa-arrow-right-arrow-left"></i> Campaign Status Flow</div>
      <div class="card-body">
        <div class="flow">
          <span class="flow-pill fp-gray">Draft</span>
          <span class="flow-arr">→</span>
          <span class="flow-pill fp-green">Active</span>
          <span class="flow-arr">↔</span>
          <span class="flow-pill fp-orange">Paused</span>
        </div>
        <div style="margin-top:10px;display:flex;flex-direction:column;gap:6px;font-size:12.5px;color:#475569">
          <div><strong style="color:var(--text)">Draft</strong> — Being set up. Apply link is not live yet.</div>
          <div><strong style="color:var(--text)">Active</strong> — Apply link is live. Candidates can apply and take interviews.</div>
          <div><strong style="color:var(--text)">Paused</strong> — Apply link disabled. All candidate data is preserved. Re-activate anytime.</div>
        </div>
      </div>
    </div>
    <div class="steps" style="margin-top:4px">
      <div class="step">
        <div class="step-n">1</div>
        <div class="step-body">
          <div class="step-title">Create a Campaign</div>
          <div class="step-desc">Go to <strong>Campaigns → + New Campaign</strong>. Fill in: Campaign Name, Job Role, Description, Language, Passing Score, Max Duration, Number of Questions.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">2</div>
        <div class="step-body">
          <div class="step-title">Add Questions</div>
          <div class="step-desc">Go to the <strong>Questions</strong> tab → <strong>+ Add Question</strong>. Choose type: Voice Note, Short Answer, or MCQ. Set the weight (importance %) and write the question.</div>
          <div class="step-note note-warn"><i class="fa-solid fa-triangle-exclamation"></i> All question weights must add up to exactly 100 before you can activate.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">3</div>
        <div class="step-body">
          <div class="step-title">Activate</div>
          <div class="step-desc">Once campaign details and questions are set, click <strong>Activate</strong>. The apply link goes live instantly.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">4</div>
        <div class="step-body">
          <div class="step-title">Share the Link</div>
          <div class="step-desc">Use <strong>Share Link</strong> to copy the URL, or <strong>WhatsApp</strong> to open a pre-filled message. You can also send via the Outreach page for bulk sending.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">5</div>
        <div class="step-body">
          <div class="step-title">Deactivate When Done</div>
          <div class="step-desc">Click <strong>Deactivate</strong> on an active campaign when the hiring drive ends. The apply link stops working but all data is preserved. Re-activate for the next batch.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ AI BUILDER ════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="ai_builder">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#FEF9C3;color:#D97706"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
      <div>
        <div class="tg-sec-title">AI Campaign Builder</div>
        <div class="tg-sec-sub">Create a complete campaign from a Job Description in 30 seconds</div>
      </div>
    </div>
    <div class="info info-green">
      <i class="fa-solid fa-circle-check"></i>
      <div><strong>Fastest way to create a campaign.</strong> Paste your JD and Avyukta AI generates the campaign name, 10 questions with scoring criteria, and apply form fields automatically.</div>
    </div>
    <div class="steps" style="margin-top:4px">
      <div class="step">
        <div class="step-n">1</div>
        <div class="step-body">
          <div class="step-title">Open AI Builder</div>
          <div class="step-desc">Go to <strong>Campaigns</strong> and click the <strong>AI Builder</strong> button (top right), or navigate to <strong>/jd_builder</strong>.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">2</div>
        <div class="step-body">
          <div class="step-title">Paste the Job Description</div>
          <div class="step-desc">Paste the full JD text (30–15,000 characters). The more detailed it is, the better the questions. Click <strong>Generate Campaign with Avyukta AI</strong>.</div>
          <div class="step-note note-tip"><i class="fa-solid fa-clock"></i> Takes 5–10 seconds. Do not close the page during generation.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">3</div>
        <div class="step-body">
          <div class="step-title">Review &amp; Edit</div>
          <div class="step-desc">A preview shows the generated campaign: name, 10 questions (4 MCQ + 3 text + 3 voice), and custom fields. Edit any text inline before saving.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">4</div>
        <div class="step-body">
          <div class="step-title">Save Campaign</div>
          <div class="step-desc">Click <strong>Create Campaign</strong>. The campaign is saved in Draft status with all questions and a default apply form. Activate it when ready.</div>
        </div>
      </div>
    </div>
    <div class="card" style="margin-top:12px">
      <div class="card-title"><i class="fa-solid fa-list-check"></i> What AI generates automatically</div>
      <div class="card-body">
        <table class="tbl" style="margin:0">
          <thead><tr><th>Output</th><th>Details</th></tr></thead>
          <tbody>
            <tr><td>Campaign Name</td><td>"Role Name at Company Name" format, max 60 chars</td></tr>
            <tr><td>10 Questions</td><td>Q1–4: MCQ with 4 options &amp; correct answer · Q5–7: Short text · Q8–10: Voice note</td></tr>
            <tr><td>Question Weights</td><td>Automatically sum to exactly 100</td></tr>
            <tr><td>AI Scoring Hints</td><td>What the ideal answer should be for each question</td></tr>
            <tr><td>Custom Apply Fields</td><td>2–5 role-specific fields (e.g. GitHub URL, driving licence)</td></tr>
            <tr><td>Passing Score</td><td>60–75 based on seniority level in the JD</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ══ APPLY FORM ════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="apply_form">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#ECFDF5;color:#059669"><i class="fa-solid fa-clipboard-list"></i></div>
      <div>
        <div class="tg-sec-title">Application Form Configuration</div>
        <div class="tg-sec-sub">Controlling what candidates fill in before the interview</div>
      </div>
    </div>
    <div class="g2" style="margin-bottom:12px">
      <div class="card" style="margin-bottom:0">
        <div class="card-title"><i class="fa-solid fa-toggle-on"></i> Standard Fields</div>
        <div class="card-body">Built-in fields: name, phone, email, DOB, city, experience, salary, education, etc. Controlled by ON/OFF toggles in the Apply Form tab. All are ON by default.</div>
      </div>
      <div class="card" style="margin-bottom:0">
        <div class="card-title"><i class="fa-solid fa-plus"></i> Custom Fields</div>
        <div class="card-body">Role-specific fields added manually or auto-generated by AI Builder (e.g. GitHub URL, notice period, driving licence). Stored separately and shown after standard fields.</div>
      </div>
    </div>
    <div class="steps">
      <div class="step">
        <div class="step-n">1</div>
        <div class="step-body">
          <div class="step-title">Open Apply Form Settings</div>
          <div class="step-desc">Go to a campaign → click <strong>Apply Form</strong>. You'll see all 40 standard fields in 9 sections: Personal, Contact, Education, Experience, Compensation, Availability, Work Readiness, Documents, Consent.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">2</div>
        <div class="step-body">
          <div class="step-title">Toggle Fields ON / OFF</div>
          <div class="step-desc">Check = shown to candidates. Uncheck = hidden. Turn off fields that aren't relevant to the role to keep the form short and focused.</div>
          <div class="step-note note-tip"><i class="fa-solid fa-lightbulb"></i> For tech roles: turn off commute/laptop questions. For freshers: turn off salary fields.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">3</div>
        <div class="step-body">
          <div class="step-title">Save Configuration</div>
          <div class="step-desc">Click <strong>Save Standard Fields Config</strong>. Changes take effect immediately for new applicants.</div>
          <div class="step-note note-warn"><i class="fa-solid fa-triangle-exclamation"></i> Keep Phone and Email enabled — they're used for duplicate detection.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ CANDIDATES ════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="candidates">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#FEF9C3;color:#D97706"><i class="fa-solid fa-user"></i></div>
      <div>
        <div class="tg-sec-title">Candidate Management</div>
        <div class="tg-sec-sub">Adding, importing, and tracking candidates</div>
      </div>
    </div>
    <div class="card">
      <div class="card-title"><i class="fa-solid fa-arrow-right-arrow-left"></i> Candidate Status Flow</div>
      <div class="card-body">
        <div class="flow">
          <span class="flow-pill fp-gray">Pending</span>
          <span class="flow-arr">→</span>
          <span class="flow-pill fp-blue">Outreach Sent</span>
          <span class="flow-arr">→</span>
          <span class="flow-pill fp-blue">Interview Started</span>
          <span class="flow-arr">→</span>
          <span class="flow-pill fp-green">Completed</span>
        </div>
        <div class="flow" style="margin-top:8px">
          <span class="flow-pill fp-green">Shortlisted</span>
          <span class="flow-arr">↔</span>
          <span class="flow-pill" style="background:#FFF1F2;border:1px solid #FECDD3;color:#9F1239">Rejected</span>
          <span class="flow-arr">↔</span>
          <span class="flow-pill fp-gray">On Hold</span>
        </div>
      </div>
    </div>
    <div class="steps" style="margin-top:4px">
      <div class="step">
        <div class="step-n">1</div>
        <div class="step-body">
          <div class="step-title">Add a Single Candidate</div>
          <div class="step-desc">Go to <strong>Candidates → + Add Candidate</strong>. Fill in Name, Phone, Email, and select the Campaign. The candidate gets a unique interview link.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">2</div>
        <div class="step-body">
          <div class="step-title">Bulk Import via CSV</div>
          <div class="step-desc">Click <strong>+ Add → Bulk CSV</strong> tab. Upload a file or paste rows. Format: <kbd>Name, Phone, Email</kbd> per line. Duplicates (same phone in same campaign) are automatically skipped.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">3</div>
        <div class="step-body">
          <div class="step-title">Filter &amp; Search</div>
          <div class="step-desc">Use the <strong>Campaign filter</strong>, <strong>Status pills</strong>, and the <strong>Search box</strong> to find candidates. Use <strong>Columns ▾</strong> to show/hide table columns — saved in your browser.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">4</div>
        <div class="step-body">
          <div class="step-title">Export to CSV</div>
          <div class="step-desc">Click <strong>Export CSV</strong> to download all filtered candidates with scores, contact details, and status — ready for Excel or Google Sheets.</div>
        </div>
      </div>
    </div>
    <div class="info info-orange">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <div><strong>Terminated Candidates:</strong> A red <strong>🚫 Terminated</strong> badge appears next to their status if the interview was ended due to face detection failure. Click the candidate to see the full integrity report.</div>
    </div>
  </div>

  <!-- ══ OUTREACH ═══════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="outreach">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#ECFDF5;color:#16A34A"><i class="fa-solid fa-paper-plane"></i></div>
      <div>
        <div class="tg-sec-title">Outreach — Sending Interview Invites</div>
        <div class="tg-sec-sub">WhatsApp messages and AI calls to candidates</div>
      </div>
    </div>
    <div class="g2" style="margin-bottom:12px">
      <div class="card" style="margin-bottom:0">
        <div class="card-title"><i class="fa-brands fa-whatsapp" style="color:#16A34A"></i> WhatsApp</div>
        <div class="card-body">Send the interview link to candidates via WhatsApp. Each candidate gets their own unique personal link. Send individually or in bulk.</div>
      </div>
      <div class="card" style="margin-bottom:0">
        <div class="card-title"><i class="fa-solid fa-phone" style="color:#7C3AED"></i> AI Voice Call</div>
        <div class="card-body">Trigger an AI phone call. The Avya AI agent explains the opportunity and guides the candidate to take the interview.</div>
      </div>
    </div>
    <div class="steps">
      <div class="step">
        <div class="step-n">1</div>
        <div class="step-body">
          <div class="step-title">Send to a Single Candidate</div>
          <div class="step-desc">From the Candidates list, click the WhatsApp icon on any row. The candidate's unique link is included automatically.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">2</div>
        <div class="step-body">
          <div class="step-title">Bulk WhatsApp Send</div>
          <div class="step-desc">Go to <strong>Outreach</strong> → filter by campaign → select candidates using checkboxes → click <strong>Bulk Send WhatsApp</strong>. Status updates to "Outreach Sent" after sending.</div>
        </div>
      </div>
    </div>
    <div class="info info-orange">
      <i class="fa-solid fa-coins"></i>
      <div><strong>Credits required:</strong> Each WhatsApp message or AI call uses credits. Check your balance in <strong>Credits</strong> before running large outreach campaigns.</div>
    </div>
  </div>

  <!-- ══ INTERVIEW FLOW ══════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="interview_flow">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#EFF6FF;color:#2563EB"><i class="fa-solid fa-microphone"></i></div>
      <div>
        <div class="tg-sec-title">Interview Flow — Candidate Experience</div>
        <div class="tg-sec-sub">What a candidate sees and does during the interview</div>
      </div>
    </div>
    <div class="info info-blue">
      <i class="fa-solid fa-circle-info"></i>
      <div>The interview runs entirely in the browser — no app download needed. Works on mobile and desktop. Candidates click their unique link to begin.</div>
    </div>
    <div class="steps" style="margin-top:4px">
      <div class="step">
        <div class="step-n">1</div>
        <div class="step-body">
          <div class="step-title">Permission Screen</div>
          <div class="step-desc">Candidate opens their interview link. They must allow Camera and Microphone, read the instructions and disclaimer, tick the consent checkbox, then click <strong>Start Interview</strong>.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">2</div>
        <div class="step-body">
          <div class="step-title">Face Verification</div>
          <div class="step-desc">A face gate screen appears. AI verifies the candidate's face is visible. The Start button activates only once confirmed. Prevents proxies and ensures the right person takes the test.</div>
          <div class="step-note note-warn"><i class="fa-solid fa-triangle-exclamation"></i> Candidate must be in good lighting facing the camera. Dark rooms will fail verification.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">3</div>
        <div class="step-body">
          <div class="step-title">Answering Questions</div>
          <div class="step-desc">Questions appear one by one with a 3-minute timer. <strong>Voice:</strong> tap mic to record. <strong>Text:</strong> type the answer. <strong>MCQ:</strong> select A/B/C/D. Click <strong>Next Question →</strong> when done.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">4</div>
        <div class="step-body">
          <div class="step-title">Completion</div>
          <div class="step-desc">A completion screen is shown after the last question. AI scoring begins automatically in the background. Candidate can close the tab.</div>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-title"><i class="fa-solid fa-bullhorn"></i> Brief candidates before they start</div>
      <div class="card-body">
        <div class="g2" style="gap:8px;margin-top:4px">
          <div style="font-size:12.5px;color:#475569;display:flex;flex-direction:column;gap:6px">
            <div><i class="fa-solid fa-volume-xmark" style="color:var(--muted);width:16px"></i> Phone on <strong>Silent Mode</strong></div>
            <div><i class="fa-solid fa-wifi" style="color:var(--muted);width:16px"></i> Use stable <strong>Wi-Fi</strong></div>
            <div><i class="fa-solid fa-phone-slash" style="color:var(--muted);width:16px"></i> No incoming calls during test</div>
          </div>
          <div style="font-size:12.5px;color:#475569;display:flex;flex-direction:column;gap:6px">
            <div><i class="fa-solid fa-laptop" style="color:var(--muted);width:16px"></i> Best on <strong>laptop or desktop</strong></div>
            <div><i class="fa-solid fa-sun" style="color:var(--muted);width:16px"></i> Well-lit room, face the camera</div>
            <div><i class="fa-solid fa-lock" style="color:var(--muted);width:16px"></i> One attempt only — cannot restart</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ FACE DETECTION ══════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="face_detection">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#FFF1F2;color:#DC2626"><i class="fa-solid fa-camera"></i></div>
      <div>
        <div class="tg-sec-title">Face Detection &amp; Integrity</div>
        <div class="tg-sec-sub">How cheating prevention works during interviews</div>
      </div>
    </div>
    <div class="g2">
      <div class="card" style="margin-bottom:0">
        <div class="card-title"><i class="fa-solid fa-eye"></i> What is checked</div>
        <div class="card-body" style="font-size:12.5px">
          <div style="display:flex;flex-direction:column;gap:6px">
            <div><i class="fa-solid fa-circle" style="color:var(--blue);font-size:7px;margin-right:6px"></i>Face in camera — checked after every question</div>
            <div><i class="fa-solid fa-circle" style="color:var(--blue);font-size:7px;margin-right:6px"></i>Tab / window switches</div>
            <div><i class="fa-solid fa-circle" style="color:var(--blue);font-size:7px;margin-right:6px"></i>Copy-paste of text over 20 characters</div>
            <div><i class="fa-solid fa-circle" style="color:var(--blue);font-size:7px;margin-right:6px"></i>Camera disconnection</div>
          </div>
        </div>
      </div>
      <div class="card" style="margin-bottom:0">
        <div class="card-title" style="color:var(--red)"><i class="fa-solid fa-ban"></i> Termination triggers</div>
        <div class="card-body" style="font-size:12.5px">
          <div style="display:flex;flex-direction:column;gap:6px">
            <div><i class="fa-solid fa-circle" style="color:var(--red);font-size:7px;margin-right:6px"></i>Face not detected on 2 consecutive checks</div>
            <div><i class="fa-solid fa-circle" style="color:var(--red);font-size:7px;margin-right:6px"></i>Camera blocked or covered mid-interview</div>
            <div><i class="fa-solid fa-circle" style="color:var(--red);font-size:7px;margin-right:6px"></i>Camera device disconnected</div>
            <div><i class="fa-solid fa-circle" style="color:var(--red);font-size:7px;margin-right:6px"></i>Very dark room — face not visible</div>
          </div>
        </div>
      </div>
    </div>
    <div class="card" style="margin-top:4px">
      <div class="card-title"><i class="fa-solid fa-magnifying-glass"></i> Where to see results in admin</div>
      <div class="card-body" style="font-size:12.5px;display:flex;flex-direction:column;gap:7px">
        <div><strong>Candidates list:</strong> Red <strong>🚫 Terminated</strong> badge next to status</div>
        <div><strong>Candidate detail page:</strong> Red banner at top + Integrity section = Critical Risk</div>
        <div><strong>Integrity report:</strong> Face-away count, tab switches, copy-paste violations</div>
      </div>
    </div>
    <div class="info info-orange">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <div>Tab switches and copy-paste events are logged <strong>silently</strong> — candidates don't see warnings for these (except paste which shows a popup). Only face failures show visible warnings.</div>
    </div>
  </div>

  <!-- ══ RESULTS ═════════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="results">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#ECFDF5;color:#059669"><i class="fa-solid fa-chart-bar"></i></div>
      <div>
        <div class="tg-sec-title">Reviewing Interview Results</div>
        <div class="tg-sec-sub">How to evaluate candidates after the interview</div>
      </div>
    </div>
    <div class="steps">
      <div class="step">
        <div class="step-n">1</div>
        <div class="step-body">
          <div class="step-title">Open Candidate Detail</div>
          <div class="step-desc">From <strong>Candidates</strong> list, click <strong>View</strong> on any completed candidate. See the score ring, pass/fail badge, and three tabs: <strong>Q&amp;A</strong>, <strong>Recording</strong>, <strong>AI Call</strong>.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">2</div>
        <div class="step-body">
          <div class="step-title">Q&amp;A Tab — Review All Answers</div>
          <div class="step-desc">Every question shows: AI score, the candidate's answer (text or audio player for voice), and AI reasoning. Sort by Question # or Score High/Low using the sort bar.</div>
          <div class="step-note note-tip"><i class="fa-solid fa-lightbulb"></i> Click "Analyze with AI" on voice answers without a transcript — it transcribes and rescores that answer.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">3</div>
        <div class="step-body">
          <div class="step-title">Recording Tab — Watch the Interview</div>
          <div class="step-desc">Watch the full webcam recording of the candidate's session. Useful for verifying identity and reviewing body language.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">4</div>
        <div class="step-body">
          <div class="step-title">Override a Score</div>
          <div class="step-desc">Next to each AI score is an override input. Type a custom score if you disagree with the AI assessment — the total updates automatically.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">5</div>
        <div class="step-body">
          <div class="step-title">Change Status &amp; Add Notes</div>
          <div class="step-desc">Use <strong>Change Status</strong> to move the candidate to Shortlisted, Rejected, or On Hold. Add recruiter notes in the Notes section for team visibility.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ AI SCORING ══════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="scoring">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#F5F3FF;color:#7C3AED"><i class="fa-solid fa-brain"></i></div>
      <div>
        <div class="tg-sec-title">AI Scoring Pipeline</div>
        <div class="tg-sec-sub">How AI evaluates candidate answers</div>
      </div>
    </div>
    <div class="card">
      <div class="card-title"><i class="fa-solid fa-gears"></i> How it works</div>
      <div class="card-body">
        <div style="display:flex;flex-direction:column;gap:8px;font-size:12.5px;color:#475569">
          <div style="display:flex;gap:10px;align-items:flex-start"><span style="background:var(--blue);color:#fff;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900;flex-shrink:0;margin-top:1px">1</span><div>Interview completes → AI scoring starts automatically in background</div></div>
          <div style="display:flex;gap:10px;align-items:flex-start"><span style="background:var(--blue);color:#fff;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900;flex-shrink:0;margin-top:1px">2</span><div>Voice answers are <strong>transcribed to text</strong> using Gemini AI</div></div>
          <div style="display:flex;gap:10px;align-items:flex-start"><span style="background:var(--blue);color:#fff;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900;flex-shrink:0;margin-top:1px">3</span><div>Each answer is evaluated against the <strong>ideal answer hint</strong> you set per question</div></div>
          <div style="display:flex;gap:10px;align-items:flex-start"><span style="background:var(--blue);color:#fff;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900;flex-shrink:0;margin-top:1px">4</span><div>Score × weight per question → <strong>total score out of 100</strong></div></div>
          <div style="display:flex;gap:10px;align-items:flex-start"><span style="background:var(--blue);color:#fff;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900;flex-shrink:0;margin-top:1px">5</span><div>Compared to passing score → <strong>Shortlisted</strong> or <strong>Rejected</strong>. If AI fails → <strong>On Hold</strong> (auto-rescored at 2am)</div></div>
        </div>
      </div>
    </div>
    <div class="info info-blue" style="margin-top:4px">
      <i class="fa-solid fa-lightbulb"></i>
      <div><strong>Tip:</strong> Add specific AI Scoring Hints when creating questions. Example: <em>"Ideal answer: Candidate should use the STAR method, mention measurable results, and show ownership."</em> The more specific the hint, the more accurate the AI score.</div>
    </div>
  </div>

  <!-- ══ ANALYTICS ═══════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="analytics">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#EFF6FF;color:#0891B2"><i class="fa-solid fa-chart-line"></i></div>
      <div>
        <div class="tg-sec-title">Analytics Dashboard</div>
        <div class="tg-sec-sub">Understanding your hiring funnel and performance</div>
      </div>
    </div>
    <div class="g2">
      <div class="tile"><div class="tile-icon" style="background:#EFF6FF;color:#2563EB"><i class="fa-solid fa-bullseye"></i></div><div class="tile-title">KPI Cards</div><div class="tile-desc">Total candidates, average score, completion rate, selection rate — at a glance.</div></div>
      <div class="tile"><div class="tile-icon" style="background:#F5F3FF;color:#7C3AED"><i class="fa-solid fa-filter"></i></div><div class="tile-title">Hiring Funnel</div><div class="tile-desc">Drop-off at each stage: Imported → Invited → Started → Completed → Shortlisted with conversion %.</div></div>
      <div class="tile"><div class="tile-icon" style="background:#ECFDF5;color:#059669"><i class="fa-solid fa-chart-bar"></i></div><div class="tile-title">Score Distribution</div><div class="tile-desc">Bar chart showing how candidates scored across 5 buckets from 0–100.</div></div>
      <div class="tile"><div class="tile-icon" style="background:#FFF1F2;color:#DC2626"><i class="fa-solid fa-arrow-trend-down"></i></div><div class="tile-title">Weakest Questions</div><div class="tile-desc">Which questions candidates scored lowest on — helps revise difficulty or focus coaching.</div></div>
    </div>
    <div class="info info-blue" style="margin-top:4px">
      <i class="fa-solid fa-sliders"></i>
      <div>Use the <strong>Campaign dropdown</strong> and <strong>time period filter</strong> (All / 7d / 30d / 90d) at the top of the analytics page to narrow down results.</div>
    </div>
  </div>

  <!-- ══ USERS ═══════════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="users">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#FEF3C7;color:#D97706"><i class="fa-solid fa-user-shield"></i></div>
      <div>
        <div class="tg-sec-title">User Management <span class="rb rb-sa" style="font-size:10px;margin-left:4px">Super Admin Only</span></div>
        <div class="tg-sec-sub">Adding and managing team members</div>
      </div>
    </div>
    <div class="steps">
      <div class="step">
        <div class="step-n">1</div>
        <div class="step-body">
          <div class="step-title">Create a New User</div>
          <div class="step-desc">Go to <strong>Admins</strong> page → fill in Name, Email, Password, and select Role (Admin / HR / Recruiter) → click <strong>Create</strong>. The user can log in immediately.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">2</div>
        <div class="step-body">
          <div class="step-title">Deactivate / Reactivate</div>
          <div class="step-desc">Click <strong>Toggle Active</strong> on any user row. Deactivated users cannot log in but their data is preserved.</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">3</div>
        <div class="step-body">
          <div class="step-title">Reset a Password</div>
          <div class="step-desc">Click <strong>Reset Password</strong> on any user row, enter a new password. Ask them to change it on next login via Account → Change Password.</div>
        </div>
      </div>
    </div>
    <div class="info info-blue">
      <i class="fa-solid fa-lightbulb"></i>
      <div><strong>Recommended:</strong> HR/Recruiters who only review results → Recruiter role. Team leads who also create campaigns → Admin/HR role. Only the account owner needs Super Admin.</div>
    </div>
  </div>

  <!-- ══ CREDITS ═════════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="credits">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#FEF9C3;color:#D97706"><i class="fa-solid fa-coins"></i></div>
      <div>
        <div class="tg-sec-title">Credits &amp; Billing</div>
        <div class="tg-sec-sub">Managing your messaging and call credits</div>
      </div>
    </div>
    <table class="tbl">
      <thead><tr><th>Credit Type</th><th>Used For</th></tr></thead>
      <tbody>
        <tr><td><strong>WhatsApp</strong></td><td>Each WhatsApp message sent to a candidate (invite or result notification)</td></tr>
        <tr><td><strong>SMS</strong></td><td>SMS messages if WhatsApp is unavailable</td></tr>
        <tr><td><strong>Email</strong></td><td>Email messages to candidates</td></tr>
        <tr><td><strong>AI Call</strong></td><td>Each AI voice call made via Avya dialer</td></tr>
      </tbody>
    </table>
    <div class="info info-orange">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <div>Check your balance before bulk outreach. Go to <strong>Credits</strong> to view current balance and purchase more. Low balance = failed sends.</div>
    </div>
  </div>

  <!-- ══ WORKFLOWS ═══════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="workflows">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#ECFDF5;color:#059669"><i class="fa-solid fa-repeat"></i></div>
      <div>
        <div class="tg-sec-title">Common Workflows</div>
        <div class="tg-sec-sub">Step-by-step guides for the most common tasks</div>
      </div>
    </div>
    <div class="wf-card">
      <div class="wf-title"><i class="fa-solid fa-flag-checkered"></i> Workflow A: Launch a new hiring drive from scratch</div>
      <div class="flow" style="margin-bottom:14px">
        <span class="flow-pill fp-blue">AI Builder</span><span class="flow-arr">→</span>
        <span class="flow-pill fp-blue">Review</span><span class="flow-arr">→</span>
        <span class="flow-pill fp-blue">Activate</span><span class="flow-arr">→</span>
        <span class="flow-pill fp-blue">Share Link</span><span class="flow-arr">→</span>
        <span class="flow-pill fp-green">Review Results</span>
      </div>
      <div class="wf-steps">
        <div class="wf-step">Go to <strong>AI Builder</strong>, paste JD → generate campaign</div>
        <div class="wf-step">Review / edit the 10 generated questions if needed</div>
        <div class="wf-step">Click <strong>Create Campaign</strong> (status = Draft)</div>
        <div class="wf-step">Click <strong>Activate</strong> on the campaign</div>
        <div class="wf-step">Share the apply link via WhatsApp or post it in job portals</div>
        <div class="wf-step">Go to <strong>Candidates</strong> → filter by Completed → review scores</div>
        <div class="wf-step">Shortlist or reject candidates based on score + recording</div>
      </div>
    </div>
    <div class="wf-card">
      <div class="wf-title"><i class="fa-solid fa-upload"></i> Workflow B: Import candidates and send invites</div>
      <div class="flow" style="margin-bottom:14px">
        <span class="flow-pill fp-blue">Import CSV</span><span class="flow-arr">→</span>
        <span class="flow-pill fp-blue">Outreach</span><span class="flow-arr">→</span>
        <span class="flow-pill fp-blue">Select All</span><span class="flow-arr">→</span>
        <span class="flow-pill fp-blue">Bulk Send WA</span><span class="flow-arr">→</span>
        <span class="flow-pill fp-green">Track Status</span>
      </div>
      <div class="wf-steps">
        <div class="wf-step">Go to <strong>Candidates → + Add → Bulk CSV</strong></div>
        <div class="wf-step">Upload CSV with Name, Phone, Email — select campaign → Import</div>
        <div class="wf-step">Go to <strong>Outreach</strong> → filter by campaign → Select All</div>
        <div class="wf-step">Click <strong>Bulk Send WhatsApp</strong></div>
        <div class="wf-step">Monitor Candidates page — statuses update as candidates respond</div>
      </div>
    </div>
    <div class="wf-card">
      <div class="wf-title"><i class="fa-solid fa-list-check"></i> Workflow C: Review and shortlist completed interviews</div>
      <div class="wf-steps">
        <div class="wf-step">Go to <strong>Candidates</strong> → filter status = <strong>Completed</strong></div>
        <div class="wf-step">Sort by Score column (highest first)</div>
        <div class="wf-step">Click <strong>View</strong> on top candidates — check score, Q&amp;A, recording, integrity</div>
        <div class="wf-step">Click <strong>Change Status → Shortlisted</strong> for qualified candidates</div>
        <div class="wf-step">Add recruiter notes for team visibility</div>
        <div class="wf-step">Export final shortlist: filter by Shortlisted → <strong>Export CSV</strong></div>
      </div>
    </div>
  </div>

  <!-- ══ FAQ ═════════════════════════════════════════════════════════════════ -->
  <div class="tg-sec" id="faq">
    <div class="tg-sec-hd">
      <div class="tg-sec-icon" style="background:#F5F3FF;color:#7C3AED"><i class="fa-solid fa-circle-question"></i></div>
      <div>
        <div class="tg-sec-title">Frequently Asked Questions</div>
        <div class="tg-sec-sub">Common questions from the team</div>
      </div>
    </div>

    <div class="faq" onclick="toggleFaq(this)"><div class="faq-q">Can a candidate retake the interview if something went wrong?<i class="fa-solid fa-chevron-down"></i></div><div class="faq-a">No. Each candidate gets exactly one attempt. If they start the interview, it's locked — even if they close the browser mid-way. If there's a genuine technical issue, delete the candidate record and re-add them with the same details to generate a fresh link.</div></div>

    <div class="faq" onclick="toggleFaq(this)"><div class="faq-q">Why does a candidate show "On Hold" instead of Shortlisted/Rejected?<i class="fa-solid fa-chevron-down"></i></div><div class="faq-a">On Hold means AI scoring failed temporarily (usually a brief API issue). The system retries scoring automatically every night at 2am. You can also manually trigger rescoring from the candidate detail page using the "Score Voice" button. On Hold candidates are NOT lost — all answers are saved.</div></div>

    <div class="faq" onclick="toggleFaq(this)"><div class="faq-q">How long does AI scoring take after interview completion?<i class="fa-solid fa-chevron-down"></i></div><div class="faq-a">Typically 1–3 minutes after the candidate submits. For candidates with many voice answers, it may take slightly longer as voice transcription adds time. Just refresh the page after a couple of minutes.</div></div>

    <div class="faq" onclick="toggleFaq(this)"><div class="faq-q">Can I use the same campaign for multiple batches?<i class="fa-solid fa-chevron-down"></i></div><div class="faq-a">Yes. One campaign can have unlimited candidates. Keep sharing the same apply link for new batches. Use the Clone button on the campaign list to create a fresh copy if you need a separate batch with different settings.</div></div>

    <div class="faq" onclick="toggleFaq(this)"><div class="faq-q">A candidate was terminated by face detection — was it a real violation?<i class="fa-solid fa-chevron-down"></i></div><div class="faq-a">Face termination requires 2 consecutive failed checks, with a 15-second warning before the second check. Common legitimate causes: dark room, bad webcam, candidate too far from camera. Always review the video recording before making a final decision. You can still manually shortlist a terminated candidate if the recording looks fine.</div></div>

    <div class="faq" onclick="toggleFaq(this)"><div class="faq-q">Can candidates apply and interview from a mobile phone?<i class="fa-solid fa-chevron-down"></i></div><div class="faq-a">Yes — both the apply form and interview are mobile-optimised. However, candidates get the best experience on a laptop/desktop, especially for voice recording and camera quality. The disclaimer on the interview screen already tells them this. Chrome on Android works best for mobile interviews.</div></div>

    <div class="faq" onclick="toggleFaq(this)"><div class="faq-q">Can I edit questions after a campaign is already active?<i class="fa-solid fa-chevron-down"></i></div><div class="faq-a">Yes — questions can be edited at any time. Changes apply only to candidates who haven't started yet. Candidates who already completed their interview are not affected. Consider deactivating the campaign, making changes, then re-activating to avoid inconsistency.</div></div>

    <div class="faq" onclick="toggleFaq(this)"><div class="faq-q">What's the difference between Deactivate and Delete for a campaign?<i class="fa-solid fa-chevron-down"></i></div><div class="faq-a"><strong>Deactivate (Paused):</strong> Apply link stops working but all candidate data, questions, and results are preserved. Re-activate anytime. <strong>Delete:</strong> Permanently removes the campaign AND all its candidates, answers, and scores. Cannot be undone. Use Deactivate for seasonal roles; Delete only when the campaign is no longer needed at all.</div></div>

    <div class="faq" onclick="toggleFaq(this)"><div class="faq-q">How do I give a new team member access?<i class="fa-solid fa-chevron-down"></i></div><div class="faq-a">Only Super Admin can create users. Go to Admins page → fill in their Name, Email, Password, and Role → click Create. They can log in immediately at hire.clouddialer.in. Recommend they change their password on first login via Account → Change Password.</div></div>

    <div style="margin-top:16px;padding:14px 18px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);font-size:12.5px;color:var(--muted)">
      <i class="fa-solid fa-envelope" style="color:var(--blue);margin-right:6px"></i>
      <strong style="color:var(--text)">Still have questions?</strong> Contact your system administrator or reach out to Avyukta Intellicall support.
    </div>
  </div>

  </div><!-- /tg-page -->
</div><!-- /tg-body -->
</div><!-- /tg-root -->

<script>
function navTo(id, btn) {
  const el = document.getElementById(id);
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  document.querySelectorAll('.tg-nav-item').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
}

function toggleFaq(el) { el.classList.toggle('open'); }

// Scroll-spy
const body = document.getElementById('tgBody');
const sids = ['overview','quickstart','roles','campaigns','ai_builder','apply_form',
  'candidates','outreach','interview_flow','face_detection','results','scoring',
  'analytics','users','credits','workflows','faq'];

body.addEventListener('scroll', () => {
  const scrollTop = body.scrollTop + 80;
  let cur = sids[0];
  sids.forEach(id => {
    const el = document.getElementById(id);
    if (el && el.offsetTop <= scrollTop) cur = id;
  });
  document.querySelectorAll('.tg-nav-item').forEach((btn, i) => {
    btn.classList.toggle('active', sids[i] === cur);
  });
}, { passive: true });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
