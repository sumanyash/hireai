#!/bin/bash
# ============================================
# HireAI - PHP Deploy Script
# Run: bash deploy.sh
# Server: /var/www/hire/
# ============================================

BASE="/var/www/hire"
mkdir -p $BASE/{api,includes,assets/css,assets/js,uploads}

# ─── DB CONFIG ───────────────────────────────
cat > $BASE/includes/config.php << 'PHPEOF'
<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'YOUR_MYSQL_PASSWORD');
define('DB_NAME', 'hireai');

define('JWT_SECRET', 'hireai_jwt_2025_change_this');
define('BASE_URL', 'https://hire.clouddialer.in');
define('INTERVIEW_URL', 'https://hire.clouddialer.in/interview.php');

define('EL_API_KEY', 'sk_f121e4d4345569ac4771c0e509a6ae0636c8bc2b2731e43e');
define('OPENAI_API_KEY', 'your-openai-key-here');

define('WA_API_URL', 'https://wa.clouddialer.in/api/v2/messages');
define('WA_INSTANCE_ID', 'RHS2B4V4AY');
define('WA_TOKEN', 'D6D9Q6VM');

session_start();
PHPEOF

# ─── DB CONNECTION ───────────────────────────
cat > $BASE/includes/db.php << 'PHPEOF'
<?php
require_once __DIR__ . '/config.php';

function get_db() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die(json_encode(['error' => 'DB connection failed: ' . $conn->connect_error]));
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function db_query($sql, $params = [], $types = '') {
    $db = get_db();
    if (empty($params)) {
        $result = $db->query($sql);
        return $result;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) return false;
    if (!empty($params)) {
        if (empty($types)) {
            $types = str_repeat('s', count($params));
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

function db_fetch_all($sql, $params = [], $types = '') {
    $result = db_query($sql, $params, $types);
    if (!$result) return [];
    if ($result instanceof mysqli_stmt) {
        $res = $result->get_result();
        return $res->fetch_all(MYSQLI_ASSOC);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

function db_fetch_one($sql, $params = [], $types = '') {
    $result = db_query($sql, $params, $types);
    if (!$result) return null;
    if ($result instanceof mysqli_stmt) {
        $res = $result->get_result();
        return $res->fetch_assoc();
    }
    return $result->fetch_assoc();
}

function db_insert($sql, $params = [], $types = '') {
    $result = db_query($sql, $params, $types);
    if (!$result) return false;
    return get_db()->insert_id;
}

function db_execute($sql, $params = [], $types = '') {
    $result = db_query($sql, $params, $types);
    return $result !== false;
}
PHPEOF

# ─── AUTH FUNCTIONS ──────────────────────────
cat > $BASE/includes/functions.php << 'PHPEOF'
<?php
require_once __DIR__ . '/db.php';

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function get_input() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if ($json) return $json;
    return array_merge($_GET, $_POST);
}

function generate_token($len = 32) {
    return bin2hex(random_bytes($len));
}

function make_jwt($user_id, $role, $org_id) {
    $header = base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload = base64_encode(json_encode([
        'user_id' => $user_id,
        'role' => $role,
        'org_id' => $org_id,
        'exp' => time() + 86400
    ]));
    $sig = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

function verify_jwt($token = null) {
    if (!$token) {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        $token = str_replace('Bearer ', '', $auth);
    }
    if (!$token && isset($_SESSION['token'])) {
        $token = $_SESSION['token'];
    }
    if (!$token) return null;

    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $sig] = $parts;
    $expected = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;

    $data = json_decode(base64_decode($payload), true);
    if ($data['exp'] < time()) return null;
    return $data;
}

function require_auth() {
    $user = verify_jwt();
    if (!$user) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            json_response(['error' => 'Unauthorized'], 401);
        }
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
    return $user;
}

function send_whatsapp($phone, $message) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 10) $phone = '91' . $phone;

    $payload = json_encode([
        'instanceId' => WA_INSTANCE_ID,
        'accessToken' => WA_TOKEN,
        'to' => $phone,
        'content' => ['text' => $message]
    ]);

    $ch = curl_init(WA_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WA_TOKEN
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'response' => $resp];
}

function call_openai($prompt, $max_tokens = 500) {
    $payload = json_encode([
        'model' => 'gpt-4o-mini',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.3,
        'max_tokens' => $max_tokens
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['choices'][0]['message']['content'] ?? '';
}

function score_candidate($candidate_id, $campaign_id, $transcript) {
    $questions = db_fetch_all(
        "SELECT * FROM questions WHERE campaign_id=? ORDER BY order_no",
        [$campaign_id], 'i'
    );
    if (empty($questions)) return;

    $total = 0;
    $max_total = 0;
    $score_details = [];

    foreach ($questions as $q) {
        $prompt = "You are an expert technical interviewer scoring a candidate's interview.\n\n";
        $prompt .= "Parameter: {$q['parameter_label']}\n";
        $prompt .= "Question: {$q['question_text']}\n";
        $prompt .= "Scoring Criteria: {$q['ideal_answer_hint']}\n";
        $prompt .= "Max Marks: {$q['max_marks']}\n\n";
        $prompt .= "Full Interview Transcript:\n$transcript\n\n";
        $prompt .= "Return ONLY valid JSON: {\"score\": <0-{$q['max_marks']}>, \"reasoning\": \"<2 sentence explanation>\"}";

        $response = call_openai($prompt, 300);
        $response = preg_replace('/```json|```/', '', $response);
        $result = json_decode(trim($response), true);

        $score = min((int)($result['score'] ?? 0), $q['max_marks']);
        $reasoning = $result['reasoning'] ?? '';

        db_execute(
            "INSERT INTO scores (candidate_id, campaign_id, parameter, parameter_label, transcript, ai_score, max_marks, ai_reasoning) VALUES (?,?,?,?,?,?,?,?)",
            [$candidate_id, $campaign_id, $q['parameter'], $q['parameter_label'], substr($transcript, 0, 1000), $score, $q['max_marks'], $reasoning],
            'iisssiis'
        );

        $total += $score;
        $max_total += $q['max_marks'];
        $score_details[] = "{$q['parameter_label']}: $score/{$q['max_marks']}";
    }

    $camp = db_fetch_one("SELECT passing_score FROM campaigns WHERE id=?", [$campaign_id], 'i');
    $passing = $camp['passing_score'] ?? 70;
    $pass_fail = $total >= $passing ? 'pass' : 'fail';

    $summary_prompt = "Candidate scored $total/$max_total in a technical interview.\nScore breakdown: " . implode(', ', $score_details) . "\nTranscript: " . substr($transcript, 0, 2000) . "\n\nWrite a 2-sentence performance summary highlighting strengths and weaknesses.";
    $summary = call_openai($summary_prompt, 150);

    db_execute(
        "INSERT INTO interview_results (candidate_id, campaign_id, total_score, max_score, pass_fail, ai_summary) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE total_score=VALUES(total_score), pass_fail=VALUES(pass_fail), ai_summary=VALUES(ai_summary), updated_at=NOW()",
        [$candidate_id, $campaign_id, $total, $max_total, $pass_fail, $summary],
        'iiisss'
    );

    $new_status = $pass_fail === 'pass' ? 'shortlisted' : 'rejected';
    db_execute("UPDATE candidates SET status=? WHERE id=?", [$new_status, $candidate_id], 'si');

    // Send WhatsApp result
    $candidate = db_fetch_one("SELECT * FROM candidates WHERE id=?", [$candidate_id], 'i');
    if ($candidate && $candidate['phone']) {
        $name = $candidate['name'] ?: 'Candidate';
        if ($pass_fail === 'pass') {
            $msg = "✅ *Interview Result — HireAI*\n\nHi $name! 🎉\n\nCongratulations! You have been *SHORTLISTED*.\n\n📊 Score: *$total/$max_total*\n\nOur HR team will contact you shortly.\n\n*HireAI — Avyukta Intellicall*";
        } else {
            $msg = "📋 *Interview Result — HireAI*\n\nHi $name,\n\nThank you for your interview.\n\n📊 Score: $total/$max_total\n\nWe'll keep your profile for future opportunities.\n\n*HireAI — Avyukta Intellicall*";
        }
        send_whatsapp($candidate['phone'], $msg);
    }
}
PHPEOF

echo "✅ includes/ created"

# ─── AUTH CHECK ──────────────────────────────
cat > $BASE/includes/auth_check.php << 'PHPEOF'
<?php
require_once __DIR__ . '/functions.php';
$user = require_auth();
PHPEOF

echo "Files created successfully!"
echo "Now run: mysql -u root -p < /var/www/hire/schema.sql"
