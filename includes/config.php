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
define('EL_API_KEY', envv('EL_API_KEY', ''));
define('EL_AGENT_ID', envv('EL_AGENT_ID', ''));
define('EL_PHONE_NUMBER_ID', envv('EL_PHONE_NUMBER_ID', ''));
define('WA_API_URL', envv('WA_API_URL', ''));
define('WA_INSTANCE_ID', envv('WA_INSTANCE_ID', ''));
define('WA_TOKEN', envv('WA_TOKEN', ''));
session_start();

ini_set('display_errors', envv('APP_DEBUG', '0') === '1' ? 1 : 0);
error_reporting(E_ALL);
