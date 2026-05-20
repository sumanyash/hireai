<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$opts = getopt('', ['all', 'campaign:', 'candidate:', 'limit:', 'dry-run']);
$campaign_id = isset($opts['campaign']) ? (int)$opts['campaign'] : 0;
$candidate_id = isset($opts['candidate']) ? (int)$opts['candidate'] : 0;
$limit = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 0;
$dry_run = array_key_exists('dry-run', $opts);

if (!$candidate_id && !$campaign_id && !array_key_exists('all', $opts)) {
    echo "Usage:\n";
    echo "  php scripts/rescore_once.php --all\n";
    echo "  php scripts/rescore_once.php --campaign=13\n";
    echo "  php scripts/rescore_once.php --candidate=112\n";
    echo "  php scripts/rescore_once.php --all --limit=20 --dry-run\n\n";
    echo "This refreshes AI scores in silent mode, so WhatsApp/result calls are not sent.\n";
    exit(1);
}

$where = "EXISTS (SELECT 1 FROM interview_answers ia WHERE ia.candidate_id = c.id)";
$params = [];
$types = '';

if ($candidate_id) {
    $where .= " AND c.id=?";
    $params[] = $candidate_id;
    $types .= 'i';
}
if ($campaign_id) {
    $where .= " AND c.campaign_id=?";
    $params[] = $campaign_id;
    $types .= 'i';
}

$sql = "SELECT c.id, c.campaign_id, c.name, camp.name AS campaign_name
        FROM candidates c
        JOIN campaigns camp ON camp.id = c.campaign_id
        WHERE $where
        ORDER BY c.id DESC";
if ($limit) {
    $sql .= " LIMIT " . $limit;
}

$rows = db_fetch_all($sql, $params, $types);
if (!$rows) {
    echo "No candidates found for rescore.\n";
    exit(0);
}

echo "Found " . count($rows) . " candidate(s) for silent rescore.\n";
if ($dry_run) {
    foreach ($rows as $row) {
        echo "[DRY RUN] candidate={$row['id']} campaign={$row['campaign_id']} {$row['name']} ({$row['campaign_name']})\n";
    }
    exit(0);
}

$score_script = realpath(__DIR__ . '/../api/score.php');
$php = PHP_BINARY ?: 'php';
$done = 0;
$failed = 0;

foreach ($rows as $row) {
    $cid = (int)$row['id'];
    $camp = (int)$row['campaign_id'];
    echo "\nRescoring candidate $cid / campaign $camp: {$row['name']}\n";
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($score_script) . ' ' . $cid . ' ' . $camp . ' --silent';
    passthru($cmd, $exit_code);
    if ($exit_code === 0) {
        $done++;
    } else {
        $failed++;
        echo "Failed candidate $cid with exit code $exit_code\n";
    }
}

echo "\nSilent rescore complete. Success: $done | Failed: $failed\n";
exit($failed ? 1 : 0);
