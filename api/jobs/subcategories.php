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

$categoryId = (int)($_GET['category_id'] ?? 0);
$data = [];

if ($categoryId > 0) {
  // if your table has is_active, keep it. If not, remove "AND is_active=1"
  $stmt = $conn->prepare("SELECT id, name FROM sub_categories WHERE category_id = ? ORDER BY name ASC");
  if ($stmt) {
    $stmt->bind_param("i", $categoryId);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
  }
}

echo json_encode(["status" => "ok", "data" => $data]);
