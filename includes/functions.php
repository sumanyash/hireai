<?php
require_once __DIR__ . '/db.php';

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function make_jwt($user_id, $role, $org_id) {
    $header  = rtrim(strtr(base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(['user_id'=>$user_id,'role'=>$role,'org_id'=>$org_id,'exp'=>time()+86400])), '+/', '-_'), '=');
    $sig     = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)), '+/', '-_'), '=');
    return "$header.$payload.$sig";
}

function verify_jwt($token = null) {
    if (!$token) {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        $token = str_replace('Bearer ', '', $auth);
    }
    if (!$token && isset($_SESSION['token'])) $token = $_SESSION['token'];
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $s)) return null;
    $data = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
    if (!$data || $data['exp'] < time()) return null;
    return $data;
}

function require_auth() {
    $user = verify_jwt();
    if (!$user) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'],'application/json') !== false) {
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
    $payload = json_encode(['instanceId'=>WA_INSTANCE_ID,'accessToken'=>WA_TOKEN,'to'=>$phone,'content'=>['text'=>$message]]);
    $ch = curl_init(WA_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Bearer '.WA_TOKEN],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'response' => $resp];
}

function call_openai($prompt, $max_tokens = 400) {
    $payload = json_encode(['model'=>'gpt-4o-mini','messages'=>[['role'=>'user','content'=>$prompt]],'temperature'=>0.3,'max_tokens'=>$max_tokens]);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Bearer '.OPENAI_API_KEY],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['choices'][0]['message']['content'] ?? '';
}

function score_candidate($candidate_id, $campaign_id, $transcript) {
    $questions = db_fetch_all("SELECT * FROM questions WHERE campaign_id=? ORDER BY order_no", [$campaign_id], 'i');
    if (empty($questions)) return;

    $total = 0; $max_total = 0; $score_details = [];

    foreach ($questions as $q) {
        $prompt  = "You are an expert technical interviewer scoring a candidate response.\n";
        $prompt .= "Parameter: {$q['parameter_label']}\nQuestion: {$q['question_text']}\n";
        $prompt .= "Criteria: {$q['ideal_answer_hint']}\nMax Marks: {$q['max_marks']}\n\n";
        $prompt .= "Transcript:\n$transcript\n\n";
        $prompt .= "Return ONLY valid JSON (no markdown): {\"score\": <0-{$q['max_marks']}>, \"reasoning\": \"<2 sentence explanation>\"}";

        $response = call_openai($prompt, 300);
        $response = preg_replace('/```json|```/', '', trim($response));
        $result   = json_decode($response, true);
        $score    = min((int)($result['score'] ?? 0), $q['max_marks']);
        $reasoning = $result['reasoning'] ?? '';

        db_execute(
            "INSERT INTO scores (candidate_id,campaign_id,parameter,parameter_label,transcript,ai_score,max_marks,ai_reasoning) VALUES (?,?,?,?,?,?,?,?)",
            [$candidate_id,$campaign_id,$q['parameter'],$q['parameter_label'],substr($transcript,0,800),$score,$q['max_marks'],$reasoning],
            'iisssiis'
        );
        $total += $score; $max_total += $q['max_marks'];
        $score_details[] = "{$q['parameter_label']}: $score/{$q['max_marks']}";
    }

    $camp     = db_fetch_one("SELECT passing_score FROM campaigns WHERE id=?", [$campaign_id], 'i');
    $passing  = $camp['passing_score'] ?? 70;
    $pass_fail = $total >= $passing ? 'pass' : 'fail';

    $summary_prompt = "Candidate scored $total/$max_total. Breakdown: ".implode(', ',$score_details)."\nTranscript: ".substr($transcript,0,1500)."\nWrite a 2-sentence performance summary.";
    $summary = call_openai($summary_prompt, 150);

    db_execute(
        "INSERT INTO interview_results (candidate_id,campaign_id,total_score,max_score,pass_fail,ai_summary) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE total_score=VALUES(total_score),pass_fail=VALUES(pass_fail),ai_summary=VALUES(ai_summary),updated_at=NOW()",
        [$candidate_id,$campaign_id,$total,$max_total,$pass_fail,$summary], 'iiisss'
    );
    $new_status = $pass_fail === 'pass' ? 'shortlisted' : 'rejected';
    db_execute("UPDATE candidates SET status=? WHERE id=?", [$new_status,$candidate_id], 'si');

    // WhatsApp result notification
    $cand = db_fetch_one("SELECT * FROM candidates WHERE id=?", [$candidate_id], 'i');
    if ($cand && $cand['phone']) {
        $name = $cand['name'] ?: 'Candidate';
        if ($pass_fail === 'pass') {
            $msg = "✅ *Interview Result — HireAI*\n\nHi $name! 🎉\n\nCongratulations! You have been *SHORTLISTED*.\n📊 Score: *$total/$max_total*\n\nOur HR team will contact you shortly.\n\n*HireAI — Avyukta Intellicall*";
        } else {
            $msg = "📋 *Interview Result — HireAI*\n\nHi $name,\n\nThank you for your interview.\n📊 Score: $total/$max_total\n\nWe will keep your profile for future opportunities.\n\n*HireAI — Avyukta Intellicall*";
        }
        send_whatsapp($cand['phone'], $msg);
    }
}
