<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? 'send_due';

if ($action === 'schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = verify_jwt();
    if (!$user) json_response(['error' => 'Unauthorized'], 401);
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $candidate_id = (int)($input['candidate_id'] ?? 0);
    $hours = max(1, min(168, (int)($input['hours'] ?? 24)));
    $c = db_fetch_one("SELECT id,campaign_id FROM candidates WHERE id=? AND org_id=?", [$candidate_id, $user['org_id']], 'ii');
    if (!$c) json_response(['error' => 'Candidate not found'], 404);
    db_insert(
        "INSERT INTO reminder_jobs (candidate_id,campaign_id,channel,scheduled_at) VALUES (?,?,'whatsapp',DATE_ADD(NOW(), INTERVAL ? HOUR))",
        [$candidate_id, $c['campaign_id'], $hours], 'iii'
    );
    json_response(['success' => true, 'message' => "Reminder scheduled in $hours hours"]);
}

if ($action === 'send_due') {
    $is_cli = php_sapi_name() === 'cli';
    if (!$is_cli) {
        $user = verify_jwt();
        if (!$user) json_response(['error' => 'Unauthorized'], 401);
    }
    $jobs = db_fetch_all(
        "SELECT r.*, c.name, c.phone, c.unique_token, c.status AS candidate_status, camp.name AS campaign_name, camp.job_role
         FROM reminder_jobs r
         JOIN candidates c ON r.candidate_id=c.id
         JOIN campaigns camp ON r.campaign_id=camp.id
         WHERE r.status='pending' AND r.scheduled_at<=NOW()
           AND c.status IN ('pending','outreach_sent','interview_started')
         ORDER BY r.scheduled_at ASC
         LIMIT 50"
    );
    $sent = 0; $failed = 0;
    foreach ($jobs as $job) {
        $link = INTERVIEW_URL . '?t=' . $job['unique_token'];
        $name = $job['name'] ?: 'Candidate';
        $msg = "⏰ *Interview Reminder — {$job['campaign_name']}*\n\nHi $name,\n\nYour AI interview for *{$job['job_role']}* is still pending.\n\nComplete it here:\n$link\n\n*HireAI — Avyukta Intellicall*";
        $res = send_whatsapp($job['phone'], $msg);
        $ok = isset($res['code']) && $res['code'] >= 200 && $res['code'] < 300;
        db_execute(
            "UPDATE reminder_jobs SET status=?, sent_at=IF(?='sent',NOW(),sent_at), attempts=attempts+1, last_error=? WHERE id=?",
            [$ok ? 'sent' : 'failed', $ok ? 'sent' : 'failed', $ok ? null : ($res['response'] ?? 'Send failed'), $job['id']],
            'sssi'
        );
        $ok ? $sent++ : $failed++;
    }
    json_response(['success' => true, 'sent' => $sent, 'failed' => $failed]);
}

json_response(['error' => 'Unknown action'], 400);
