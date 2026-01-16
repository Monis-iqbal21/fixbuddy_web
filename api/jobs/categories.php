<?php
require_once __DIR__ . '/../utils/cors.php';
require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
  ini_set('session.cookie_samesite', 'Lax');
  ini_set('session.cookie_httponly', '1');
  session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'client') {
  echo json_encode(["status" => "error", "msg" => "Unauthorized"]);
  exit;
}

$rows = [];
$res = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($res) {
  while ($r = $res->fetch_assoc()) $rows[] = $r;
}

echo json_encode(["status" => "ok", "data" => $rows]);
