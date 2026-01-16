<?php
// /fixmate/api/auth/register.php

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

require_once __DIR__ . '/../../config/db.php';

// -----------------------------
// Helpers
// -----------------------------
function fm_json_error(string $msg, int $http = 200): void {
    http_response_code($http);
    echo json_encode(["status" => "error", "msg" => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function fm_normalize_pk_phone(string $raw): string {
    $s = trim($raw);
    if ($s === '') return '';
    $s = preg_replace('/[^\d\+]/', '', $s);

    if (strpos($s, '+92') === 0) $s = '0' . substr($s, 3);
    if (strpos($s, '92') === 0)  $s = '0' . substr($s, 2);
    if (strpos($s, '3') === 0)   $s = '0' . $s;

    if (strlen($s) > 11) {
        $tail = substr($s, -11);
        if (preg_match('/^03\d{9}$/', $tail)) $s = $tail;
    }
    return $s;
}

// -----------------------------
// Input
// -----------------------------
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$name     = trim((string)($body['name'] ?? ''));
$email    = trim((string)($body['email'] ?? ''));
$phoneRaw = trim((string)($body['phone'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($name === '' || $password === '') {
    fm_json_error("Name and password are required.");
}
if ($email === '' && $phoneRaw === '') {
    fm_json_error("Please provide email or phone.");
}

$phone = '';
if ($phoneRaw !== '') {
    $phone = fm_normalize_pk_phone($phoneRaw);
    if (!preg_match('/^03\d{9}$/', $phone)) {
        fm_json_error("Please enter a valid phone number (03xxxxxxxxx / +92 / 92).");
    }
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fm_json_error("Please enter a valid email.");
}

if (strlen($password) < 6) {
    fm_json_error("Password must be at least 6 characters.");
}

// -----------------------------
// Your table schema
// -----------------------------
$table = 'users';

// Duplicate checks
if ($email !== '') {
    $stmt = $conn->prepare("SELECT id FROM `$table` WHERE email = ? LIMIT 1");
    if (!$stmt) fm_json_error("Server error (prepare).");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->fetch_assoc()) {
        $stmt->close();
        fm_json_error("Email already exists.");
    }
    $stmt->close();
}

if ($phone !== '') {
    $stmt = $conn->prepare("SELECT id FROM `$table` WHERE phone = ? LIMIT 1");
    if (!$stmt) fm_json_error("Server error (prepare).");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->fetch_assoc()) {
        $stmt->close();
        fm_json_error("Phone already exists.");
    }
    $stmt->close();
}

// Create user
$hash = password_hash($password, PASSWORD_BCRYPT);

$role = 'client';
$isActive = 1;

$stmt = $conn->prepare("
    INSERT INTO `$table` (name, phone, email, password_hash, role, is_active, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
");
if (!$stmt) fm_json_error("Server error (prepare).");

$stmt->bind_param("sssssi", $name, $phone, $email, $hash, $role, $isActive);

if (!$stmt->execute()) {
    $stmt->close();
    fm_json_error("Failed to register. Try again.");
}

$userId = (int)$stmt->insert_id;
$stmt->close();

// Login immediately
$_SESSION['user_id'] = $userId;
$_SESSION['name']    = $name;
$_SESSION['role']    = $role;

echo json_encode([
    "status" => "ok",
    "user" => [
        "id" => $userId,
        "name" => $name,
        "role" => $role,
        "email" => $email,
        "phone" => $phone,
    ]
], JSON_UNESCAPED_UNICODE);
exit;
