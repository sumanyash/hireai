<?php
require_once __DIR__ . '/includes/auth_check.php';
$campaigns    = db_fetch_all("SELECT id, name, job_role FROM campaigns WHERE org_id=? ORDER BY name", [$user['org_id']], 'i');
$sel_campaign = (int)($_GET['campaign_id'] ?? 0);
$search       = trim($_GET['q'] ?? '');
$active_status = $_GET['status'] ?? 'all';
$filter_candidate = trim($_GET['f_candidate'] ?? '');
$filter_campaign  = trim($_GET['f_campaign'] ?? '');
$filter_status    = trim($_GET['f_status'] ?? '');
$filter_score     = trim($_GET['f_score'] ?? '');
$filter_applied   = trim($_GET['f_applied'] ?? '');
$sort = $_GET['sort'] ?? 'newest';
$sort_sql = match ($sort) {
    'oldest' => 'c.created_at ASC',
    'score_desc' => 'ir.total_score IS NULL, ir.total_score DESC, c.created_at DESC',
    'score_asc' => 'ir.total_score IS NULL, ir.total_score ASC, c.created_at DESC',
    'name' => 'c.name ASC',
    default => 'c.created_at DESC',
};
$per_page = (int)($_GET['per_page'] ?? 10);
if (!in_array($per_page, [5, 10, 25, 50, 100], true)) $per_page = 10;
$page         = max(1, (int)($_GET['page'] ?? 1));

// Base WHERE (no status filter) — for counts & pills
$bwhere  = "c.org_id=?";
$bparams = [$user['org_id']];
$btypes  = 'i';
if ($sel_campaign) { $bwhere .= " AND c.campaign_id=?"; $bparams[] = $sel_campaign; $btypes .= 'i'; }
if ($search) {
    $like = "%$search%";
    $bwhere .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR camp.name LIKE ? OR c.status LIKE ?)";
    $bparams[] = $like; $bparams[] = $like; $bparams[] = $like; $bparams[] = $like; $bparams[] = $like; $btypes .= 'sssss';
}
if ($filter_candidate !== '') {
    $like = "%$filter_candidate%";
    $bwhere .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $bparams[] = $like; $bparams[] = $like; $bparams[] = $like; $btypes .= 'sss';
}
if ($filter_campaign !== '') {
    $like = "%$filter_campaign%";
    $bwhere .= " AND camp.name LIKE ?";
    $bparams[] = $like; $btypes .= 's';
}
if ($filter_status !== '') {
    $like = "%$filter_status%";
    $bwhere .= " AND c.status LIKE ?";
    $bparams[] = $like; $btypes .= 's';
}
if ($filter_score !== '') {
    $like = "%$filter_score%";
    $bwhere .= " AND CAST(ir.total_score AS CHAR) LIKE ?";
    $bparams[] = $like; $btypes .= 's';
}
if ($filter_applied !== '') {
    $like = "%$filter_applied%";
    $bwhere .= " AND (DATE(c.created_at) LIKE ? OR DATE_FORMAT(c.created_at, '%d %b %Y') LIKE ?)";
    $bparams[] = $like; $bparams[] = $like; $btypes .= 'ss';
}

// Status counts across all statuses (no status filter)
$count_rows = db_fetch_all(
    "SELECT c.status
     FROM candidates c
     LEFT JOIN campaigns camp ON c.campaign_id=camp.id
     LEFT JOIN interview_results ir ON c.id=ir.candidate_id
     WHERE $bwhere",
    $bparams,
    $btypes
);
$total = count($count_rows);
$status_counts = [];
foreach ($count_rows as $r) $status_counts[$r['status']] = ($status_counts[$r['status']] ?? 0) + 1;

// Paginated query WITH status filter
$where  = $bwhere;
$params = $bparams;
$types  = $btypes;
if ($active_status !== 'all') { $where .= " AND c.status=?"; $params[] = $active_status; $types .= 's'; }

$count_row = db_fetch_one(
    "SELECT COUNT(*) cnt
     FROM candidates c
     LEFT JOIN campaigns camp ON c.campaign_id=camp.id
     LEFT JOIN interview_results ir ON c.id=ir.candidate_id
     WHERE $where",
    $params,
    $types
);
$total_filtered = (int)($count_row['cnt'] ?? 0);
$total_pages    = max(1, (int)ceil($total_filtered / $per_page));
$page           = min($page, $total_pages);
$offset         = ($page - 1) * $per_page;

$candidates = db_fetch_all(
    "SELECT c.*, camp.name campaign_name, camp.job_role, ir.total_score, ir.max_score, ir.pass_fail, ir.ai_summary, ir.id result_id
     FROM candidates c
     LEFT JOIN campaigns camp ON c.campaign_id=camp.id
     LEFT JOIN interview_results ir ON c.id=ir.candidate_id
     WHERE $where ORDER BY $sort_sql LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $offset]), $types . 'ii'
);
$column_source_rows = db_fetch_all(
    "SELECT c.application_answers_json
     FROM candidates c
     LEFT JOIN campaigns camp ON c.campaign_id=camp.id
     LEFT JOIN interview_results ir ON c.id=ir.candidate_id
     WHERE $where",
    $params,
    $types
);
$candidate_export_params = [
    'campaign_id' => $sel_campaign ?: null,
    'status' => $active_status !== 'all' ? $active_status : null,
    'q' => $search ?: null,
    'sort' => $sort,
    'f_candidate' => $filter_candidate ?: null,
    'f_campaign' => $filter_campaign ?: null,
    'f_status' => $filter_status ?: null,
    'f_score' => $filter_score ?: null,
    'f_applied' => $filter_applied ?: null,
    'detailed' => 1,
];

function candidate_col_key(string $key): string {
    $key = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $key));
    return trim($key, '_') ?: 'column';
}

function candidate_app_answers(array $row): array {
    $answers = json_decode((string)($row['application_answers_json'] ?? ''), true);
    return is_array($answers) ? $answers : [];
}

function candidate_app_field_value(array $row, string $fieldKey): string {
    foreach (candidate_app_answers($row) as $key => $answer) {
        if (is_array($answer)) {
            $answerKey = (string)($answer['key'] ?? $key);
            if ($answerKey !== $fieldKey && (string)$key !== $fieldKey) continue;
            $value = $answer['value'] ?? '';
        } else {
            if ((string)$key !== $fieldKey) continue;
            $value = $answer;
        }
        if (is_array($value)) return implode(', ', array_map('strval', $value));
        return (string)$value;
    }
    return '';
}

function candidate_short_value($value): string {
    if ($value === null || $value === '') return '—';
    if (is_array($value)) $value = implode(', ', array_map('strval', $value));
    return (string)$value;
}

$candidate_export_base_columns = [
    ['key' => 'id', 'label' => 'ID', 'value' => fn($row) => $row['id'] ?? ''],
    ['key' => 'candidate', 'label' => 'Name', 'value' => fn($row) => $row['name'] ?? ''],
    ['key' => 'phone', 'label' => 'Phone', 'value' => fn($row) => $row['phone'] ?? ''],
    ['key' => 'email', 'label' => 'Email', 'value' => fn($row) => $row['email'] ?? ''],
    ['key' => 'city', 'label' => 'City', 'value' => fn($row) => $row['city'] ?? ''],
    ['key' => 'experience_years', 'label' => 'Experience', 'value' => fn($row) => $row['experience_years'] ?? ''],
    ['key' => 'current_ctc', 'label' => 'Current CTC', 'value' => fn($row) => $row['current_ctc'] ?? ''],
    ['key' => 'expected_ctc', 'label' => 'Expected CTC', 'value' => fn($row) => $row['expected_ctc'] ?? ''],
    ['key' => 'source', 'label' => 'Source', 'value' => fn($row) => $row['source'] ?? ''],
    ['key' => 'referred_by_name', 'label' => 'Referral Name', 'value' => fn($row) => $row['referred_by_name'] ?? ''],
    ['key' => 'referred_medium', 'label' => 'Referral Medium', 'value' => fn($row) => $row['referred_medium'] ?? ''],
    ['key' => 'campaign', 'label' => 'Campaign', 'value' => fn($row) => $row['campaign_name'] ?? ''],
    ['key' => 'job_role', 'label' => 'Role', 'value' => fn($row) => $row['job_role'] ?? ''],
    ['key' => 'status', 'label' => 'Status', 'value' => fn($row) => $row['status'] ?? ''],
    ['key' => 'score', 'label' => 'Score', 'value' => fn($row) => $row['total_score'] ?? ''],
    ['key' => 'max_score', 'label' => 'Max Score', 'value' => fn($row) => $row['max_score'] ?? ''],
    ['key' => 'pass_fail', 'label' => 'Pass/Fail', 'value' => fn($row) => $row['pass_fail'] ?? ''],
    ['key' => 'ai_summary', 'label' => 'AI Summary', 'value' => fn($row) => $row['ai_summary'] ?? ''],
    ['key' => 'resume_path', 'label' => 'Resume', 'value' => fn($row) => $row['resume_path'] ?? ''],
    ['key' => 'photo_path', 'label' => 'Photo', 'value' => fn($row) => $row['photo_path'] ?? ''],
    ['key' => 'applied', 'label' => 'Applied At', 'value' => fn($row) => $row['created_at'] ?? ''],
    ['key' => 'updated_at', 'label' => 'Updated At', 'value' => fn($row) => $row['updated_at'] ?? ''],
];

$dynamic_candidate_columns = [];
foreach ($column_source_rows as $row) {
    foreach (candidate_app_answers($row) as $key => $answer) {
        if (is_array($answer)) {
            $fieldKey = (string)($answer['key'] ?? $key);
            $label = (string)($answer['label'] ?? $fieldKey);
        } else {
            $fieldKey = (string)$key;
            $label = (string)$key;
        }
        if ($fieldKey !== '') {
            $exportLabel = (string)$fieldKey;
            $dynamic_candidate_columns[$fieldKey] = [
                'label' => $label ?: $exportLabel,
                'export_label' => $exportLabel,
            ];
        }
    }
}

$default_visible_columns = ['candidate', 'campaign', 'status', 'score', 'applied'];
$candidate_table_columns = [];
foreach ($candidate_export_base_columns as $column) {
    $candidate_table_columns[] = [
        'key' => $column['key'],
        'label' => $column['key'] === 'candidate' ? 'Candidate' : $column['label'],
        'export_label' => $column['label'],
        'visible' => in_array($column['key'], $default_visible_columns, true),
        'base' => in_array($column['key'], $default_visible_columns, true),
        'export' => true,
    ];
}
foreach ($dynamic_candidate_columns as $fieldKey => $meta) {
    $candidate_table_columns[] = [
        'key' => 'app_' . candidate_col_key((string)$fieldKey),
        'field_key' => (string)$fieldKey,
        'label' => $meta['label'],
        'export_label' => $meta['export_label'],
        'visible' => false,
        'dynamic' => true,
        'export' => true,
    ];
}
$candidate_table_columns[] = ['key' => 'action', 'label' => 'Action', 'export_label' => 'Action', 'visible' => true, 'base' => true, 'export' => false];
$candidate_table_colspan = count($candidate_table_columns) + 1;
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
.dt-toolbar{display:flex;align-items:center;gap:12px;padding:14px 18px;background:#fff;border-bottom:1px solid #E2E8F0;flex-wrap:wrap}
.dt-left,.dt-actions,.dt-search{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.dt-left{min-width:0}
.dt-actions{justify-content:flex-end;margin-left:auto}
.dt-label{font-size:13px;font-weight:700;color:var(--text2);display:flex;align-items:center;gap:6px}
.dt-select{padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:9px;background:#F8FAFC;font-size:13px;font-weight:700;color:var(--text);outline:none}
.dt-action{display:inline-flex;align-items:center;gap:6px;border:none;border-radius:9px;background:#2563EB;color:#fff;padding:8px 12px;font-size:12px;font-weight:800;cursor:pointer;text-decoration:none;box-shadow:0 4px 12px rgba(37,99,235,.18);transition:transform .12s,background .12s}
.dt-action:hover{background:#1D4ED8;transform:translateY(-1px)}
.dt-search{justify-content:flex-start}
.dt-search label{font-size:13px;font-weight:800;color:var(--text2)}
.dt-search-input{width:240px;padding:8px 12px;border:1.5px solid #E2E8F0;border-radius:9px;background:#fff;font-size:13px;outline:none}
.dt-search-input:focus,.col-filter:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.columns-menu{position:relative}
.columns-menu.open .dt-action{background:#1D4ED8;box-shadow:0 0 0 3px rgba(37,99,235,.14),0 4px 12px rgba(37,99,235,.18)}
.columns-panel{display:none;position:absolute;top:calc(100% + 8px);right:0;background:#fff;border:1px solid #E2E8F0;border-radius:14px;box-shadow:0 18px 60px rgba(15,23,42,.18);padding:12px;z-index:20;width:360px;max-width:calc(100vw - 40px);max-height:440px;overflow:auto}
.columns-panel.active,.columns-menu.open .columns-panel{display:block}
.columns-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:2px 4px 10px;border-bottom:1px solid #E2E8F0;margin-bottom:8px}
.columns-title{font-size:12px;font-weight:900;color:var(--text);letter-spacing:.2px}
.columns-mini-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.columns-mini-actions button{border:1px solid #D8B4FE;background:#FAF5FF;color:#6D28D9;border-radius:7px;padding:5px 8px;font-size:10px;font-weight:900;cursor:pointer}
.columns-search{width:100%;padding:8px 10px;border:1.5px solid #E2E8F0;border-radius:9px;margin-bottom:8px;font-size:12px;outline:none}
.columns-grid{display:grid;grid-template-columns:1fr 1fr;gap:2px 6px}
.columns-panel label{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:8px;font-size:12px;font-weight:700;color:var(--text2);cursor:pointer;white-space:nowrap}
.columns-panel label:hover{background:#F8FAFC}
.column-label{display:flex;min-width:0;flex-direction:column;line-height:1.15}
.column-label strong{overflow:hidden;text-overflow:ellipsis}
.column-label small{color:var(--gray);font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-top:2px;overflow:hidden;text-overflow:ellipsis}
.col-hidden-default{display:none}
.extra-col{max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--gray2);font-size:12px}
.extra-col-wide{min-width:260px;white-space:normal;line-height:1.35}
.path-pill{display:inline-block;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border:1px solid #E2E8F0;background:#F8FAFC;border-radius:999px;padding:3px 8px;color:var(--gray2);font-size:11px}

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
.cand-table thead tr:first-child th{background:#F8FAFC;color:var(--gray);font-size:10px;text-align:left;font-style:normal;letter-spacing:.7px;text-transform:uppercase;border-right:none}
.cand-table thead tr.filter-row th{background:#EFF6FF;border-bottom:2px solid #DBEAFE;border-right:none;padding:8px 12px}
.col-filter{width:100%;min-width:100px;padding:7px 10px;border:1.5px solid #E2E8F0;border-radius:8px;background:#fff;font-size:12px;color:var(--text);font-weight:600;outline:none;text-transform:none;letter-spacing:0}
.col-filter::placeholder{color:#94A3B8;font-style:italic}
.sort-link{display:flex;align-items:center;justify-content:space-between;gap:8px;color:inherit;text-decoration:none}
.sort-icon{color:#CBD5E1;font-size:11px}
.cand-table th:first-child{border-radius:10px 0 0 0}
.cand-table th:last-child{border-radius:0 10px 0 0}
.cand-table td{padding:12px 14px;border-bottom:1px solid #F1F5F9;vertical-align:middle;transition:background .12s}
.cand-table tbody tr{animation:rowIn .35s ease both}
.cand-table tbody tr:hover td{background:#F8FAFC}
.cand-table tbody tr:last-child td{border-bottom:none}
@keyframes rowIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.cand-avatar{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:13px;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,.12)}
.cname-cell{display:flex;align-items:center;gap:10px}
.cname{font-weight:700;color:var(--text);font-size:13px;text-decoration:none}
.cname:hover{color:var(--blue)}
.cphone{font-size:11px;color:var(--gray);margin-top:1px}
.referral-cell{font-size:12px;color:var(--gray2);max-width:150px}
.referral-cell span{display:block;font-size:10px;color:var(--gray);text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px}
.score-pill{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:700}
.score-pass{background:#ECFDF5;color:#065F46}
.score-fail{background:#FEF2F2;color:#991B1B}
.score-pending{background:#F1F5F9;color:var(--gray)}
.act-btns{display:flex;gap:6px;opacity:0;transition:opacity .15s}
.cand-table tbody tr:hover .act-btns{opacity:1}
.act-btn{width:30px;height:30px;border-radius:8px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;transition:all .12s;text-decoration:none}
.act-view{background:#EFF6FF;color:#1E40AF}.act-view:hover{background:#DBEAFE;transform:scale(1.08)}
.act-del{background:#FEF2F2;color:#991B1B}.act-del:hover{background:#FECACA;transform:scale(1.08)}
.act-call{background:#ECFDF5;color:#065F46}.act-call:hover{background:#D1FAE5;transform:scale(1.08)}
.act-wa{background:#25D36620;color:#128C7E;border:1px solid #25D36630}.act-wa:hover{background:#25D36630;transform:scale(1.08)}
.empty-state{text-align:center;padding:60px 20px;color:var(--gray)}
.empty-icon{font-size:48px;opacity:.2;margin-bottom:12px}

/* ── PAGINATION ──────────────────────────────────────────── */
.pagination{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-top:1px solid #F1F5F9;flex-wrap:wrap;gap:10px}
.pg-info{font-size:12px;color:var(--gray);font-weight:600}
.pg-btns{display:flex;gap:4px;align-items:center}
.pg-btn{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border-radius:9px;font-size:12px;font-weight:700;text-decoration:none;color:var(--text2);border:1.5px solid #E2E8F0;background:#fff;transition:all .15s;cursor:pointer}
.pg-btn:hover{background:#F8FAFC;border-color:#CBD5E1}
.pg-btn.active{background:var(--blue);color:#fff;border-color:var(--blue);box-shadow:0 2px 8px rgba(37,99,235,.3)}
.pg-btn.disabled{opacity:.35;pointer-events:none}
.pg-ellipsis{padding:6px 4px;font-size:12px;color:var(--gray)}

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
@media(max-width:900px){.dt-actions{justify-content:flex-start;margin-left:0}.dt-search-input{width:100%}.columns-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">

<!-- HERO -->
<div class="page-hero animate-in">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
    <div>
      <div class="hero-title"><i class="fa-solid fa-users" style="font-size:22px;opacity:.8;margin-right:10px"></i>Candidates</div>
      <div class="hero-sub">Manage your candidate pipeline across all campaigns</div>
    </div>
    <div style="display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap">
      <button onclick="openAddModal()" class="btn-primary" style="padding:10px 20px;font-size:13px;white-space:nowrap">
        <i class="fa-solid fa-plus fa-sm"></i> Add Candidate
      </button>
      <a href="export_candidates.php?<?= http_build_query($candidate_export_params) ?>"
         class="btn-outline" style="padding:10px 16px;font-size:13px;white-space:nowrap;background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.2);color:#fff;text-decoration:none">
        <i class="fa-solid fa-file-csv fa-sm"></i> Export Detailed
      </a>
    </div>
  </div>
  <div class="hero-stats" style="margin-top:18px">
    <?php
    $hstats = [
      ['Total',       $total,                              'fa-users',        '#93C5FD'],
      ['Shortlisted', $status_counts['shortlisted'] ?? 0,  'fa-circle-check', '#6EE7B7'],
      ['Completed',   $status_counts['interview_completed'] ?? 0, 'fa-video','#C4B5FD'],
      ['Rejected',    $status_counts['rejected'] ?? 0,     'fa-circle-xmark', '#FCA5A5'],
      ['Pending',     $status_counts['pending'] ?? 0,      'fa-clock',        '#FCD34D'],
    ];
    foreach ($hstats as [$lbl, $num, $icon, $color]): ?>
    <div class="hstat">
      <div style="display:flex;align-items:center;gap:8px">
        <i class="fa-solid <?= $icon ?>" style="color:<?= $color ?>;font-size:16px;opacity:.9"></i>
        <div class="hstat-num"><?= $num ?></div>
      </div>
      <div class="hstat-lbl"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- TABLE CONTROLS -->
<form method="GET" id="candidateFilterForm" class="animate-in">
  <input type="hidden" name="status" value="<?= htmlspecialchars($active_status) ?>">
</form>

<!-- STATUS PILLS + TABLE (swapped on search) -->
<div id="results-container">
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
<?php
$avatarPalette = [
  'A'=>'135deg,#6366F1,#8B5CF6','B'=>'135deg,#3B82F6,#6366F1','C'=>'135deg,#0EA5E9,#3B82F6',
  'D'=>'135deg,#10B981,#059669','E'=>'135deg,#F59E0B,#D97706','F'=>'135deg,#EF4444,#DC2626',
  'G'=>'135deg,#8B5CF6,#7C3AED','H'=>'135deg,#06B6D4,#0891B2','I'=>'135deg,#84CC16,#65A30D',
  'J'=>'135deg,#F97316,#EA580C','K'=>'135deg,#EC4899,#DB2777','L'=>'135deg,#14B8A6,#0D9488',
  'M'=>'135deg,#6366F1,#4F46E5','N'=>'135deg,#3B82F6,#2563EB','O'=>'135deg,#10B981,#047857',
  'P'=>'135deg,#F59E0B,#B45309','Q'=>'135deg,#EF4444,#B91C1C','R'=>'135deg,#8B5CF6,#6D28D9',
  'S'=>'135deg,#F97316,#C2410C','T'=>'135deg,#06B6D4,#0E7490','U'=>'135deg,#84CC16,#4D7C0F',
  'V'=>'135deg,#EC4899,#BE185D','W'=>'135deg,#14B8A6,#0F766E','X'=>'135deg,#6366F1,#4338CA',
  'Y'=>'135deg,#F59E0B,#92400E','Z'=>'135deg,#EF4444,#991B1B',
];
?>
<div class="card animate-in" style="padding:0;overflow:visible">
  <div class="dt-toolbar">
    <div class="dt-left">
      <label class="dt-label">Show
        <select class="dt-select" name="per_page" form="candidateFilterForm" onchange="document.getElementById('candidateFilterForm').submit()">
          <?php foreach ([5,10,25,50,100] as $n): ?>
          <option value="<?= $n ?>" <?= $per_page === $n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
        entries
      </label>
      <select class="dt-select" name="campaign_id" form="candidateFilterForm" onchange="document.getElementById('candidateFilterForm').submit()" title="Campaign filter">
        <option value="">All Campaigns</option>
        <?php foreach ($campaigns as $camp): ?>
        <option value="<?= $camp['id'] ?>" <?= $sel_campaign === (int)$camp['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($camp['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <select class="dt-select" name="sort" form="candidateFilterForm" onchange="document.getElementById('candidateFilterForm').submit()" title="Sort">
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
        <option value="score_desc" <?= $sort === 'score_desc' ? 'selected' : '' ?>>Highest score</option>
        <option value="score_asc" <?= $sort === 'score_asc' ? 'selected' : '' ?>>Lowest score</option>
        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name A-Z</option>
      </select>
    </div>
    <div class="dt-search">
      <label for="search-q">Search</label>
      <input class="dt-search-input" type="text" name="q" id="search-q" form="candidateFilterForm" value="<?= htmlspecialchars($search) ?>" placeholder="Name, phone, email..." autocomplete="off">
      <?php if ($search || $sel_campaign || $sort !== 'newest' || $active_status !== 'all' || $filter_candidate || $filter_campaign || $filter_status || $filter_score || $filter_applied || $per_page !== 10): ?>
      <a href="candidates" class="btn-outline" style="padding:8px 12px;font-size:12px">Clear</a>
      <?php endif; ?>
    </div>
    <div class="dt-actions">
      <div class="columns-menu">
        <button type="button" id="columnsToggleBtn" class="dt-action" onclick="toggleColumnsPanel(event)" aria-expanded="false"><i class="fa-solid fa-table-columns"></i> Columns Visible</button>
        <div class="columns-panel" id="columnsPanel">
          <div class="columns-head">
            <div>
              <div class="columns-title">Table Columns</div>
              <div style="font-size:10px;color:var(--gray);font-weight:700">Same headers as detailed export</div>
            </div>
            <div class="columns-mini-actions">
              <button type="button" onclick="setColumnPreset('all')">All</button>
              <button type="button" onclick="setColumnPreset('export')">Export</button>
              <button type="button" onclick="setColumnPreset('core')">Core</button>
            </div>
          </div>
          <input class="columns-search" type="text" id="columnsSearch" placeholder="Find column..." oninput="filterColumnOptions(this.value)">
          <div class="columns-grid" id="columnsGrid">
          <?php foreach ($candidate_table_columns as $col):
            $colKey = htmlspecialchars($col['key']);
            $checked = !empty($col['visible']) ? 'checked' : '';
            $core = !empty($col['base']) ? '1' : '0';
            $exportCol = !empty($col['export']) ? '1' : '0';
            $exportLabel = (string)($col['export_label'] ?? $col['label']);
            $optionSearch = strtolower($col['label'] . ' ' . $exportLabel . ' ' . $col['key']);
          ?>
            <label data-column-option="<?= htmlspecialchars($optionSearch) ?>">
              <input type="checkbox" data-col-key="<?= $colKey ?>" data-core="<?= $core ?>" data-export="<?= $exportCol ?>" <?= $checked ?> onchange="toggleColumn(this)">
              <span class="column-label">
                <strong><?= htmlspecialchars($col['label']) ?></strong>
                <?php if ($exportLabel !== (string)$col['label']): ?>
                <small>Export: <?= htmlspecialchars($exportLabel) ?></small>
                <?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div style="overflow:auto">
  <table class="cand-table">
    <thead>
      <tr>
        <th style="width:36px;text-align:center"><input type="checkbox" id="select-all-cands" title="Select All" style="cursor:pointer;width:16px;height:16px"></th>
        <?php foreach ($candidate_table_columns as $col):
          $hiddenClass = empty($col['visible']) ? ' col-hidden-default' : '';
          $colKey = htmlspecialchars($col['key']);
          $label = htmlspecialchars($col['label']);
          $style = $col['key'] === 'action' ? ' style="width:120px"' : '';
        ?>
        <th class="<?= $hiddenClass ?>" data-col-key="<?= $colKey ?>"<?= $style ?>>
          <?php if ($col['key'] === 'candidate'): ?>
            <a class="sort-link" href="?<?= http_build_query(array_merge($_GET, ['sort' => $sort === 'name' ? 'newest' : 'name', 'page' => 1])) ?>"><?= $label ?> <i class="fa-solid fa-arrow-up-wide-short sort-icon"></i></a>
          <?php elseif ($col['key'] === 'score'): ?>
            <a class="sort-link" href="?<?= http_build_query(array_merge($_GET, ['sort' => $sort === 'score_desc' ? 'score_asc' : 'score_desc', 'page' => 1])) ?>"><?= $label ?> <i class="fa-solid fa-arrow-up-wide-short sort-icon"></i></a>
          <?php elseif ($col['key'] === 'applied'): ?>
            <a class="sort-link" href="?<?= http_build_query(array_merge($_GET, ['sort' => $sort === 'newest' ? 'oldest' : 'newest', 'page' => 1])) ?>"><?= $label ?> <i class="fa-solid fa-arrow-up-wide-short sort-icon"></i></a>
          <?php else: ?>
            <?= $label ?> <i class="fa-solid fa-arrow-up-wide-short sort-icon"></i>
          <?php endif; ?>
        </th>
        <?php endforeach; ?>
      </tr>
      <tr class="filter-row">
        <th></th>
        <?php foreach ($candidate_table_columns as $col):
          $hiddenClass = empty($col['visible']) ? ' col-hidden-default' : '';
          $colKey = htmlspecialchars($col['key']);
          $filterHtml = '';
          if ($col['key'] === 'candidate') $filterHtml = '<input class="col-filter" name="f_candidate" form="candidateFilterForm" value="' . htmlspecialchars($filter_candidate) . '" placeholder="Search Candidate">';
          if ($col['key'] === 'campaign') $filterHtml = '<input class="col-filter" name="f_campaign" form="candidateFilterForm" value="' . htmlspecialchars($filter_campaign) . '" placeholder="Search Campaign">';
          if ($col['key'] === 'status') $filterHtml = '<input class="col-filter" name="f_status" form="candidateFilterForm" value="' . htmlspecialchars($filter_status) . '" placeholder="Search Status">';
          if ($col['key'] === 'score') $filterHtml = '<input class="col-filter" name="f_score" form="candidateFilterForm" value="' . htmlspecialchars($filter_score) . '" placeholder="Search Score">';
          if ($col['key'] === 'applied') $filterHtml = '<input class="col-filter" name="f_applied" form="candidateFilterForm" value="' . htmlspecialchars($filter_applied) . '" placeholder="Search Date">';
        ?>
        <th class="<?= $hiddenClass ?>" data-col-key="<?= $colKey ?>"><?= $filterHtml ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($candidates)): ?>
    <tr>
      <td colspan="<?= $candidate_table_colspan ?>">
        <div class="empty-state">
          <div class="empty-icon"><i class="fa-solid fa-users"></i></div>
          <div style="font-size:16px;font-weight:700;margin-bottom:6px">No candidates found</div>
          <div style="font-size:13px;margin-bottom:16px">Try adjusting your filters or add a new candidate</div>
          <button onclick="openAddModal()" class="btn-primary">
            <i class="fa-solid fa-plus fa-sm"></i> Add Candidate
          </button>
        </div>
      </td>
    </tr>
    <?php else: foreach ($candidates as $i => $c):
      $initials = strtoupper(substr(trim($c['name']), 0, 1));
      $w2 = array_filter(explode(' ', trim($c['name'])));
      if (count($w2) > 1) $initials .= strtoupper(substr(end($w2), 0, 1));
      $firstLetter = strtoupper(substr(trim($c['name']), 0, 1));
      $grad = $avatarPalette[$firstLetter] ?? '135deg,#6366F1,#8B5CF6';
      $score = $c['total_score'];
      $maxScore = (int)($c['max_score'] ?? 100) ?: 100;
      $pf = $c['pass_fail'] ?? null;
      $delay = min($i * 40, 400);
    ?>
    <tr style="animation-delay:<?= $delay ?>ms">
      <td style="width:36px;text-align:center;padding:12px 8px">
        <input type="checkbox" class="cand-chk" value="<?= $c['id'] ?>" style="cursor:pointer;width:16px;height:16px">
      </td>
      <?php foreach ($candidate_table_columns as $col):
        $colKey = htmlspecialchars($col['key']);
        $hiddenClass = empty($col['visible']) ? ' col-hidden-default' : '';
      ?>
      <?php if ($col['key'] === 'candidate'): ?>
      <td class="<?= $hiddenClass ?>" data-col-key="<?= $colKey ?>">
        <div class="cname-cell">
          <div class="cand-avatar" style="background:linear-gradient(<?= $grad ?>)"><?= htmlspecialchars($initials) ?></div>
          <div>
            <a href="candidate_detail?id=<?= $c['id'] ?>" class="cname"><?= htmlspecialchars($c['name']) ?></a>
            <div class="cphone"><?= htmlspecialchars($c['phone'] ?? $c['email'] ?? '—') ?></div>
          </div>
        </div>
      </td>
      <?php elseif ($col['key'] === 'campaign'): ?>
      <td class="<?= $hiddenClass ?>" data-col-key="<?= $colKey ?>" style="font-size:12px;color:var(--gray2);max-width:180px">
        <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($c['campaign_name'] ?? '—') ?></div>
      </td>
      <?php elseif ($col['key'] === 'status'): ?>
      <td class="<?= $hiddenClass ?>" data-col-key="<?= $colKey ?>"><span class="badge badge-<?= $c['status'] ?>"><?= ucfirst(str_replace('_', ' ', $c['status'])) ?></span></td>
      <?php elseif ($col['key'] === 'score'): ?>
      <td class="<?= $hiddenClass ?>" data-col-key="<?= $colKey ?>">
        <?php if ($score !== null): ?>
        <span class="score-pill <?= $pf === 'pass' ? 'score-pass' : 'score-fail' ?>">
          <i class="fa-solid fa-<?= $pf === 'pass' ? 'circle-check' : 'circle-xmark' ?> fa-xs"></i>
          <?= $score ?>/<?= $maxScore ?>
        </span>
        <?php else: ?>
        <span class="score-pill score-pending"><i class="fa-regular fa-clock fa-xs"></i> Pending</span>
        <?php endif; ?>
      </td>
      <?php elseif ($col['key'] === 'applied'): ?>
      <td class="<?= $hiddenClass ?>" data-col-key="<?= $colKey ?>" style="font-size:12px;color:var(--gray);white-space:nowrap"><?= $c['created_at'] ? date('d M Y', strtotime($c['created_at'])) : '—' ?></td>
      <?php elseif ($col['key'] === 'action'): ?>
      <td class="<?= $hiddenClass ?>" data-col-key="<?= $colKey ?>">
        <div class="act-btns">
          <a href="candidate_detail?id=<?= $c['id'] ?>" class="act-btn act-view" title="View">
            <i class="fa-solid fa-eye"></i>
          </a>
          <?php if (!empty($c['phone'])): ?>
          <a href="tel:<?= htmlspecialchars($c['phone']) ?>" class="act-btn act-call" title="Call">
            <i class="fa-solid fa-phone"></i>
          </a>
          <button onclick="sendWA(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['name'] ?: $c['phone'])) ?>')"
            class="act-btn act-wa" title="WhatsApp Invite">
            <i class="fa-brands fa-whatsapp"></i>
          </button>
          <?php endif; ?>
          <button onclick="deleteCand(<?= $c['id'] ?>,'<?= addslashes(htmlspecialchars($c['name'])) ?>')"
            class="act-btn act-del" title="Delete">
            <i class="fa-solid fa-trash"></i>
          </button>
        </div>
      </td>
      <?php else:
        $plainValue = !empty($col['dynamic'])
          ? candidate_app_field_value($c, (string)$col['field_key'])
          : ($col['key'] === 'max_score' ? $maxScore : ($col['key'] === 'pass_fail' ? $pf : ($c[$col['key']] ?? '')));
        $isWide = in_array($col['key'], ['ai_summary'], true) || !empty($col['dynamic']);
        $displayValue = candidate_short_value($plainValue);
      ?>
      <td class="extra-col<?= $isWide ? ' extra-col-wide' : '' ?><?= $hiddenClass ?>" data-col-key="<?= $colKey ?>" title="<?= htmlspecialchars($displayValue) ?>">
        <?php if (in_array($col['key'], ['resume_path', 'photo_path'], true) && $displayValue !== '—'): ?>
          <span class="path-pill"><?= htmlspecialchars($displayValue) ?></span>
        <?php else: ?>
          <?= htmlspecialchars($displayValue) ?>
        <?php endif; ?>
      </td>
      <?php endif; ?>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>

  <!-- PAGINATION -->
  <?php if ($total_pages > 1 || $total_filtered > 0): ?>
  <div class="pagination">
    <div class="pg-info">
      Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_filtered) ?> of <?= $total_filtered ?> candidates
    </div>
    <div class="pg-btns">
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
         class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>">
        <i class="fa-solid fa-chevron-left fa-xs"></i> Prev
      </a>
      <?php
      $range_start = max(1, $page - 2);
      $range_end   = min($total_pages, $page + 2);
      if ($range_start > 1): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="pg-btn">1</a>
        <?php if ($range_start > 2): ?><span class="pg-ellipsis">…</span><?php endif; ?>
      <?php endif;
      for ($p = $range_start; $p <= $range_end; $p++): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
           class="pg-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor;
      if ($range_end < $total_pages): ?>
        <?php if ($range_end < $total_pages - 1): ?><span class="pg-ellipsis">…</span><?php endif; ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>" class="pg-btn"><?= $total_pages ?></a>
      <?php endif; ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
         class="pg-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">
        Next <i class="fa-solid fa-chevron-right fa-xs"></i>
      </a>
    </div>
  </div>
  <?php endif; ?>
</div>
</div><!-- /#results-container -->

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
            <option value="<?= $camp['id'] ?>" <?= $sel_campaign === (int)$camp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($camp['name']) ?></option>
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
          <label class="field-label">Expected CTC</label>
          <input class="field-input" type="text" id="addExpectedCtc" placeholder="8 LPA">
        </div>
        <div class="field-group">
          <label class="field-label">Source</label>
          <select class="field-input" id="addSource">
            <option value="">Select source...</option>
            <option>LinkedIn</option><option>Naukri</option><option>Indeed</option>
            <option>Referral</option><option>Walk-in</option><option>Website</option><option>Other</option>
          </select>
        </div>
        <div class="field-group">
          <label class="field-label">Referral Name</label>
          <input class="field-input" type="text" id="addReferralName" placeholder="Who referred this candidate?">
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
            <strong>Quick paste:</strong> Name, Phone, Email (one per line) &nbsp;|&nbsp; Phone & Email are optional
          </div>
        </div>
        <div class="field-group">
          <label class="field-label">Or Upload CSV</label>
          <input class="field-input" type="file" id="bulkCsvFile" accept=".csv,text/csv">
          <div class="bulk-hint">
            <strong>CSV headers:</strong> First Name, Last Name, Phone Code, Phone Number, Email, City, Experience, Current CTC, Expected CTC, Referral Name
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

<!-- BULK ACTION BAR -->
<div id="bulk-bar-cands" style="display:none;position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:12px 20px;border-radius:14px;box-shadow:0 4px 32px rgba(0,0,0,.45);align-items:center;gap:10px;z-index:999;white-space:nowrap;flex-wrap:wrap;max-width:95vw">
  <span id="bulk-cands-count" style="font-weight:700;margin-right:4px">0 selected</span>
  <button onclick="bulkStatus('shortlisted')" style="background:#059669;color:#fff;border:none;padding:7px 14px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px">✅ Shortlist</button>
  <button onclick="bulkStatus('rejected')"   style="background:#dc2626;color:#fff;border:none;padding:7px 14px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px">❌ Reject</button>
  <button onclick="bulkWhatsApp()"           style="background:#16a34a;color:#fff;border:none;padding:7px 14px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px">📲 WhatsApp All</button>
  <button onclick="bulkDeleteCands()"        style="background:#475569;color:#fff;border:none;padding:7px 14px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px">🗑 Delete</button>
  <button onclick="clearCandSelection()"     style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2);padding:7px 12px;border-radius:8px;cursor:pointer;font-size:13px">✕</button>
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
        expected_ctc: document.getElementById('addExpectedCtc').value.trim(),
        source: document.getElementById('addSource').value,
        referred_by_name: document.getElementById('addReferralName').value.trim(),
      })
    });
    const d = await readApiJson(r);
    if (d.success) {
      closeAdd();
      showToast('✅ ' + (d.message || 'Candidate added!'), 'success');
      setTimeout(() => location.reload(), 900);
    } else {
      showToast('❌ ' + (d.error || 'Failed'), 'error');
    }
  } catch(e) {
    showToast('❌ ' + (e.message || 'Network error. Try again.'), 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-plus fa-sm"></i> Add Candidate';
  }
}

async function submitBulk() {
  const camp = document.getElementById('bulkCampaign').value;
  const raw  = document.getElementById('bulkData').value.trim();
  const file = document.getElementById('bulkCsvFile').files[0];
  if (!camp) { showToast('Please select a campaign', 'error'); return; }
  if (!raw && !file)  { showToast('Paste candidate data or upload a CSV', 'error'); return; }

  let rows = raw ? raw.split('\n').map(line => {
    const p = line.split(',').map(s => s.trim());
    return { name: p[0] || '', phone: p[1] || '', email: p[2] || '' };
  }).filter(r => r.name) : [];

  let csvText = '';
  if (file) csvText = await file.text();
  if (!rows.length && !csvText.trim()) { showToast('No valid rows found', 'error'); return; }

  const btn = document.getElementById('bulkSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin fa-xs"></i> Importing...`;

  try {
    const r = await fetch('/api/candidates.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'bulk_import', campaign_id: parseInt(camp), rows, csv_text: csvText })
    });
    const d = await readApiJson(r);
    if (d.success) {
      closeAdd();
      showToast(`✅ Added ${d.added} | Dupes skipped: ${d.dupes} | Errors: ${d.errors}`, 'success');
      setTimeout(() => location.reload(), 1200);
    } else {
      showToast('❌ ' + (d.error || 'Import failed'), 'error');
    }
  } catch(e) {
    showToast('❌ ' + (e.message || 'Network error'), 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-file-import fa-sm"></i> Import All';
  }
}

async function readApiJson(response) {
  const text = await response.text();
  let data = {};
  try {
    data = text ? JSON.parse(text) : {};
  } catch (e) {
    const clean = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    throw new Error(clean ? clean.slice(0, 180) : `Server returned HTTP ${response.status}`);
  }
  if (!response.ok && !data.error) data.error = `Server returned HTTP ${response.status}`;
  return data;
}

// ── WHATSAPP INVITE ───────────────────────────────────────────
async function sendWA(id, name) {
  if (!confirm(`Send WhatsApp interview invite to ${name}?`)) return;
  showToast('Sending WhatsApp...', 'info');
  try {
    const r = await fetch(`/api/outreach.php?action=send_single&candidate_id=${id}`, {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const d = await r.json();
    if (d.status === 'sent') {
      showToast('✅ WhatsApp sent successfully!', 'success');
      setTimeout(() => location.reload(), 1500);
    } else {
      const providerError = d.provider?.error || d.provider?.response || '';
      showToast('❌ ' + (d.message || d.error || 'Failed to send') + (providerError ? ': ' + String(providerError).slice(0, 120) : ''), 'error');
    }
  } catch(e) {
    showToast('❌ Network error', 'error');
  }
}

// ── BULK DELETE ──────────────────────────────────────────────
const selectAllCands = document.getElementById('select-all-cands');
const bulkBarCands   = document.getElementById('bulk-bar-cands');
const bulkCandsCount = document.getElementById('bulk-cands-count');

function getCheckedCands() {
  return [...document.querySelectorAll('.cand-chk:checked')];
}
function updateCandBulkBar() {
  const checked = getCheckedCands();
  if (checked.length > 0) {
    bulkBarCands.style.display = 'flex';
    bulkCandsCount.textContent = checked.length + ' selected';
  } else {
    bulkBarCands.style.display = 'none';
  }
}
if (selectAllCands) {
  selectAllCands.addEventListener('change', function() {
    document.querySelectorAll('.cand-chk').forEach(c => c.checked = this.checked);
    updateCandBulkBar();
  });
}
document.querySelectorAll('.cand-chk').forEach(c => {
  c.addEventListener('change', function() {
    if (selectAllCands) selectAllCands.checked = [...document.querySelectorAll('.cand-chk')].every(x => x.checked);
    updateCandBulkBar();
  });
});
function clearCandSelection() {
  document.querySelectorAll('.cand-chk').forEach(c => c.checked = false);
  if (selectAllCands) selectAllCands.checked = false;
  bulkBarCands.style.display = 'none';
}
async function bulkDeleteCands() {
  const checked = getCheckedCands();
  if (!checked.length) return;
  if (!confirm('Delete ' + checked.length + ' candidate(s) and all their interview data? This CANNOT be undone.')) return;
  const ids = checked.map(c => parseInt(c.value));
  try {
    const r = await fetch('/api/candidates.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'bulk_delete', candidate_ids: ids })
    });
    const d = await r.json();
    if (d.success) {
      showToast('✅ ' + d.deleted + ' candidate(s) deleted', 'success');
      setTimeout(() => location.reload(), 800);
    } else {
      showToast('❌ ' + (d.error || 'Bulk delete failed'), 'error');
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

// ── TABLE SEARCH / COLUMN FILTERS ────────────────────────────
(function() {
  const form = document.getElementById('candidateFilterForm');
  const inputs = document.querySelectorAll('#search-q,.col-filter');
  if (!form || !inputs.length) return;
  let timer;
  async function runSearch() {
    const pageInput = document.createElement('input');
    pageInput.type = 'hidden';
    pageInput.name = 'page';
    pageInput.value = '1';
    form.appendChild(pageInput);
    form.submit();
  }
  inputs.forEach(input => {
    input.addEventListener('input', function() {
      clearTimeout(timer);
      timer = setTimeout(runSearch, 550);
    });
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); runSearch(); }
    });
  });
})();

function copyCandidateTable() {
  const rows = [...document.querySelectorAll('.cand-table tr')].map(row =>
    [...row.children]
      .filter(cell => getComputedStyle(cell).display !== 'none')
      .map(cell => cell.innerText.replace(/\s+/g, ' ').trim()).join('\t')
  ).join('\n');
  navigator.clipboard?.writeText(rows).then(
    () => showToast('Candidate table copied', 'success'),
    () => showToast('Copy failed', 'error')
  );
}

function printCandidateTable() {
  window.print();
}

function getColumnsPanel() {
  return document.getElementById('columnsPanel');
}

function getColumnsMenu() {
  return document.querySelector('.columns-menu');
}

function setColumnsPanelState(open) {
  const panel = getColumnsPanel();
  const menu = getColumnsMenu();
  const btn = document.getElementById('columnsToggleBtn');
  if (!panel || !menu) return;
  panel.classList.toggle('active', open);
  menu.classList.toggle('open', open);
  if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function toggleColumnsPanel(event) {
  if (event) {
    event.preventDefault();
    event.stopPropagation();
  }
  const panel = getColumnsPanel();
  setColumnsPanelState(!panel?.classList.contains('active'));
}

function closeColumnsPanel() {
  setColumnsPanelState(false);
}

function toggleColumn(box) {
  const key = box?.dataset?.colKey;
  if (!key) return;
  const safeKey = window.CSS && CSS.escape ? CSS.escape(key) : key.replace(/"/g, '\\"');
  document.querySelectorAll(`.cand-table [data-col-key="${safeKey}"]`).forEach(cell => {
    cell.classList.toggle('col-hidden-default', !box.checked);
    cell.style.display = box.checked ? '' : 'none';
  });
  saveColumnState();
}

function setColumnPreset(mode) {
  document.querySelectorAll('#columnsPanel input[data-col-key]').forEach(box => {
    box.checked = mode === 'all' || (mode === 'export' && box.dataset.export === '1') || (mode === 'core' && box.dataset.core === '1');
    toggleColumn(box);
  });
  saveColumnState();
}

function filterColumnOptions(term) {
  const q = (term || '').trim().toLowerCase();
  document.querySelectorAll('#columnsGrid label[data-column-option]').forEach(label => {
    const haystack = label.dataset.columnOption || '';
    label.style.display = !q || haystack.includes(q) ? '' : 'none';
  });
}

document.getElementById('columnsPanel')?.addEventListener('click', e => e.stopPropagation());

document.addEventListener('click', e => {
  const menu = getColumnsMenu();
  if (menu && !menu.contains(e.target)) closeColumnsPanel();
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeColumnsPanel();
});

document.addEventListener('DOMContentLoaded', () => {
  restoreColumnState();
  document.querySelectorAll('#columnsPanel input[data-col-key]').forEach(toggleColumn);
});

function saveColumnState() {
  const boxes = [...document.querySelectorAll('#columnsPanel input[data-col-key]')];
  if (!boxes.length) return;
  const selected = boxes.filter(box => box.checked).map(box => box.dataset.colKey);
  try { localStorage.setItem('hireai_candidate_columns', JSON.stringify(selected)); } catch(e) {}
}

function restoreColumnState() {
  let selected = null;
  try { selected = JSON.parse(localStorage.getItem('hireai_candidate_columns') || 'null'); } catch(e) {}
  if (!Array.isArray(selected)) return;
  document.querySelectorAll('#columnsPanel input[data-col-key]').forEach(box => {
    box.checked = selected.includes(box.dataset.colKey);
  });
}

// ── BULK STATUS UPDATE ────────────────────────────────────────
async function bulkStatus(status) {
  const checked = getCheckedCands();
  if (!checked.length) return;
  const label = status === 'shortlisted' ? 'shortlist' : 'reject';
  if (!confirm(`${label.charAt(0).toUpperCase()+label.slice(1)} ${checked.length} candidate(s)?`)) return;
  const ids = checked.map(c => parseInt(c.value));
  try {
    const r = await fetch('/api/candidates.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'bulk_status_update', candidate_ids: ids, status })
    });
    const d = await r.json();
    if (d.success) {
      showToast(`✅ ${d.updated} candidate(s) marked as ${status.replace('_',' ')}`, 'success');
      setTimeout(() => location.reload(), 900);
    } else {
      showToast('❌ ' + (d.error || 'Update failed'), 'error');
    }
  } catch(e) { showToast('❌ Network error', 'error'); }
}

async function bulkWhatsApp() {
  const checked = getCheckedCands();
  if (!checked.length) return;
  if (!confirm(`Send WhatsApp interview invite to ${checked.length} candidate(s)?`)) return;
  const ids = checked.map(c => parseInt(c.value));
  showToast('Sending WhatsApp invites…', 'info');
  try {
    const r = await fetch('/api/outreach.php?action=bulk_send', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ candidate_ids: ids })
    });
    const d = await r.json();
    showToast(`✅ Sent: ${d.sent} | Failed: ${d.failed}`, d.failed > 0 ? 'info' : 'success');
    setTimeout(() => location.reload(), 1200);
  } catch(e) { showToast('❌ Network error', 'error'); }
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
