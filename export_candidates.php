<?php
require_once __DIR__ . '/includes/auth_check.php';

$campaign_id = (int)($_GET['campaign_id'] ?? 0);
$status = trim($_GET['status'] ?? '');
$allowed = ['pending','outreach_sent','interview_started','interview_completed','shortlisted','rejected','on_hold'];

$where = "c.org_id=?";
$params = [$user['org_id']];
$types = 'i';
if ($campaign_id) { $where .= " AND c.campaign_id=?"; $params[] = $campaign_id; $types .= 'i'; }
if ($status && in_array($status, $allowed, true)) { $where .= " AND c.status=?"; $params[] = $status; $types .= 's'; }

$rows = db_fetch_all(
    "SELECT c.id,c.name,c.phone,c.email,c.city,c.experience_years,c.current_ctc,c.expected_ctc,c.source,c.status,c.created_at,
            camp.name AS campaign_name,camp.job_role,ir.total_score,ir.max_score,ir.pass_fail,ir.ai_summary
     FROM candidates c
     LEFT JOIN campaigns camp ON c.campaign_id=camp.id
     LEFT JOIN interview_results ir ON c.id=ir.candidate_id
     WHERE $where
     ORDER BY c.created_at DESC",
    $params, $types
);

$filename = 'hireai_candidates_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID','Name','Phone','Email','City','Experience','Current CTC','Expected CTC','Source','Campaign','Role','Status','Score','Max Score','Pass/Fail','AI Summary','Created At']);
foreach ($rows as $row) {
    fputcsv($out, [
        $row['id'], $row['name'], $row['phone'], $row['email'], $row['city'], $row['experience_years'],
        $row['current_ctc'], $row['expected_ctc'], $row['source'], $row['campaign_name'], $row['job_role'],
        $row['status'], $row['total_score'], $row['max_score'], $row['pass_fail'], $row['ai_summary'], $row['created_at'],
    ]);
}
fclose($out);
exit;
