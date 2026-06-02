<?php
require_once __DIR__ . '/includes/config.php';
session_destroy();
// Clear Remember Me cookie
setcookie('hire_remember', '', time() - 3600, '/', '', true, true);
header('Location: ' . BASE_URL . '/');
exit;
