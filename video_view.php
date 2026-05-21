<?php
require_once __DIR__ . '/includes/auth_check.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) exit('Invalid request');

$session = db_fetch_one(
    "SELECT s.recording_url FROM interview_sessions s
     JOIN candidates c ON s.candidate_id = c.id
     WHERE s.candidate_id = ? AND c.org_id = ?
     ORDER BY s.id DESC LIMIT 1",
    [$id, $user['org_id']], 'ii'
);
if (!$session) {
    $videoFiles = glob(__DIR__ . '/uploads/video/session_' . $id . '_*.webm') ?: [];
    if ($videoFiles) {
        rsort($videoFiles);
        $recUrl = BASE_URL . '/uploads/video/' . basename($videoFiles[0]);
    } else {
        exit('No recording found for this candidate.');
    }
} else {
    $recUrl = $session['recording_url'] ?? '';
    if (!$recUrl) exit('No recording found for this candidate.');
}

$cand = db_fetch_one("SELECT name FROM candidates WHERE id=? AND org_id=?", [$id, $user['org_id']], 'ii');
$name = htmlspecialchars($cand['name'] ?? 'Candidate');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $name ?> — Recording</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0F172A;color:#fff;font-family:system-ui,sans-serif;height:100vh;display:flex;flex-direction:column}
.top{display:flex;align-items:center;justify-content:space-between;padding:12px 20px;background:#1E293B;border-bottom:1px solid #334155;flex-shrink:0}
.top h1{font-size:14px;font-weight:700;color:#E2E8F0}
.top a{font-size:12px;color:#94A3B8;text-decoration:none;padding:5px 12px;border:1px solid #334155;border-radius:8px}
.top a:hover{background:#334155}
.video-wrap{flex:1;display:flex;align-items:center;justify-content:center;padding:16px}
video{max-width:100%;max-height:100%;border-radius:12px;background:#000}
</style>
</head>
<body>
<div class="top">
  <h1>🎥 <?= $name ?> — Interview Recording</h1>
  <div style="display:flex;gap:8px">
    <a href="<?= htmlspecialchars($recUrl) ?>" download>⬇ Download</a>
    <a href="candidate_detail.php?id=<?= $id ?>">← Back</a>
  </div>
</div>
<div class="video-wrap">
  <video controls preload="auto" id="rec" onloadedmetadata="fixDur(this)">
    <source src="<?= htmlspecialchars($recUrl) ?>">
    Your browser does not support video playback.
  </video>
</div>
<script>
function fixDur(v) {
  if (!isNaN(v.duration) && v.duration !== Infinity && v.duration > 0) return;
  v.currentTime = 1e101;
  v.addEventListener('timeupdate', function h() {
    v.removeEventListener('timeupdate', h);
    v.currentTime = 0;
  }, { once: true });
}
</script>
</body>
</html>
