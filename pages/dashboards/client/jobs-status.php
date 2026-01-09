<?php
// /fixmate/pages/dashboards/client/your-jobs.php
// Can be included inside client-dashboard.php?page=jobs-status (or similar)

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
// CSRF (for future-proof modals/forms if you switch to POST)
// ---------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_token'];

// ---------------------------
// Detect jobs table columns (handshake compatibility)
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
        // legacy fallbacks (if any)
        'client_marked_done',
    ]);

    $workerDone = fm_bool_from_job($job, [
        'worker_mark_done',
        'worker_done',
        'worker_mark_done_at',
        'worker_done_at',
        // admin-as-worker fallbacks
        'admin_mark_done',
        'admin_done',
        'admin_mark_done_at',
        'admin_done_at',
        // legacy fallbacks (if any)
        'worker_marked_done',
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
    if ($status === 'in_progress')
        return ['In Progress', 'bg-violet-50 text-violet-800 border-violet-200'];
    if ($status === 'waiting_client_confirmation')
        return ['Waiting Confirmation', 'bg-cyan-50 text-cyan-800 border-cyan-200'];

    return ['Live', 'bg-emerald-100 text-emerald-700 border-emerald-200'];
}

/**
 * Handshake badge (secondary)
 */
function fm_handshake_badge(array $job): ?array
{
    $status = strtolower(trim((string) ($job['status'] ?? 'live')));
    [$clientDone, $workerDone] = fm_get_done_flags($job);

    $relevant = ($status === 'completed' || $status === 'waiting_client_confirmation' || $clientDone || $workerDone);
    if (!$relevant)
        return null;

    if ($clientDone && $workerDone)
        return ['Handshake Completed', 'bg-indigo-100 text-indigo-700 border-indigo-200'];
    if ($workerDone && !$clientDone)
        return ['Waiting Your Confirmation', 'bg-cyan-50 text-cyan-800 border-cyan-200'];
    if ($clientDone && !$workerDone)
        return ['You Marked Done', 'bg-sky-50 text-sky-800 border-sky-200'];

    return ['Confirmation Pending', 'bg-slate-100 text-slate-700 border-slate-200'];
}

// ---------------------------
// Filters
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

// Status filter rules (match admin style)
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
// Query: Jobs + Category + Active Assignment + Worker
// (active assignment compatible with NULL/''/0000 date)
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

            $status = strtolower(trim((string) ($job['status'] ?? 'live')));
            $isFinal = in_array($status, ['completed', 'cancelled', 'deleted'], true);

            $isExpired = fm_compute_is_expired($job, $now);
            $isLocked = $isFinal || $isExpired;

            // handshake flags
            [$clientDone, $workerDone] = fm_get_done_flags($job);

            // Buttons
            $canEdit = (!$hasAssigned) && !$isLocked && in_array($status, ['live'], true);
            // Safer delete: allow delete only when unassigned + not locked
            $canDelete = (!$hasAssigned) && !$isLocked && !in_array($status, ['deleted'], true);

            // Client can mark done when worker already marked done OR job is in progress phases
            $canMarkComplete = $hasAssigned
                && !$isLocked
                && !$clientDone
                && in_array($status, ['waiting_client_confirmation', 'in_progress', 'worker_coming'], true);

            // Review only when truly completed
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

            // Links (keep consistent with your routing)
            $viewUrl = "client-dashboard.php?page=job-detail&job_id=" . $jobId;
            $editUrl = "client-dashboard.php?page=post-job&job_id=" . $jobId;

            // Review: open job-detail + modal flag
            $reviewUrl = "client-dashboard.php?page=job-detail&job_id=" . $jobId . "&show_review_modal=1";

            // Existing operation endpoints (as in your current code)
            $deleteUrl = "/fixmate/pages/dashboards/client/client-post-operations/delete-job.php?id=" . $jobId;
            $doneUrl = "/fixmate/pages/dashboards/client/client-post-operations/client_mark_done.php?id=" . $jobId;
            ?>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <!-- Left -->
                    <div class="min-w-0">
                        <div class="flex items-start gap-2">
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-semibold text-slate-900 truncate">
                                    #<?php echo (int) $jobId; ?> — <?php echo fm_h($title); ?>
                                </h3>

                                <?php if ($desc !== ''): ?>
                                    <p class="text-xs sm:text-sm text-slate-600 mt-1">
                                        <?php echo fm_h($desc); ?>
                                    </p>
                                <?php endif; ?>

                                <div
                                    class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] sm:text-xs text-slate-500">
                                    <span class="inline-flex items-center gap-1">
                                        <span class="text-slate-400">Category:</span>
                                        <span class="font-medium text-slate-700"><?php echo fm_h($catDisplay); ?></span>
                                    </span>

                                    <span class="inline-flex items-center gap-1">
                                        <span class="text-slate-400">Location:</span>
                                        <span
                                            class="font-medium text-slate-700"><?php echo fm_h($loc !== '' ? $loc : '—'); ?></span>
                                    </span>

                                    <span class="inline-flex items-center gap-1">
                                        <span class="text-slate-400">Budget:</span>
                                        <span class="font-semibold text-slate-900"><?php echo number_format($budget); ?>
                                            PKR</span>
                                    </span>
                                </div>

                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
                                    <span>Posted: <?php echo fm_h($createdAt); ?></span>
                                    <?php if ($prefDisplay !== ''): ?>
                                        <span>Preferred: <?php echo fm_h($prefDisplay); ?></span>
                                    <?php endif; ?>
                                    <?php if ($expDisplay !== ''): ?>
                                        <span>Deadline: <?php echo fm_h($expDisplay); ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-2 text-[11px] text-slate-500">
                                    Assigned Worker:
                                    <?php if ($hasAssigned): ?>
                                        <span class="font-semibold text-slate-800">
                                            #<?php echo (int) $assignedWorkerId; ?><?php echo $workerName !== '' ? " — " . fm_h($workerName) : ''; ?>
                                        </span>
                                        <?php if ($workerPhone !== ''): ?>
                                            <span class="text-slate-400"> • <?php echo fm_h($workerPhone); ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="font-semibold text-slate-400">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
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

                <?php if ($status === 'waiting_client_confirmation' && !$clientDone): ?>
                    <div class="mt-3 rounded-xl border border-cyan-100 bg-cyan-50 px-3 py-2 text-xs text-cyan-800">
                        The worker marked this job as done. Please confirm completion to finish the handshake.
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
            This action cannot be undone. The job will no longer be visible to workers.
        </p>
        <div class="flex justify-end gap-2 text-xs">
            <button type="button"
                class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                onclick="closeDeleteModal()">
                Cancel
            </button>
            <a id="deleteConfirmBtn" href="#" class="px-3 py-1.5 rounded-lg bg-rose-600 text-white hover:bg-rose-700">
                Delete
            </a>
        </div>
    </div>
</div>

<!-- Complete Modal -->
<div id="completeModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="bg-white rounded-2xl shadow-lg w-full max-w-sm p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-2">Confirm completion?</h3>
        <p class="text-xs text-slate-600 mb-4">
            This will mark the job as completed from your side. If the worker already marked done, the job becomes
            <b>Completed</b>.
        </p>
        <div class="flex justify-end gap-2 text-xs">
            <button type="button"
                class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                onclick="closeCompleteModal()">
                Cancel
            </button>
            <a id="completeConfirmBtn" href="#"
                class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                Confirm
            </a>
        </div>
    </div>
</div>

<script>
    function openDeleteModal(jobId) {
        const modal = document.getElementById('deleteModal');
        const btn = document.getElementById('deleteConfirmBtn');
        btn.href = <?php echo json_encode($deleteUrl ?? ''); ?>.replace(/id=\d+$/, 'id=' + jobId);
        // If you prefer direct build:
        btn.href = "/fixmate/pages/dashboards/client/client-post-operations/delete-job.php?id=" + jobId;

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
        const btn = document.getElementById('completeConfirmBtn');
        btn.href = "/fixmate/pages/dashboards/client/client-post-operations/client_mark_done.php?id=" + jobId;

        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeCompleteModal() {
        const modal = document.getElementById('completeModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
</script>