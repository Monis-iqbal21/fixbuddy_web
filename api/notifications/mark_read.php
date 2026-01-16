<?php
// /fixmate/api/notifications/mark_read.php

// ----------------------------------------------------
// ✅ DEV CORS (allow ALL localhost ports + 127.0.0.1 + LAN)
// ----------------------------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin) {
    $isLocalhost =
        preg_match('#^https?://localhost(:\d+)?$#i', $origin) ||
        preg_match('#^https?://127\.0\.0\.1(:\d+)?$#i', $origin);

    $isLan =
        preg_match('#^https?://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#i', $origin) ||
        preg_match('#^https?://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#i', $origin);

    if ($isLocalhost || $isLan) {
        header("Access-Control-Allow-Origin: $origin");
        header("Vary: Origin");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With");
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';

// ----------------------------------------------------
// Auth guard
// ----------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "msg" => "Unauthorized"]);
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ----------------------------------------------------
// Read input (JSON)
// ----------------------------------------------------
$data = json_decode(file_get_contents("php://input"), true) ?? [];

$notificationId = isset($data['notification_id'])
    ? (int) $data['notification_id']
    : 0;

$markAll = isset($data['mark_all']) && $data['mark_all'] === true;

// ----------------------------------------------------
// Mark logic
// ----------------------------------------------------
if ($markAll) {
    $stmt = $conn->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = ?
    ");
    if (!$stmt) {
        echo json_encode(["status" => "error", "msg" => "SQL prepare failed"]);
        exit;
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();

    echo json_encode(["status" => "ok"]);
    exit;
}

if ($notificationId <= 0) {
    echo json_encode(["status" => "error", "msg" => "Invalid notification_id"]);
    exit;
}

$stmt = $conn->prepare("
    UPDATE notifications
    SET is_read = 1
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
if (!$stmt) {
    echo json_encode(["status" => "error", "msg" => "SQL prepare failed"]);
    exit;
}

$stmt->bind_param("ii", $notificationId, $userId);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    $stmt->close();
    echo json_encode(["status" => "error", "msg" => "Notification not found"]);
    exit;
}

$stmt->close();

echo json_encode(["status" => "ok"]);
exit;
