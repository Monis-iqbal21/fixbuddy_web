<?php
// /fixmate/api/client/dashboard_home.php

// -------------------------------------------------------
// 1. CORS & HEADERS
// -------------------------------------------------------
ini_set('display_errors', '0');
error_reporting(E_ALL);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowOrigin = '';

// Allow localhost with ports (Flutter Web)
if ($origin && preg_match('#^http://localhost:\d+$#', $origin)) {
    $allowOrigin = $origin;
}

// Add your production IP/Domain here if needed
$allowed = [
    // 'http://192.168.1.10',
];
if ($origin && in_array($origin, $allowed, true)) {
    $allowOrigin = $origin;
}

if ($allowOrigin !== '') {
    header("Access-Control-Allow-Origin: $allowOrigin");
    header("Access-Control-Allow-Credentials: true");
} else {
    // Fallback logic
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Vary: Origin");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// -------------------------------------------------------
// 2. SESSION & AUTH CHECK
// -------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "msg" => "Unauthorized"]);
    exit;
}

$clientId = (int) $_SESSION['user_id'];

// ✅ CRITICAL FIX: Release session lock immediately.
// We only need to READ the user_id. We don't need to keep the session file locked 
// while we run database queries. This prevents blocking 'AuthService.me()'.
session_write_close();

require_once __DIR__ . '/../../config/db.php';

try {
    $response = [
        "status" => "ok",
        "stats" => [
            "active" => 0,
            "completed" => 0,
            "in_progress" => 0
        ],
        "categories" => [],
        "active_jobs" => [],
        "notifications" => []
    ];

    // -------------------------------------------------------
    // 3. STATS
    // -------------------------------------------------------
    $sqlStats = "
        SELECT 
            COUNT(CASE WHEN status = 'live' THEN 1 END) as active_count,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
            COUNT(CASE WHEN status IN ('inprogress', 'in progress', 'worker_coming') THEN 1 END) as progress_count
        FROM jobs 
        WHERE client_id = ? AND status != 'deleted'
    ";
    $stmt = $conn->prepare($sqlStats);
    $stmt->bind_param("i", $clientId);
    $stmt->execute();
    $resStats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($resStats) {
        $response['stats']['active'] = (int) $resStats['active_count'];
        $response['stats']['completed'] = (int) $resStats['completed_count'];
        $response['stats']['in_progress'] = (int) $resStats['progress_count'];
    }

    // -------------------------------------------------------
    // 4. CATEGORIES
    // -------------------------------------------------------
    $sqlCats = "SELECT id, name FROM categories ORDER BY id ASC";
    $resCats = $conn->query($sqlCats);
    if ($resCats) {
        while ($row = $resCats->fetch_assoc()) {
            $response['categories'][] = $row;
        }
    }

    // -------------------------------------------------------
    // 5. LIVE JOBS FEED
    // -------------------------------------------------------
    $sqlJobs = "
        SELECT id, title, status, budget, created_at, location_text 
        FROM jobs 
        WHERE client_id = ? AND status = 'live'
        ORDER BY created_at DESC 
        LIMIT 5
    ";
    $stmt = $conn->prepare($sqlJobs);
    $stmt->bind_param("i", $clientId);
    $stmt->execute();
    $resJobs = $stmt->get_result();
    while ($row = $resJobs->fetch_assoc()) {
        $response['active_jobs'][] = $row;
    }
    $stmt->close();

    // -------------------------------------------------------
    // 6. NOTIFICATIONS
    // -------------------------------------------------------
    // Check if table exists to prevent crash
    $checkTable = $conn->query("SHOW TABLES LIKE 'notifications'");
    if ($checkTable && $checkTable->num_rows > 0) {
        $sqlNotif = "SELECT id, title, body, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
        $stmt = $conn->prepare($sqlNotif);
        if ($stmt) {
            $stmt->bind_param("i", $clientId);
            $stmt->execute();
            $resNotif = $stmt->get_result();
            while ($row = $resNotif->fetch_assoc()) {
                $response['notifications'][] = $row;
            }
            $stmt->close();
        }
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "msg" => $e->getMessage()]);
}
?>