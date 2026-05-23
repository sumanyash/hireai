<?php
require_once __DIR__ . '/includes/functions.php';

$export_user = null;
$export_token = trim((string)($_GET['export_token'] ?? ''));
if ($export_token !== '') {
    $decoded = base64_decode($export_token, true);
    $parts = $decoded === false ? [] : explode(':', $decoded);
    if (count($parts) === 4) {
        [$org_id, $user_id, $exp, $sig] = $parts;
        $payload = (int)$org_id . ':' . (int)$user_id . ':' . (int)$exp;
        $expected = hash_hmac('sha256', $payload, JWT_SECRET);
        if ((int)$exp >= time() && hash_equals($expected, $sig)) {
            $export_user = [
                'org_id' => (int)$org_id,
                'user_id' => (int)$user_id,
                'role' => 'export',
            ];
        }
    }
}

if ($export_user) {
    $user = $export_user;
} else {
    $user = require_auth();
}

$campaign_id = (int)($_GET['campaign_id'] ?? 0);
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');
$filter_candidate = trim($_GET['f_candidate'] ?? '');
$filter_campaign  = trim($_GET['f_campaign'] ?? '');
$filter_status    = trim($_GET['f_status'] ?? '');
$filter_score     = trim($_GET['f_score'] ?? '');
$filter_applied   = trim($_GET['f_applied'] ?? '');
$sort = $_GET['sort'] ?? 'newest';
$detailed = (int)($_GET['detailed'] ?? 0) === 1;
$allowed = ['pending','outreach_sent','interview_started','interview_completed','shortlisted','rejected','on_hold'];
$sort_sql = match ($sort) {
    'oldest' => 'c.created_at ASC',
    'score_desc' => 'ir.total_score IS NULL, ir.total_score DESC, c.created_at DESC',
    'score_asc' => 'ir.total_score IS NULL, ir.total_score ASC, c.created_at DESC',
    'name' => 'c.name ASC',
    default => 'c.created_at DESC',
};

$where = "c.org_id=?";
$params = [$user['org_id']];
$types = 'i';
if ($campaign_id) { $where .= " AND c.campaign_id=?"; $params[] = $campaign_id; $types .= 'i'; }
if ($status && in_array($status, $allowed, true)) { $where .= " AND c.status=?"; $params[] = $status; $types .= 's'; }
if ($search !== '') {
    $like = "%$search%";
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR camp.name LIKE ? OR c.status LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sssss';
}
if ($filter_candidate !== '') {
    $like = "%$filter_candidate%";
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss';
}
if ($filter_campaign !== '') {
    $like = "%$filter_campaign%";
    $where .= " AND camp.name LIKE ?";
    $params[] = $like; $types .= 's';
}
if ($filter_status !== '') {
    $like = "%$filter_status%";
    $where .= " AND c.status LIKE ?";
    $params[] = $like; $types .= 's';
}
if ($filter_score !== '') {
    $like = "%$filter_score%";
    $where .= " AND CAST(ir.total_score AS CHAR) LIKE ?";
    $params[] = $like; $types .= 's';
}
if ($filter_applied !== '') {
    $like = "%$filter_applied%";
    $where .= " AND (DATE(c.created_at) LIKE ? OR DATE_FORMAT(c.created_at, '%d %b %Y') LIKE ?)";
    $params[] = $like; $params[] = $like; $types .= 'ss';
}

$rows = db_fetch_all(
    "SELECT c.*, camp.name AS campaign_name,camp.job_role,ir.total_score,ir.max_score,ir.pass_fail,ir.ai_summary
     FROM candidates c
     LEFT JOIN campaigns camp ON c.campaign_id=camp.id
     LEFT JOIN interview_results ir ON c.id=ir.candidate_id
     WHERE $where
     ORDER BY $sort_sql",
    $params, $types
);

$dynamicKeys = [];
if ($detailed) {
    foreach ($rows as $row) {
        $answers = json_decode((string)($row['application_answers_json'] ?? ''), true);
        if (!is_array($answers)) continue;
        foreach ($answers as $key => $value) {
            $label = is_string($key) ? $key : 'field_' . $key;
            $dynamicKeys[$label] = true;
        }
    }
}

$filename = 'hireai_candidates_' . ($detailed ? 'detailed_' : '') . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
$baseHeaders = [
    'ID','Name','Phone','Email','City','Experience','Current CTC','Expected CTC','Source',
    'Referral Name','Referral Medium','Campaign','Role','Status','Score','Max Score',
    'Pass/Fail','AI Summary','Resume','Photo','Applied At','Updated At'
];
$answerHeaders = array_keys($dynamicKeys);
fputcsv($out, array_merge($baseHeaders, $detailed ? $answerHeaders : []));

foreach ($rows as $row) {
    $line = [
        $row['id'], $row['name'], $row['phone'], $row['email'], $row['city'], $row['experience_years'],
        $row['current_ctc'], $row['expected_ctc'], $row['source'], $row['referred_by_name'] ?? '',
        $row['referred_medium'] ?? '', $row['campaign_name'], $row['job_role'], $row['status'],
        $row['total_score'], $row['max_score'], $row['pass_fail'], $row['ai_summary'],
        $row['resume_path'] ?? '', $row['photo_path'] ?? '', $row['created_at'], $row['updated_at'] ?? '',
    ];
    if ($detailed) {
        $answers = json_decode((string)($row['application_answers_json'] ?? ''), true);
        if (!is_array($answers)) $answers = [];
        foreach ($answerHeaders as $key) {
            $value = $answers[$key] ?? '';
            if (is_array($value)) $value = implode(', ', array_map('strval', $value));
            $line[] = $value;
        }
    }
    fputcsv($out, $line);
}
fclose($out);
exit;
