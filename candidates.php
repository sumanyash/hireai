<?php
require_once __DIR__ . '/includes/auth_check.php';
$campaigns = db_fetch_all("SELECT id, name, job_role FROM campaigns WHERE org_id=? ORDER BY name", [$user['org_id']], 'i');
$sel_campaign = (int)($_GET['campaign_id'] ?? 0);
$search = trim($_GET['q'] ?? '');

$where  = "c.org_id=?";
$params = [$user['org_id']];
$types  = 'i';
if ($sel_campaign) { $where .= " AND c.campaign_id=?"; $params[] = $sel_campaign; $types .= 'i'; }
if ($search) {
    $like = "%$search%";
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss';
}

$candidates = db_fetch_all(
    "SELECT c.*, camp.name campaign_name, ir.total_score, ir.pass_fail, ir.id result_id
     FROM candidates c
     LEFT JOIN campaigns camp ON c.campaign_id=camp.id
     LEFT JOIN interview_results ir ON c.id=ir.candidate_id
     WHERE $where ORDER BY c.created_at DESC",
    [$params], $types
);
$total = count($candidates);
$status_counts = [];
foreach ($candidates as $c) $status_counts[$c['status']] = ($status_counts[$c['status']] ?? 0) + 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Candidates — HireAI</title>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
/* ── PREMIUM CANDIDATES UI ───────────────────────────────── */
.page-hero{background:linear-gradient(135deg,#0F172A 0%,#1E293B 50%,#0F2044 100%);border-radius:20px;padding:28px 32px;margin-bottom:24px;position:relative;overflow:hidden}
.page-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:240px;height:240px;background:radial-gradient(circle,rgba(37,99,235,.3) 0%,transparent 70%);pointer-events:none}
.page-hero::after{content:'';position:absolute;bottom:-60px;left:20%;width:300px;height:300px;background:radial-gradient(circle,rgba(124,58,237,.15) 0%,transparent 70%);pointer-events:none}
.hero-title{font-size:26px;font-weight:900;color:#fff;letter-spacing:-.5px;margin-bottom:4px}
.hero-sub{font-size:13px;color:rgba(255,255,255,.5);margin-bottom:20px}
.hero-stats{display:flex;gap:20px;flex-wrap:wrap}
.hstat{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:10px 18px;text-align:center;backdrop-filter:blur(8px)}
.hstat-num{font-size:22px;font-weight:900;color:#fff;line-height:1}
.hstat-lbl{font-size:10px;color:rgba(255,255,255,.45);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-top:2px}

/* ── FILTERS ─────────────────────────────────────────────── */
.filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#fff;border-radius:16px;padding:14px 18px;box-shadow:0 1px 8px rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.04);margin-bottom:20px}
.search-wrap{position:relative;flex:1;min-width:200px}
.search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--gray);font-size:13px;pointer-events:none}
.search-input{width:100%;padding:9px 14px 9px 36px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:13px;transition:all .2s;background:#F8FAFC}
.search-input:focus{outline:none;border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.filter-select{padding:9px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:13px;background:#F8FAFC;cursor:pointer;color:var(--text);transition:all .2s}
.filter-select:focus{outline:none;border-color:var(--blue)}

/* ── STATUS PILLS ────────────────────────────────────────── */
.status-scroll{display:flex;gap:8px;margin-bottom:16px;overflow-x:auto;padding-bottom:4px}
.status-scroll::-webkit-scrollbar{height:3px}
.status-scroll::-webkit-scrollbar-thumb{background:#E2E8F0;border-radius:99px}
.spill{padding:7px 16px;border-radius:99px;font-size:12px;font-weight:700;cursor:pointer;border:2px solid transparent;white-space:nowrap;transition:all .2s;display:flex;align-items:center;gap:5px}
.spill.active{border-color:var(--blue);background:var(--blue);color:#fff}
.spill:not(.active){background:#F1F5F9;color:var(--gray2)}
.spill:not(.active):hover{background:#E2E8F0}
.spill-count{background:rgba(255,255,255,.3);padding:1px 6px;border-radius:99px;font-size:10px;font-weight:800}
.spill:not(.active) .spill-count{background:rgba(0,0,0,.08)}

/* ── TABLE ───────────────────────────────────────────────── */
.cand-table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
.cand-table th{padding:10px 14px;text-align:left;font-size:10px;font-weight:800;color:var(--gray);text-transform:uppercase;letter-spacing:.7px;background:#F8FAFC;border-bottom:2px solid #E2E8F0;white-space:nowrap}
.cand-table th:first-child{border-radius:10px 0 0 0}
.cand-table th:last-child{border-radius:0 10px 0 0}
.cand-table td{padding:12px 14px;border-bottom:1px solid #F1F5F9;vertical-align:middle;transition:background .15s}
.cand-table tr:hover td{background:#F8FAFC}
.cand-table tr:last-child td{border-bottom:none}
.cand-avatar{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--blue),var(--accent));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:13px;flex-shrink:0}
.cname-cell{display:flex;align-items:center;gap:10px}
.cname{font-weight:700;color:var(--text);font-size:13px}
.cphone{font-size:11px;color:var(--gray);margin-top:1px}
.score-pill{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:700}
.score-pass{background:#ECFDF5;color:#065F46}
.score-fail{background:#FEF2F2;color:#991B1B}
.score-pending{background:#F1F5F9;color:var(--gray)}
.act-btns{display:flex;gap:6px;opacity:0;transition:opacity .2s}
.cand-table tr:hover .act-btns{opacity:1}
.act-btn{width:30px;height:30px;border-radius:8px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;transition:all .15s}
.act-view{background:#EFF6FF;color:#1E40AF}.act-view:hover{background:#DBEAFE}
.act-del{background:#FEF2F2;color:#991B1B}.act-del:hover{background:#FECACA}
.act-call{background:#ECFDF5;color:#065F46}.act-call:hover{background:#D1FAE5}
.empty-state{text-align:center;padding:60px 20px;color:var(--gray)}
.empty-icon{font-size:48px;opacity:.2;margin-bottom:12px}

/* ── ADD MODAL ───────────────────────────────────────────── */
.add-modal-overlay{display:none;position:fixed;inset:0;background:rgba(8,15,30,.7);backdrop-filter:blur(12px);z-index:2000;align-items:center;justify-content:center;padding:20px}
.add-modal-overlay.active{display:flex;animation:fadeIn .2s}
.add-modal{background:#fff;border-radius:24px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 32px 100px rgba(0,0,0,.35);animation:slideUp .3s cubic-bezier(.4,0,.2,1)}
.add-modal-header{padding:28px 28px 0;display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
.add-modal-title{font-size:20px;font-weight:900;color:var(--text);letter-spacing:-.3px}
.add-modal-body{padding:0 28px 28px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:500px){.form-row{grid-template-columns:1fr}}
.field-group{margin-bottom:14px}
.field-label{font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:block}
.field-label span{color:#EF4444}
.field-input{width:100%;padding:10px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;transition:all .2s;background:#F8FAFC;box-sizing:border-box}
.field-input:focus{outline:none;border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.field-input.error{border-color:#EF4444;background:#FEF2F2}
.field-error{font-size:11px;color:#EF4444;margin-top:4px;display:none}
.field-error.show{display:block}
.add-btn-row{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;padding-top:20px;border-top:1px solid #F1F5F9}
.bulk-tab-btns{display:flex;gap:6px;background:#F1F5F9;padding:4px;border-radius:10px;margin-bottom:20px}
.btab{flex:1;padding:7px 14px;border-radius:7px;border:none;background:transparent;font-size:13px;font-weight:600;color:var(--gray2);cursor:pointer;transition:all .2s}
.btab.active{background:#fff;color:var(--text);box-shadow:0 1px 5px rgba(0,0,0,.1)}
.bulk-area{display:none}
.bulk-area.active{display:block}
.bulk-textarea{width:100%;min-height:120px;padding:12px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:13px;font-family:monospace;resize:vertical;background:#F8FAFC;box-sizing:border-box}
.bulk-textarea:focus{outline:none;border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.bulk-hint{font-size:11px;color:var(--gray);margin-top:6px;background:#F8FAFC;border-radius:8px;padding:8px 10px;border:1px solid #E2E8F0}

/* ── CONFIRM DELETE ──────────────────────────────────────── */
.del-overlay{display:none;position:fixed;inset:0;background:rgba(8,15,30,.75);backdrop-filter:blur(10px);z-index:3000;align-items:center;justify-content:center;padding:20px}
.del-overlay.active{display:flex;animation:fadeIn .2s}
.del-box{background:#fff;border-radius:20px;padding:36px 32px;max-width:400px;width:100%;text-align:center;box-shadow:0 24px 80px rgba(0,0,0,.3);animation:slideUp .25s cubic-bezier(.4,0,.2,1)}

/* ── TOAST ───────────────────────────────────────────────── */
.toast{position:fixed;bottom:28px;right:28px;z-index:9999;padding:14px 20px;border-radius:14px;font-size:14px;font-weight:600;color:#fff;display:flex;align-items:center;gap:10px;box-shadow:0 8px 40px rgba(0,0,0,.25);animation:toastIn .3s cubic-bezier(.4,0,.2,1);pointer-events:none;max-width:340px}
.t-success{background:linear-gradient(135deg,#059669,#10B981)}
.t-error{background:linear-gradient(135deg,#DC2626,#EF4444)}
.t-info{background:linear-gradient(135deg,#1D4ED8,#3B82F6)}
@keyframes toastIn{from{opacity:0;transform:translateY(16px) scale(.96)}to{opacity:1;transform:none}}
@keyframes toastOut{to{opacity:0;transform:translateY(16px) scale(.96)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">

<!-- HERO -->
<div class="page-hero animate-in">
  <div class="hero-title">👥 Candidates</div>
  <div class="hero-sub">Manage your candidate pipeline across all campaigns</div>
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
    <div class="hero-stats">
      <?php
      $stats = [
        'Total' => $total,
        'Shortlisted' => $status_counts['shortlisted'] ?? 0,
        'Completed' => $status_counts['interview_completed'] ?? 0,
        'Rejected' => $status_counts['rejected'] ?? 0,
      ];
      foreach ($stats as $lbl => $num): ?>
      <div class="hstat">
        <div class="hstat-num"><?= $num ?></div>
        <div class="hstat-lbl"><?= $lbl ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <button onclick="openAddModal()" class="btn-primary" style="padding:10px 22px;font-size:14px;white-space:nowrap">
      <i class="fa-solid fa-plus fa-sm"></i> Add Candidate
    </button>
  </div>
</div>

<!-- FILTER BAR -->
<form method="GET" class="filter-bar animate-in">
  <div class="search-wrap">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input class="search-input" type="text" name="q" placeholder="Search name, phone, email..." value="<?= htmlspecialchars($search) ?>" oninput="this.form.submit()">
  </div>
  <select class="filter-select" name="campaign_id" onchange="this.form.submit()">
    <option value="">All Campaigns</option>
    <?php foreach ($campaigns as $camp): ?>
    <option value="<?= $camp['id'] ?>" <?= $sel_campaign === (int)$camp['id'] ? 'selected' : '' ?>>
      <?= htmlspecialchars($camp['name']) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <?php if ($search || $sel_campaign): ?>
  <a href="candidates.php" class="btn-outline" style="padding:9px 14px;font-size:13px">
    <i class="fa-solid fa-xmark fa-sm"></i> Clear
  </a>
  <?php endif; ?>
</form>

<!-- STATUS PILLS -->
<?php
$statuses = ['all'=>'All','pending'=>'Pending','outreach_sent'=>'Outreached','interview_started'=>'In Progress','interview_completed'=>'Completed','shortlisted'=>'Shortlisted','rejected'=>'Rejected','on_hold'=>'On Hold'];
$active_status = $_GET['status'] ?? 'all';
?>
<div class="status-scroll animate-in">
  <?php foreach ($statuses as $val => $lbl):
    $cnt = $val === 'all' ? $total : ($status_counts[$val] ?? 0);
    $href = '?' . http_build_query(array_merge($_GET, ['status' => $val]));
    ?>
  <a href="<?= $href ?>" class="spill <?= $active_status === $val ? 'active' : '' ?>"
     style="text-decoration:none">
    <?= $lbl ?><span class="spill-count"><?= $cnt ?></span>
  </a>
  <?php endforeach; ?>
</div>

<!-- TABLE -->
<div class="card animate-in" style="padding:0;overflow:hidden">
  <?php
  $filtered = $candidates;
  if ($active_status !== 'all') $filtered = array_filter($candidates, fn($c) => $c['status'] === $active_status);
  $filtered = array_values($filtered);
  ?>
  <table class="cand-table">
    <thead>
      <tr>
        <th>Candidate</th>
        <th>Campaign</th>
        <th>Status</th>
        <th>Score</th>
        <th>Applied</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($filtered)): ?>
    <tr>
      <td colspan="6">
        <div class="empty-state">
          <div class="empty-icon">👥</div>
          <div style="font-size:16px;font-weight:700;margin-bottom:6px">No candidates found</div>
          <div style="font-size:13px;margin-bottom:16px">Add your first candidate to get started</div>
          <button onclick="openAddModal()" class="btn-primary">
            <i class="fa-solid fa-plus fa-sm"></i> Add Candidate
          </button>
        </div>
      </td>
    </tr>
    <?php else: foreach ($filtered as $c):
      $initials = strtoupper(implode('', array_map(fn($w) => $w[0], explode(' ', trim($c['name'])))));
      $initials = substr($initials, 0, 2);
      $score = $c['total_score'];
      $pf = $c['pass_fail'] ?? null;
    ?>
    <tr>
      <td>
        <div class="cname-cell">
          <div class="cand-avatar"><?= htmlspecialchars($initials) ?></div>
          <div>
            <div class="cname"><?= htmlspecialchars($c['name']) ?></div>
            <div class="cphone"><?= htmlspecialchars($c['phone'] ?? $c['email'] ?? '—') ?></div>
          </div>
        </div>
      </td>
      <td style="font-size:12px;color:var(--gray2)"><?= htmlspecialchars($c['campaign_name'] ?? '—') ?></td>
      <td><span class="badge badge-<?= $c['status'] ?>"><?= ucfirst(str_replace('_', ' ', $c['status'])) ?></span></td>
      <td>
        <?php if ($score !== null): ?>
        <span class="score-pill <?= $pf === 'pass' ? 'score-pass' : 'score-fail' ?>">
          <i class="fa-solid fa-<?= $pf === 'pass' ? 'circle-check' : 'circle-xmark' ?> fa-xs"></i>
          <?= $score ?>
        </span>
        <?php else: ?>
        <span class="score-pill score-pending"><i class="fa-regular fa-clock fa-xs"></i> Pending</span>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;color:var(--gray)"><?= $c['created_at'] ? date('d M Y', strtotime($c['created_at'])) : '—' ?></td>
      <td>
        <div class="act-btns">
          <a href="candidate_detail.php?id=<?= $c['id'] ?>" class="act-btn act-view" title="View">
            <i class="fa-solid fa-eye"></i>
          </a>
          <?php if (!empty($c['phone'])): ?>
          <a href="tel:<?= htmlspecialchars($c['phone']) ?>" class="act-btn act-call" title="Call">
            <i class="fa-solid fa-phone"></i>
          </a>
          <button onclick="sendWA(<?= $c['id'] ?>,'<?= htmlspecialchars($c['name'] ?: $c['phone']) ?>')"
            class="act-btn" style="background:#25D36620;color:#25D366;border:1px solid #25D36640" title="Send WhatsApp Invite">
            <i class="fa-brands fa-whatsapp"></i>
          </button>
          <?php endif; ?>
          <button onclick="deleteCand(<?= $c['id'] ?>,'<?= addslashes(htmlspecialchars($c['name'])) ?>')"
            class="act-btn act-del" title="Delete">
            <i class="fa-solid fa-trash"></i>
          </button>
        </div>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<!-- ══ ADD CANDIDATE MODAL ══════════════════════════════════ -->
<div class="add-modal-overlay" id="addModal">
  <div class="add-modal">
    <div class="add-modal-header">
      <div class="add-modal-title">➕ Add Candidate</div>
      <button onclick="closeAdd()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--gray);line-height:1">✕</button>
    </div>
    <div class="add-modal-body">

      <!-- Single / Bulk tabs -->
      <div class="bulk-tab-btns">
        <button class="btab active" onclick="switchAddTab('single',this)">
          <i class="fa-solid fa-user fa-xs"></i> Single
        </button>
        <button class="btab" onclick="switchAddTab('bulk',this)">
          <i class="fa-solid fa-users fa-xs"></i> Bulk Import
        </button>
      </div>

      <!-- SINGLE FORM -->
      <div class="bulk-area active" id="addTab-single">
        <div class="field-group">
          <label class="field-label">Campaign <span>*</span></label>
          <select class="field-input" id="addCampaign">
            <option value="">Select campaign...</option>
            <?php foreach ($campaigns as $camp): ?>
            <option value="<?= $camp['id'] ?>"><?= htmlspecialchars($camp['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="field-error" id="err-campaign">Please select a campaign</div>
        </div>
        <div class="form-row">
          <div class="field-group">
            <label class="field-label">Full Name <span>*</span></label>
            <input class="field-input" type="text" id="addName" placeholder="John Doe">
            <div class="field-error" id="err-name">Name is required</div>
          </div>
          <div class="field-group">
            <label class="field-label">Phone</label>
            <input class="field-input" type="tel" id="addPhone" placeholder="+91 98765 43210">
          </div>
        </div>
        <div class="form-row">
          <div class="field-group">
            <label class="field-label">Email</label>
            <input class="field-input" type="email" id="addEmail" placeholder="john@example.com">
          </div>
          <div class="field-group">
            <label class="field-label">City</label>
            <input class="field-input" type="text" id="addCity" placeholder="Mumbai">
          </div>
        </div>
        <div class="form-row">
          <div class="field-group">
            <label class="field-label">Experience (years)</label>
            <input class="field-input" type="number" id="addExp" placeholder="3" min="0">
          </div>
          <div class="field-group">
            <label class="field-label">Current CTC</label>
            <input class="field-input" type="text" id="addCtc" placeholder="5 LPA">
          </div>
        </div>
        <div class="field-group">
          <label class="field-label">Source</label>
          <select class="field-input" id="addSource">
            <option value="">Select source...</option>
            <option>LinkedIn</option><option>Naukri</option><option>Indeed</option>
            <option>Referral</option><option>Walk-in</option><option>Website</option><option>Other</option>
          </select>
        </div>
        <div class="add-btn-row">
          <button class="btn-outline" onclick="closeAdd()">Cancel</button>
          <button class="btn-primary" onclick="submitAdd()" id="addSubmitBtn">
            <i class="fa-solid fa-plus fa-sm"></i> Add Candidate
          </button>
        </div>
      </div>

      <!-- BULK FORM -->
      <div class="bulk-area" id="addTab-bulk">
        <div class="field-group">
          <label class="field-label">Campaign <span>*</span></label>
          <select class="field-input" id="bulkCampaign">
            <option value="">Select campaign...</option>
            <?php foreach ($campaigns as $camp): ?>
            <option value="<?= $camp['id'] ?>"><?= htmlspecialchars($camp['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-group">
          <label class="field-label">Paste Data</label>
          <textarea class="bulk-textarea" id="bulkData" placeholder="John Doe, +91 9876543210, john@email.com
Jane Smith, +91 9123456789
Ravi Kumar, , ravi@email.com"></textarea>
          <div class="bulk-hint">
            <strong>Format:</strong> Name, Phone, Email (one per line) &nbsp;|&nbsp; Phone & Email are optional
          </div>
        </div>
        <div class="add-btn-row">
          <button class="btn-outline" onclick="closeAdd()">Cancel</button>
          <button class="btn-primary" onclick="submitBulk()" id="bulkSubmitBtn">
            <i class="fa-solid fa-file-import fa-sm"></i> Import All
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- DELETE CONFIRM -->
<div class="del-overlay" id="delModal">
  <div class="del-box">
    <div style="font-size:52px;margin-bottom:16px">🗑️</div>
    <div style="font-size:20px;font-weight:800;margin-bottom:8px">Delete Candidate?</div>
    <div style="font-size:14px;color:var(--gray2);margin-bottom:24px;line-height:1.6">
      Delete <strong id="delNameSpan"></strong> and all their interview data?<br>
      <span style="color:#EF4444;font-weight:700">This cannot be undone.</span>
    </div>
    <div style="display:flex;gap:12px;justify-content:center">
      <button class="btn-outline" onclick="document.getElementById('delModal').classList.remove('active')">Cancel</button>
      <button class="btn-danger" id="confirmDelBtn">
        <i class="fa-solid fa-trash fa-sm"></i> Delete
      </button>
    </div>
  </div>
</div>

<script>
const SESSION_TOKEN = '<?= $_SESSION['token'] ?? '' ?>';
if (SESSION_TOKEN) localStorage.setItem('hireai_token', SESSION_TOKEN);

// ── ADD MODAL ─────────────────────────────────────────────────
function openAddModal() { document.getElementById('addModal').classList.add('active'); }
function closeAdd()     { document.getElementById('addModal').classList.remove('active'); }

document.getElementById('addModal').addEventListener('click', function(e) {
  if (e.target === this) closeAdd();
});

function switchAddTab(tab, btn) {
  document.querySelectorAll('.bulk-area').forEach(a => a.classList.remove('active'));
  document.querySelectorAll('.btab').forEach(b => b.classList.remove('active'));
  document.getElementById('addTab-' + tab).classList.add('active');
  btn.classList.add('active');
}

// ── VALIDATION ────────────────────────────────────────────────
function validate(id, errId, cond) {
  const el = document.getElementById(id), er = document.getElementById(errId);
  if (!cond) { el.classList.add('error'); er.classList.add('show'); return false; }
  el.classList.remove('error'); er.classList.remove('show'); return true;
}

async function submitAdd() {
  const camp = document.getElementById('addCampaign').value;
  const name = document.getElementById('addName').value.trim();
  if (!validate('addCampaign','err-campaign', camp)) return;
  if (!validate('addName','err-name', name)) return;

  const btn = document.getElementById('addSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-xs"></i> Adding...';

  try {
    const r = await fetch('/api/candidates.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'add',
        campaign_id: parseInt(camp),
        name,
        phone: document.getElementById('addPhone').value.trim(),
        email: document.getElementById('addEmail').value.trim(),
        city: document.getElementById('addCity').value.trim(),
        experience_years: document.getElementById('addExp').value.trim(),
        current_ctc: document.getElementById('addCtc').value.trim(),
        source: document.getElementById('addSource').value,
      })
    });
    const d = await r.json();
    if (d.success) {
      closeAdd();
      showToast('✅ ' + (d.message || 'Candidate added!'), 'success');
      setTimeout(() => location.reload(), 900);
    } else {
      showToast('❌ ' + (d.error || 'Failed'), 'error');
    }
  } catch(e) {
    showToast('Network error. Try again.', 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-plus fa-sm"></i> Add Candidate';
  }
}

async function submitBulk() {
  const camp = document.getElementById('bulkCampaign').value;
  const raw  = document.getElementById('bulkData').value.trim();
  if (!camp) { showToast('Please select a campaign', 'error'); return; }
  if (!raw)  { showToast('Please paste candidate data', 'error'); return; }

  const rows = raw.split('\n').map(line => {
    const p = line.split(',').map(s => s.trim());
    return { name: p[0] || '', phone: p[1] || '', email: p[2] || '' };
  }).filter(r => r.name);

  if (!rows.length) { showToast('No valid rows found', 'error'); return; }

  const btn = document.getElementById('bulkSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin fa-xs"></i> Importing ${rows.length}...`;

  try {
    const r = await fetch('/api/candidates.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'bulk_import', campaign_id: parseInt(camp), rows })
    });
    const d = await r.json();
    if (d.success) {
      closeAdd();
      showToast(`✅ Added ${d.added} | Dupes skipped: ${d.dupes} | Errors: ${d.errors}`, 'success');
      setTimeout(() => location.reload(), 1200);
    } else {
      showToast('❌ ' + (d.error || 'Import failed'), 'error');
    }
  } catch(e) {
    showToast('Network error', 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-file-import fa-sm"></i> Import All';
  }
}

// ── WHATSAPP INVITE ───────────────────────────────────────────
async function sendWA(id, name) {
  if (!confirm(`Send WhatsApp interview invite to ${name}?`)) return;
  showToast('Sending WhatsApp...', 'info');
  try {
    const r = await fetch(`/api/outreach.php?action=send_single&candidate_id=${id}`, {
      headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hireai_token') || '') }
    });
    const d = await r.json();
    if (d.status === 'sent') {
      showToast('✅ WhatsApp sent successfully!', 'success');
      setTimeout(() => location.reload(), 1500);
    } else {
      showToast('❌ ' + (d.message || d.error || 'Failed to send'), 'error');
    }
  } catch(e) {
    showToast('❌ Network error', 'error');
  }
}

// ── DELETE ────────────────────────────────────────────────────
let _delId = null;
function deleteCand(id, name) {
  _delId = id;
  document.getElementById('delNameSpan').textContent = name;
  document.getElementById('delModal').classList.add('active');
}

document.getElementById('confirmDelBtn').addEventListener('click', async () => {
  const btn = document.getElementById('confirmDelBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-xs"></i> Deleting...';
  try {
    const r = await fetch('/api/candidates.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', candidate_id: _delId })
    });
    const d = await r.json();
    if (d.success) {
      showToast('Candidate deleted', 'success');
      setTimeout(() => location.reload(), 800);
    } else {
      showToast('Error: ' + (d.error || 'Delete failed'), 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-trash fa-sm"></i> Delete';
    }
  } catch(e) {
    showToast('Network error', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-trash fa-sm"></i> Delete';
  }
});

document.getElementById('delModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('active');
});

// ── TOAST ─────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
  const icons = { success: 'circle-check', error: 'circle-xmark', info: 'circle-info' };
  const t = document.createElement('div');
  t.className = `toast t-${type}`;
  t.innerHTML = `<i class="fa-solid fa-${icons[type]||'circle-check'}"></i>${msg}`;
  document.body.appendChild(t);
  setTimeout(() => {
    t.style.animation = 'toastOut .3s forwards';
    setTimeout(() => t.remove(), 300);
  }, 3500);
}

// ── KEYBOARD ──────────────────────────────────────────────────
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.getElementById('addModal').classList.remove('active');
    document.getElementById('delModal').classList.remove('active');
  }
  if (e.key === 'n' && !e.target.matches('input,textarea,select')) openAddModal();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
