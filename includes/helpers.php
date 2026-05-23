<?php

function security_json_error(string $message, int $code = 403): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

function verify_hmac_signature(string $payload, string $secret, string $header = 'HTTP_X_SIGNATURE'): void {
    $sig = $_SERVER[$header] ?? '';
    if ($secret === '' || $sig === '') {
        error_log('[security] Missing webhook secret or signature for ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
        security_json_error('Unauthorized', 401);
    }
    $expected = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $sig)) {
        error_log('[security] Invalid webhook signature for ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
        security_json_error('Unauthorized', 401);
    }
}

function verify_session_ownership($pdo, string $token, int $session_id): ?array {
    if ($token === '' || $session_id <= 0) {
        error_log('[security] Missing token/session_id during session ownership check');
        return null;
    }
    $candidate = db_fetch_one(
        "SELECT id, campaign_id FROM candidates WHERE unique_token=?",
        [$token],
        's'
    );
    if (!$candidate) {
        error_log('[security] Invalid candidate token during session ownership check');
        return null;
    }
    $session = db_fetch_one(
        "SELECT id, candidate_id, campaign_id FROM interview_sessions WHERE id=? AND candidate_id=?",
        [$session_id, (int)$candidate['id']],
        'ii'
    );
    if (!$session) {
        error_log('[security] Session ownership failed candidate_id=' . (int)$candidate['id'] . ' session_id=' . $session_id);
        return null;
    }
    return ['candidate' => $candidate, 'session' => $session];
}

function verify_question_belongs_to_session(int $question_id, array $session): bool {
    if ($question_id <= 0 || empty($session['campaign_id'])) return false;
    $question = db_fetch_one(
        "SELECT id FROM questions WHERE id=? AND campaign_id=?",
        [$question_id, (int)$session['campaign_id']],
        'ii'
    );
    if (!$question) {
        error_log('[security] Question ownership failed question_id=' . $question_id . ' campaign_id=' . (int)$session['campaign_id']);
        return false;
    }
    return true;
}

function detect_uploaded_mime(string $tmp_path): string {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) return '';
    $mime = finfo_file($finfo, $tmp_path) ?: '';
    finfo_close($finfo);
    return $mime;
}

function upload_safe_extension(string $mime, string $kind): ?string {
    $audio = [
        'audio/webm' => 'webm',
        'video/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
    ];
    $video = [
        'video/webm' => 'webm',
        'video/mp4' => 'mp4',
    ];
    $map = $kind === 'video' ? $video : $audio;
    return $map[$mime] ?? null;
}

function is_blocked_integration_host(string $host): bool {
    $ip = gethostbyname($host);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) return true;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        $ranges = [
            ['127.0.0.0', '127.255.255.255'],
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['0.0.0.0', '0.255.255.255'],
        ];
        foreach ($ranges as [$start, $end]) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) return true;
        }
        return false;
    }
    return $ip === '::1' || str_starts_with(strtolower($ip), 'fc') || str_starts_with(strtolower($ip), 'fd') || str_starts_with(strtolower($ip), 'fe80');
}

function validate_integration_endpoint(string $endpoint): bool {
    $parts = parse_url($endpoint);
    if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) return false;
    $allowlist = array_filter(array_map('trim', explode(',', defined('INTEGRATION_ALLOWED_DOMAINS') ? INTEGRATION_ALLOWED_DOMAINS : '')));
    if ($allowlist) {
        $host = strtolower($parts['host']);
        $allowed = false;
        foreach ($allowlist as $domain) {
            $domain = strtolower(ltrim($domain, '.'));
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) return false;
    }
    return !is_blocked_integration_host($parts['host']);
}
