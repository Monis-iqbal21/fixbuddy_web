<?php
// /fixmate/api/profile_update.php

// -------------------------------------------------------
// 1. HARD RULE: NEVER output HTML warnings (Flutter needs JSON)
// -------------------------------------------------------
ini_set('display_errors', '0');
error_reporting(E_ALL);

// -------------------------------------------------------
// 2. CORS (COPIED EXACTLY FROM YOUR WORKING create.php)
// -------------------------------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowOrigin = '';
if ($origin && preg_match('#^http://localhost:\d+$#', $origin)) {
    $allowOrigin = $origin; // allow localhost:anyport (flutter web/dev)
}

// Add production domains if needed
$allowed = [
    // 'https://yourdomain.com',
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

// NOTE: Adjust path. If this file is in /api/, config is ../config
require_once __DIR__ . '/../../config/db.php';

// -----------------------------------
// Helpers (Copied/Adapted from create.php)
// -----------------------------------
function json_out($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_SLASHES);
    exit;
}

function ensure_dir(string $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

// -----------------------------------
// Auth Guard
// -----------------------------------
if (empty($_SESSION['user_id'])) {
    json_out(["status" => "error", "msg" => "Unauthorized. Please log in."], 401);
}

$userId = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

// -----------------------------------
// DB Column Detection
// -----------------------------------
$USER_COLS = [];
try {
    $rc = $conn->query("SHOW COLUMNS FROM users");
    while ($row = $rc->fetch_assoc()) {
        $USER_COLS[strtolower($row['Field'])] = true;
    }
} catch (Exception $e) {}

$PASSWORD_COL = isset($USER_COLS['password_hash']) ? 'password_hash' : 'password';
$IMAGE_COL    = isset($USER_COLS['profile_image']) ? 'profile_image' : (isset($USER_COLS['avatar']) ? 'avatar' : 'image');

try {
    // ==========================================
    // ACTION: UPDATE PASSWORD
    // ==========================================
    if ($action === 'update_password') {
        $current = trim($_POST['current_password'] ?? '');
        $newPass = trim($_POST['new_password'] ?? '');
        $confirm = trim($_POST['confirm_password'] ?? '');

        if (!$current || !$newPass || !$confirm) {
            throw new Exception("All password fields are required.");
        }
        if ($newPass !== $confirm) {
            throw new Exception("New passwords do not match.");
        }
        if (strlen($newPass) < 8) {
            throw new Exception("Password must be at least 8 characters.");
        }

        // Fetch current hash
        $stmt = $conn->prepare("SELECT {$PASSWORD_COL} FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res) throw new Exception("User not found.");

        $storedHash = $res[$PASSWORD_COL];

        if (!password_verify($current, $storedHash)) {
            throw new Exception("Incorrect current password.");
        }

        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $update = $conn->prepare("UPDATE users SET {$PASSWORD_COL} = ? WHERE id = ?");
        $update->bind_param("si", $newHash, $userId);
        
        if ($update->execute()) {
            json_out(['status' => 'ok', 'msg' => 'Password updated successfully.']);
        } else {
            throw new Exception("Database error while updating password.");
        }
    }

    // ==========================================
    // ACTION: UPDATE PHOTO
    // ==========================================
    elseif ($action === 'update_photo') {
        if (empty($_FILES['profile_image'])) {
            throw new Exception("No image file received.");
        }

        $file = $_FILES['profile_image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload error code: " . $file['error']);
        }

        // Validate Type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        
        $ext = '';
        if ($mime === 'image/jpeg') $ext = 'jpg';
        elseif ($mime === 'image/png') $ext = 'png';
        elseif ($mime === 'image/webp') $ext = 'webp';
        // Add more if needed

        if (!$ext) {
            throw new Exception("Invalid file type ($mime). Only JPG, PNG, WEBP allowed.");
        }

        // --- PATH LOGIC ---
        // Mirroring create.php logic: use __DIR__ for absolute path
        // Since create.php (in /api/jobs/) uses /../../uploads/
        // profile_update.php (in /api/) should use /../uploads/
        
        $uploadDir = __DIR__ . '/../../uploads/profile_images/';
        ensure_dir($uploadDir);

        // Unique Name: profile_1767816010_8431.jpg
        $uniqueName = 'profile_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        $targetPath = $uploadDir . $uniqueName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            // DB Path
            $dbPath = "/fixmate/uploads/profile_images/" . $uniqueName;

            $update = $conn->prepare("UPDATE users SET {$IMAGE_COL} = ? WHERE id = ?");
            $update->bind_param("si", $dbPath, $userId);
            
            if ($update->execute()) {
                json_out([
                    'status' => 'ok', 
                    'msg' => 'Profile photo updated.',
                    'user' => [
                        'profile_image' => $dbPath
                    ]
                ]);
            } else {
                throw new Exception("Database update failed.");
            }
        } else {
            throw new Exception("Failed to move uploaded file to: $targetPath");
        }
    } 
    else {
        throw new Exception("Invalid action.");
    }

} catch (Exception $e) {
    json_out(['status' => 'error', 'msg' => $e->getMessage()], 400);
} catch (Throwable $e) {
    json_out(['status' => 'error', 'msg' => $e->getMessage()], 500);
}
?>