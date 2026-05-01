<?php
/**
 * HireAI - Public Job Application Form
 * 9-Step Comprehensive Candidate Application
 * Location: /apply.php (public route)
 * 
 * Usage:
 *   - ?campaign_id=123 (direct campaign ID)
 *   - ?t=unique_token (campaign token)
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';

// Get campaign from ID or token
$campaign_id = (int)($_GET['campaign_id'] ?? 0);
$token       = trim($_GET['t'] ?? '');
$ref_token   = trim($_GET['ref'] ?? '');
$referrer    = null;
if ($ref_token !== '') {
    $referrer = db_fetch_one("SELECT id,campaign_id FROM candidates WHERE unique_token=?", [$ref_token], 's');
    if (!$campaign_id && $referrer) $campaign_id = (int)$referrer['campaign_id'];
}

$campaign = null;
if ($campaign_id) {
    $campaign = db_fetch_one(
        "SELECT * FROM campaigns WHERE id=? AND status='active'",
        [$campaign_id],
        'i'
    );
} elseif ($token) {
    $campaign = db_fetch_one(
        "SELECT * FROM campaigns WHERE unique_token=? AND status='active'",
        [$token],
        's'
    );
    if ($campaign) $campaign_id = $campaign['id'];
}

// Get organization details for branding
$org = null;
if ($campaign) {
    $org = db_fetch_one(
        "SELECT * FROM organizations WHERE id=?",
        [$campaign['org_id']],
        'i'
    );
}

$org_name  = $org['name'] ?? 'HireAI';
$org_logo  = $org['logo_url'] ?? 'https://www.avyukta.in/assets/images/logoo.png';
$job_role  = $campaign['job_role'] ?? ($campaign['name'] ?? 'Open Position');
$job_desc  = $campaign['description'] ?? '';

// Fetch all active campaigns for dropdown
$all_campaigns = db_fetch_all("SELECT id, name, job_role FROM campaigns WHERE status='active' ORDER BY name ASC", [], '');

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Apply — <?=htmlspecialchars($job_role)?> | <?=htmlspecialchars($org_name)?></title>
  <link href="https://fonts.googleapis.com/css2?family=Jost:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400;1,500;1,600&display=swap" rel="stylesheet">
  <style>
:root{
  --bg:#0b0d14;
  --surface:#13161f;
  --card:#181c28;
  --border:#252a3d;
  --accent:#4f7cff;
  --accent2:#7c3aed;
  --gold:#f0b429;
  --text:#e6e8f2;
  --muted:#6b728f;
  --error:#f43f5e;
  --success:#22d3a5;
  --radius:10px;
  --tr:0.2s cubic-bezier(.4,0,.2,1);
  --hbg:linear-gradient(135deg,#0c0f1e 0%,#141728 60%,#1a1332 100%);
  --hglow:rgba(79,124,255,.14)
}

[data-theme="light"]{
  --bg:#f0f6ff;
  --surface:#e2ecfa;
  --card:#fff;
  --border:#c4d6f0;
  --accent:#0066ff;
  --accent2:#00b4ff;
  --gold:#ff9500;
  --text:#0a1e38;
  --muted:#5a7899;
  --error:#d62828;
  --success:#00995a;
  --hbg:linear-gradient(135deg,#dbeeff 0%,#c6e2ff 60%,#b0d4ff 100%);
  --hglow:rgba(0,102,255,.14)
}

[data-theme="light"] input,
[data-theme="light"] select,
[data-theme="light"] textarea { color: var(--text); }

[data-theme="light"] select option { background:#fff; color:#0a1e38; }

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

body{
  background:var(--bg);
  color:var(--text);
  font-family:'Jost',sans-serif;
  font-size:15px;
  line-height:1.6;
  min-height:100vh
}

body::before{
  content:'';
  position:fixed;
  inset:0;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
  pointer-events:none;
  z-index:0;
  opacity:.45
}

.header{
  background:var(--hbg);
  border-bottom:1px solid var(--border);
  padding:48px 24px 40px;
  text-align:center;
  position:relative;
  overflow:hidden
}

.header::before{
  content:'';
  position:absolute;
  top:-80px;
  left:50%;
  transform:translateX(-50%);
  width:560px;
  height:320px;
  background:radial-gradient(ellipse,var(--hglow) 0%,transparent 70%);
  pointer-events:none
}

.logo-wrap{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:20px}
.logo-wrap img{height:38px;width:auto;object-fit:contain}

.logo-badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  background:rgba(0,102,255,.1);
  border:1px solid rgba(0,102,255,.28);
  border-radius:4px;
  padding:6px 18px;
  font-size:11px;
  font-weight:600;
  letter-spacing:.14em;
  text-transform:uppercase;
  color:var(--accent)
}

.logo-dot{
  width:7px;
  height:7px;
  border-radius:50%;
  background:var(--accent);
  animation:pulse 2s ease-in-out infinite
}

@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.45;transform:scale(.75)}}

h1{
  font-size:clamp(22px,4vw,38px);
  font-weight:600;
  letter-spacing:-.02em;
  color:var(--text);
  line-height:1.15;
  margin-bottom:8px
}

h1 em{color:var(--accent);font-style:italic;font-weight:500}

.header-sub{
  color:var(--muted);
  font-size:14px;
  max-width:500px;
  margin:0 auto
}

.progress-wrap{
  position:sticky;
  top:0;
  z-index:100;
  background:rgba(240,246,255,.92);
  backdrop-filter:blur(14px);
  border-bottom:1px solid var(--border);
  padding:10px 24px;
  display:flex;
  align-items:center;
  gap:14px
}

.progress-bar-bg{flex:1;height:3px;background:var(--border);border-radius:3px;overflow:hidden}
.progress-bar-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:3px;transition:width .5s cubic-bezier(.4,0,.2,1);width:0%}
.progress-label{font-size:12px;font-weight:600;color:var(--muted);white-space:nowrap;min-width:64px;text-align:right}

.step-dots{display:flex;gap:5px;align-items:center}
.step-dot{width:5px;height:5px;border-radius:50%;background:var(--border);transition:all var(--tr)}
.step-dot.done{background:var(--accent)}
.step-dot.current{width:18px;border-radius:3px;background:var(--accent)}

.container{max-width:760px;margin:0 auto;padding:36px 20px 80px;position:relative;z-index:1}

.required-note{font-size:12px;color:var(--muted);margin-bottom:20px}
.required-note span{color:var(--accent)}

.section{display:none;animation:fadeIn .32s ease}
.section.active{display:block}

@keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

.section-header{
  margin-bottom:24px;
  padding-bottom:16px;
  border-bottom:1px solid var(--border);
  display:flex;
  align-items:flex-start;
  gap:14px
}

.section-num{
  width:34px;
  height:34px;
  border-radius:8px;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:13px;
  font-weight:700;
  flex-shrink:0;
  margin-top:2px;
  color:#fff
}

.section-title{font-size:20px;font-weight:600;font-style:italic;color:var(--text);margin-bottom:3px;letter-spacing:-.01em}
.section-desc{font-size:13px;color:var(--muted)}

.card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:var(--radius);
  padding:22px;
  margin-bottom:14px;
  transition:border-color var(--tr)
}

.card:focus-within{border-color:rgba(0,102,255,.4)}

.field{margin-bottom:20px}
.field:last-child{margin-bottom:0}

.field-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}

@media(max-width:520px){
  .field-row{grid-template-columns:1fr}
  #phoneRow{grid-template-columns:1fr !important}
}

label{
  display:block;
  font-size:12px;
  font-weight:600;
  color:var(--muted);
  margin-bottom:7px;
  letter-spacing:.06em;
  text-transform:uppercase
}

label .req{color:var(--accent);margin-left:2px}

input[type=text],input[type=email],input[type=tel],input[type=number],input[type=date],input[type=url],select,textarea{
  width:100%;
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:7px;
  color:var(--text);
  font-family:'Jost',sans-serif;
  font-size:14px;
  padding:10px 13px;
  outline:none;
  transition:border-color var(--tr),box-shadow var(--tr);
  appearance:none
}

input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,102,255,.14)}

textarea{min-height:90px;resize:vertical}

select{
  cursor:pointer;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%235a7899' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
  background-repeat:no-repeat;
  background-position:right 12px center;
  padding-right:32px
}

select option{background:#fff;color:#0a1e38}

.options-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(154px,1fr));gap:9px}
.options-grid.cols2{grid-template-columns:repeat(auto-fill,minmax(196px,1fr))}

.opt-label{
  display:flex;
  align-items:center;
  gap:10px;
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:7px;
  padding:9px 13px;
  cursor:pointer;
  transition:border-color var(--tr),background var(--tr);
  font-size:13px;
  color:var(--text);
  user-select:none
}

.opt-label:hover{border-color:rgba(0,102,255,.4);background:rgba(0,102,255,.05)}
.opt-label input[type=radio],.opt-label input[type=checkbox]{width:15px;height:15px;accent-color:var(--accent);flex-shrink:0;cursor:pointer}
.opt-label:has(input:checked){border-color:var(--accent);background:rgba(0,102,255,.08)}
input[type=radio]:checked+span,input[type=checkbox]:checked+span{color:var(--accent);font-weight:500}

.info-box{
  background:rgba(0,102,255,.07);
  border:1px solid rgba(0,102,255,.2);
  border-radius:7px;
  padding:13px 15px;
  font-size:13px;
  color:rgba(0,70,200,.9);
  margin-bottom:18px;
  display:flex;
  gap:10px;
  align-items:flex-start
}

.field-hint{font-size:12px;color:var(--muted);margin-top:5px}

.input-invalid{border-color:var(--error)!important;box-shadow:0 0 0 3px rgba(214,40,40,.12)!important}

.val-banner{
  display:none;
  background:rgba(214,40,40,.08);
  border:1px solid rgba(214,40,40,.3);
  border-radius:8px;
  padding:14px 16px;
  margin-bottom:20px;
  animation:fadeIn .22s ease
}

.val-banner.show{display:block}

.val-banner-title{
  font-size:13px;
  font-weight:700;
  color:var(--error);
  margin-bottom:6px;
  display:flex;
  align-items:center;
  gap:7px
}

.val-banner ul{padding-left:18px}
.val-banner ul li{font-size:13px;color:var(--text);margin-bottom:3px}

.nav-bar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-top:24px;
  gap:12px
}

.btn{
  padding:11px 28px;
  border-radius:7px;
  border:none;
  font-family:'Jost',sans-serif;
  font-size:14px;
  font-weight:600;
  cursor:pointer;
  transition:all var(--tr);
  display:inline-flex;
  align-items:center;
  gap:7px
}

.btn-primary{
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  color:#fff;
  box-shadow:0 4px 14px rgba(0,102,255,.3)
}

.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(0,102,255,.4)}

.btn-ghost{background:var(--surface);border:1px solid var(--border);color:var(--text)}
.btn-ghost:hover{border-color:var(--accent);color:var(--accent)}

.btn-success{
  background:linear-gradient(135deg,#059669,#10B981);
  color:#fff;
  box-shadow:0 4px 14px rgba(16,185,129,.3)
}

.btn-success:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(16,185,129,.4)}

.file-upload-area{
  border:2px dashed var(--border);
  border-radius:var(--radius);
  padding:28px 20px;
  text-align:center;
  cursor:pointer;
  transition:all var(--tr);
  position:relative
}

.file-upload-area:hover{border-color:var(--accent);background:rgba(0,102,255,.03)}
.file-upload-area input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}

.upload-title{font-size:14px;font-weight:600;color:var(--text);margin:10px 0 4px}
.upload-sub{font-size:12px;color:var(--muted)}

.file-name{font-size:12px;color:var(--success);margin-top:6px;font-weight:600;display:none}

.submit-overlay{
  display:none;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.6);
  z-index:999;
  align-items:center;
  justify-content:center;
  backdrop-filter:blur(8px)
}

.submit-overlay.active{display:flex}

.submit-spinner{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:14px;
  padding:36px 48px;
  text-align:center
}

.spinner-ring{
  width:44px;
  height:44px;
  border:3px solid var(--border);
  border-top-color:var(--accent);
  border-radius:50%;
  animation:spin .75s linear infinite;
  margin:0 auto 14px
}

@keyframes spin{to{transform:rotate(360deg)}}

.thankyou{display:none;text-align:center;padding:80px 20px;animation:fadeIn .5s ease}
.thankyou.active{display:block}

.checkmark{
  width:70px;
  height:70px;
  border-radius:50%;
  background:rgba(0,153,90,.12);
  border:2px solid var(--success);
  display:flex;
  align-items:center;
  justify-content:center;
  margin:0 auto 22px
}

.thankyou h2{font-size:28px;font-weight:600;font-style:italic;margin-bottom:10px}
.thankyou p{color:var(--muted);max-width:420px;margin:0 auto 22px}

.ai-link{
  display:inline-flex;
  align-items:center;
  gap:8px;
  background:rgba(255,149,0,.1);
  border:1px solid rgba(255,149,0,.3);
  color:var(--gold);
  padding:11px 24px;
  border-radius:7px;
  text-decoration:none;
  font-weight:600;
  font-size:14px;
  transition:all var(--tr)
}

.ai-link:hover{background:rgba(255,149,0,.18)}

#jdContent p{margin-bottom:10px}
#jdContent ul{padding-left:18px;margin:6px 0 10px}
#jdContent li{margin-bottom:4px;font-size:13px}

.theme-toggle{
  position:absolute;
  top:16px;
  right:24px;
  background:var(--surface);
  border:1px solid var(--border);
  color:var(--text);
  width:40px;height:40px;
  border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;z-index:200;
  transition:all var(--tr)
}
.theme-toggle:hover{border-color:var(--accent);color:var(--accent)}
.icon-moon{display:none}
[data-theme="light"] .icon-moon{display:block}
[data-theme="light"] .icon-sun{display:none}
#jdContent p{margin-bottom:12px}
#jdContent p:last-of-type{margin-bottom:0}
#jdContent ul{padding-left:20px;margin-top:8px;margin-bottom:12px}
#jdContent li{margin-bottom:5px;font-size:13px}
.jd-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin:14px 0}
.jd-card{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:8px;
  padding:14px 16px;
  cursor:pointer;
  transition:border-color var(--tr),background var(--tr);
  text-align:center
}
.jd-card:hover{border-color:var(--accent);background:rgba(79,124,255,.07)}
.jd-card.active{border-color:var(--accent);background:rgba(79,124,255,.12)}
.jd-card-icon{font-size:22px;margin-bottom:6px}
.jd-card-title{font-size:13px;font-weight:600;color:var(--text)}
.jd-card-sub{font-size:11px;color:var(--muted);margin-top:2px}
  </style>
</head>
<body>

<!-- Submit Spinner Overlay -->
<div class="submit-overlay" id="submitOverlay">
  <div class="submit-spinner">
    <div class="spinner-ring"></div>
    <p>Submitting your application...<br><span style="font-size:11px;opacity:.7">This may take a moment</span></p>
  </div>
</div>

<!-- Header -->
<div class="header">
  <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle Theme">
    <svg class="icon-sun" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="5"/><path d="M12 1v2m0 18v2M4.22 4.22l1.42 1.42m12.72 12.72l1.42 1.42M1 12h2m18 0h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
    <svg class="icon-moon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="20" height="20"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
  </button>
  <div class="logo-wrap">
    <img src="<?=htmlspecialchars($org_logo)?>" alt="<?=htmlspecialchars($org_name)?>" onerror="this.style.display='none'">
  </div>
  <div class="logo-badge"><div class="logo-dot"></div> <?=htmlspecialchars($org_name)?></div>
  <h1>Candidate <em>Application</em> Form</h1>
  <p class="header-sub">Apply for: <strong><?=htmlspecialchars($job_role)?></strong> &nbsp;·&nbsp; Please complete all sections carefully.</p>
</div>

<!-- Progress Bar -->
<div class="progress-wrap">
  <div class="step-dots" id="stepDots"></div>
  <div class="progress-bar-bg"><div class="progress-bar-fill" id="progressBar"></div></div>
  <div class="progress-label" id="progressLabel">Step 1 / 9</div>
</div>

<div class="container">
  <p class="required-note">Fields marked <span>*</span> are required.</p>

  <!-- ═══ SECTION 1: Personal Information ═══ -->
  <div class="section active" id="section-1">
    <div class="section-header">
      <div class="section-num">1</div>
      <div>
        <div class="section-title">Personal Information</div>
        <div class="section-desc">Tell us a little about yourself.</div>
      </div>
    </div>
    <div id="val-banner-1" class="val-banner"></div>
    <div class="card">
      <div class="field">
        <label for="salutation">Salutation <span class="req">*</span></label>
        <select id="salutation">
          <option value="">Select salutation</option>
          <option>Mr.</option>
          <option>Ms.</option>
          <option>Mrs.</option>
          <option>Dr.</option>
        </select>
      </div>
      <div class="field-row">
        <div class="field"><label for="firstName">First Name <span class="req">*</span></label><input type="text" id="firstName" placeholder="First name" oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')"></div>
        <div class="field"><label for="lastName">Last Name <span class="req">*</span></label><input type="text" id="lastName" placeholder="Last name" oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')"></div>
      </div>
      <div class="field"><label for="dob">Date of Birth <span class="req">*</span></label><input type="date" id="dob"></div>
      <div class="field-row" style="align-items:start">
        <div class="field" style="margin-bottom:0"><label for="currentCity">Current City <span class="req">*</span></label><input type="text" id="currentCity" placeholder="Your city" oninput="handleCityChange()"></div>
        <div class="field" id="relocateCol" style="display:none;margin-bottom:0"><label for="relocate">Comfortable to Relocate? <span class="req">*</span></label><select id="relocate" onchange="handleRelocateChange()"><option value="">Select</option><option>Yes</option><option>No</option></select></div>
      </div>
      <div class="field" id="relocateTimeRow" style="display:none;margin-top:20px">
        <label for="relocateTime">Relocation Time <span class="req">*</span></label>
        <select id="relocateTime"><option value="">Select</option><option>Immediate</option><option>Within 15 days</option><option>Within 1 month</option><option>Within 3 months</option><option>More than 3 months</option></select>
      </div>
      <div id="phoneRow" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;margin-top:20px">
        <div class="field" style="margin-bottom:0">
          <label for="phoneCode">Phone Code <span class="req">*</span></label>
          <select id="phoneCode" onchange="handlePhoneCodeChange()">
            <option value="+91">+91 (India)</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="field" id="otherCountryCol" style="display:none;margin-bottom:0">
          <label for="otherCountryCode">Country <span class="req">*</span></label>
          <select id="otherCountryCode" onchange="handleOtherCountryChange()">
            <option value="">Select country</option>
            <option value="+1:10:10">+1 (USA / Canada)</option>
            <option value="+44:10:10">+44 (United Kingdom)</option>
            <option value="+49:10:11">+49 (Germany)</option>
            <option value="+33:9:9">+33 (France)</option>
            <option value="+966:9:9">+966 (Saudi Arabia)</option>
            <option value="+81:10:11">+81 (Japan)</option>
            <option value="+55:10:11">+55 (Brazil)</option>
            <option value="+27:9:9">+27 (South Africa)</option>
            <option value="+94:9:9">+94 (Sri Lanka)</option>
            <option value="+60:9:10">+60 (Malaysia)</option>
            <option value="+44:10:10">+44 (UK)</option>
            <option value="+61:9:9">+61 (Australia)</option>
            <option value="+971:9:9">+971 (UAE)</option>
            <option value="+65:8:8">+65 (Singapore)</option>
            <option value="+92:10:10">+92 (Pakistan)</option>
            <option value="+880:10:10">+880 (Bangladesh)</option>
            <option value="+977:9:10">+977 (Nepal)</option>
          </select>
        </div>
        <div class="field" id="phoneNumberCol" style="margin-bottom:0">
          <label for="phone">Phone Number <span class="req">*</span></label>
          <input type="tel" id="phone" placeholder="10-digit number" maxlength="10">
          <p class="field-hint" id="phoneHint">Must be exactly 10 digits for India (+91).</p>
          <p class="field-hint" id="otherCountryHint" style="display:none"></p>
        </div>
      </div>
      <div class="field" style="margin-top:20px"><label for="email">Email ID <span class="req">*</span></label><input type="email" id="email" placeholder="you@example.com"></div>
      <div class="field">
        <label for="college">College / University <span class="req">*</span></label>
        <select id="college" onchange="handleCollegeChange()">
          <option value="">Select institution</option>
          <option>University of Rajasthan</option>
          <option>JECRC University</option>
          <option>Manipal University Jaipur</option>
          <option>Amity University Jaipur</option>
          <option>Poornima University</option>
          <option>IIS University</option>
          <option>MNIT Jaipur</option>
          <option>Jaipur National University</option>
          <option>NIMS University</option>
          <option>Arya College</option>
          <option value="Other – specify">Other – specify</option>
        </select>
      </div>
      <div class="field" id="collegeOtherField" style="display:none"><label for="collegeOther">Specify College <span class="req">*</span></label><input type="text" id="collegeOther" placeholder="Full college/university name"></div>
      <div class="field">
        <label for="source">How did you hear about us? <span class="req">*</span></label>
        <select id="source" onchange="handleSourceChange()">
          <option value="">Select source</option>
          <option>Direct Website</option>
          <option>LinkedIn</option>
          <option>Internshala</option>
          <option>Naukri.com</option>
          <option>Monster.com</option>
          <option>Dice.com</option>
          <option>Indeed.com</option>
          <option>WorkIndia</option>
          <option value="Other – specify">Other – specify</option>
        </select>
      </div>
      <div class="field" id="sourceOtherField" style="display:none"><label for="sourceOther">Please specify <span class="req">*</span></label><input type="text" id="sourceOther" placeholder="Where did you hear about us?"></div>
    </div>
    <div class="nav-bar"><div></div><button class="btn btn-primary" onclick="nextSection(1)">Continue →</button></div>
  </div>

  <!-- ═══ SECTION 2: Role Selection ═══ -->
  <div class="section" id="section-2">
    <div class="section-header">
      <div class="section-num">2</div>
      <div>
        <div class="section-title">Role Selection</div>
        <div class="section-desc">Select the role and engagement type you're applying for.</div>
      </div>
    </div>
    <div id="val-banner-2" class="val-banner"></div>
    <?php if($job_desc):?>
    <div class="info-box"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><div><?=nl2br(htmlspecialchars($job_desc))?></div></div>
    <?php endif;?>
    <div class="info-box">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <a href="https://www.dialerindia.com/career" target="_blank" style="color:var(--accent);font-weight:600;text-decoration:underline;">Particular Job role training material ↗</a>
    </div>

    <div class="card">
      <div class="field">
        <label for="campaignSelect">Campaign / Job Opening <span class="req">*</span></label>
        <select id="campaignSelect" onchange="updateCampaign(this.value)">
          <option value="">Select Campaign</option>
          <?php foreach($all_campaigns as $ac): ?>
          <option value="<?=$ac['id']?>" data-role="<?=htmlspecialchars($ac['job_role']??$ac['name'])?>" <?=$campaign_id==$ac['id']?'selected':''?>>
            <?=htmlspecialchars($ac['name'])?> — <?=htmlspecialchars($ac['job_role']??'')?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Default JD cards — shown when no campaign selected, clickable to select role -->
      <div id="defaultJdCards" style="margin:10px 0 16px;">
        <p style="font-size:12px;color:var(--muted);margin-bottom:10px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;">Available Positions</p>
        <div class="jd-cards-grid">
          <div class="jd-card" onclick="selectDefaultRole('AI')" id="jdcard-AI">
            <div class="jd-card-icon">🤖</div>
            <div class="jd-card-title">AI / ML</div>
            <div class="jd-card-sub">AI Automation Role</div>
          </div>
          <div class="jd-card" onclick="selectDefaultRole('Sales')" id="jdcard-Sales">
            <div class="jd-card-icon">💼</div>
            <div class="jd-card-title">Sales</div>
            <div class="jd-card-sub">Software Sales Executive</div>
          </div>
          <div class="jd-card" onclick="selectDefaultRole('PHP & Developer')" id="jdcard-PHP">
            <div class="jd-card-icon">💻</div>
            <div class="jd-card-title">PHP Developer</div>
            <div class="jd-card-sub">Software Engineer</div>
          </div>
          <div class="jd-card" onclick="selectDefaultRole('Support Engineer')" id="jdcard-Support">
            <div class="jd-card-icon">🔧</div>
            <div class="jd-card-title">Support Engineer</div>
            <div class="jd-card-sub">Dialer / GSM / DevOps</div>
          </div>
        </div>
      </div>

      <div class="field-row">
        <div class="field">
          <label for="roleApplied">Role <span class="req">*</span></label>
          <select id="roleApplied" onchange="updateJD(this.value); highlightJdCard(this.value)">
            <option value="">Select Role</option>
            <?php foreach($all_campaigns as $ac):
              $r = htmlspecialchars($ac['job_role']??$ac['name']);
            ?>
            <option value="<?=$r?>" <?=($job_role===$r)?'selected':''?>><?=$r?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="engagementType">Engagement Type <span class="req">*</span></label>
          <select id="engagementType" onchange="updateRemuneration(this.value)">
            <option value="">Select Engagement Type</option>
            <option value="Paid Training">Paid Training</option>
            <option value="Unpaid Internship">Unpaid Internship</option>
            <option value="Paid Internship">Paid Internship</option>
            <option value="Employment">Employment</option>
          </select>
        </div>
      </div>
      <div id="jdBox" style="display:none;margin-top:15px;margin-bottom:15px;padding:18px;border-radius:7px;background:color-mix(in srgb,var(--surface) 50%,transparent);border:1px solid var(--border);">
        <div id="jdContent" style="font-size:13px;color:var(--text);"></div>
      </div>
      <div id="remunerationBox" class="info-box" style="display:none;margin-top:15px;margin-bottom:0">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        <div><strong style="font-size:14px;margin-bottom:4px;display:block">Remuneration</strong><span id="remunerationText"></span></div>
      </div>
    </div>
    <div class="nav-bar"><button class="btn btn-ghost" onclick="prevSection(2)">← Back</button><button class="btn btn-primary" onclick="nextSection(2)">Continue →</button></div>
  </div>

  <!-- ═══ SECTION 3: Experience & Skills ═══ -->
  <div class="section" id="section-3">
    <div class="section-header">
      <div class="section-num">3</div>
      <div>
        <div class="section-title">General Experience & Skills</div>
        <div class="section-desc">Your background, communication, and professional assessment.</div>
      </div>
    </div>
    <div id="val-banner-3" class="val-banner"></div>
    <div class="card">
      <div class="field-row">
        <div class="field">
          <label for="englishLevel">English Communication <span class="req">*</span></label>
          <select id="englishLevel">
            <option value="">Select Level</option>
            <option value="1">1 - Basic</option>
            <option value="2">2 - Fair</option>
            <option value="3">3 - Good</option>
            <option value="4">4 - Very Good</option>
            <option value="5">5 - Fluent / Native</option>
          </select>
        </div>
        <div class="field">
          <label for="yearsExp">Years of Experience <span class="req">*</span></label>
          <select id="yearsExp" onchange="handleYearsExpChange()">
            <option value="">Select Experience</option>
            <option value="Fresher">Fresher</option>
            <option value="0.5 Years">0.5 Years</option>
            <option value="1–2 Years">1–2 Years</option>
            <option value="2–5 Years">2–5 Years</option>
            <option value="5-7 Years">5–7 Years</option>
            <option value="7-10 Years">7–10 Years</option>
            <option value="10-15 Years">10–15 Years</option>
            <option value="15+ Years">15+ Years</option>
          </select>
        </div>
      </div>
      <div class="field-row" id="industryFieldContainer">
        <div class="field">
          <label for="industry">Industry Background <span class="req">*</span></label>
          <select id="industry" onchange="handleIndustryChange()">
            <option value="">Select Industry</option>
            <option id="industryFresherOpt" value="Fresher / None">Fresher / None</option>
            <option value="IT/Software">IT / Software</option>
            <option value="Telecom">Telecom</option>
            <option value="Sales/Marketing">Sales / Marketing</option>
            <option value="Customer Support">Customer Support</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="field" id="industryOtherField" style="display:none">
          <label for="industryOther">Specify Industry <span class="req">*</span></label>
          <input type="text" id="industryOther" placeholder="Your industry">
        </div>
      </div>
      <div class="field" id="expTypeFieldContainer">
        <label>Experience Type <span class="req">*</span></label>
        <div class="options-grid cols2">
          <label class="opt-label" id="expTypeFresherLabel"><input type="radio" name="expType" value="Fresher / None"><span>Fresher / None</span></label>
          <label class="opt-label"><input type="radio" name="expType" value="Full-time"><span>Full-time Employment</span></label>
          <label class="opt-label"><input type="radio" name="expType" value="Freelance"><span>Part-time / Freelance</span></label>
          <label class="opt-label"><input type="radio" name="expType" value="Internship"><span>Internship</span></label>
          <label class="opt-label"><input type="radio" name="expType" value="Academic Project"><span>Research / Academic</span></label>
          <label class="opt-label"><input type="radio" name="expType" value="Full-time"><span>Entrepreneurial / Startup</span></label>
        </div>
      </div>
      <div class="field" id="internshipDescContainer">
        <label for="internshipDesc">Describe Your Past Experience (If Any)</label>
        <textarea id="internshipDesc" placeholder="Briefly describe any relevant internship or project experience (Max 50 words)..." oninput="limitWords(this, 50)"></textarea>
        <p class="field-hint" id="wordCountHint">0 / 50 words</p>
      </div>
    </div>
    <div class="nav-bar"><button class="btn btn-ghost" onclick="prevSection(3)">← Back</button><button class="btn btn-primary" onclick="nextSection(3)">Continue →</button></div>
  </div>

  <!-- ═══ SECTION 4: Compensation ═══ -->
  <div class="section" id="section-4">
    <div class="section-header">
      <div class="section-num">4</div>
      <div>
        <div class="section-title">Compensation</div>
        <div class="section-desc">Your current and expected remuneration details.</div>
      </div>
    </div>
    <div id="val-banner-4" class="val-banner"></div>
    <div class="card">
      <div class="field-row">
        <div class="field">
          <label for="currentSalary">Current Salary / Stipend (Per Month)</label>
          <input type="text" id="currentSalary" placeholder="e.g. ₹15,000/month or N/A">
        </div>
        <div class="field">
          <label for="expectedSalary">Expected Salary / Stipend (Per Month) <span class="req">*</span></label>
          <input type="text" id="expectedSalary" placeholder="Mention realistic figures (in ₹)">
        </div>
      </div>
    </div>
    <div class="nav-bar"><button class="btn btn-ghost" onclick="prevSection(4)">← Back</button><button class="btn btn-primary" onclick="nextSection(4)">Continue →</button></div>
  </div>

  <!-- ═══ SECTION 5: Work Preferences ═══ -->
  <div class="section" id="section-5">
    <div class="section-header">
      <div class="section-num">5</div>
      <div>
        <div class="section-title">Internship & Availability</div>
        <div class="section-desc">Your schedule preferences and joining details.</div>
      </div>
    </div>
    <div id="val-banner-5" class="val-banner"></div>
    <div class="card">
      <div class="field" id="tenureField">
        <label for="tenure">Internship / Training Tenure <span class="req">*</span></label>
        <select id="tenure">
          <option value="">Select Tenure</option>
          <option value="6 months">6 Months</option>
          <option value="9 months">9 Months</option>
          <option value="12 months">12 Months</option>
          <option value="18 months">18 Months</option>
          <option value="24 months">24 Months</option>
        </select>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="joiningDate">Preferred Joining Date <span class="req">*</span></label>
          <input type="date" id="joiningDate">
        </div>
        <div class="field">
          <label for="flexHours">Open to Flexible Hours? <span class="req">*</span></label>
          <select id="flexHours">
            <option value="">Select Option</option>
            <option value="Yes">Yes</option>
            <option value="No">No</option>
          </select>
        </div>
      </div>
    </div>
    <div class="nav-bar"><button class="btn btn-ghost" onclick="prevSection(5)">← Back</button><button class="btn btn-primary" onclick="nextSection(5)">Continue →</button></div>
  </div>

  <!-- ═══ SECTION 6: Work Readiness ═══ -->
  <div class="section" id="section-6">
    <div class="section-header">
      <div class="section-num">6</div>
      <div>
        <div class="section-title">Work Readiness</div>
        <div class="section-desc">Confirm your technical and logistical readiness.</div>
      </div>
    </div>
    <div id="val-banner-6" class="val-banner"></div>
    <div class="card">
      <div class="field-row">
        <div class="field">
          <label for="laptop">Do you own a Laptop? <span class="req">*</span></label>
          <select id="laptop">
            <option value="">Select Option</option>
            <option value="Yes">Yes</option>
            <option value="No">No</option>
          </select>
        </div>
        <div class="field">
          <label for="internet">Reliable Broadband / Wi-Fi at Home? <span class="req">*</span></label>
          <select id="internet">
            <option value="">Select Option</option>
            <option value="Yes">Yes</option>
            <option value="No">No</option>
          </select>
        </div>
      </div>
      <div class="field">
        <label for="candidateLocation">Check Commute Distance <span style="color:var(--muted);font-size:11px;font-weight:400;">(Optional)</span></label>
        <div style="display:flex;gap:10px;margin-bottom:8px;">
          <input type="text" id="candidateLocation" placeholder="Enter your area/city (e.g. Vaishali Nagar, Jaipur)" style="flex:1;">
          <button type="button" class="btn btn-ghost" onclick="checkDistance()" style="padding:10px 15px;white-space:nowrap;height:42px;">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            Check Maps
          </button>
        </div>
        <p class="field-hint" style="margin-top:0;font-size:13px;">📍 Office: <a href="https://maps.google.com/?q=Avyukta+Intellicall,+Narayan+Vihar+Rd,+Ganatpura,+Jaipur" target="_blank" style="color:var(--accent);font-weight:500;text-decoration:none;">Avyukta Intellicall, Narayan Vihar Rd, Ganatpura, Jaipur</a></p>
      </div>
      <div class="field">
        <label for="commute">Commute to Office <span class="req">*</span></label>
        <select id="commute">
          <option value="">Select Option</option>
          <option value="Personal vehicle">Personal Vehicle</option>
          <option value="Self-managed">I will manage on my own</option>
        </select>
      </div>
    </div>
    <div class="nav-bar"><button class="btn btn-ghost" onclick="prevSection(6)">← Back</button><button class="btn btn-primary" onclick="nextSection(6)">Continue →</button></div>
  </div>

  <!-- ═══ SECTION 7: Documents ═══ -->
  <div class="section" id="section-7">
    <div class="section-header">
      <div class="section-num">7</div>
      <div>
        <div class="section-title">Documents & Portfolio</div>
        <div class="section-desc">Upload your resume and optional video introduction.</div>
      </div>
    </div>
    <div id="val-banner-7" class="val-banner"></div>
    <div class="card">
      <div class="field">
        <label>Resume / CV <span class="req">*</span></label>
        <div class="file-upload-area" onclick="document.getElementById('resumeFile').click()">
          <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
          <div class="upload-title">Click to upload Resume / CV</div>
          <div class="upload-sub">PDF or DOCX only · Max 10 MB</div>
          <div class="file-name" id="resumeFileName"></div>
          <input type="file" id="resumeFile" accept=".pdf,.docx" onchange="showFileName('resumeFile','resumeFileName')">
        </div>
      </div>
      <div class="field">
        <label for="videoOption">Video Introduction <span style="color:var(--muted);font-size:11px;font-weight:400">(Optional)</span></label>
        <select id="videoOption" onchange="toggleVideoInput()">
          <option value="none">Skip (Optional)</option>
          <option value="link">Provide a Video Link (YouTube, Drive, etc.)</option>
          <option value="upload">Upload a Video File</option>
        </select>
      </div>
      <div class="field" id="videoLinkDiv" style="display:none"><label for="videoLinkInput">Video URL</label><input type="url" id="videoLinkInput" placeholder="https://..."></div>
      <div class="field" id="videoUploadDiv" style="display:none">
        <div class="file-upload-area" onclick="document.getElementById('videoFile').click()">
          <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><rect x="2" y="7" width="15" height="10" rx="2"/><polygon points="17 9 22 6 22 18 17 15"/></svg>
          <div class="upload-title">Click to upload Video</div>
          <div class="upload-sub">MP4, MOV or AVI · Max 15 MB</div>
          <div class="file-name" id="videoFileName"></div>
          <input type="file" id="videoFile" accept=".mp4,.mov,.avi" onchange="showFileName('videoFile','videoFileName')">
        </div>
      </div>
      <div class="field"><label for="portfolioLinks">Portfolio / Project Links</label><input type="url" id="portfolioLinks" placeholder="GitHub, LinkedIn, or personal website URL"><p class="field-hint">Separate multiple URLs with a comma.</p></div>
    </div>
    <div class="nav-bar"><button class="btn btn-ghost" onclick="prevSection(7)">← Back</button><button class="btn btn-primary" onclick="nextSection(7)">Continue →</button></div>
  </div>

  <!-- ═══ SECTION 8: AI Test ═══ -->
  <div class="section" id="section-8">
    <div class="section-header">
      <div class="section-num">8</div>
      <div>
        <div class="section-title">AI Test Section</div>
        <div class="section-desc">Consent for the AI aptitude test.</div>
      </div>
    </div>
    <div id="val-banner-8" class="val-banner"></div>
    <div class="card">
      <div class="field">
        <label for="aiTestWilling">Willing to Take the AI Test? <span class="req">*</span></label>
        <select id="aiTestWilling">
          <option value="">Select Option</option>
          <option value="Yes">Yes</option>
          <option value="No">No</option>
        </select>
      </div>
    </div>
    <div class="nav-bar"><button class="btn btn-ghost" onclick="prevSection(8)">← Back</button><button class="btn btn-primary" onclick="nextSection(8)">Continue →</button></div>
  </div>

  <!-- ═══ SECTION 9: Declaration ═══ -->
  <div class="section" id="section-9">
    <div class="section-header">
      <div class="section-num">9</div>
      <div>
        <div class="section-title">Declaration</div>
        <div class="section-desc">Please review and confirm your submission.</div>
      </div>
    </div>
    <div id="val-banner-9" class="val-banner"></div>
    <div class="card">
      <label class="opt-label" style="border-color:rgba(0,153,90,.3);background:rgba(0,153,90,.05);padding:16px;align-items:flex-start">
        <input type="checkbox" id="declaration">
        <span style="color:var(--success);font-size:14px;line-height:1.6;font-weight:400">I confirm that the information provided is true and accurate to the best of my knowledge. I understand that any false or misleading information may result in disqualification.</span>
      </label>
    </div>
    <div class="nav-bar"><button class="btn btn-ghost" onclick="prevSection(9)">← Back</button><button class="btn btn-success" onclick="submitForm()">Submit Application ✓</button></div>
  </div>

  <!-- ═══ THANK YOU ═══ -->
  <div class="thankyou" id="thankyou">
    <div class="checkmark">
      <svg width="34" height="34" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <h2>Application Submitted! 🎉</h2>
    <p>Thank you for applying to <?=htmlspecialchars($org_name)?>. Our team will review your application and contact you shortly.</p>
    <a href="#" id="aiInterviewLink" class="ai-link" style="display:none">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
      Begin AI Interview Test
    </a>
  </div>
</div><!-- container -->

<script>
const TOTAL = 9;
let currentSection = 1;
const CAMPAIGN_ID = <?=$campaign_id?>;
const REF_TOKEN = <?= json_encode($ref_token) ?>;
const INTERVIEW_URL_PUBLIC = <?= json_encode(defined('INTERVIEW_URL') ? INTERVIEW_URL : '/interview.php') ?>;

function gotoSection(id) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  currentSection = parseInt(id.replace('section-', ''));
  updateProgress();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function nextSection(cur) {
  const e = (validators[cur] || (() => []))();
  showBanner('val-banner-' + cur, e);
  if (!e.length) gotoSection('section-' + (cur + 1));
}

function prevSection(cur) {
  gotoSection('section-' + (cur - 1));
}

function updateProgress() {
  const pct = Math.round((currentSection / TOTAL) * 100);
  document.getElementById('progressBar').style.width = pct + '%';
  document.getElementById('progressLabel').textContent = `Step ${currentSection} / ${TOTAL}`;
  
  const dots = document.getElementById('stepDots');
  dots.innerHTML = '';
  for (let i = 1; i <= TOTAL; i++) {
    const d = document.createElement('div');
    d.className = 'step-dot' + (i < currentSection ? ' done' : '') + (i === currentSection ? ' current' : '');
    dots.appendChild(d);
  }
}

function showBanner(id, errs) {
  const b = document.getElementById(id);
  if (!errs.length) {
    b.classList.remove('show');
    b.innerHTML = '';
    return;
  }
  b.innerHTML = '<div class="val-banner-title"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Please fix the following:</div><ul>' + errs.map(e => `<li>${e}</li>`).join('') + '</ul>';
  b.classList.add('show');
  b.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function v(id) {
  return (document.getElementById(id) || {}).value || '';
}

function radio(name) {
  const c = document.querySelector(`input[name="${name}"]:checked`);
  return c ? c.value : '';
}

function checks(name) {
  return [...document.querySelectorAll(`input[name="${name}"]:checked`)].map(e => e.value).join(', ');
}

// Campaign selector
function updateCampaign(id) {
  window._selectedCampaignId = parseInt(id) || 0;
  const sel = document.getElementById('campaignSelect');
  const opt = sel.options[sel.selectedIndex];
  const role = opt ? opt.dataset.role : '';
  const roleEl = document.getElementById('roleApplied');
  if (role && roleEl) {
    for (let o of roleEl.options) {
      if (o.value === role || o.text === role) { roleEl.value = o.value; return; }
    }
    // Only add if genuinely not in list (avoid duplicates)
    const exists = [...roleEl.options].some(o => o.value === role);
    if (!exists) { const newOpt = new Option(role, role, true, true); roleEl.add(newOpt); }
    updateJD(role);
    highlightJdCard(role);
  }
}

// Validators
const validators = {
  1: () => {
    const e = [];
    if (!v('salutation')) e.push('Salutation required');
    if (!v('firstName').trim()) e.push('First name required');
    if (!v('lastName').trim()) e.push('Last name required');
    const dobVal = v('dob');
    if (!dobVal) { e.push('Date of birth required.'); }
    else { const _t = new Date(); _t.setHours(0,0,0,0); if (new Date(dobVal) > _t) e.push('Date of birth cannot be in the future.'); }
    if (!v('currentCity').trim()) e.push('Current city required');
    
    const pe = validatePhone(); if (pe) e.push(pe);
    
    if (!v('email').match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) e.push('Valid email required');
    if (!v('college')) e.push('College/University required');
    if (v('college') === 'Other – specify' && !v('collegeOther').trim()) e.push('Specify your college');
    if (!v('source')) e.push('Application source required');
    return e;
  },
  
  2: () => {
    const e = [];
    if (!v('campaignSelect')) e.push('Please select a campaign/job opening');
    if (!v('roleApplied')) e.push('Role required');
    if (!v('engagementType')) e.push('Engagement type required');
    return e;
  },
  
  3: () => {
    const e = [];
    if (!v('englishLevel')) e.push('English level required');
    if (!v('yearsExp')) e.push('Years of experience required');
    const isFresher = v('yearsExp') === 'Fresher';
    if (!isFresher && !v('industry')) e.push('Industry background required');
    if (!isFresher && !checks('expType')) e.push('Select at least one experience type');
    if (isFresher) {
      // auto-set for fresher
      const ind = document.getElementById('industry');
      if (ind) ind.value = 'Fresher / None';
    }
    return e;
  },
  
  4: () => {
    const e = [];
    if (!v('expectedSalary').trim()) e.push('Expected salary / stipend is required.');
    return e;
  },
  
  5: () => {
    const e = [];
    const et = v('engagementType');
    if (et !== 'Employment' && !v('tenure')) e.push('Internship / training tenure is required.');
    if (!v('joiningDate')) e.push('Preferred joining date is required.');
    if (!v('flexHours')) e.push('Flexible hours preference is required.');
    return e;
  },
  
  6: () => {
    const e = [];
    if (!v('laptop')) e.push('Laptop ownership is required.');
    if (!v('internet')) e.push('Internet availability is required.');
    if (!v('commute')) e.push('Commute preference is required.');
    return e;
  },
  
  7: () => {
    const e = [];
    if (!document.getElementById('resumeFile').files.length) { e.push('Please upload your Resume / CV.'); } else if (!checkFileSize('resumeFile', 10)) { e.push('Resume file size must be less than 10 MB.'); }
    const vo = v('videoOption');
    if (vo === 'link' && !v('videoLinkInput').trim()) e.push('Video URL required');
    return e;
  },
  
  8: () => {
    const e = [];
    if (!v('aiTestWilling')) e.push('Please indicate AI test willingness');
    return e;
  },
  
  9: () => document.getElementById('declaration').checked ? [] : ['Please confirm the declaration']
};

// Remuneration
function updateRemuneration(type) {
  const box = document.getElementById('remunerationBox');
  const text = document.getElementById('remunerationText');
  const tf = document.getElementById('tenureField');
  
  if (!type) {
    box.style.display = 'none';
    tf.style.display = 'block';
    return;
  }
  
  box.style.display = 'flex';
  const ben = '<br><span style="font-size:13px;color:var(--muted);margin-top:5px;display:block">✨ Additional Benefits: Accommodation and food may be provided depending on location and role.</span>';
  
  if (type === 'Employment') {
    text.innerHTML = 'Employee Salary Range: <strong>₹15,000 – ₹85,000/month</strong> (based on role and experience).' + ben;
    tf.style.display = 'none';
  } else if (type === 'Paid Training') {
    text.innerHTML = 'Paid training at <strong>₹15,000 per quarter per module</strong>. Potential for placement based on performance.' + ben;
    tf.style.display = 'block';
  } else if (type === 'Paid Internship') {
    text.innerHTML = 'Paid Internship Stipend: <strong>₹8,000 – ₹15,000/month</strong>.' + ben;
    tf.style.display = 'block';
  } else {
    text.innerHTML = 'Unpaid internship for training/learning purposes. Practical exposure without financial compensation.' + ben;
    tf.style.display = 'block';
  }
}

// Field helpers
function handleCityChange() {
  const city = document.getElementById('currentCity').value.trim().toLowerCase();
  const relocateCol = document.getElementById('relocateCol');
  const relocateSelect = document.getElementById('relocate');
  const timeRow = document.getElementById('relocateTimeRow');
  const timeSelect = document.getElementById('relocateTime');
  if (city && city !== 'jaipur') {
    relocateCol.style.display = 'block';
  } else {
    relocateCol.style.display = 'none';
    relocateSelect.value = '';
    timeRow.style.display = 'none';
    timeSelect.value = '';
  }
}

function handleRelocateChange() {
  document.getElementById('relocateTimeRow').style.display = v('relocate') === 'Yes' ? 'block' : 'none';
}

function handlePhoneCodeChange() {
  const o = v('phoneCode') === 'other';
  document.getElementById('otherCountryCol').style.display = o ? 'block' : 'none';
  document.getElementById('phoneHint').style.display = o ? 'none' : 'block';
  document.getElementById('otherCountryHint').style.display = o ? 'block' : 'none';
  document.getElementById('phone').maxLength = o ? 15 : 10;
}

function handleOtherCountryChange() {
  const sel = document.getElementById('otherCountryCode');
  const val = sel.value;
  if (!val) return;
  const [, min, max] = val.split(':');
  document.getElementById('otherCountryHint').textContent = `Enter ${min}–${max} digit number`;
  document.getElementById('phone').maxLength = parseInt(max);
}

function handleCollegeChange() {
  document.getElementById('collegeOtherField').style.display = v('college') === 'Other – specify' ? 'block' : 'none';
}

function handleSourceChange() {
  document.getElementById('sourceOtherField').style.display = v('source') === 'Other – specify' ? 'block' : 'none';
}

function handleYearsExpChange() {
  const val = document.getElementById('yearsExp').value;
  const isFresher = val === 'Fresher';
  const hasExp = val && !isFresher;
  
  // Show/hide industry + expType containers
  document.getElementById('industryFieldContainer').style.display = isFresher ? 'none' : 'grid';
  document.getElementById('expTypeFieldContainer').style.display = isFresher ? 'none' : 'block';
  
  // Hide Fresher options when candidate has experience
  const indFresherOpt = document.getElementById('industryFresherOpt');
  const expFresherLabel = document.getElementById('expTypeFresherLabel');
  if (indFresherOpt) indFresherOpt.style.display = hasExp ? 'none' : '';
  if (expFresherLabel) expFresherLabel.style.display = hasExp ? 'none' : '';
  
  if (isFresher) {
    document.getElementById('industry').value = '';
    document.querySelectorAll('input[name="expType"]').forEach(r => r.checked = false);
  } else if (hasExp) {
    // Clear fresher selection if previously chosen
    if (document.getElementById('industry').value === 'Fresher / None')
      document.getElementById('industry').value = '';
    const fresherRadio = document.querySelector('input[name="expType"][value="Fresher / None"]');
    if (fresherRadio && fresherRadio.checked) fresherRadio.checked = false;
  }
}

function handleIndustryChange() {
  document.getElementById('industryOtherField').style.display = v('industry') === 'Other' ? 'block' : 'none';
}

function toggleVideoInput() {
  const o = v('videoOption');
  document.getElementById('videoLinkDiv').style.display = o === 'link' ? 'block' : 'none';
  document.getElementById('videoUploadDiv').style.display = o === 'upload' ? 'block' : 'none';
}

function showFileName(inputId, displayId) {
  const f = document.getElementById(inputId).files[0];
  if (f) {
    const d = document.getElementById(displayId);
    d.textContent = '✓ ' + f.name;
    d.style.display = 'block';
  }
}

function getBase64(file) {
  return new Promise((res, rej) => {
    const r = new FileReader();
    r.readAsDataURL(file);
    r.onload = () => res(r.result.split(',')[1]);
    r.onerror = e => rej(e);
  });
}

// Submit
async function submitForm() {
  const errs = validators[9]();
  showBanner('val-banner-9', errs);
  if (errs.length) return;
  
  document.getElementById('submitOverlay').classList.add('active');
  
  try {
    const g = id => (document.getElementById(id) || {}).value || '';
    
    const data = {
      campaign_id: window._selectedCampaignId || CAMPAIGN_ID,
      salutation: g('salutation'),
      first_name: g('firstName'),
      last_name: g('lastName'),
      dob: g('dob'),
      city: g('currentCity'),
      relocate: g('relocate'),
      relocate_time: g('relocateTime'),
      phone_code: g('phoneCode'),
      phone: g('phone'),
      email: g('email'),
      college: v('college') === 'Other – specify' ? g('collegeOther') : g('college'),
      source: v('source') === 'Other – specify' ? g('sourceOther') : g('source'),
      role_applied: g('roleApplied'),
      engagement_type: g('engagementType'),
      english_level: g('englishLevel'),
      years_exp: g('yearsExp'),
      industry: v('industry') === 'Other' ? g('industryOther') : g('industry'),
      exp_type: checks('expType'),
      exp_desc: g('internshipDesc'),
      current_salary: g('currentSalary'),
      expected_salary: g('expectedSalary'),
      tenure: g('tenure'),
      joining_date: g('joiningDate'),
      flex_hours: radio('flexHours'),
      laptop: radio('laptop'),
      internet: radio('internet'),
      commute: radio('commute'),
      location: g('candidateLocation'),
      tech_skills: checks('techSkills'),
      soft_skills: checks('softSkills'),
      portfolio: g('portfolioLinks'),
      video_option: g('videoOption'),
      video_link: g('videoLinkInput'),
      ai_test_willing: g('aiTestWilling'),
      ref_token: REF_TOKEN,
      timestamp: new Date().toISOString()
    };
    
    // Resume
    const ri = document.getElementById('resumeFile');
    if (ri.files.length) {
      const f = ri.files[0];
      data.resume_name = f.name;
      data.resume_type = f.type;
      data.resume_base64 = await getBase64(f);
    }
    
    // Video
    const vi = document.getElementById('videoFile');
    if (vi.files.length && g('videoOption') === 'upload') {
      const f = vi.files[0];
      data.video_name = f.name;
      data.video_type = f.type;
      data.video_base64 = await getBase64(f);
    }
    
    const res = await fetch('/api/apply.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    
    const d = await res.json();
    if (!d.success) throw new Error(d.error || 'Submit failed');
    if (d.interview_token) {
      const link = document.getElementById('aiInterviewLink');
      link.href = INTERVIEW_URL_PUBLIC + '?t=' + encodeURIComponent(d.interview_token);
      link.style.display = 'inline-flex';
    }
    
  } catch (err) {
    console.error(err);
    alert('Submission failed. Please try again.\n' + err.message);
  }
  
  document.getElementById('submitOverlay').classList.remove('active');
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.getElementById('thankyou').classList.add('active');
  document.getElementById('progressBar').style.width = '100%';
  document.getElementById('progressLabel').textContent = 'Complete!';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.addEventListener('DOMContentLoaded', () => {
  // Default theme: light (midnight available via toggle)
  try {
    const saved = localStorage.getItem('avk_theme');
    if (saved) { document.documentElement.setAttribute('data-theme', saved); }
    else { setTheme('light'); }
  } catch(e) { setTheme('light'); }

  document.getElementById('phone').addEventListener('input', () => {
    if (v('phoneCode') === '+91') {
      const i = document.getElementById('phone');
      i.value = i.value.replace(/\D/g, '').slice(0, 10);
    }
    document.getElementById('phone').classList.remove('input-invalid');
  });
  
  const today = new Date().toISOString().split('T')[0];
  document.getElementById('joiningDate').min = today;
  document.getElementById('dob').max = today;
});


// ── THEME ──────────────────────────────────────────────────────
function setTheme(t) {
  document.documentElement.setAttribute('data-theme', t);
  try { localStorage.setItem('avk_theme', t); } catch(e) {}
}
function toggleTheme() {
  const cur = document.documentElement.getAttribute('data-theme') || 'light';
  setTheme(cur === 'light' ? 'midnight' : 'light');
}


// ── JOB DESCRIPTION ────────────────────────────────────────────
const JD_DATA = {
  "AI": `<strong>Job Description Summary – AI/ML Role</strong>
    <p>We are looking for passionate and driven individuals to join our team in the field of Artificial Intelligence and Machine Learning. The ideal candidate should have strong communication skills in English, relevant industry exposure, and a willingness to learn and grow in a fast-paced environment.</p>
    <p>Candidates with varying levels of experience—ranging from freshers to experienced professionals—are encouraged to apply. We welcome applicants with backgrounds in internships, full-time roles, freelance work, or academic projects related to AI/ML or similar domains.</p>
    <strong>Key Highlights:</strong>
    <ul>
      <li>Open to Freshers, Interns, Freelancers, and Experienced Professionals</li>
      <li>Emphasis on English communication and clarity</li>
      <li>Opportunity to work on real-world AI/ML projects</li>
      <li>Industry exposure preferred but not mandatory</li>
      <li>Strong learning mindset and adaptability required</li>
    </ul>`,
  "Sales": `<strong>Job Description Summary – Software Sales</strong>
    <p>We are looking for a motivated and customer-focused Software Sales Executive to promote and assist clients with our range of technology solutions, including Dialer Systems, CRM Software, AI Voice Bots, AI Automation Tools, Digital Marketing Services, and Website/App Development.</p>
    <p>The role involves understanding client requirements, explaining suitable solutions, and guiding them through the onboarding process. Candidates should have good English communication skills, a professional approach, and the ability to build strong client relationships.</p>
    <strong>Key Highlights:</strong>
    <ul>
      <li>Open to Freshers and Experienced Candidates</li>
      <li>No Sales Targets or Incentive-Based Pressure</li>
      <li>Client Interaction and Relationship Management Role</li>
      <li>Collaborative and Growth-Oriented Work Environment</li>
      <li>Training and Support Provided</li>
    </ul>`,
  "PHP & Developer": `<strong>Job Description Summary – PHP Developer / Software Engineer</strong>
    <p>We are looking for a skilled and detail-oriented PHP Developer / Software Engineer to design, develop, and maintain web-based applications. The candidate will be responsible for building scalable backend systems, integrating APIs, and ensuring smooth functionality of websites and software solutions.</p>
    <p>This opportunity is open to both freshers and experienced candidates who have hands-on experience through internships, freelance work, or academic projects. A strong willingness to learn and adapt to new technologies is essential.</p>
    <strong>Key Highlights:</strong>
    <ul>
      <li>Real-world PHP/web development projects</li>
      <li>Mentorship and guidance provided</li>
      <li>CRM, web apps, and automation tools</li>
      <li>Open to freshers with project experience</li>
    </ul>`,
  "Support Engineer": `<strong>Job Description Summary – Support Engineer</strong>
    <p>Manage dialer systems, GSM gateways, and Linux servers. Monitor performance and troubleshoot technical issues. Basic networking and Linux knowledge required.</p>
    <p>Open to freshers with a strong technical base and eagerness to learn VoIP and server infrastructure.</p>
    <strong>Key Highlights:</strong>
    <ul>
      <li>VoIP and Asterisk exposure</li>
      <li>Linux server hands-on experience</li>
      <li>Open to freshers with strong technical base</li>
      <li>Training and technical guidance provided</li>
    </ul>`
};

function updateJD(role) {
  const jdBox = document.getElementById('jdBox');
  const jdContent = document.getElementById('jdContent');
  if (role && JD_DATA[role]) {
    jdContent.innerHTML = JD_DATA[role];
    jdBox.style.display = 'block';
  } else {
    jdBox.style.display = 'none';
    jdContent.innerHTML = '';
  }
  highlightJdCard(role);
}

function highlightJdCard(role) {
  document.querySelectorAll('.jd-card').forEach(c => c.classList.remove('active'));
  const cardMap = {
    'AI': 'jdcard-AI',
    'Sales': 'jdcard-Sales',
    'PHP & Developer': 'jdcard-PHP',
    'Support Engineer': 'jdcard-Support'
  };
  const id = cardMap[role];
  if (id) { const el = document.getElementById(id); if (el) el.classList.add('active'); }
}

function selectDefaultRole(role) {
  const sel = document.getElementById('roleApplied');
  // Try to find in DB-populated options first
  let found = false;
  for (let o of sel.options) {
    if (o.value === role || o.text === role ||
        (role === 'PHP & Developer' && (o.value.includes('PHP') || o.text.includes('PHP'))) ||
        (role === 'Support Engineer' && (o.value.includes('Support') || o.text.includes('Support')))) {
      sel.value = o.value;
      found = true;
      break;
    }
  }
  // If not in DB, add as a temporary option (shown but not required to exist in DB)
  if (!found) {
    const opt = new Option(role, role, true, true);
    sel.add(opt);
  }
  updateJD(role);
  highlightJdCard(role);
  // Scroll to role select
  sel.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ── WORD LIMIT ─────────────────────────────────────────────────
function limitWords(field, maxWords) {
  let words = field.value.trim().split(/\s+/).filter(w => w.length > 0);
  if (words.length > maxWords) { field.value = words.slice(0, maxWords).join(' ') + ' '; words = words.slice(0, maxWords); }
  const hint = document.getElementById('wordCountHint');
  if (hint) hint.innerText = words.length + ' / ' + maxWords + ' words';
}

// ── GOOGLE MAPS DISTANCE ───────────────────────────────────────
function checkDistance() {
  const origin = document.getElementById('candidateLocation').value.trim();
  if (!origin) { alert('Please enter your area/location first.'); return; }
  window.open('https://www.google.com/maps/dir/?api=1&origin=' + encodeURIComponent(origin) + '&destination=' + encodeURIComponent('Avyukta Intellicall, Narayan Vihar Rd, Ganatpura, Jaipur'), '_blank');
}

// ── BETTER PHONE VALIDATE ──────────────────────────────────────
function validatePhone() {
  const code = document.getElementById('phoneCode').value;
  const ph = document.getElementById('phone').value.trim();
  const inp = document.getElementById('phone');
  if (code === '+91') {
    if (!/^\d{10}$/.test(ph)) { inp.classList.add('input-invalid'); return 'Phone number must be exactly 10 digits.'; }
  } else if (code === 'other') {
    const sel = document.getElementById('otherCountryCode');
    if (!sel.value) return 'Please select a country code.';
    const [, min, max] = sel.value.split(':');
    const d = ph.replace(/\D/g, '');
    if (d.length < parseInt(min) || d.length > parseInt(max)) {
      inp.classList.add('input-invalid');
      return 'Phone must be ' + (min === max ? min : min+'–'+max) + ' digits for selected country.';
    }
  }
  inp.classList.remove('input-invalid');
  return null;
}

// ── FILE SIZE CHECK ────────────────────────────────────────────
function checkFileSize(inputId, maxMB) {
  const fi = document.getElementById(inputId);
  return !(fi.files.length > 0 && fi.files[0].size > maxMB * 1024 * 1024);
}

updateProgress();
</script>

</body>
</html>
