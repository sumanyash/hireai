<?php
// api/call_webhook.php — receives post-call results from Avya AI panel
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
$raw = file_get_contents('php://input');
verify_hmac_signature($raw, defined('CALL_WEBHOOK_SECRET') ? CALL_WEBHOOK_SECRET : (getenv('CALL_WEBHOOK_SECRET') ?: ''));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$data = json_decode($raw, true);
if ($data === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

error_log("[call_webhook] received call_id=" . ($data['call_id'] ?? 'n/a') . " phone=" . ($data['phone'] ?? 'n/a'));

// ── Normalise phone — strips +91 prefix to get bare 10-digit number ──────────
function normalize_phone_10(string $p): string {
    $p = preg_replace('/[^0-9]/', '', $p);
    if (strlen($p) === 12 && str_starts_with($p, '91')) return substr($p, 2);
    if (strlen($p) === 13 && str_starts_with($p, '091')) return substr($p, 3);
    return $p;
}

$raw_phone = (string)($data['phone'] ?? '');
$phone10   = normalize_phone_10($raw_phone);

// ── Match candidate ───────────────────────────────────────────
// Try by phone (last 10 digits), ordered by most recent
$candidate = null;
if ($phone10 !== '') {
    $like = "%$phone10";
    $candidate = db_fetch_one(
        "SELECT c.*, camp.id campaign_id, camp.org_id FROM candidates c
         JOIN campaigns camp ON c.campaign_id = camp.id
         WHERE c.phone LIKE ? ORDER BY c.created_at DESC LIMIT 1",
        [$like], 's'
    );
}

// Also try by call_id stored in outreach_log if phone didn't match
if (!$candidate && !empty($data['call_id'])) {
    $call_id = (string)$data['call_id'];
    $row = db_fetch_one(
        "SELECT c.*, camp.id campaign_id, camp.org_id
         FROM candidates c
         JOIN campaigns camp ON c.campaign_id = camp.id
         WHERE c.call_id=? ORDER BY c.created_at DESC LIMIT 1",
        [$call_id], 's'
    );
    if ($row) $candidate = $row;
}

if (!$candidate) {
    error_log("[call_webhook] no candidate matched for phone=$phone10");
    http_response_code(200); // return 200 so Avya doesn't retry
    echo json_encode(['status' => 'ignored', 'reason' => 'no matching candidate']);
    exit;
}

$candidate_id = (int)$candidate['id'];
$campaign_id  = (int)$candidate['campaign_id'];
$org_id       = (int)$candidate['org_id'];

// ── Extract insights ──────────────────────────────────────────
$insights  = $data['insights'] ?? [];
$score     = isset($insights['interview_score']) ? (int)$insights['interview_score'] : null;
$grade     = $insights['interview_grade'] ?? null;
$rec       = $insights['interview_recommendation'] ?? null;
$reasoning = $insights['score_reasoning'] ?? null;
$strengths = isset($insights['strengths']) ? json_encode($insights['strengths']) : null;
$improve   = isset($insights['improvements']) ? json_encode($insights['improvements']) : null;

$transcript = isset($data['transcript'])
    ? (is_array($data['transcript']) ? json_encode($data['transcript']) : (string)$data['transcript'])
    : null;

$summary  = $data['summary'] ?? null;
$sentiment= $data['sentiment'] ?? null;
$duration = (int)($data['duration_sec'] ?? 0);
$call_id  = $data['call_id'] ?? null;

// ── Store result ──────────────────────────────────────────────
db_execute(
    "INSERT INTO ai_call_results
        (candidate_id, campaign_id, org_id, call_id, phone, duration_sec,
         transcript, summary, sentiment,
         interview_score, interview_grade, interview_recommendation,
         score_reasoning, strengths, improvements, raw_payload)
     VALUES (?,?,?,?,?,?, ?,?,?, ?,?,?, ?,?,?,?)",
    [$candidate_id, $campaign_id, $org_id, $call_id, $raw_phone, $duration,
     $transcript, $summary, $sentiment,
     $score, $grade, $rec,
     $reasoning, $strengths, $improve, $raw],
    'iiississsissssss'
);

// ── Auto-update candidate status based on recommendation ──────
if ($rec) {
    $rec_lc = strtolower($rec);
    if (str_contains($rec_lc, 'advance')) {
        db_execute("UPDATE candidates SET status='shortlisted' WHERE id=?", [$candidate_id], 'i');
    } elseif (str_contains($rec_lc, 'reject')) {
        db_execute("UPDATE candidates SET status='rejected' WHERE id=?", [$candidate_id], 'i');
    }
}

error_log("[call_webhook] saved result for candidate_id=$candidate_id score=$score grade=$grade rec=$rec");
echo json_encode(['status' => 'ok', 'candidate_id' => $candidate_id]);
