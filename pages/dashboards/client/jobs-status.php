<?php
// /fixmate/pages/dashboards/client/your-jobs.php
// Can be included inside client-dashboard.php?page=jobs-status (or similar)
//
// ✅ Upgrades added:
// - Client actions handled via POST here (Confirm Completion / Delete) with CSRF
// - Admin notifications on: client_mark_done, delete_job (and a ready hook for review_submitted)
// - Handshake logic aligned with your flow: status stays "in_progress" until both done, then "completed"
// - FILTER SYSTEM (GET search/status) kept exactly the same (no changes)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/db.php';

// ---------------------------
// AUTH GUARD (client only)
// ---------------------------
if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'] ?? '')) !== 'client') {
    echo "<p class='text-red-600 p-4'>Access denied.</p>";
    return;
}

$clientId = (int) $_SESSION['user_id'];

// ---------------------------
// CSRF
// ---------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_token'];

// ---------------------------
// Detect jobs + notifications columns (compat)
// ---------------------------
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

// ---------------------------
// Helpers
// ---------------------------
function fm_h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function fm_truncate(string $text, int $maxChars = 120): string
{
    $text = trim($text);
    if ($text === '')
        return '';
    if (mb_strlen($text) <= $maxChars)
        return $text;
    $short = mb_substr($text, 0, $maxChars);
    $lastSpace = mb_strrpos($short, ' ');
    if ($lastSpace !== false)
        $short = mb_substr($short, 0, $lastSpace);
    return rtrim($short, " \t\n\r\0\x0B.,!?") . '…';
}

function fm_compute_is_expired(array $job, DateTime $now): bool
{
    $expiresAt = (string) ($job['expires_at'] ?? '');
    if ($expiresAt === '' || $expiresAt === '0000-00-00 00:00:00')
        return false;
    try {
        $dt = new DateTime($expiresAt);
        return $dt < $now;
    } catch (Throwable $e) {
        return false;
    }
}

function fm_bool_from_job(array $job, array $keys): bool
{
    foreach ($keys as $k) {
        if (!array_key_exists($k, $job))
            continue;
        $v = $job[$k];

        // datetime style
        if (is_string($v) && trim($v) !== '' && $v !== '0000-00-00 00:00:00')
            return true;

        // bool/int style
        if (is_numeric($v) && (int) $v === 1)
            return true;
        if (is_bool($v) && $v === true)
            return true;
    }
    return false;
}

/**
 * Handshake flags:
 * - client = client_mark_done / client_done / *_at
 * - worker = worker_mark_done / worker_done / admin_mark_done / *_at
 */
function fm_get_done_flags(array $job): array
{
    $clientDone = fm_bool_from_job($job, [
        'client_mark_done',
        'client_done',
        'client_mark_done_at',
        'client_done_at',
        'client_marked_done', // legacy
    ]);

    $workerDone = fm_bool_from_job($job, [
        'worker_mark_done',
        'worker_done',
        'worker_mark_done_at',
        'worker_done_at',
        'admin_mark_done',
        'admin_done',
        'admin_mark_done_at',
        'admin_done_at',
        'worker_marked_done', // legacy
    ]);

    return [$clientDone, $workerDone];
}

function fm_badge_html(string $text, string $class): string
{
    return '<span class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium ' . $class . '">
        <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span>' . fm_h($text) . '</span>';
}

/**
 * Primary status badge (jobs.status + expired override)
 * NOTE: supports both "in_progress" and "inprogress"
 */
function fm_status_badge(array $job, DateTime $now): array
{
    $status = strtolower(trim((string) ($job['status'] ?? 'live')));

    if ($status === 'completed')
        return ['Completed', 'bg-indigo-100 text-indigo-700 border-indigo-200'];
    if ($status === 'cancelled')
        return ['Cancelled', 'bg-slate-200 text-slate-700 border-slate-300'];
    if ($status === 'deleted')
        return ['Deleted', 'bg-slate-200 text-slate-700 border-slate-300'];

    if (fm_compute_is_expired($job, $now)) {
        return ['Expired', 'bg-rose-100 text-rose-700 border-rose-200'];
    }

    if ($status === 'assigned')
        return ['Assigned', 'bg-sky-50 text-sky-800 border-sky-200'];
    if ($status === 'worker_coming')
        return ['Worker Coming', 'bg-amber-50 text-amber-800 border-amber-200'];

    if ($status === 'in_progress' || $status === 'inprogress') {
        return ['In Progress', 'bg-violet-50 text-violet-800 border-violet-200'];
    }

    // keep for backward compatibility (if present)
    if ($status === 'waiting_client_confirmation') {
        return ['Waiting Confirmation', 'bg-cyan-50 text-cyan-800 border-cyan-200'];
    }

    return ['Live', 'bg-emerald-100 text-emerald-700 border-emerald-200'];
}

/**
 * Handshake badge (secondary)
 * IMPORTANT: your flow = status stays "in progress" until handshake completes
 */
function fm_handshake_badge(array $job): ?array
{
    [$clientDone, $workerDone] = fm_get_done_flags($job);

    if ($clientDone && $workerDone) {
        return ['Handshake Completed', 'bg-indigo-100 text-indigo-700 border-indigo-200'];
    }
    if ($workerDone && !$clientDone) {
        return ['Waiting Your Confirmation', 'bg-cyan-50 text-cyan-800 border-cyan-200'];
    }
    if ($clientDone && !$workerDone) {
        return ['Waiting Worker Confirmation', 'bg-sky-50 text-sky-800 border-sky-200'];
    }

    return null;
}

function fm_redirect_back_same_filters(): void
{
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $back = "client-dashboard.php?page=jobs-status" . ($qs ? "&" . $qs : "");
    echo "<script>window.location.href=" . json_encode($back) . ";</script>";
    exit;
}

// ---------------------------
// Notifications: send to ALL admins (robust across schemas)
// ---------------------------
$CLIENT_JOB_LINK_BASE = "/fixmate/pages/dashboards/admin/admin-dashboard.php?page=job-detail&job_id=";

$getAdminIds = function () use ($conn): array {
    $ids = [];
    try {
        // Assumes users table has role
        $st = $conn->prepare("SELECT id FROM users WHERE LOWER(role) = 'admin'");
        if ($st) {
            $st->execute();
            $r = $st->get_result();
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    $ids[] = (int) ($row['id'] ?? 0);
                }
            }
            $st->close();
        }
    } catch (Throwable $e) {
    }
    $ids = array_values(array_filter($ids, fn($v) => $v > 0));
    return $ids;
};

$notifyAdmins = function (int $jobId, string $type, string $title, string $body, ?string $link = null) use ($conn, $NOTIF_COLS, $CLIENT_JOB_LINK_BASE, $getAdminIds): void {
    try {
        $adminIds = $getAdminIds();
        if (empty($adminIds))
            return;

        if ($link === null || $link === '')
            $link = $CLIENT_JOB_LINK_BASE . (int) $jobId;

        $hasUserId = isset($NOTIF_COLS['user_id']);
        $hasJobId = isset($NOTIF_COLS['job_id']);
        $hasType = isset($NOTIF_COLS['type']);
        $hasTitle = isset($NOTIF_COLS['title']);
        $hasBody = isset($NOTIF_COLS['body']);
        $hasMsg = isset($NOTIF_COLS['message']);
        $hasLink = isset($NOTIF_COLS['link']);
        $hasRead = isset($NOTIF_COLS['is_read']);

        if (!$hasUserId && !$hasTitle && !$hasBody && !$hasMsg)
            return;

        foreach ($adminIds as $aid) {
            $cols = [];
            $ph = [];
            $vals = [];
            $types = '';

            if ($hasUserId) {
                $cols[] = 'user_id';
                $ph[] = '?';
                $vals[] = $aid;
                $types .= 'i';
            }
            if ($hasJobId) {
                $cols[] = 'job_id';
                $ph[] = '?';
                $vals[] = $jobId;
                $types .= 'i';
            }
            if ($hasType) {
                $cols[] = 'type';
                $ph[] = '?';
                $vals[] = $type;
                $types .= 's';
            }
            if ($hasTitle) {
                $cols[] = 'title';
                $ph[] = '?';
                $vals[] = $title;
                $types .= 's';
            }

            if ($hasBody) {
                $cols[] = 'body';
                $ph[] = '?';
                $vals[] = $body;
                $types .= 's';
            } elseif ($hasMsg) {
                $cols[] = 'message';
                $ph[] = '?';
                $vals[] = $body;
                $types .= 's';
            }

            if ($hasLink) {
                $cols[] = 'link';
                $ph[] = '?';
                $vals[] = $link;
                $types .= 's';
            }
            if ($hasRead) {
                $cols[] = 'is_read';
                $ph[] = '0';
            }
            if (isset($NOTIF_COLS['created_at'])) {
                $cols[] = 'created_at';
                $ph[] = 'NOW()';
            }

            if (empty($cols))
                continue;

            $sql = "INSERT INTO notifications (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
            $st = $conn->prepare($sql);
            if (!$st)
                continue;

            if (!empty($vals))
                $st->bind_param($types, ...$vals);
            $st->execute();
            $st->close();
        }
    } catch (Throwable $e) {
    }
};

// ---------------------------
// POST actions (do NOT affect filter system)
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    $jobId = (int) ($_POST['job_id'] ?? 0);

    if (!hash_equals($CSRF, $token)) {
        $_SESSION['flash_jobs_error'] = "Security check failed (CSRF). Please refresh and try again.";
        fm_redirect_back_same_filters();
    }

    if ($jobId <= 0) {
        $_SESSION['flash_jobs_error'] = "Invalid job.";
        fm_redirect_back_same_filters();
    }

    // Load job (must belong to client)
    $jobRow = null;
    $st = $conn->prepare("SELECT * FROM jobs WHERE id = ? AND client_id = ? LIMIT 1");
    if ($st) {
        $st->bind_param("ii", $jobId, $clientId);
        $st->execute();
        $r = $st->get_result();
        if ($r && $r->num_rows === 1)
            $jobRow = $r->fetch_assoc();
        $st->close();
    }
    if (!$jobRow) {
        $_SESSION['flash_jobs_error'] = "Job not found.";
        fm_redirect_back_same_filters();
    }

    $status = strtolower(trim((string) ($jobRow['status'] ?? 'live')));
    $now = new DateTime();
    $isExpired = fm_compute_is_expired($jobRow, $now);
    $isFinal = in_array($status, ['completed', 'cancelled', 'deleted'], true);

    // Active assignment (for safe checks)
    $activeAssign = null;
    $stA = $conn->prepare("
        SELECT id, worker_id
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
        $activeAssign = ($rA && $rA->num_rows === 1) ? $rA->fetch_assoc() : null;
        $stA->close();
    }
    $hasAssigned = ($activeAssign && (int) ($activeAssign['worker_id'] ?? 0) > 0);

    // ---- ACTION: client_mark_done
    if ($action === 'client_mark_done') {
        if ($isFinal) {
            $_SESSION['flash_jobs_error'] = "This job is already finalized.";
            fm_redirect_back_same_filters();
        }
        if ($isExpired) {
            $_SESSION['flash_jobs_error'] = "This job is expired. Contact admin if you need help.";
            fm_redirect_back_same_filters();
        }
        if (!$hasAssigned) {
            $_SESSION['flash_jobs_error'] = "A worker must be assigned before you can confirm completion.";
            fm_redirect_back_same_filters();
        }

        [$clientDone, $workerDone] = fm_get_done_flags($jobRow);
        if ($clientDone) {
            $_SESSION['flash_jobs_success'] = "You already confirmed completion for this job.";
            fm_redirect_back_same_filters();
        }

        $conn->begin_transaction();
        try {
            $set = [];
            $set[] = "updated_at = NOW()";

            // client done fields (support schema variants)
            if (isset($JOB_COLS['client_mark_done']))
                $set[] = "client_mark_done = 1";
            if (isset($JOB_COLS['client_done']))
                $set[] = "client_done = 1";
            if (isset($JOB_COLS['client_mark_done_at']))
                $set[] = "client_mark_done_at = NOW()";
            if (isset($JOB_COLS['client_done_at']))
                $set[] = "client_done_at = NOW()";
            if (isset($JOB_COLS['client_marked_done']))
                $set[] = "client_marked_done = 1";

            // STATUS RULE (your flow):
            // - Do NOT set "live" ever
            // - Keep status as "in_progress" until both confirmed
            $nextStatus = $workerDone ? 'completed' : 'in_progress';
            $set[] = "status = '" . $conn->real_escape_string($nextStatus) . "'";

            $sqlUp = "UPDATE jobs SET " . implode(", ", $set) . " WHERE id = ? AND client_id = ? LIMIT 1";
            $up = $conn->prepare($sqlUp);
            if (!$up)
                throw new Exception("Prepare failed: " . $conn->error);
            $up->bind_param("ii", $jobId, $clientId);
            if (!$up->execute())
                throw new Exception("Failed to confirm completion.");
            $up->close();

            // Notify admins
            if ($nextStatus === 'completed') {
                $notifyAdmins(
                    $jobId,
                    "client_mark_done_completed",
                    "Job completed (handshake done)",
                    "Client confirmed completion for job #{$jobId}. Worker already marked done. Status → Completed.",
                    null
                );
                $_SESSION['flash_jobs_success'] = "You confirmed completion. Job is now Completed.";
            } else {
                $notifyAdmins(
                    $jobId,
                    "client_mark_done",
                    "Client confirmed completion",
                    "Client confirmed completion for job #{$jobId}. Waiting for worker/admin confirmation.",
                    null
                );
                $_SESSION['flash_jobs_success'] = "You confirmed completion. Waiting for worker confirmation.";
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['flash_jobs_error'] = $e->getMessage();
        }

        fm_redirect_back_same_filters();
    }

    // ---- ACTION: delete_job (soft delete)
    elseif ($action === 'delete_job') {
        if ($isFinal) {
            $_SESSION['flash_jobs_error'] = "This job is already finalized and cannot be deleted.";
            fm_redirect_back_same_filters();
        }
        if ($hasAssigned) {
            $_SESSION['flash_jobs_error'] = "You cannot delete a job after a worker is assigned. Contact admin.";
            fm_redirect_back_same_filters();
        }
        if ($isExpired) {
            // still allow soft delete if you want — but safer to allow
        }

        $conn->begin_transaction();
        try {
            $set = ["updated_at = NOW()", "status = 'deleted'"];
            $sqlUp = "UPDATE jobs SET " . implode(", ", $set) . " WHERE id = ? AND client_id = ? LIMIT 1";
            $up = $conn->prepare($sqlUp);
            if (!$up)
                throw new Exception("Prepare failed: " . $conn->error);
            $up->bind_param("ii", $jobId, $clientId);
            if (!$up->execute())
                throw new Exception("Failed to delete job.");
            $up->close();

            $notifyAdmins(
                $jobId,
                "client_deleted_job",
                "Client deleted job",
                "Client deleted job #{$jobId}.",
                null
            );

            $conn->commit();
            $_SESSION['flash_jobs_success'] = "Job deleted successfully.";
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['flash_jobs_error'] = $e->getMessage();
        }

        fm_redirect_back_same_filters();
    }

    // ---- ACTION: review_submitted (HOOK for job-detail later)
    // If later you POST here from job-detail after saving review, it will notify admins.
    elseif ($action === 'review_submitted') {
        // This action does NOT save review (your review saving can remain in job-detail),
        // it only sends admin notification that review was submitted.
        $jobTitle = (string) ($jobRow['title'] ?? '');
        $notifyAdmins(
            $jobId,
            "review_submitted",
            "New review submitted",
            "Client submitted a review for job #{$jobId} — " . ($jobTitle !== '' ? $jobTitle : "Job"),
            null
        );
        $_SESSION['flash_jobs_success'] = "Review notification sent to admin.";
        fm_redirect_back_same_filters();
    }

    // Unknown action
    $_SESSION['flash_jobs_error'] = "Invalid action.";
    fm_redirect_back_same_filters();
}

// ---------------------------
// Flash
// ---------------------------
$flashError = (string) ($_SESSION['flash_jobs_error'] ?? '');
$flashSuccess = (string) ($_SESSION['flash_jobs_success'] ?? '');
unset($_SESSION['flash_jobs_error'], $_SESSION['flash_jobs_success']);

// ---------------------------
// Filters (UNCHANGED)
// ---------------------------
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all'); // all | live | assigned | worker_coming | in_progress | waiting_client_confirmation | completed | expired | cancelled | deleted | unassigned

$where = " WHERE j.client_id = ? ";
$params = [$clientId];
$types = "i";

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
    $jobIdExact = ctype_digit($search) ? (int) $search : 0;

    $params[] = $jobIdExact;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "issssss";
}

// Status filter rules (match admin style) (UNCHANGED)
if ($statusFilter !== 'all') {
    if ($statusFilter === 'assigned') {
        $where .= " AND aa.worker_id IS NOT NULL ";
    } elseif ($statusFilter === 'unassigned') {
        $where .= " AND aa.worker_id IS NULL ";
    } elseif ($statusFilter === 'expired') {
        $where .= " AND (j.expires_at IS NOT NULL AND j.expires_at <> '0000-00-00 00:00:00' AND j.expires_at < NOW())
                    AND (LOWER(j.status) NOT IN ('completed','cancelled','deleted')) ";
    } else {
        $where .= " AND LOWER(j.status) = ? ";
        $params[] = strtolower($statusFilter);
        $types .= "s";
    }
}

// ---------------------------
// Query (UNCHANGED logic)
// ---------------------------
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

    $where
    ORDER BY j.created_at DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "<p class='text-red-600 p-4'>SQL Prepare failed: " . fm_h($conn->error) . "</p>";
    return;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$jobs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$now = new DateTime();
?>

<div class="mx-4 sm:mx-6 lg:mx-8 mb-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Your Job Posts</h2>
            <p class="text-xs text-slate-500 mt-0.5">Track status, confirm completion, and leave reviews.</p>
        </div>

        <form method="GET" action="client-dashboard.php" class="w-full sm:w-auto">
            <input type="hidden" name="page" value="jobs-status" />

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <input type="text" name="search" value="<?php echo fm_h($search); ?>"
                    placeholder="Search (ID, title, category, location)…"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />

                <select name="status"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                    <option value="live" <?php echo $statusFilter === 'live' ? 'selected' : ''; ?>>Live</option>
                    <option value="unassigned" <?php echo $statusFilter === 'unassigned' ? 'selected' : ''; ?>>Unassigned
                    </option>
                    <option value="assigned" <?php echo $statusFilter === 'assigned' ? 'selected' : ''; ?>>Assigned
                    </option>
                    <option value="worker_coming" <?php echo $statusFilter === 'worker_coming' ? 'selected' : ''; ?>>
                        Worker Coming</option>
                    <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In
                        Progress</option>
                    <option value="waiting_client_confirmation" <?php echo $statusFilter === 'waiting_client_confirmation' ? 'selected' : ''; ?>>Waiting Your Confirmation</option>
                    <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed
                    </option>
                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled
                    </option>
                    <option value="deleted" <?php echo $statusFilter === 'deleted' ? 'selected' : ''; ?>>Deleted</option>
                </select>

                <button type="submit"
                    class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                    Apply
                </button>
            </div>
        </form>
    </div>

    <?php if ($flashError): ?>
        <div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            <?php echo fm_h($flashError); ?>
        </div>
    <?php endif; ?>

    <?php if ($flashSuccess): ?>
        <div class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            <?php echo fm_h($flashSuccess); ?>
        </div>
    <?php endif; ?>
</div>

<?php if (empty($jobs)): ?>
    <div
        class="mx-4 sm:mx-6 lg:mx-8 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500">
        You haven’t posted any jobs yet.
        <a href="client-dashboard.php?page=post-job" class="text-indigo-600 font-semibold">Post your first job.</a>
    </div>
<?php else: ?>

    <div class="mx-4 sm:mx-6 lg:mx-8 space-y-3">
        <?php foreach ($jobs as $job): ?>
            <?php
            $jobId = (int) ($job['id'] ?? 0);

            $title = (string) ($job['title'] ?? 'Untitled');
            $desc = fm_truncate((string) ($job['description'] ?? ''), 130);

            $cat = trim((string) ($job['category_name'] ?? ''));
            $sub = trim((string) ($job['sub_category_name'] ?? ''));
            $catDisplay = $cat !== '' ? $cat : '—';
            if ($sub !== '')
                $catDisplay .= " / " . $sub;

            $loc = trim((string) ($job['location_text'] ?? ''));
            $budget = (int) ($job['budget'] ?? 0);

            $hasAssigned = ((int) ($job['has_active_assignment'] ?? 0) === 1);
            $assignedWorkerId = (int) ($job['assigned_worker_id'] ?? 0);

            $workerName = trim((string) ($job['worker_name'] ?? ''));
            $workerPhone = trim((string) ($job['worker_phone'] ?? ''));
            $workerEmail = trim((string) ($job['worker_email'] ?? ''));

            $status = strtolower(trim((string) ($job['status'] ?? 'live')));
            $isFinal = in_array($status, ['completed', 'cancelled', 'deleted'], true);

            $isExpired = fm_compute_is_expired($job, $now);
            $isLocked = $isFinal || $isExpired;

            // handshake flags
            [$clientDone, $workerDone] = fm_get_done_flags($job);

            // Buttons
            $canEdit = (!$hasAssigned) && !$isLocked && in_array($status, ['live'], true);
            $canDelete = (!$hasAssigned) && !$isLocked && !in_array($status, ['deleted'], true);

            // Client can confirm completion when:
            // - worker already marked done OR job in progress phases
            // - and client not already done
            $canMarkComplete = $hasAssigned && !$isLocked && !$clientDone
                && in_array($status, ['waiting_client_confirmation', 'in_progress', 'inprogress', 'worker_coming'], true);

            // Review only when truly completed (handshake completed)
            $canReview = ($status === 'completed') || ($clientDone && $workerDone);

            [$badgeText, $badgeClass] = fm_status_badge($job, $now);
            $handshake = fm_handshake_badge($job);

            $createdAt = !empty($job['created_at']) ? date('M d, Y', strtotime($job['created_at'])) : '—';

            $pref = (string) ($job['preferred_date'] ?? '');
            $prefDisplay = '';
            if ($pref !== '' && $pref !== '0000-00-00 00:00:00' && $pref !== '0000-00-00') {
                $prefDisplay = date('M d, Y', strtotime($pref));
            }

            $exp = (string) ($job['expires_at'] ?? '');
            $expDisplay = '';
            if ($exp !== '' && $exp !== '0000-00-00 00:00:00' && $exp !== '0000-00-00') {
                $expDisplay = date('M d, Y', strtotime($exp));
            }

            $viewUrl = "client-dashboard.php?page=job-detail&job_id=" . $jobId;
            $editUrl = "client-dashboard.php?page=post-job&job_id=" . $jobId;
            $reviewUrl = "client-dashboard.php?page=job-detail&job_id=" . $jobId . "&show_review_modal=1";
            ?>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <!-- Left -->
                    <div class="min-w-0">
                        <h3 class="text-sm sm:text-base font-semibold text-slate-900 truncate">
                            #<?php echo (int) $jobId; ?> — <?php echo fm_h($title); ?>
                        </h3>

                        <?php if ($desc !== ''): ?>
                            <p class="text-xs sm:text-sm text-slate-600 mt-1"><?php echo fm_h($desc); ?></p>
                        <?php endif; ?>

                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] sm:text-xs text-slate-500">
                            <span><span class="text-slate-400">Category:</span> <span
                                    class="font-medium text-slate-700"><?php echo fm_h($catDisplay); ?></span></span>
                            <span><span class="text-slate-400">Location:</span> <span
                                    class="font-medium text-slate-700"><?php echo fm_h($loc !== '' ? $loc : '—'); ?></span></span>
                            <span><span class="text-slate-400">Budget:</span> <span
                                    class="font-semibold text-slate-900"><?php echo number_format($budget); ?> PKR</span></span>
                        </div>

                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
                            <span>Posted: <?php echo fm_h($createdAt); ?></span>
                            <?php if ($prefDisplay !== ''): ?><span>Preferred:
                                    <?php echo fm_h($prefDisplay); ?></span><?php endif; ?>
                            <?php if ($expDisplay !== ''): ?><span>Deadline:
                                    <?php echo fm_h($expDisplay); ?></span><?php endif; ?>
                        </div>

                        <div class="mt-2 text-[11px] text-slate-500">
                            Assigned Worker:
                            <?php if ($hasAssigned): ?>
                                <span class="font-semibold text-slate-800">
                                    #<?php echo (int) $assignedWorkerId; ?><?php echo $workerName !== '' ? " — " . fm_h($workerName) : ''; ?>
                                </span>
                                <?php if ($workerPhone !== ''): ?><span class="text-slate-400"> •
                                        <?php echo fm_h($workerPhone); ?></span><?php endif; ?>
                                <?php if ($workerEmail !== ''): ?><span class="text-slate-400"> •
                                        <?php echo fm_h($workerEmail); ?></span><?php endif; ?>
                            <?php else: ?>
                                <span class="font-semibold text-slate-400">—</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right -->
                    <div class="flex flex-col items-start sm:items-end gap-2">
                        <div class="flex flex-wrap gap-2 sm:justify-end">
                            <?php echo fm_badge_html($badgeText, $badgeClass); ?>
                            <?php if ($handshake): ?>
                                <?php echo fm_badge_html($handshake[0], $handshake[1]); ?>
                            <?php endif; ?>
                        </div>

                        <div class="flex flex-wrap gap-2 sm:justify-end">
                            <a href="<?php echo fm_h($viewUrl); ?>"
                                class="text-xs px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50">
                                View
                            </a>

                            <?php if ($canEdit): ?>
                                <a href="<?php echo fm_h($editUrl); ?>"
                                    class="text-xs px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50">
                                    Edit
                                </a>
                            <?php endif; ?>

                            <?php if ($canMarkComplete): ?>
                                <button type="button"
                                    class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700"
                                    onclick="openCompleteModal(<?php echo $jobId; ?>)">
                                    Confirm Completion
                                </button>
                            <?php endif; ?>

                            <?php if ($canReview): ?>
                                <a href="<?php echo fm_h($reviewUrl); ?>"
                                    class="text-xs px-3 py-1.5 rounded-lg border border-indigo-200 text-indigo-700 hover:bg-indigo-50">
                                    Review
                                </a>
                            <?php endif; ?>

                            <?php if ($canDelete): ?>
                                <button type="button"
                                    class="text-xs px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50"
                                    onclick="openDeleteModal(<?php echo $jobId; ?>)">
                                    Delete
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($workerDone && !$clientDone && ($status === 'in_progress' || $status === 'inprogress' || $status === 'waiting_client_confirmation')): ?>
                    <div class="mt-3 rounded-xl border border-cyan-100 bg-cyan-50 px-3 py-2 text-xs text-cyan-800">
                        The worker marked this job as done. Please confirm completion to finish the handshake.
                    </div>
                <?php elseif ($clientDone && !$workerDone && ($status === 'in_progress' || $status === 'inprogress')): ?>
                    <div class="mt-3 rounded-xl border border-sky-100 bg-sky-50 px-3 py-2 text-xs text-sky-800">
                        You confirmed completion. Waiting for worker/admin confirmation.
                    </div>
                <?php endif; ?>

            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="bg-white rounded-2xl shadow-lg w-full max-w-sm p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-2">Delete job?</h3>
        <p class="text-xs text-slate-600 mb-4">
            This action cannot be undone. The job will be removed from active listings.
        </p>

        <form method="POST" class="flex justify-end gap-2 text-xs">
            <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
            <input type="hidden" name="action" value="delete_job">
            <input type="hidden" name="job_id" id="delete_job_id" value="0">

            <button type="button"
                class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                onclick="closeDeleteModal()">
                Cancel
            </button>
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-rose-600 text-white hover:bg-rose-700">
                Delete
            </button>
        </form>
    </div>
</div>

<!-- Complete Modal -->
<div id="completeModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="bg-white rounded-2xl shadow-lg w-full max-w-sm p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-2">Confirm completion?</h3>
        <p class="text-xs text-slate-600 mb-4">
            This will mark completion from your side.
            If the worker already marked done, the job becomes <b>Completed</b>.
            Otherwise it stays <b>In Progress</b> until worker/admin confirms.
        </p>

        <form method="POST" class="flex justify-end gap-2 text-xs">
            <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
            <input type="hidden" name="action" value="client_mark_done">
            <input type="hidden" name="job_id" id="complete_job_id" value="0">

            <button type="button"
                class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                onclick="closeCompleteModal()">
                Cancel
            </button>
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                Confirm
            </button>
        </form>
    </div>
</div>

<script>
    function openDeleteModal(jobId) {
        const modal = document.getElementById('deleteModal');
        document.getElementById('delete_job_id').value = jobId;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    function closeDeleteModal() {
        const modal = document.getElementById('deleteModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function openCompleteModal(jobId) {
        const modal = document.getElementById('completeModal');
        document.getElementById('complete_job_id').value = jobId;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    function closeCompleteModal() {
        const modal = document.getElementById('completeModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
</script>