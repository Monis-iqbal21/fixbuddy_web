<?php
// /fixmate/api/auth/logout.php

require_once __DIR__ . '/../utils/cors.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// Clear session
$_SESSION = [];

// Clear cookie (important for web)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        (bool)$params["secure"],
        (bool)$params["httponly"]
    );
}

session_destroy();

echo json_encode(["status" => "ok"]);
exit;
