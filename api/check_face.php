<?php
/**
 * Backend face-presence check using Gemini Vision.
 * Called after each interview question is answered.
 * Returns {"face": true|false}
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}

$token      = trim($_POST['token'] ?? '');
$image_b64  = trim($_POST['image'] ?? '');
$question_no = (int)($_POST['question_no'] ?? 0);

if (!$token || !$image_b64) {
    http_response_code(400); echo json_encode(['error' => 'Missing fields']); exit;
}

// Validate active interview token
$candidate = db_fetch_one(
    "SELECT c.id, c.campaign_id FROM candidates c WHERE c.unique_token=? AND c.status IN ('interview_started','pending','outreach_sent')",
    [$token], 's'
);
if (!$candidate) {
    http_response_code(403); echo json_encode(['error' => 'Invalid token']); exit;
}

// Strip data-URL prefix (data:image/jpeg;base64,...)
$image_b64 = preg_replace('#^data:image/\w+;base64,#', '', $image_b64);

$gemini_key  = defined('GEMINI_API_KEY') && GEMINI_API_KEY ? GEMINI_API_KEY : '';
$credential  = defined('GOOGLE_APPLICATION_CREDENTIALS') && GOOGLE_APPLICATION_CREDENTIALS ? GOOGLE_APPLICATION_CREDENTIALS : '';
$model       = defined('GEMINI_MODEL') && GEMINI_MODEL ? GEMINI_MODEL : 'gemini-2.0-flash';

function face_b64url($d){ return rtrim(strtr(base64_encode($d),'+/','-_'),'='); }
function face_service_account_token($json_path) {
    if (!is_readable($json_path)) return [null, 'not readable'];
    $sa = json_decode(file_get_contents($json_path), true);
    if (empty($sa['client_email']) || empty($sa['private_key'])) return [null, 'invalid SA'];
    $now = time();
    $hdr = face_b64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));
    $clm = face_b64url(json_encode(['iss'=>$sa['client_email'],'scope'=>'https://www.googleapis.com/auth/cloud-platform','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600]));
    $sig = '';
    if (!openssl_sign("$hdr.$clm", $sig, $sa['private_key'], 'sha256WithRSAEncryption')) return [null, 'sign failed'];
    $jwt = "$hdr.$clm." . face_b64url($sig);
    $ch  = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]), CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode($resp, true);
    if ($code !== 200 || empty($data['access_token'])) return [null, "oauth error $code"];
    return [$data['access_token'], $sa];
}

if (!$gemini_key && !is_readable($credential)) {
    // No AI key configured — cannot check; assume face present
    echo json_encode(['face' => true, 'fallback' => true]); exit;
}

$payload = [
    'contents' => [[
        'role'  => 'user',
        'parts' => [
            [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data'      => $image_b64,
                ]
            ],
            ['text' => 'Is there a human face clearly visible and centered in this image? Answer only YES or NO.']
        ]
    ]],
    'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 4],
];

if (is_readable($credential)) {
    // Vertex AI path
    [$access_token, $sa_or_err] = face_service_account_token($credential);
    if (!$access_token) {
        echo json_encode(['face' => true, 'error' => 'SA token error']); exit;
    }
    $sa       = $sa_or_err;
    $project  = defined('VERTEX_AI_PROJECT') && VERTEX_AI_PROJECT ? VERTEX_AI_PROJECT : ($sa['project_id'] ?? '');
    $location = defined('VERTEX_AI_LOCATION') && VERTEX_AI_LOCATION ? VERTEX_AI_LOCATION : 'us-central1';
    $vmodel   = defined('VERTEX_AI_MODEL') && VERTEX_AI_MODEL ? VERTEX_AI_MODEL : $model;
    $url      = 'https://' . $location . '-aiplatform.googleapis.com/v1/projects/' . rawurlencode($project)
              . '/locations/' . rawurlencode($location) . '/publishers/google/models/' . rawurlencode($vmodel) . ':generateContent';
    $headers  = ['Content-Type: application/json', 'Authorization: Bearer ' . $access_token];
} else {
    // Direct Gemini API key path
    $url     = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($gemini_key);
    $headers = ['Content-Type: application/json'];
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    // On API error default to face-present so interview isn't disrupted
    echo json_encode(['face' => true, 'http_error' => $code]); exit;
}

$data   = json_decode($resp, true);
$answer = strtoupper(trim((string)($data['candidates'][0]['content']['parts'][0]['text'] ?? '')));
$face   = str_starts_with($answer, 'YES');

// Frontend's logCheat() handles persistence via saveAnswer / cheat_summary — no DB write needed here.
echo json_encode(['face' => $face]);
