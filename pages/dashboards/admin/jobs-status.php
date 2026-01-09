<?php
// /fixmate/pages/dashboards/admin/jobs-status.php
// NOTE: This file can run standalone OR be included inside admin-dashboard.php?page=jobs

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/db.php';

// ---------------------------
// AUTH GUARD (admin only)
// ---------------------------
if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'] ?? '')) !== 'admin') {
    echo "<p style='padding:16px;color:#b91c1c'>Access denied.</p>";
    exit;
}

$adminId = (int) ($_SESSION['user_id'] ?? 0);

// ---------------------------
// CSRF (simple)
// ---------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_token'];

// ---------------------------
// Detect jobs table columns once (for handshake fields compatibility)
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
// Detect notifications table columns once (because your schema differs across versions)
// ---------------------------
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
function fm_h($s)
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

function fm_badge_html(string $text, string $class): string
{
    return '<span class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium ' . $class . '">
        <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span>' . fm_h($text) . '</span>';
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

        if (is_string($v) && trim($v) !== '' && $v !== '0000-00-00 00:00:00')
            return true;
        if (is_numeric($v) && (int) $v === 1)
            return true;
        if (is_bool($v) && $v === true)
            return true;
    }
    return false;
}

/**
 * Handshake (client vs worker) completion state.
 * IMPORTANT: In your system, "Admin means worker", so admin marking done = worker done.
 */
function fm_get_done_flags(array $job): array
{
    $clientDone = fm_bool_from_job($job, [
        'client_mark_done',
        'client_done',
        'client_mark_done_at',
        'client_done_at',
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
    ]);

    return [$clientDone, $workerDone];
}

/**
 * Status badge (based on jobs.status) + Expired override.
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
        return ['In Review', 'bg-cyan-50 text-cyan-800 border-cyan-200'];

    return ['Live', 'bg-emerald-100 text-emerald-700 border-emerald-200'];
}

/**
 * Handshake badge logic
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
        return ['Waiting Client Confirmation', 'bg-cyan-50 text-cyan-800 border-cyan-200'];
    if ($clientDone && !$workerDone)
        return ['Client Mark Done', 'bg-sky-50 text-sky-800 border-sky-200'];

    return ['Confirmation Pending (Both)', 'bg-slate-100 text-slate-700 border-slate-200'];
}

function fm_script_redirect(string $url): void
{
    $url = fm_h($url);
    echo "<script>window.location.href='{$url}';</script>";
    exit;
}

// ---------------------------
// Notifications helper (robust across schemas)
// ---------------------------
$CLIENT_JOB_LINK_BASE = "/fixmate/pages/dashboards/client/client-dashboard.php?page=job-detail&job_id=";

$getClientId = function (int $jobId) use ($conn): int {
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
    return $cid;
};

$notifyClient = function (int $jobId, string $type, string $title, string $body, ?string $link = null) use ($conn, $NOTIF_COLS, $CLIENT_JOB_LINK_BASE, $getClientId): void {
    try {
        $cid = $getClientId($jobId);
        if ($cid <= 0)
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

        $cols = [];
        $ph = [];
        $vals = [];
        $types = '';

        if ($hasUserId) {
            $cols[] = 'user_id';
            $ph[] = '?';
            $vals[] = $cid;
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
            return;

        $sql = "INSERT INTO notifications (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
        $st = $conn->prepare($sql);
        if (!$st)
            return;

        if (!empty($vals))
            $st->bind_param($types, ...$vals);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {
    }
};

// ---------------------------
// Handle POST actions
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $token = (string) ($_POST['csrf_token'] ?? '');

    $flashError = '';
    $flashSuccess = '';

    if (!hash_equals($CSRF, $token)) {
        $flashError = "Security check failed (CSRF). Please refresh and try again.";
    } else {
        $jobId = (int) ($_POST['job_id'] ?? 0);

        if ($jobId <= 0) {
            $flashError = "Invalid job.";
        } else {

            $jobRow = null;
            $stmtJ = $conn->prepare("SELECT * FROM jobs WHERE id = ? LIMIT 1");
            if ($stmtJ) {
                $stmtJ->bind_param('i', $jobId);
                $stmtJ->execute();
                $resJ = $stmtJ->get_result();
                if ($resJ && $resJ->num_rows === 1)
                    $jobRow = $resJ->fetch_assoc();
                $stmtJ->close();
            }

            $jobStatus = strtolower(trim((string) ($jobRow['status'] ?? '')));
            $isFinal = in_array($jobStatus, ['completed', 'cancelled', 'deleted'], true);

            $activeAssign = null;
            $stmtA = $conn->prepare("
                SELECT id, job_id, worker_id
                FROM job_worker_assignments
                WHERE job_id = ?
                  AND (ended_at IS NULL OR ended_at = '' OR ended_at = '0000-00-00 00:00:00')
                ORDER BY id DESC
                LIMIT 1
            ");
            if ($stmtA) {
                $stmtA->bind_param('i', $jobId);
                $stmtA->execute();
                $resA = $stmtA->get_result();
                $activeAssign = ($resA && $resA->num_rows === 1) ? $resA->fetch_assoc() : null;
                $stmtA->close();
            }

            $insertHistory = function (string $status, string $note = '') use ($conn, $adminId, $jobId) {
                try {
                    $stmt = $conn->prepare("INSERT INTO job_status_history (job_id, status, created_at) VALUES (?, ?, NOW())");
                    if ($stmt) {
                        $stmt->bind_param("is", $jobId, $status);
                        $stmt->execute();
                        $stmt->close();
                        return;
                    }
                } catch (Throwable $e) {
                }

                try {
                    $stmt = $conn->prepare("INSERT INTO job_status_history (job_id, status, note, admin_id, created_at) VALUES (?, ?, ?, ?, NOW())");
                    if ($stmt) {
                        $stmt->bind_param("issi", $jobId, $status, $note, $adminId);
                        $stmt->execute();
                        $stmt->close();
                        return;
                    }
                } catch (Throwable $e) {
                }
            };

            // assign_worker
            if ($action === 'assign_worker') {
                if ($isFinal) {
                    $flashError = "This job is already finalized (completed/cancelled/deleted).";
                } else {
                    $workerId = (int) ($_POST['worker_id'] ?? 0);
                    if ($workerId <= 0) {
                        $flashError = "Invalid worker.";
                    } else {
                        $wOk = false;
                        $stmtW = $conn->prepare("SELECT id FROM workers WHERE id = ? AND is_active = 1 LIMIT 1");
                        if ($stmtW) {
                            $stmtW->bind_param("i", $workerId);
                            $stmtW->execute();
                            $rw = $stmtW->get_result();
                            $wOk = ($rw && $rw->num_rows === 1);
                            $stmtW->close();
                        }
                        if (!$wOk) {
                            $flashError = "Worker is not active or not found.";
                        } else {
                            $reason = trim((string) ($_POST['reason'] ?? ''));

                            $conn->begin_transaction();
                            try {
                                if ($activeAssign && (int) ($activeAssign['worker_id'] ?? 0) > 0) {
                                    $oldAssignId = (int) ($activeAssign['id'] ?? 0);
                                    $up = $conn->prepare("UPDATE job_worker_assignments SET ended_at = NOW(), action = 'reassigned', reason = ? WHERE id = ? LIMIT 1");
                                    if ($up) {
                                        $up->bind_param("si", $reason, $oldAssignId);
                                        if (!$up->execute())
                                            throw new Exception("Failed to end previous assignment.");
                                        $up->close();
                                    }
                                    $insertHistory('reassigned', $reason);
                                }

                                $ins = $conn->prepare("
                                    INSERT INTO job_worker_assignments (job_id, worker_id, admin_id, action, reason, assigned_at)
                                    VALUES (?, ?, ?, 'assigned', ?, NOW())
                                ");
                                if (!$ins)
                                    throw new Exception("Prepare failed: " . $conn->error);
                                $ins->bind_param("iiis", $jobId, $workerId, $adminId, $reason);
                                if (!$ins->execute())
                                    throw new Exception("Failed to assign worker.");
                                $ins->close();

                                $upJob = $conn->prepare("UPDATE jobs SET status = 'assigned', updated_at = NOW() WHERE id = ? LIMIT 1");
                                if (!$upJob)
                                    throw new Exception("Prepare failed: " . $conn->error);
                                $upJob->bind_param("i", $jobId);
                                if (!$upJob->execute())
                                    throw new Exception("Failed to update job status.");
                                $upJob->close();

                                $insertHistory('assigned', $reason);

                                $notifyClient($jobId, "job_assigned", "Worker assigned", "A worker has been assigned to your job #{$jobId}.", null);

                                $conn->commit();
                                $flashSuccess = $activeAssign ? "Worker changed successfully." : "Worker assigned successfully.";
                            } catch (Throwable $e) {
                                $conn->rollback();
                                $flashError = $e->getMessage();
                            }
                        }
                    }
                }
            }

            // unassign_worker
            elseif ($action === 'unassign_worker') {
                if ($isFinal) {
                    $flashError = "This job is already finalized (completed/cancelled/deleted).";
                } elseif (!$activeAssign) {
                    $flashError = "This job has no active assigned worker.";
                } else {
                    $reason = trim((string) ($_POST['reason'] ?? ''));
                    $assignId = (int) ($activeAssign['id'] ?? 0);

                    $conn->begin_transaction();
                    try {
                        $up = $conn->prepare("UPDATE job_worker_assignments SET ended_at = NOW(), action = 'unassigned', reason = ? WHERE id = ? LIMIT 1");
                        if (!$up)
                            throw new Exception("Prepare failed: " . $conn->error);
                        $up->bind_param("si", $reason, $assignId);
                        if (!$up->execute())
                            throw new Exception("Failed to unassign worker.");
                        $up->close();

                        $upJob = $conn->prepare("UPDATE jobs SET status = 'live', updated_at = NOW() WHERE id = ? LIMIT 1");
                        if (!$upJob)
                            throw new Exception("Prepare failed: " . $conn->error);
                        $upJob->bind_param("i", $jobId);
                        if (!$upJob->execute())
                            throw new Exception("Failed to update job status.");
                        $upJob->close();

                        $insertHistory('unassigned', $reason);

                        $notifyClient($jobId, "job_unassigned", "Worker unassigned", "The assigned worker was unassigned from your job #{$jobId}.", null);

                        $conn->commit();
                        $flashSuccess = "Worker unassigned successfully.";
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $flashError = $e->getMessage();
                    }
                }
            }

            // set_stage
            elseif ($action === 'set_stage') {
                if ($isFinal) {
                    $flashError = "This job is already finalized (completed/cancelled/deleted).";
                } elseif (!$activeAssign) {
                    $flashError = "Assign a worker first to set progress.";
                } else {
                    $stage = strtolower(trim((string) ($_POST['stage'] ?? '')));
                    $allowed = ['worker_coming', 'in_progress'];
                    if (!in_array($stage, $allowed, true)) {
                        $flashError = "Invalid stage.";
                    } else {
                        if ($stage === 'in_progress' && $jobStatus !== 'worker_coming') {
                            $flashError = "You must set Worker Coming first.";
                        } else {
                            $note = trim((string) ($_POST['note'] ?? ''));

                            $conn->begin_transaction();
                            try {
                                $upJob = $conn->prepare("UPDATE jobs SET status = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                                if (!$upJob)
                                    throw new Exception("Prepare failed: " . $conn->error);
                                $upJob->bind_param("si", $stage, $jobId);
                                if (!$upJob->execute())
                                    throw new Exception("Failed to update job status.");
                                $upJob->close();

                                $insertHistory($stage, $note);

                                if ($stage === 'worker_coming') {
                                    $notifyClient($jobId, "worker_coming", "Worker is coming", "Your job #{$jobId} is in progress. The worker will come soon today.", null);
                                } elseif ($stage === 'in_progress') {
                                    $notifyClient($jobId, "job_in_progress", "Job in progress", "Your job #{$jobId} is now in progress.", null);
                                }

                                $conn->commit();
                                $flashSuccess = "Job status updated.";
                            } catch (Throwable $e) {
                                $conn->rollback();
                                $flashError = $e->getMessage();
                            }
                        }
                    }
                }
            }

            // mark_worker_done_admin
            elseif ($action === 'mark_worker_done_admin') {
                if ($isFinal) {
                    $flashError = "This job is already finalized (completed/cancelled/deleted).";
                } elseif (!$activeAssign) {
                    $flashError = "Assign a worker first to mark done.";
                } else {
                    $reason = trim((string) ($_POST['reason'] ?? ''));

                    [$clientDone, $workerDone] = fm_get_done_flags($jobRow ?: []);

                    $conn->begin_transaction();
                    try {
                        $setParts = [];
                        $setParts[] = "updated_at = NOW()";

                        if (isset($JOB_COLS['worker_mark_done']))
                            $setParts[] = "worker_mark_done = 1";
                        if (isset($JOB_COLS['worker_done']))
                            $setParts[] = "worker_done = 1";
                        if (isset($JOB_COLS['worker_mark_done_at']))
                            $setParts[] = "worker_mark_done_at = NOW()";
                        if (isset($JOB_COLS['worker_done_at']))
                            $setParts[] = "worker_done_at = NOW()";

                        if (isset($JOB_COLS['admin_mark_done']))
                            $setParts[] = "admin_mark_done = 1";
                        if (isset($JOB_COLS['admin_done']))
                            $setParts[] = "admin_done = 1";
                        if (isset($JOB_COLS['admin_mark_done_at']))
                            $setParts[] = "admin_mark_done_at = NOW()";
                        if (isset($JOB_COLS['admin_done_at']))
                            $setParts[] = "admin_done_at = NOW()";

                        $nextStatus = $clientDone ? 'completed' : 'waiting_client_confirmation';
                        $setParts[] = "status = '" . $conn->real_escape_string($nextStatus) . "'";

                        $sqlUp = "UPDATE jobs SET " . implode(", ", $setParts) . " WHERE id = ? LIMIT 1";
                        $up = $conn->prepare($sqlUp);
                        if (!$up)
                            throw new Exception("Prepare failed: " . $conn->error);
                        $up->bind_param("i", $jobId);
                        if (!$up->execute())
                            throw new Exception("Failed to mark worker done.");
                        $up->close();

                        $insertHistory('worker_mark_done_by_admin', $reason);

                        if ($nextStatus === 'waiting_client_confirmation') {
                            $notifyClient($jobId, "waiting_client_confirmation", "Job marked done by worker", "Job #{$jobId} is marked done by the worker. Please confirm completion.", null);
                        } else {
                            $notifyClient($jobId, "job_completed", "Job completed", "Job #{$jobId} is completed (both confirmations done).", null);
                        }

                        $conn->commit();
                        $flashSuccess = ($nextStatus === 'completed')
                            ? "Worker done set. Handshake completed → Job marked Completed."
                            : "Worker done set. Now waiting for client confirmation.";
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $flashError = $e->getMessage();
                    }
                }
            }

            // admin_confirm_done
            elseif ($action === 'admin_confirm_done') {

                $curStatus = strtolower(trim((string) ($jobRow['status'] ?? 'live')));
                if (in_array($curStatus, ['cancelled', 'deleted'], true)) {
                    $flashError = "You cannot confirm a cancelled/deleted job.";
                } else {

                    $reason = trim((string) ($_POST['reason'] ?? ''));

                    [$clientDone, $workerDone] = fm_get_done_flags($jobRow ?: []);

                    if ($clientDone && $workerDone) {
                        $flashSuccess = "This job is already fully confirmed (handshake completed).";
                    } else {

                        $conn->begin_transaction();
                        try {
                            $setParts = [];
                            $setParts[] = "updated_at = NOW()";
                            $setParts[] = "status = 'completed'";

                            if (isset($JOB_COLS['client_mark_done']))
                                $setParts[] = "client_mark_done = 1";
                            if (isset($JOB_COLS['client_done']))
                                $setParts[] = "client_done = 1";
                            if (isset($JOB_COLS['client_mark_done_at']))
                                $setParts[] = "client_mark_done_at = NOW()";
                            if (isset($JOB_COLS['client_done_at']))
                                $setParts[] = "client_done_at = NOW()";

                            if (isset($JOB_COLS['worker_mark_done']))
                                $setParts[] = "worker_mark_done = 1";
                            if (isset($JOB_COLS['worker_done']))
                                $setParts[] = "worker_done = 1";
                            if (isset($JOB_COLS['worker_mark_done_at']))
                                $setParts[] = "worker_mark_done_at = NOW()";
                            if (isset($JOB_COLS['worker_done_at']))
                                $setParts[] = "worker_done_at = NOW()";

                            if (isset($JOB_COLS['admin_mark_done']))
                                $setParts[] = "admin_mark_done = 1";
                            if (isset($JOB_COLS['admin_done']))
                                $setParts[] = "admin_done = 1";
                            if (isset($JOB_COLS['admin_mark_done_at']))
                                $setParts[] = "admin_mark_done_at = NOW()";
                            if (isset($JOB_COLS['admin_done_at']))
                                $setParts[] = "admin_done_at = NOW()";

                            $sqlUp = "UPDATE jobs SET " . implode(", ", $setParts) . " WHERE id = ? LIMIT 1";
                            $up = $conn->prepare($sqlUp);
                            if (!$up)
                                throw new Exception("Prepare failed: " . $conn->error);

                            $up->bind_param("i", $jobId);
                            if (!$up->execute())
                                throw new Exception("Failed to confirm job done.");
                            $up->close();

                            $insertHistory('admin_confirm_done', $reason);

                            $notifyClient($jobId, "job_completed", "Job completed", "Job #{$jobId} has been confirmed completed by admin.", null);

                            $conn->commit();
                            $flashSuccess = "Admin confirmed job done. Handshake completed → Job marked Completed.";
                        } catch (Throwable $e) {
                            $conn->rollback();
                            $flashError = $e->getMessage();
                        }
                    }
                }
            }

            if ($flashError !== '')
                $_SESSION['flash_jobs_error'] = $flashError;
            if ($flashSuccess !== '')
                $_SESSION['flash_jobs_success'] = $flashSuccess;
        }
    }

    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $back = "admin-dashboard.php?page=jobs" . ($qs ? "&" . $qs : "");
    fm_script_redirect($back);
}

$flashError = (string) ($_SESSION['flash_jobs_error'] ?? '');
$flashSuccess = (string) ($_SESSION['flash_jobs_success'] ?? '');
unset($_SESSION['flash_jobs_error'], $_SESSION['flash_jobs_success']);

// ---------------------------
// Filter inputs
// ---------------------------
$jobSearch = trim($_GET['job_search'] ?? '');
$clientSearch = trim($_GET['client_search'] ?? '');
$workerSearch = trim($_GET['worker_search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');

$categoryId = (int) ($_GET['category_id'] ?? 0);
$subCategoryId = (int) ($_GET['sub_category_id'] ?? 0);

$budgetMin = trim($_GET['budget_min'] ?? '');
$budgetMax = trim($_GET['budget_max'] ?? '');

$dateFilter = trim($_GET['date_filter'] ?? 'all');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$attachmentsFilter = trim($_GET['attachments'] ?? 'all');

$openAssignJobId = (int) ($_GET['assign_job_id'] ?? 0);

// ---------------------------
// Load categories
// ---------------------------
$categories = [];
$r1 = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($r1)
    $categories = $r1->fetch_all(MYSQLI_ASSOC);

// Subcats AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] === 'subcategories') {
    header('Content-Type: application/json; charset=utf-8');
    $cid = (int) ($_GET['category_id'] ?? 0);
    $rows = [];
    if ($cid > 0) {
        $stmt = $conn->prepare("
            SELECT id, name
            FROM sub_categories
            WHERE category_id = ?
            ORDER BY name ASC
        ");
        if ($stmt) {
            $stmt->bind_param("i", $cid);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
        }
    }
    echo json_encode(["status" => "ok", "data" => $rows]);
    exit;
}

// ---------------------------
// Build WHERE clause
// ---------------------------
$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($jobSearch !== '') {
    $where .= " AND (
        j.id = ? OR
        j.title LIKE ? OR
        j.description LIKE ? OR
        j.location_text LIKE ?
    ) ";
    $like = '%' . $jobSearch . '%';
    $jobIdExact = ctype_digit($jobSearch) ? (int) $jobSearch : 0;

    $params[] = $jobIdExact;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "isss";
}

if ($clientSearch !== '') {
    $where .= " AND (
        u.name LIKE ? OR
        u.email LIKE ?
    ) ";
    $like = '%' . $clientSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

if ($workerSearch !== '') {
    $where .= " AND (
        w.full_name LIKE ? OR
        w.phone LIKE ? OR
        w.email LIKE ?
    ) ";
    $like = '%' . $workerSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

if ($categoryId > 0) {
    $where .= " AND j.category_id = ? ";
    $params[] = $categoryId;
    $types .= "i";
}
if ($subCategoryId > 0) {
    $where .= " AND j.sub_category_id = ? ";
    $params[] = $subCategoryId;
    $types .= "i";
}

if ($budgetMin !== '' && ctype_digit($budgetMin)) {
    $where .= " AND j.budget >= ? ";
    $params[] = (int) $budgetMin;
    $types .= "i";
}
if ($budgetMax !== '' && ctype_digit($budgetMax)) {
    $where .= " AND j.budget <= ? ";
    $params[] = (int) $budgetMax;
    $types .= "i";
}

if ($attachmentsFilter === 'yes') {
    $where .= " AND (j.is_attachments = 'yes') ";
} elseif ($attachmentsFilter === 'no') {
    $where .= " AND (j.is_attachments = 'no' OR j.is_attachments IS NULL OR j.is_attachments = '') ";
}

if ($dateFilter === 'today') {
    $where .= " AND DATE(j.created_at) = CURDATE() ";
} elseif ($dateFilter === 'last7') {
    $where .= " AND j.created_at >= (NOW() - INTERVAL 7 DAY) ";
} elseif ($dateFilter === 'this_month') {
    $where .= " AND YEAR(j.created_at) = YEAR(CURDATE()) AND MONTH(j.created_at) = MONTH(CURDATE()) ";
} elseif ($dateFilter === 'custom') {
    if ($dateFrom !== '') {
        $where .= " AND DATE(j.created_at) >= ? ";
        $params[] = $dateFrom;
        $types .= "s";
    }
    if ($dateTo !== '') {
        $where .= " AND DATE(j.created_at) <= ? ";
        $params[] = $dateTo;
        $types .= "s";
    }
}

$now = new DateTime();
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
// Query: Jobs + Client + Category + Active Assignment + Worker
// ---------------------------
$sql = "
    SELECT
        j.*,
        u.name  AS client_name,
        u.email AS client_email,
        c.name  AS category_name,
        sc.name AS sub_category_name,
        aa.worker_id AS assigned_worker_id,
        CASE WHEN aa.worker_id IS NULL THEN 0 ELSE 1 END AS has_active_assignment,
        w.full_name AS worker_name,
        w.phone AS worker_phone,
        w.email AS worker_email
    FROM jobs j
    LEFT JOIN users u ON u.id = j.client_id
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
    echo "<div style='padding:16px;color:#b91c1c'>SQL error: " . fm_h($conn->error) . "</div>";
    exit;
}

if (!empty($params))
    $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$jobs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// ---------------------------
// Assign panel: workers list (filters)
// ---------------------------
$assignWorkers = [];
$assignFilters = [
    'w_search' => trim($_GET['w_search'] ?? ''),
    'w_category' => (int) ($_GET['w_category'] ?? 0),
    'w_active' => trim($_GET['w_active'] ?? '1'),
    'w_available' => trim($_GET['w_available'] ?? 'all')
];

if ($openAssignJobId > 0) {
    $wWhere = " WHERE 1=1 ";
    $wParams = [];
    $wTypes = "";

    if ($assignFilters['w_active'] === '1')
        $wWhere .= " AND w.is_active = 1 ";
    elseif ($assignFilters['w_active'] === '0')
        $wWhere .= " AND w.is_active = 0 ";

    if ($assignFilters['w_available'] === '1')
        $wWhere .= " AND w.is_available = 1 ";
    elseif ($assignFilters['w_available'] === '0')
        $wWhere .= " AND w.is_available = 0 ";

    if ($assignFilters['w_search'] !== '') {
        $like = '%' . $assignFilters['w_search'] . '%';
        $wWhere .= " AND (
            w.full_name LIKE ? OR
            w.phone LIKE ? OR
            w.email LIKE ? OR
            w.cnic LIKE ? OR
            w.city LIKE ? OR
            w.area LIKE ?
        ) ";
        $wParams = array_merge($wParams, [$like, $like, $like, $like, $like, $like]);
        $wTypes .= "ssssss";
    }

    if ($assignFilters['w_category'] > 0) {
        $wWhere .= " AND EXISTS (
            SELECT 1 FROM worker_categories wc
            WHERE wc.worker_id = w.id AND wc.category_id = ?
        ) ";
        $wParams[] = $assignFilters['w_category'];
        $wTypes .= "i";
    }

    $wSql = "
        SELECT
            w.*,
            GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS category_names
        FROM workers w
        LEFT JOIN worker_categories wc ON wc.worker_id = w.id
        LEFT JOIN categories c ON c.id = wc.category_id
        $wWhere
        GROUP BY w.id
        ORDER BY w.is_active DESC, w.is_available DESC, w.created_at DESC
        LIMIT 200
    ";
    $stW = $conn->prepare($wSql);
    if ($stW) {
        if (!empty($wParams))
            $stW->bind_param($wTypes, ...$wParams);
        $stW->execute();
        $rw = $stW->get_result();
        $assignWorkers = $rw ? $rw->fetch_all(MYSQLI_ASSOC) : [];
        $stW->close();
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>FixMate Admin — Jobs</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/bold/style.css">
</head>

<body class="bg-slate-50">
    <div class="py-6">

        <div class="mb-4 mx-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-lg font-semibold text-slate-900">Jobs (Admin)</h2>

            <form method="GET" action="admin-dashboard.php" class="w-full">
                <input type="hidden" name="page" value="jobs" />

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-2">
                    <input type="text" name="job_search" value="<?php echo fm_h($jobSearch); ?>"
                        placeholder="Search job (title, desc, location, ID)"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />

                    <input type="text" name="client_search" value="<?php echo fm_h($clientSearch); ?>"
                        placeholder="Search client (name/email)"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />

                    <input type="text" name="worker_search" value="<?php echo fm_h($workerSearch); ?>"
                        placeholder="Search worker (name/phone/email)"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />

                    <select name="status"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                        <option value="live" <?php echo $statusFilter === 'live' ? 'selected' : ''; ?>>Live</option>
                        <option value="assigned" <?php echo $statusFilter === 'assigned' ? 'selected' : ''; ?>>Assigned
                        </option>
                        <option value="worker_coming" <?php echo $statusFilter === 'worker_coming' ? 'selected' : ''; ?>>
                            Worker Coming</option>
                        <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In
                            Progress</option>
                        <option value="waiting_client_confirmation" <?php echo $statusFilter === 'waiting_client_confirmation' ? 'selected' : ''; ?>>Waiting Client
                            Confirmation</option>
                        <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired (by
                            deadline)</option>
                        <option value="unassigned" <?php echo $statusFilter === 'unassigned' ? 'selected' : ''; ?>>
                            Unassigned (no worker)</option>
                        <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed
                        </option>
                        <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled
                        </option>
                        <option value="deleted" <?php echo $statusFilter === 'deleted' ? 'selected' : ''; ?>>Deleted
                        </option>
                    </select>

                    <select id="category_id" name="category_id"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="0">All categories</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo ($categoryId === (int) $c['id']) ? 'selected' : ''; ?>>
                                <?php echo fm_h($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="sub_category_id" name="sub_category_id"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="0">All sub-categories</option>
                    </select>

                    <input type="number" name="budget_min" value="<?php echo fm_h($budgetMin); ?>"
                        placeholder="Min budget"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />

                    <input type="number" name="budget_max" value="<?php echo fm_h($budgetMax); ?>"
                        placeholder="Max budget"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />

                    <select id="date_filter" name="date_filter"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="all" <?php echo $dateFilter === 'all' ? 'selected' : ''; ?>>All time</option>
                        <option value="today" <?php echo $dateFilter === 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="last7" <?php echo $dateFilter === 'last7' ? 'selected' : ''; ?>>Last 7 days
                        </option>
                        <option value="this_month" <?php echo $dateFilter === 'this_month' ? 'selected' : ''; ?>>This
                            month</option>
                        <option value="custom" <?php echo $dateFilter === 'custom' ? 'selected' : ''; ?>>Custom range
                        </option>
                    </select>

                    <select name="attachments"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="all" <?php echo $attachmentsFilter === 'all' ? 'selected' : ''; ?>>All attachments
                        </option>
                        <option value="yes" <?php echo $attachmentsFilter === 'yes' ? 'selected' : ''; ?>>Has attachments
                        </option>
                        <option value="no" <?php echo $attachmentsFilter === 'no' ? 'selected' : ''; ?>>No attachments
                        </option>
                    </select>
                </div>

                <div id="custom_date_wrap"
                    class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2 <?php echo ($dateFilter === 'custom') ? '' : 'hidden'; ?>">
                    <input type="date" name="date_from" value="<?php echo fm_h($dateFrom); ?>"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                    <input type="date" name="date_to" value="<?php echo fm_h($dateTo); ?>"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                </div>

                <div class="mt-3 flex items-center gap-2">
                    <button type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                        <span class="ph-bold ph-magnifying-glass text-sm mr-1"></span>
                        Apply Filters
                    </button>

                    <a href="admin-dashboard.php?page=jobs"
                        class="inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <?php if ($flashError): ?>
            <div class="mx-8 mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                <?php echo fm_h($flashError); ?>
            </div>
        <?php endif; ?>

        <?php if ($flashSuccess): ?>
            <div class="mx-8 mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                <?php echo fm_h($flashSuccess); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($jobs)): ?>
            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500 mx-8">
                No jobs found for the selected filters.
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($jobs as $job): ?>
                    <?php
                    $jobId = (int) ($job['id'] ?? 0);
                    $title = (string) ($job['title'] ?? '');
                    $desc = fm_truncate((string) ($job['description'] ?? ''), 120);

                    $catName = (string) ($job['category_name'] ?? '—');
                    $subName = (string) ($job['sub_category_name'] ?? '');
                    $loc = (string) ($job['location_text'] ?? '');

                    $clientName = (string) ($job['client_name'] ?? '—');
                    $clientEmail = (string) ($job['client_email'] ?? '—');

                    $workerName = (string) ($job['worker_name'] ?? '');
                    $workerPhone = (string) ($job['worker_phone'] ?? '');
                    $assignedWorkerId = (int) ($job['assigned_worker_id'] ?? 0);
                    $hasAssigned = ((int) ($job['has_active_assignment'] ?? 0) === 1);

                    $status = strtolower(trim((string) ($job['status'] ?? 'live')));
                    $isFinal = in_array($status, ['completed', 'cancelled', 'deleted'], true);

                    $showWorkerComingBtn = (!$isFinal && $hasAssigned && $status === 'assigned');
                    $showInProgressBtn = (!$isFinal && $hasAssigned && $status === 'worker_coming');
                    $showMarkDoneBtn = (!$isFinal && $hasAssigned && in_array($status, ['in_progress', 'worker_coming'], true));

                    [$clientDoneFlag, $workerDoneFlag] = fm_get_done_flags($job);
                    $needsAdminConfirm = !($clientDoneFlag && $workerDoneFlag);
                    $showConfirmJobDoneBtn = $needsAdminConfirm && !in_array($status, ['cancelled', 'deleted'], true);

                    [$badgeText, $badgeClass] = fm_status_badge($job, $now);
                    $statusHtml = fm_badge_html($badgeText, $badgeClass);

                    $handshake = fm_handshake_badge($job);

                    $baseQS = $_GET;
                    $baseQS['page'] = 'jobs';
                    $openQS = $baseQS;
                    $openQS['assign_job_id'] = $jobId;
                    $openUrl = "admin-dashboard.php?" . http_build_query($openQS);

                    $closeQS = $baseQS;
                    unset($closeQS['assign_job_id'], $closeQS['w_search'], $closeQS['w_category'], $closeQS['w_active'], $closeQS['w_available']);
                    $closeUrl = "admin-dashboard.php?" . http_build_query($closeQS);

                    $defaultCat = 0;
                    if ((int) ($job['category_id'] ?? 0) > 0)
                        $defaultCat = (int) $job['category_id'];
                    ?>

                    <div class="border border-slate-200 mx-8 rounded-xl p-4 flex flex-col gap-3 bg-white">
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-3">
                            <div class="max-w-full sm:max-w-[72%]">
                                <h3 class="text-sm font-semibold text-slate-900">
                                    #<?php echo (int) $jobId; ?> — <?php echo fm_h($title); ?>
                                </h3>

                                <?php if ($desc !== ''): ?>
                                    <p class="text-xs text-slate-600 mt-1"><?php echo fm_h($desc); ?></p>
                                <?php endif; ?>

                                <p class="text-xs text-slate-500 mt-1">
                                    <?php echo fm_h($catName); ?>
                                    <?php if ($subName !== ''): ?> • <?php echo fm_h($subName); ?><?php endif; ?>
                                    <?php if ($loc !== ''): ?> • <?php echo fm_h($loc); ?><?php endif; ?>
                                </p>

                                <p class="text-[11px] text-slate-400 mt-1">
                                    Client: <?php echo fm_h($clientName); ?> (<?php echo fm_h($clientEmail); ?>)
                                    • Budget: <?php echo (int) ($job['budget'] ?? 0); ?> PKR
                                    • Posted:
                                    <?php echo !empty($job['created_at']) ? date('M d, Y', strtotime($job['created_at'])) : '—'; ?>
                                    <?php if (!empty($job['expires_at']) && $job['expires_at'] !== '0000-00-00 00:00:00'): ?>
                                        • Deadline: <?php echo date('M d, Y', strtotime($job['expires_at'])); ?>
                                    <?php endif; ?>
                                </p>

                                <?php if ($workerName !== ''): ?>
                                    <p class="text-[11px] text-slate-500 mt-2">
                                        Assigned Worker: <span class="font-semibold"><?php echo fm_h($workerName); ?></span>
                                        <?php if ($workerPhone !== ''): ?> • <?php echo fm_h($workerPhone); ?><?php endif; ?>
                                    </p>
                                <?php else: ?>
                                    <p class="text-[11px] text-slate-500 mt-2">
                                        Assigned Worker: <span class="font-semibold text-slate-400">—</span>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div class="flex flex-col items-end gap-2">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <?php echo $statusHtml; ?>
                                    <?php if ($handshake): ?>
                                        <?php echo fm_badge_html($handshake[0], $handshake[1]); ?>
                                    <?php endif; ?>
                                </div>

                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="admin-dashboard.php?page=job-detail&job_id=<?php echo $jobId; ?>"
                                        class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-slate-50">
                                        View
                                    </a>

                                    <?php if (!$isFinal): ?>
                                        <?php if ($hasAssigned): ?>
                                            <a href="<?php echo fm_h($openUrl); ?>"
                                                class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-slate-50">
                                                Change Worker
                                            </a>

                                            <button type="button"
                                                class="text-xs px-3 py-1 rounded-md border border-rose-200 text-rose-600 hover:bg-rose-50"
                                                onclick="openConfirmModal('unassign', <?php echo $jobId; ?>, 'Unassign worker?', 'This will unassign current worker from job #<?php echo $jobId; ?>.');">
                                                Unassign
                                            </button>

                                            <?php if ($showWorkerComingBtn): ?>
                                                <button type="button"
                                                    class="text-xs px-3 py-1 rounded-md border border-amber-200 text-amber-700 hover:bg-amber-50"
                                                    onclick="openStageModal(<?php echo $jobId; ?>, 'worker_coming', 'Worker Coming', 'Notify client that worker is coming soon today.');">
                                                    Worker Coming
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($showInProgressBtn): ?>
                                                <button type="button"
                                                    class="text-xs px-3 py-1 rounded-md border border-violet-200 text-violet-700 hover:bg-violet-50"
                                                    onclick="openStageModal(<?php echo $jobId; ?>, 'in_progress', 'In Progress', 'Mark job as in progress.');">
                                                    In Progress
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($showMarkDoneBtn): ?>
                                                <button type="button"
                                                    class="text-xs px-3 py-1 rounded-md bg-indigo-600 text-white hover:bg-indigo-700"
                                                    onclick="openConfirmModal('worker_done', <?php echo $jobId; ?>, 'Mark worker done?', 'Admin will mark worker done for job #<?php echo $jobId; ?>. If client already confirmed, job will be completed.');">
                                                    Mark Done (Worker/Admin)
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <a href="<?php echo fm_h($openUrl); ?>"
                                                class="text-xs px-3 py-1 rounded-md bg-indigo-600 text-white hover:bg-indigo-700">
                                                Assign Worker
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <!-- ✅ ALWAYS allow Confirm Job Done even if status is completed (but handshake pending) -->
                                    <?php if ($showConfirmJobDoneBtn): ?>
                                        <button type="button"
                                            class="text-xs px-3 py-1 rounded-md bg-emerald-600 text-white hover:bg-emerald-700"
                                            onclick="openConfirmModal('admin_confirm_done', <?php echo $jobId; ?>, 'Confirm job done?', 'Admin will force complete handshake for job #<?php echo $jobId; ?> and mark it Completed.');">
                                            Confirm Job Done
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Assign/Reassign Panel -->
                        <?php if ($openAssignJobId === $jobId): ?>
                            <?php
                            $keepKeys = [
                                'job_search',
                                'client_search',
                                'worker_search',
                                'status',
                                'category_id',
                                'sub_category_id',
                                'budget_min',
                                'budget_max',
                                'date_filter',
                                'date_from',
                                'date_to',
                                'attachments',
                                'assign_job_id'
                            ];
                            ?>
                            <div class="mt-2 border-t border-slate-100 pt-4">
                                <div class="flex items-center justify-between flex-wrap gap-2">
                                    <h4 class="text-sm font-semibold text-slate-900">
                                        <?php echo $hasAssigned ? 'Change Worker' : 'Assign Worker'; ?> — Job #<?php echo $jobId; ?>
                                    </h4>
                                    <a href="<?php echo fm_h($closeUrl); ?>"
                                        class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-slate-50">
                                        Close
                                    </a>
                                </div>

                                <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                                    <div class="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs text-slate-600">
                                            <span class="font-semibold text-slate-800">Title:</span> <?php echo fm_h($title); ?>
                                        </p>
                                        <p class="text-xs text-slate-600 mt-1">
                                            <span class="font-semibold text-slate-800">Category:</span>
                                            <?php echo fm_h($catName); ?>            <?php if ($subName !== ''): ?> •
                                                <?php echo fm_h($subName); ?>            <?php endif; ?>
                                        </p>
                                        <p class="text-xs text-slate-600 mt-1">
                                            <span class="font-semibold text-slate-800">Client:</span>
                                            <?php echo fm_h($clientName); ?> (<?php echo fm_h($clientEmail); ?>)
                                        </p>
                                        <p class="text-xs text-slate-600 mt-1">
                                            <span class="font-semibold text-slate-800">Current Worker:</span>
                                            <?php if ($hasAssigned): ?>
                                                #<?php echo (int) $assignedWorkerId; ?> — <?php echo fm_h($workerName); ?>
                                                <?php if ($workerPhone): ?> • <?php echo fm_h($workerPhone); ?><?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-slate-400">Not assigned</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>

                                    <form method="GET" action="admin-dashboard.php"
                                        class="rounded-lg border border-slate-200 bg-white p-3">
                                        <input type="hidden" name="page" value="jobs">
                                        <input type="hidden" name="assign_job_id" value="<?php echo $jobId; ?>">

                                        <?php foreach ($keepKeys as $k): ?>
                                            <?php if ($k !== 'assign_job_id' && isset($_GET[$k]) && $_GET[$k] !== ''): ?>
                                                <input type="hidden" name="<?php echo fm_h($k); ?>" value="<?php echo fm_h($_GET[$k]); ?>">
                                            <?php endif; ?>
                                        <?php endforeach; ?>

                                        <label class="text-xs font-semibold text-slate-700">Worker search</label>
                                        <input type="text" name="w_search"
                                            value="<?php echo fm_h($assignFilters['w_search'] ?? ''); ?>"
                                            placeholder="name, phone, email, cnic, city, area…"
                                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />

                                        <div class="grid grid-cols-1 gap-2 mt-2">
                                            <div>
                                                <label class="text-xs font-semibold text-slate-700">Category</label>
                                                <select name="w_category"
                                                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                                    <option value="0">All categories</option>
                                                    <?php foreach ($categories as $c): ?>
                                                        <?php
                                                        $cid = (int) $c['id'];
                                                        $selectedWCat = (int) ($assignFilters['w_category'] ?? 0);
                                                        if ($selectedWCat <= 0 && $defaultCat > 0)
                                                            $selectedWCat = $defaultCat;
                                                        ?>
                                                        <option value="<?php echo $cid; ?>" <?php echo ($selectedWCat === $cid) ? 'selected' : ''; ?>>
                                                            <?php echo fm_h($c['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="grid grid-cols-2 gap-2">
                                                <div>
                                                    <label class="text-xs font-semibold text-slate-700">Active</label>
                                                    <select name="w_active"
                                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                                        <option value="1" <?php echo ($assignFilters['w_active'] ?? '1') === '1' ? 'selected' : ''; ?>>Active only</option>
                                                        <option value="all" <?php echo ($assignFilters['w_active'] ?? '1') === 'all' ? 'selected' : ''; ?>>All</option>
                                                        <option value="0" <?php echo ($assignFilters['w_active'] ?? '1') === '0' ? 'selected' : ''; ?>>Inactive only</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="text-xs font-semibold text-slate-700">Available</label>
                                                    <select name="w_available"
                                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                                        <option value="all" <?php echo ($assignFilters['w_available'] ?? 'all') === 'all' ? 'selected' : ''; ?>>All</option>
                                                        <option value="1" <?php echo ($assignFilters['w_available'] ?? 'all') === '1' ? 'selected' : ''; ?>>Available</option>
                                                        <option value="0" <?php echo ($assignFilters['w_available'] ?? 'all') === '0' ? 'selected' : ''; ?>>Not available</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-3 flex items-center justify-between gap-2">
                                            <span class="text-[11px] text-slate-400">Showing
                                                <?php echo (int) count($assignWorkers); ?> workers</span>
                                            <button type="submit"
                                                class="text-xs px-3 py-1 rounded-md bg-indigo-600 text-white hover:bg-indigo-700">Apply</button>
                                        </div>
                                    </form>
                                </div>

                                <div class="mt-3">
                                    <?php if (empty($assignWorkers)): ?>
                                        <div
                                            class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500">
                                            No workers found for selected filters.
                                        </div>
                                    <?php else: ?>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <?php foreach ($assignWorkers as $w): ?>
                                                <?php
                                                $wid = (int) ($w['id'] ?? 0);
                                                $wName = (string) ($w['full_name'] ?? '—');
                                                $wPhone = (string) ($w['phone'] ?? '');
                                                $wEmail = (string) ($w['email'] ?? '');
                                                $wCnic = (string) ($w['cnic'] ?? '');
                                                $wCity = (string) ($w['city'] ?? '');
                                                $wArea = (string) ($w['area'] ?? '');

                                                $wActive = ((int) ($w['is_active'] ?? 0) === 1);
                                                $wAvail = ((int) ($w['is_available'] ?? 0) === 1);

                                                $cats = trim((string) ($w['category_names'] ?? ''));
                                                if ($cats === '')
                                                    $cats = '—';

                                                $wAvatar = trim((string) ($w['profile_image'] ?? ''));
                                                if ($wAvatar === '')
                                                    $wAvatar = '/fixmate/assets/images/avatar-default.png';

                                                $b1 = $wActive
                                                    ? fm_badge_html('Active', 'bg-emerald-100 text-emerald-700 border-emerald-200')
                                                    : fm_badge_html('Inactive', 'bg-rose-100 text-rose-700 border-rose-200');

                                                $b2 = $wAvail
                                                    ? fm_badge_html('Available', 'bg-sky-100 text-sky-700 border-sky-200')
                                                    : fm_badge_html('Not available', 'bg-slate-200 text-slate-700 border-slate-300');
                                                ?>
                                                <div class="rounded-xl border border-slate-200 bg-white p-3 flex gap-3">
                                                    <img src="<?php echo fm_h($wAvatar); ?>"
                                                        class="w-12 h-12 rounded-full object-cover border border-slate-200" alt="Worker">
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <div class="min-w-0">
                                                                <p class="text-sm font-semibold text-slate-900 truncate">
                                                                    #<?php echo $wid; ?> — <?php echo fm_h($wName); ?>
                                                                </p>
                                                                <p class="text-xs text-slate-600 truncate">
                                                                    <?php echo fm_h($wPhone); ?>                    <?php if ($wEmail): ?> •
                                                                        <?php echo fm_h($wEmail); ?>                    <?php endif; ?>
                                                                </p>
                                                            </div>
                                                            <div class="flex flex-wrap justify-end gap-2">
                                                                <?php echo $b1; ?>
                                                                <?php echo $b2; ?>
                                                            </div>
                                                        </div>

                                                        <p class="text-[11px] text-slate-500 mt-1">
                                                            <?php echo fm_h($wCity); ?>                    <?php echo ($wCity && $wArea) ? ' • ' : ''; ?>                    <?php echo fm_h($wArea); ?>
                                                            <?php if ($wCnic !== ''): ?> • CNIC: <?php echo fm_h($wCnic); ?><?php endif; ?>
                                                        </p>

                                                        <p class="text-[11px] text-slate-400 mt-1">
                                                            Categories: <?php echo fm_h($cats); ?>
                                                        </p>

                                                        <div class="mt-2 flex justify-end">
                                                            <button type="button"
                                                                class="text-xs px-3 py-1 rounded-md bg-indigo-600 text-white hover:bg-indigo-700"
                                                                onclick="openAssignModal(<?php echo $jobId; ?>, <?php echo $wid; ?>, '<?php echo fm_h($wName); ?>')">
                                                                <?php echo $hasAssigned ? 'Assign & Replace' : 'Assign this worker'; ?>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                            </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Assign Confirm Modal -->
        <div id="assignModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-lg w-full max-w-sm p-5">
                <h3 id="assignTitle" class="text-sm font-semibold text-slate-900 mb-2">Confirm Assignment</h3>
                <p id="assignDesc" class="text-xs text-slate-600 mb-3"></p>

                <form method="POST" class="space-y-2">
                    <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
                    <input type="hidden" name="action" value="assign_worker">
                    <input type="hidden" name="job_id" id="assign_job_id" value="0">
                    <input type="hidden" name="worker_id" id="assign_worker_id" value="0">

                    <label class="text-xs font-semibold text-slate-700">Reason (optional)</label>
                    <input type="text" name="reason" placeholder="e.g. best match for category"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />

                    <div class="flex justify-end gap-2 text-xs pt-2">
                        <button type="button"
                            class="px-3 py-1 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50"
                            onclick="closeAssignModal()">
                            Cancel
                        </button>
                        <button type="submit" class="px-3 py-1 rounded-md bg-indigo-600 text-white hover:bg-indigo-700">
                            Confirm
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Confirm Modal -->
        <div id="confirmModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-lg w-full max-w-sm p-5">
                <h3 id="confirmTitle" class="text-sm font-semibold text-slate-900 mb-2">Confirm</h3>
                <p id="confirmDesc" class="text-xs text-slate-600 mb-3"></p>

                <form method="POST" class="space-y-2">
                    <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
                    <input type="hidden" name="action" id="confirm_action" value="">
                    <input type="hidden" name="job_id" id="confirm_job_id" value="0">

                    <label class="text-xs font-semibold text-slate-700">Reason (optional)</label>
                    <input type="text" name="reason" placeholder="optional note"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />

                    <div class="flex justify-end gap-2 text-xs pt-2">
                        <button type="button"
                            class="px-3 py-1 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50"
                            onclick="closeConfirmModal()">
                            Cancel
                        </button>
                        <button type="submit" id="confirmBtn"
                            class="px-3 py-1 rounded-md bg-rose-600 text-white hover:bg-rose-700">
                            Confirm
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Stage Modal -->
        <div id="stageModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-lg w-full max-w-sm p-5">
                <h3 id="stageTitle" class="text-sm font-semibold text-slate-900 mb-2">Set Status</h3>
                <p id="stageDesc" class="text-xs text-slate-600 mb-3"></p>

                <form method="POST" class="space-y-2">
                    <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
                    <input type="hidden" name="action" value="set_stage">
                    <input type="hidden" name="job_id" id="stage_job_id" value="0">
                    <input type="hidden" name="stage" id="stage_value" value="">

                    <label class="text-xs font-semibold text-slate-700">Note (optional)</label>
                    <input type="text" name="note" placeholder="optional note"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />

                    <div class="flex justify-end gap-2 text-xs pt-2">
                        <button type="button"
                            class="px-3 py-1 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50"
                            onclick="closeStageModal()">
                            Cancel
                        </button>
                        <button type="submit" class="px-3 py-1 rounded-md bg-indigo-600 text-white hover:bg-indigo-700">
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const df = document.getElementById('date_filter');
            const wrap = document.getElementById('custom_date_wrap');
            if (!df || !wrap) return;
            df.addEventListener('change', function () {
                if (this.value === 'custom') wrap.classList.remove('hidden');
                else wrap.classList.add('hidden');
            });
        });

        document.addEventListener('DOMContentLoaded', function () {
            const cat = document.getElementById('category_id');
            const sub = document.getElementById('sub_category_id');
            if (!cat || !sub) return;

            async function loadSubcats(categoryId, selectedId) {
                sub.innerHTML = '<option value="0">All sub-categories</option>';
                if (!categoryId || parseInt(categoryId, 10) <= 0) return;

                try {
                    const url = "admin-dashboard.php?page=jobs&ajax=subcategories&category_id=" + encodeURIComponent(categoryId);
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    const json = await res.json();
                    const rows = (json && json.data) ? json.data : [];
                    rows.forEach(r => {
                        const opt = document.createElement('option');
                        opt.value = r.id;
                        opt.textContent = r.name;
                        if (parseInt(selectedId, 10) === parseInt(r.id, 10)) opt.selected = true;
                        sub.appendChild(opt);
                    });
                } catch (e) { }
            }

            const initialCat = "<?php echo (int) $categoryId; ?>";
            const initialSub = "<?php echo (int) $subCategoryId; ?>";
            if (parseInt(initialCat, 10) > 0) loadSubcats(initialCat, initialSub);

            cat.addEventListener('change', function () {
                loadSubcats(this.value, 0);
            });
        });

        function openAssignModal(jobId, workerId, workerName) {
            const m = document.getElementById('assignModal');
            document.getElementById('assign_job_id').value = jobId;
            document.getElementById('assign_worker_id').value = workerId;
            document.getElementById('assignTitle').textContent = 'Confirm Assignment';
            document.getElementById('assignDesc').textContent = `Assign worker "${workerName}" to job #${jobId}?`;
            m.classList.remove('hidden');
            m.classList.add('flex');
        }
        function closeAssignModal() {
            const m = document.getElementById('assignModal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        function openConfirmModal(kind, jobId, title, desc) {
            const m = document.getElementById('confirmModal');
            const a = document.getElementById('confirm_action');
            const jid = document.getElementById('confirm_job_id');
            const btn = document.getElementById('confirmBtn');

            jid.value = jobId;

            if (kind === 'unassign') {
                a.value = 'unassign_worker';
                btn.className = 'px-3 py-1 rounded-md bg-rose-600 text-white hover:bg-rose-700';
                btn.textContent = 'Unassign';
            } else if (kind === 'worker_done') {
                a.value = 'mark_worker_done_admin';
                btn.className = 'px-3 py-1 rounded-md bg-indigo-600 text-white hover:bg-indigo-700';
                btn.textContent = 'Mark Done';
            } else if (kind === 'admin_confirm_done') {
                a.value = 'admin_confirm_done';
                btn.className = 'px-3 py-1 rounded-md bg-emerald-600 text-white hover:bg-emerald-700';
                btn.textContent = 'Confirm Done';
            } else {
                a.value = '';
                btn.className = 'px-3 py-1 rounded-md bg-rose-600 text-white hover:bg-rose-700';
                btn.textContent = 'Confirm';
            }

            document.getElementById('confirmTitle').textContent = title || 'Confirm';
            document.getElementById('confirmDesc').textContent = desc || '';

            m.classList.remove('hidden');
            m.classList.add('flex');
        }
        function closeConfirmModal() {
            const m = document.getElementById('confirmModal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        function openStageModal(jobId, stage, title, desc) {
            const m = document.getElementById('stageModal');
            document.getElementById('stage_job_id').value = jobId;
            document.getElementById('stage_value').value = stage;
            document.getElementById('stageTitle').textContent = title || 'Set Status';
            document.getElementById('stageDesc').textContent = desc || '';
            m.classList.remove('hidden');
            m.classList.add('flex');
        }
        function closeStageModal() {
            const m = document.getElementById('stageModal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }
    </script>
</body>

</html>