<?php
require_once __DIR__ . '/includes/auth_check.php';

$campaigns = db_fetch_all(
    "SELECT * FROM campaigns WHERE org_id=? AND status='active' ORDER BY created_at DESC",
    [$user['org_id']], 'i'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Outreach — HireAI</title>
<?php include __DIR__ . '/includes/head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:center">
    <div><h2>📤 Outreach</h2><p>Send interview invites to candidates</p></div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Bulk Send WhatsApp Invites</h3></div>
    <div style="margin-bottom:20px">
      <label class="form-label">Select Campaign</label>
      <select id="campaign-select" class="form-control" style="max-width:300px">
        <option value="">-- Select Campaign --</option>
        <?php foreach ($campaigns as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="stats" style="display:none;margin-bottom:16px" class="alert alert-info"></div>

    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <button onclick="loadPending()" class="btn-sm">🔍 Load Pending Candidates</button>
      <button onclick="sendAll()" class="btn-primary">📲 Send WhatsApp to All Pending</button>
      <button onclick="startAllCalls()" class="btn-sm" style="color:#0066FF;border-color:#0066FF">🎤 Start AI Calls for All</button>
    </div>

    <div id="candidate-list" style="margin-top:20px"></div>
  </div>
</div>

<script>
async function loadPending() {
    const cid = document.getElementById('campaign-select').value;
    if (!cid) { alert('Please select a campaign'); return; }
    const r = await fetch(`candidates.php?campaign_id=${cid}&status=pending`);
    document.getElementById('stats').style.display = 'block';
    document.getElementById('stats').textContent = 'Redirecting to candidates...';
    window.location.href = `candidates.php?campaign_id=${cid}&status=pending`;
}

async function sendAll() {
    const cid = document.getElementById('campaign-select').value;
    if (!cid) { alert('Please select a campaign'); return; }
    if (!confirm('Send WhatsApp invites to ALL pending candidates in this campaign?')) return;

    const statsEl = document.getElementById('stats');
    statsEl.style.display = 'block';
    statsEl.textContent = '⏳ Sending...';

    // Get all pending candidates
    const r = await fetch(`api/outreach.php?action=send_campaign&campaign_id=${cid}`);
    const d = await r.json();
    statsEl.className = 'alert alert-success';
    statsEl.textContent = d.message || d.error;
}

async function startAllCalls() {
    const cid = document.getElementById('campaign-select').value;
    if (!cid) { alert('Please select a campaign'); return; }
    if (!confirm('Start AI phone calls for ALL pending candidates?')) return;

    const statsEl = document.getElementById('stats');
    statsEl.style.display = 'block';
    statsEl.textContent = '⏳ Initiating calls...';

    const r = await fetch(`api/outreach.php?action=call_campaign&campaign_id=${cid}`);
    const d = await r.json();
    statsEl.textContent = d.message || d.error;
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
