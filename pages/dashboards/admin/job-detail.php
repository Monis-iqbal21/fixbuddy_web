<?php
// /fixmate/pages/dashboards/admin/job-detail.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/db.php';

// ---------------------------
// AUTH GUARD (admin only)
// ---------------------------
if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'] ?? '')) !== 'admin') {
    echo "<p class='text-red-600 p-4'>Access denied.</p>";
    return;
}

$adminId = (int) ($_SESSION['user_id'] ?? 0);
$jobId = (int) ($_GET['job_id'] ?? 0);

if ($jobId <= 0) {
    echo "<p class='text-slate-600 p-4'>Job ID missing.</p>";
    return;
}

// ---------------------------
// CSRF
// ---------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_token'];

// ---------------------------
// Detect Columns
// ---------------------------
$JOB_COLS = [];
try {
    $rc = $conn->query("SHOW COLUMNS FROM jobs");
    while ($row = $rc->fetch_assoc())
        $JOB_COLS[strtolower((string) $row['Field'])] = true;
} catch (Throwable $e) {
}

$NOTIF_COLS = [];
try {
    $rc2 = $conn->query("SHOW COLUMNS FROM notifications");
    while ($row = $rc2->fetch_assoc())
        $NOTIF_COLS[strtolower((string) $row['Field'])] = true;
} catch (Throwable $e) {
}

// ---------------------------
// Helpers
// ---------------------------
function fm_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function fm_format_dt($dt): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00')
        return '—';
    return date('M d, Y h:i A', strtotime($dt));
}

function fm_format_date($dt): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00')
        return '—';
    return date('M d, Y', strtotime($dt));
}

function fm_bool_from_row(array $row, array $keys): bool
{
    foreach ($keys as $k) {
        if (!array_key_exists($k, $row))
            continue;
        $v = $row[$k];
        if (is_string($v) && trim($v) !== '' && $v !== '0000-00-00 00:00:00')
            return true;
        if (is_numeric($v) && (int) $v === 1)
            return true;
        if (is_bool($v) && $v === true)
            return true;
    }
    return false;
}

function fm_get_done_flags(array $job): array
{
    $clientDone = fm_bool_from_row($job, ['client_marked_done', 'client_mark_done', 'client_done']);
    $workerDone = fm_bool_from_row($job, ['worker_marked_done', 'worker_mark_done', 'worker_done', 'admin_mark_done', 'admin_done']);
    return [$clientDone, $workerDone];
}

function fm_redirect(string $url): void
{
    echo "<script>window.location.href=" . json_encode($url) . ";</script>";
    exit;
}

// ---------------------------
// Notification Helper (Notify Client)
// ---------------------------
$notifyClient = function (int $jobId, string $type, string $title, string $body) use ($conn, $NOTIF_COLS): void {
    try {
        // Find client ID from job
        $cid = 0;
        $st = $conn->prepare("SELECT client_id FROM jobs WHERE id = ? LIMIT 1");
        if ($st) {
            $st->bind_param("i", $jobId);
            $st->execute();
            $r = $st->get_result();
            if ($r && $r->num_rows === 1)
                $cid = (int) ($r->fetch_assoc()['client_id'] ?? 0);
            $st->close();
        }
        if ($cid <= 0)
            return;

        $link = "/fixmate/pages/dashboards/client/client-dashboard.php?page=job-detail&job_id=" . (int) $jobId;

        $cols = [];
        $ph = [];
        $vals = [];
        $types = '';

        // Dynamic Insert based on columns
        if (isset($NOTIF_COLS['user_id'])) {
            $cols[] = 'user_id';
            $ph[] = '?';
            $vals[] = $cid;
            $types .= 'i';
        }
        if (isset($NOTIF_COLS['job_id'])) {
            $cols[] = 'job_id';
            $ph[] = '?';
            $vals[] = $jobId;
            $types .= 'i';
        }
        if (isset($NOTIF_COLS['type'])) {
            $cols[] = 'type';
            $ph[] = '?';
            $vals[] = $type;
            $types .= 's';
        }
        if (isset($NOTIF_COLS['title'])) {
            $cols[] = 'title';
            $ph[] = '?';
            $vals[] = $title;
            $types .= 's';
        }

        // Handle body/message variation
        if (isset($NOTIF_COLS['body'])) {
            $cols[] = 'body';
            $ph[] = '?';
            $vals[] = $body;
            $types .= 's';
        } elseif (isset($NOTIF_COLS['message'])) {
            $cols[] = 'message';
            $ph[] = '?';
            $vals[] = $body;
            $types .= 's';
        }

        if (isset($NOTIF_COLS['link'])) {
            $cols[] = 'link';
            $ph[] = '?';
            $vals[] = $link;
            $types .= 's';
        }
        if (isset($NOTIF_COLS['is_read'])) {
            $cols[] = 'is_read';
            $ph[] = '0';
        }
        if (isset($NOTIF_COLS['created_at'])) {
            $cols[] = 'created_at';
            $ph[] = 'NOW()';
        }

        if (empty($cols))
            return;

        $sql = "INSERT INTO notifications (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!empty($vals))
                $stmt->bind_param($types, ...$vals);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
    }
};

// ---------------------------
// History Logger
// ---------------------------
$insertHistory = function (string $status, string $note = '') use ($conn, $adminId, $jobId) {
    try {
        $stmt = $conn->prepare("INSERT INTO job_status_history (job_id, status, note, admin_id, created_at) VALUES (?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("issi", $jobId, $status, $note, $adminId);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
    }
};

// ---------------------------
// FETCH JOB DETAILS (Admin View - No Client ID Check)
// ---------------------------
$stmt = $conn->prepare("
    SELECT 
        j.*,
        c.name  AS category_name,
        sc.name AS sub_category_name,
        u.name AS client_name,
        u.email AS client_email,
        u.phone AS client_phone
    FROM jobs j
    LEFT JOIN users u ON u.id = j.client_id
    LEFT JOIN categories c ON c.id = j.category_id
    LEFT JOIN sub_categories sc ON sc.id = j.sub_category_id
    WHERE j.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $jobId);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    echo "<p class='text-red-600 p-4'>Job not found.</p>";
    return;
}

// ---------------------------
// Active Assignment
// ---------------------------
$assignment = null;
$stmtW = $conn->prepare("
    SELECT 
        jwa.*, w.full_name, w.phone, w.email, w.city, w.area, w.profile_image
    FROM job_worker_assignments jwa
    LEFT JOIN workers w ON w.id = jwa.worker_id
    WHERE jwa.job_id = ?
      AND (jwa.ended_at IS NULL OR jwa.ended_at = '' OR jwa.ended_at = '0000-00-00 00:00:00')
    ORDER BY jwa.id DESC LIMIT 1
");
$stmtW->bind_param("i", $jobId);
$stmtW->execute();
$assignment = $stmtW->get_result()->fetch_assoc();
$stmtW->close();

$assignedWorkerId = $assignment ? (int) ($assignment['worker_id'] ?? 0) : 0;
$hasAssigned = ($assignedWorkerId > 0);

// ---------------------------
// Logic: Status & Flags
// ---------------------------
$status = strtolower(trim((string) ($job['status'] ?? 'live')));
if ($status === 'in_progress')
    $status = 'inprogress';

$isFinal = in_array($status, ['completed', 'deleted', 'cancelled'], true);
$isExpired = ($status === 'expire'); // or check dates

// Check Done Flags
[$clientDone, $workerDone] = fm_get_done_flags($job);
$handshakeComplete = ($clientDone && $workerDone) || ($status === 'completed');

// ---------------------------
// HANDLE POST ACTIONS (Admin)
// ---------------------------
$flashError = '';
$flashSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($CSRF, $token)) {
        $flashError = "CSRF Error. Refresh page.";
    } else {
        $conn->begin_transaction();
        try {
            // --- ASSIGN WORKER ---
            if ($action === 'assign_worker') {
                $workerId = (int) $_POST['worker_id'];
                $reason = trim((string) $_POST['reason'] ?? '');

                if ($isFinal)
                    throw new Exception("Job is finalized.");
                if ($workerId <= 0)
                    throw new Exception("Invalid worker.");

                // Close old assignment if exists
                if ($assignment) {
                    $up = $conn->prepare("UPDATE job_worker_assignments SET ended_at = NOW(), action='reassigned', reason=? WHERE id=?");
                    $up->bind_param("si", $reason, $assignment['id']);
                    $up->execute();
                    $up->close();
                }

                // New Assignment
                $ins = $conn->prepare("INSERT INTO job_worker_assignments (job_id, worker_id, admin_id, action, reason, assigned_at) VALUES (?, ?, ?, 'assigned', ?, NOW())");
                $ins->bind_param("iiis", $jobId, $workerId, $adminId, $reason);
                $ins->execute();
                $ins->close();

                // Update Job
                $upJ = $conn->prepare("UPDATE jobs SET status='assigned', updated_at=NOW() WHERE id=?");
                $upJ->bind_param("i", $jobId);
                $upJ->execute();
                $upJ->close();

                $insertHistory('assigned', $reason);
                $notifyClient($jobId, "job_assigned", "Worker assigned", "A worker has been assigned to job #{$jobId}.");

                $flashSuccess = "Worker assigned successfully.";
            }

            // --- UNASSIGN WORKER ---
            elseif ($action === 'unassign_worker') {
                $reason = trim((string) $_POST['reason'] ?? '');
                if (!$hasAssigned)
                    throw new Exception("No active worker.");

                $up = $conn->prepare("UPDATE job_worker_assignments SET ended_at = NOW(), action='unassigned', reason=? WHERE id=?");
                $up->bind_param("si", $reason, $assignment['id']);
                $up->execute();
                $up->close();

                $upJ = $conn->prepare("UPDATE jobs SET status='live', updated_at=NOW() WHERE id=?");
                $upJ->bind_param("i", $jobId);
                $upJ->execute();
                $upJ->close();

                $insertHistory('unassigned', $reason);
                $notifyClient($jobId, "job_unassigned", "Worker unassigned", "Worker was unassigned from job #{$jobId}.");

                $flashSuccess = "Worker unassigned.";
            }

            // --- WORKER COMING (Set In Progress) ---
            elseif ($action === 'set_stage') {
                $stage = $_POST['stage']; // 'inprogress'
                $note = trim((string) $_POST['note'] ?? '');

                if (!$hasAssigned)
                    throw new Exception("Assign worker first.");

                $upJ = $conn->prepare("UPDATE jobs SET status=?, updated_at=NOW() WHERE id=?");
                $upJ->bind_param("si", $stage, $jobId);
                $upJ->execute();
                $upJ->close();

                $insertHistory($stage, $note);
                $notifyClient($jobId, "job_inprogress", "Job In Progress", "Your job #{$jobId} is now in progress.");

                $flashSuccess = "Job set to In Progress.";
            }

            // --- MARK WORKER DONE ---
            elseif ($action === 'mark_worker_done_admin') {
                $reason = trim((string) $_POST['reason'] ?? '');

                // Set worker_marked_done = 1. If client already done, status = completed.
                $nextStatus = ($clientDone) ? 'completed' : 'inprogress';
                $setSql = "worker_marked_done = 1, updated_at = NOW(), status = '$nextStatus'";

                // Compatibility for legacy columns if they exist
                if (isset($JOB_COLS['worker_done']))
                    $setSql .= ", worker_done = 1";
                if (isset($JOB_COLS['admin_mark_done']))
                    $setSql .= ", admin_mark_done = 1";

                $conn->query("UPDATE jobs SET $setSql WHERE id = $jobId");

                $insertHistory('worker_done', $reason);

                if ($nextStatus === 'completed') {
                    $notifyClient($jobId, "job_completed", "Job Completed", "Job #{$jobId} is now completed.");
                    $flashSuccess = "Job marked completed (Handshake done).";
                } else {
                    $notifyClient($jobId, "job_worker_done", "Worker Done", "Worker marked job #{$jobId} done. Please confirm.");
                    $flashSuccess = "Worker side marked done. Waiting for client.";
                }
            }

            $conn->commit();
            fm_redirect("admin-dashboard.php?page=job-detail&job_id=$jobId");

        } catch (Exception $e) {
            $conn->rollback();
            $flashError = $e->getMessage();
        }
    }
}

// ---------------------------
// Load Available Workers (For Modal)
// ---------------------------
$activeWorkers = [];
if (!$isFinal) {
    // Only fetch active workers
    $rw = $conn->query("SELECT id, full_name, phone, profile_image FROM workers WHERE is_active = 1 ORDER BY full_name ASC");
    if ($rw)
        $activeWorkers = $rw->fetch_all(MYSQLI_ASSOC);
}

// ---------------------------
// Load Attachments & Review
// ---------------------------
$attachments = [];
$resA = $conn->query("SELECT * FROM job_attachments WHERE job_id = $jobId ORDER BY id DESC");
if ($resA)
    $attachments = $resA->fetch_all(MYSQLI_ASSOC);

$review = null;
$resR = $conn->query("SELECT * FROM reviews WHERE job_id = $jobId LIMIT 1");
if ($resR)
    $review = $resR->fetch_assoc();

// ---------------------------
// Display Logic (Colors)
// ---------------------------
$badgeClass = 'bg-slate-100 text-slate-700';
$badgeText = ucfirst($status);

if ($status == 'live') {
    $badgeClass = 'bg-emerald-100 text-emerald-700';
} elseif ($status == 'assigned') {
    $badgeClass = 'bg-sky-50 text-sky-800';
} elseif ($status == 'inprogress') {
    $badgeClass = 'bg-violet-50 text-violet-800';
    $badgeText = 'In Progress';
} elseif ($status == 'completed') {
    $badgeClass = 'bg-indigo-100 text-indigo-700';
} elseif ($status == 'deleted') {
    $badgeClass = 'bg-slate-200 text-slate-700';
}

// Action Buttons Availability
$isInProgress = ($status === 'inprogress');
$showStartBtn = (!$isFinal && $hasAssigned && in_array($status, ['assigned', 'live']));
$showConfirmDone = (!$isFinal && $hasAssigned && $isInProgress && !$workerDone);

?>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/phosphor-icons"></script>

<div class="px-4 sm:px-8 py-6">
    <div class="max-w-5xl">

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">Job Details (Admin)</h2>
                <p class="text-sm text-slate-500">Manage job status and worker assignment.</p>
            </div>
            <a href="admin-dashboard.php?page=jobs"
                class="text-xs px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50">
                Back to Jobs List
            </a>
        </div>

        <?php if ($flashError): ?>
            <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                <?php echo fm_h($flashError); ?></div>
        <?php endif; ?>
        <?php if ($flashSuccess): ?>
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                <?php echo fm_h($flashSuccess); ?></div>
        <?php endif; ?>

        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 sm:p-6">

            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="text-lg font-semibold text-slate-900 break-words">#<?php echo $jobId; ?> —
                        <?php echo fm_h($job['title']); ?></h3>

                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-600">
                        <span class="inline-flex items-center gap-1">
                            <span class="ph ph-tag"></span> <?php echo fm_h($job['category_name'] ?? '—'); ?>
                            <?php if (!empty($job['sub_category_name'])): ?> •
                                <?php echo fm_h($job['sub_category_name']); ?><?php endif; ?>
                        </span>
                        <span class="text-slate-300">•</span>
                        <span class="inline-flex items-center gap-1">
                            <span class="ph ph-map-pin"></span>
                            <?php echo fm_h($job['location_text'] ?? 'No location'); ?>
                        </span>
                        <span class="text-slate-300">•</span>
                        <span class="inline-flex items-center gap-1">
                            <span class="ph ph-user"></span> Client: <?php echo fm_h($job['client_name']); ?>
                        </span>
                    </div>
                </div>

                <div class="flex flex-col items-end gap-2">
                    <span
                        class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium <?php echo $badgeClass; ?>">
                        <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span> <?php echo fm_h($badgeText); ?>
                    </span>

                    <?php if ($workerDone && !$clientDone && !$isFinal): ?>
                        <span
                            class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium bg-cyan-50 text-cyan-800 border-cyan-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span> Waiting Client Confirmation
                        </span>
                    <?php elseif ($clientDone && !$workerDone && !$isFinal): ?>
                        <span
                            class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium bg-sky-50 text-sky-800 border-sky-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span> Waiting Worker Confirmation
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$isFinal): ?>
                <div class="mt-5 pt-4 border-t border-slate-100 flex flex-wrap gap-2">

                    <button onclick="openAssignModal()"
                        class="text-xs px-3 py-1.5 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">
                        <?php echo $hasAssigned ? 'Change Worker' : 'Assign Worker'; ?>
                    </button>

                    <?php if ($hasAssigned): ?>
                        <button onclick="openConfirmModal('unassign')"
                            class="text-xs px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50">
                            Unassign
                        </button>
                    <?php endif; ?>

                    <?php if ($showStartBtn): ?>
                        <button onclick="openStageModal('inprogress', 'Worker Coming')"
                            class="text-xs px-3 py-1.5 rounded-lg border border-amber-200 text-amber-700 hover:bg-amber-50">
                            Worker Coming
                        </button>
                    <?php endif; ?>

                    <?php if ($showConfirmDone): ?>
                        <button onclick="openConfirmModal('worker_done')"
                            class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                            Confirm Job Done
                        </button>
                    <?php endif; ?>

                </div>
            <?php endif; ?>

            <div class="mt-5">
                <h4 class="text-sm font-semibold text-slate-900 mb-2">Description</h4>
                <div
                    class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 whitespace-pre-line">
                    <?php echo fm_h($job['description']); ?>
                </div>
            </div>

            <div class="mt-6">
                <h4 class="text-sm font-semibold text-slate-900 mb-2">Assigned Worker</h4>
                <?php if ($assignment): ?>
                    <div class="rounded-xl border border-slate-200 p-4 flex gap-3">
                        <img src="<?php echo fm_h($assignment['profile_image'] ?: '/fixmate/assets/images/avatar-default.png'); ?>"
                            class="w-12 h-12 rounded-full object-cover border border-slate-200">
                        <div>
                            <p class="text-sm font-semibold text-slate-900"><?php echo fm_h($assignment['full_name']); ?>
                            </p>
                            <p class="text-xs text-slate-500"><?php echo fm_h($assignment['phone']); ?> •
                                <?php echo fm_h($assignment['email']); ?></p>
                            <p class="text-[11px] text-slate-400 mt-1">Assigned:
                                <?php echo fm_format_dt($assignment['assigned_at']); ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div
                        class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                        No worker assigned.
                    </div>
                <?php endif; ?>
            </div>

            <div class="mt-6">
                <h4 class="text-sm font-semibold text-slate-900 mb-2">Attachments</h4>
                <?php if (!empty($attachments)): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <?php foreach ($attachments as $index => $a): ?>
                            <?php
                            $type = strtolower(trim((string) ($a['file_type'] ?? 'file')));
                            $url = (string) ($a['file_url'] ?? '');
                            if ($url === '')
                                continue;

                            $label = 'File';
                            if ($type === 'image')
                                $label = 'Image';
                            elseif ($type === 'video')
                                $label = 'Video';
                            elseif ($type === 'audio')
                                $label = 'Voice Note';
                            ?>
                            <div class="rounded-xl border border-slate-200 p-3 bg-white">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs font-semibold text-slate-800"><?php echo fm_h($label); ?></p>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">
                                        <?php echo fm_h($type ?: 'file'); ?>
                                    </span>
                                </div>

                                <div class="mt-2">
                                    <?php if ($type === 'image'): ?>
                                        <button type="button" class="w-full" onclick="openGallery(<?php echo $index; ?>)">
                                            <img src="<?php echo fm_h($url); ?>"
                                                class="w-full h-36 object-cover rounded-lg border border-slate-200"
                                                alt="attachment">
                                        </button>

                                    <?php elseif ($type === 'video'): ?>
                                        <button type="button" class="w-full" onclick="openGallery(<?php echo $index; ?>)">
                                            <div
                                                class="w-full h-36 rounded-lg border border-slate-200 bg-black flex items-center justify-center text-white/80">
                                                <span class="ph ph-play-circle text-3xl"></span>
                                            </div>
                                        </button>

                                    <?php elseif ($type === 'audio'): ?>
                                        <div class="space-y-2">
                                            <audio controls class="w-full">
                                                <source src="<?php echo fm_h($url); ?>">
                                            </audio>
                                            <button type="button"
                                                class="w-full text-xs px-3 py-2 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50"
                                                onclick="openGallery(<?php echo $index; ?>)">
                                                Open voice note
                                            </button>
                                        </div>

                                    <?php else: ?>
                                        <a href="<?php echo fm_h($url); ?>" target="_blank"
                                            class="inline-flex items-center gap-2 text-xs text-indigo-700 hover:underline">
                                            <span class="ph ph-paperclip"></span> Open file
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-2 text-[11px] text-slate-400">
                                    Uploaded: <?php echo fm_format_dt($a['created_at'] ?? ''); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-slate-500 italic">No attachments.</p>
                <?php endif; ?>
            </div>

            <?php if ($review): ?>
                <div class="mt-6 pt-6 border-t border-slate-100">
                    <h4 class="text-sm font-semibold text-slate-900 mb-2">Client Review</h4>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-yellow-500 font-bold">★ <?php echo (int) $review['rating']; ?>/5</span>
                            <span class="text-xs text-slate-400"><?php echo fm_format_dt($review['created_at']); ?></span>
                        </div>
                        <p class="text-sm text-slate-700 italic">"<?php echo fm_h($review['comment']); ?>"</p>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<div id="mediaModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-black/85 p-4">
    <button type="button"
        class="absolute top-4 right-4 z-50 inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/20 text-white hover:bg-white/40 backdrop-blur-sm"
        onclick="closeMediaModal()">
        <span class="ph ph-x text-lg"></span>
    </button>

    <button type="button" id="prevMediaBtn"
        class="absolute left-4 top-1/2 -translate-y-1/2 z-50 inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/20 text-white hover:bg-white/40 backdrop-blur-sm hidden"
        onclick="navigateMedia(-1)">
        <span class="ph ph-caret-left text-lg"></span>
    </button>

    <button type="button" id="nextMediaBtn"
        class="absolute right-4 top-1/2 -translate-y-1/2 z-50 inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/20 text-white hover:bg-white/40 backdrop-blur-sm hidden"
        onclick="navigateMedia(1)">
        <span class="ph ph-caret-right text-lg"></span>
    </button>

    <div class="relative w-full max-w-5xl h-full flex flex-col items-center justify-center">
        <div id="mediaModalBody" class="flex items-center justify-center w-full h-full">
        </div>
    </div>
</div>

<div id="assignModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-xl shadow-lg w-full max-w-sm p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-2">Assign Worker</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
            <input type="hidden" name="action" value="assign_worker">

            <label class="block text-xs font-medium text-slate-700 mb-1">Select Worker</label>
            <select name="worker_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-3">
                <?php foreach ($activeWorkers as $w): ?>
                    <option value="<?php echo $w['id']; ?>" <?php echo ($assignedWorkerId == $w['id']) ? 'selected' : ''; ?>>
                        <?php echo fm_h($w['full_name']); ?> (<?php echo fm_h($w['phone']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="block text-xs font-medium text-slate-700 mb-1">Reason / Note</label>
            <input type="text" name="reason" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-4"
                placeholder="Optional">

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeModals()"
                    class="px-3 py-1.5 rounded-lg border text-slate-600">Cancel</button>
                <button type="submit" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white">Assign</button>
            </div>
        </form>
    </div>
</div>

<div id="confirmModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-xl shadow-lg w-full max-w-sm p-5">
        <h3 id="confirmTitle" class="text-sm font-semibold text-slate-900 mb-2">Confirm Action</h3>
        <p id="confirmDesc" class="text-xs text-slate-600 mb-3"></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
            <input type="hidden" name="action" id="confirmAction" value="">
            <input type="text" name="reason" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-4"
                placeholder="Reason (Optional)">
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeModals()"
                    class="px-3 py-1.5 rounded-lg border text-slate-600">Cancel</button>
                <button type="submit" id="confirmBtn"
                    class="px-3 py-1.5 rounded-lg bg-rose-600 text-white">Confirm</button>
            </div>
        </form>
    </div>
</div>

<div id="stageModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-xl shadow-lg w-full max-w-sm p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-2">Set Status: In Progress</h3>
        <p class="text-xs text-slate-600 mb-3">Mark that the worker is coming/starting the job.</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
            <input type="hidden" name="action" value="set_stage">
            <input type="hidden" name="stage" id="stageVal" value="">
            <input type="text" name="note" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-4"
                placeholder="Note (Optional)">
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeModals()"
                    class="px-3 py-1.5 rounded-lg border text-slate-600">Cancel</button>
                <button type="submit" class="px-3 py-1.5 rounded-lg bg-amber-600 text-white">Update</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Pass PHP attachments to JS
    const galleryItems = <?php echo json_encode(array_values($attachments)); ?>;
    let currentMediaIndex = 0;

    function openGallery(index) {
        currentMediaIndex = index;
        renderMedia();
        const modal = document.getElementById('mediaModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeMediaModal() {
        const modal = document.getElementById('mediaModal');
        const body = document.getElementById('mediaModalBody');
        body.innerHTML = ""; // Stop video/audio
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function navigateMedia(dir) {
        let newIndex = currentMediaIndex + dir;
        if (newIndex >= 0 && newIndex < galleryItems.length) {
            currentMediaIndex = newIndex;
            renderMedia();
        }
    }

    function renderMedia() {
        const item = galleryItems[currentMediaIndex];
        const body = document.getElementById('mediaModalBody');
        const prevBtn = document.getElementById('prevMediaBtn');
        const nextBtn = document.getElementById('nextMediaBtn');

        body.innerHTML = "";

        if (!item) return;

        const type = (item.file_type || 'file').toLowerCase();
        const url = item.file_url || '';

        // Render content based on type
        if (type === 'image') {
            const img = document.createElement('img');
            img.src = url;
            img.className = "max-h-[85vh] max-w-full object-contain";
            body.appendChild(img);
        } else if (type === 'video') {
            const video = document.createElement('video');
            video.controls = true;
            video.autoplay = true;
            video.className = "max-h-[85vh] max-w-full bg-black";
            const src = document.createElement('source');
            src.src = url;
            video.appendChild(src);
            body.appendChild(video);
        } else if (type === 'audio') {
            const wrap = document.createElement('div');
            wrap.className = "w-full max-w-md p-6 bg-white rounded-lg shadow-xl";
            const h = document.createElement('div');
            h.className = "text-sm font-semibold text-slate-900 mb-3 text-center";
            h.textContent = "Voice Note";
            const audio = document.createElement('audio');
            audio.controls = true;
            audio.autoplay = true;
            audio.className = "w-full";
            const src = document.createElement('source');
            src.src = url;
            audio.appendChild(src);
            wrap.appendChild(h);
            wrap.appendChild(audio);
            body.appendChild(wrap);
        } else {
            // Fallback for non-media files
            const link = document.createElement('a');
            link.href = url;
            link.target = "_blank";
            link.className = "text-white text-lg hover:underline flex items-center gap-2";
            link.innerHTML = '<span class="ph ph-file-arrow-down text-2xl"></span> Download/View File';
            body.appendChild(link);
        }

        // Toggle buttons
        if (currentMediaIndex > 0) {
            prevBtn.classList.remove('hidden');
        } else {
            prevBtn.classList.add('hidden');
        }

        if (currentMediaIndex < galleryItems.length - 1) {
            nextBtn.classList.remove('hidden');
        } else {
            nextBtn.classList.add('hidden');
        }
    }

    function closeModals() {
        document.getElementById('assignModal').classList.add('hidden');
        document.getElementById('assignModal').classList.remove('flex');
        document.getElementById('confirmModal').classList.add('hidden');
        document.getElementById('confirmModal').classList.remove('flex');
        document.getElementById('stageModal').classList.add('hidden');
        document.getElementById('stageModal').classList.remove('flex');
    }

    function openAssignModal() {
        const m = document.getElementById('assignModal');
        m.classList.remove('hidden'); m.classList.add('flex');
    }

    function openConfirmModal(type) {
        const m = document.getElementById('confirmModal');
        const act = document.getElementById('confirmAction');
        const tit = document.getElementById('confirmTitle');
        const desc = document.getElementById('confirmDesc');
        const btn = document.getElementById('confirmBtn');

        if (type === 'unassign') {
            act.value = 'unassign_worker';
            tit.innerText = 'Unassign Worker?';
            desc.innerText = 'The job status will revert to Live.';
            btn.className = 'px-3 py-1.5 rounded-lg bg-rose-600 text-white';
            btn.innerText = 'Unassign';
        } else if (type === 'worker_done') {
            act.value = 'mark_worker_done_admin';
            tit.innerText = 'Confirm Job Done?';
            desc.innerText = 'Sets worker side as done. If client also confirmed, job becomes Completed.';
            btn.className = 'px-3 py-1.5 rounded-lg bg-emerald-600 text-white';
            btn.innerText = 'Confirm Done';
        }
        m.classList.remove('hidden'); m.classList.add('flex');
    }

    function openStageModal(stage, title) {
        const m = document.getElementById('stageModal');
        document.getElementById('stageVal').value = stage;
        m.classList.remove('hidden'); m.classList.add('flex');
    }
</script>