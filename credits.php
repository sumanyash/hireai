<?php
require_once __DIR__ . '/includes/auth_check.php';

$summary = ensure_credit_wallet($user['org_id']);
$transactions = db_fetch_all("SELECT * FROM credit_transactions WHERE org_id=? ORDER BY created_at DESC LIMIT 25", [$user['org_id']], 'i');
$usage = db_fetch_all(
    "SELECT cu.*, c.name candidate_name, camp.name campaign_name
     FROM credit_usage cu
     LEFT JOIN candidates c ON cu.candidate_id=c.id
     LEFT JOIN campaigns camp ON cu.campaign_id=camp.id
     WHERE cu.org_id=?
     ORDER BY cu.created_at DESC
     LIMIT 30",
    [$user['org_id']], 'i'
);
$channels = [
    'whatsapp' => ['WhatsApp', 'fa-brands fa-whatsapp', '#16A34A', (int)$summary['whatsapp_credits']],
    'sms' => ['SMS', 'fa-solid fa-comment-sms', '#2563EB', (int)$summary['sms_credits']],
    'email' => ['Email', 'fa-solid fa-envelope', '#7C3AED', (int)$summary['email_credits']],
    'rcs' => ['RCS', 'fa-solid fa-message', '#F59E0B', (int)$summary['rcs_credits']],
];
$low = [];
foreach ($channels as $key => $meta) {
    if ($meta[3] <= (int)$summary['low_balance_threshold']) $low[] = $meta[0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Credits & Payments — HireAI</title>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
.credit-hero{background:linear-gradient(135deg,#0F172A,#1D2454 55%,#3B1766);border-radius:20px;padding:28px 32px;margin-bottom:24px;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;position:relative;overflow:hidden}
.credit-hero::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.08) 1px,transparent 1px);background-size:24px 24px;pointer-events:none}
.credit-hero>*{position:relative}
.credit-title{font-size:25px;font-weight:900;letter-spacing:-.4px}
.credit-sub{font-size:13px;color:rgba(255,255,255,.55);margin-top:4px}
.wallet-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px}
@media(max-width:980px){.wallet-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.wallet-grid{grid-template-columns:1fr}}
.pricing-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
@media(max-width:1100px){.pricing-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:620px){.pricing-grid{grid-template-columns:1fr}}
.pricing-card{background:#fff;border:1px solid rgba(0,0,0,.05);border-radius:16px;padding:18px;box-shadow:var(--card-shadow)}
.pricing-card h4{font-size:14px;font-weight:900;color:var(--text);margin:0 0 6px;display:flex;align-items:center;gap:8px}
.pricing-card p{font-size:12px;color:var(--gray2);line-height:1.55;margin:0}
.pricing-tag{display:inline-flex;margin-top:12px;padding:5px 10px;border-radius:999px;background:#F5F3FF;color:var(--blue);font-size:11px;font-weight:800}
.wallet-card{background:#fff;border-radius:16px;padding:22px;box-shadow:var(--card-shadow);border:1px solid rgba(0,0,0,.04);position:relative;overflow:hidden}
.wallet-icon{width:42px;height:42px;border-radius:12px;color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:14px}
.wallet-value{font-size:34px;font-weight:900;line-height:1;letter-spacing:-1px}
.wallet-label{font-size:12px;color:var(--gray2);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-top:5px}
.low-card{background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:14px;padding:14px 16px;margin-bottom:18px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:10px}
.credit-layout{display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start}
@media(max-width:1050px){.credit-layout{grid-template-columns:1fr}}
.provider-row{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px}
.provider{border:1.5px solid var(--light);background:#fff;border-radius:10px;padding:10px;font-size:12px;font-weight:700;cursor:pointer;color:var(--text2)}
.provider.active{border-color:var(--blue);background:#F5F3FF;color:var(--blue)}
.mini-table{width:100%;border-collapse:collapse}
.mini-table th{font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--gray);text-align:left;padding:9px;border-bottom:1px solid #E2E8F0;background:#F8FAFC}
.mini-table td{font-size:13px;padding:10px 9px;border-bottom:1px solid #F1F5F9;vertical-align:top}
.usage-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px}
.toast{position:fixed;bottom:28px;right:28px;z-index:9999;padding:14px 20px;border-radius:14px;font-size:14px;font-weight:600;color:#fff;display:flex;align-items:center;gap:10px;box-shadow:0 8px 40px rgba(0,0,0,.25);animation:toastIn .3s cubic-bezier(.4,0,.2,1);pointer-events:none;max-width:340px}
.t-success{background:linear-gradient(135deg,#059669,#10B981)}
.t-error{background:linear-gradient(135deg,#DC2626,#EF4444)}
@keyframes toastIn{from{opacity:0;transform:translateY(16px) scale(.96)}to{opacity:1;transform:none}}
@keyframes toastOut{to{opacity:0;transform:translateY(16px) scale(.96)}}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">

<div class="credit-hero animate-in">
  <div>
    <div class="credit-title">Credits & Payments</div>
    <div class="credit-sub">Buy channel credits, track usage, payment history, and low-balance alerts.</div>
  </div>
  <button class="btn-green" onclick="document.getElementById('buyCard').scrollIntoView({behavior:'smooth'})">
    <i class="fa-solid fa-cart-shopping fa-sm"></i> Buy Credits
  </button>
</div>

<?php if (!empty($low)): ?>
<div class="low-card animate-in">
  <i class="fa-solid fa-triangle-exclamation"></i>
  Low balance alert: <?= htmlspecialchars(implode(', ', $low)) ?> credits are at or below <?= (int)$summary['low_balance_threshold'] ?>.
</div>
<?php endif; ?>

<div class="card animate-in" id="pricing" style="padding:22px;margin-bottom:22px">
  <div class="card-header" style="margin-bottom:16px"><h3><i class="fa-solid fa-tags" style="color:var(--blue)"></i> Pricing Guide</h3></div>
  <div class="pricing-grid">
    <div class="pricing-card">
      <h4><i class="fa-solid fa-microphone-lines" style="color:#6B21A8"></i> AI Interviews</h4>
      <p>Used when a candidate completes an AI test with Q&A, recording, integrity checks, and scoring workflow.</p>
      <span class="pricing-tag">Interview credit based</span>
    </div>
    <div class="pricing-card">
      <h4><i class="fa-brands fa-whatsapp" style="color:#16A34A"></i> WhatsApp Outreach</h4>
      <p>Invite, reminder, referral, and result messages consume WhatsApp credits when successfully sent.</p>
      <span class="pricing-tag">Per sent message</span>
    </div>
    <div class="pricing-card">
      <h4><i class="fa-solid fa-phone-volume" style="color:#2563EB"></i> AI Voice Calls</h4>
      <p>Outbound AI calls use the configured voice agent and connected telephony provider balance.</p>
      <span class="pricing-tag">Provider usage based</span>
    </div>
    <div class="pricing-card">
      <h4><i class="fa-solid fa-building-shield" style="color:#F59E0B"></i> Enterprise Setup</h4>
      <p>Custom domain, separate admin logins, CRM or Sheet integration, and reporting can be enabled per client.</p>
      <span class="pricing-tag">Custom plan</span>
    </div>
  </div>
</div>

<div class="wallet-grid">
<?php foreach ($channels as $key => [$label,$icon,$color,$balance]): ?>
  <div class="wallet-card animate-in">
    <div class="wallet-icon" style="background:<?= $color ?>"><i class="<?= $icon ?>"></i></div>
    <div class="wallet-value" style="color:<?= $color ?>"><?= number_format($balance) ?></div>
    <div class="wallet-label"><?= htmlspecialchars($label) ?> Credits</div>
  </div>
<?php endforeach; ?>
</div>

<div class="credit-layout">
  <div>
    <div class="card animate-in" id="buyCard">
      <div class="card-header"><h3><i class="fa-solid fa-credit-card" style="color:var(--green)"></i> Buy Credits</h3></div>
      <div class="provider-row">
        <button class="provider active" type="button" data-provider="razorpay">Razorpay</button>
        <button class="provider" type="button" data-provider="paypal">PayPal</button>
        <button class="provider" type="button" data-provider="payoneer">Payoneer</button>
      </div>
      <div class="grid-2" style="gap:12px">
        <div class="form-group"><label class="form-label">WhatsApp</label><input class="form-control" type="number" id="waCredits" min="0" value="500"></div>
        <div class="form-group"><label class="form-label">SMS</label><input class="form-control" type="number" id="smsCredits" min="0" value="0"></div>
        <div class="form-group"><label class="form-label">Email</label><input class="form-control" type="number" id="emailCredits" min="0" value="0"></div>
        <div class="form-group"><label class="form-label">RCS</label><input class="form-control" type="number" id="rcsCredits" min="0" value="0"></div>
      </div>
      <div class="grid-2" style="gap:12px">
        <div class="form-group"><label class="form-label">Amount</label><input class="form-control" type="number" id="amount" min="0" value="999"></div>
        <div class="form-group"><label class="form-label">Currency</label><select class="form-control" id="currency"><option>INR</option><option>USD</option></select></div>
      </div>
      <div class="form-group"><label class="form-label">Payment / Reference ID</label><input class="form-control" id="paymentId" placeholder="Optional gateway transaction id"></div>
      <button class="btn-primary" id="buyBtn" onclick="buyCredits()"><i class="fa-solid fa-circle-plus fa-sm"></i> Confirm Purchase</button>
      <div style="font-size:12px;color:var(--gray2);margin-top:10px">Gateway buttons are wired for transaction recording; add live provider SDK keys before taking real online payments.</div>
    </div>

    <div class="card animate-in">
      <div class="card-header"><h3><i class="fa-solid fa-bell" style="color:var(--orange)"></i> Low Balance Settings</h3></div>
      <div class="form-group"><label class="form-label">Alert Threshold</label><input class="form-control" type="number" id="threshold" value="<?= (int)$summary['low_balance_threshold'] ?>" min="0"></div>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin-bottom:16px">
        <input type="checkbox" id="autoRecharge" <?= !empty($summary['auto_recharge_enabled']) ? 'checked' : '' ?>> Auto-recharge enabled
      </label>
      <button class="btn-outline" onclick="saveSettings()"><i class="fa-solid fa-floppy-disk fa-sm"></i> Save Settings</button>
    </div>
  </div>

  <div>
    <div class="card animate-in">
      <div class="card-header"><h3><i class="fa-solid fa-clock-rotate-left" style="color:var(--blue)"></i> Payment History</h3></div>
      <table class="mini-table">
        <thead><tr><th>Date</th><th>Provider</th><th>Amount</th><th>Credits</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($transactions as $t): $credits = json_decode($t['credits_json'] ?? '{}', true) ?: []; ?>
          <tr>
            <td><?= date('d M Y H:i', strtotime($t['created_at'])) ?></td>
            <td><?= ucfirst(htmlspecialchars($t['provider'])) ?><br><small style="color:var(--gray)"><?= htmlspecialchars($t['provider_payment_id'] ?? '') ?></small></td>
            <td><?= htmlspecialchars($t['currency']) ?> <?= number_format((float)$t['amount'], 2) ?></td>
            <td><?php foreach ($credits as $k => $v) if ((int)$v > 0) echo htmlspecialchars(ucfirst($k)) . ': ' . number_format((int)$v) . '<br>'; ?></td>
            <td><span class="badge badge-active"><?= ucfirst($t['status']) ?></span></td>
          </tr>
        <?php endforeach; if (empty($transactions)): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--gray);padding:28px">No payment history yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card animate-in">
      <div class="card-header"><h3><i class="fa-solid fa-chart-simple" style="color:var(--purple)"></i> Credit Usage</h3></div>
      <table class="mini-table">
        <thead><tr><th>Date</th><th>Channel</th><th>Candidate</th><th>Reason</th><th>Balance</th></tr></thead>
        <tbody>
        <?php foreach ($usage as $u):
          $color = $channels[$u['channel']][2] ?? '#64748B'; ?>
          <tr>
            <td><?= date('d M Y H:i', strtotime($u['created_at'])) ?></td>
            <td><span class="usage-dot" style="background:<?= $color ?>"></span><?= ucfirst($u['channel']) ?></td>
            <td><?= htmlspecialchars($u['candidate_name'] ?? '—') ?><br><small style="color:var(--gray)"><?= htmlspecialchars($u['campaign_name'] ?? '') ?></small></td>
            <td><?= htmlspecialchars(str_replace('_', ' ', $u['reason'] ?? 'message')) ?></td>
            <td><?= (int)$u['balance_after'] ?></td>
          </tr>
        <?php endforeach; if (empty($usage)): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--gray);padding:28px">No credit usage recorded yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div>
<script>
let selectedProvider = 'razorpay';
document.querySelectorAll('.provider').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.provider').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    selectedProvider = btn.dataset.provider;
  });
});

function toast(msg, type='success') {
  const t = document.createElement('div');
  t.className = 'toast t-' + type;
  t.innerHTML = `<i class="fa-solid fa-${type === 'error' ? 'circle-xmark' : 'circle-check'}"></i>${msg}`;
  document.body.appendChild(t);
  setTimeout(() => { t.style.animation = 'toastOut .3s forwards'; setTimeout(() => t.remove(), 300); }, 2800);
}

async function postCredits(payload) {
  const r = await fetch('/api/credits.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  return r.json();
}

async function buyCredits() {
  const btn = document.getElementById('buyBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-sm"></i> Processing...';
  try {
    const d = await postCredits({
      action: 'buy',
      provider: selectedProvider,
      whatsapp_credits: parseInt(document.getElementById('waCredits').value || '0'),
      sms_credits: parseInt(document.getElementById('smsCredits').value || '0'),
      email_credits: parseInt(document.getElementById('emailCredits').value || '0'),
      rcs_credits: parseInt(document.getElementById('rcsCredits').value || '0'),
      amount: parseFloat(document.getElementById('amount').value || '0'),
      currency: document.getElementById('currency').value,
      payment_id: document.getElementById('paymentId').value.trim()
    });
    if (!d.success) throw new Error(d.error || 'Purchase failed');
    toast('Credits added successfully');
    setTimeout(() => location.reload(), 900);
  } catch (e) {
    toast(e.message || 'Purchase failed', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-circle-plus fa-sm"></i> Confirm Purchase';
  }
}

async function saveSettings() {
  try {
    const d = await postCredits({
      action: 'settings',
      low_balance_threshold: parseInt(document.getElementById('threshold').value || '0'),
      auto_recharge_enabled: document.getElementById('autoRecharge').checked ? 1 : 0
    });
    if (!d.success) throw new Error(d.error || 'Save failed');
    toast('Settings saved');
  } catch (e) {
    toast(e.message || 'Save failed', 'error');
  }
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
