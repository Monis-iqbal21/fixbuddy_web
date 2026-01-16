<?php
// /fixmate/api/notifications/list.php

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
// Auth guard (client OR admin)
// ----------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "msg" => "Unauthorized"]);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? ''; // not used now, but ok to keep

// ----------------------------------------------------
// Pagination
// ----------------------------------------------------
$limit = isset($_GET['limit']) ? max(1, min(50, (int) $_GET['limit'])) : 20;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

// ----------------------------------------------------
// Fetch notifications
// ----------------------------------------------------
$stmt = $conn->prepare("
    SELECT
        id,
        job_id,
        title,
        body,
        link,
        is_read,
        created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
if (!$stmt) {
    echo json_encode(["status" => "error", "msg" => "SQL prepare failed"]);
    exit;
}

$stmt->bind_param("iii", $userId, $limit, $offset);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = [
        "id" => (int) $row['id'],
        "job_id" => (int) $row['job_id'],
        "title" => (string) $row['title'],
        "body" => (string) $row['body'],
        "link" => (string) $row['link'],   // job:detail:ID
        "is_read" => (int) $row['is_read'],
        "created_at" => (string) $row['created_at'],
    ];
}
$stmt->close();

// ----------------------------------------------------
// Unread count (for badge)
// ----------------------------------------------------
$uc = $conn->prepare("
    SELECT COUNT(*) AS unread
    FROM notifications
    WHERE user_id = ? AND is_read = 0
");
if (!$uc) {
    echo json_encode(["status" => "error", "msg" => "SQL prepare failed"]);
    exit;
}
$uc->bind_param("i", $userId);
$uc->execute();
$unread = (int) (($uc->get_result()->fetch_assoc()['unread'] ?? 0));
$uc->close();

echo json_encode([
    "status" => "ok",
    "unread" => $unread,
    "data" => $rows
]);
exit;
