<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function make_jwt($user_id, $role, $org_id, $expiry_seconds = 86400) {
    $header  = rtrim(strtr(base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(['user_id'=>$user_id,'role'=>$role,'org_id'=>$org_id,'exp'=>time()+(int)$expiry_seconds])), '+/', '-_'), '=');
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
    $hdr = json_decode(base64_decode(strtr($h, '-_', '+/')), true);
    if (($hdr['alg'] ?? '') !== 'HS256') return null;
    return $data;
}

function require_auth() {
    $user = verify_jwt();
    if (!$user) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'],'application/json') !== false) {
            json_response(['error' => 'Unauthorized'], 401);
        }
        header('Location: ' . BASE_URL . '/');
        exit;
    }
    return $user;
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_input() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_or_die() {
    $sent = $_POST['csrf_token'] ?? '';
    if (!$sent || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(419);
        exit('Invalid security token. Please refresh and try again.');
    }
}

function audit_log($org_id, $user_id, $entity_type, $entity_id, $action, $details = []) {
    db_insert(
        "INSERT INTO audit_logs (org_id,user_id,entity_type,entity_id,action,details) VALUES (?,?,?,?,?,?)",
        [(int)$org_id, $user_id ? (int)$user_id : null, $entity_type, $entity_id ? (int)$entity_id : null, $action, json_encode($details)],
        'iisiss'
    );
}

function ensure_credit_wallet($org_id) {
    $org_id = (int)$org_id;
    if ($org_id <= 0) return null;
    $wallet = db_fetch_one("SELECT * FROM credit_wallets WHERE org_id=?", [$org_id], 'i');
    if ($wallet) return $wallet;
    db_insert("INSERT INTO credit_wallets (org_id) VALUES (?)", [$org_id], 'i');
    return db_fetch_one("SELECT * FROM credit_wallets WHERE org_id=?", [$org_id], 'i');
}

function channel_credit_column($channel) {
    return match ($channel) {
        'sms' => 'sms_credits',
        'email' => 'email_credits',
        'rcs' => 'rcs_credits',
        default => 'whatsapp_credits',
    };
}

function add_credits($org_id, $credits, $provider = 'manual', $amount = 0, $currency = 'INR', $user_id = null, $payment_id = null) {
    ensure_credit_wallet($org_id);
    $credits = array_merge(['whatsapp' => 0, 'sms' => 0, 'email' => 0, 'rcs' => 0], $credits);
    db_execute(
        "UPDATE credit_wallets SET whatsapp_credits=whatsapp_credits+?, sms_credits=sms_credits+?, email_credits=email_credits+?, rcs_credits=rcs_credits+? WHERE org_id=?",
        [(int)$credits['whatsapp'], (int)$credits['sms'], (int)$credits['email'], (int)$credits['rcs'], (int)$org_id],
        'iiiii'
    );
    return db_insert(
        "INSERT INTO credit_transactions (org_id,user_id,provider,provider_payment_id,amount,currency,credits_json,status) VALUES (?,?,?,?,?,?,?,'confirmed')",
        [(int)$org_id, $user_id ? (int)$user_id : null, $provider, $payment_id, (float)$amount, $currency, json_encode($credits)],
        'iissdss'
    );
}

function deduct_credit($org_id, $channel = 'whatsapp', $credits = 1, $candidate_id = null, $campaign_id = null, $reason = '') {
    $org_id = (int)$org_id;
    $credits = max(1, (int)$credits);
    if ($org_id <= 0) return ['success' => false, 'error' => 'Missing organization'];
    $wallet = ensure_credit_wallet($org_id);
    $column = channel_credit_column($channel);
    $balance = (int)($wallet[$column] ?? 0);
    if ($balance < $credits) {
        return ['success' => false, 'error' => 'Insufficient credits', 'balance' => $balance];
    }
    $db = get_db();
    $stmt = $db->prepare("UPDATE credit_wallets SET $column=$column-? WHERE org_id=? AND $column>=?");
    if (!$stmt) return ['success' => false, 'error' => 'Credit update failed', 'balance' => $balance];
    $stmt->bind_param('iii', $credits, $org_id, $credits);
    $stmt->execute();
    if ($db->affected_rows < 1) {
        return ['success' => false, 'error' => 'Insufficient credits (concurrent request)', 'balance' => $balance];
    }
    $after = max(0, $balance - $credits);
    db_insert(
        "INSERT INTO credit_usage (org_id,candidate_id,campaign_id,channel,credits_used,balance_after,reason) VALUES (?,?,?,?,?,?,?)",
        [$org_id, $candidate_id ? (int)$candidate_id : null, $campaign_id ? (int)$campaign_id : null, $channel, $credits, $after, $reason],
        'iiisiis'
    );
    return ['success' => true, 'balance_after' => $after];
}

function send_whatsapp($phone, $message, $context = []) {
    return send_whatsapp_content($phone, ['text' => $message], $context);
}

function send_whatsapp_content($phone, $content, $context = [], $endpoint = null) {
    // Credit check removed from pre-send — deduct_credit() is atomic and handles
    // insufficient balance via WHERE balance >= ? guard, avoiding the race condition
    // of a non-atomic read-then-send pattern.
    if (!WA_API_URL || !WA_INSTANCE_ID || !WA_TOKEN) {
        $missing = [];
        if (!WA_API_URL) $missing[] = 'WA_API_URL';
        if (!WA_INSTANCE_ID) $missing[] = 'WA_INSTANCE_ID';
        if (!WA_TOKEN) $missing[] = 'WA_TOKEN';
        $error = 'WhatsApp config missing: ' . implode(', ', $missing);
        error_log('[send_whatsapp] ' . $error);
        return ['code' => 0, 'response' => $error, 'error' => $error];
    }
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 10) $phone = '91' . $phone;
    $payload_data = ['instanceId'=>WA_INSTANCE_ID,'accessToken'=>WA_TOKEN,'to'=>$phone];
    if (!empty($context['bulk_payload'])) {
        $payload_data = array_merge($payload_data, $content);
    } else {
        $payload_data['content'] = $content;
    }
    $payload = json_encode($payload_data);
    $ch = curl_init($endpoint ?: WA_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Bearer '.WA_TOKEN],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code < 200 || $code >= 300) {
        error_log('[send_whatsapp] code=' . $code . ' phone=' . $phone . ' curl_error=' . $err . ' response=' . substr((string)$resp, 0, 500));
    }
    $credit = null;
    if (!$err && $code >= 200 && $code < 300 && !empty($context['org_id'])) {
        $credit = deduct_credit(
            (int)$context['org_id'],
            'whatsapp',
            (int)($context['credits'] ?? 1),
            $context['candidate_id'] ?? null,
            $context['campaign_id'] ?? null,
            $context['reason'] ?? 'whatsapp_message'
        );
    }
    return ['code' => $code, 'response' => $resp, 'error' => $err, 'credit' => $credit];
}

function call_openai($prompt, $max_tokens = 400) {
    $payload = json_encode(['model'=>'gpt-4o-mini','messages'=>[['role'=>'user','content'=>$prompt]],'temperature'=>0.3,'max_tokens'=>$max_tokens]);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Bearer '.OPENAI_API_KEY],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['choices'][0]['message']['content'] ?? '';
}

// REMOVED: score_candidate() — use api/score.php instead
/* function score_candidate($candidate_id, $campaign_id, $transcript) {
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
        send_whatsapp($cand['phone'], $msg, [
            'org_id' => $cand['org_id'],
            'candidate_id' => $candidate_id,
            'campaign_id' => $campaign_id,
            'reason' => 'legacy_interview_result_notification',
        ]);
    }
} */
