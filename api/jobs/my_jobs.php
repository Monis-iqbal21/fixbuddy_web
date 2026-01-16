<?php
// /fixmate/api/jobs/my_jobs.php

// ---------------------------------------------
// CORS (must be BEFORE session/auth/DB output)
// ---------------------------------------------
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
    header("Access-Control-Allow-Credentials: true");
    header("Vary: Origin");
    header("Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With, Authorization");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
  }
}

// Preflight must exit before auth
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(200);
  exit;
}

header('Content-Type: application/json; charset=utf-8');

// ---------------------------------------------
// Boot
// ---------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/../../config/db.php';

// ---------------------------------------------
// Helpers
// ---------------------------------------------
function json_out($arr, $code = 200)
{
  http_response_code($code);
  echo json_encode($arr);
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
  // Prefer session
  if (!empty($_SESSION['user_id']) && strtolower($_SESSION['role'] ?? '') === 'client') {
    return (int)$_SESSION['user_id'];
  }

  // Fallback: Bearer token
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

  // Hydrate session
  $_SESSION['user_id'] = (int)$u['id'];
  $_SESSION['role'] = 'client';

  return (int)$u['id'];
}

function is_expired_row(array $job): bool
{
  $expiresAt = (string)($job['expires_at'] ?? '');
  if ($expiresAt === '' || $expiresAt === '0000-00-00 00:00:00') return false;
  $ts = strtotime($expiresAt);
  if ($ts === false) return false;
  return $ts < time();
}

function bool_from_job(array $job, array $keys): bool
{
  foreach ($keys as $k) {
    if (!array_key_exists($k, $job)) continue;
    $v = $job[$k];

    if (is_string($v) && trim($v) !== '' && $v !== '0000-00-00 00:00:00') return true;
    if (is_numeric($v) && (int)$v === 1) return true;
    if (is_bool($v) && $v === true) return true;
  }
  return false;
}

function done_flags(array $job): array
{
  $clientDone = bool_from_job($job, [
    'client_mark_done',
    'client_done',
    'client_mark_done_at',
    'client_done_at',
    'client_marked_done',
  ]);

  $workerDone = bool_from_job($job, [
    'worker_mark_done',
    'worker_done',
    'worker_mark_done_at',
    'worker_done_at',
    'admin_mark_done',
    'admin_done',
    'admin_mark_done_at',
    'admin_done_at',
    'worker_marked_done',
  ]);

  return [$clientDone, $workerDone];
}

// ---------------------------------------------
// Auth
// ---------------------------------------------
$clientId = require_client($conn);

// ---------------------------------------------
// Inputs (filters + pagination)
// ---------------------------------------------
$search = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$categoryId = (int)($_GET['category_id'] ?? 0);

$page = (int)($_GET['page'] ?? 1);
$limit = (int)($_GET['limit'] ?? 10);
if ($page < 1) $page = 1;
if ($limit < 1) $limit = 10;
if ($limit > 50) $limit = 50;

$offset = ($page - 1) * $limit;

// ---------------------------------------------
// Build WHERE
// ---------------------------------------------
$where = " WHERE j.client_id = ? ";
$params = [$clientId];
$types = "i";

if ($categoryId > 0) {
  $where .= " AND j.category_id = ? ";
  $params[] = $categoryId;
  $types .= "i";
}

if ($search !== '') {
  $where .= " AND (
      j.id = ? OR
      j.title LIKE ? OR
      j.description LIKE ? OR
      j.location_text LIKE ? OR
      c.name LIKE ? OR
      sc.name LIKE ?
  ) ";
  $like = '%' . $search . '%';
  $jobIdExact = ctype_digit($search) ? (int)$search : 0;

  $params[] = $jobIdExact;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $types .= "issssss";
}

$sf = strtolower($statusFilter);
if ($sf !== '' && $sf !== 'all') {

  if ($sf === 'assigned') {
    $where .= " AND aa.worker_id IS NOT NULL ";
  } elseif ($sf === 'unassigned') {
    $where .= " AND aa.worker_id IS NULL ";
  } elseif ($sf === 'expired') {
    $where .= " AND (j.expires_at IS NOT NULL AND j.expires_at <> '0000-00-00 00:00:00' AND j.expires_at < NOW())
                AND (LOWER(j.status) NOT IN ('completed','cancelled','deleted')) ";
  } else {
    if ($sf === 'inprogress') $sf = 'in_progress';
    $where .= " AND LOWER(j.status) = ? ";
    $params[] = $sf;
    $types .= "s";
  }
}

// ---------------------------------------------
// Base FROM with "active assignment"
// ---------------------------------------------
$from = "
  FROM jobs j
  LEFT JOIN categories c ON c.id = j.category_id
  LEFT JOIN sub_categories sc ON sc.id = j.sub_category_id

  LEFT JOIN (
    SELECT jwa1.*
    FROM job_worker_assignments jwa1
    INNER JOIN (
      SELECT job_id, MAX(id) AS max_id
      FROM job_worker_assignments
      WHERE (ended_at IS NULL OR ended_at = '' OR ended_at = '0000-00-00 00:00:00')
      GROUP BY job_id
    ) t ON t.max_id = jwa1.id
  ) aa ON aa.job_id = j.id

  LEFT JOIN workers w ON w.id = aa.worker_id
";

// ---------------------------------------------
// Total count
// ---------------------------------------------
$sqlCount = "SELECT COUNT(*) AS total " . $from . $where;
$stmtC = $conn->prepare($sqlCount);
if (!$stmtC) json_out(["status" => "error", "msg" => "SQL prepare failed: " . $conn->error], 500);

$stmtC->bind_param($types, ...$params);
$stmtC->execute();
$resC = $stmtC->get_result();
$total = 0;
if ($resC && $row = $resC->fetch_assoc()) {
  $total = (int)($row['total'] ?? 0);
}
$stmtC->close();

// ---------------------------------------------
// Data query
// ---------------------------------------------
$sql = "
  SELECT
    j.*,
    c.name  AS category_name,
    sc.name AS sub_category_name,

    aa.worker_id AS assigned_worker_id,
    CASE WHEN aa.worker_id IS NULL THEN 0 ELSE 1 END AS has_active_assignment,

    w.full_name AS worker_name,
    w.phone     AS worker_phone,
    w.email     AS worker_email

  " . $from . $where . "
  ORDER BY j.created_at DESC
  LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
if (!$stmt) json_out(["status" => "error", "msg" => "SQL prepare failed: " . $conn->error], 500);

$params2 = $params;
$types2 = $types . "ii";
$params2[] = $limit;
$params2[] = $offset;

$stmt->bind_param($types2, ...$params2);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// ---------------------------------------------
// ✅ Preload review job_ids for this client (FAST)
// ---------------------------------------------
$reviewedJobIds = [];
if (!empty($rows)) {
  $ids = array_map(fn($r) => (int)($r['id'] ?? 0), $rows);
  $ids = array_values(array_filter($ids, fn($v) => $v > 0));

  if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $typesR = str_repeat('i', count($ids) + 1);

    $sqlR = "SELECT job_id FROM reviews WHERE client_id = ? AND job_id IN ($placeholders)";
    $stR = $conn->prepare($sqlR);
    if ($stR) {
      $paramsR = array_merge([$clientId], $ids);
      $stR->bind_param($typesR, ...$paramsR);
      $stR->execute();
      $rr = $stR->get_result();
      if ($rr) {
        while ($row = $rr->fetch_assoc()) {
          $reviewedJobIds[(int)$row['job_id']] = true;
        }
      }
      $stR->close();
    }
  }
}

// ---------------------------------------------
// Build response rows with your SHEET RULES
// ---------------------------------------------
$data = [];

foreach ($rows as $job) {
  $jobId = (int)($job['id'] ?? 0);

  $rawStatus = strtolower(trim((string)($job['status'] ?? 'live')));
  if ($rawStatus === 'inprogress') $rawStatus = 'in_progress';

  $hasAssigned = ((int)($job['has_active_assignment'] ?? 0) === 1);

  $expired = is_expired_row($job);
  $isFinal = in_array($rawStatus, ['completed', 'cancelled', 'deleted'], true);

  // If expired but not final -> treat as expired for UI (Flutter uses is_expired)
  $isExpired = ($expired && !$isFinal);

  // done flags
  [$clientDone, $workerDone] = done_flags($job);

  // review exists?
  $hasReview = isset($reviewedJobIds[$jobId]);

  // -----------------------------
  // CLIENT BUTTONS (your sheet)
  // -----------------------------
  // live => view, edit, delete
  // assigned => view
  // in_progress => view, confirm completion (ONLY in_progress as you said)
  // completed => view, review (only if review not given)
  // after review => view only
  $canEdit = (!$isExpired && !$isFinal && $rawStatus === 'live');
  $canDelete = (!$isExpired && !$isFinal && $rawStatus === 'live');

  // ONLY in_progress can mark done, and must be assigned
  $canConfirmCompletion =
    ($rawStatus === 'in_progress') &&
    $hasAssigned &&
    !$isExpired &&
    !$isFinal &&
    ($clientDone === false);

  // Review only when status completed and review not given yet
  $canReview =
    ($rawStatus === 'completed') &&
    $hasAssigned &&
    !$hasReview;

  // -----------------------------
  // BADGE (your sheet)
  // -----------------------------
  $handshakeBadge = null;
  if ($hasReview) {
    $handshakeBadge = 'reviewed_given_by_client';
  } elseif ($clientDone && $workerDone) {
    $handshakeBadge = 'handshaked_completed';
  } elseif ($clientDone && !$workerDone) {
    $handshakeBadge = 'client_mark_done';
  } else {
    $handshakeBadge = null;
  }

  $data[] = [
    // job core
    "id" => $jobId,
    "client_id" => (int)($job['client_id'] ?? 0),
    "title" => (string)($job['title'] ?? ''),
    "description" => (string)($job['description'] ?? ''),
    "location_text" => (string)($job['location_text'] ?? ''),
    "budget" => (int)($job['budget'] ?? 0),
    "preferred_date" => (string)($job['preferred_date'] ?? ''),
    "expires_at" => (string)($job['expires_at'] ?? ''),
    "created_at" => (string)($job['created_at'] ?? ''),
    "updated_at" => (string)($job['updated_at'] ?? ''),
    "status" => (string)($job['status'] ?? 'live'),

    // category
    "category_id" => (int)($job['category_id'] ?? 0),
    "sub_category_id" => (int)($job['sub_category_id'] ?? 0),
    "category_name" => (string)($job['category_name'] ?? ''),
    "sub_category_name" => (string)($job['sub_category_name'] ?? ''),

    // assignment + worker
    "has_active_assignment" => $hasAssigned ? 1 : 0,
    "assigned_worker_id" => (int)($job['assigned_worker_id'] ?? 0),
    "worker_name" => (string)($job['worker_name'] ?? ''),
    "worker_phone" => (string)($job['worker_phone'] ?? ''),
    "worker_email" => (string)($job['worker_email'] ?? ''),

    // done flags (return BOTH styles so Flutter never breaks)
    "client_done" => $clientDone ? 1 : 0,
    "worker_done" => $workerDone ? 1 : 0,
    "client_marked_done" => $clientDone ? 1 : 0,
    "worker_marked_done" => $workerDone ? 1 : 0,

    // badge
    "handshake_badge" => $handshakeBadge, // null|client_mark_done|handshaked_completed|reviewed_given_by_client
    "has_review" => $hasReview ? 1 : 0,

    // computed
    "is_expired" => $isExpired ? 1 : 0,

    // UI controls (client)
    "can_view" => 1,
    "can_edit" => $canEdit ? 1 : 0,
    "can_delete" => $canDelete ? 1 : 0,
    "can_confirm_completion" => $canConfirmCompletion ? 1 : 0,
    "can_review" => $canReview ? 1 : 0,
  ];
}

$hasMore = ($offset + count($data)) < $total;

json_out([
  "status" => "ok",
  "data" => $data,
  "meta" => [
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "has_more" => $hasMore,
    "filters" => [
      "q" => $search,
      "status" => $statusFilter,
      "category_id" => $categoryId,
    ]
  ]
]);
