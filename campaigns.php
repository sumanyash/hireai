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

// ─── POST HANDLERS ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    if ($action === 'save') {
        $id = db_insert(
            "INSERT INTO campaigns (org_id,created_by,name,job_role,description,el_agent_id,passing_score,num_questions,language,status) VALUES (?,?,?,?,?,?,?,?,?,'draft')",
            [$user['org_id'],$user['user_id'],$_POST['name'],$_POST['job_role'],$_POST['description'],$_POST['el_agent_id'],(int)$_POST['passing_score'],(int)$_POST['num_questions'],$_POST['language']],
            'iissssiis'
        );
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $id, 'campaign_created');
        header("Location: campaigns.php?action=questions&id=$id&msg=created"); exit;
    }
    if ($action === 'edit_save') {
        db_execute(
            "UPDATE campaigns SET name=?,job_role=?,description=?,el_agent_id=?,passing_score=?,num_questions=?,language=? WHERE id=? AND org_id=?",
            [$_POST['name'],$_POST['job_role'],$_POST['description'],$_POST['el_agent_id'],(int)$_POST['passing_score'],(int)$_POST['num_questions'],$_POST['language'],$campaign_id,$user['org_id']],
            'ssssiisii'
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
    if ($action === 'activate') {
        db_execute("UPDATE campaigns SET status='active' WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
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

// ─── DATA ────────────────────────────────────────────────────────
$campaigns = db_fetch_all(
    "SELECT ca.*, COUNT(DISTINCT c.id) as total_cands FROM campaigns ca LEFT JOIN candidates c ON ca.id=c.campaign_id WHERE ca.org_id=? GROUP BY ca.id ORDER BY ca.created_at DESC",
    [$user['org_id']], 'i'
);
$campaign  = $campaign_id ? db_fetch_one("SELECT * FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii') : null;
$questions = $campaign_id ? db_fetch_all("SELECT * FROM questions WHERE campaign_id=? ORDER BY order_no", [$campaign_id], 'i') : [];
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
          <td><strong><?= htmlspecialchars($c['name']) ?></strong><br><small style="color:#8892A4"><?= date('d M Y', strtotime($c['created_at'])) ?></small></td>
          <td><?= htmlspecialchars($c['job_role']) ?></td>
          <td><small style="font-family:monospace;color:#0066FF"><?= $c['el_agent_id'] ? substr($c['el_agent_id'],0,20).'...' : '<span style="color:#dc3545">Not set</span>' ?></small></td>
          <td><?= $c['total_cands'] ?></td>
          <td><?= $c['passing_score'] ?>/100</td>
          <td><span class="badge badge-<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span></td>
          <td style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="campaigns.php?action=edit&id=<?= $c['id'] ?>" class="btn-sm">✏️ Edit</a>
            <a href="campaigns.php?action=questions&id=<?= $c['id'] ?>" class="btn-sm">Questions</a>
            <a href="candidates.php?campaign_id=<?= $c['id'] ?>" class="btn-sm">Leads</a>
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
      <a href="campaigns.php?action=edit&id=<?= $campaign_id ?>" class="btn-sm">✏️ Edit</a>
      <a href="campaigns.php" class="btn-sm">← Back</a>
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

<?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
