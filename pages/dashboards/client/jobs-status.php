<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/db.php';
// require_once __DIR__ . '/../../../includes/auto-expire-jobs.php';

// fm_auto_expire_jobs($conn);
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
    echo "<p class='text-red-600 p-4'>Access denied.</p>";
    return;
}

$clientId = (int) $_SESSION['user_id'];

/* ------------------ CHECK OPTIONAL COLUMNS (MariaDB safe) ------------------ */
function fm_has_column(mysqli $conn, string $table, string $col): bool
{
    // Not user input; still escape to be safe
    $tableEsc = str_replace('`', '``', $table);
    $colEsc = $conn->real_escape_string($col);

    $sql = "SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'";
    $res = $conn->query($sql);
    if (!$res)
        return false;

    return ($res->num_rows > 0);
}

$hasWorkerMarked = fm_has_column($conn, 'jobs', 'worker_marked_done');
$hasClientMarked = fm_has_column($conn, 'jobs', 'client_marked_done');
$handshakeEnabled = ($hasWorkerMarked && $hasClientMarked);

/* ------------------ SEARCH + FILTER INPUT ------------------ */
$search = trim($_GET['search'] ?? '');
$filter = trim($_GET['status'] ?? 'all');

/* -------------------- BUILD WHERE CLAUSE ------------------- */
$where = " WHERE j.client_id = ? ";
$params = [$clientId];
$types = 'i';

if ($search !== '') {
    $where .= " AND (
        j.title LIKE ?
        OR c.name LIKE ?
        OR sc.name LIKE ?
        OR j.location_text LIKE ?
    ) ";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}

if ($filter !== 'all') {
    if ($filter === 'assigned') {
        $where .= " AND active_assign.assign_count > 0 ";
    } elseif ($filter === 'open') {
        $where .= " AND j.status IN ('open','live') ";
    } else {
        $where .= " AND j.status = ? ";
        $params[] = $filter;
        $types .= 's';
    }
}

/* ------------------------ FETCH JOBS ------------------------ */
$handshakeSelect = "";
if ($handshakeEnabled) {
    $handshakeSelect = ",
        j.worker_marked_done,
        j.client_marked_done
    ";
}

$sql = "
SELECT
    j.id,
    j.client_id,
    j.title,
    j.description,
    j.category_id,
    j.sub_category_id,
    j.location_text,
    j.budget,
    j.preferred_date,
    j.expires_at,
    j.status,
    j.is_attachments,
    j.created_at,
    j.updated_at,

    c.name  AS category_name,
    sc.name AS sub_category_name,

    COALESCE(active_assign.assign_count, 0) AS assign_count,
    active_assign.worker_id AS active_worker_id
    $handshakeSelect

FROM jobs j
LEFT JOIN categories c ON c.id = j.category_id
LEFT JOIN sub_categories sc ON sc.id = j.sub_category_id

LEFT JOIN (
    SELECT
        job_id,
        COUNT(*) AS assign_count,
        MAX(worker_id) AS worker_id
    FROM job_worker_assignments
    WHERE ended_at IS NULL
    GROUP BY job_id
) active_assign ON active_assign.job_id = j.id

$where
ORDER BY j.created_at DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "<p class='text-red-600 p-4'>SQL Prepare failed: " . htmlspecialchars($conn->error) . "</p>";
    return;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$jobs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$now = new DateTime();

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
?>

<div class="mb-4 mx-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <h2 class="text-lg font-semibold text-slate-900">Your Job Posts</h2>

    <form method="GET" action="client-dashboard.php" class="flex flex-col sm:flex-row gap-2 sm:items-center">
        <input type="hidden" name="page" value="jobs-status" />

        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
            placeholder="Search by title, category, location…"
            class="w-full sm:w-64 rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />

        <select name="status"
            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All statuses</option>
            <option value="assigned" <?php echo $filter === 'assigned' ? 'selected' : ''; ?>>Assigned jobs only</option>
            <option value="open" <?php echo $filter === 'open' ? 'selected' : ''; ?>>Live / Open</option>
            <option value="completed" <?php echo $filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="expired" <?php echo $filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
            <option value="cancelled" <?php echo $filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            <option value="deleted" <?php echo $filter === 'deleted' ? 'selected' : ''; ?>>Deleted</option>
        </select>

        <button type="submit"
            class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
            <span class="ph-bold ph-magnifying-glass text-sm mr-1"></span>
            Filter
        </button>
    </form>
</div>

<?php if (empty($jobs)): ?>
    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500 mx-8">
        You haven’t posted any jobs yet.
        <a href="client-dashboard.php?page=post-job" class="text-indigo-600 font-semibold">Post your first job.</a>
    </div>
<?php else: ?>

    <div class="space-y-3">
        <?php foreach ($jobs as $job): ?>
            <?php
            $jobId = (int) ($job['id'] ?? 0);

            $rawStatus = $job['status'] ?? 'open';
            $finalStatus = $rawStatus;

            $hasHiredWorker = ((int) ($job['assign_count'] ?? 0) > 0);

            $workerConfirmed = $handshakeEnabled ? ((int) ($job['worker_marked_done'] ?? 0) === 1) : false;
            $clientConfirmed = $handshakeEnabled ? ((int) ($job['client_marked_done'] ?? 0) === 1) : false;

            // expiry by expires_at
            $expiresAt = $job['expires_at'] ?? null;
            if (!empty($expiresAt) && !in_array($rawStatus, ['completed', 'cancelled', 'deleted'], true)) {
                try {
                    $expDt = new DateTime($expiresAt);
                    if ($expDt < $now)
                        $finalStatus = 'expired';
                } catch (Exception $e) {
                }
            }

            // BADGE LOGIC
            if ($finalStatus === 'deleted') {
                if ($hasHiredWorker) {
                    $badgeText = 'Assigned & Deleted';
                    $badgeClass = 'bg-slate-300 text-slate-800 border-slate-400 font-medium';
                } else {
                    $badgeText = 'Deleted';
                    $badgeClass = 'bg-slate-200 text-slate-700 border-slate-300';
                }
            } elseif ($finalStatus === 'cancelled') {
                $badgeText = 'Cancelled';
                $badgeClass = 'bg-slate-200 text-slate-700 border-slate-300';
            } elseif ($finalStatus === 'completed') {
                $badgeText = 'Completed';
                $badgeClass = 'bg-indigo-100 text-indigo-700 border-indigo-200';
            } elseif ($finalStatus === 'expired') {
                $badgeText = 'Expired';
                $badgeClass = 'bg-rose-100 text-rose-700 border-rose-200';
            } else {
                if (!$hasHiredWorker) {
                    $badgeText = 'Live';
                    $badgeClass = 'bg-emerald-100 text-emerald-700 border-emerald-200';
                } else {
                    if ($handshakeEnabled) {
                        if ($workerConfirmed && $clientConfirmed) {
                            $badgeText = 'Completed';
                            $badgeClass = 'bg-indigo-100 text-indigo-700 border-indigo-200';
                        } elseif ($clientConfirmed && !$workerConfirmed) {
                            $badgeText = 'Waiting for worker confirmation';
                            $badgeClass = 'bg-indigo-50 text-indigo-800 border-indigo-200';
                        } elseif ($workerConfirmed && !$clientConfirmed) {
                            $badgeText = 'Waiting for your confirmation';
                            $badgeClass = 'bg-amber-50 text-amber-800 border-amber-200';
                        } else {
                            $badgeText = 'Assigned';
                            $badgeClass = 'bg-sky-50 text-sky-800 border-sky-200';
                        }
                    } else {
                        $badgeText = 'Assigned';
                        $badgeClass = 'bg-sky-50 text-sky-800 border-sky-200';
                    }
                }
            }

            $descSnippet = !empty($job['description']) ? fm_truncate($job['description'], 120) : '';

            $isLocked = in_array($finalStatus, ['completed', 'cancelled', 'deleted', 'expired'], true);

            $canEdit = (!$hasHiredWorker) && !$isLocked;

            $alreadyFullyCompleted = ($handshakeEnabled && $hasHiredWorker && $workerConfirmed && $clientConfirmed) || ($finalStatus === 'completed');
            $canMarkComplete = $hasHiredWorker && !$isLocked && !$alreadyFullyCompleted;

            $canReview = $hasHiredWorker && $alreadyFullyCompleted;

            $canDelete = !in_array($finalStatus, ['deleted', 'cancelled', 'completed'], true);

            $cat = trim(($job['category_name'] ?? ''));
            $sub = trim(($job['sub_category_name'] ?? ''));
            $catDisplay = $cat !== '' ? $cat : '—';
            if ($sub !== '')
                $catDisplay .= ' / ' . $sub;

            $locationText = $job['location_text'] ?? '';
            $budget = (int) ($job['budget'] ?? 0);

            $preferredDate = $job['preferred_date'] ?? '';
            $preferredDateDisplay = '';
            if (!empty($preferredDate) && $preferredDate !== '0000-00-00 00:00:00' && $preferredDate !== '0000-00-00') {
                $preferredDateDisplay = date('M d, Y', strtotime($preferredDate));
            }

            $expiresAtDisplay = '';
            if (!empty($expiresAt) && $expiresAt !== '0000-00-00 00:00:00' && $expiresAt !== '0000-00-00') {
                $expiresAtDisplay = date('M d, Y', strtotime($expiresAt));
            }
            ?>

            <div
                class="border border-slate-200 mx-8 rounded-xl p-4 flex flex-col sm:flex-row sm:justify-between sm:items-start gap-3 bg-white">

                <div class="max-w-full sm:max-w-[70%]">
                    <h3 class="text-sm font-semibold text-slate-900">
                        <?php echo htmlspecialchars($job['title'] ?? 'Untitled'); ?>
                    </h3>

                    <?php if ($descSnippet !== ''): ?>
                        <p class="text-xs text-slate-600 mt-1"><?php echo htmlspecialchars($descSnippet); ?></p>
                    <?php endif; ?>

                    <p class="text-xs text-slate-500 mt-1">
                        <?php echo htmlspecialchars($catDisplay); ?> •
                        <?php echo htmlspecialchars($locationText !== '' ? $locationText : 'No location'); ?>
                    </p>

                    <p class="text-[11px] text-slate-400 mt-1">
                        Posted: <?php echo !empty($job['created_at']) ? date('M d, Y', strtotime($job['created_at'])) : '—'; ?>
                        <?php if ($preferredDateDisplay !== ''): ?> • Preferred:
                            <?php echo htmlspecialchars($preferredDateDisplay); ?>         <?php endif; ?>
                        <?php if ($expiresAtDisplay !== ''): ?> • Expires:
                            <?php echo htmlspecialchars($expiresAtDisplay); ?>         <?php endif; ?>
                        • Budget: <?php echo number_format($budget); ?>
                    </p>
                </div>

                <div class="flex flex-col items-end gap-2">
                    <span
                        class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium text-right <?php echo $badgeClass; ?>">
                        <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span>
                        <?php echo htmlspecialchars($badgeText); ?>
                    </span>

                    <div class="flex flex-wrap justify-end gap-2">
                        <a href="client-dashboard.php?page=job-detail&job_id=<?php echo $jobId; ?>"
                            class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-slate-50">View</a>

                        <?php if ($canEdit): ?>
                            <a href="client-dashboard.php?page=post-job&job_id=<?php echo $jobId; ?>"
                                class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-slate-50">
                                Edit
                            </a>
                        <?php endif; ?>

                        <?php if ($canMarkComplete): ?>
                            <button type="button" onclick="openCompleteModal(<?php echo $jobId; ?>)"
                                class="text-xs px-3 py-1 rounded-md border border-emerald-200 text-emerald-700 hover:bg-emerald-50">Mark
                                complete</button>
                        <?php endif; ?>

                        <?php if ($canReview): ?>
                            <a href="/fixmate/pages/dashboards/client-dashboard.php?page=job-detail&job_id=<?php echo $jobId; ?>&show_review_modal=1"
                                class="text-xs px-3 py-1 rounded-md border border-indigo-200 text-indigo-700 hover:bg-indigo-50">Review</a>
                        <?php endif; ?>

                        <?php if ($canDelete): ?>
                            <button type="button" onclick="openDeleteModal(<?php echo $jobId; ?>)"
                                class="text-xs px-3 py-1 rounded-md border border-rose-200 text-rose-600 hover:bg-rose-50">Delete</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-lg w-full max-w-sm p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-2">Delete job?</h3>
        <p class="text-xs text-slate-600 mb-4">This action cannot be undone. The job will no longer be visible to
            workers.</p>
        <div class="flex justify-end gap-2 text-xs">
            <button type="button" class="px-3 py-1 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50"
                onclick="closeDeleteModal()">Cancel</button>
            <a id="deleteConfirmBtn" href="#"
                class="px-3 py-1 rounded-md bg-rose-600 text-white hover:bg-rose-700">Delete</a>
        </div>
    </div>
</div>

<div id="completeModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-lg w-full max-w-sm p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-2">Mark job as completed?</h3>
        <p class="text-xs text-slate-600 mb-4">Once completed, this job will be marked as done. You’ll be able to review
            the worker later.</p>
        <div class="flex justify-end gap-2 text-xs">
            <button type="button" class="px-3 py-1 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50"
                onclick="closeCompleteModal()">Cancel</button>
            <a id="completeConfirmBtn" href="#"
                class="px-3 py-1 rounded-md bg-emerald-600 text-white hover:bg-emerald-700">Mark complete</a>
        </div>
    </div>
</div>

<script>
    function openDeleteModal(jobId) {
        const modal = document.getElementById('deleteModal');
        const btn = document.getElementById('deleteConfirmBtn');
        btn.href = '/fixmate/pages/dashboards/client/client-post-operations/delete-job.php?id=' + jobId;
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
        btn.href = '/fixmate/pages/dashboards/client/client-post-operations/client_mark_done.php?id=' + jobId;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    function closeCompleteModal() {
        const modal = document.getElementById('completeModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
</script>