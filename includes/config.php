<?php
$env_file = __DIR__ . '/../.env';
if (is_readable($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (getenv($key) === false) putenv($key . '=' . trim($value, "\"'"));
    }
}

function envv($key, $default = '') {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

define('DB_HOST', envv('DB_HOST', 'localhost'));
define('DB_USER', envv('DB_USER', 'root'));
define('DB_PASS', envv('DB_PASS', ''));
define('DB_NAME', envv('DB_NAME', 'hireai'));
define('JWT_SECRET', envv('JWT_SECRET', 'change-this-secret'));
define('BASE_URL', rtrim(envv('BASE_URL', 'http://localhost'), '/'));
define('INTERVIEW_URL', envv('INTERVIEW_URL', BASE_URL . '/interview.php'));
define('OPENAI_API_KEY', envv('OPENAI_API_KEY', ''));
define('GEMINI_API_KEY', envv('GEMINI_API_KEY', envv('GOOGLE_API_KEY', '')));
define('GEMINI_MODEL', envv('GEMINI_MODEL', 'gemini-2.0-flash'));
define('GOOGLE_APPLICATION_CREDENTIALS', envv('GOOGLE_APPLICATION_CREDENTIALS', ''));
define('VERTEX_AI_PROJECT', envv('VERTEX_AI_PROJECT', ''));
define('VERTEX_AI_LOCATION', envv('VERTEX_AI_LOCATION', 'us-central1'));
define('VERTEX_AI_MODEL', envv('VERTEX_AI_MODEL', ''));
define('GROQ_API_KEY', envv('GROQ_API_KEY', ''));
// ElevenLabs disabled — using Avya Dialer instead
define('EL_API_KEY', '');
define('EL_AGENT_ID', '');
define('EL_PHONE_NUMBER_ID', '');
define('WA_API_URL', envv('WA_API_URL', ''));
define('WA_INSTANCE_ID', envv('WA_INSTANCE_ID', ''));
define('WA_TOKEN', envv('WA_TOKEN', ''));
define('DIALER_API_KEY', envv('DIALER_API_KEY', ''));
define('DIALER_CALLER_ID', envv('DIALER_CALLER_ID', ''));
define('DIALER_CAMPAIGN_ID', (int)envv('DIALER_CAMPAIGN_ID', '0'));
define('DIALER_API_URL', envv('DIALER_API_URL', 'https://dialer.clouddialer.in/api/public/call'));
define('CALL_WEBHOOK_SECRET', envv('CALL_WEBHOOK_SECRET', ''));
define('INTERVIEW_WEBHOOK_SECRET', envv('INTERVIEW_WEBHOOK_SECRET', ''));
define('EXPORT_TOKEN_SECRET', envv('EXPORT_TOKEN_SECRET', JWT_SECRET));
define('INTEGRATION_ALLOWED_DOMAINS', envv('INTEGRATION_ALLOWED_DOMAINS', ''));
define('RAZORPAY_KEY_ID', envv('RAZORPAY_KEY_ID', ''));
define('RAZORPAY_KEY_SECRET', envv('RAZORPAY_KEY_SECRET', ''));
define('PAYPAL_CLIENT_ID', envv('PAYPAL_CLIENT_ID', ''));
define('PAYPAL_CLIENT_SECRET', envv('PAYPAL_CLIENT_SECRET', ''));
define('PAYONEER_PROGRAM_ID', envv('PAYONEER_PROGRAM_ID', ''));
define('CREDIT_ENFORCEMENT', envv('CREDIT_ENFORCEMENT', '0') === '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', parse_url(BASE_URL, PHP_URL_SCHEME) === 'https' ? '1' : '0');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
session_start();

ini_set('display_errors', envv('APP_DEBUG', '0') === '1' ? 1 : 0);
error_reporting(E_ALL);
