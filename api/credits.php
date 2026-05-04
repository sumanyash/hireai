<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$user = verify_jwt();
if (!$user) json_response(['error' => 'Unauthorized'], 401);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!$action) $action = $input['action'] ?? 'summary';

function wallet_summary($org_id) {
    $wallet = ensure_credit_wallet($org_id);
    $usage = db_fetch_all(
        "SELECT channel, COUNT(*) sends, COALESCE(SUM(credits_used),0) credits
         FROM credit_usage WHERE org_id=? GROUP BY channel",
        [$org_id], 'i'
    );
    $recent = db_fetch_all(
        "SELECT * FROM credit_transactions WHERE org_id=? ORDER BY created_at DESC LIMIT 10",
        [$org_id], 'i'
    );
    return ['wallet' => $wallet, 'usage' => $usage, 'transactions' => $recent];
}

if ($action === 'summary' && $method === 'GET') {
    json_response(wallet_summary((int)$user['org_id']));
}

if ($action === 'buy' && $method === 'POST') {
    $provider = $input['provider'] ?? 'manual';
    $allowed = ['razorpay','paypal','payoneer','manual'];
    if (!in_array($provider, $allowed, true)) json_response(['error' => 'Invalid provider'], 400);

    $credits = [
        'whatsapp' => max(0, (int)($input['whatsapp_credits'] ?? 0)),
        'sms' => max(0, (int)($input['sms_credits'] ?? 0)),
        'email' => max(0, (int)($input['email_credits'] ?? 0)),
        'rcs' => max(0, (int)($input['rcs_credits'] ?? 0)),
    ];
    if (array_sum($credits) <= 0) json_response(['error' => 'Enter at least one credit quantity'], 400);

    $amount = max(0, (float)($input['amount'] ?? 0));
    $currency = strtoupper(trim($input['currency'] ?? 'INR')) ?: 'INR';
    $payment_id = trim($input['payment_id'] ?? '');

    $transaction_id = add_credits(
        (int)$user['org_id'],
        $credits,
        $provider,
        $amount,
        $currency,
        $user['user_id'] ?? null,
        $payment_id ?: strtoupper($provider) . '-' . date('YmdHis') . '-' . random_int(1000, 9999)
    );

    audit_log($user['org_id'], $user['user_id'] ?? null, 'credits', $transaction_id, 'credits_purchased', [
        'provider' => $provider,
        'amount' => $amount,
        'currency' => $currency,
        'credits' => $credits,
    ]);

    json_response(['success' => true, 'transaction_id' => $transaction_id] + wallet_summary((int)$user['org_id']));
}

if ($action === 'settings' && $method === 'POST') {
    $threshold = max(0, (int)($input['low_balance_threshold'] ?? 100));
    $auto = !empty($input['auto_recharge_enabled']) ? 1 : 0;
    ensure_credit_wallet($user['org_id']);
    db_execute(
        "UPDATE credit_wallets SET low_balance_threshold=?, auto_recharge_enabled=? WHERE org_id=?",
        [$threshold, $auto, $user['org_id']], 'iii'
    );
    audit_log($user['org_id'], $user['user_id'] ?? null, 'credits', null, 'credit_settings_updated', [
        'low_balance_threshold' => $threshold,
        'auto_recharge_enabled' => $auto,
    ]);
    json_response(['success' => true] + wallet_summary((int)$user['org_id']));
}

json_response(['error' => 'Unknown action'], 400);
