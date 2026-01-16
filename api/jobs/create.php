<?php
// /fixmate/api/jobs/create.php

// -------------------------------------------------------
// HARD RULE: NEVER output HTML warnings (Flutter needs JSON)
// -------------------------------------------------------
ini_set('display_errors', '0');
error_reporting(E_ALL);

// -------------------------------------------------------
// CORS (MUST be before session/auth/DB)
// -------------------------------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowOrigin = '';
if ($origin && preg_match('#^http://localhost:\d+$#', $origin)) {
  $allowOrigin = $origin; // allow localhost:anyport (flutter web/dev)
}

// If you also use fixed origins later, add them here:
$allowed = [
  // 'https://yourdomain.com',
  // 'https://app.yourdomain.com',
];

if ($origin && in_array($origin, $allowed, true)) {
  $allowOrigin = $origin;
}

if ($allowOrigin !== '') {
  header("Access-Control-Allow-Origin: $allowOrigin");
  header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Vary: Origin");

// Preflight request must return 200 with headers (NO AUTH CHECK)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(200);
  exit;
}

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/../../config/db.php';

// -----------------------------------
// Helpers
// -----------------------------------
function json_out($arr, $code = 200)
{
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_SLASHES);
  exit;
}

function get_bearer_token(): string
{
  $h = function_exists('getallheaders') ? getallheaders() : [];
  $auth = $h['Authorization'] ?? $h['authorization'] ?? '';
  if (!$auth) return '';
  if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
  return '';
}

function require_client(mysqli $conn): int
{
  // prefer session
  if (!empty($_SESSION['user_id']) && strtolower($_SESSION['role'] ?? '') === 'client') {
    return (int)$_SESSION['user_id'];
  }

  // fallback: Bearer token
  $token = get_bearer_token();
  if ($token === '') json_out(["status" => "error", "msg" => "Unauthorized"], 401);

  $stmt = $conn->prepare("SELECT id, role FROM users WHERE token = ? LIMIT 1");
  if (!$stmt) json_out(["status" => "error", "msg" => "DB error: " . $conn->error], 500);

  $stmt->bind_param("s", $token);
  $stmt->execute();
  $res = $stmt->get_result();
  $u = $res ? $res->fetch_assoc() : null;
  $stmt->close();

  if (!$u || strtolower($u['role'] ?? '') !== 'client') {
    json_out(["status" => "error", "msg" => "Unauthorized"], 401);
  }

  $_SESSION['user_id'] = (int)$u['id'];
  $_SESSION['role']    = 'client';

  return (int)$u['id'];
}

function detect_type_from_mime(string $mime): string
{
  if (strpos($mime, 'image/') === 0) return 'image';
  if (strpos($mime, 'video/') === 0) return 'video';
  return '';
}

function safe_ext(string $name, string $fallback): string
{
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
  return $ext !== '' ? $ext : $fallback;
}

function ensure_dir(string $dir)
{
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

// -----------------------------------
// Auth
// -----------------------------------
$clientId = require_client($conn);

// -----------------------------------
// Read/validate fields (same rules as web)
// -----------------------------------
$MIN_BUDGET_PKR = 500;

$title          = trim($_POST['title'] ?? '');
$description    = trim($_POST['description'] ?? '');
$location_text  = trim($_POST['location_text'] ?? '');
$category_id    = (int)($_POST['category_id'] ?? 0);
$sub_category_id= (int)($_POST['sub_category_id'] ?? 0);
$budget         = (int)($_POST['budget'] ?? 0);
$expires_date   = trim($_POST['expires_date'] ?? '');

// OPTIONAL: preferred_date (your DB requires it)
// If not provided, we set it to NOW()
$preferred_date = trim($_POST['preferred_date'] ?? ''); // expected: YYYY-MM-DD or YYYY-MM-DD HH:MM:SS
if ($preferred_date === '') {
  $preferred_at = date('Y-m-d H:i:s');
} else {
  // if user sends only YYYY-MM-DD, make it noon (safe)
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $preferred_date)) {
    $preferred_at = $preferred_date . ' 12:00:00';
  } else {
    $preferred_at = $preferred_date;
  }
}

$errors = [];
if ($title === '')        $errors[] = "Job title is required.";
if ($description === '')  $errors[] = "Job description is required.";
if ($category_id <= 0)    $errors[] = "Please select a category.";
if ($budget < $MIN_BUDGET_PKR) $errors[] = "Budget must be at least {$MIN_BUDGET_PKR} PKR.";

$today = date('Y-m-d');
if ($expires_date === '' || $expires_date < $today) {
  $errors[] = "Deadline must be today or a future date.";
}

if (!empty($errors)) {
  json_out(["status" => "error", "msg" => implode("\n", $errors)], 422);
}

$expires_at = $expires_date . " 23:59:59";

// -----------------------------------
// Insert job + attachments (transaction)
// -----------------------------------
$conn->begin_transaction();

try {
  $stmt = $conn->prepare("
    INSERT INTO jobs
      (client_id, title, description, budget, category_id, sub_category_id, location_text, preferred_date, expires_at, status, is_attachments, created_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, 'live', 'no', NOW())
  ");
  if (!$stmt) throw new Exception("Prepare failed (jobs insert): " . $conn->error);

  // 9 placeholders => 9 types => 9 vars ✅
  $stmt->bind_param(
    "issiiisss",
    $clientId,
    $title,
    $description,
    $budget,
    $category_id,
    $sub_category_id,
    $location_text,
    $preferred_at,
    $expires_at
  );

  if (!$stmt->execute()) throw new Exception("Insert job failed: " . $stmt->error);

  $jobId = (int)$conn->insert_id;
  $stmt->close();

  if ($jobId <= 0) throw new Exception("Could not retrieve job ID after insert.");

  // -----------------------------------
  // Attachments
  // -----------------------------------
  $hasAttachments = false;

  // A) media[] (images/videos)
  if (!empty($_FILES['media']) && !empty($_FILES['media']['name'][0])) {
    $uploadDir = __DIR__ . '/../../uploads/jobs/';
    ensure_dir($uploadDir);

    foreach ($_FILES['media']['name'] as $i => $originalName) {
      if (($_FILES['media']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

      $tmpName = $_FILES['media']['tmp_name'][$i] ?? '';
      $size    = (int)($_FILES['media']['size'][$i] ?? 0);
      if ($tmpName === '' || $size <= 0) continue;

      $mime = mime_content_type($tmpName) ?: '';
      $type = detect_type_from_mime($mime);
      if ($type === '') continue;

      $ext = safe_ext($originalName, $type === 'image' ? 'jpg' : 'mp4');
      $fileName = "job_{$jobId}_" . uniqid('', true) . "." . $ext;

      $target = $uploadDir . $fileName;
      if (!move_uploaded_file($tmpName, $target)) continue;

      $publicUrl = "/fixmate/uploads/jobs/" . $fileName;

      $stmtA = $conn->prepare("
        INSERT INTO job_attachments (job_id, file_type, file_url, created_at)
        VALUES (?, ?, ?, NOW())
      ");
      if (!$stmtA) throw new Exception("Prepare failed (attachment insert): " . $conn->error);

      $stmtA->bind_param("iss", $jobId, $type, $publicUrl);
      if (!$stmtA->execute()) throw new Exception("Attachment insert failed: " . $stmtA->error);
      $stmtA->close();

      $hasAttachments = true;
    }
  }

  // B) audio_file (mobile upload)
  if (!empty($_FILES['audio_file']) && ($_FILES['audio_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $tmpName = $_FILES['audio_file']['tmp_name'] ?? '';
    $size    = (int)($_FILES['audio_file']['size'] ?? 0);

    if ($tmpName !== '' && $size > 0) {
      $audioDir = __DIR__ . '/../../uploads/jobs/audio/';
      ensure_dir($audioDir);

      $ext = safe_ext($_FILES['audio_file']['name'] ?? 'voice.m4a', 'm4a');
      $fileName = "job_{$jobId}_voice_" . time() . "." . $ext;

      $target = $audioDir . $fileName;
      if (move_uploaded_file($tmpName, $target)) {
        $publicUrl = "/fixmate/uploads/jobs/audio/" . $fileName;

        $stmtA = $conn->prepare("
          INSERT INTO job_attachments (job_id, file_type, file_url, created_at)
          VALUES (?, 'audio', ?, NOW())
        ");
        if (!$stmtA) throw new Exception("Prepare failed (audio insert): " . $conn->error);

        $stmtA->bind_param("is", $jobId, $publicUrl);
        if (!$stmtA->execute()) throw new Exception("Audio insert failed: " . $stmtA->error);
        $stmtA->close();

        $hasAttachments = true;
      }
    }
  }

  // C) audio_data (base64) fallback
  if (!empty($_POST['audio_data'])) {
    $audioDataUrl = (string)$_POST['audio_data'];

    if (strpos($audioDataUrl, 'base64,') !== false) {
      [, $b64] = explode('base64,', $audioDataUrl, 2);
      $bin = base64_decode($b64);

      if ($bin !== false && strlen($bin) > 0) {
        $audioDir = __DIR__ . '/../../uploads/jobs/audio/';
        ensure_dir($audioDir);

        $fileName = "job_{$jobId}_voice_" . time() . ".webm";
        $filePath = $audioDir . $fileName;
        $publicUrl = "/fixmate/uploads/jobs/audio/" . $fileName;

        file_put_contents($filePath, $bin);

        $stmtA = $conn->prepare("
          INSERT INTO job_attachments (job_id, file_type, file_url, created_at)
          VALUES (?, 'audio', ?, NOW())
        ");
        if (!$stmtA) throw new Exception("Prepare failed (audio b64 insert): " . $conn->error);

        $stmtA->bind_param("is", $jobId, $publicUrl);
        if (!$stmtA->execute()) throw new Exception("Audio b64 insert failed: " . $stmtA->error);
        $stmtA->close();

        $hasAttachments = true;
      }
    }
  }

  // Update jobs.is_attachments
  $flag = $hasAttachments ? 'yes' : 'no';

  $stmtU = $conn->prepare("UPDATE jobs SET is_attachments = ? WHERE id = ? LIMIT 1");
  if (!$stmtU) throw new Exception("Prepare failed (jobs update is_attachments): " . $conn->error);

  $stmtU->bind_param("si", $flag, $jobId);
  if (!$stmtU->execute()) throw new Exception("Update is_attachments failed: " . $stmtU->error);
  $stmtU->close();

  $conn->commit();

  json_out([
    "status" => "ok",
    "msg"    => "Job posted",
    "job_id" => $jobId
  ]);
} catch (Throwable $e) {
  $conn->rollback();
  json_out([
    "status" => "error",
    "msg"    => $e->getMessage()
  ], 500);
}
