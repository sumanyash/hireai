<?php
require_once __DIR__ . '/includes/auth_check.php';

$action      = $_GET['action'] ?? 'list';
$campaign_id = (int)($_GET['id'] ?? 0);

function normalize_json_text($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? json_encode($decoded) : null;
}

function options_to_json($value) {
    $items = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$value))));
    return empty($items) ? null : json_encode($items);
}

function field_key_from_label($label) {
    $key = strtolower(trim((string)$label));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    $key = trim($key, '_');
    return $key ?: 'custom_field';
}

function campaign_apply_link($campaign) {
    $token = $campaign['share_token'] ?? '';
    return BASE_URL . '/apply.php?' . ($token ? 'c=' . urlencode($token) : 'campaign_id=' . (int)$campaign['id']);
}

// ─── POST HANDLERS ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    if ($action === 'save') {
        $share_token = bin2hex(random_bytes(12));
        $start_date = trim($_POST['start_date'] ?? '') ?: null;
        $end_date = trim($_POST['end_date'] ?? '') ?: null;
        $id = db_insert(
            "INSERT INTO campaigns (org_id,created_by,name,job_role,description,share_token,start_date,end_date,el_agent_id,passing_score,num_questions,language,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'draft')",
            [$user['org_id'],$user['user_id'],$_POST['name'],$_POST['job_role'],$_POST['description'],$share_token,$start_date,$end_date,$_POST['el_agent_id'],(int)$_POST['passing_score'],(int)$_POST['num_questions'],$_POST['language']],
            'iisssssssiis'
        );
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $id, 'campaign_created');
        header("Location: campaigns.php?action=questions&id=$id&msg=created"); exit;
    }
    if ($action === 'edit_save') {
        $start_date = trim($_POST['start_date'] ?? '') ?: null;
        $end_date = trim($_POST['end_date'] ?? '') ?: null;
        db_execute(
            "UPDATE campaigns SET name=?,job_role=?,description=?,start_date=?,end_date=?,el_agent_id=?,passing_score=?,num_questions=?,language=?,share_token=COALESCE(share_token, ?) WHERE id=? AND org_id=?",
            [$_POST['name'],$_POST['job_role'],$_POST['description'],$start_date,$end_date,$_POST['el_agent_id'],(int)$_POST['passing_score'],(int)$_POST['num_questions'],$_POST['language'],bin2hex(random_bytes(12)),$campaign_id,$user['org_id']],
            'ssssssiissii'
        );
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'campaign_updated');
        header("Location: campaigns.php?action=questions&id=$campaign_id&msg=updated"); exit;
    }
    if ($action === 'add_question') {
        $question_type = $_POST['question_type'] ?? 'textarea';
        $options_json = options_to_json($_POST['options_text'] ?? '');
        $branch_rules_json = normalize_json_text($_POST['branch_rules_json'] ?? '');
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        db_insert(
            "INSERT INTO questions (campaign_id,parameter,parameter_label,weight,max_marks,question_text,ideal_answer_hint,question_type,options_json,branch_rules_json,is_required,order_no) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [$campaign_id,$_POST['parameter'],$_POST['parameter_label'],(int)$_POST['weight'],(int)$_POST['max_marks'],$_POST['question_text'],$_POST['ideal_answer_hint'],$question_type,$options_json,$branch_rules_json,$is_required,(int)$_POST['order_no']],
            'issiisssssii'
        );
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'question_added', ['type' => $question_type]);
        header("Location: campaigns.php?action=questions&id=$campaign_id&msg=question_added"); exit;
    }
    if ($action === 'add_application_field') {
        $field_type = $_POST['field_type'] ?? 'text';
        $allowed = ['text','textarea','number','decimal','date','dropdown','multi_select','checkbox','email','phone','url','file'];
        if (!in_array($field_type, $allowed, true)) $field_type = 'text';
        $field_label = trim($_POST['field_label'] ?? '');
        $field_key = field_key_from_label($_POST['field_key'] ?? $field_label);
        $options_json = options_to_json($_POST['options_text'] ?? '');
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $campaign_exists = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
        if (!$campaign_exists || $field_label === '') {
            header("Location: campaigns.php?action=apply_form&id=$campaign_id&msg=field_error"); exit;
        }
        db_insert(
            "INSERT INTO application_fields (campaign_id,field_key,field_label,field_type,placeholder,help_text,options_json,is_required,order_no,is_active) VALUES (?,?,?,?,?,?,?,?,?,1)",
            [$campaign_id,$field_key,$field_label,$field_type,trim($_POST['placeholder'] ?? ''),trim($_POST['help_text'] ?? ''),$options_json,$is_required,(int)($_POST['order_no'] ?? 1)],
            'issssssii'
        );
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'application_field_added', ['label' => $field_label, 'type' => $field_type]);
        header("Location: campaigns.php?action=apply_form&id=$campaign_id&msg=field_added"); exit;
    }
    if ($action === 'activate') {
        db_execute("UPDATE campaigns SET status='active', share_token=COALESCE(share_token, ?) WHERE id=? AND org_id=?", [bin2hex(random_bytes(12)),$campaign_id,$user['org_id']], 'sii');
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'campaign_activated');
        header("Location: campaigns.php?msg=activated"); exit;
    }
}

if ($action === 'delete_question' && $campaign_id) {
    $sent = $_GET['csrf_token'] ?? '';
    if (!$sent || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Invalid security token. Please refresh and try again.');
    }
    $qid = (int)$_GET['qid'];
    db_execute("DELETE FROM questions WHERE id=? AND campaign_id=?", [$qid,$campaign_id], 'ii');
    header("Location: campaigns.php?action=questions&id=$campaign_id"); exit;
}

if ($action === 'delete_application_field' && $campaign_id) {
    $sent = $_GET['csrf_token'] ?? '';
    if (!$sent || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Invalid security token. Please refresh and try again.');
    }
    $fid = (int)($_GET['fid'] ?? 0);
    $campaign_exists = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
    if ($campaign_exists) {
        db_execute("UPDATE application_fields SET is_active=0 WHERE id=? AND campaign_id=?", [$fid,$campaign_id], 'ii');
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'application_field_deleted', ['field_id' => $fid]);
    }
    header("Location: campaigns.php?action=apply_form&id=$campaign_id"); exit;
}

// ─── DATA ────────────────────────────────────────────────────────
$campaigns = db_fetch_all(
    "SELECT ca.*, COUNT(DISTINCT c.id) as total_cands FROM campaigns ca LEFT JOIN candidates c ON ca.id=c.campaign_id WHERE ca.org_id=? GROUP BY ca.id ORDER BY ca.created_at DESC",
    [$user['org_id']], 'i'
);
$campaign  = $campaign_id ? db_fetch_one("SELECT * FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii') : null;
$questions = $campaign_id ? db_fetch_all("SELECT * FROM questions WHERE campaign_id=? ORDER BY order_no", [$campaign_id], 'i') : [];
$application_fields = $campaign_id ? db_fetch_all("SELECT * FROM application_fields WHERE campaign_id=? AND is_active=1 ORDER BY order_no,id", [$campaign_id], 'i') : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campaigns — HireAI</title>
<?php include __DIR__ . '/includes/head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">

<?php if ($action === 'list'): ?>
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:center">
    <div><h2>Campaigns</h2><p>Manage all hiring campaigns</p></div>
    <a href="campaigns.php?action=new" class="btn-primary">+ New Campaign</a>
  </div>
  <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success">✅ Campaign <?= htmlspecialchars($_GET['msg']) ?>!</div>
  <?php endif; ?>
  <div class="card">
    <table class="table">
      <thead><tr><th>Campaign</th><th>Job Role</th><th>Agent</th><th>Candidates</th><th>Pass Score</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($campaigns as $c): ?>
        <tr>
          <?php $applyLink = campaign_apply_link($c); ?>
          <td><strong><?= htmlspecialchars($c['name']) ?></strong><br><small style="color:#8892A4"><?= date('d M Y', strtotime($c['created_at'])) ?></small></td>
          <td><?= htmlspecialchars($c['job_role']) ?></td>
          <td><small style="font-family:monospace;color:#0066FF"><?= $c['el_agent_id'] ? substr($c['el_agent_id'],0,20).'...' : '<span style="color:#dc3545">Not set</span>' ?></small></td>
          <td><?= $c['total_cands'] ?></td>
          <td><?= $c['passing_score'] ?>/100</td>
          <td><span class="badge badge-<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span></td>
          <td style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="campaigns.php?action=edit&id=<?= $c['id'] ?>" class="btn-sm">✏️ Edit</a>
            <a href="campaigns.php?action=apply_form&id=<?= $c['id'] ?>" class="btn-sm">Apply Form</a>
            <a href="campaigns.php?action=questions&id=<?= $c['id'] ?>" class="btn-sm">Questions</a>
            <a href="candidates.php?campaign_id=<?= $c['id'] ?>" class="btn-sm">Leads</a>
            <button type="button" class="btn-sm" onclick="copyCampaignLink(<?= htmlspecialchars(json_encode($applyLink), ENT_QUOTES, 'UTF-8') ?>)">Copy Link</button>
            <a href="https://wa.me/?text=<?= urlencode('Apply here: ' . $applyLink) ?>" target="_blank" rel="noopener" class="btn-sm" style="color:#16A34A;border-color:#16A34A40;background:#16A34A10">WhatsApp</a>
            <?php if ($c['status'] !== 'active'): ?>
              <form method="POST" action="campaigns.php?action=activate&id=<?= $c['id'] ?>" style="display:inline">
                <?= csrf_input() ?>
                <button type="submit" class="btn-green" style="padding:5px 12px;font-size:13px">▶ Activate</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($campaigns)): ?>
        <tr><td colspan="7" style="text-align:center;padding:32px;color:#8892A4">No campaigns yet. <a href="campaigns.php?action=new">Create your first →</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($action === 'new' || ($action === 'edit' && $campaign)): ?>
  <?php $is_edit = ($action === 'edit'); ?>
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:center">
    <div><h2><?= $is_edit ? 'Edit Campaign' : 'New Campaign' ?></h2><p><?= $is_edit ? htmlspecialchars($campaign['name']) : 'Set up a new hiring campaign' ?></p></div>
    <a href="campaigns.php" class="btn-sm">← Back</a>
  </div>
  <div class="card" style="max-width:720px">
    <form method="POST" action="campaigns.php?action=<?= $is_edit ? 'edit_save' : 'save' ?><?= $is_edit ? '&id='.$campaign_id : '' ?>">
      <?= csrf_input() ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Campaign Name *</label>
          <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($campaign['name'] ?? '') ?>" placeholder="AI Developer Batch 1" required>
        </div>
        <div class="form-group">
          <label class="form-label">Job Role *</label>
          <input type="text" name="job_role" class="form-control" value="<?= htmlspecialchars($campaign['job_role'] ?? '') ?>" placeholder="AI Developer" required>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control"><?= htmlspecialchars($campaign['description'] ?? '') ?></textarea>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Start Date</label>
          <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($campaign['start_date'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">End Date</label>
          <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($campaign['end_date'] ?? '') ?>">
        </div>
      </div>

      <!-- ElevenLabs Agent Selector -->
      <div class="form-group">
        <label class="form-label">ElevenLabs Agent *
          <span id="agent-loading" style="color:#8892A4;font-size:12px;margin-left:8px">Loading agents...</span>
        </label>
        <select name="el_agent_id" id="agent-select" class="form-control" required>
          <option value="">-- Select Agent --</option>
          <?php if (!empty($campaign['el_agent_id'])): ?>
            <option value="<?= htmlspecialchars($campaign['el_agent_id']) ?>" selected><?= htmlspecialchars($campaign['el_agent_id']) ?></option>
          <?php endif; ?>
        </select>
        <small style="color:#8892A4">Agents are fetched from your ElevenLabs account</small>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Passing Score (/100)</label>
          <input type="number" name="passing_score" class="form-control" value="<?= $campaign['passing_score'] ?? 70 ?>" min="0" max="100">
        </div>
        <div class="form-group">
          <label class="form-label">No. of Questions</label>
          <input type="number" name="num_questions" class="form-control" value="<?= $campaign['num_questions'] ?? 6 ?>" min="1" max="20">
        </div>
        <div class="form-group">
          <label class="form-label">Language</label>
          <select name="language" class="form-control">
            <option value="english" <?= ($campaign['language']??'english')==='english'?'selected':'' ?>>English</option>
            <option value="hinglish" <?= ($campaign['language']??'')==='hinglish'?'selected':'' ?>>Hinglish</option>
            <option value="hindi" <?= ($campaign['language']??'')==='hindi'?'selected':'' ?>>Hindi</option>
          </select>
        </div>
      </div>
      <button type="submit" class="btn-primary"><?= $is_edit ? '💾 Save Changes' : 'Save & Add Questions →' ?></button>
    </form>
  </div>

  <script>
  const currentAgentId = '<?= htmlspecialchars($campaign['el_agent_id'] ?? '') ?>';
  async function loadAgents() {
      try {
          const r = await fetch('api/interview.php?action=get_agents');
          const d = await r.json();
          const sel = document.getElementById('agent-select');
          document.getElementById('agent-loading').textContent = '';
          if (d.error) { document.getElementById('agent-loading').textContent = '❌ ' + d.error; return; }
          // Clear and rebuild
          sel.innerHTML = '<option value="">-- Select Agent --</option>';
          (d.agents || []).forEach(a => {
              const opt = document.createElement('option');
              opt.value = a.agent_id;
              opt.textContent = a.name + ' (' + a.agent_id + ')';
              if (a.agent_id === currentAgentId) opt.selected = true;
              sel.appendChild(opt);
          });
          document.getElementById('agent-loading').textContent = d.agents.length + ' agents loaded ✅';
      } catch(e) {
          document.getElementById('agent-loading').textContent = '❌ Failed to load agents';
      }
  }
  loadAgents();
  </script>

<?php elseif ($action === 'questions' && $campaign): ?>
  <?php $applyLink = campaign_apply_link($campaign); ?>
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:center">
    <div>
      <h2><?= htmlspecialchars($campaign['name']) ?></h2>
      <p>
        Role: <strong><?= htmlspecialchars($campaign['job_role']) ?></strong> |
        Agent: <code style="font-size:12px;color:#0066FF"><?= htmlspecialchars($campaign['el_agent_id'] ?: 'Not set') ?></code> |
        Pass: <?= $campaign['passing_score'] ?>/100
      </p>
    </div>
    <div style="display:flex;gap:8px">
      <button type="button" onclick="copyCampaignLink(<?= htmlspecialchars(json_encode($applyLink), ENT_QUOTES, 'UTF-8') ?>)" class="btn-green">Copy Apply Link</button>
      <a href="https://wa.me/?text=<?= urlencode('Apply here: ' . $applyLink) ?>" target="_blank" rel="noopener" class="btn-sm" style="color:#16A34A;border-color:#16A34A40;background:#16A34A10">Share WA</a>
      <a href="campaigns.php?action=apply_form&id=<?= $campaign_id ?>" class="btn-sm">Apply Form</a>
      <a href="campaigns.php?action=edit&id=<?= $campaign_id ?>" class="btn-sm">✏️ Edit</a>
      <a href="campaigns.php" class="btn-sm">← Back</a>
    </div>
  </div>

  <div class="card" style="padding:16px 18px">
    <div style="font-size:12px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Public Apply Link</div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <code style="flex:1;min-width:260px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:9px 12px;color:#2563EB;word-break:break-all"><?= htmlspecialchars($applyLink) ?></code>
      <a class="btn-sm" href="<?= htmlspecialchars($applyLink) ?>" target="_blank" rel="noopener">Preview</a>
    </div>
  </div>

  <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars(str_replace('_',' ',$_GET['msg'])) ?>!</div>
  <?php endif; ?>

  <?php if (!$campaign['el_agent_id'] || $campaign['el_agent_id'] === 'PASTE_YOUR_EL_AGENT_ID'): ?>
  <div class="alert alert-error">⚠️ ElevenLabs Agent not set! <a href="campaigns.php?action=edit&id=<?= $campaign_id ?>">Click here to select agent →</a></div>
  <?php endif; ?>

  <!-- Existing Questions -->
  <?php if (!empty($questions)):
    $total_weight = array_sum(array_column($questions, 'weight')); ?>
  <div class="card">
    <div class="card-header">
      <h3>Interview Questions (<?= count($questions) ?>)</h3>
      <span style="font-size:13px;color:<?= $total_weight==100?'#00C896':'#dc3545' ?>">
        Total Weight: <strong><?= $total_weight ?>%</strong>
        <?= $total_weight==100 ? '✅' : '⚠️ Must be 100%' ?>
      </span>
    </div>
    <table class="table">
      <thead><tr><th>#</th><th>Parameter</th><th>Type</th><th>Weight</th><th>Max Marks</th><th>Question</th><th>Logic</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($questions as $q): ?>
        <tr>
          <td><?= $q['order_no'] ?></td>
          <td><strong><?= htmlspecialchars($q['parameter_label']) ?></strong><br><small style="color:#8892A4"><?= htmlspecialchars($q['parameter']) ?></small></td>
          <td><span class="badge badge-draft"><?= htmlspecialchars(str_replace('_', ' ', $q['question_type'] ?? 'textarea')) ?></span></td>
          <td><strong><?= $q['weight'] ?>%</strong></td>
          <td><?= $q['max_marks'] ?></td>
          <td style="max-width:280px;font-size:13px"><?= htmlspecialchars($q['question_text']) ?></td>
          <td style="font-size:12px;color:#64748B">
            <?= !empty($q['branch_rules_json']) ? 'Branching' : 'Linear' ?>
          </td>
          <td><a href="campaigns.php?action=delete_question&id=<?= $campaign_id ?>&qid=<?= $q['id'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>" class="btn-danger" style="font-size:12px" onclick="return confirm('Delete?')">🗑</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Add Question -->
  <div class="card" style="max-width:720px">
    <div class="card-header"><h3>Add Question</h3></div>
    <form method="POST" action="campaigns.php?action=add_question&id=<?= $campaign_id ?>">
      <?= csrf_input() ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Parameter Key</label>
          <select name="parameter" class="form-control" onchange="this.nextElementSibling.nextElementSibling.value=this.options[this.selectedIndex].dataset.label">
            <option value="english_communication" data-label="English Communication Skills">english_communication</option>
            <option value="ai_tools_usage" data-label="AI Tools Usage">ai_tools_usage</option>
            <option value="ai_prompting" data-label="AI Prompting">ai_prompting</option>
            <option value="ai_projects" data-label="AI Projects Done">ai_projects</option>
            <option value="machine_learning" data-label="Machine Learning">machine_learning</option>
            <option value="api_db_integration" data-label="API & DB Integration">api_db_integration</option>
            <option value="domain_knowledge" data-label="Domain Knowledge">domain_knowledge</option>
            <option value="confidence" data-label="Confidence Level">confidence</option>
            <option value="custom" data-label="">custom</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Display Label *</label>
          <input type="text" name="parameter_label" class="form-control" placeholder="English Communication Skills" required>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Field Type</label>
          <select name="question_type" class="form-control">
            <option value="textarea">Long Text / Interview Answer</option>
            <option value="text">Short Text</option>
            <option value="number">Numeric</option>
            <option value="decimal">Decimal</option>
            <option value="date">Date</option>
            <option value="dropdown">Dropdown</option>
            <option value="multi_select">Multi-select</option>
            <option value="rating">Rating</option>
            <option value="file">Upload Section</option>
            <option value="audio">Record Audio</option>
            <option value="video">Record Video</option>
            <option value="hyperlink">Hyperlink</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Required</label>
          <label style="display:flex;align-items:center;gap:8px;padding:11px 0;font-size:14px">
            <input type="checkbox" name="is_required" checked> Candidate must answer this field
          </label>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Weight (%)</label>
          <input type="number" name="weight" class="form-control" value="15" min="1" max="100" required>
        </div>
        <div class="form-group">
          <label class="form-label">Max Marks</label>
          <input type="number" name="max_marks" class="form-control" value="15" min="1" required>
        </div>
        <div class="form-group">
          <label class="form-label">Order</label>
          <input type="number" name="order_no" class="form-control" value="<?= count($questions)+1 ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Question Text *</label>
        <textarea name="question_text" class="form-control" rows="3" placeholder="Write the interview question..." required></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Ideal Answer Hint (AI scoring criteria)</label>
        <textarea name="ideal_answer_hint" class="form-control" rows="2" placeholder="Keywords or criteria AI should look for..."></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Options (for dropdown / multi-select / rating labels)</label>
        <textarea name="options_text" class="form-control" rows="3" placeholder="One option per line, e.g.&#10;Yes&#10;No&#10;Maybe"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Conditional Logic JSON</label>
        <textarea name="branch_rules_json" class="form-control" rows="4" placeholder='Example: [{"when":"yes","jump_to_order":5},{"when":"no","skip_to_order":8}]'></textarea>
        <small style="color:#8892A4">Use answer keywords to jump or skip questions. Leave blank for linear flow.</small>
      </div>
      <button type="submit" class="btn-primary">+ Add Question</button>
    </form>
  </div>

<?php elseif ($action === 'apply_form' && $campaign): ?>
  <?php $applyLink = campaign_apply_link($campaign); ?>
  <style>
    .builder-shell{display:grid;grid-template-columns:minmax(0,1fr) 420px;gap:20px;align-items:start}
    .builder-hero{background:linear-gradient(135deg,#101827,#1D2A44 58%,#3A1C63);border-radius:18px;padding:26px 28px;color:#fff;box-shadow:0 20px 70px rgba(15,23,42,.22);margin-bottom:20px;overflow:hidden;position:relative}
    .builder-hero::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,255,255,.08) 1px,transparent 1px),linear-gradient(rgba(255,255,255,.06) 1px,transparent 1px);background-size:34px 34px;mask-image:linear-gradient(90deg,transparent,black 25%,black 75%,transparent);pointer-events:none}
    .builder-hero>*{position:relative}
    .builder-title{font-size:28px;font-weight:900;letter-spacing:-.7px;margin-bottom:6px}
    .builder-sub{font-size:14px;color:rgba(255,255,255,.72)}
    .builder-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
    .builder-actions a,.builder-actions button{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);color:#fff;border-radius:10px;padding:9px 14px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:7px;cursor:pointer}
    .builder-actions a:hover,.builder-actions button:hover{background:#fff;color:#111827}
    .builder-link-card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:14px;display:flex;align-items:center;gap:10px;box-shadow:var(--card-shadow);margin-bottom:18px}
    .builder-link-card code{flex:1;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:10px 12px;color:#2563EB;word-break:break-all;font-size:13px}
    .builder-note{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E3A8A;border-radius:14px;padding:14px 16px;margin-bottom:18px;display:flex;gap:12px;font-size:13px;line-height:1.55}
    .builder-note code{background:#DBEAFE;border-radius:6px;padding:2px 6px;color:#1D4ED8}
    .canvas-card,.builder-panel{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:16px;box-shadow:var(--card-shadow);overflow:hidden}
    .builder-panel{position:sticky;top:88px}
    .canvas-head,.panel-head{padding:18px 20px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .canvas-head h3,.panel-head h3{font-size:15px;font-weight:900;color:#0F172A;display:flex;align-items:center;gap:8px}
    .canvas-meta{font-size:12px;color:#64748B;font-weight:700}
    .field-list{padding:14px}
    .field-tile{border:1px solid #E5E7EB;background:#FFFFFF;border-radius:13px;padding:14px 14px;display:grid;grid-template-columns:34px minmax(0,1fr) auto;gap:12px;align-items:center;margin-bottom:10px;transition:all .18s}
    .field-tile:hover{border-color:#A78BFA;box-shadow:0 10px 30px rgba(124,58,237,.12);transform:translateY(-1px)}
    .field-order{width:34px;height:34px;border-radius:10px;background:#F3E8FF;color:#6B21A8;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900}
    .field-name{font-size:14px;font-weight:850;color:#111827;margin-bottom:3px}
    .field-detail{font-size:12px;color:#64748B;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
    .field-key{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:999px;padding:2px 7px;color:#2563EB}
    .type-pill{background:#F1F5F9;color:#475569;border-radius:999px;padding:2px 8px;font-weight:800;text-transform:capitalize}
    .req-pill{background:#DCFCE7;color:#166534;border-radius:999px;padding:2px 8px;font-weight:800}
    .empty-canvas{padding:42px 24px;text-align:center;color:#64748B}
    .empty-canvas i{font-size:34px;color:#A78BFA;margin-bottom:12px}
    .quick-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;padding:16px 20px 4px}
    .quick-chip{border:1px solid #E5E7EB;background:#F8FAFC;color:#334155;border-radius:10px;padding:9px 10px;font-size:12px;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:8px;text-align:left}
    .quick-chip:hover{border-color:#7C3AED;background:#F5F3FF;color:#6B21A8}
    .panel-body{padding:18px 20px 20px}
    .panel-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .panel-grid-3{display:grid;grid-template-columns:1fr 110px 130px;gap:12px;align-items:end}
    .builder-panel .form-control{border-radius:11px;padding:11px 13px}
    .builder-panel .form-label{font-size:12px;text-transform:uppercase;letter-spacing:.35px;color:#475569}
    .required-toggle{height:44px;display:flex;align-items:center;gap:9px;border:1.5px solid var(--light);border-radius:11px;padding:0 12px;font-size:13px;font-weight:700;color:#334155;background:#fff}
    .builder-submit{width:100%;justify-content:center;padding:12px 18px;margin-top:4px}
    @media(max-width:1100px){.builder-shell{grid-template-columns:1fr}.builder-panel{position:static}.panel-grid-3{grid-template-columns:1fr}.quick-grid{grid-template-columns:1fr 1fr}}
    @media(max-width:620px){.builder-hero{padding:20px}.builder-title{font-size:22px}.builder-link-card{display:block}.builder-link-card code{display:block;margin-bottom:10px}.field-tile{grid-template-columns:30px minmax(0,1fr)}.field-tile>a{grid-column:1/-1;justify-content:center}.quick-grid,.panel-grid-2{grid-template-columns:1fr}}
  </style>

  <div class="builder-hero">
    <div class="builder-title">Apply Form Builder</div>
    <div class="builder-sub"><?= htmlspecialchars($campaign['name']) ?> · Design the candidate-facing application flow for this campaign.</div>
    <div class="builder-actions">
      <a href="<?= htmlspecialchars($applyLink) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-eye"></i> Preview Apply Form</a>
      <a href="campaigns.php?action=questions&id=<?= $campaign_id ?>"><i class="fa-solid fa-microphone-lines"></i> Interview Questions</a>
      <a href="campaigns.php"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
  </div>

  <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars(str_replace('_',' ',$_GET['msg'])) ?>!</div>
  <?php endif; ?>

  <div class="builder-link-card">
    <code><?= htmlspecialchars($applyLink) ?></code>
    <button type="button" onclick="copyCampaignLink(<?= htmlspecialchars(json_encode($applyLink), ENT_QUOTES, 'UTF-8') ?>)" class="btn-green"><i class="fa-solid fa-copy"></i> Copy Link</button>
  </div>

  <div class="builder-note">
    <i class="fa-solid fa-circle-info" style="margin-top:3px"></i>
    <div>Use keys like <code>name</code>, <code>phone</code>, <code>email</code>, <code>city</code>, <code>resume</code>, <code>photo</code>, <code>current_ctc</code>, <code>expected_ctc</code>, <code>linkedin</code>. A <code>phone</code> field is required for WhatsApp/interview outreach.</div>
  </div>

  <div class="builder-shell">
    <div class="canvas-card">
      <div class="canvas-head">
        <h3><i class="fa-solid fa-diagram-project" style="color:#7C3AED"></i> Form Flow</h3>
        <div class="canvas-meta"><?= count($application_fields) ?> fields</div>
      </div>
      <div class="field-list">
      <?php if (!empty($application_fields)): ?>
        <?php foreach ($application_fields as $f): $opts = json_decode($f['options_json'] ?? '[]', true) ?: []; ?>
        <div class="field-tile">
          <div class="field-order"><?= (int)$f['order_no'] ?></div>
          <div>
            <div class="field-name"><?= htmlspecialchars($f['field_label']) ?></div>
            <div class="field-detail">
              <span class="field-key"><?= htmlspecialchars($f['field_key']) ?></span>
              <span class="type-pill"><?= htmlspecialchars(str_replace('_', ' ', $f['field_type'])) ?></span>
              <?php if (!empty($f['is_required'])): ?><span class="req-pill">Required</span><?php endif; ?>
              <?php if (!empty($opts)): ?><span><?= htmlspecialchars(implode(', ', array_slice($opts, 0, 3))) ?><?= count($opts) > 3 ? '...' : '' ?></span><?php endif; ?>
            </div>
          </div>
          <a href="campaigns.php?action=delete_application_field&id=<?= $campaign_id ?>&fid=<?= $f['id'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>" class="btn-danger" style="font-size:12px;padding:7px 12px" onclick="return confirm('Remove this application field?')"><i class="fa-solid fa-trash-can"></i></a>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-canvas">
          <i class="fa-solid fa-layer-group"></i>
          <h3 style="font-size:17px;color:#0F172A;margin-bottom:4px">Start with a candidate detail</h3>
          <p>Add fields from the right panel. Name, phone, CV, photo and client-specific questions can all be configured per campaign.</p>
        </div>
      <?php endif; ?>
      </div>
    </div>

    <div class="builder-panel">
      <div class="panel-head">
        <h3><i class="fa-solid fa-plus" style="color:#10B981"></i> Add Field</h3>
        <span class="canvas-meta">Quick presets</span>
      </div>
      <div class="quick-grid">
        <button type="button" class="quick-chip" onclick="presetField('Full Name','name','text','Candidate full name')"><i class="fa-solid fa-user"></i> Name</button>
        <button type="button" class="quick-chip" onclick="presetField('Phone','phone','phone','WhatsApp number')"><i class="fa-brands fa-whatsapp"></i> Phone</button>
        <button type="button" class="quick-chip" onclick="presetField('Email','email','email','Candidate email')"><i class="fa-solid fa-envelope"></i> Email</button>
        <button type="button" class="quick-chip" onclick="presetField('Upload CV','resume','file','Upload PDF or DOCX CV')"><i class="fa-solid fa-file-arrow-up"></i> CV</button>
        <button type="button" class="quick-chip" onclick="presetField('Photo','photo','file','Upload a recent photo')"><i class="fa-solid fa-image"></i> Photo</button>
        <button type="button" class="quick-chip" onclick="presetField('LinkedIn Profile','linkedin','url','https://linkedin.com/in/...')"><i class="fa-brands fa-linkedin"></i> LinkedIn</button>
      </div>
      <div class="panel-body">
        <form method="POST" action="campaigns.php?action=add_application_field&id=<?= $campaign_id ?>">
          <?= csrf_input() ?>
          <div class="panel-grid-2">
            <div class="form-group">
              <label class="form-label">Field Label *</label>
              <input type="text" name="field_label" id="fieldLabel" class="form-control" placeholder="LinkedIn Profile" required>
            </div>
            <div class="form-group">
              <label class="form-label">Field Key</label>
              <input type="text" name="field_key" id="fieldKey" class="form-control" placeholder="Auto generated if blank">
            </div>
          </div>
          <div class="panel-grid-3">
            <div class="form-group">
              <label class="form-label">Field Type</label>
              <select name="field_type" id="fieldType" class="form-control">
                <option value="text">Short Text</option>
                <option value="textarea">Long Text</option>
                <option value="number">Numeric</option>
                <option value="decimal">Decimal</option>
                <option value="date">Date</option>
                <option value="dropdown">Dropdown</option>
                <option value="multi_select">Multi-select</option>
                <option value="checkbox">Checkbox</option>
                <option value="email">Email</option>
                <option value="phone">Phone</option>
                <option value="url">Hyperlink</option>
                <option value="file">File Upload</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Order</label>
              <input type="number" name="order_no" class="form-control" value="<?= count($application_fields)+1 ?>" min="1">
            </div>
            <div class="form-group">
              <label class="form-label">Required</label>
              <label class="required-toggle"><input type="checkbox" name="is_required" checked> Required</label>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Placeholder</label>
            <input type="text" name="placeholder" id="fieldPlaceholder" class="form-control" placeholder="What should candidate enter?">
          </div>
          <div class="form-group">
            <label class="form-label">Help Text</label>
            <input type="text" name="help_text" id="fieldHelp" class="form-control" placeholder="Small instruction shown below field">
          </div>
          <div class="form-group">
            <label class="form-label">Options</label>
            <textarea name="options_text" id="fieldOptions" class="form-control" rows="3" placeholder="One option per line for dropdown, multi-select, or checkbox"></textarea>
          </div>
          <button type="submit" class="btn-primary builder-submit"><i class="fa-solid fa-circle-plus"></i> Add Field to Flow</button>
        </form>
      </div>
    </div>
  </div>
        </div>
        <div class="form-group">
          <label class="form-label">Order</label>
          <input type="number" name="order_no" class="form-control" value="<?= count($application_fields)+1 ?>" min="1">
        </div>
        <div class="form-group">
          <label class="form-label">Required</label>
          <label style="display:flex;align-items:center;gap:8px;padding:11px 0;font-size:14px">
            <input type="checkbox" name="is_required" checked> Candidate must fill
          </label>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Placeholder</label>
        <input type="text" name="placeholder" class="form-control" placeholder="What should candidate enter?">
      </div>
      <div class="form-group">
        <label class="form-label">Help Text</label>
        <input type="text" name="help_text" class="form-control" placeholder="Small instruction shown below field">
      </div>
      <div class="form-group">
        <label class="form-label">Options</label>
        <textarea name="options_text" class="form-control" rows="3" placeholder="One option per line for dropdown, multi-select, or checkbox"></textarea>
      </div>
      <button type="submit" class="btn-primary">+ Add Field</button>
    </form>
  </div>

<?php endif; ?>
</div>
<script>
async function copyCampaignLink(link) {
  try {
    await navigator.clipboard.writeText(link);
    alert('Campaign apply link copied');
  } catch (e) {
    prompt('Copy campaign apply link', link);
  }
}
function presetField(label, key, type, placeholder) {
  const labelEl = document.getElementById('fieldLabel');
  const keyEl = document.getElementById('fieldKey');
  const typeEl = document.getElementById('fieldType');
  const placeholderEl = document.getElementById('fieldPlaceholder');
  const helpEl = document.getElementById('fieldHelp');
  if (!labelEl) return;
  labelEl.value = label;
  keyEl.value = key;
  typeEl.value = type;
  placeholderEl.value = placeholder || '';
  helpEl.value = type === 'file' ? 'Candidate can upload this file during application.' : '';
  labelEl.focus();
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
