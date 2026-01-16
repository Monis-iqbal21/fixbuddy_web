<?php
// /fixmate/api/auth/me.php
require_once __DIR__ . '/../utils/cors.php';
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    // For same-origin hosting this is fine
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "msg" => "Unauthorized"]);
    exit;
}

echo json_encode([
    "status" => "ok",
    "user" => [
        "id" => (int)($_SESSION['user_id'] ?? 0),
        "name" => (string)($_SESSION['name'] ?? ''),
        "role" => (string)($_SESSION['role'] ?? ''),
    ]
]);
