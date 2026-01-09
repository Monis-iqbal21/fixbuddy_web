<?php
session_start();

// load app config (for future flexibility)
$app = require '../../config/app.php';

// --------------------
// 1) Unset all session variables
// --------------------
$_SESSION = [];

// --------------------
// 2) Destroy session cookie (PHPSESSID)
// --------------------
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// --------------------
// 3) Destroy session
// --------------------
session_destroy();

// --------------------
// 4) Remove remember-me cookie (if exists)
// --------------------
if (isset($_COOKIE['fm_remember'])) {
    setcookie(
        'fm_remember',
        '',
        time() - 3600,
        '/',
        '',
        false,
        true
    );
}

// --------------------
// 5) Regenerate session ID (extra safety)
// --------------------
session_start();
session_regenerate_id(true);

// --------------------
// 6) Redirect user
// --------------------
header("Location: /fixmate/pages/auth/login.php");
exit;
