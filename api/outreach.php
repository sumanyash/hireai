<?php
// api/outreach.php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'whatsapp_status') {
    $user = verify_jwt();
    if (!$user) { json_response(['error' => 'Unauthorized'], 401); }
    json_response([
        'configured' => [
            'WA_API_URL' => WA_API_URL !== '',
            'WA_INSTANCE_ID' => WA_INSTANCE_ID !== '',
            'WA_TOKEN' => WA_TOKEN !== '',
        ],
        'api_url_host' => WA_API_URL ? parse_url(WA_API_URL, PHP_URL_HOST) : null,
    ]);
}

if ($action === 'send_test') {
    $user = verify_jwt();
    if (!$user) { json_response(['error' => 'Unauthorized'], 401); }
    $phone = trim($_GET['phone'] ?? '');
    if ($phone === '') json_response(['error' => 'phone required'], 400);
    $result = send_whatsapp($phone, "HireAI WhatsApp test message\n\nIf you received this, WhatsApp is configured correctly.");
    $ok = ($result['code'] >= 200 && $result['code'] < 300);
    json_response(['success' => $ok, 'provider' => $result], $ok ? 200 : 502);
}

if ($action === 'send_single') {
    $user = verify_jwt();
    if (!$user) { json_response(['error' => 'Unauthorized'], 401); }
    $candidate_id = (int)($_GET['candidate_id'] ?? 0);
    $c = db_fetch_one("SELECT c.*, camp.name as campaign_name, camp.job_role FROM candidates c JOIN campaigns camp ON c.campaign_id=camp.id WHERE c.id=? AND c.org_id=?", [$candidate_id, $user['org_id']], 'ii');
    if (!$c) json_response(['error' => 'Candidate not found'], 404);

    $url = INTERVIEW_URL . '?t=' . $c['unique_token'];
    $name = $c['name'] ?: 'Candidate';
    $msg = "🎯 *Interview Invitation — {$c['campaign_name']}*\n\nHi $name! 👋\n\nYou have been shortlisted for *{$c['job_role']}*.\n\nComplete your AI interview here:\n🔗 $url\n\n⏱ Duration: ~15 min | 🎤 Mic required\n\n*HireAI — Avyukta Intellicall*";

    $result = send_whatsapp($c['phone'], $msg);
    $status = ($result['code'] >= 200 && $result['code'] < 300) ? 'sent' : 'failed';
    error_log("[outreach send_single] candidate_id=$candidate_id phone={$c['phone']} status=$status code={$result['code']}");
    db_execute("INSERT INTO outreach_log (candidate_id,campaign_id,channel,status) VALUES (?,?,'whatsapp',?)", [$candidate_id, $c['campaign_id'], $status], 'iis');
    if ($status === 'sent') db_execute("UPDATE candidates SET status='outreach_sent' WHERE id=?", [$candidate_id], 'i');
    json_response(['status' => $status, 'message' => "WhatsApp $status to {$c['phone']}", 'provider' => $result]);
}

if ($action === 'bulk_send') {
    $user = verify_jwt();
    if (!$user) json_response(['error' => 'Unauthorized'], 401);
    $input = json_decode(file_get_contents('php://input'), true);
    $ids = array_map('intval', $input['candidate_ids'] ?? []);
    if (empty($ids)) json_response(['error' => 'No candidates selected'], 400);

    $sent = $failed = 0;
    foreach ($ids as $id) {
        $c = db_fetch_one("SELECT c.*, camp.name as campaign_name, camp.job_role FROM candidates c JOIN campaigns camp ON c.campaign_id=camp.id WHERE c.id=? AND c.org_id=?", [$id, $user['org_id']], 'ii');
        if (!$c) continue;
        $url = INTERVIEW_URL . '?t=' . $c['unique_token'];
        $name = $c['name'] ?: 'Candidate';
        $msg = "🎯 *Interview Invitation — {$c['campaign_name']}*\n\nHi $name!\n\nPlease complete your AI interview:\n🔗 $url\n\n*HireAI*";
        $result = send_whatsapp($c['phone'], $msg);
        if ($result['code'] >= 200 && $result['code'] < 300) {
            $sent++;
            db_execute("UPDATE candidates SET status='outreach_sent' WHERE id=?", [$id], 'i');
        } else { $failed++; }
        db_execute("INSERT INTO outreach_log (candidate_id,campaign_id,channel,status) VALUES (?,?,'whatsapp',?)", [$id, $c['campaign_id'], $result['code']<300?'sent':'failed'], 'iis');
        usleep(500000); // 0.5s delay between messages
    }
    json_response(['sent' => $sent, 'failed' => $failed, 'message' => "Sent: $sent | Failed: $failed"]);
}
