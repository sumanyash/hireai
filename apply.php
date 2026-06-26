<?php
/**
 * HireAI - Public Job Application Form
 * 9-Step Comprehensive Candidate Application
 * Location: /apply.php (public route)
 * 
 * Usage:
 *   - ?campaign_id=123 (direct campaign ID)
 *   - ?c=share_token (public campaign share token)
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';

// Get campaign from ID or token
$campaign_id = (int)($_GET['campaign_id'] ?? 0);
$token       = trim($_GET['c'] ?? $_GET['t'] ?? '');
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
        "SELECT * FROM campaigns WHERE share_token=? AND status='active'",
        [$token],
        's'
    );
    if ($campaign) $campaign_id = $campaign['id'];
}
$campaign_is_live = $campaign && $campaign['status'] === 'active';

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
$application_fields = $campaign_id ? db_fetch_all("SELECT * FROM application_fields WHERE campaign_id=? AND is_active=1 ORDER BY order_no,id", [$campaign_id], 'i') : [];
$default_apply_keys = [
    // Core personal
    'salutation', 'first_name', 'last_name', 'full_name', 'name', 'candidate_name',
    'dob', 'date_of_birth', 'birth_date',
    'city', 'current_city', 'location', 'current_location', 'hometown',
    'relocate', 'relocate_time',
    // Contact
    'phone_code', 'other_country_code',
    'phone', 'phone_number', 'mobile', 'mobile_number', 'contact', 'contact_number',
    'email', 'email_id', 'email_address',
    // Education / source
    'college', 'college_other', 'source', 'source_other', 'role_applied',
    // Experience
    'engagement_type', 'english_level',
    'years_exp', 'experience_years', 'years_of_experience', 'exp_years', 'experience', 'total_experience',
    'industry', 'industry_other', 'exp_type', 'exp_desc',
    // Compensation
    'current_salary', 'current_ctc', 'ctc', 'current_salary_monthly',
    'expected_salary', 'expected_ctc', 'expected_salary_monthly',
    // Work preferences
    'tenure', 'joining_date', 'flex_hours', 'laptop', 'internet', 'commute',
    // Uploads / consent
    'resume', 'photo', 'video_option', 'video_link', 'video_file',
    'portfolio', 'ai_test_willing', 'declaration_confirmation'
];
$custom_application_fields = array_values(array_filter($application_fields, function ($field) use ($default_apply_keys) {
    $key = strtolower(trim($field['field_key'] ?? ''));
    return $key !== '' && !in_array($key, $default_apply_keys, true);
}));
$has_extra_application_fields = !empty($custom_application_fields);
$declaration_section = $has_extra_application_fields ? 10 : 9;
// Standard field visibility config (JSON stored in campaigns.apply_form_config)
$_std_form_cfg = null;
if (!empty($campaign['apply_form_config'])) {
    $_std_form_cfg = json_decode($campaign['apply_form_config'], true) ?: null;
}
$_has_std_form_cfg = ($_std_form_cfg !== null);
function is_std_on(string $key): bool {
    global $_std_form_cfg;
    return $_std_form_cfg === null || in_array($key, $_std_form_cfg, true);
}
// Use 9-step wizard when std-field config is set OR no custom fields exist.
// Use 2-step dynamic form ONLY for pure custom-field campaigns (JD-builder) with no std config.
$is_dynamic_apply = !$_has_std_form_cfg && !empty($custom_application_fields);
// Detect if mandatory contact fields are missing from application_fields (need injection)
$_adf_phone_keys = ['phone','mobile','phone_number','mobile_number','whatsapp','contact'];
$_adf_email_keys = ['email','email_id','email_address'];
$_adf_name_keys  = ['first_name','last_name','full_name','name','candidate_name','applicant_name'];
$df_has_phone = false; $df_has_email = false; $df_has_name = false;
foreach ($application_fields as $_f) {
    $_fk = strtolower(trim($_f['field_key'] ?? ''));
    $_ft = $_f['field_type'] ?? '';
    if (in_array($_fk, $_adf_phone_keys, true) || $_ft === 'phone') $df_has_phone = true;
    if (in_array($_fk, $_adf_email_keys, true) || $_ft === 'email') $df_has_email = true;
    if (in_array($_fk, $_adf_name_keys, true)) $df_has_name = true;
}

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
.file-upload-area.has-file{border-color:var(--success);background:rgba(34,211,165,.06)}

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
.ai-required-panel{
  display:none;
  max-width:520px;
  margin:0 auto 20px;
  padding:16px 18px;
  border:1px solid rgba(255,149,0,.34);
  background:linear-gradient(135deg,rgba(255,149,0,.12),rgba(37,99,235,.08));
  border-radius:14px;
  color:var(--text);
  text-align:left;
  box-shadow:0 12px 36px rgba(15,23,42,.08)
}
.ai-required-panel.active{display:block}
.ai-required-kicker{
  display:inline-flex;
  align-items:center;
  gap:6px;
  color:var(--gold);
  font-size:12px;
  font-weight:800;
  letter-spacing:.08em;
  text-transform:uppercase;
  margin-bottom:8px
}
.ai-required-panel strong{color:var(--text)}
.ai-required-panel span{display:block;color:var(--muted);font-size:13px;line-height:1.6}

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
.ai-link.mandatory{
  background:linear-gradient(135deg,#F59E0B,#2563EB);
  border:0;
  color:#fff;
  box-shadow:0 16px 36px rgba(37,99,235,.24);
  padding:13px 28px
}

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
/* ── Duplicate warning ── */
.dup-warn{margin-top:8px;padding:10px 14px;border-radius:10px;background:#FFF1F2;border:1.5px solid #FECDD3;color:#BE123C;font-size:13px;font-weight:600;display:flex;align-items:flex-start;gap:9px;line-height:1.5;animation:dupFadeIn .2s ease}
@keyframes dupFadeIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.dup-warn-icon{font-size:16px;flex-shrink:0;margin-top:1px}
/* ── Custom date input (single field, DD/MM/YYYY mask) ── */
.dw-outer{position:relative}
.dw-input{width:100%;padding:11px 42px 11px 16px;border:1.5px solid var(--border-color,#dde3ef);border-radius:12px;background:var(--surface,#EEF2FB);color:var(--text,#1a2a4a);font-size:15px;font-family:inherit;outline:none;transition:border-color .15s,box-shadow .15s;letter-spacing:.5px}
.dw-input:focus{border-color:var(--accent,#4F7CFF);box-shadow:0 0 0 3px rgba(79,124,255,.12);background:#fff}
.dw-cal-icon{position:absolute;right:13px;top:50%;transform:translateY(-50%);color:var(--muted,#7a8ab0);cursor:pointer;display:flex;align-items:center;transition:color .15s}
.dw-cal-icon:hover{color:var(--accent,#4F7CFF)}
/* ── Flatpickr calendar theme ── */
.flatpickr-calendar{font-family:inherit;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.2);border:1.5px solid #dde3ef;z-index:99999!important}
.flatpickr-months .flatpickr-month{background:#4f7cff;border-radius:12px 12px 0 0;height:44px}
.flatpickr-current-month{font-size:14px;font-weight:700;color:#fff;padding-top:10px}
.flatpickr-monthDropdown-months{background:#4f7cff;color:#fff;font-weight:700}
.flatpickr-prev-month,.flatpickr-next-month{color:#fff!important;fill:#fff!important;padding-top:10px}
.flatpickr-prev-month svg,.flatpickr-next-month svg{fill:#fff}
.flatpickr-weekday{background:#f0f4ff;color:#4f7cff;font-weight:700;font-size:11px}
.flatpickr-day{border-radius:8px;font-size:13px;font-weight:500;color:#374151}
.flatpickr-day:hover{background:#e8eeff;border-color:#c7d5ff;color:#2a54c7}
.flatpickr-day.selected,.flatpickr-day.selected:hover{background:#4f7cff;border-color:#4f7cff;color:#fff;font-weight:700}
.flatpickr-day.today{border-color:#4f7cff;color:#4f7cff;font-weight:700}
.flatpickr-day.today.selected{color:#fff}
.flatpickr-day.flatpickr-disabled,.flatpickr-day.flatpickr-disabled:hover{color:#CBD5E1}
  </style>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>
<body>

<!-- Submit Spinner Overlay -->
<div class="submit-overlay" id="submitOverlay">
  <div class="submit-spinner">
    <div class="spinner-ring"></div>
    <p id="submitOverlayText">Submitting your application...<br><span style="font-size:11px;opacity:.7">Uploading files and saving your form. Please do not close this page.</span></p>
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

<?php
$_camp_status = $campaign ? ($campaign['status'] ?? '') : '';
$_is_closed   = in_array($_camp_status, ['paused','completed'], true);
$_is_draft    = $_camp_status === 'draft';
?>
<?php if ($_is_closed): ?>
<!-- ══ CAMPAIGN CLOSED PAGE ═══════════════════════════════════════════════ -->
<div style="min-height:70vh;display:flex;align-items:center;justify-content:center;padding:40px 20px">
  <div style="text-align:center;max-width:460px">
    <div style="font-size:56px;margin-bottom:20px"><?= $_camp_status === 'completed' ? '🏁' : '⏸️' ?></div>
    <h2 style="font-size:22px;font-weight:800;color:var(--text-primary,#0F172A);margin-bottom:10px">
      <?= $_camp_status === 'completed' ? 'Campaign Completed' : 'Applications Paused' ?>
    </h2>
    <p style="font-size:15px;color:#64748B;line-height:1.65;margin-bottom:28px">
      <?php if ($_camp_status === 'completed'): ?>
        This campaign has concluded and is no longer accepting applications. Thank you for your interest — please check back for future openings.
      <?php else: ?>
        This campaign is temporarily paused and not accepting new applications at the moment. Please check back later or contact the team for updates.
      <?php endif; ?>
    </p>
    <div style="font-size:13px;color:#94A3B8;font-weight:600"><?= htmlspecialchars($org_name) ?> · <?= htmlspecialchars($job_role) ?></div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body></html>
<?php exit; ?>
<?php endif; ?>
<?php if ($_is_draft): ?>
<div style="background:#FEF3C7;border-bottom:1px solid #FDE68A;padding:10px 20px;text-align:center;font-size:13px;font-weight:700;color:#92400E">
  <i class="fa-solid fa-eye" style="margin-right:6px"></i> Preview Mode — This form is not yet live. Submissions will be rejected until the campaign is activated.
</div>
<?php endif; ?>

<!-- Progress Bar -->
<div class="progress-wrap">
  <div class="step-dots" id="stepDots"></div>
  <div class="progress-bar-bg"><div class="progress-bar-fill" id="progressBar"></div></div>
  <div class="progress-label" id="progressLabel">Step 1 / 9</div>
</div>

<div class="container">
  <p class="required-note">Fields marked <span>*</span> are required.</p>

<?php if ($is_dynamic_apply): ?>
  <!-- ═══ FULLY DYNAMIC CAMPAIGN FORM ═══ -->
  <div class="section active" id="section-1">
    <div class="section-header">
      <div class="section-num">1</div>
      <div>
        <div class="section-title"><?= htmlspecialchars($campaign['name'] ?? 'Application Form') ?></div>
        <div class="section-desc"><?= htmlspecialchars($campaign['job_role'] ?? 'Please complete the form below.') ?></div>
      </div>
    </div>
    <div id="val-banner-1" class="val-banner"></div>
    <div class="card">
      <?php if (empty($application_fields)): ?>
        <div class="info-box" style="margin:0">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <div>This campaign application form is not configured yet. Please contact the recruiter.</div>
        </div>
      <?php endif; ?>

      <?php /* ── Inject mandatory contact fields if not in application_fields ── */ ?>
      <?php if (!$df_has_phone): ?>
      <div class="field" id="mand-phone-wrap">
        <label>Phone Number <span class="req">*</span></label>
        <input type="tel" id="mand_phone" placeholder="10-digit number" maxlength="15">
        <p class="field-hint">Your interview link will be sent to this WhatsApp number</p>
      </div>
      <?php endif; ?>
      <?php if (!$df_has_email): ?>
      <div class="field" id="mand-email-wrap">
        <label>Email Address <span class="req">*</span></label>
        <input type="email" id="mand_email" placeholder="you@example.com">
      </div>
      <?php endif; ?>
      <?php if (!$df_has_name): ?>
      <div class="field" id="mand-name-wrap">
        <label>Full Name <span class="req">*</span></label>
        <input type="text" id="mand_name" placeholder="Your full name">
      </div>
      <?php endif; ?>

      <?php foreach ($application_fields as $field):
        $fid = (int)$field['id'];
        $fieldId = 'appField_' . $fid;
        $type = $field['field_type'] ?? 'text';
        $options = json_decode($field['options_json'] ?? '[]', true) ?: [];
        $required = !empty($field['is_required']);
        $fieldKey = strtolower($field['field_key'] ?? '');
        $accept = '';
        if ($type === 'file') {
          if (str_contains($fieldKey, 'photo') || str_contains($fieldKey, 'image') || str_contains($fieldKey, 'picture')) $accept = 'image/*';
          elseif (str_contains($fieldKey, 'video')) $accept = 'video/*';
          elseif (str_contains($fieldKey, 'cv') || str_contains($fieldKey, 'resume')) $accept = '.pdf,.doc,.docx';
          else $accept = '.pdf,.doc,.docx,image/*,.mp4,.mov,.avi';
        }
      ?>
      <div class="field" data-app-wrap="<?= $fid ?>">
        <label for="<?= $fieldId ?>"><?= htmlspecialchars($field['field_label']) ?><?= $required ? ' <span class="req">*</span>' : '' ?></label>
        <?php if ($type === 'textarea'): ?>
          <textarea id="<?= $fieldId ?>" data-app-field="<?= $fid ?>" placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>"></textarea>
        <?php elseif ($type === 'dropdown'): ?>
          <select id="<?= $fieldId ?>" data-app-field="<?= $fid ?>">
            <option value="">Select option</option>
            <?php foreach ($options as $option): ?><option value="<?= htmlspecialchars($option) ?>"><?= htmlspecialchars($option) ?></option><?php endforeach; ?>
          </select>
        <?php elseif ($type === 'multi_select'): ?>
          <div class="options-grid cols2">
            <?php foreach ($options as $option): ?>
              <label class="opt-label"><input type="checkbox" name="<?= $fieldId ?>[]" value="<?= htmlspecialchars($option) ?>" data-app-field="<?= $fid ?>"><span><?= htmlspecialchars($option) ?></span></label>
            <?php endforeach; ?>
          </div>
        <?php elseif ($type === 'checkbox'): ?>
          <div class="options-grid cols2">
            <?php if (!empty($options)): foreach ($options as $option): ?>
              <label class="opt-label"><input type="checkbox" name="<?= $fieldId ?>[]" value="<?= htmlspecialchars($option) ?>" data-app-field="<?= $fid ?>"><span><?= htmlspecialchars($option) ?></span></label>
            <?php endforeach; else: ?>
              <label class="opt-label"><input type="checkbox" id="<?= $fieldId ?>" value="Yes" data-app-field="<?= $fid ?>"><span>Yes</span></label>
            <?php endif; ?>
          </div>
        <?php elseif ($type === 'file'): ?>
          <div class="file-upload-area" onclick="document.getElementById('<?= $fieldId ?>').click()">
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <div class="upload-title"><?= htmlspecialchars($field['placeholder'] ?: 'Click to upload file') ?></div>
            <div class="upload-sub">PDF, DOCX, image, or video · Max 20 MB</div>
            <div class="file-name" id="<?= $fieldId ?>Name"></div>
            <input type="file" id="<?= $fieldId ?>" data-app-field="<?= $fid ?>" accept="<?= htmlspecialchars($accept) ?>" onclick="event.stopPropagation()" onchange="showFileName('<?= $fieldId ?>','<?= $fieldId ?>Name')">
          </div>
        <?php else:
          $inputType = in_array($type, ['number','date','email','url'], true) ? $type : ($type === 'decimal' ? 'number' : ($type === 'phone' ? 'tel' : 'text'));
          $step = $type === 'decimal' ? ' step="0.01"' : '';
        ?>
          <input type="<?= $inputType ?>"<?= $step ?> id="<?= $fieldId ?>" data-app-field="<?= $fid ?>" placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>">
        <?php endif; ?>
        <?php if (!empty($field['help_text'])): ?><p class="field-hint"><?= htmlspecialchars($field['help_text']) ?></p><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="nav-bar"><div></div><button class="btn btn-primary" onclick="nextSection(1)" <?= empty($application_fields) ? 'disabled' : '' ?>>Continue →</button></div>
  </div>

  <div class="section" id="section-2">
    <div class="section-header">
      <div class="section-num">2</div>
      <div>
        <div class="section-title">Declaration</div>
        <div class="section-desc">Please review and confirm your submission.</div>
      </div>
    </div>
    <div id="val-banner-2" class="val-banner"></div>
    <div class="card">
      <label class="opt-label" style="border-color:rgba(0,153,90,.3);background:rgba(0,153,90,.05);padding:16px;align-items:flex-start">
        <input type="checkbox" id="declaration">
        <span style="color:var(--success);font-size:14px;line-height:1.6;font-weight:400">I confirm that the information provided is true and accurate to the best of my knowledge. I understand that any false or misleading information may result in disqualification.</span>
      </label>
      <label class="opt-label" style="border-color:rgba(79,124,255,.3);background:rgba(79,124,255,.06);padding:16px;align-items:flex-start;margin-top:12px">
        <input type="checkbox" id="recordingConsent">
        <span style="color:var(--accent);font-size:14px;line-height:1.6;font-weight:400">I consent to voice/video recording, transcription, and AI-assisted summarisation for hiring evaluation and recruiter review.</span>
      </label>
    </div>
    <div class="nav-bar"><button class="btn btn-ghost" onclick="prevSection(2)">← Back</button><button class="btn btn-success" onclick="submitForm()">Submit Application ✓</button></div>
  </div>
<?php else: ?>

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
      <?php if (is_std_on('salutation')): ?>
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
      <?php endif; ?>
      <?php if (is_std_on('first_name') || is_std_on('last_name')): ?>
      <div class="field-row">
        <?php if (is_std_on('first_name')): ?>
        <div class="field"><label for="firstName">First Name <span class="req">*</span></label><input type="text" id="firstName" placeholder="First name" oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')"></div>
        <?php endif; ?>
        <?php if (is_std_on('last_name')): ?>
        <div class="field"><label for="lastName">Last Name <span class="req">*</span></label><input type="text" id="lastName" placeholder="Last name" oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')"></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if (is_std_on('dob')): ?>
      <div class="field">
        <label>Date of Birth <span class="req">*</span></label>
        <div class="dw-outer">
          <input class="dw-input" type="text" id="dob-display" placeholder="DD/MM/YYYY" readonly autocomplete="off">
          <span class="dw-cal-icon" id="dob-cal-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
        </div>
        <input type="hidden" id="dob">
      </div>
      <?php endif; ?>
      <?php if (is_std_on('city')): ?>
      <div class="field-row" style="align-items:start">
        <div class="field" style="margin-bottom:0"><label for="currentCity">Current City <span class="req">*</span></label><input type="text" id="currentCity" placeholder="Your city" oninput="handleCityChange()"></div>
        <div class="field" id="relocateCol" style="display:none;margin-bottom:0"><label for="relocate">Comfortable to Relocate? <span class="req">*</span></label><select id="relocate" onchange="handleRelocateChange()"><option value="">Select</option><option>Yes</option><option>No</option></select></div>
      </div>
      <div class="field" id="relocateTimeRow" style="display:none;margin-top:20px">
        <label for="relocateTime">Relocation Time <span class="req">*</span></label>
        <select id="relocateTime"><option value="">Select</option><option>Immediate</option><option>Within 15 days</option><option>Within 1 month</option><option>Within 3 months</option><option>More than 3 months</option></select>
      </div>
      <?php endif; ?>
      <!-- Phone row: combobox (editable + selectable) + number -->
      <style>
      .cc-wrap{position:relative;width:100%}
      .cc-input{width:100%;padding:9px 32px 9px 12px;border:1.5px solid var(--border-color,#E2E8F0);border-radius:9px;font-size:13px;font-family:inherit;background:#FAFBFC;color:#0F172A;outline:none;transition:border-color .15s,box-shadow .15s;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .cc-input:focus{border-color:#7C3AED;box-shadow:0 0 0 3px rgba(124,58,237,.1);background:#fff}
      .cc-arrow{position:absolute;right:10px;top:50%;transform:translateY(-50%);pointer-events:none;color:#94A3B8;font-size:11px;transition:transform .15s}
      .cc-wrap.open .cc-arrow{transform:translateY(-50%) rotate(180deg)}
      .cc-dropdown{position:absolute;top:calc(100% + 5px);left:0;right:0;background:#fff;border:1.5px solid #E2E8F0;border-radius:11px;box-shadow:0 8px 28px rgba(0,0,0,.13);z-index:999;max-height:240px;overflow-y:auto;display:none}
      .cc-wrap.open .cc-dropdown{display:block}
      .cc-option{padding:9px 13px;font-size:13px;cursor:pointer;transition:background .1s;display:flex;align-items:center;gap:8px;color:#0F172A}
      .cc-option:hover,.cc-option.active{background:#F5F3FF;color:#6D28D9}
      .cc-option.selected{background:#EDE9FE;font-weight:700;color:#5B21B6}
      .cc-sep{height:1px;background:#F1F5F9;margin:4px 0}
      .phone-grid{display:grid;grid-template-columns:210px 1fr;gap:14px;align-items:start;margin-top:20px}
      @media(max-width:540px){
        .phone-grid{grid-template-columns:minmax(0,130px) 1fr;gap:10px}
        .cc-input{font-size:12px;padding:9px 28px 9px 8px}
        .cc-input-short::placeholder{font-size:12px}
      }
      @media(max-width:380px){
        .phone-grid{grid-template-columns:minmax(0,110px) 1fr;gap:8px}
        .cc-input{font-size:11px;padding:8px 24px 8px 7px}
      }
      </style>
      <div class="phone-grid">
        <div class="field" style="margin-bottom:0">
          <label>Country Code <span class="req">*</span></label>
          <div class="cc-wrap" id="ccWrap">
            <input type="text" id="ccInput" class="cc-input" value="🇮🇳 +91 India"
              placeholder="Type or select…"
              autocomplete="off"
              oninput="ccFilter()"
              onfocus="ccOpen()"
            >
            <span class="cc-arrow">▼</span>
            <div class="cc-dropdown" id="ccDropdown"></div>
          </div>
          <!-- Resolved hidden value -->
          <input type="hidden" id="phoneCode" value="+91">
          <p class="field-hint" id="otherCountryHint" style="margin-top:4px"></p>
        </div>
        <div class="field" id="phoneNumberCol" style="margin-bottom:0">
          <label for="phone">Phone Number <span class="req">*</span></label>
          <input type="tel" id="phone" placeholder="10-digit mobile number" maxlength="10"
            onblur="checkDuplicate('phone')">
          <p class="field-hint" id="phoneHint">10-digit number — no country code needed.</p>
          <div class="dup-warn" id="phoneWarn" style="display:none"></div>
        </div>
      </div>
      <div class="field" style="margin-top:20px">
        <label for="email">Email ID <span class="req">*</span></label>
        <input type="email" id="email" placeholder="you@example.com"
          onblur="checkDuplicate('email')">
        <div class="dup-warn" id="emailWarn" style="display:none"></div>
      </div>
      <?php if (is_std_on('college')): ?>
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
      <?php endif; ?>
      <?php if (is_std_on('source')): ?>
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
      <?php endif; ?>
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
        <?php if (is_std_on('english_level')): ?>
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
        <?php endif; ?>
        <?php if (is_std_on('years_exp')): ?>
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
        <?php endif; ?>
      </div>
      <?php if (is_std_on('industry')): ?>
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
      <?php endif; ?>
      <?php if (is_std_on('exp_type')): ?>
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
      <?php endif; ?>
      <?php if (is_std_on('exp_desc')): ?>
      <div class="field" id="internshipDescContainer">
        <label for="internshipDesc">Describe Your Past Experience (If Any)</label>
        <textarea id="internshipDesc" placeholder="Briefly describe any relevant internship or project experience (Max 50 words)..." oninput="limitWords(this, 50)"></textarea>
        <p class="field-hint" id="wordCountHint">0 / 50 words</p>
      </div>
      <?php endif; ?>
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
        <?php if (is_std_on('current_salary')): ?>
        <div class="field">
          <label for="currentSalary">Current Salary / Stipend <span style="font-weight:400;color:var(--muted)">(₹ / month)</span></label>
          <input type="number" id="currentSalary" placeholder="e.g. 15000" min="0" step="1" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
          <p class="field-hint">Numbers only · enter monthly amount in ₹ · leave blank if not applicable</p>
        </div>
        <?php endif; ?>
        <?php if (is_std_on('expected_salary')): ?>
        <div class="field">
          <label for="expectedSalary">Expected Salary / Stipend <span style="font-weight:400;color:var(--muted)">(₹ / month)</span> <span class="req">*</span></label>
          <input type="number" id="expectedSalary" placeholder="e.g. 20000" min="0" step="1" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
          <p class="field-hint">Numbers only · enter monthly amount in ₹</p>
        </div>
        <?php endif; ?>
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
      <?php if (is_std_on('tenure')): ?>
      <div class="field" id="tenureField">
        <label for="tenure">Internship / Training Tenure <span class="req">*</span></label>
        <select id="tenure" onchange="handleTenureChange()">
          <option value="">Select Tenure</option>
          <option value="6 months">6 Months</option>
          <option value="9 months">9 Months</option>
          <option value="12 months">12 Months</option>
          <option value="18 months">18 Months</option>
          <option value="24 months">24 Months</option>
          <option value="other">Other – specify</option>
        </select>
      </div>
      <div class="field" id="tenureOtherField" style="display:none">
        <label for="tenureOther">Specify Tenure <span class="req">*</span></label>
        <input type="text" id="tenureOther" placeholder="e.g. 3 months, 6 weeks…">
      </div>
      <?php endif; ?>
      <div class="field-row">
        <?php if (is_std_on('joining_date')): ?>
        <div class="field">
          <label for="joiningDate">Preferred Joining Date <span class="req">*</span></label>
          <div class="dw-outer">
            <input class="dw-input" type="text" id="joiningDate-display" placeholder="DD/MM/YYYY" readonly autocomplete="off">
            <span class="dw-cal-icon" id="jd-cal-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
          </div>
          <input type="hidden" id="joiningDate">
        </div>
        <?php endif; ?>
        <?php if (is_std_on('flex_hours')): ?>
        <div class="field">
          <label for="flexHours">Open to Flexible Hours? <span class="req">*</span></label>
          <select id="flexHours">
            <option value="">Select Option</option>
            <option value="Yes">Yes</option>
            <option value="No">No</option>
          </select>
        </div>
        <?php endif; ?>
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
        <?php if (is_std_on('laptop')): ?>
        <div class="field">
          <label for="laptop">Do you own a Laptop? <span class="req">*</span></label>
          <select id="laptop">
            <option value="">Select Option</option>
            <option value="Yes">Yes</option>
            <option value="No">No</option>
          </select>
        </div>
        <?php endif; ?>
        <?php if (is_std_on('internet')): ?>
        <div class="field">
          <label for="internet">Reliable Broadband / Wi-Fi at Home? <span class="req">*</span></label>
          <select id="internet">
            <option value="">Select Option</option>
            <option value="Yes">Yes</option>
            <option value="No">No</option>
          </select>
        </div>
        <?php endif; ?>
      </div>
      <?php if (is_std_on('location')): ?>
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
      <?php endif; ?>
      <?php if (is_std_on('commute')): ?>
      <div class="field">
        <label for="commute">Commute to Office <span class="req">*</span></label>
        <select id="commute">
          <option value="">Select Option</option>
          <option value="Personal vehicle">Personal Vehicle</option>
          <option value="Self-managed">I will manage on my own</option>
        </select>
      </div>
      <?php endif; ?>
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
      <?php if (is_std_on('resume')): ?>
      <div class="field">
        <label>Resume / CV <span class="req">*</span></label>
        <div class="file-upload-area" onclick="document.getElementById('resumeFile').click()">
          <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
          <div class="upload-title">Click to upload Resume / CV</div>
          <div class="upload-sub">PDF or DOCX only · Max 10 MB</div>
          <div class="file-name" id="resumeFileName"></div>
          <input type="file" id="resumeFile" accept=".pdf,.docx" onclick="event.stopPropagation()" onchange="showFileName('resumeFile','resumeFileName')">
        </div>
      </div>
      <?php endif; ?>
      <?php if (is_std_on('video_option')): ?>
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
          <input type="file" id="videoFile" accept=".mp4,.mov,.avi" onclick="event.stopPropagation()" onchange="showFileName('videoFile','videoFileName')">
        </div>
      </div>
      <?php endif; ?>
      <?php if (is_std_on('portfolio')): ?>
      <div class="field"><label for="portfolioLinks">Portfolio / Project Links</label><input type="url" id="portfolioLinks" placeholder="GitHub, LinkedIn, or personal website URL"><p class="field-hint">Separate multiple URLs with a comma.</p></div>
      <?php endif; ?>
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
      <?php if (is_std_on('ai_test_willing')): ?>
      <div class="field">
        <label for="aiTestWilling">Willing to Take the AI Test? <span class="req">*</span></label>
        <select id="aiTestWilling">
          <option value="">Select Option</option>
          <option value="Yes">Yes</option>
          <option value="No">No</option>
        </select>
      </div>
      <?php endif; ?>
    </div>
    <div class="nav-bar"><button class="btn btn-ghost" onclick="prevSection(8)">← Back</button><button class="btn btn-primary" onclick="nextSection(8)">Continue →</button></div>
  </div>

  <?php if ($has_extra_application_fields): ?>
  <!-- ═══ SECTION 9: Campaign Questions ═══ -->
  <div class="section" id="section-9">
    <div class="section-header">
      <div class="section-num">9</div>
      <div>
        <div class="section-title">Campaign Questions</div>
        <div class="section-desc">Additional details requested for this specific campaign.</div>
      </div>
    </div>
    <div id="val-banner-9" class="val-banner"></div>
    <div class="card">
      <?php foreach ($custom_application_fields as $field):
          $fid = (int)$field['id'];
          $fieldId = 'appField_' . $fid;
          $type = $field['field_type'] ?? 'text';
          $options = json_decode($field['options_json'] ?? '[]', true) ?: [];
          $required = !empty($field['is_required']);
        ?>
        <div class="field" data-app-wrap="<?= $fid ?>">
          <label for="<?= $fieldId ?>"><?= htmlspecialchars($field['field_label']) ?><?= $required ? ' <span class="req">*</span>' : '' ?></label>
          <?php if ($type === 'textarea'): ?>
            <textarea id="<?= $fieldId ?>" data-app-field="<?= $fid ?>" placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>"></textarea>
          <?php elseif ($type === 'dropdown'): ?>
            <select id="<?= $fieldId ?>" data-app-field="<?= $fid ?>">
              <option value="">Select option</option>
              <?php foreach ($options as $option): ?><option value="<?= htmlspecialchars($option) ?>"><?= htmlspecialchars($option) ?></option><?php endforeach; ?>
            </select>
          <?php elseif ($type === 'multi_select'): ?>
            <div class="options-grid cols2">
              <?php foreach ($options as $option): ?>
                <label class="opt-label"><input type="checkbox" name="<?= $fieldId ?>[]" value="<?= htmlspecialchars($option) ?>" data-app-field="<?= $fid ?>"><span><?= htmlspecialchars($option) ?></span></label>
              <?php endforeach; ?>
            </div>
          <?php elseif ($type === 'checkbox'): ?>
            <div class="options-grid cols2">
              <?php if (!empty($options)): foreach ($options as $option): ?>
                <label class="opt-label"><input type="checkbox" name="<?= $fieldId ?>[]" value="<?= htmlspecialchars($option) ?>" data-app-field="<?= $fid ?>"><span><?= htmlspecialchars($option) ?></span></label>
              <?php endforeach; else: ?>
                <label class="opt-label"><input type="checkbox" id="<?= $fieldId ?>" value="Yes" data-app-field="<?= $fid ?>"><span>Yes</span></label>
              <?php endif; ?>
            </div>
          <?php else:
            $inputType = in_array($type, ['number','date','email','url'], true) ? $type : ($type === 'decimal' ? 'number' : ($type === 'phone' ? 'tel' : 'text'));
            $step = $type === 'decimal' ? ' step="0.01"' : '';
          ?>
            <input type="<?= $inputType ?>"<?= $step ?> id="<?= $fieldId ?>" data-app-field="<?= $fid ?>" placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>">
          <?php endif; ?>
          <?php if (!empty($field['help_text'])): ?><p class="field-hint"><?= htmlspecialchars($field['help_text']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="nav-bar"><button class="btn btn-ghost" onclick="prevSection(9)">← Back</button><button class="btn btn-primary" onclick="nextSection(9)">Continue →</button></div>
  </div>
  <?php endif; ?>

  <!-- ═══ SECTION <?= $declaration_section ?>: Declaration ═══ -->
  <div class="section" id="section-<?= $declaration_section ?>">
    <div class="section-header">
      <div class="section-num"><?= $declaration_section ?></div>
      <div>
        <div class="section-title">Declaration</div>
        <div class="section-desc">Please review and confirm your submission.</div>
      </div>
    </div>
    <div id="val-banner-<?= $declaration_section ?>" class="val-banner"></div>
    <div class="card">
      <label class="opt-label" style="border-color:rgba(0,153,90,.3);background:rgba(0,153,90,.05);padding:16px;align-items:flex-start">
        <input type="checkbox" id="declaration">
        <span style="color:var(--success);font-size:14px;line-height:1.6;font-weight:400">I confirm that the information provided is true and accurate to the best of my knowledge. I understand that any false or misleading information may result in disqualification.</span>
      </label>
      <label class="opt-label" style="border-color:rgba(79,124,255,.3);background:rgba(79,124,255,.06);padding:16px;align-items:flex-start;margin-top:12px">
        <input type="checkbox" id="recordingConsent">
        <span style="color:var(--accent);font-size:14px;line-height:1.6;font-weight:400">I consent to voice/video recording, transcription, and AI-assisted summarisation for hiring evaluation and recruiter review.</span>
      </label>
    </div>
    <div class="nav-bar"><button class="btn btn-ghost" onclick="prevSection(<?= $declaration_section ?>)">← Back</button><button class="btn btn-success" onclick="submitForm()">Submit Application ✓</button></div>
  </div>
<?php endif; ?>

  <!-- ═══ THANK YOU ═══ -->
  <div class="thankyou" id="thankyou">
    <div class="checkmark">
      <svg width="34" height="34" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <h2 id="thankyouTitle">Application Submitted! 🎉</h2>
    <p id="thankyouMessage">Thank you for applying to <?=htmlspecialchars($org_name)?>. Our team will review your application and contact you shortly.</p>
    <div class="ai-required-panel" id="aiRequiredPanel">
      <div class="ai-required-kicker">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        Mandatory Next Step
      </div>
      <strong>AI interview test is required because you selected “Yes”.</strong>
      <span>Please start the test now to complete your application. Camera, microphone, and recording consent will be required on the next screen.</span>
    </div>
    <a href="#" id="aiInterviewLink" class="ai-link" style="display:none">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
      Begin AI Interview Test
    </a>
    <div id="referralBox" style="display:none;margin-top:18px">
      <p style="font-size:13px;color:var(--muted);margin-bottom:10px">Refer someone for the same campaign:</p>
      <select id="refMedium" style="margin:0 auto 10px;max-width:260px;width:100%;padding:10px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--text)">
        <option value="whatsapp">Medium: WhatsApp</option>
        <option value="email">Medium: Email</option>
        <option value="sms">Medium: SMS</option>
        <option value="copy_link">Medium: Copy Link</option>
      </select>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <a href="#" id="refWhatsapp" class="ai-link" target="_blank" rel="noopener" style="background:#16A34A">Share on WhatsApp</a>
        <button type="button" id="refCopyBtn" class="ai-link" style="border:none;cursor:pointer;background:#7C3AED">Copy Apply Link</button>
      </div>
    </div>
  </div>
</div><!-- container -->

<script>
const DYNAMIC_APPLY = <?= $is_dynamic_apply ? 'true' : 'false' ?>;
const HAS_EXTRA_FIELDS = <?= $has_extra_application_fields ? 'true' : 'false' ?>;
const DECLARATION_SECTION = <?= (int)$declaration_section ?>;
const TOTAL = DYNAMIC_APPLY ? 2 : DECLARATION_SECTION;
let currentSection = 1;
const CAMPAIGN_ID = <?=$campaign_id?>;
const REF_TOKEN = <?= json_encode($ref_token) ?>;
const INTERVIEW_URL_PUBLIC = <?= json_encode(defined('INTERVIEW_URL') ? INTERVIEW_URL : '/interview.php') ?>;
const APP_FIELDS = <?= json_encode(array_map(function($f) {
  return [
    'id' => (int)$f['id'],
    'key' => $f['field_key'],
    'label' => $f['field_label'],
    'type' => $f['field_type'],
    'required' => !empty($f['is_required']),
  ];
}, $custom_application_fields)) ?>;
const APP_FIELD_BY_KEY = Object.fromEntries(APP_FIELDS.map(field => [String(field.key || '').toLowerCase(), field]));
let referralLink = '';
const REFERRAL_MESSAGE_PREFIX = 'I have completed my HireAI interview. You can apply using this campaign link: ';
let mandatoryInterviewUrl = '';

function isYesValue(value) {
  return ['yes', 'y', '1', 'true', 'agree', 'agreed', 'willing'].includes(String(value || '').trim().toLowerCase());
}

function applySubmitSuccessState(response) {
  const link = document.getElementById('aiInterviewLink');
  const title = document.getElementById('thankyouTitle');
  const message = document.getElementById('thankyouMessage');
  const requiredPanel = document.getElementById('aiRequiredPanel');
  const referralBox = document.getElementById('referralBox');
  const aiRequired = !!(response.ai_test_required && response.interview_token);
  mandatoryInterviewUrl = aiRequired ? INTERVIEW_URL_PUBLIC + '?t=' + encodeURIComponent(response.interview_token) : '';

  link.style.display = 'none';
  link.classList.remove('mandatory');
  requiredPanel.classList.remove('active');
  referralBox.style.display = 'none';

  if (aiRequired) {
    title.textContent = 'Application Submitted - AI Test Required';
    message.textContent = 'Your application is saved. Please complete the AI interview now to finish this step.';
    requiredPanel.classList.add('active');
    link.href = mandatoryInterviewUrl;
    link.classList.add('mandatory');
    link.innerHTML = '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg> Start Mandatory AI Interview';
    link.style.display = 'inline-flex';
    setTimeout(() => {
      if (mandatoryInterviewUrl && document.getElementById('thankyou').classList.contains('active')) {
        window.location.href = mandatoryInterviewUrl;
      }
    }, 7000);
    return;
  }

  title.textContent = 'Application Submitted!';
  message.textContent = 'Thank you for applying to ' + <?= json_encode($org_name) ?> + '. Our team will review your application and contact you shortly.';
  if (response.referral_link) {
    referralLink = response.referral_link;
    document.getElementById('refWhatsapp').href = 'https://wa.me/?text=' + encodeURIComponent(REFERRAL_MESSAGE_PREFIX + referralLink + '&medium=whatsapp');
    referralBox.style.display = 'block';
  }
}

function gotoSection(id) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  currentSection = parseInt(id.replace('section-', ''));
  updateProgress();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function nextSection(cur) {
  // For section 1, run duplicate checks first (in case user skipped blur)
  if (cur === 1) {
    await Promise.all([checkDuplicate('phone'), checkDuplicate('email')]);
  }
  const e = (DYNAMIC_APPLY && cur === 1) ? validateDynamicFields() : (validators[cur] || (() => []))();
  // Block section 1 advance if a duplicate warning is visible
  if (cur === 1) {
    const phoneWarn = document.getElementById('phoneWarn');
    const emailWarn = document.getElementById('emailWarn');
    if (phoneWarn?.style.display !== 'none' && phoneWarn?.innerHTML) {
      e.push('This phone number is already registered. Please use a different number or contact HR.');
    }
    if (emailWarn?.style.display !== 'none' && emailWarn?.innerHTML) {
      e.push('This email is already registered. Please use a different email or contact HR.');
    }
  }
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
// Check element exists in DOM (used by validators to skip fields hidden via PHP)
function el(id) {
  return !!document.getElementById(id);
}

function radio(name) {
  const c = document.querySelector(`input[name="${name}"]:checked`);
  return c ? c.value : '';
}

function checks(name) {
  return [...document.querySelectorAll(`input[name="${name}"]:checked`)].map(e => e.value).join(', ');
}

function todayYmd() {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 10);
}

function isStrictYmd(value) {
  const s = String(value || '').trim();
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return false;
  const y = Number(m[1]), mo = Number(m[2]), d = Number(m[3]);
  const dt = new Date(Date.UTC(y, mo - 1, d));
  return y >= 1900 && dt.getUTCFullYear() === y && dt.getUTCMonth() === mo - 1 && dt.getUTCDate() === d;
}

function dateError(label, value, opts = {}) {
  if (!value) return `${label} is required.`;
  if (!isStrictYmd(value)) return `${label} must be a valid date in YYYY-MM-DD format.`;
  if (opts.min && value < opts.min) return `${label} cannot be before ${opts.min}.`;
  if (opts.max && value > opts.max) return `${label} cannot be after ${opts.max}.`;
  return '';
}

function isStrictEmail(email) {
  const s = String(email || '').trim();
  if (!/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(s)) return false;
  const [local, domain] = s.split('@');
  if (!local || !domain || local.includes('..') || domain.includes('..')) return false;
  return domain.split('.').every(part => part && !part.startsWith('-') && !part.endsWith('-'));
}

function moneyNumber(value) {
  const cleaned = String(value || '').replace(/,/g, '').replace(/[^\d.]/g, '');
  if (!cleaned || cleaned === '.') return null;
  const n = Number(cleaned);
  return Number.isFinite(n) ? n : null;
}

function dynamicValue(field) {
  const id = 'appField_' + field.id;
  const el = document.getElementById(id);
  if (field.type === 'file') {
    return el && el.files && el.files[0] ? el.files[0].name : '';
  }
  if (field.type === 'multi_select' || field.type === 'checkbox') {
    return [...document.querySelectorAll(`input[name="${id}[]"]:checked,input#${id}:checked`)].map(e => e.value);
  }
  return v(id).trim();
}

function dynamicFieldWrap(field) {
  return document.querySelector(`[data-app-wrap="${field.id}"]`);
}

function isDynamicFieldVisible(field) {
  const wrap = dynamicFieldWrap(field);
  return !wrap || wrap.style.display !== 'none';
}

function dynamicValueByKey(key) {
  const field = APP_FIELD_BY_KEY[String(key || '').toLowerCase()];
  return field ? dynamicValue(field) : '';
}

function clearDynamicField(field) {
  const id = 'appField_' + field.id;
  const el = document.getElementById(id);
  if (field.type === 'multi_select' || field.type === 'checkbox') {
    document.querySelectorAll(`input[name="${id}[]"],input#${id}`).forEach(input => input.checked = false);
  } else if (el) {
    if (field.type === 'file') {
      el.value = '';
      const name = document.getElementById(id + 'Name');
      if (name) { name.textContent = ''; name.style.display = 'none'; }
      el.closest('.file-upload-area')?.classList.remove('has-file');
    } else {
      el.value = '';
    }
  }
}

function setDynamicVisible(key, visible) {
  const field = APP_FIELD_BY_KEY[String(key || '').toLowerCase()];
  if (!field) return;
  const wrap = dynamicFieldWrap(field);
  if (!wrap) return;
  wrap.style.display = visible ? '' : 'none';
  if (!visible) clearDynamicField(field);
}

function applyDynamicFieldLogic() {
  if (!DYNAMIC_APPLY) return;
  const city = String(dynamicValueByKey('city') || dynamicValueByKey('current_city')).trim().toLowerCase();
  const needsRelocation = !!city && city !== 'jaipur';
  setDynamicVisible('relocate', needsRelocation);
  setDynamicVisible('relocate_time', needsRelocation && dynamicValueByKey('relocate') === 'Yes');
  setDynamicVisible('other_country_code', String(dynamicValueByKey('phone_code')).toLowerCase().includes('other'));
  const college = String(dynamicValueByKey('college')).toLowerCase();
  setDynamicVisible('college_other', college.includes('other'));
  const source = String(dynamicValueByKey('source')).toLowerCase();
  setDynamicVisible('source_other', source.includes('other'));
  const industry = String(dynamicValueByKey('industry')).toLowerCase();
  setDynamicVisible('industry_other', industry.includes('other'));
  const videoOption = String(dynamicValueByKey('video_option')).toLowerCase();
  setDynamicVisible('video_link', videoOption.includes('link'));
  setDynamicVisible('video_file', videoOption.includes('upload'));
}

function dynamicConditionalErrors() {
  const e = [];
  const city = String(dynamicValueByKey('city') || dynamicValueByKey('current_city')).trim().toLowerCase();
  if (city && city !== 'jaipur') {
    if (!String(dynamicValueByKey('relocate') || '').trim()) e.push('Comfortable to Relocate is required');
    if (dynamicValueByKey('relocate') === 'Yes' && !String(dynamicValueByKey('relocate_time') || '').trim()) e.push('Relocation Time is required');
  }
  // Validate: hidden phoneCode must have a value (always set from dropdown or manual input)
  const _phoneCode = (document.getElementById('phoneCode')?.value || '').trim();
  if (!_phoneCode) e.push('Please select or enter a country code.');
  if (String(dynamicValueByKey('college')).toLowerCase().includes('other') && !String(dynamicValueByKey('college_other') || '').trim()) e.push('Specify College / University is required');
  if (String(dynamicValueByKey('source')).toLowerCase().includes('other') && !String(dynamicValueByKey('source_other') || '').trim()) e.push('Please specify source is required');
  if (String(dynamicValueByKey('industry')).toLowerCase().includes('other') && !String(dynamicValueByKey('industry_other') || '').trim()) e.push('Specify Industry is required');
  const videoOption = String(dynamicValueByKey('video_option')).toLowerCase();
  if (videoOption.includes('link') && !String(dynamicValueByKey('video_link') || '').trim()) e.push('Video Introduction Link is required');
  if (videoOption.includes('upload') && !String(dynamicValueByKey('video_file') || '').trim()) e.push('Video Introduction File is required');
  return e;
}

async function collectDynamicAnswers() {
  const answers = {};
  for (const field of APP_FIELDS) {
    if (!isDynamicFieldVisible(field)) continue;
    let value = dynamicValue(field);
    let file = null;
    if (field.type === 'file') {
      const el = document.getElementById('appField_' + field.id);
      if (el && el.files && el.files[0]) {
        const f = el.files[0];
        file = { name: f.name, type: f.type, base64: await getBase64(f) };
        value = f.name;
      }
    }
    answers[field.id] = {
      key: field.key,
      label: field.label,
      type: field.type,
      value,
      file
    };
  }
  return answers;
}

function dynamicByKeys(answers, keys) {
  const slug = value => String(value || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '_');
  keys = keys.map(slug);
  for (const ans of Object.values(answers)) {
    const key = slug(ans.key);
    const label = slug(ans.label);
    if (keys.includes(key) || keys.includes(label)) return Array.isArray(ans.value) ? ans.value.join(', ') : String(ans.value || '').trim();
  }
  return '';
}

function validateDynamicFields() {
  applyDynamicFieldLogic();
  const e = [];
  APP_FIELDS.forEach(field => {
    if (!isDynamicFieldVisible(field)) return;
    const value = dynamicValue(field);
    const empty = Array.isArray(value) ? value.length === 0 : !String(value || '').trim();
    if (field.required && empty) e.push(`${field.label} is required`);
    const key = String(field.key || '').toLowerCase();
    if (!empty && (field.type === 'email' || ['email','email_id','email_address'].includes(key)) && !isStrictEmail(value)) {
      e.push(`${field.label} must be a valid email address`);
    }
    if (!empty && (field.type === 'date' || ['dob','date_of_birth','joining_date','preferred_joining_date'].includes(key))) {
      const err = dateError(field.label, String(value), key.includes('joining') ? { min: todayYmd() } : { max: todayYmd() });
      if (err) e.push(err);
    }
  });
  const currentSalary = moneyNumber(dynamicValueByKey('current_salary') || dynamicValueByKey('current_ctc'));
  const expectedSalary = moneyNumber(dynamicValueByKey('expected_salary') || dynamicValueByKey('expected_ctc'));
  if (expectedSalary !== null && currentSalary !== null && expectedSalary < currentSalary) {
    e.push('Expected salary / stipend cannot be lower than current salary / stipend.');
  }
  return e.concat(dynamicConditionalErrors());
}

// Campaign selector
function updateCampaign(id) {
  window._selectedCampaignId = parseInt(id) || 0;
  if (window._selectedCampaignId && window._selectedCampaignId !== CAMPAIGN_ID) {
    window.location.href = '/apply.php?campaign_id=' + encodeURIComponent(window._selectedCampaignId);
    return;
  }
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

function validateDeclaration() {
  const e = [];
  if (!document.getElementById('declaration').checked) e.push('Please confirm the declaration');
  if (!document.getElementById('recordingConsent')?.checked) e.push('Please provide consent for voice/video recording and AI summarisation');
  return e;
}

// Validators — each check uses el(id) to skip fields not rendered by PHP (toggled off)
const validators = {
  1: () => {
    const e = [];
    if (el('salutation') && !v('salutation')) e.push('Salutation required');
    if (el('firstName') && !v('firstName').trim()) e.push('First name required');
    if (el('lastName') && !v('lastName').trim()) e.push('Last name required');
    if (el('dob')) {
      const dobVal = v('dob');
      const maxDobYmd = (() => { const d = new Date(); d.setFullYear(d.getFullYear()-18); return d.toISOString().slice(0,10); })();
      const dobErr = dateError('Date of birth', dobVal, { max: maxDobYmd });
      if (dobErr) {
        e.push(dobVal && dobVal > maxDobYmd ? 'You must be at least 18 years old to apply.' : dobErr);
      }
    }
    if (el('currentCity') && !v('currentCity').trim()) e.push('Current city required');
    if (el('phone')) { const pe = validatePhone(); if (pe) e.push(pe); }
    if (el('email') && !isStrictEmail(v('email'))) e.push('Valid email required');
    if (el('college') && !v('college')) e.push('College/University required');
    if (el('collegeOther') && v('college') === 'Other – specify' && !v('collegeOther').trim()) e.push('Specify your college');
    if (el('source') && !v('source')) e.push('Application source required');
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
    if (el('englishLevel') && !v('englishLevel')) e.push('English level required');
    if (el('yearsExp') && !v('yearsExp')) e.push('Years of experience required');
    const isFresher = v('yearsExp') === 'Fresher';
    if (el('industry') && !isFresher && !v('industry')) e.push('Industry background required');
    if (el('expTypeFieldContainer') && !isFresher && !checks('expType')) e.push('Select at least one experience type');
    if (isFresher) { const ind = document.getElementById('industry'); if (ind) ind.value = 'Fresher / None'; }
    return e;
  },

  4: () => {
    const e = [];
    if (el('expectedSalary')) {
      const expected = moneyNumber(v('expectedSalary'));
      const current = moneyNumber(v('currentSalary'));
      if (expected === null) e.push('Expected salary / stipend must be a valid number.');
      if (expected !== null && current !== null && expected < current) e.push('Expected salary / stipend cannot be lower than current salary / stipend.');
    }
    return e;
  },

  5: () => {
    const e = [];
    const et = v('engagementType');
    if (el('tenure') && et !== 'Employment' && !v('tenure')) e.push('Internship / training tenure is required.');
    if (el('tenure') && et !== 'Employment' && v('tenure') === 'other' && !v('tenureOther').trim()) e.push('Please specify your internship tenure.');
    if (el('joiningDate')) { const joiningErr = dateError('Preferred joining date', v('joiningDate'), { min: todayYmd() }); if (joiningErr) e.push(joiningErr); }
    if (el('flexHours') && !v('flexHours')) e.push('Flexible hours preference is required.');
    return e;
  },

  6: () => {
    const e = [];
    if (el('laptop') && !v('laptop')) e.push('Laptop ownership is required.');
    if (el('internet') && !v('internet')) e.push('Internet availability is required.');
    if (el('commute') && !v('commute')) e.push('Commute preference is required.');
    return e;
  },

  7: () => {
    const e = [];
    const resumeEl = document.getElementById('resumeFile');
    if (resumeEl) {
      if (!resumeEl.files.length) { e.push('Please upload your Resume / CV.'); }
      else if (!checkFileSize('resumeFile', 10)) { e.push('Resume file size must be less than 10 MB.'); }
    }
    if (el('videoOption')) { const vo = v('videoOption'); if (vo === 'link' && !v('videoLinkInput').trim()) e.push('Video URL required'); }
    return e;
  },

  8: () => {
    const e = [];
    if (el('aiTestWilling') && !v('aiTestWilling')) e.push('Please indicate AI test willingness');
    return e;
  },

  9: () => {
    if (!HAS_EXTRA_FIELDS) return validateDeclaration();
    const e = [];
    APP_FIELDS.forEach(field => {
      if (!isDynamicFieldVisible(field)) return;
      const value = dynamicValue(field);
      const empty = Array.isArray(value) ? value.length === 0 : !String(value || '').trim();
      if (field.required && empty) e.push(`${field.label} is required`);
    });
    return e;
  },
  10: () => validateDeclaration()
};

// Remuneration
function updateRemuneration(type) {
  const box = document.getElementById('remunerationBox');
  const text = document.getElementById('remunerationText');
  const tf = document.getElementById('tenureField');
  
  if (!type) {
    box.style.display = 'none';
    if (tf) tf.style.display = 'none'; // hide by default until engagement type chosen
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

// ── Country Code Combobox ────────────────────────────────────────────────────
const CC_OPTIONS = [
  {label:'🇮🇳 +91 India',      code:'+91', min:10, max:10},
  {label:'🇺🇸 +1 USA/Canada',  code:'+1',  min:10, max:10},
  {label:'🇬🇧 +44 UK',         code:'+44', min:10, max:10},
  {label:'🇦🇪 +971 UAE',        code:'+971',min:9,  max:9},
  {label:'🇸🇬 +65 Singapore',   code:'+65', min:8,  max:8},
  {label:'🇲🇾 +60 Malaysia',    code:'+60', min:9,  max:10},
  {label:'🇦🇺 +61 Australia',   code:'+61', min:9,  max:9},
  {label:'🇩🇪 +49 Germany',     code:'+49', min:10, max:11},
  {label:'🇫🇷 +33 France',      code:'+33', min:9,  max:9},
  {label:'🇸🇦 +966 Saudi Arabia',code:'+966',min:9, max:9},
  {label:'🇯🇵 +81 Japan',       code:'+81', min:10, max:11},
  {label:'🇧🇷 +55 Brazil',      code:'+55', min:10, max:11},
  {label:'🇿🇦 +27 South Africa', code:'+27', min:9,  max:9},
  {label:'🇱🇰 +94 Sri Lanka',   code:'+94', min:9,  max:9},
  {label:'🇵🇰 +92 Pakistan',    code:'+92', min:10, max:10},
  {label:'🇧🇩 +880 Bangladesh', code:'+880',min:10, max:10},
  {label:'🇳🇵 +977 Nepal',      code:'+977',min:9,  max:10},
];
let _ccSelected = CC_OPTIONS[0]; // India default

function ccDisplayLabel(opt) {
  // On narrow mobile screens show compact "🇮🇳 +91" instead of full "🇮🇳 +91 India"
  if (window.innerWidth <= 540) {
    const parts = opt.label.split(' ');
    return parts.slice(0, 2).join(' '); // flag + code only
  }
  return opt.label;
}

function ccApply(opt) {
  _ccSelected = opt;
  const inp = document.getElementById('ccInput');
  if (inp) inp.value = ccDisplayLabel(opt);
  document.getElementById('phoneCode').value = opt.code;
  const isIndia = opt.code === '+91';
  const hint = document.getElementById('otherCountryHint');
  const ph   = document.getElementById('phoneHint');
  const phoneEl = document.getElementById('phone');
  if (hint) hint.textContent = isIndia ? '' : `Enter ${opt.min}–${opt.max} digit number`;
  if (ph)   ph.style.display  = isIndia ? 'block' : 'none';
  if (phoneEl) {
    phoneEl.maxLength   = opt.max;
    phoneEl.placeholder = isIndia ? '10-digit mobile number' : 'Phone number';
  }
  ccClose();
}

function ccRenderList(query) {
  const drop = document.getElementById('ccDropdown');
  if (!drop) return;
  const q = (query || '').toLowerCase();
  const matches = CC_OPTIONS.filter(o => !q || o.label.toLowerCase().includes(q) || o.code.includes(q));
  drop.innerHTML = '';
  matches.forEach((opt, i) => {
    const d = document.createElement('div');
    d.className = 'cc-option' + (opt === _ccSelected ? ' selected' : '');
    d.textContent = opt.label;
    d.onmousedown = (e) => { e.preventDefault(); ccApply(opt); };
    drop.appendChild(d);
  });
  // "Use what I typed" option when query looks like a dial code
  if (q && (q.startsWith('+') || /^\d/.test(q))) {
    const sep = document.createElement('div'); sep.className = 'cc-sep'; drop.appendChild(sep);
    const d = document.createElement('div');
    d.className = 'cc-option';
    const raw = q.startsWith('+') ? q : '+' + q;
    d.textContent = '✏️ Use "' + raw + '" as code';
    d.onmousedown = (e) => {
      e.preventDefault();
      document.getElementById('phoneCode').value = raw;
      document.getElementById('ccInput').value = raw;
      const phoneEl = document.getElementById('phone');
      if (phoneEl) { phoneEl.maxLength = 15; phoneEl.placeholder = 'Phone number'; }
      const ph = document.getElementById('phoneHint');
      if (ph) ph.style.display = 'none';
      _ccSelected = null;
      ccClose();
    };
    drop.appendChild(d);
  }
}

function ccFilter() {
  const inp = document.getElementById('ccInput');
  const raw = inp ? inp.value.trim() : '';
  // If typed something that doesn't match a known label, treat as manual code
  if (raw && !CC_OPTIONS.some(o => o.label === raw)) {
    document.getElementById('phoneCode').value = raw.startsWith('+') ? raw : (raw ? '+' + raw : '');
  }
  ccRenderList(raw);
  ccOpen();
}

function ccOpen() {
  const wrap = document.getElementById('ccWrap');
  if (wrap) wrap.classList.add('open');
  ccRenderList(document.getElementById('ccInput')?.value || '');
}

function ccClose() {
  const wrap = document.getElementById('ccWrap');
  if (wrap) wrap.classList.remove('open');
  // If input is blank or invalid, restore selected or India
  const inp = document.getElementById('ccInput');
  if (inp && !inp.value.trim()) { ccApply(CC_OPTIONS[0]); }
}

document.addEventListener('click', (e) => {
  if (!document.getElementById('ccWrap')?.contains(e.target)) ccClose();
});

function handlePhoneCodeChange() { /* handled by combobox */ }
function handleOtherCountryChange() { /* handled by combobox */ }
function handleManualCode() { /* handled by combobox */ }

// dwMask / dwKey removed — Flatpickr handles all date input

// ── Duplicate phone / email check ────────────────────────────────────────────
const _dupCache = {};
async function checkDuplicate(field) {
  const campId = <?= (int)($campaign_id ?? 0) ?>;
  if (!campId) return;

  let value = '';
  let warnEl = null;

  if (field === 'phone') {
    const code = document.getElementById('phoneCode')?.value || '+91';
    const num  = (document.getElementById('phone')?.value || '').trim();
    if (num.length < 8) return; // too short to be meaningful
    value = code + num;
    warnEl = document.getElementById('phoneWarn');
  } else {
    value = (document.getElementById('email')?.value || '').trim().toLowerCase();
    if (!value || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return;
    warnEl = document.getElementById('emailWarn');
  }

  if (!warnEl) return;

  // Clear any previous warning for this field
  warnEl.style.display = 'none';
  warnEl.innerHTML = '';

  // Cache to avoid re-hitting server for same value
  const cacheKey = field + ':' + value;
  if (_dupCache[cacheKey] !== undefined) {
    if (_dupCache[cacheKey]) showDupWarn(field, warnEl);
    return;
  }

  try {
    const params = new URLSearchParams({ campaign_id: campId });
    if (field === 'phone') {
      params.set('phone', (document.getElementById('phone')?.value || '').trim());
    } else {
      params.set('email', value);
    }
    const res  = await fetch('/api/check_duplicate.php?' + params.toString());
    const data = await res.json();
    _dupCache[cacheKey] = !!data.exists;
    if (data.exists) showDupWarn(field, warnEl);
  } catch(e) { /* silent — don't block form on network error */ }
}

function showDupWarn(field, warnEl) {
  const msg = field === 'phone'
    ? 'This phone number is already registered for this campaign. Please use a different number or <strong>contact HR</strong> for assistance.'
    : 'This email address is already registered for this campaign. Please use a different email or <strong>contact HR</strong> for assistance.';
  warnEl.innerHTML = '<span class="dup-warn-icon">⚠️</span><span>' + msg + '</span>';
  warnEl.style.display = 'flex';

  // Mark the input as visually invalid
  const inputId = field === 'phone' ? 'phone' : 'email';
  const inp = document.getElementById(inputId);
  if (inp) { inp.style.borderColor = '#FECDD3'; inp.style.boxShadow = '0 0 0 3px rgba(239,68,68,.1)'; }

  // Reset border on next input
  warnEl.closest('.field')?.querySelector('input')?.addEventListener('input', function() {
    this.style.borderColor = '';
    this.style.boxShadow   = '';
    warnEl.style.display   = 'none';
    warnEl.innerHTML       = '';
  }, { once: true });
}

function handleTenureChange() {
  const isOther = v('tenure') === 'other';
  const otherField = document.getElementById('tenureOtherField');
  if (otherField) otherField.style.display = isOther ? 'block' : 'none';
  if (!isOther && document.getElementById('tenureOther')) document.getElementById('tenureOther').value = '';
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
  const linkDiv   = document.getElementById('videoLinkDiv');
  const uploadDiv = document.getElementById('videoUploadDiv');
  if (!linkDiv || !uploadDiv) return;
  linkDiv.style.display   = o === 'link'   ? 'block' : 'none';
  uploadDiv.style.display = o === 'upload' ? 'block' : 'none';
  // Clear the inactive input so stale data isn't submitted
  if (o !== 'link') {
    const li = document.getElementById('videoLinkInput');
    if (li) li.value = '';
  }
  if (o !== 'upload') {
    const fi = document.getElementById('videoFile');
    if (fi) { fi.value = ''; }
    const fn = document.getElementById('videoFileName');
    if (fn) fn.textContent = '';
  }
}

function showFileName(inputId, displayId) {
  const inp = document.getElementById(inputId);
  const f = inp && inp.files ? inp.files[0] : null;
  if (f) {
    const d = document.getElementById(displayId);
    d.textContent = '✓ ' + f.name;
    d.style.display = 'block';
    document.getElementById(inputId).closest('.file-upload-area')?.classList.add('has-file');
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

function setSubmitLoading(isLoading, message) {
  const overlay = document.getElementById('submitOverlay');
  const text = document.getElementById('submitOverlayText');
  if (text && message) text.innerHTML = message + '<br><span style="font-size:11px;opacity:.7">Please do not close this page.</span>';
  overlay?.classList.toggle('active', !!isLoading);
  document.querySelectorAll('.btn').forEach(btn => btn.disabled = !!isLoading);
}

// Submit
async function submitForm() {
  if (DYNAMIC_APPLY) {
    return submitDynamicForm();
  }
  if (HAS_EXTRA_FIELDS) {
    const dynamicErrs = validators[9]();
    showBanner('val-banner-9', dynamicErrs);
    if (dynamicErrs.length) {
      gotoSection('section-9');
      return;
    }
  }
  const errs = validators[DECLARATION_SECTION]();
  showBanner('val-banner-' + DECLARATION_SECTION, errs);
  if (errs.length) {
    gotoSection('section-' + DECLARATION_SECTION);
    return;
  }
  
  setSubmitLoading(true, 'Submitting your application...');
  
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
      tenure: v('tenure') === 'other' ? g('tenureOther') : g('tenure'),
      joining_date: g('joiningDate'),
      flex_hours: g('flexHours'),
      laptop: g('laptop'),
      internet: g('internet'),
      commute: g('commute'),
      location: g('candidateLocation'),
      tech_skills: checks('techSkills'),
      soft_skills: checks('softSkills'),
      portfolio: g('portfolioLinks'),
      video_option: g('videoOption'),
      video_link: g('videoLinkInput'),
      ai_test_willing: g('aiTestWilling'),
      application_answers: await collectDynamicAnswers(),
      recording_consent: !!document.getElementById('recordingConsent')?.checked,
      ref_token: REF_TOKEN,
      ref_medium: new URLSearchParams(location.search).get('medium') || '',
      timestamp: new Date().toISOString()
    };
    
    // Resume (field may be hidden by std field config)
    const ri = document.getElementById('resumeFile');
    if (ri && ri.files.length) {
      const f = ri.files[0];
      data.resume_name = f.name;
      data.resume_type = f.type;
      data.resume_base64 = await getBase64(f);
    }

    // Video (field may be hidden by std field config)
    const vi = document.getElementById('videoFile');
    if (vi && vi.files.length && g('videoOption') === 'upload') {
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
    applySubmitSuccessState(d);
    
  } catch (err) {
    console.error(err);
    alert('Submission failed. Please try again.\n' + err.message);
    setSubmitLoading(false);
    return;
  }
  
  setSubmitLoading(false);
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.getElementById('thankyou').classList.add('active');
  document.getElementById('progressBar').style.width = '100%';
  document.getElementById('progressLabel').textContent = 'Complete!';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function submitDynamicForm() {
  const fieldErrs = validateDynamicFields();
  showBanner('val-banner-1', fieldErrs);
  if (fieldErrs.length) return;
  const declarationErrs = [];
  if (!document.getElementById('declaration').checked) declarationErrs.push('Please confirm the declaration');
  if (!document.getElementById('recordingConsent')?.checked) declarationErrs.push('Please provide consent for voice/video recording and AI summarisation');
  showBanner('val-banner-2', declarationErrs);
  if (declarationErrs.length) {
    gotoSection('section-2');
    return;
  }

  setSubmitLoading(true, 'Preparing uploads and submitting your application...');
  try {
    const answers = await collectDynamicAnswers();
    const fullName = dynamicByKeys(answers, ['name','full_name','candidate_name']);
    let firstName = dynamicByKeys(answers, ['first_name','firstname']);
    let lastName = dynamicByKeys(answers, ['last_name','lastname']);
    if (!firstName && fullName) {
      const parts = fullName.split(/\s+/);
      firstName = parts.shift() || fullName;
      lastName = parts.join(' ');
    }
    // Collect injected mandatory fields (when not in application_fields)
    const mandPhone = (document.getElementById('mand_phone')?.value || '').trim();
    const mandEmail = (document.getElementById('mand_email')?.value || '').trim();
    const mandName  = (document.getElementById('mand_name')?.value || '').trim();
    // Mandatory field validation
    const mandErrs = [];
    if (document.getElementById('mand_phone') && !mandPhone) mandErrs.push('Phone number is required');
    if (document.getElementById('mand_email') && !mandEmail) mandErrs.push('Email address is required');
    if (document.getElementById('mand_name')  && !mandName)  mandErrs.push('Full name is required');
    if (mandErrs.length) { showBanner('val-banner-1', mandErrs); return; }
    const data = {
      campaign_id: CAMPAIGN_ID,
      salutation: dynamicByKeys(answers, ['salutation','title']),
      first_name: firstName || fullName || mandName || 'Candidate',
      last_name: lastName,
      phone: dynamicByKeys(answers, ['phone','mobile','mobile_number','phone_number','whatsapp','whatsapp_number']) || mandPhone,
      email: dynamicByKeys(answers, ['email','email_id','email_address']) || mandEmail,
      city: dynamicByKeys(answers, ['city','current_city','location']),
      source: dynamicByKeys(answers, ['source','application_source','how_did_you_hear']),
      role_applied: dynamicByKeys(answers, ['role','role_applied','job_role']) || <?= json_encode($job_role) ?>,
      years_exp: dynamicByKeys(answers, ['experience','years_exp','years_of_experience']),
      current_salary: dynamicByKeys(answers, ['current_salary','current_ctc']),
      expected_salary: dynamicByKeys(answers, ['expected_salary','expected_ctc']),
      portfolio: dynamicByKeys(answers, ['portfolio','linkedin','linkedin_profile','github','website']),
      ai_test_willing: dynamicByKeys(answers, ['ai_test_willing','willing_to_take_ai_test','willing_to_take_the_ai_test','ai_test']),
      application_answers: answers,
      recording_consent: !!document.getElementById('recordingConsent')?.checked,
      ref_token: REF_TOKEN,
      ref_medium: new URLSearchParams(location.search).get('medium') || '',
      timestamp: new Date().toISOString()
    };

    const res = await fetch('/api/apply.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    const d = await res.json();
    if (!d.success) throw new Error(d.error || 'Submit failed');
    applySubmitSuccessState(d);
  } catch (err) {
    console.error(err);
    alert('Submission failed. Please try again.\n' + err.message);
    setSubmitLoading(false);
    return;
  }

  setSubmitLoading(false);
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.getElementById('thankyou').classList.add('active');
  document.getElementById('progressBar').style.width = '100%';
  document.getElementById('progressLabel').textContent = 'Complete!';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.getElementById('refCopyBtn')?.addEventListener('click', async () => {
  if (!referralLink) return;
  const medium = document.getElementById('refMedium')?.value || 'copy_link';
  const link = referralLink + '&medium=' + encodeURIComponent(medium);
  try {
    await navigator.clipboard.writeText(REFERRAL_MESSAGE_PREFIX + link);
    alert('Same campaign apply link copied');
  } catch(e) {
    prompt('Copy same campaign apply link', REFERRAL_MESSAGE_PREFIX + link);
  }
});

document.addEventListener('DOMContentLoaded', () => {
  // Default theme: light (midnight available via toggle)
  try {
    const saved = localStorage.getItem('avk_theme');
    if (saved) { document.documentElement.setAttribute('data-theme', saved); }
    else { setTheme('light'); }
  } catch(e) { setTheme('light'); }
  updateProgress();

  const phoneInput = document.getElementById('phone');
  if (phoneInput) phoneInput.addEventListener('input', () => {
    const code = document.getElementById('phoneCode')?.value || '+91';
    if (code === '+91') {
      const i = document.getElementById('phone');
      i.value = i.value.replace(/\D/g, '').slice(0, 10);
    }
    document.getElementById('phone').classList.remove('input-invalid');
  });
  
  // date min/max now enforced via PHP attributes on the dw-seg inputs, not JS

  // Init country code combobox — India default (compact label on mobile)
  if (document.getElementById('ccInput')) ccApply(CC_OPTIONS[0]);
  // Re-apply compact/full label on orientation change
  window.addEventListener('resize', () => {
    if (_ccSelected) {
      const inp = document.getElementById('ccInput');
      if (inp && !document.getElementById('ccWrap')?.classList.contains('open')) {
        inp.value = ccDisplayLabel(_ccSelected);
      }
    }
  });

  // Sync tenure visibility to whichever engagement type is already selected
  const engEl = document.getElementById('engagementType');
  if (engEl) updateRemuneration(engEl.value || '');
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
  if (!fi) return true;
  return !(fi.files.length > 0 && fi.files[0].size > maxMB * 1024 * 1024);
}

document.addEventListener('DOMContentLoaded', () => {
  if (!DYNAMIC_APPLY) return;
  APP_FIELDS.forEach(field => {
    const id = 'appField_' + field.id;
    document.querySelectorAll(`#${id},input[name="${id}[]"]`).forEach(input => {
      input.addEventListener('change', applyDynamicFieldLogic);
      input.addEventListener('input', applyDynamicFieldLogic);
    });
  });
  applyDynamicFieldLogic();
});

updateProgress();
</script>

<script>
function initDatePicker(displayId, hiddenId, opts) {
  const display = document.getElementById(displayId);
  const hidden  = document.getElementById(hiddenId);
  if (!display || !hidden) return null;
  const fp = flatpickr(display, Object.assign({
    dateFormat  : 'd/m/Y',
    allowInput  : false,
    disableMobile: false,
    onChange(dates) {
      if (!dates[0]) { hidden.value = ''; return; }
      const d = dates[0];
      hidden.value = d.getFullYear() + '-'
        + String(d.getMonth()+1).padStart(2,'0') + '-'
        + String(d.getDate()).padStart(2,'0');
    },
    onClose() { display.blur(); }
  }, opts));
  // Both the input field AND the calendar icon open the picker
  display.addEventListener('click', () => fp.open());
  document.getElementById(displayId.replace('-display','-cal-icon'))
    ?.addEventListener('click', () => fp.toggle());
  return fp;
}

document.addEventListener('DOMContentLoaded', function() {
  const today = new Date();
  const maxJd  = new Date(); maxJd.setFullYear(maxJd.getFullYear() + 5);

  const maxDob = new Date(); maxDob.setFullYear(maxDob.getFullYear() - 18);
  initDatePicker('dob-display', 'dob', {
    maxDate : maxDob,
    minDate : '01/01/1900',
  });

  initDatePicker('joiningDate-display', 'joiningDate', {
    minDate : today,
    maxDate : maxJd,
  });
});
</script>

</body>
</html>
