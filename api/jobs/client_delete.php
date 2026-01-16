<?php
// /fixmate/api/jobs/client_delete.php

// ----------------------------------------------------
// ✅ DEV CORS (allow ALL localhost ports + 127.0.0.1)
// Works for Flutter Web random ports like http://localhost:49494
// ----------------------------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin) {
    $isLocalhost =
        preg_match('#^https?://localhost(:\d+)?$#i', $origin) ||
        preg_match('#^https?://127\.0\.0\.1(:\d+)?$#i', $origin);

    // ✅ Allow LAN IP (optional for dev on phone)
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

// ✅ Preflight request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';

// ----------------------------------------------------
// Auth guard (client only)
// ----------------------------------------------------
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
    echo json_encode(["status" => "error", "msg" => "Unauthorized"]);
    exit;
}

$clientId = (int) $_SESSION['user_id'];

// ----------------------------------------------------
// Read job_id (JSON or POST)
// ----------------------------------------------------
$jobId = 0;

$raw = file_get_contents("php://input");
if ($raw) {
    $data = json_decode($raw, true);
    if (is_array($data) && isset($data['job_id'])) {
        $jobId = (int) $data['job_id'];
    }
}
if ($jobId <= 0 && isset($_POST['job_id'])) {
    $jobId = (int) $_POST['job_id'];
}

if ($jobId <= 0) {
    echo json_encode(["status" => "error", "msg" => "Missing job_id"]);
    exit;
}

// ----------------------------------------------------
// Fetch job + ownership check
// ----------------------------------------------------
$stmt = $conn->prepare("
    SELECT id, client_id, status
    FROM jobs
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $jobId);
$stmt->execute();
$res = $stmt->get_result();
$job = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$job) {
    echo json_encode(["status" => "error", "msg" => "Job not found"]);
    exit;
}

if ((int)$job['client_id'] !== $clientId) {
    echo json_encode(["status" => "error", "msg" => "Forbidden"]);
    exit;
}

$currentStatus = strtolower(trim($job['status'] ?? ''));

// ----------------------------------------------------
// ✅ Deletion rules
// ----------------------------------------------------
// ✅ Already deleted → ok (idempotent)
if ($currentStatus === 'deleted') {
    echo json_encode([
        "status" => "ok",
        "msg" => "Already deleted",
        "job" => [
            "id" => (int)$jobId,
            "status" => "deleted"
        ]
    ]);
    exit;
}

// ❌ Block delete for completed jobs (recommended)
// (You can add more blocked statuses if you want)
$blocked = ['completed'];
if (in_array($currentStatus, $blocked, true)) {
    echo json_encode([
        "status" => "error",
        "msg" => "Completed jobs cannot be deleted."
    ]);
    exit;
}

// ----------------------------------------------------
// Soft delete (status=deleted)
// If you have a deleted_at column, you can add it.
// ----------------------------------------------------
$upd = $conn->prepare("
    UPDATE jobs
    SET status = 'deleted'
    WHERE id = ? AND client_id = ?
    LIMIT 1
");
$upd->bind_param("ii", $jobId, $clientId);
$ok = $upd->execute();
$upd->close();

if (!$ok) {
    echo json_encode(["status" => "error", "msg" => "Failed to delete job"]);
    exit;
}

echo json_encode([
    "status" => "ok",
    "msg" => "Job deleted",
    "job" => [
        "id" => (int)$jobId,
        "status" => "deleted"
    ]
]);
