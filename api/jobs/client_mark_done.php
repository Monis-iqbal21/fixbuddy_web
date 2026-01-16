<?php
// /fixmate/api/jobs/client_mark_done.php

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
// Auth guard (client only)
// ----------------------------------------------------
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
    echo json_encode(["status" => "error", "msg" => "Unauthorized"]);
    exit;
}

$clientId = (int) $_SESSION['user_id'];

// ----------------------------------------------------
// Read input (JSON or form)
// ----------------------------------------------------
$jobId = 0;

$raw = file_get_contents("php://input");
if ($raw !== false && trim($raw) !== '') {
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
    SELECT id, client_id, status, client_marked_done, worker_marked_done
    FROM jobs
    WHERE id = ?
    LIMIT 1
");
if (!$stmt) {
    echo json_encode(["status" => "error", "msg" => "DB prepare failed"]);
    exit;
}

$stmt->bind_param("i", $jobId);
$stmt->execute();
$res = $stmt->get_result();
$job = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$job) {
    echo json_encode(["status" => "error", "msg" => "Job not found"]);
    exit;
}

if ((int) $job['client_id'] !== $clientId) {
    echo json_encode(["status" => "error", "msg" => "Forbidden"]);
    exit;
}

// ----------------------------------------------------
// Normalize values (Strictly 'inprogress')
// ----------------------------------------------------
$status = strtolower(trim((string) ($job['status'] ?? 'live')));

// ✅ FIX: Handle legacy/typo variations just in case, but target 'inprogress'
if ($status === 'in_progress') {
    $status = 'inprogress';
}

$clientDone = (int) ($job['client_marked_done'] ?? 0);
$workerDone = (int) ($job['worker_marked_done'] ?? 0);

// ✅ Idempotent check
if ($clientDone === 1) {
    echo json_encode([
        "status" => "ok",
        "msg" => "Already marked done",
        "job" => [
            "id" => (int) $job['id'],
            "status" => $status,
            "client_marked_done" => 1,
            "worker_marked_done" => $workerDone,
        ]
    ]);
    exit;
}

// ----------------------------------------------------
// ✅ RULE: mark done ONLY when status is inprogress
// ----------------------------------------------------
if ($status !== 'inprogress') {
    echo json_encode([
        "status" => "error",
        "msg" => "Job can be marked done only in In Progress status",
        "current_status" => $status
    ]);
    exit;
}

// ----------------------------------------------------
// Update client_marked_done + status
// - Keep 'inprogress' until both done
// - If both done => 'completed'
// ----------------------------------------------------
$conn->begin_transaction();

try {
    // 1) Mark client done
    $u1 = $conn->prepare("
        UPDATE jobs
        SET client_marked_done = 1
        WHERE id = ? AND client_id = ?
        LIMIT 1
    ");
    if (!$u1) {
        throw new Exception("DB prepare failed (u1)");
    }

    $u1->bind_param("ii", $jobId, $clientId);
    if (!$u1->execute()) {
        throw new Exception("Failed to update client_marked_done");
    }
    $u1->close();

    // 2) Decide new status:
    // ✅ FIX: Use 'inprogress' (no underscore) to match DB enum
    $newStatus = ($workerDone === 1) ? 'completed' : 'inprogress';

    // IMPORTANT: do NOT ever set status to live here.
    $u2 = $conn->prepare("
        UPDATE jobs
        SET status = ?
        WHERE id = ?
        LIMIT 1
    ");
    if (!$u2) {
        throw new Exception("DB prepare failed (u2)");
    }

    $u2->bind_param("si", $newStatus, $jobId);
    if (!$u2->execute()) {
        throw new Exception("Failed to update status");
    }
    $u2->close();

    // 3) Re-fetch final job state (source of truth)
    $st2 = $conn->prepare("
        SELECT id, status, client_marked_done, worker_marked_done
        FROM jobs
        WHERE id = ?
        LIMIT 1
    ");
    if (!$st2) {
        throw new Exception("DB prepare failed (st2)");
    }

    $st2->bind_param("i", $jobId);
    $st2->execute();
    $r2 = $st2->get_result();
    $job2 = $r2 ? $r2->fetch_assoc() : null;
    $st2->close();

    if (!$job2) {
        throw new Exception("Job not found after update");
    }

    $conn->commit();

    echo json_encode([
        "status" => "ok",
        "msg" => "Marked done",
        "job" => [
            "id" => (int) ($job2['id'] ?? $jobId),
            "status" => (string) ($job2['status'] ?? $newStatus),
            "client_marked_done" => (int) ($job2['client_marked_done'] ?? 1),
            "worker_marked_done" => (int) ($job2['worker_marked_done'] ?? $workerDone),
        ]
    ]);
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "msg" => $e->getMessage()]);
    exit;
}
?>