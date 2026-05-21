<?php
require_once __DIR__ . '/includes/auth_check.php';

$campaigns = db_fetch_all(
    "SELECT * FROM campaigns WHERE org_id=? ORDER BY created_at DESC",
    [$user['org_id']], 'i'
);
$sel_camp = (int)($_GET['campaign_id'] ?? 0);

// Candidate list with outreach status when campaign selected
$candidates = [];
$stats = ['total'=>0,'sent'=>0,'pending'=>0];
if ($sel_camp) {
    $camp = db_fetch_one("SELECT * FROM campaigns WHERE id=? AND org_id=?", [$sel_camp, $user['org_id']], 'ii');
    if ($camp) {
        $candidates = db_fetch_all(
            "SELECT c.id, c.name, c.phone, c.status, c.created_at,
                    (SELECT status FROM outreach_log WHERE candidate_id=c.id AND channel='whatsapp' ORDER BY sent_at DESC LIMIT 1) last_wa_status,
                    (SELECT sent_at FROM outreach_log WHERE candidate_id=c.id AND channel='whatsapp' ORDER BY sent_at DESC LIMIT 1) last_wa_at
             FROM candidates c
             WHERE c.campaign_id=? AND c.org_id=?
             ORDER BY c.created_at DESC",
            [$sel_camp, $user['org_id']], 'ii'
        );
        $stats['total'] = count($candidates);
        foreach ($candidates as $c) {
            if ($c['last_wa_status'] === 'sent') $stats['sent']++;
            elseif (!$c['last_wa_status'] || $c['last_wa_status'] === 'failed') $stats['pending']++;
        }
    }
}

// Preview message template
$preview_name = 'Candidate Name';
$preview_role = $camp['job_role'] ?? 'the role';
$preview_camp = $camp['name'] ?? 'the campaign';
$preview_url  = 'https://urm.clouddialer.in/interview.php?t=XXXX';
$preview_msg  = "🎯 *Interview Invitation — $preview_camp*\n\nHi $preview_name! 👋\n\nYou have been shortlisted for *$preview_role*.\n\nComplete your AI interview here:\n🔗 $preview_url\n\n⏱ Duration: ~15 min | 🎤 Mic required\n\n*HireAI — Avyukta Intellicall*";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Outreach — HireAI</title>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
.wa-bubble{background:#DCF8C6;border-radius:12px 12px 0 12px;padding:12px 14px;font-size:13px;line-height:1.6;white-space:pre-wrap;font-family:inherit;max-width:340px;color:#111;box-shadow:0 1px 2px rgba(0,0,0,.15);margin-top:10px}
.status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px}
.dot-sent{background:#10B981}.dot-failed{background:#EF4444}.dot-pending{background:#94A3B8}
.cand-row-check{width:16px;height:16px;cursor:pointer;accent-color:var(--accent)}
.outreach-table th{padding:10px 12px;font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--gray);background:#F8FAFC;border-bottom:2px solid #E2E8F0;white-space:nowrap;font-weight:800}
.outreach-table td{padding:11px 12px;border-bottom:1px solid #F1F5F9;font-size:13px;vertical-align:middle}
.outreach-table tbody tr:hover td{background:#F8FAFF}
.send-btn{border:none;background:#EFF6FF;color:#1D4ED8;border-radius:8px;padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer;transition:background .15s}
.send-btn:hover{background:#DBEAFE}
.send-btn:disabled{opacity:.5;cursor:not-allowed}
.bulk-out-bar{display:none;position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:12px 20px;border-radius:14px;box-shadow:0 4px 32px rgba(0,0,0,.45);align-items:center;gap:12px;z-index:999;white-space:nowrap}
.wa-composer{margin-top:16px;border-top:1px solid #F1F5F9;padding-top:14px}
.wa-field{margin-top:10px}
.wa-field label{display:block;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--gray);margin-bottom:5px}
.wa-field input,.wa-field textarea,.wa-field select{width:100%;box-sizing:border-box;border:1.5px solid #E2E8F0;border-radius:10px;padding:9px 11px;font-size:12px;background:#F8FAFC;color:var(--text)}
.wa-field textarea{min-height:76px;resize:vertical}
.wa-help{font-size:10px;color:var(--gray);line-height:1.4;margin-top:4px}
.wa-dynamic{display:none}
.wa-dynamic.active{display:block}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">
  <div class="page-header">
    <h2>Outreach</h2>
    <p>Send interview invites and track delivery for each candidate</p>
  </div>

  <div style="display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;align-items:start">
    <div>
      <!-- Campaign Selector -->
      <div class="card" style="padding:18px 20px;margin-bottom:18px">
        <form method="GET" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
          <label style="font-weight:700;font-size:13px;color:var(--text2);white-space:nowrap">Campaign:</label>
          <select name="campaign_id" class="form-control" style="max-width:320px;font-size:13px" onchange="this.form.submit()">
            <option value="">— Select a campaign —</option>
            <?php foreach ($campaigns as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $sel_camp === (int)$c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['name']) ?> <?= $c['status'] !== 'active' ? '('.$c['status'].')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <?php if ($sel_camp && $camp): ?>
      <!-- Stats Bar -->
      <div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap">
        <?php foreach ([
          ['Total Candidates', $stats['total'], '#6366F1', 'users'],
          ['Invite Sent', $stats['sent'],  '#10B981', 'paper-plane'],
          ['Not Yet Sent', $stats['pending'], '#F59E0B', 'clock'],
        ] as [$lbl,$val,$col,$ico]): ?>
        <div style="background:#fff;border-radius:14px;padding:14px 18px;border:1px solid rgba(0,0,0,.05);box-shadow:var(--card-shadow);display:flex;align-items:center;gap:10px;flex:1;min-width:120px">
          <i class="fa-solid fa-<?= $ico ?>" style="color:<?= $col ?>;font-size:20px"></i>
          <div><div style="font-size:22px;font-weight:900;color:var(--text)"><?= $val ?></div><div style="font-size:11px;color:var(--gray);font-weight:700;text-transform:uppercase;letter-spacing:.4px"><?= $lbl ?></div></div>
        </div>
        <?php endforeach; ?>
        <div style="display:flex;gap:8px;align-items:center">
          <button onclick="bulkSendAll()" class="btn-primary" style="font-size:13px;padding:10px 18px">📲 Send to All Pending</button>
        </div>
      </div>

      <!-- Candidate Table -->
      <div class="card" style="padding:0;overflow:hidden">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #F1F5F9">
          <div style="font-size:14px;font-weight:700;color:var(--text)"><?= count($candidates) ?> candidates</div>
          <div style="display:flex;gap:8px">
            <button onclick="selectAllOut(true)" class="btn-sm" style="font-size:12px;padding:5px 10px">Select All</button>
            <button onclick="selectAllOut(false)" class="btn-sm" style="font-size:12px;padding:5px 10px">Clear</button>
            <button onclick="bulkSendSelected()" class="btn-sm" style="font-size:12px;padding:5px 10px;color:var(--accent);border-color:var(--accent)">📲 Send Selected</button>
            <?php if (defined('DIALER_API_KEY') && DIALER_API_KEY): ?>
            <button onclick="bulkAiCall()" class="btn-sm" style="font-size:12px;padding:5px 10px;color:#059669;border-color:#059669">📞 AI Call Selected</button>
            <?php endif; ?>
          </div>
        </div>
        <table class="outreach-table" style="width:100%;border-collapse:collapse">
          <thead>
            <tr>
              <th style="width:36px;text-align:center"><input type="checkbox" id="out-select-all" onclick="selectAllOut(this.checked)" style="cursor:pointer;width:16px;height:16px"></th>
              <th>Candidate</th>
              <th>Status</th>
              <th>WA Invite</th>
              <th>Last Sent</th>
              <th style="width:80px"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($candidates as $c):
              $waStatus   = $c['last_wa_status'];
              $waAt       = $c['last_wa_at'];
              $waLabel    = match($waStatus) { 'sent' => 'Sent', 'failed' => 'Failed', default => 'Not sent' };
              $waDot      = match($waStatus) { 'sent' => 'dot-sent', 'failed' => 'dot-failed', default => 'dot-pending' };
              $waSince    = $waAt ? (function($t){ $d=time()-strtotime($t); if($d<3600) return round($d/60).'m ago'; if($d<86400) return round($d/3600).'h ago'; return date('d M',strtotime($t)); })($waAt) : '—';
            ?>
            <tr>
              <td style="text-align:center"><input type="checkbox" class="out-chk cand-row-check" value="<?= $c['id'] ?>"></td>
              <td>
                <a href="candidate_detail.php?id=<?= $c['id'] ?>" style="font-weight:700;color:var(--text);text-decoration:none"><?= htmlspecialchars($c['name']) ?></a>
                <div style="font-size:11px;color:var(--gray);margin-top:1px"><?= htmlspecialchars($c['phone'] ?? '') ?></div>
              </td>
              <td><span class="badge badge-<?= $c['status'] ?>"><?= ucfirst(str_replace('_',' ',$c['status'])) ?></span></td>
              <td><span class="status-dot <?= $waDot ?>"></span><?= $waLabel ?></td>
              <td style="font-size:12px;color:var(--gray)"><?= $waSince ?></td>
              <td>
                <button class="send-btn" onclick="sendSingle(<?= $c['id'] ?>, '<?= addslashes(htmlspecialchars($c['name'])) ?>', this)">Send</button>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($candidates)): ?>
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--gray)">No candidates in this campaign yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php elseif (!$sel_camp): ?>
      <div class="card" style="text-align:center;padding:40px;color:var(--gray)">
        <i class="fa-solid fa-paper-plane" style="font-size:40px;opacity:.2;margin-bottom:12px;display:block"></i>
        <div style="font-size:15px;font-weight:700">Select a campaign to manage outreach</div>
        <div style="font-size:13px;margin-top:6px">Choose from the dropdown above to see candidates and send invites.</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Message Preview Panel -->
    <div class="card" style="position:sticky;top:88px">
      <div class="card-header"><h3>📱 WhatsApp Preview</h3></div>
      <div style="font-size:12px;color:var(--gray);margin-bottom:8px">Message that will be sent to each candidate:</div>
      <div class="wa-bubble"><?= htmlspecialchars($preview_msg) ?></div>
      <div style="font-size:11px;color:var(--gray);margin-top:10px">✓ Interview link is personalised per candidate</div>
      <?php if ($sel_camp && $camp): ?>
      <div class="wa-composer">
        <div style="font-size:13px;font-weight:800;color:var(--text);margin-bottom:8px">CloudDialer WhatsApp Sender</div>
        <div style="font-size:11px;color:var(--gray);line-height:1.5;margin-bottom:10px">Select candidates from the table, choose message type, then send through wa.clouddialer.in.</div>
        <div class="wa-field">
          <label>Message Type</label>
          <select id="waType" onchange="renderWaComposer()">
            <option value="text">Text</option>
            <option value="image">Image</option>
            <option value="video">Video</option>
            <option value="audio">Audio / Voice Note</option>
            <option value="document">Document</option>
            <option value="location">Location</option>
            <option value="contact">Contact Card</option>
            <option value="buttons">Buttons</option>
            <option value="list">List</option>
            <option value="template">Template</option>
            <option value="album">Album</option>
            <option value="sequence">Bulk Text Sequence</option>
          </select>
        </div>

        <div class="wa-dynamic active" data-wa-panel="text buttons list">
          <div class="wa-field">
            <label>Message / Body</label>
            <textarea id="waText" placeholder="Write message body..."></textarea>
          </div>
        </div>

        <div class="wa-dynamic" data-wa-panel="image video audio document">
          <div class="wa-field">
            <label>Public Media URL</label>
            <input id="waUrl" placeholder="https://example.com/file.pdf">
          </div>
          <div class="wa-field" data-wa-panel-extra="image video document album">
            <label>Caption</label>
            <textarea id="waCaption" placeholder="Optional caption"></textarea>
          </div>
        </div>

        <div class="wa-dynamic" data-wa-panel="document">
          <div class="wa-field">
            <label>File Name</label>
            <input id="waFileName" placeholder="Document.pdf">
          </div>
          <div class="wa-field">
            <label>Mimetype</label>
            <input id="waMime" placeholder="application/pdf">
          </div>
        </div>

        <div class="wa-dynamic" data-wa-panel="audio">
          <div class="wa-field">
            <label>Mimetype</label>
            <input id="waAudioMime" placeholder="audio/mpeg">
          </div>
          <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:12px;font-weight:700;color:var(--text2)">
            <input id="waPtt" type="checkbox" style="width:auto"> Send as voice note
          </label>
        </div>

        <div class="wa-dynamic" data-wa-panel="image video">
          <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:12px;font-weight:700;color:var(--text2)">
            <input id="waViewOnce" type="checkbox" style="width:auto"> View once
          </label>
          <label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-size:12px;font-weight:700;color:var(--text2)">
            <input id="waGif" type="checkbox" style="width:auto"> Video GIF playback
          </label>
        </div>

        <div class="wa-dynamic" data-wa-panel="location">
          <div class="wa-field"><label>Latitude</label><input id="waLat" placeholder="28.6139"></div>
          <div class="wa-field"><label>Longitude</label><input id="waLng" placeholder="77.209"></div>
          <div class="wa-field"><label>Place Name</label><input id="waPlaceName" placeholder="India Gate"></div>
          <div class="wa-field"><label>Address</label><textarea id="waAddress" placeholder="Full address"></textarea></div>
        </div>

        <div class="wa-dynamic" data-wa-panel="contact">
          <div class="wa-field"><label>Contact Name</label><input id="waContactName" placeholder="Yash Suman"></div>
          <div class="wa-field"><label>Contact Phone</label><input id="waContactPhone" placeholder="919876543210"></div>
        </div>

        <div class="wa-dynamic" data-wa-panel="buttons">
          <div class="wa-field"><label>Buttons</label><textarea id="waButtons" placeholder="Yes&#10;No&#10;Maybe"></textarea><div class="wa-help">Max 3 buttons, one title per line.</div></div>
          <div class="wa-field"><label>Footer</label><input id="waFooter" placeholder="Powered by HireAI"></div>
        </div>

        <div class="wa-dynamic" data-wa-panel="list">
          <div class="wa-field"><label>Header</label><input id="waHeader" placeholder="Menu"></div>
          <div class="wa-field"><label>Button Text</label><input id="waListButton" placeholder="Open List"></div>
          <div class="wa-field"><label>Section Title</label><input id="waSection" placeholder="Options"></div>
          <div class="wa-field"><label>Rows</label><textarea id="waRows" placeholder="Option 1|Description 1&#10;Option 2|Description 2"></textarea><div class="wa-help">One row per line. Use Title|Description.</div></div>
        </div>

        <div class="wa-dynamic" data-wa-panel="template">
          <div class="wa-field"><label>Template Name</label><input id="waTemplate" placeholder="your_template_name"></div>
          <div class="wa-field"><label>Language Code</label><input id="waLanguage" placeholder="en"></div>
        </div>

        <div class="wa-dynamic" data-wa-panel="album">
          <div class="wa-field"><label>Album Items</label><textarea id="waAlbum" placeholder="image|https://example.com/1.jpg&#10;video|https://example.com/2.mp4"></textarea><div class="wa-help">One item per line. Use image|url or video|url.</div></div>
          <div class="wa-field"><label>Caption</label><textarea id="waAlbumCaption" placeholder="Album caption"></textarea></div>
        </div>

        <div class="wa-dynamic" data-wa-panel="sequence">
          <div class="wa-field"><label>Messages</label><textarea id="waSequence" placeholder="Message 1&#10;Message 2&#10;Message 3"></textarea><div class="wa-help">One text message per line, sent sequentially to each selected number.</div></div>
          <div class="wa-field"><label>Delay MS</label><input id="waDelay" placeholder="500"></div>
        </div>

        <button onclick="sendCustomWhatsApp()" class="btn-primary" style="width:100%;justify-content:center;margin-top:12px;font-size:13px;padding:10px 14px">Send Custom WhatsApp</button>
      </div>
      <hr style="margin:16px 0;border:none;border-top:1px solid #F1F5F9">
      <div style="font-size:12px;font-weight:700;color:var(--text2);margin-bottom:6px">AI Phone Calls</div>
      <div style="font-size:12px;color:var(--gray);margin-bottom:10px">Trigger outbound AI calls to all pending candidates.</div>
      <button onclick="startAllCalls()" class="btn-sm" style="width:100%;justify-content:center;color:#0066FF;border-color:#0066FF20;background:#EFF6FF">🎤 Start AI Calls for All Pending</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Toast container (reuse same pattern) -->
<script>
const CAMP_ID = <?= $sel_camp ?: 'null' ?>;

function showToast(msg, type) {
  const colors = { success:'#059669', error:'#DC2626', info:'#1D4ED8' };
  const t = document.createElement('div');
  t.style.cssText = `position:fixed;bottom:28px;right:24px;background:${colors[type]||colors.info};color:#fff;padding:12px 18px;border-radius:12px;font-size:13px;font-weight:600;z-index:9999;box-shadow:0 4px 24px rgba(0,0,0,.3);max-width:340px`;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

function waVal(id) {
  const el = document.getElementById(id);
  return el ? el.value.trim() : '';
}

function renderWaComposer() {
  const type = waVal('waType') || 'text';
  document.querySelectorAll('.wa-dynamic').forEach(panel => {
    const allowed = (panel.dataset.waPanel || '').split(/\s+/);
    panel.classList.toggle('active', allowed.includes(type));
  });
  document.querySelectorAll('[data-wa-panel-extra]').forEach(el => {
    const allowed = (el.dataset.waPanelExtra || '').split(/\s+/);
    el.style.display = allowed.includes(type) ? '' : 'none';
  });
  const gif = document.getElementById('waGif');
  if (gif) gif.parentElement.style.display = type === 'video' ? 'flex' : 'none';
}

async function sendCustomWhatsApp() {
  const ids = getCheckedIds();
  if (!ids.length) { showToast('Select candidates from table first', 'info'); return; }
  const type = waVal('waType') || 'text';
  const data = {
    text: waVal('waText'),
    url: waVal('waUrl'),
    caption: type === 'album' ? waVal('waAlbumCaption') : waVal('waCaption'),
    fileName: waVal('waFileName'),
    mimetype: type === 'audio' ? waVal('waAudioMime') : waVal('waMime'),
    ptt: !!document.getElementById('waPtt')?.checked,
    viewOnce: !!document.getElementById('waViewOnce')?.checked,
    gifPlayback: !!document.getElementById('waGif')?.checked,
    latitude: waVal('waLat'),
    longitude: waVal('waLng'),
    name: type === 'contact' ? waVal('waContactName') : waVal('waPlaceName'),
    address: waVal('waAddress'),
    phone: waVal('waContactPhone'),
    buttons: waVal('waButtons'),
    footer: waVal('waFooter'),
    header: waVal('waHeader'),
    button: waVal('waListButton'),
    section: waVal('waSection'),
    rows: waVal('waRows'),
    template: waVal('waTemplate'),
    language: waVal('waLanguage') || 'en',
    album: waVal('waAlbum'),
    sequence: waVal('waSequence'),
    delay: waVal('waDelay') || '500',
  };
  if (!confirm(`Send ${type} WhatsApp message to ${ids.length} selected candidate(s)?`)) return;
  showToast(`⏳ Sending ${type} message…`, 'info');
  try {
    const r = await fetch('/api/outreach.php?action=custom_whatsapp_send', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ candidate_ids: ids, type, data })
    });
    const d = await r.json();
    if (r.ok) {
      showToast(`✅ Sent: ${d.sent} | Failed: ${d.failed}`, d.failed ? 'info' : 'success');
      setTimeout(() => location.reload(), 1200);
    } else {
      showToast('❌ ' + (d.error || 'Failed to send'), 'error');
    }
  } catch(e) {
    showToast('❌ Network error', 'error');
  }
}

async function sendSingle(id, name, btn) {
  if (!confirm(`Send WhatsApp interview invite to ${name}?`)) return;
  btn.disabled = true; btn.textContent = '…';
  try {
    const r = await fetch(`/api/outreach.php?action=send_single&candidate_id=${id}`);
    const d = await r.json();
    if (d.status === 'sent') { showToast(`✅ Sent to ${name}`, 'success'); btn.textContent = 'Sent ✓'; btn.style.color = '#059669'; }
    else { showToast('❌ ' + (d.message || 'Failed'), 'error'); btn.disabled = false; btn.textContent = 'Retry'; }
  } catch(e) { showToast('❌ Network error', 'error'); btn.disabled = false; btn.textContent = 'Send'; }
}

function getCheckedIds() {
  return [...document.querySelectorAll('.out-chk:checked')].map(c => parseInt(c.value));
}

function selectAllOut(checked) {
  document.querySelectorAll('.out-chk').forEach(c => c.checked = checked);
  document.getElementById('out-select-all').checked = checked;
}

async function bulkSendSelected() {
  const ids = getCheckedIds();
  if (!ids.length) { showToast('Select at least one candidate', 'info'); return; }
  if (!confirm(`Send WhatsApp invite to ${ids.length} candidate(s)?`)) return;
  await _bulkSend(ids);
}

async function bulkSendAll() {
  if (!CAMP_ID) return;
  if (!confirm('Send WhatsApp invite to ALL pending candidates in this campaign?')) return;
  // Get only pending (not yet sent)
  const ids = [...document.querySelectorAll('.out-chk')].map(c => parseInt(c.value));
  if (!ids.length) { showToast('No candidates found', 'info'); return; }
  await _bulkSend(ids);
}

async function _bulkSend(ids) {
  showToast(`⏳ Sending to ${ids.length} candidate(s)…`, 'info');
  try {
    const r = await fetch('/api/outreach.php?action=bulk_send', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ candidate_ids: ids })
    });
    const d = await r.json();
    showToast(`✅ Sent: ${d.sent} | Failed: ${d.failed}`, d.failed > 0 ? 'info' : 'success');
    setTimeout(() => location.reload(), 1500);
  } catch(e) { showToast('❌ Network error', 'error'); }
}

async function bulkAiCall() {
  const ids = getCheckedIds();
  if (!ids.length) { showToast('Select at least one candidate', 'info'); return; }
  if (!confirm(`Trigger AI calls to ${ids.length} candidate(s) via the dialer?`)) return;
  showToast(`⏳ Triggering ${ids.length} call(s)…`, 'info');
  try {
    const r = await fetch('/api/outreach.php?action=bulk_ai_call', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ candidate_ids: ids })
    });
    const d = await r.json();
    showToast(`📞 Triggered: ${d.triggered} | Failed: ${d.failed}`, d.failed > 0 ? 'info' : 'success');
  } catch(e) { showToast('❌ Network error', 'error'); }
}

async function startAllCalls() {
  if (!CAMP_ID) return;
  if (!confirm('Start outbound AI phone calls for pending candidates in this campaign?')) return;
  showToast('⏳ Initiating calls…', 'info');
  try {
    const r = await fetch(`api/outreach.php?action=call_campaign&campaign_id=${CAMP_ID}`);
    const d = await r.json();
    showToast(d.message || d.error || 'Done', 'success');
  } catch(e) { showToast('❌ Network error', 'error'); }
}
renderWaComposer();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
