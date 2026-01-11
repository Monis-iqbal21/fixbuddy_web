<?php
// /fixmate/pages/dashboards/client/job-detail.php

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

$clientId = (int) ($_SESSION['user_id'] ?? 0);
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
// Detect table columns (schema-safe)
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

$REV_COLS = [];
try {
    $rc3 = $conn->query("SHOW COLUMNS FROM reviews");
    if ($rc3) {
        while ($row = $rc3->fetch_assoc()) {
            $REV_COLS[strtolower((string) $row['Field'])] = true;
        }
    }
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
    $clientDone = fm_bool_from_row($job, [
        'client_mark_done',
        'client_done',
        'client_mark_done_at',
        'client_done_at',
        'client_marked_done',
    ]);

    $workerDone = fm_bool_from_row($job, [
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

function fm_redirect(string $url): void
{
    echo "<script>window.location.href=" . json_encode($url) . ";</script>";
    exit;
}

// ---------------------------
// Notifications: send to ALL admins (schema-safe)
// ---------------------------
$getAdminIds = function () use ($conn): array {
    $ids = [];
    try {
        $st = $conn->prepare("SELECT id FROM users WHERE LOWER(role) = 'admin'");
        if ($st) {
            $st->execute();
            $r = $st->get_result();
            if ($r) {
                while ($row = $r->fetch_assoc())
                    $ids[] = (int) ($row['id'] ?? 0);
            }
            $st->close();
        }
    } catch (Throwable $e) {
    }
    return array_values(array_filter($ids, fn($v) => $v > 0));
};

$notifyAdmins = function (int $jobId, string $type, string $title, string $body, ?string $link = null) use ($conn, $NOTIF_COLS, $getAdminIds): void {
    try {
        $adminIds = $getAdminIds();
        if (empty($adminIds))
            return;

        if ($link === null || $link === '') {
            // adjust admin link if your admin uses a different route
            $link = "/fixmate/pages/dashboards/admin/admin-dashboard.php?page=job-detail&job_id=" . (int) $jobId;
        }

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
// Fetch Job + Category Names
// ---------------------------
$stmt = $conn->prepare("
    SELECT 
        j.*,
        c.name  AS category_name,
        sc.name AS sub_category_name
    FROM jobs j
    LEFT JOIN categories c ON c.id = j.category_id
    LEFT JOIN sub_categories sc ON sc.id = j.sub_category_id
    WHERE j.id = ? AND j.client_id = ?
    LIMIT 1
");
if (!$stmt) {
    echo "<p class='text-red-600 p-4'>SQL error: " . fm_h($conn->error) . "</p>";
    return;
}
$stmt->bind_param("ii", $jobId, $clientId);
$stmt->execute();
$res = $stmt->get_result();
$job = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$job) {
    echo "<p class='text-red-600 p-4'>Job not found or not yours.</p>";
    return;
}

// ---------------------------
// Active Assignment + Worker
// ---------------------------
$assignment = null;
$stmtW = $conn->prepare("
    SELECT 
        jwa.*,
        w.full_name,
        w.phone,
        w.email,
        w.city,
        w.area,
        w.address,
        w.profile_image,
        w.rating_avg,
        w.jobs_completed
    FROM job_worker_assignments jwa
    LEFT JOIN workers w ON w.id = jwa.worker_id
    WHERE jwa.job_id = ?
      AND (jwa.ended_at IS NULL OR jwa.ended_at = '' OR jwa.ended_at = '0000-00-00 00:00:00')
    ORDER BY jwa.id DESC
    LIMIT 1
");
if ($stmtW) {
    $stmtW->bind_param("i", $jobId);
    $stmtW->execute();
    $rw = $stmtW->get_result();
    $assignment = $rw ? $rw->fetch_assoc() : null;
    $stmtW->close();
}
$assignedWorkerId = $assignment ? (int) ($assignment['worker_id'] ?? 0) : 0;

// ---------------------------
// Fetch Attachments
// job_attachments: id, job_id, file_type, file_url, created_at
// ---------------------------
$attachments = [];
$stmtA = $conn->prepare("
    SELECT id, file_type, file_url, created_at
    FROM job_attachments
    WHERE job_id = ?
    ORDER BY id DESC
");
if ($stmtA) {
    $stmtA->bind_param("i", $jobId);
    $stmtA->execute();
    $rA = $stmtA->get_result();
    $attachments = $rA ? $rA->fetch_all(MYSQLI_ASSOC) : [];
    $stmtA->close();
}

// ---------------------------
// Determine Final Status (expiry check)
// ---------------------------
$rawStatus = strtolower(trim($job['status'] ?? 'live'));
$finalStatus = $rawStatus;

$now = new DateTime();
if (fm_compute_is_expired($job, $now) && !in_array($rawStatus, ['completed', 'cancelled', 'deleted'], true)) {
    $finalStatus = 'expired';
}

// ---------------------------
// Badge style mapping
// ---------------------------
$badgeText = ucfirst($finalStatus);
$badgeClass = 'bg-slate-100 text-slate-700 border-slate-200';

switch ($finalStatus) {
    case 'live':
    case 'open':
        $badgeText = 'Live';
        $badgeClass = 'bg-emerald-100 text-emerald-700 border-emerald-200';
        break;

    case 'assigned':
        $badgeText = 'Assigned';
        $badgeClass = 'bg-sky-50 text-sky-800 border-sky-200';
        break;

    case 'worker_coming':
        $badgeText = 'Worker Coming';
        $badgeClass = 'bg-amber-50 text-amber-800 border-amber-200';
        break;

    case 'inprogress':
    case 'in_progress':
        $badgeText = 'In Progress';
        $badgeClass = 'bg-violet-50 text-violet-800 border-violet-200';
        break;

    case 'waiting_client_confirmation':
        $badgeText = 'Waiting Confirmation';
        $badgeClass = 'bg-cyan-50 text-cyan-800 border-cyan-200';
        break;

    case 'expired':
        $badgeText = 'Expired';
        $badgeClass = 'bg-rose-100 text-rose-700 border-rose-200';
        break;

    case 'completed':
        $badgeText = 'Completed';
        $badgeClass = 'bg-indigo-100 text-indigo-700 border-indigo-200';
        break;

    case 'cancelled':
        $badgeText = 'Cancelled';
        $badgeClass = 'bg-slate-200 text-slate-700 border-slate-300';
        break;

    case 'deleted':
        $badgeText = 'Deleted';
        $badgeClass = 'bg-slate-200 text-slate-700 border-slate-300';
        break;
}

// ---------------------------
// Handshake + review state
// ---------------------------
[$clientDone, $workerDone] = fm_get_done_flags($job);
$handshakeComplete = ($clientDone && $workerDone) || ($finalStatus === 'completed');

// Can client mark done from here?
$isExpired = ($finalStatus === 'expired');
$isFinal = in_array($finalStatus, ['completed', 'cancelled', 'deleted'], true);

$canMarkComplete = ($assignedWorkerId > 0)
    && !$isFinal
    && !$isExpired
    && !$clientDone
    && in_array($finalStatus, ['waiting_client_confirmation', 'in_progress', 'inprogress', 'worker_coming'], true);

// ---------------------------
// Review: check existing review for (job_id, client_id)
// ---------------------------
$existingReview = null;
if ($jobId > 0 && $clientId > 0) {
    $stR = $conn->prepare("SELECT * FROM reviews WHERE job_id = ? AND client_id = ? LIMIT 1");
    if ($stR) {
        $stR->bind_param("ii", $jobId, $clientId);
        $stR->execute();
        $rr = $stR->get_result();
        $existingReview = ($rr && $rr->num_rows === 1) ? $rr->fetch_assoc() : null;
        $stR->close();
    }
}

$canReview = $handshakeComplete && !$existingReview && ($assignedWorkerId > 0);

// Auto-open review modal if requested
$autoOpenReview = (isset($_GET['show_review_modal']) && $_GET['show_review_modal'] == '1' && $canReview);

// ---------------------------
// Handle POST actions: client_mark_done / submit_review
// ---------------------------
$flashError = '';
$flashSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($CSRF, $token)) {
        $flashError = "Security check failed (CSRF). Please refresh and try again.";
    } else {

        // Re-fetch fresh job row for safe updates
        $fresh = null;
        $stF = $conn->prepare("SELECT * FROM jobs WHERE id = ? AND client_id = ? LIMIT 1");
        if ($stF) {
            $stF->bind_param("ii", $jobId, $clientId);
            $stF->execute();
            $rf = $stF->get_result();
            $fresh = ($rf && $rf->num_rows === 1) ? $rf->fetch_assoc() : null;
            $stF->close();
        }

        if (!$fresh) {
            $flashError = "Job not found.";
        } else {

            $freshStatus = strtolower(trim((string) ($fresh['status'] ?? 'live')));
            $freshExpired = fm_compute_is_expired($fresh, new DateTime());
            $freshFinal = in_array($freshStatus, ['completed', 'cancelled', 'deleted'], true);

            [$freshClientDone, $freshWorkerDone] = fm_get_done_flags($fresh);

            // Re-check assignment (must be active + worker assigned)
            $freshAssignedWorkerId = 0;
            $stAA = $conn->prepare("
                SELECT worker_id
                FROM job_worker_assignments
                WHERE job_id = ?
                  AND (ended_at IS NULL OR ended_at = '' OR ended_at = '0000-00-00 00:00:00')
                ORDER BY id DESC
                LIMIT 1
            ");
            if ($stAA) {
                $stAA->bind_param("i", $jobId);
                $stAA->execute();
                $ra = $stAA->get_result();
                if ($ra && $ra->num_rows === 1) {
                    $rowA = $ra->fetch_assoc();
                    $freshAssignedWorkerId = (int) ($rowA['worker_id'] ?? 0);
                }
                $stAA->close();
            }

            if ($action === 'client_mark_done') {

                if ($freshFinal) {
                    $flashError = "This job is already finalized.";
                } elseif ($freshExpired) {
                    $flashError = "This job is expired. Contact admin if you need help.";
                } elseif ($freshAssignedWorkerId <= 0) {
                    $flashError = "A worker must be assigned before you can confirm completion.";
                } elseif ($freshClientDone) {
                    $flashSuccess = "You already confirmed completion.";
                } else {

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

                        // ✅ FLOW RULE:
                        // - never set live again
                        // - keep in_progress until both marked done
                        $nextStatus = $freshWorkerDone ? 'completed' : 'in_progress';
                        $set[] = "status = '" . $conn->real_escape_string($nextStatus) . "'";

                        $sqlUp = "UPDATE jobs SET " . implode(", ", $set) . " WHERE id = ? AND client_id = ? LIMIT 1";
                        $up = $conn->prepare($sqlUp);
                        if (!$up)
                            throw new Exception("Prepare failed: " . $conn->error);
                        $up->bind_param("ii", $jobId, $clientId);
                        if (!$up->execute())
                            throw new Exception("Failed to confirm completion.");
                        $up->close();

                        if ($nextStatus === 'completed') {
                            $notifyAdmins(
                                $jobId,
                                "client_mark_done_completed",
                                "Job completed (handshake done)",
                                "Client confirmed completion for job #{$jobId}. Worker already marked done. Status → Completed."
                            );
                            $flashSuccess = "You confirmed completion. Job is now Completed.";
                        } else {
                            $notifyAdmins(
                                $jobId,
                                "client_mark_done",
                                "Client confirmed completion",
                                "Client confirmed completion for job #{$jobId}. Waiting for worker/admin confirmation."
                            );
                            $flashSuccess = "You confirmed completion. Waiting for worker confirmation.";
                        }

                        $conn->commit();

                        // reload cleanly
                        fm_redirect("client-dashboard.php?page=job-detail&job_id=" . (int) $jobId);
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $flashError = $e->getMessage();
                    }
                }
            } elseif ($action === 'submit_review') {

                $rating = (int) ($_POST['rating'] ?? 0);
                $comment = trim((string) ($_POST['comment'] ?? ''));

                if (!$freshAssignedWorkerId) {
                    $flashError = "No worker assigned — cannot submit review.";
                } elseif (!$freshClientDone || !$freshWorkerDone) {
                    $flashError = "You can submit a review only after job completion is confirmed by both sides.";
                } elseif ($rating < 1 || $rating > 5) {
                    $flashError = "Please select a rating (1 to 5).";
                } elseif (mb_strlen($comment) < 2) {
                    $flashError = "Please write a short review message.";
                } else {

                    // prevent duplicates
                    $dup = null;
                    $stD = $conn->prepare("SELECT id FROM reviews WHERE job_id = ? AND client_id = ? LIMIT 1");
                    if ($stD) {
                        $stD->bind_param("ii", $jobId, $clientId);
                        $stD->execute();
                        $rd = $stD->get_result();
                        $dup = ($rd && $rd->num_rows === 1) ? $rd->fetch_assoc() : null;
                        $stD->close();
                    }
                    if ($dup) {
                        $flashSuccess = "Review already submitted.";
                        fm_redirect("client-dashboard.php?page=job-detail&job_id=" . (int) $jobId);
                    }

                    $conn->begin_transaction();
                    try {
                        $cols = [];
                        $ph = [];
                        $vals = [];
                        $types = '';

                        // reviews table shown: id, job_id, worker_id, client_id, rating, comment, created_at
                        // (schema-safe in case you add columns later)
                        if (isset($REV_COLS['job_id'])) {
                            $cols[] = 'job_id';
                            $ph[] = '?';
                            $vals[] = $jobId;
                            $types .= 'i';
                        }
                        if (isset($REV_COLS['worker_id'])) {
                            $cols[] = 'worker_id';
                            $ph[] = '?';
                            $vals[] = $freshAssignedWorkerId;
                            $types .= 'i';
                        }
                        if (isset($REV_COLS['client_id'])) {
                            $cols[] = 'client_id';
                            $ph[] = '?';
                            $vals[] = $clientId;
                            $types .= 'i';
                        }
                        if (isset($REV_COLS['rating'])) {
                            $cols[] = 'rating';
                            $ph[] = '?';
                            $vals[] = $rating;
                            $types .= 'i';
                        }
                        if (isset($REV_COLS['comment'])) {
                            $cols[] = 'comment';
                            $ph[] = '?';
                            $vals[] = $comment;
                            $types .= 's';
                        }
                        if (isset($REV_COLS['created_at'])) {
                            $cols[] = 'created_at';
                            $ph[] = 'NOW()';
                        }

                        if (empty($cols))
                            throw new Exception("Reviews table columns not detected.");

                        $sqlIns = "INSERT INTO reviews (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
                        $ins = $conn->prepare($sqlIns);
                        if (!$ins)
                            throw new Exception("Prepare failed: " . $conn->error);
                        if (!empty($vals))
                            $ins->bind_param($types, ...$vals);
                        if (!$ins->execute())
                            throw new Exception("Failed to submit review.");
                        $ins->close();

                        // notify admin
                        $notifyAdmins(
                            $jobId,
                            "review_submitted",
                            "New review submitted",
                            "Client submitted a {$rating}/5 review for job #{$jobId}."
                        );

                        $conn->commit();

                        // redirect back and hide review button (existingReview will be found)
                        fm_redirect("client-dashboard.php?page=job-detail&job_id=" . (int) $jobId . "&review_submitted=1");
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $flashError = $e->getMessage();
                    }
                }
            }
        }
    }
}

// Refresh local vars after possible POST (simple page load)
$title = (string) ($job['title'] ?? '');
$desc = (string) ($job['description'] ?? '');
$loc = (string) ($job['location_text'] ?? '');
$budget = (int) ($job['budget'] ?? 0);
$categoryName = (string) ($job['category_name'] ?? '—');
$subCategoryName = (string) ($job['sub_category_name'] ?? '');
?>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/phosphor-icons"></script>

<div class="px-4 sm:px-8 py-6">
    <div class="max-w-5xl">

        <!-- Top bar -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">Job Details</h2>
                <p class="text-sm text-slate-500">View complete information about your job post.</p>
            </div>

            <div class="flex flex-wrap gap-2 items-center">
                <a href="client-dashboard.php?page=jobs-status"
                    class="text-xs px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50">
                    Back to Jobs
                </a>

                <a href="client-dashboard.php?page=post-job&job_id=<?php echo (int) $jobId; ?>"
                    class="text-xs px-3 py-1.5 rounded-lg border border-indigo-200 text-indigo-700 hover:bg-indigo-50">
                    Edit Job
                </a>

                <?php if ($canReview): ?>
                    <button type="button" id="openReviewBtn"
                        class="text-xs px-3 py-1.5 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">
                        Leave Review
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flashError): ?>
            <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                <?php echo fm_h($flashError); ?>
            </div>
        <?php endif; ?>

        <?php if ($flashSuccess): ?>
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                <?php echo fm_h($flashSuccess); ?>
            </div>
        <?php endif; ?>

        <!-- Main Card -->
        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 sm:p-6">

            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="text-lg font-semibold text-slate-900 break-words"><?php echo fm_h($title); ?></h3>

                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-600">
                        <span class="inline-flex items-center gap-1">
                            <span class="ph ph-tag"></span>
                            <?php echo fm_h($categoryName); ?>
                            <?php if ($subCategoryName): ?>
                                <span class="text-slate-300">•</span>
                                <?php echo fm_h($subCategoryName); ?>
                            <?php endif; ?>
                        </span>

                        <span class="text-slate-300">•</span>

                        <span class="inline-flex items-center gap-1">
                            <span class="ph ph-map-pin"></span>
                            <?php echo $loc ? fm_h($loc) : 'No location'; ?>
                        </span>
                    </div>
                </div>

                <div class="flex flex-col items-start sm:items-end gap-2">
                    <span
                        class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium <?php echo $badgeClass; ?>">
                        <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span>
                        <?php echo fm_h($badgeText); ?>
                    </span>

                    <?php if ($workerDone && !$clientDone && !$isFinal && !$isExpired): ?>
                        <span
                            class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium bg-cyan-50 text-cyan-800 border-cyan-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span>
                            Waiting Your Confirmation
                        </span>
                    <?php elseif ($clientDone && !$workerDone && !$isFinal && !$isExpired): ?>
                        <span
                            class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium bg-sky-50 text-sky-800 border-sky-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span>
                            Waiting Worker Confirmation
                        </span>
                    <?php elseif ($handshakeComplete): ?>
                        <span
                            class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium bg-indigo-100 text-indigo-700 border-indigo-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span>
                            Handshake Completed
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Meta -->
            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-[11px] text-slate-500">Budget</p>
                    <p class="text-sm font-semibold text-slate-900"><?php echo number_format($budget); ?> PKR</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-[11px] text-slate-500">Posted</p>
                    <p class="text-sm font-semibold text-slate-900">
                        <?php echo fm_format_date($job['created_at'] ?? ''); ?></p>
                </div>
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-[11px] text-slate-500">Deadline</p>
                    <p class="text-sm font-semibold text-slate-900">
                        <?php echo fm_format_date($job['expires_at'] ?? ''); ?></p>
                </div>
            </div>

            <?php if ($canMarkComplete): ?>
                <div
                    class="mt-4 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="text-sm text-emerald-900">
                        <span class="font-semibold">Confirm completion:</span>
                        If the worker already marked done, job becomes <b>Completed</b>. Otherwise it stays <b>In
                            Progress</b>.
                    </div>

                    <button type="button"
                        class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                        onclick="openConfirmDoneModal()">
                        Confirm
                    </button>
                </div>
            <?php endif; ?>

            <!-- Description -->
            <div class="mt-5">
                <h4 class="text-sm font-semibold text-slate-900 mb-2">Job Description</h4>
                <div
                    class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 whitespace-pre-line">
                    <?php echo $desc ? fm_h($desc) : '—'; ?>
                </div>
            </div>

            <!-- Assigned worker -->
            <div class="mt-6">
                <h4 class="text-sm font-semibold text-slate-900 mb-2">Assigned Worker</h4>

                <?php if ($assignment && !empty($assignment['worker_id'])): ?>
                    <?php
                    $workerName = $assignment['full_name'] ?? ('Worker #' . (int) $assignment['worker_id']);
                    $workerPhone = $assignment['phone'] ?? '';
                    $workerEmail = $assignment['email'] ?? '';
                    $workerCity = $assignment['city'] ?? '';
                    $workerArea = $assignment['area'] ?? '';
                    $workerAddress = $assignment['address'] ?? '';
                    $ratingAvg = $assignment['rating_avg'] ?? '';
                    $jobsCompleted = $assignment['jobs_completed'] ?? '';

                    $profileImg = trim((string) ($assignment['profile_image'] ?? ''));
                    $profileUrl = $profileImg !== '' ? $profileImg : '';
                    ?>

                    <div class="rounded-xl border border-slate-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3 min-w-0">
                                <div
                                    class="w-12 h-12 rounded-full border border-slate-200 bg-slate-50 overflow-hidden flex-shrink-0">
                                    <?php if ($profileUrl): ?>
                                        <img src="<?php echo fm_h($profileUrl); ?>" class="w-full h-full object-cover"
                                            alt="Worker">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center text-slate-400">
                                            <span class="ph ph-user"></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-900 truncate">
                                        <?php echo fm_h($workerName); ?></p>

                                    <p class="text-xs text-slate-500 mt-1">
                                        Assigned at: <?php echo fm_format_dt($assignment['assigned_at'] ?? ''); ?>
                                    </p>

                                    <?php if ($workerCity || $workerArea): ?>
                                        <p class="text-xs text-slate-500 mt-1">
                                            <?php echo fm_h(trim($workerCity . ($workerCity && $workerArea ? ' • ' : '') . $workerArea)); ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if ($workerAddress): ?>
                                        <p class="text-xs text-slate-500 mt-1"><?php echo fm_h($workerAddress); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="text-right text-xs text-slate-600 space-y-1 flex-shrink-0">
                                <?php if ($workerPhone !== ''): ?>
                                    <div class="flex items-center justify-end gap-2">
                                        <span class="ph ph-phone"></span>
                                        <span><?php echo fm_h($workerPhone); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($workerEmail !== ''): ?>
                                    <div class="flex items-center justify-end gap-2">
                                        <span class="ph ph-envelope"></span>
                                        <span><?php echo fm_h($workerEmail); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($ratingAvg !== '' || $jobsCompleted !== ''): ?>
                                    <div class="mt-2 text-[11px] text-slate-500">
                                        <?php if ($ratingAvg !== ''): ?>
                                            Rating: <span
                                                class="font-semibold text-slate-700"><?php echo fm_h($ratingAvg); ?></span>
                                        <?php endif; ?>
                                        <?php if ($ratingAvg !== '' && $jobsCompleted !== ''): ?> • <?php endif; ?>
                                        <?php if ($jobsCompleted !== ''): ?>
                                            Jobs: <span
                                                class="font-semibold text-slate-700"><?php echo fm_h($jobsCompleted); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($assignment['reason'])): ?>
                            <div class="mt-3 text-xs text-slate-600">
                                <span class="font-semibold">Reason:</span> <?php echo fm_h($assignment['reason']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div
                        class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                        No worker assigned yet.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Attachments -->
            <div class="mt-6">
                <h4 class="text-sm font-semibold text-slate-900 mb-2">Attachments</h4>

                <?php if (!empty($attachments)): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <?php foreach ($attachments as $a): ?>
                            <?php
                            $type = strtolower(trim((string) ($a['file_type'] ?? 'file')));
                            $url = (string) ($a['file_url'] ?? '');
                            if ($url === '')
                                continue;

                            // labels (no filename shown)
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
                                        <button type="button" class="w-full"
                                            onclick="openMediaModal('image', <?php echo json_encode($url); ?>)">
                                            <img src="<?php echo fm_h($url); ?>"
                                                class="w-full h-36 object-cover rounded-lg border border-slate-200"
                                                alt="attachment">
                                        </button>

                                    <?php elseif ($type === 'video'): ?>
                                        <button type="button" class="w-full"
                                            onclick="openMediaModal('video', <?php echo json_encode($url); ?>)">
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
                                                onclick="openMediaModal('audio', <?php echo json_encode($url); ?>)">
                                                Open voice note
                                            </button>
                                        </div>

                                    <?php else: ?>
                                        <a href="<?php echo fm_h($url); ?>" target="_blank"
                                            class="inline-flex items-center gap-2 text-xs text-indigo-700 hover:underline">
                                            <span class="ph ph-paperclip"></span>
                                            Open file
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
                    <div
                        class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                        No attachments for this job.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Review summary (if exists) -->
            <?php if ($existingReview): ?>
                <div class="mt-6">
                    <h4 class="text-sm font-semibold text-slate-900 mb-2">Your Review</h4>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-sm text-slate-900 font-semibold">
                            Rating: <?php echo (int) ($existingReview['rating'] ?? 0); ?>/5
                        </div>
                        <div class="mt-2 text-sm text-slate-700 whitespace-pre-line">
                            <?php echo fm_h($existingReview['comment'] ?? ''); ?>
                        </div>
                        <div class="mt-2 text-[11px] text-slate-500">
                            Submitted: <?php echo fm_format_dt($existingReview['created_at'] ?? ''); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- Full View Media Modal -->
<div id="mediaModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-black/70 p-4">
    <div class="relative w-full max-w-4xl bg-white rounded-2xl overflow-hidden shadow-xl">
        <button type="button"
            class="absolute top-3 right-3 z-10 inline-flex items-center justify-center w-9 h-9 rounded-full bg-black/70 text-white hover:bg-black"
            onclick="closeMediaModal()">
            <span class="ph ph-x"></span>
        </button>

        <div id="mediaModalBody" class="bg-black flex items-center justify-center min-h-[200px]">
            <!-- injected -->
        </div>
    </div>
</div>

<!-- Confirm Done Modal -->
<div id="confirmDoneModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-2xl shadow-lg w-full max-w-sm p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-2">Confirm completion?</h3>
        <p class="text-xs text-slate-600 mb-4">
            This will mark completion from your side. If worker already marked done, job becomes <b>Completed</b>.
            Otherwise it stays <b>In Progress</b> until worker/admin confirms.
        </p>

        <form method="POST" class="flex justify-end gap-2 text-xs">
            <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
            <input type="hidden" name="action" value="client_mark_done">
            <button type="button"
                class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                onclick="closeConfirmDoneModal()">
                Cancel
            </button>
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                Confirm
            </button>
        </form>
    </div>
</div>

<!-- Review Modal -->
<div id="reviewModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-2xl shadow-lg w-full max-w-md p-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Leave a review</h3>
                <p class="text-xs text-slate-500 mt-1">Rate the worker and write a short message.</p>
            </div>
            <button type="button"
                class="inline-flex items-center justify-center w-9 h-9 rounded-full border border-slate-200 text-slate-600 hover:bg-slate-50"
                onclick="closeReviewModal()">
                <span class="ph ph-x"></span>
            </button>
        </div>

        <form method="POST" class="mt-4 space-y-3" id="reviewForm">
            <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
            <input type="hidden" name="action" value="submit_review">

            <div>
                <label class="text-xs font-semibold text-slate-700">Rating</label>
                <select name="rating"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                    required>
                    <option value="">Select rating</option>
                    <option value="5">5 — Excellent</option>
                    <option value="4">4 — Good</option>
                    <option value="3">3 — Okay</option>
                    <option value="2">2 — Bad</option>
                    <option value="1">1 — Very bad</option>
                </select>
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700">Review message</label>
                <textarea name="comment" rows="4" required
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="Write your experience..."></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button"
                    class="text-xs px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                    onclick="closeReviewModal()">
                    Cancel
                </button>
                <button type="submit"
                    class="text-xs px-3 py-1.5 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">
                    Submit Review
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Media modal
    function openMediaModal(type, url) {
        const modal = document.getElementById('mediaModal');
        const body = document.getElementById('mediaModalBody');

        body.innerHTML = "";

        if (type === 'image') {
            const img = document.createElement('img');
            img.src = url;
            img.alt = "Full view";
            img.className = "max-h-[75vh] w-auto object-contain";
            body.className = "bg-black flex items-center justify-center p-4";
            body.appendChild(img);

        } else if (type === 'video') {
            const video = document.createElement('video');
            video.controls = true;
            video.autoplay = true;
            video.className = "w-full max-h-[75vh] bg-black";
            const src = document.createElement('source');
            src.src = url;
            video.appendChild(src);
            body.className = "bg-black flex items-center justify-center";
            body.appendChild(video);

        } else if (type === 'audio') {
            const wrap = document.createElement('div');
            wrap.className = "w-full p-6 bg-white";
            const h = document.createElement('div');
            h.className = "text-sm font-semibold text-slate-900 mb-3";
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

            body.className = "bg-white flex items-center justify-center";
            body.appendChild(wrap);
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeMediaModal() {
        const modal = document.getElementById('mediaModal');
        const body = document.getElementById('mediaModalBody');
        body.innerHTML = ""; // stop video/audio
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    // Confirm done modal
    function openConfirmDoneModal() {
        const modal = document.getElementById('confirmDoneModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    function closeConfirmDoneModal() {
        const modal = document.getElementById('confirmDoneModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    // Review modal
    function openReviewModal() {
        const modal = document.getElementById('reviewModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    function closeReviewModal() {
        const modal = document.getElementById('reviewModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    const openBtn = document.getElementById('openReviewBtn');
    if (openBtn) {
        openBtn.addEventListener('click', openReviewModal);
    }

    // Auto-open review modal if requested
    <?php if ($autoOpenReview): ?>
        window.addEventListener('load', function () {
            openReviewModal();
        });
    <?php endif; ?>
</script>