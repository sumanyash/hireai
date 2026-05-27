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

function login_client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach ($candidates as $ip) {
        $ip = trim((string)$ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return 'unknown';
}

function login_ip_lock_key(): string {
    return hash('sha256', login_client_ip());
}

function login_lock_key(string $email = ''): string {
    return hash('sha256', login_client_ip() . '|' . strtolower(trim($email)));
}

function login_lock_file(): string {
    return sys_get_temp_dir() . '/hireai_login_locks.json';
}

function login_lock_mutate(callable $callback) {
    $data = [];
    $file = login_lock_file();
    $fh = fopen($file, 'c+');
    if (!$fh) return $callback($data);
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) $data = [];
    $now = time();
    foreach ($data as $key => $row) {
        if (($row['locked_until'] ?? 0) < $now && ($row['last_attempt'] ?? 0) < $now - 86400) unset($data[$key]);
    }
    $result = $callback($data);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

function login_lock_state(string $email = ''): array {
    $keys = [login_ip_lock_key(), login_lock_key($email)];
    return login_lock_mutate(function (&$data) use ($keys) {
        $state = ['attempts' => 0, 'locked_until' => 0];
        foreach ($keys as $key) {
            $row = $data[$key] ?? [];
            $state['attempts'] = max($state['attempts'], (int)($row['attempts'] ?? 0));
            $state['locked_until'] = max($state['locked_until'], (int)($row['locked_until'] ?? 0));
        }
        return $state;
    });
}

function login_lock_register_failure(string $email = ''): array {
    $keys = [login_ip_lock_key(), login_lock_key($email)];
    return login_lock_mutate(function (&$data) use ($keys) {
        $now = time();
        $max_attempts = 5;
        $attempts = 0;
        $locked_until = 0;
        foreach ($keys as $key) {
            $row = $data[$key] ?? ['attempts' => 0, 'locked_until' => 0, 'last_attempt' => 0];
            if (($row['last_attempt'] ?? 0) < $now - 900 && ($row['locked_until'] ?? 0) < $now) $row['attempts'] = 0;
            $row['attempts'] = (int)($row['attempts'] ?? 0) + 1;
            $row['last_attempt'] = $now;
            if ($row['attempts'] >= $max_attempts) $row['locked_until'] = $now + 900;
            $data[$key] = $row;
            $attempts = max($attempts, (int)$row['attempts']);
            $locked_until = max($locked_until, (int)($row['locked_until'] ?? 0));
        }
        return ['attempts' => $attempts, 'locked_until' => $locked_until, 'left' => max(0, $max_attempts - $attempts)];
    });
}

function login_lock_clear(string $email = ''): void {
    $keys = [login_ip_lock_key(), login_lock_key($email)];
    login_lock_mutate(function (&$data) use ($keys) {
        foreach ($keys as $key) unset($data[$key]);
        return null;
    });
}

function pagination_page(string $key = 'page'): int {
    return max(1, (int)($_GET[$key] ?? 1));
}

function pagination_per_page(string $key = 'per_page', int $default = 10, array $allowed = [5, 10, 25, 50, 100]): int {
    $value = (int)($_GET[$key] ?? $default);
    return in_array($value, $allowed, true) ? $value : $default;
}

function pagination_url(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
    }
    $qs = http_build_query($query);
    return $qs ? '?' . $qs : strtok($_SERVER['REQUEST_URI'] ?? '', '?');
}

function pagination_per_page_select(string $per_page_key, string $page_key, int $current, array $allowed = [5, 10, 25, 50, 100]): string {
    $html = '<select class="pager-select" onchange="window.location.href=this.value">';
    foreach ($allowed as $option) {
        $selected = $option === $current ? ' selected' : '';
        $url = htmlspecialchars(pagination_url([$per_page_key => $option, $page_key => 1]), ENT_QUOTES, 'UTF-8');
        $html .= '<option value="' . $url . '"' . $selected . '>' . (int)$option . '</option>';
    }
    return $html . '</select>';
}

function pagination_html(string $page_key, int $page, int $total_pages, int $total, int $per_page): string {
    $total_pages = max(1, $total_pages);
    $page = max(1, min($page, $total_pages));
    $from = $total > 0 ? (($page - 1) * $per_page) + 1 : 0;
    $to = min($total, $page * $per_page);
    $html = '<div class="pager-wrap">';
    $html .= '<div class="pager-summary">Showing <strong>' . $from . '</strong> to <strong>' . $to . '</strong> of <strong>' . (int)$total . '</strong></div>';
    if ($total_pages > 1) {
        $html .= '<div class="pager-actions">';
        $prev_url = htmlspecialchars(pagination_url([$page_key => max(1, $page - 1)]), ENT_QUOTES, 'UTF-8');
        $next_url = htmlspecialchars(pagination_url([$page_key => min($total_pages, $page + 1)]), ENT_QUOTES, 'UTF-8');
        $html .= '<a class="pager-btn' . ($page <= 1 ? ' disabled' : '') . '" href="' . $prev_url . '">Previous</a>';
        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);
        if ($start > 1) {
            $html .= '<a class="pager-btn" href="' . htmlspecialchars(pagination_url([$page_key => 1]), ENT_QUOTES, 'UTF-8') . '">1</a>';
            if ($start > 2) $html .= '<span class="pager-ellipsis">...</span>';
        }
        for ($i = $start; $i <= $end; $i++) {
            $url = htmlspecialchars(pagination_url([$page_key => $i]), ENT_QUOTES, 'UTF-8');
            $html .= '<a class="pager-btn' . ($i === $page ? ' active' : '') . '" href="' . $url . '">' . $i . '</a>';
        }
        if ($end < $total_pages) {
            if ($end < $total_pages - 1) $html .= '<span class="pager-ellipsis">...</span>';
            $html .= '<a class="pager-btn" href="' . htmlspecialchars(pagination_url([$page_key => $total_pages]), ENT_QUOTES, 'UTF-8') . '">' . $total_pages . '</a>';
        }
        $html .= '<a class="pager-btn' . ($page >= $total_pages ? ' disabled' : '') . '" href="' . $next_url . '">Next</a>';
        $html .= '</div>';
    }
    return $html . '</div>';
}
