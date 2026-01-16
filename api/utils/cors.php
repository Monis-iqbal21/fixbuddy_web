<?php
// /fixmate/api/utils/cors.php
// FINAL CORS for Flutter Web + PHP Sessions + Media files

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// ----------------------------------------------------
// Allow Flutter Web (localhost / 127.0.0.1 any port)
// ----------------------------------------------------
if ($origin && preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Vary: Origin");
    header("Access-Control-Allow-Credentials: true");
}

// ----------------------------------------------------
// Common headers (API + media-safe)
// ----------------------------------------------------
header("Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// ⚠️ IMPORTANT
// Do NOT force JSON content-type globally
// Media (images/audio/video) must keep native content-type
// So only set charset safely:
header("Content-Type: text/plain; charset=utf-8");

// ----------------------------------------------------
// Preflight handling
// ----------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit; // 🚨 DO NOT output anything
}
