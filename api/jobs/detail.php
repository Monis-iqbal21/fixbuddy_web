<?php
// /fixmate/api/jobs/detail.php

// ----------------------------------------------------
// DEV CORS – allow ALL localhost + LAN ports
// ----------------------------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    if (
        preg_match('#^https?://localhost(:\d+)?$#i', $origin) ||
        preg_match('#^https?://127\.0\.0\.1(:\d+)?$#i', $origin) ||
        preg_match('#^https?://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#i', $origin) ||
        preg_match('#^https?://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#i', $origin)
    ) {
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
// Small helper: make absolute URL for Flutter
// ----------------------------------------------------
function fm_abs_url(string $path): string
{
    $path = trim($path);
    if ($path === '')
        return '';

    // already absolute
    if (preg_match('~^https?://~i', $path))
        return $path;

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // ensure leading slash
    if ($path[0] !== '/')
        $path = '/' . $path;

    return $scheme . '://' . $host . $path;
}

// ----------------------------------------------------
// Normalize attachment type
// - Prefer explicit file_type, but if it's unclear, detect by extension
// ----------------------------------------------------
function fm_norm_type(string $t, string $url = ''): string
{
    $t = strtolower(trim($t));
    $u = strtolower(trim($url));

    // explicit mappings
    if ($t === 'img' || $t === 'pic' || $t === 'picture')
        return 'image';
    if ($t === 'voice' || $t === 'sound')
        return 'audio';
    if ($t === 'audio')
        return 'audio';
    if ($t === 'video')
        return 'video';
    if ($t === 'image')
        return 'image';

    // if file_type is actually extension
    if (in_array($t, ['mp3', 'm4a', 'wav', 'aac', 'ogg', 'opus', 'webm'], true)) {
        // webm can be audio or video, we'll decide by folder/filename if possible
        if ($t === 'webm') {
            if (strpos($u, '/audio/') !== false || strpos($u, '_voice_') !== false)
                return 'audio';
            if (strpos($u, '/video/') !== false || strpos($u, '_video_') !== false)
                return 'video';
            // default: treat as video container (safe), but your UI still previews anyway
            return 'video';
        }
        return 'audio';
    }

    if (in_array($t, ['mp4', 'mov', 'mkv', 'avi', 'm4v'], true))
        return 'video';
    if (in_array($t, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic'], true))
        return 'image';

    // detect by URL extension if file_type is unknown
    if ($u !== '') {
        $ext = pathinfo(parse_url($u, PHP_URL_PATH) ?? $u, PATHINFO_EXTENSION);
        $ext = strtolower(trim($ext));

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic'], true))
            return 'image';
        if (in_array($ext, ['mp3', 'm4a', 'wav', 'aac', 'ogg', 'opus'], true))
            return 'audio';
        if (in_array($ext, ['mp4', 'mov', 'mkv', 'avi', 'm4v'], true))
            return 'video';
        if ($ext === 'webm') {
            if (strpos($u, '/audio/') !== false || strpos($u, '_voice_') !== false)
                return 'audio';
            if (strpos($u, '/video/') !== false || strpos($u, '_video_') !== false)
                return 'video';
            return 'video';
        }
    }

    // fallback
    return $t !== '' ? $t : 'file';
}

// ----------------------------------------------------
// Auth guard (client only)
// ----------------------------------------------------
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
    echo json_encode(["status" => "error", "msg" => "Unauthorized"]);
    exit;
}
$clientId = (int) $_SESSION['user_id'];

// ----------------------------------------------------
// job_id
// ----------------------------------------------------
$jobId = (int) ($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    echo json_encode(["status" => "error", "msg" => "Missing job_id"]);
    exit;
}

// ----------------------------------------------------
// Fetch job + category names
// ----------------------------------------------------
$stmt = $conn->prepare("
    SELECT 
        j.*,
        c.name  AS category_name,
        sc.name AS sub_category_name
    FROM jobs j
    LEFT JOIN categories c ON c.id = j.category_id
    LEFT JOIN sub_categories sc ON sc.id = j.sub_category_id
    WHERE j.id = ? AND j.client_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $jobId, $clientId);
$stmt->execute();
$res = $stmt->get_result();
$job = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$job) {
    echo json_encode(["status" => "error", "msg" => "Job not found"]);
    exit;
}

// ----------------------------------------------------
// Active assignment + worker
// ----------------------------------------------------
$assignment = null;
$stmtW = $conn->prepare("
    SELECT 
        jwa.*,
        w.full_name,
        w.phone,
        w.email,
        w.profile_image
    FROM job_worker_assignments jwa
    LEFT JOIN workers w ON w.id = jwa.worker_id
    WHERE jwa.job_id = ?
      AND (jwa.ended_at IS NULL OR jwa.ended_at = '' OR jwa.ended_at = '0000-00-00 00:00:00')
    ORDER BY jwa.id DESC
    LIMIT 1
");
if ($stmtW) {
    $stmtW->bind_param("i", $jobId);
    $stmtW->execute();
    $rw = $stmtW->get_result();
    $assignment = $rw ? $rw->fetch_assoc() : null;
    $stmtW->close();
}

$assignedWorkerId = $assignment ? (int) ($assignment['worker_id'] ?? 0) : 0;
$hasAssign = ($assignedWorkerId > 0) ? 1 : 0;

// ----------------------------------------------------
// Expiry + final status
// ----------------------------------------------------
$rawStatus = strtolower(trim((string) ($job['status'] ?? 'live')));
if ($rawStatus === 'inprogress')
    $rawStatus = 'in_progress';

$finalStatus = $rawStatus;
$isFinal = in_array($finalStatus, ['completed', 'cancelled', 'deleted'], true);

$isExpired = false;
$expiresAt = (string) ($job['expires_at'] ?? '');
if ($expiresAt !== '' && $expiresAt !== '0000-00-00 00:00:00') {
    $ts = strtotime($expiresAt);
    if ($ts !== false && $ts < time() && !$isFinal) {
        $isExpired = true;
        $finalStatus = 'expired';
    }
}

$clientDone = (int) ($job['client_marked_done'] ?? 0);
$workerDone = (int) ($job['worker_marked_done'] ?? 0);

// ✅ IMPORTANT: If any side marked done, status shown should be in_progress (unless final/expired)
if (!$isFinal && !$isExpired && ($clientDone === 1 || $workerDone === 1)) {
    $finalStatus = 'in_progress';
}

// ----------------------------------------------------
// Handshake badge (your app style)
// ----------------------------------------------------
$handshake = null;
if (($clientDone === 1 && $workerDone === 1) || $finalStatus === 'completed') {
    $handshake = 'Handshake Completed';
} elseif ($workerDone === 1 && $clientDone === 0 && !$isFinal && !$isExpired) {
    $handshake = 'Waiting Your Confirmation';
} elseif ($clientDone === 1 && $workerDone === 0 && !$isFinal && !$isExpired) {
    $handshake = 'Waiting Worker Confirmation';
}

// ----------------------------------------------------
// ✅ can_confirm_completion (ONLY in_progress + assigned + client not done)
// ----------------------------------------------------
$canConfirm = ($assignedWorkerId > 0)
    && !$isFinal
    && !$isExpired
    && ($clientDone === 0)
    && ($finalStatus === 'in_progress');

// Delete rule (keep your existing)
$canDelete = !in_array($finalStatus, ['completed', 'deleted'], true);

// ----------------------------------------------------
// Review check
// ----------------------------------------------------
$review = null;
$r = $conn->prepare("
    SELECT id, rating, comment, created_at
    FROM reviews
    WHERE job_id = ? AND client_id = ?
    LIMIT 1
");
$r->bind_param("ii", $jobId, $clientId);
$r->execute();
$rr = $r->get_result();
if ($rr)
    $review = $rr->fetch_assoc();
$r->close();

$handshakeComplete = (($clientDone === 1 && $workerDone === 1) || $finalStatus === 'completed');
$canReview = ($handshakeComplete && !$review && ($assignedWorkerId > 0)) ? 1 : 0;

// ----------------------------------------------------
// ✅ Attachments (job_attachments)
// ----------------------------------------------------
$attachments = [];
$att = $conn->prepare("
    SELECT 
        id,
        job_id,
        file_type,
        file_url,
        thumb_url,
        duration_seconds,
        created_at
    FROM job_attachments
    WHERE job_id = ?
    ORDER BY id ASC
");
if ($att) {
    $att->bind_param("i", $jobId);
    $att->execute();
    $ar = $att->get_result();
    while ($row = $ar->fetch_assoc()) {
        $fileUrl = (string) ($row["file_url"] ?? "");
        $thumbUrl = (string) ($row["thumb_url"] ?? "");

        $durRaw = $row["duration_seconds"] ?? null;
        $dur = null;
        if ($durRaw !== null && $durRaw !== '' && is_numeric($durRaw)) {
            $dur = (int) $durRaw;
        }

        $attachments[] = [
            "id" => (int) ($row["id"] ?? 0),
            "job_id" => (int) ($row["job_id"] ?? $jobId),
            "file_type" => fm_norm_type((string) ($row["file_type"] ?? ""), $fileUrl),
            "file_url" => fm_abs_url($fileUrl),
            "thumb_url" => fm_abs_url($thumbUrl),
            "duration_seconds" => $dur,
            "created_at" => (string) ($row["created_at"] ?? ""),
        ];
    }
    $att->close();
}

// ----------------------------------------------------
// Response
// ----------------------------------------------------
echo json_encode([
    "status" => "ok",
    "job" => [
        "id" => (int) $job['id'],
        "title" => (string) ($job['title'] ?? ''),
        "description" => (string) ($job['description'] ?? ''),
        "location_text" => (string) ($job['location_text'] ?? ''),
        "budget" => (int) ($job['budget'] ?? 0),
        "preferred_date" => (string) ($job['preferred_date'] ?? ''),
        "expires_at" => (string) ($job['expires_at'] ?? ''),
        "created_at" => (string) ($job['created_at'] ?? ''),
        "updated_at" => (string) ($job['updated_at'] ?? ''),

        // ✅ forced status
        "status" => $finalStatus,

        "category_name" => (string) ($job['category_name'] ?? ''),
        "sub_category_name" => (string) ($job['sub_category_name'] ?? ''),

        "client_marked_done" => $clientDone,
        "worker_marked_done" => $workerDone,

        "has_active_assignment" => $hasAssign,
        "assigned_worker_id" => $assignedWorkerId,

        "worker_name" => $assignment ? (string) ($assignment['full_name'] ?? '') : '',
        "worker_phone" => $assignment ? (string) ($assignment['phone'] ?? '') : '',
        "worker_email" => $assignment ? (string) ($assignment['email'] ?? '') : '',
        "worker_image" => $assignment ? (string) ($assignment['profile_image'] ?? '') : '',

        "handshake_badge" => $handshake,

        "can_confirm_completion" => $canConfirm ? 1 : 0,
        "can_delete" => $canDelete ? 1 : 0,
        "can_review" => $canReview,
    ],
    "attachments" => $attachments,
    "review" => $review ?: null
]);
