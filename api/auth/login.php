<?php
// /fixmate/api/auth/login.php
// Login with email OR phone + password
// Phone normalization: +92xxxxxxxxxx / 92xxxxxxxxxx / 03xxxxxxxxx  -> stored & matched as 03xxxxxxxxx

require_once __DIR__ . '/../utils/cors.php';

if (session_status() === PHP_SESSION_NONE) {
    // Helps cookie work better for localhost dev (Flutter web)
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

// IMPORTANT: prevent HTML warnings from breaking JSON
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php'; // ✅ correct path for your project

// -----------------------------
// Helpers
// -----------------------------
function fm_json_error(string $msg, int $http = 200): void {
    http_response_code($http);
    echo json_encode(["status" => "error", "msg" => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Normalize Pakistani mobile number to 03xxxxxxxxx (11 digits)
 * Accepts: +92XXXXXXXXXX, 92XXXXXXXXXX, 03XXXXXXXXX, 3XXXXXXXXX
 */
function fm_normalize_pk_phone(string $raw): string {
    $s = trim($raw);
    if ($s === '') return '';

    // keep + and digits only, remove spaces/dashes/etc.
    $s = preg_replace('/[^\d\+]/', '', $s);

    // +92XXXXXXXXXX -> 0XXXXXXXXXX
    if (strpos($s, '+92') === 0) {
        $s = '0' . substr($s, 3);
    }

    // 92XXXXXXXXXX -> 0XXXXXXXXXX
    if (strpos($s, '92') === 0) {
        $s = '0' . substr($s, 2);
    }

    // 3XXXXXXXXXX -> 03...
    if (strpos($s, '3') === 0) {
        $s = '0' . $s;
    }

    // If too long, try last 11 digits if it matches 03xxxxxxxxx
    if (strlen($s) > 11) {
        $tail = substr($s, -11);
        if (preg_match('/^03\d{9}$/', $tail)) {
            $s = $tail;
        }
    }

    return $s;
}

// -----------------------------
// Input
// -----------------------------
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$identifier = trim((string)($body['identifier'] ?? $body['email'] ?? ''));
$password   = (string)($body['password'] ?? '');

if ($identifier === '' || $password === '') {
    fm_json_error("Email/phone and password are required.");
}

$isEmail = (strpos($identifier, '@') !== false);

// -----------------------------
// Your actual table/columns (from screenshot)
// -----------------------------
$tableUsers = 'users';

$idCol    = 'id';
$nameCol  = 'name';
$emailCol = 'email';
$phoneCol = 'phone';
$passCol  = 'password_hash';
$roleCol  = 'role';
$activeCol = 'is_active'; // exists in your table

$user = null;

// -----------------------------
// Query
// -----------------------------
if ($isEmail) {

    $stmt = $conn->prepare("
        SELECT `$idCol` AS id, `$nameCol` AS name, `$emailCol` AS email, `$phoneCol` AS phone,
               `$passCol` AS pass, `$roleCol` AS role, `$activeCol` AS is_active
        FROM `$tableUsers`
        WHERE `$emailCol` = ?
        LIMIT 1
    ");
    if (!$stmt) fm_json_error("Server error (prepare).");

    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();

} else {

    $normalizedPhone = fm_normalize_pk_phone($identifier);
    if (!preg_match('/^03\d{9}$/', $normalizedPhone)) {
        fm_json_error("Please enter a valid phone number (03xxxxxxxxx / +92 / 92).");
    }

    // Try exact match first (since you store 03xxxxxxxxx)
    $stmt = $conn->prepare("
        SELECT `$idCol` AS id, `$nameCol` AS name, `$emailCol` AS email, `$phoneCol` AS phone,
               `$passCol` AS pass, `$roleCol` AS role, `$activeCol` AS is_active
        FROM `$tableUsers`
        WHERE `$phoneCol` = ?
        LIMIT 1
    ");
    if (!$stmt) fm_json_error("Server error (prepare).");

    $stmt->bind_param("s", $normalizedPhone);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    // If old data exists in +92/92 format, try matching by last 10 digits
    if (!$user) {
        $last10 = substr($normalizedPhone, -10); // 3xxxxxxxxx
        $like = "%$last10";

        $stmt = $conn->prepare("
            SELECT `$idCol` AS id, `$nameCol` AS name, `$emailCol` AS email, `$phoneCol` AS phone,
                   `$passCol` AS pass, `$roleCol` AS role, `$activeCol` AS is_active
            FROM `$tableUsers`
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(`$phoneCol`, '+', ''), ' ', ''), '-', ''), '(', '') LIKE ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("s", $like);
            $stmt->execute();
            $res = $stmt->get_result();
            $user = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }
    }

    // If found and phone stored differently, normalize + update to 03xxxxxxxxx
    if ($user) {
        $currentPhone = (string)($user['phone'] ?? '');
        $currentNorm  = fm_normalize_pk_phone($currentPhone);

        if ($currentNorm !== $normalizedPhone) {
            $uid = (int)$user['id'];
            $stmt = $conn->prepare("UPDATE `$tableUsers` SET `$phoneCol` = ? WHERE `$idCol` = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("si", $normalizedPhone, $uid);
                $stmt->execute();
                $stmt->close();
                $user['phone'] = $normalizedPhone;
            }
        } else {
            $user['phone'] = $normalizedPhone;
        }
    }
}

if (!$user) {
    fm_json_error("Invalid credentials.");
}

// -----------------------------
// Active check
// -----------------------------
if (isset($user['is_active']) && (int)$user['is_active'] !== 1) {
    fm_json_error("Your account is disabled. Please contact support.");
}

// -----------------------------
// Password verify (bcrypt)
// Your password_hash values in screenshot start with $2y$ ✅
// -----------------------------
$stored = (string)($user['pass'] ?? '');
if ($stored === '' || !password_verify($password, $stored)) {
    fm_json_error("Invalid credentials.");
}

// -----------------------------
// Set session for cookie-based auth (Flutter Web)
// -----------------------------
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['name']    = (string)($user['name'] ?? '');
$_SESSION['role']    = (string)($user['role'] ?? 'client');

// -----------------------------
// Response
// -----------------------------
echo json_encode([
    "status" => "ok",
    "user" => [
        "id"    => (int)$user['id'],
        "name"  => (string)($user['name'] ?? ''),
        "role"  => (string)($user['role'] ?? 'client'),
        "email" => (string)($user['email'] ?? ''),
        "phone" => (string)($user['phone'] ?? ''),
    ]
], JSON_UNESCAPED_UNICODE);

exit;
