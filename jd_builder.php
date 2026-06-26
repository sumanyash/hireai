<?php
require_once __DIR__.'/includes/auth_check.php';
?><!DOCTYPE html>
<html lang="en">
<head>
<title>AI Campaign Builder — Avyukta Intellicall AI Hire</title>
<?php include __DIR__.'/includes/head.php'; ?>
<style>
/* ── Builder layout ─────────────────────────────────────────── */
.builder-wrap{max-width:860px;margin:0 auto;padding:28px 0 60px}
/* Step indicator */
.step-bar{display:flex;align-items:center;margin-bottom:32px;gap:0}
.step-item{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:#94A3B8}
.step-item.active{color:#2563EB}
.step-item.done{color:#10B981}
.step-dot{width:28px;height:28px;border-radius:50%;background:#E2E8F0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;flex-shrink:0;transition:all .3s}
.step-item.active .step-dot{background:#2563EB;color:#fff;box-shadow:0 0 0 4px rgba(37,99,235,.18)}
.step-item.done .step-dot{background:#10B981;color:#fff}
.step-line{flex:1;height:2px;background:#E2E8F0;margin:0 8px}
.step-line.done{background:#10B981}
/* Phase containers */
#phase-input,#phase-preview,#phase-done{transition:opacity .3s}
/* Input phase */
.jd-textarea{width:100%;min-height:240px;padding:16px;border:2px solid #E2E8F0;border-radius:16px;font-size:14px;font-family:inherit;resize:vertical;color:#0F172A;line-height:1.6;transition:border-color .2s}
.jd-textarea:focus{outline:none;border-color:#2563EB}
.upload-zone{border:2px dashed #CBD5E1;border-radius:12px;padding:18px 20px;display:flex;align-items:center;gap:12px;cursor:pointer;transition:all .2s;margin-top:12px}
.upload-zone:hover{border-color:#2563EB;background:#EFF6FF}
.upload-zone input{display:none}
.generate-btn{width:100%;padding:16px;background:linear-gradient(135deg,#7C3AED,#2563EB);color:#fff;border:none;border-radius:14px;font-size:16px;font-weight:800;cursor:pointer;margin-top:20px;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:10px}
.generate-btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(37,99,235,.3)}
.generate-btn:disabled{opacity:.6;cursor:not-allowed;transform:none}
.cost-note{text-align:center;font-size:12px;color:#94A3B8;margin-top:12px}
/* Preview phase */
.preview-section{background:#fff;border-radius:16px;border:1px solid #E2E8F0;margin-bottom:16px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.preview-section-head{padding:16px 20px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;justify-content:space-between}
.preview-section-head h3{font-size:15px;font-weight:800;color:#0F172A;display:flex;align-items:center;gap:8px;margin:0}
.preview-section-body{padding:20px}
.pf-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.pf-group{display:flex;flex-direction:column;gap:4px}
.pf-label{font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.5px}
.pf-input{padding:9px 12px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:13px;font-family:inherit;color:#0F172A;transition:border-color .2s;background:#fff}
.pf-input:focus{outline:none;border-color:#2563EB}
textarea.pf-input{resize:vertical;min-height:72px}
/* Question cards */
.q-card{background:#F8FAFC;border:1.5px solid #E2E8F0;border-radius:12px;padding:14px 16px;margin-bottom:10px;position:relative}
.q-card-head{display:flex;align-items:flex-start;gap:10px;margin-bottom:8px}
.q-num-badge{width:26px;height:26px;background:linear-gradient(135deg,#7C3AED,#2563EB);color:#fff;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0}
.q-text-input{flex:1;padding:8px 10px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:13px;font-family:inherit;color:#0F172A;resize:vertical;min-height:52px}
.q-text-input:focus{outline:none;border-color:#7C3AED}
.q-remove{width:26px;height:26px;border:none;background:#FEE2E2;color:#DC2626;border-radius:7px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;transition:all .15s}
.q-remove:hover{background:#DC2626;color:#fff}
.q-meta{display:flex;gap:8px;flex-wrap:wrap}
.q-meta-group{display:flex;flex-direction:column;gap:3px}
.q-meta-label{font-size:10px;font-weight:700;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px}
.q-meta-input{padding:5px 9px;border:1.5px solid #E2E8F0;border-radius:8px;font-size:12px;font-family:inherit;color:#0F172A;background:#fff}
.q-meta-input:focus{outline:none;border-color:#7C3AED}
.q-meta-input[name="weight"]{width:58px;text-align:center;font-weight:800}
.add-q-btn{width:100%;padding:10px;border:2px dashed #CBD5E1;border-radius:10px;background:transparent;color:#64748B;font-size:13px;font-weight:700;cursor:pointer;transition:all .2s;margin-top:4px}
.add-q-btn:hover{border-color:#7C3AED;color:#7C3AED;background:#F5F3FF}
/* Field rows */
.field-row{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid #F1F5F9;transition:background .15s}
.field-row:last-child{border-bottom:none}
.field-row:hover{background:#F8FAFC}
.field-toggle{width:18px;height:18px;accent-color:#7C3AED;cursor:pointer;flex-shrink:0}
.field-label-input{flex:1;padding:5px 9px;border:1.5px solid #E2E8F0;border-radius:8px;font-size:13px;font-family:inherit;color:#0F172A}
.field-label-input:focus{outline:none;border-color:#7C3AED}
.field-type-select{padding:5px 8px;border:1.5px solid #E2E8F0;border-radius:8px;font-size:12px;font-family:inherit;color:#0F172A;background:#fff}
.field-req-badge{padding:2px 8px;border-radius:99px;font-size:10px;font-weight:800;background:#FEF3C7;color:#92400E;white-space:nowrap;cursor:pointer;user-select:none}
.field-req-badge.req{background:#DCFCE7;color:#166534}
/* Bottom action bar */
.preview-actions{display:flex;gap:10px;margin-top:20px}
.btn-regen{flex:0 0 auto;padding:14px 20px;border:2px solid #E2E8F0;border-radius:12px;background:#fff;color:#64748B;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s}
.btn-regen:hover{border-color:#7C3AED;color:#7C3AED}
.btn-create{flex:1;padding:14px;background:linear-gradient(135deg,#7C3AED,#2563EB);color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s}
.btn-create:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(37,99,235,.3)}
.btn-create:disabled{opacity:.6;cursor:not-allowed;transform:none}
/* Done phase */
.done-card{text-align:center;padding:48px 32px}
.done-icon{width:72px;height:72px;background:linear-gradient(135deg,#7C3AED,#2563EB);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:32px}
.done-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:24px}
/* Loading overlay */
#loading-overlay{display:none;position:fixed;inset:0;background:rgba(8,15,30,.75);backdrop-filter:blur(8px);z-index:9000;align-items:center;justify-content:center;flex-direction:column;gap:16px}
#loading-overlay.active{display:flex}
.loading-spinner{width:56px;height:56px;border:4px solid rgba(255,255,255,.15);border-top-color:#7C3AED;border-radius:50%;animation:spin .8s linear infinite}
.loading-text{color:#fff;font-size:16px;font-weight:700}
.loading-sub{color:rgba(255,255,255,.55);font-size:13px}
@keyframes spin{to{transform:rotate(360deg)}}
/* Weight sum indicator */
.weight-sum-bar{background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.weight-sum-val{font-size:15px;font-weight:900}
.weight-ok{color:#10B981}
.weight-warn{color:#EF4444}
@media(max-width:640px){.pf-row{grid-template-columns:1fr}.step-label{display:none}.preview-actions{flex-direction:column}}
</style>
</head>
<body>
<?php include __DIR__.'/includes/nav.php'; ?>

<!-- Loading overlay -->
<div id="loading-overlay">
  <div class="loading-spinner"></div>
  <div class="loading-text" id="loading-text">Analyzing Job Description…</div>
  <div class="loading-sub">Avyukta AI is generating your campaign</div>
</div>

<div class="main-content">
  <div class="builder-wrap">

    <!-- Page header -->
    <div style="margin-bottom:24px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
        <a href="/campaigns" style="color:var(--gray);font-size:13px;text-decoration:none">← Campaigns</a>
      </div>
      <h2 style="font-size:26px;font-weight:900;letter-spacing:-.5px;background:linear-gradient(135deg,#7C3AED,#2563EB);-webkit-background-clip:text;-webkit-text-fill-color:transparent">✨ AI Campaign Builder</h2>
      <p style="color:var(--gray2);font-size:14px;margin-top:4px">Paste your Job Description — AI creates the full campaign, questions &amp; application form</p>
    </div>

    <!-- Step indicator -->
    <div class="step-bar">
      <div class="step-item active" id="step-1">
        <div class="step-dot">1</div>
        <span class="step-label">Job Description</span>
      </div>
      <div class="step-line" id="line-1"></div>
      <div class="step-item" id="step-2">
        <div class="step-dot">2</div>
        <span class="step-label">Review &amp; Edit</span>
      </div>
      <div class="step-line" id="line-2"></div>
      <div class="step-item" id="step-3">
        <div class="step-dot">3</div>
        <span class="step-label">Done!</span>
      </div>
    </div>

    <!-- Phase 1: Input -->
    <div id="phase-input">
      <div class="card">
        <div style="margin-bottom:16px">
          <label style="font-size:14px;font-weight:700;color:#0F172A;display:block;margin-bottom:8px">Paste your Job Description</label>
          <textarea class="jd-textarea" id="jd-input" placeholder="Paste the full job description here — title, responsibilities, requirements, skills needed…&#10;&#10;The more detail you provide, the better the generated questions and form fields."></textarea>
        </div>
        <div class="upload-zone" onclick="document.getElementById('jd-file').click()">
          <i class="fa-solid fa-file-lines" style="font-size:20px;color:#7C3AED"></i>
          <div>
            <div style="font-size:13px;font-weight:700;color:#334155">Upload a .txt or .md file</div>
            <div style="font-size:12px;color:#94A3B8">Click to browse or drop file</div>
          </div>
          <input type="file" id="jd-file" accept=".txt,.md,.text" onchange="handleFile(this)">
        </div>
        <button class="generate-btn" id="generateBtn" onclick="generateCampaign()">
          <i class="fa-solid fa-wand-magic-sparkles"></i> Generate Campaign with AI
        </button>
        <p class="cost-note">Powered by <strong>Avyukta AI</strong></p>
      </div>
    </div>

    <!-- Phase 2: Preview (rendered by JS) -->
    <div id="phase-preview" style="display:none"></div>

    <!-- Phase 3: Done -->
    <div id="phase-done" style="display:none">
      <div class="card">
        <div class="done-card">
          <div class="done-icon">🚀</div>
          <h3 style="font-size:22px;font-weight:900;color:#0F172A;margin-bottom:8px">Campaign Created!</h3>
          <p style="color:#64748B;font-size:14px;max-width:400px;margin:0 auto" id="done-msg">Your campaign has been created with questions and application form.</p>
          <div class="done-actions" id="done-actions"></div>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
const CSRF_TOKEN = '<?= htmlspecialchars(csrf_token()) ?>';
let generatedData = null;

// ── STEP INDICATOR ───────────────────────────────────────────
function setStep(n) {
  [1,2,3].forEach(i => {
    const el = document.getElementById('step-'+i);
    el.classList.remove('active','done');
    if (i < n) el.classList.add('done');
    else if (i === n) el.classList.add('active');
    if (i < 3) {
      const line = document.getElementById('line-'+i);
      line.classList.toggle('done', i < n);
    }
  });
}

// ── FILE UPLOAD ───────────────────────────────────────────────
function handleFile(input) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => { document.getElementById('jd-input').value = e.target.result; };
  reader.readAsText(file);
}

// ── PHASE 1 → 2: GENERATE ────────────────────────────────────
async function generateCampaign() {
  const jd = document.getElementById('jd-input').value.trim();
  if (jd.length < 30) { showToast('Please paste a job description first (min 30 characters).','error'); return; }
  const btn = document.getElementById('generateBtn');
  btn.disabled = true;
  showLoading('Analyzing Job Description…', 'Avyukta AI is generating your campaign configuration');
  try {
    const r = await fetch('/api/generate_campaign.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({jd_text: jd})
    });
    const d = await r.json();
    hideLoading();
    if (d.success) {
      generatedData = d;
      renderPreview(d);
      document.getElementById('phase-input').style.display = 'none';
      document.getElementById('phase-preview').style.display = 'block';
      setStep(2);
      window.scrollTo({top: 0, behavior:'smooth'});
    } else {
      showToast(d.error || 'Generation failed — please retry', 'error');
      btn.disabled = false;
    }
  } catch(e) {
    hideLoading();
    showToast('Network error — please retry', 'error');
    btn.disabled = false;
  }
}

// ── RENDER PREVIEW ────────────────────────────────────────────
function renderPreview(d) {
  const container = document.getElementById('phase-preview');
  const langOptions = ['english','hinglish','hindi'].map(l =>
    `<option value="${l}" ${d.language===l?'selected':''}>${l.charAt(0).toUpperCase()+l.slice(1)}</option>`
  ).join('');
  const agentField = ''; // ElevenLabs disabled — using Avya Dialer instead

  let questionsHtml = d.questions.map((q, i) => renderQuestionCard(q, i)).join('');

  const fieldTypeOpts = ['text','email','phone','number','url','dropdown','date','textarea'].map(t =>
    `<option value="${t}">${t}</option>`
  ).join('');

  let fieldsHtml = d.application_fields.map((f, i) => {
    const reqClass = f.is_required ? 'req' : '';
    const reqLabel = f.is_required ? 'Required' : 'Optional';
    return `<div class="field-row" id="frow-${i}">
      <input type="checkbox" class="field-toggle" checked onchange="updateFieldRow(${i}, this.checked)">
      <input class="field-label-input" type="text" value="${esc(f.field_label)}" onchange="updateFieldData(${i},'label',this.value)">
      <select class="field-type-select" onchange="updateFieldData(${i},'type',this.value)">
        ${['text','email','phone','number','url','dropdown','date','textarea'].map(t=>
          `<option value="${t}" ${f.field_type===t?'selected':''}>${t}</option>`
        ).join('')}
      </select>
      <span class="field-req-badge ${reqClass}" onclick="toggleRequired(${i}, this)">${reqLabel}</span>
    </div>`;
  }).join('');

  const totalW = d.questions.reduce((s,q) => s + (q.weight||0), 0);
  const wClass = totalW === 100 ? 'weight-ok' : 'weight-warn';

  container.innerHTML = `
  <!-- Campaign Details -->
  <div class="preview-section">
    <div class="preview-section-head">
      <h3><i class="fa-solid fa-rocket" style="color:#7C3AED"></i> Campaign Details</h3>
    </div>
    <div class="preview-section-body">
      <div class="pf-row">
        <div class="pf-group" style="grid-column:span 2">
          <div class="pf-label">Campaign Name</div>
          <input class="pf-input" name="campaign_name" type="text" value="${esc(d.campaign_name)}">
        </div>
      </div>
      <div class="pf-row">
        <div class="pf-group">
          <div class="pf-label">Job Role</div>
          <input class="pf-input" name="job_role" type="text" value="${esc(d.job_role)}">
        </div>
        <div class="pf-group">
          <div class="pf-label">Language</div>
          <select class="pf-input" name="language">${langOptions}</select>
        </div>
      </div>
      <div class="pf-row">
        <div class="pf-group">
          <div class="pf-label">Passing Score (/100)</div>
          <input class="pf-input" name="passing_score" type="number" min="50" max="95" value="${d.passing_score}">
        </div>
        <!-- ElevenLabs Agent ID field disabled — using Avya Dialer instead -->
      </div>
      <div class="pf-group">
        <div class="pf-label">Description (shown to candidates)</div>
        <textarea class="pf-input" name="description" rows="2">${esc(d.description)}</textarea>
      </div>
    </div>
  </div>

  <!-- Interview Questions -->
  <div class="preview-section">
    <div class="preview-section-head">
      <h3><i class="fa-solid fa-comments" style="color:#2563EB"></i> Interview Questions <span style="font-size:12px;font-weight:600;color:#94A3B8;margin-left:6px">${d.questions.length} questions</span></h3>
    </div>
    <div class="preview-section-body">
      <div class="weight-sum-bar" id="weight-bar">
        <i class="fa-solid fa-scale-balanced" style="color:#64748B"></i>
        Total weight: <span class="weight-sum-val ${wClass}" id="weight-total">${totalW}</span>/100
        ${totalW !== 100 ? '<span style="color:#EF4444;font-size:11px">(must equal 100)</span>' : '<span style="color:#10B981;font-size:11px">✓ OK</span>'}
      </div>
      <div id="questions-list">${questionsHtml}</div>
      <button class="add-q-btn" onclick="addQuestion()"><i class="fa-solid fa-plus fa-xs"></i> Add Question</button>
    </div>
  </div>

  <!-- Application Form Fields -->
  <div class="preview-section">
    <div class="preview-section-head">
      <h3><i class="fa-solid fa-wpforms" style="color:#10B981"></i> Application Form Fields <span style="font-size:12px;font-weight:600;color:#94A3B8;margin-left:6px">${d.application_fields.length} fields</span></h3>
      <span style="font-size:11px;color:#94A3B8">Toggle to include/exclude each field</span>
    </div>
    <div id="fields-list">${fieldsHtml}</div>
  </div>

  <!-- Action buttons -->
  <div class="preview-actions">
    <button class="btn-regen" onclick="reGenerate()"><i class="fa-solid fa-rotate-left fa-xs"></i> Re-generate</button>
    <button class="btn-create" id="createBtn" onclick="createCampaign()">
      <i class="fa-solid fa-rocket fa-xs"></i> Create Campaign
    </button>
  </div>`;
}

const Q_TYPE_META = {
  mcq:          { label:'MCQ',          color:'#7C3AED', bg:'#F5F3FF', icon:'fa-list-check' },
  short_answer: { label:'Short Answer', color:'#0369A1', bg:'#EFF6FF', icon:'fa-pen-line' },
  voice_note:   { label:'Voice Note',   color:'#0F766E', bg:'#F0FDFA', icon:'fa-microphone' },
};
function renderQuestionCard(q, i) {
  const rawType = q.raw_type || (q.question_type === 'dropdown' ? 'mcq' : q.question_type === 'textarea' ? 'short_answer' : 'voice_note');
  const tm = Q_TYPE_META[rawType] || Q_TYPE_META['voice_note'];
  const typeBadge = `<span style="font-size:10px;font-weight:800;background:${tm.bg};color:${tm.color};border-radius:6px;padding:2px 8px;white-space:nowrap"><i class="fa-solid ${tm.icon} fa-xs"></i> ${tm.label}</span>`;
  const typeSelect = `<select class="q-meta-input q-type-select" style="width:140px" onchange="onQTypeChange(this,${i})">
    <option value="mcq" ${rawType==='mcq'?'selected':''}>MCQ (Multiple Choice)</option>
    <option value="short_answer" ${rawType==='short_answer'?'selected':''}>Short Answer</option>
    <option value="voice_note" ${rawType==='voice_note'?'selected':''}>Voice Note</option>
  </select>`;
  const isMCQ = rawType === 'mcq';
  const optionsVal = isMCQ && Array.isArray(q.options) ? q.options.join('\n') : '';
  const optionsBlock = `<div class="q-mcq-opts" id="qopts-${i}" style="display:${isMCQ?'block':'none'};margin-top:8px">
    <div class="q-meta-label" style="margin-bottom:4px">MCQ Options (one per line, 4 required)</div>
    <textarea class="q-meta-input" rows="4" style="width:100%;resize:vertical" placeholder="Option A\nOption B\nOption C\nOption D">${esc(optionsVal)}</textarea>
    <div class="q-meta-label" style="margin-top:4px;margin-bottom:2px">Correct Answer</div>
    <input class="q-meta-input" type="text" style="width:100%" placeholder="Must match one option exactly" value="${esc(q.correct_answer||'')}">
  </div>`;
  return `<div class="q-card" id="qcard-${i}" data-idx="${i}" data-rawtype="${rawType}">
    <div class="q-card-head">
      <div class="q-num-badge">${i+1}</div>
      <textarea class="q-text-input" rows="2" placeholder="Question text…" onchange="updateWeight()">${esc(q.question_text)}</textarea>
      <button class="q-remove" onclick="removeQuestion(${i})" title="Remove"><i class="fa-solid fa-xmark fa-xs"></i></button>
    </div>
    <div class="q-meta">
      <div class="q-meta-group">
        <div class="q-meta-label">Parameter key</div>
        <input class="q-meta-input" name="q-param" type="text" value="${esc(q.parameter)}" placeholder="param_key" style="width:120px">
      </div>
      <div class="q-meta-group">
        <div class="q-meta-label">Label</div>
        <input class="q-meta-input" name="q-label" type="text" value="${esc(q.parameter_label)}" placeholder="Label" style="width:130px">
      </div>
      <div class="q-meta-group">
        <div class="q-meta-label">Type</div>
        ${typeSelect}
      </div>
      <div class="q-meta-group">
        <div class="q-meta-label">Weight (pts)</div>
        <input class="q-meta-input" type="number" name="weight" value="${q.weight}" min="1" max="50" onchange="updateWeight()">
      </div>
      <div class="q-meta-group" style="flex:1">
        <div class="q-meta-label">Ideal answer hint</div>
        <input class="q-meta-input" name="q-hint" type="text" value="${esc(q.ideal_answer_hint)}" placeholder="Keywords to look for…" style="width:100%">
      </div>
    </div>
    ${optionsBlock}
  </div>`;
}
function onQTypeChange(select, i) {
  const card = document.getElementById('qcard-'+i);
  if (!card) return;
  const rawType = select.value;
  card.dataset.rawtype = rawType;
  // Use card-scoped querySelector so it works even if qopts ID is momentarily stale
  const optsDiv = card.querySelector('.q-mcq-opts');
  if (optsDiv) optsDiv.style.display = rawType === 'mcq' ? 'block' : 'none';
}

function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── WEIGHT SUM UPDATER ───────────────────────────────────────
function updateWeight() {
  const inputs = document.querySelectorAll('#questions-list .q-meta-input[name="weight"]');
  let total = 0;
  inputs.forEach(inp => total += parseInt(inp.value||0));
  const el = document.getElementById('weight-total');
  if (!el) return;
  el.textContent = total;
  el.className = 'weight-sum-val ' + (total === 100 ? 'weight-ok' : 'weight-warn');
  const bar = document.getElementById('weight-bar');
  if (bar) {
    const note = bar.querySelector('span:last-child');
    if (note) note.outerHTML = total === 100
      ? '<span style="color:#10B981;font-size:11px">✓ OK</span>'
      : '<span style="color:#EF4444;font-size:11px">(must equal 100)</span>';
  }
}

// ── ADD/REMOVE QUESTION ───────────────────────────────────────
function addQuestion() {
  const list = document.getElementById('questions-list');
  const idx = list.querySelectorAll('.q-card').length;
  const blank = {question_text:'',parameter:'question_'+(idx+1),parameter_label:'Question '+(idx+1),weight:10,max_marks:10,ideal_answer_hint:'',raw_type:'voice_note',question_type:'audio',options:[],correct_answer:''};
  const div = document.createElement('div');
  div.innerHTML = renderQuestionCard(blank, idx);
  list.appendChild(div.firstElementChild);
  renumberQuestions();
  updateWeight();
}

function removeQuestion(i) {
  const card = document.getElementById('qcard-'+i);
  if (card) { card.remove(); renumberQuestions(); updateWeight(); }
}

function renumberQuestions() {
  document.querySelectorAll('#questions-list .q-card').forEach((card, i) => {
    card.id = 'qcard-'+i;
    card.dataset.idx = i;
    const badge = card.querySelector('.q-num-badge');
    if (badge) badge.textContent = i+1;
    const removeBtn = card.querySelector('.q-remove');
    if (removeBtn) removeBtn.setAttribute('onclick', `removeQuestion(${i})`);
    // Keep qopts div ID and type-select onchange in sync with new card index
    const optsDiv = card.querySelector('.q-mcq-opts');
    if (optsDiv) optsDiv.id = 'qopts-'+i;
    const typeSelect = card.querySelector('.q-type-select');
    if (typeSelect) typeSelect.setAttribute('onchange', `onQTypeChange(this,${i})`);
  });
}

// ── FIELD HELPERS ─────────────────────────────────────────────
function updateFieldRow(i, enabled) {
  const row = document.getElementById('frow-'+i);
  if (row) row.style.opacity = enabled ? '1' : '0.4';
}
function updateFieldData(i, key, val) { /* data stored in DOM, read at save time */ }
function toggleRequired(i, el) {
  const isReq = el.classList.contains('req');
  el.classList.toggle('req', !isReq);
  el.textContent = isReq ? 'Optional' : 'Required';
}

// ── RE-GENERATE ───────────────────────────────────────────────
function reGenerate() {
  document.getElementById('phase-preview').style.display = 'none';
  document.getElementById('phase-input').style.display = 'block';
  document.getElementById('generateBtn').disabled = false;
  setStep(1);
  window.scrollTo({top:0,behavior:'smooth'});
}

// ── COLLECT DATA FROM DOM ─────────────────────────────────────
function collectData() {
  const pv = document.getElementById('phase-preview');

  // Campaign details
  const campaign_name  = pv.querySelector('[name="campaign_name"]')?.value?.trim() || '';
  const job_role       = pv.querySelector('[name="job_role"]')?.value?.trim() || '';
  const description    = pv.querySelector('[name="description"]')?.value?.trim() || '';
  const passing_score  = parseInt(pv.querySelector('[name="passing_score"]')?.value || '70');
  const language       = pv.querySelector('[name="language"]')?.value || 'english';
  const el_agent_id    = ''; // ElevenLabs disabled

  // Questions
  const typeMap = {mcq:'dropdown', short_answer:'textarea', voice_note:'audio'};
  const questions = [];
  pv.querySelectorAll('#questions-list .q-card').forEach((card, i) => {
    const q_text  = card.querySelector('.q-text-input')?.value?.trim() || '';
    const param   = card.querySelector('input[name="q-param"]')?.value?.trim() || 'question_'+(i+1);
    const p_label = card.querySelector('input[name="q-label"]')?.value?.trim() || 'Question '+(i+1);
    const weight  = parseInt(card.querySelector('input[name="weight"]')?.value || '15');
    const hint    = card.querySelector('input[name="q-hint"]')?.value?.trim() || '';
    const rawType = card.dataset.rawtype || card.querySelector('.q-type-select')?.value || 'voice_note';
    const dbType  = typeMap[rawType] || 'audio';
    // MCQ extras — use card-scoped querySelector, not global getElementById, to be immune to ID drift
    let options = [], correct_answer = '';
    if (rawType === 'mcq') {
      const optsDiv = card.querySelector('.q-mcq-opts');
      if (optsDiv) {
        const optsTa = optsDiv.querySelector('textarea');
        const correctIn = optsDiv.querySelector('input[type=text]');
        options = (optsTa?.value||'').split('\n').map(s=>s.trim()).filter(Boolean);
        correct_answer = correctIn?.value?.trim() || '';
      }
    }
    if (q_text) questions.push({parameter:param, parameter_label:p_label, weight, max_marks:weight, question_text:q_text, ideal_answer_hint:hint, question_type:dbType, raw_type:rawType, options, correct_answer, order_no:i+1});
  });

  // Application fields
  const application_fields = [];
  pv.querySelectorAll('#fields-list .field-row').forEach((row, i) => {
    const enabled = row.querySelector('.field-toggle')?.checked || false;
    const label   = row.querySelector('.field-label-input')?.value?.trim() || '';
    const type    = row.querySelector('.field-type-select')?.value || 'text';
    const reqEl   = row.querySelector('.field-req-badge');
    const is_req  = reqEl?.classList.contains('req') ? 1 : 0;
    const origField = generatedData?.application_fields?.[i] || {};
    if (label) application_fields.push({enabled, field_label:label, field_key:origField.field_key||('field_'+(i+1)), field_type:type, placeholder:origField.placeholder||'', is_required:is_req, order_no:i+1, options_json:origField.options_json||null});
  });

  return {campaign_name, job_role, description, passing_score, language, el_agent_id, num_questions:questions.length, questions, application_fields};
}

// ── CREATE CAMPAIGN ───────────────────────────────────────────
async function createCampaign() {
  const data = collectData();
  if (!data.campaign_name) { showToast('Campaign name is required','error'); return; }
  if (!data.job_role) { showToast('Job role is required','error'); return; }
  if (data.questions.length < 10) { showToast(`At least 10 questions required (currently ${data.questions.length})`, 'error'); return; }

  const totalW = data.questions.reduce((s,q) => s + q.weight, 0);
  if (totalW !== 100) { showToast(`Question weights must sum to 100 (currently ${totalW})`, 'error'); return; }

  const btn = document.getElementById('createBtn');
  btn.disabled = true;
  showLoading('Creating Campaign…', 'Saving questions and application form');
  try {
    const r = await fetch('/api/save_from_jd.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(data)
    });
    const d = await r.json();
    hideLoading();
    if (d.success) {
      document.getElementById('phase-preview').style.display = 'none';
      document.getElementById('phase-done').style.display = 'block';
      setStep(3);
      document.getElementById('done-msg').textContent = `"${data.campaign_name}" created with ${data.questions.length} questions and ${data.application_fields.filter(f=>f.enabled).length} form fields.`;
      document.getElementById('done-actions').innerHTML = `
        <a href="${d.redirect}" class="btn-primary" style="text-decoration:none">Review Questions →</a>
        <a href="/campaigns?action=edit&id=${d.campaign_id}" style="padding:10px 18px;border:2px solid #E2E8F0;border-radius:10px;font-size:14px;font-weight:700;color:#334155;text-decoration:none;background:#fff">✏️ Edit Details</a>
        <a href="/campaigns" style="padding:10px 18px;border:2px solid #E2E8F0;border-radius:10px;font-size:14px;font-weight:700;color:#334155;text-decoration:none;background:#fff">All Campaigns</a>`;
      window.scrollTo({top:0,behavior:'smooth'});
    } else {
      showToast(d.error || 'Save failed — please retry', 'error');
      btn.disabled = false;
    }
  } catch(e) {
    hideLoading();
    showToast('Network error — please retry', 'error');
    btn.disabled = false;
  }
}

// ── LOADING OVERLAY ───────────────────────────────────────────
function showLoading(text, sub) {
  document.getElementById('loading-text').textContent = text || 'Loading…';
  document.querySelector('#loading-overlay .loading-sub').textContent = sub || '';
  document.getElementById('loading-overlay').classList.add('active');
}
function hideLoading() { document.getElementById('loading-overlay').classList.remove('active'); }

// ── TOAST ─────────────────────────────────────────────────────
function showToast(msg, type) {
  const existing = document.getElementById('jd-toast');
  if (existing) existing.remove();
  const t = document.createElement('div');
  t.id = 'jd-toast';
  t.style.cssText = `position:fixed;bottom:24px;left:50%;transform:translateX(-50%);padding:12px 20px;border-radius:12px;font-size:14px;font-weight:700;z-index:9999;box-shadow:0 8px 32px rgba(0,0,0,.2);display:flex;align-items:center;gap:8px;animation:slideUp .3s ease;max-width:420px;text-align:center`;
  t.style.background = type === 'error' ? '#FEF2F2' : (type === 'success' ? '#ECFDF5' : '#EFF6FF');
  t.style.color      = type === 'error' ? '#991B1B' : (type === 'success' ? '#065F46' : '#1E40AF');
  t.style.border     = '1px solid ' + (type === 'error' ? '#FECACA' : (type === 'success' ? '#A7F3D0' : '#BFDBFE'));
  t.innerHTML = `<i class="fa-solid fa-${type==='error'?'circle-xmark':'circle-check'} fa-sm"></i> ${msg}`;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 5000);
}
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
</body>
</html>
