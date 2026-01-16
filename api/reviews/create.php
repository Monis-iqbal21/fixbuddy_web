<?php
// /fixmate/api/reviews/create.php

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
// Read input (JSON or POST)
// ----------------------------------------------------
$jobId = 0;
$rating = 0;
$comment = '';

$raw = file_get_contents("php://input");
if ($raw) {
    $data = json_decode($raw, true);
    if (is_array($data)) {
        $jobId = isset($data['job_id']) ? (int) $data['job_id'] : 0;
        $rating = isset($data['rating']) ? (int) $data['rating'] : 0;
        $comment = isset($data['comment']) ? trim((string) $data['comment']) : '';
    }
}
if ($jobId <= 0 && isset($_POST['job_id'])) {
    $jobId = (int) $_POST['job_id'];
    $rating = (int) ($_POST['rating'] ?? 0);
    $comment = trim((string) ($_POST['comment'] ?? ''));
}

if ($jobId <= 0) {
    echo json_encode(["status" => "error", "msg" => "Missing job_id"]);
    exit;
}
if ($rating < 1 || $rating > 5) {
    echo json_encode(["status" => "error", "msg" => "Rating must be between 1 and 5"]);
    exit;
}
if ($comment === '') {
    echo json_encode(["status" => "error", "msg" => "Comment is required"]);
    exit;
}

// ----------------------------------------------------
// Detect columns (jobs + notifications schema-safe)
// ----------------------------------------------------
$JOB_COLS = [];
try {
    $rc = $conn->query("SHOW COLUMNS FROM jobs");
    if ($rc) {
        while ($row = $rc->fetch_assoc()) {
            $JOB_COLS[strtolower((string) $row['Field'])] = true;
        }
    }
} catch (Throwable $e) {
}

$NOTIF_COLS = [];
try {
    $rc2 = $conn->query("SHOW COLUMNS FROM notifications");
    if ($rc2) {
        while ($row = $rc2->fetch_assoc()) {
            $NOTIF_COLS[strtolower((string) $row['Field'])] = true;
        }
    }
} catch (Throwable $e) {
}

$hasNotifUserId = isset($NOTIF_COLS['user_id']);
$hasNotifJobId = isset($NOTIF_COLS['job_id']);
$hasNotifTitle = isset($NOTIF_COLS['title']);
$hasNotifBody = isset($NOTIF_COLS['body']);
$hasNotifLink = isset($NOTIF_COLS['link']);
$hasNotifRead = isset($NOTIF_COLS['is_read']);
$hasNotifCreated = isset($NOTIF_COLS['created_at']);

// ----------------------------------------------------
// Verify job ownership + rules:
// ✅ ONLY when status is completed AND handshake is done
// status enum: live, assigned, inprogress, completed, expire, deleted
// ----------------------------------------------------
$selectCols = "id, client_id, status";
if (isset($JOB_COLS['client_marked_done']))
    $selectCols .= ", client_marked_done";
if (isset($JOB_COLS['worker_marked_done']))
    $selectCols .= ", worker_marked_done";

$stmt = $conn->prepare("SELECT {$selectCols} FROM jobs WHERE id = ? AND client_id = ? LIMIT 1");
$stmt->bind_param("ii", $jobId, $clientId);
$stmt->execute();
$res = $stmt->get_result();
$job = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$job) {
    echo json_encode(["status" => "error", "msg" => "Job not found"]);
    exit;
}

$status = strtolower(trim((string) ($job['status'] ?? '')));
$clientDone = (int) ($job['client_marked_done'] ?? 0);
$workerDone = (int) ($job['worker_marked_done'] ?? 0);

if ($status !== 'completed' || $clientDone !== 1 || $workerDone !== 1) {
    echo json_encode(["status" => "error", "msg" => "You can review only after job is completed and both confirmations are done"]);
    exit;
}

// ----------------------------------------------------
// ✅ Only ONE review per job (NO edit)
// ----------------------------------------------------
$check = $conn->prepare("SELECT id FROM reviews WHERE job_id = ? AND client_id = ? LIMIT 1");
$check->bind_param("ii", $jobId, $clientId);
$check->execute();
$checkRes = $check->get_result();
$existing = $checkRes ? $checkRes->fetch_assoc() : null;
$check->close();

if ($existing && (int) $existing['id'] > 0) {
    echo json_encode(["status" => "error", "msg" => "Review already submitted. Reviews cannot be edited."]);
    exit;
}

// ----------------------------------------------------
// ✅ Get worker_id from job_worker_assignments (latest active)
// ----------------------------------------------------
$workerId = 0;
$stA = $conn->prepare("
    SELECT worker_id
    FROM job_worker_assignments
    WHERE job_id = ?
      AND (ended_at IS NULL OR ended_at = '' OR ended_at = '0000-00-00 00:00:00')
    ORDER BY id DESC
    LIMIT 1
");
if ($stA) {
    $stA->bind_param("i", $jobId);
    $stA->execute();
    $rA = $stA->get_result();
    if ($rA && $rA->num_rows === 1) {
        $workerId = (int) ($rA->fetch_assoc()['worker_id'] ?? 0);
    }
    $stA->close();
}

if ($workerId <= 0) {
    echo json_encode(["status" => "error", "msg" => "No assigned worker found for this job"]);
    exit;
}

// ----------------------------------------------------
// Insert review + notifications in a transaction
// ----------------------------------------------------
$conn->begin_transaction();

try {
    $ins = $conn->prepare("
        INSERT INTO reviews (job_id, worker_id, client_id, rating, comment, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $ins->bind_param("iiiis", $jobId, $workerId, $clientId, $rating, $comment);
    if (!$ins->execute()) {
        throw new Exception("Failed to submit review");
    }
    $reviewId = (int) $conn->insert_id;
    $ins->close();

    // ----------------------------------------------------
    // ✅ Link strategy for both web + app:
    // store tokens like: job:detail:123
    // ----------------------------------------------------
    $notifLink = "job:detail:" . (int) $jobId;

    $insertNotif = function (int $toUserId, int $jobId, string $title, string $body, string $link) use ($conn, $hasNotifUserId, $hasNotifJobId, $hasNotifTitle, $hasNotifBody, $hasNotifLink, $hasNotifRead, $hasNotifCreated) {

        if (!$hasNotifUserId)
            return;

        $cols = [];
        $ph = [];
        $vals = [];
        $types = "";

        $cols[] = "user_id";
        $ph[] = "?";
        $vals[] = $toUserId;
        $types .= "i";
        if ($hasNotifJobId) {
            $cols[] = "job_id";
            $ph[] = "?";
            $vals[] = $jobId;
            $types .= "i";
        }
        if ($hasNotifTitle) {
            $cols[] = "title";
            $ph[] = "?";
            $vals[] = $title;
            $types .= "s";
        }
        if ($hasNotifBody) {
            $cols[] = "body";
            $ph[] = "?";
            $vals[] = $body;
            $types .= "s";
        }
        if ($hasNotifLink) {
            $cols[] = "link";
            $ph[] = "?";
            $vals[] = $link;
            $types .= "s";
        }
        if ($hasNotifRead) {
            $cols[] = "is_read";
            $ph[] = "0";
        }
        if ($hasNotifCreated) {
            $cols[] = "created_at";
            $ph[] = "NOW()";
        }

        $sql = "INSERT INTO notifications (" . implode(",", $cols) . ") VALUES (" . implode(",", $ph) . ")";
        $st = $conn->prepare($sql);
        if (!$st)
            return;

        if (!empty($vals))
            $st->bind_param($types, ...$vals);
        $st->execute();
        $st->close();
    };

    // 1) client row
    $insertNotif(
        $clientId,
        $jobId,
        "Review submitted",
        "Your review has been submitted for job #{$jobId}.",
        $notifLink
    );

    // 2) admin row(s): all users.role = 'admin'
    $adminIds = [];
    $stAdmins = $conn->prepare("SELECT id FROM users WHERE LOWER(role) = 'admin'");
    if ($stAdmins) {
        $stAdmins->execute();
        $rAdmins = $stAdmins->get_result();
        while ($rAdmins && ($row = $rAdmins->fetch_assoc())) {
            $aid = (int) ($row['id'] ?? 0);
            if ($aid > 0)
                $adminIds[] = $aid;
        }
        $stAdmins->close();
    }

    foreach ($adminIds as $aid) {
        $insertNotif(
            $aid,
            $jobId,
            "New review received",
            "Client submitted a review for job #{$jobId}. Rating: {$rating}/5",
            $notifLink
        );
    }

    $conn->commit();

    echo json_encode([
        "status" => "ok",
        "msg" => "Review submitted",
        "review_id" => $reviewId
    ]);
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "msg" => $e->getMessage()]);
    exit;
}
