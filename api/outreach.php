<?php
// api/outreach.php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

function wa_bulk_endpoint() {
    $url = rtrim(WA_API_URL, '/');
    if (str_ends_with($url, '/messages')) return preg_replace('~/messages$~', '/sendBulk', $url);
    return rtrim(dirname($url), '/') . '/sendBulk';
}

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
    $result = send_whatsapp($phone, "HireAI WhatsApp test message\n\nIf you received this, WhatsApp is configured correctly.", [
        'org_id' => $user['org_id'],
        'reason' => 'whatsapp_test',
    ]);
    $ok = ($result['code'] >= 200 && $result['code'] < 300);
    json_response(['success' => $ok, 'provider' => $result], $ok ? 200 : 502);
}

if ($action === 'custom_whatsapp_send') {
    $user = verify_jwt();
    if (!$user) { json_response(['error' => 'Unauthorized'], 401); }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids = array_values(array_filter(array_map('intval', $input['candidate_ids'] ?? [])));
    $type = trim((string)($input['type'] ?? 'text'));
    $data = is_array($input['data'] ?? null) ? $input['data'] : [];
    if (empty($ids)) json_response(['error' => 'No candidates selected'], 400);

    $content = null;
    $bulk_payload = false;
    $endpoint = null;
    $text = trim((string)($data['text'] ?? ''));
    $url = trim((string)($data['url'] ?? ''));
    $caption = trim((string)($data['caption'] ?? ''));
    $mime = trim((string)($data['mimetype'] ?? ''));

    if ($type === 'text') {
        if ($text === '') json_response(['error' => 'Message text required'], 400);
        $content = ['text' => $text];
    } elseif ($type === 'image') {
        if ($url === '') json_response(['error' => 'Image URL required'], 400);
        $content = ['image' => ['url' => $url]];
        if ($caption !== '') $content['caption'] = $caption;
        if (!empty($data['viewOnce'])) $content['viewOnce'] = true;
    } elseif ($type === 'video') {
        if ($url === '') json_response(['error' => 'Video URL required'], 400);
        $content = ['video' => ['url' => $url]];
        if ($caption !== '') $content['caption'] = $caption;
        if (!empty($data['viewOnce'])) $content['viewOnce'] = true;
        if (!empty($data['gifPlayback'])) $content['gifPlayback'] = true;
    } elseif ($type === 'audio') {
        if ($url === '') json_response(['error' => 'Audio URL required'], 400);
        $content = ['audio' => ['url' => $url], 'mimetype' => $mime ?: 'audio/mpeg', 'ptt' => !empty($data['ptt'])];
    } elseif ($type === 'document') {
        if ($url === '') json_response(['error' => 'Document URL required'], 400);
        $content = [
            'document' => ['url' => $url],
            'fileName' => trim((string)($data['fileName'] ?? 'Document')),
            'mimetype' => $mime ?: 'application/pdf',
        ];
        if ($caption !== '') $content['caption'] = $caption;
    } elseif ($type === 'location') {
        $lat = trim((string)($data['latitude'] ?? ''));
        $lng = trim((string)($data['longitude'] ?? ''));
        if ($lat === '' || $lng === '') json_response(['error' => 'Latitude and longitude required'], 400);
        $content = ['location' => [
            'degreesLatitude' => (float)$lat,
            'degreesLongitude' => (float)$lng,
            'name' => trim((string)($data['name'] ?? '')),
            'address' => trim((string)($data['address'] ?? '')),
        ]];
    } elseif ($type === 'contact') {
        $contact_name = trim((string)($data['name'] ?? ''));
        $contact_phone = preg_replace('/[^0-9]/', '', (string)($data['phone'] ?? ''));
        if ($contact_name === '' || $contact_phone === '') json_response(['error' => 'Contact name and phone required'], 400);
        if (strlen($contact_phone) === 10) $contact_phone = '91' . $contact_phone;
        $content = ['contact' => ['name' => $contact_name, 'phone' => $contact_phone]];
    } elseif ($type === 'buttons') {
        if ($text === '') json_response(['error' => 'Button body text required'], 400);
        $buttons = [];
        foreach (array_slice(array_filter(array_map('trim', explode("\n", (string)($data['buttons'] ?? '')))), 0, 3) as $i => $title) {
            $buttons[] = ['type' => 'reply', 'reply' => ['id' => 'btn' . ($i + 1), 'title' => $title]];
        }
        if (empty($buttons)) json_response(['error' => 'Add at least one button title'], 400);
        $content = ['interactive' => [
            'type' => 'button',
            'body' => ['text' => $text],
            'footer' => ['text' => trim((string)($data['footer'] ?? 'HireAI'))],
            'action' => ['buttons' => $buttons],
        ]];
    } elseif ($type === 'list') {
        if ($text === '') json_response(['error' => 'List body text required'], 400);
        $rows = [];
        foreach (array_slice(array_filter(array_map('trim', explode("\n", (string)($data['rows'] ?? '')))), 0, 10) as $i => $line) {
            [$title, $desc] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            $rows[] = ['id' => 'row' . ($i + 1), 'title' => $title, 'description' => $desc];
        }
        if (empty($rows)) json_response(['error' => 'Add at least one list row'], 400);
        $content = ['interactive' => [
            'type' => 'list',
            'header' => ['type' => 'text', 'text' => trim((string)($data['header'] ?? 'Menu'))],
            'body' => ['text' => $text],
            'footer' => ['text' => trim((string)($data['footer'] ?? 'HireAI'))],
            'action' => ['button' => trim((string)($data['button'] ?? 'Open List')), 'sections' => [['title' => trim((string)($data['section'] ?? 'Options')), 'rows' => $rows]]],
        ]];
    } elseif ($type === 'template') {
        $template = trim((string)($data['template'] ?? ''));
        if ($template === '') json_response(['error' => 'Template name required'], 400);
        $content = ['template' => ['name' => $template, 'language' => ['code' => trim((string)($data['language'] ?? 'en'))], 'components' => []]];
    } elseif ($type === 'album') {
        $items = [];
        foreach (array_filter(array_map('trim', explode("\n", (string)($data['album'] ?? '')))) as $line) {
            [$media_type, $media_url] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if (in_array($media_type, ['image', 'video'], true) && $media_url !== '') $items[] = ['type' => $media_type, 'url' => $media_url];
        }
        if (empty($items)) json_response(['error' => 'Album needs lines like image|https://...'], 400);
        $bulk_payload = true;
        $endpoint = wa_bulk_endpoint();
        $content = ['album' => $items];
        if ($caption !== '') $content['caption'] = $caption;
    } elseif ($type === 'sequence') {
        $messages = [];
        foreach (array_filter(array_map('trim', explode("\n", (string)($data['sequence'] ?? '')))) as $line) {
            $messages[] = ['type' => 'text', 'text' => $line];
        }
        if (empty($messages)) json_response(['error' => 'Sequence needs one text message per line'], 400);
        $bulk_payload = true;
        $endpoint = wa_bulk_endpoint();
        $content = ['data' => $messages, 'delay' => max(0, (int)($data['delay'] ?? 500))];
    } else {
        json_response(['error' => 'Unsupported WhatsApp message type'], 400);
    }

    $sent = $failed = 0;
    foreach ($ids as $id) {
        $c = db_fetch_one("SELECT c.*, camp.name as campaign_name, camp.job_role FROM candidates c JOIN campaigns camp ON c.campaign_id=camp.id WHERE c.id=? AND c.org_id=?", [$id, $user['org_id']], 'ii');
        if (!$c || empty($c['phone'])) { $failed++; continue; }
        $result = send_whatsapp_content($c['phone'], $content, [
            'org_id' => $user['org_id'],
            'candidate_id' => $id,
            'campaign_id' => $c['campaign_id'],
            'reason' => 'custom_' . $type,
            'bulk_payload' => $bulk_payload,
        ], $endpoint);
        $ok = ($result['code'] >= 200 && $result['code'] < 300);
        $ok ? $sent++ : $failed++;
        db_execute("INSERT INTO outreach_log (candidate_id,campaign_id,channel,status) VALUES (?,?,'whatsapp',?)", [$id, $c['campaign_id'], $ok ? 'sent' : 'failed'], 'iis');
        if ($ok) db_execute("UPDATE candidates SET status='outreach_sent' WHERE id=? AND status='pending'", [$id], 'i');
        usleep(400000);
    }
    json_response(['success' => $failed === 0, 'sent' => $sent, 'failed' => $failed]);
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

    $result = send_whatsapp($c['phone'], $msg, [
        'org_id' => $user['org_id'],
        'candidate_id' => $candidate_id,
        'campaign_id' => $c['campaign_id'],
        'reason' => 'manual_interview_invite',
    ]);
    $status = ($result['code'] >= 200 && $result['code'] < 300) ? 'sent' : 'failed';
    $phone_masked = substr($c['phone'], 0, 4) . '****' . substr($c['phone'], -2);
    error_log("[outreach send_single] candidate_id=$candidate_id phone=$phone_masked status=$status code={$result['code']}");
    db_execute("INSERT INTO outreach_log (candidate_id,campaign_id,channel,status) VALUES (?,?,'whatsapp',?)", [$candidate_id, $c['campaign_id'], $status], 'iis');
    if ($status === 'sent') db_execute("UPDATE candidates SET status='outreach_sent' WHERE id=?", [$candidate_id], 'i');
    json_response(['status' => $status, 'message' => "WhatsApp $status to {$c['phone']}", 'provider' => $result]);
}

if ($action === 'trigger_ai_call') {
    $user = verify_jwt();
    if (!$user) json_response(['error' => 'Unauthorized'], 401);
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $candidate_id = (int)($input['candidate_id'] ?? 0);
    if (!$candidate_id) json_response(['error' => 'candidate_id required'], 400);

    $c = db_fetch_one(
        "SELECT c.*, camp.name campaign_name, camp.job_role FROM candidates c
         JOIN campaigns camp ON c.campaign_id=camp.id
         WHERE c.id=? AND c.org_id=?",
        [$candidate_id, $user['org_id']], 'ii'
    );
    if (!$c) json_response(['error' => 'Candidate not found'], 404);

    $api_key   = DIALER_API_KEY;
    $caller_id = $input['caller_id'] ?? DIALER_CALLER_ID;
    if (!$api_key) json_response(['error' => 'Dialer API key not configured. Add DIALER_API_KEY to .env'], 503);

    // Avya adds prefix 0 automatically — send bare 10-digit number
    $phone = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
    if (strlen($phone) === 12 && str_starts_with($phone, '91')) $phone = substr($phone, 2);
    if (strlen($phone) === 13 && str_starts_with($phone, '091')) $phone = substr($phone, 3);

    $payload = [
        'api_key'      => $api_key,
        'phone_number' => $phone,
        'name'         => $c['name'],
        'custom_data'  => [
            'job_role'       => $c['job_role'],
            'interview_type' => 'AI Interview',
            'interview_link' => INTERVIEW_URL . '?t=' . $c['unique_token'],
        ],
    ];
    if (DIALER_CAMPAIGN_ID) $payload['campaign_id'] = DIALER_CAMPAIGN_ID;
    if ($caller_id) $payload['caller_id'] = $caller_id;
    if (!empty($input['custom_data']) && is_array($input['custom_data'])) {
        $payload['custom_data'] = array_merge($payload['custom_data'], $input['custom_data']);
    }

    $ch = curl_init(DIALER_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($code >= 200 && $code < 300 && !empty($data['call_id'])) {
        db_execute(
            "INSERT INTO outreach_log (candidate_id,campaign_id,channel,status) VALUES (?,?,'call','sent')",
            [$candidate_id, $c['campaign_id']], 'ii'
        );
        json_response(['success' => true, 'call_id' => $data['call_id'], 'status' => $data['status'] ?? 'ringing']);
    } else {
        $msg = $data['error'] ?? $data['message'] ?? "Dialer returned HTTP $code";
        error_log("[trigger_ai_call] candidate=$candidate_id code=$code resp=" . substr($resp, 0, 300));
        json_response(['success' => false, 'error' => $msg], 502);
    }
}

if ($action === 'bulk_ai_call') {
    $user = verify_jwt();
    if (!$user) json_response(['error' => 'Unauthorized'], 401);
    if (!DIALER_API_KEY) json_response(['error' => 'Dialer API key not configured'], 503);
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids = array_map('intval', $input['candidate_ids'] ?? []);
    if (empty($ids)) json_response(['error' => 'No candidates selected'], 400);

    $triggered = $failed = 0;
    foreach ($ids as $id) {
        $c = db_fetch_one(
            "SELECT c.*, camp.name campaign_name, camp.job_role FROM candidates c
             JOIN campaigns camp ON c.campaign_id=camp.id
             WHERE c.id=? AND c.org_id=?",
            [$id, $user['org_id']], 'ii'
        );
        if (!$c) continue;
        $phone = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
        if (strlen($phone) === 12 && str_starts_with($phone, '91')) $phone = substr($phone, 2);
        if (strlen($phone) === 13 && str_starts_with($phone, '091')) $phone = substr($phone, 3);

        $payload = [
            'api_key'      => DIALER_API_KEY,
            'phone_number' => $phone,
            'name'         => $c['name'],
            'custom_data'  => [
                'job_role'       => $c['job_role'],
                'interview_type' => 'AI Interview',
                'interview_link' => INTERVIEW_URL . '?t=' . $c['unique_token'],
            ],
        ];
        if (DIALER_CALLER_ID) $payload['caller_id'] = DIALER_CALLER_ID;

        $ch = curl_init(DIALER_API_URL);
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $data = json_decode($resp, true);
        if ($code >= 200 && $code < 300 && !empty($data['call_id'])) {
            $triggered++;
            db_execute("INSERT INTO outreach_log (candidate_id,campaign_id,channel,status) VALUES (?,?,'call','sent')", [$id, $c['campaign_id']], 'ii');
        } else { $failed++; }
        usleep(300000); // 0.3s between calls
    }
    json_response(['triggered' => $triggered, 'failed' => $failed]);
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
        $result = send_whatsapp($c['phone'], $msg, [
            'org_id' => $user['org_id'],
            'candidate_id' => $id,
            'campaign_id' => $c['campaign_id'],
            'reason' => 'bulk_interview_invite',
        ]);
        if ($result['code'] >= 200 && $result['code'] < 300) {
            $sent++;
            db_execute("UPDATE candidates SET status='outreach_sent' WHERE id=? AND org_id=?", [$id, $user['org_id']], 'ii');
        } else { $failed++; }
        db_execute("INSERT INTO outreach_log (candidate_id,campaign_id,channel,status) VALUES (?,?,'whatsapp',?)", [$id, $c['campaign_id'], $result['code']<300?'sent':'failed'], 'iis');
        usleep(500000); // 0.5s delay between messages
    }
    json_response(['sent' => $sent, 'failed' => $failed, 'message' => "Sent: $sent | Failed: $failed"]);
}
