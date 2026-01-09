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
    echo "<p class='text-red-600 p-4'>SQL error: " . htmlspecialchars($conn->error) . "</p>";
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
// Determine Final Status (expiry check)
// ---------------------------
$rawStatus = strtolower(trim($job['status'] ?? 'live'));
$finalStatus = $rawStatus;

$now = new DateTime();
$expiresAt = $job['expires_at'] ?? '';

if ($expiresAt && $expiresAt !== '0000-00-00 00:00:00') {
    $exp = new DateTime($expiresAt);
    if (in_array($rawStatus, ['live', 'open'], true) && $exp < $now) {
        $finalStatus = 'expired';
    }
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
// Fetch Active Assignment (SAFE: no hardcoded worker columns)
// job_worker_assignments: id, job_id, worker_id, admin_id, action, reason, assigned_at, ended_at
// workers table: unknown columns -> we fetch w.* and detect in PHP
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

// Helpers
function fm_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function fm_format_dt($dt)
{
    if (!$dt || $dt === '0000-00-00 00:00:00')
        return '—';
    return date('M d, Y h:i A', strtotime($dt));
}

function fm_format_date($dt)
{
    if (!$dt || $dt === '0000-00-00 00:00:00')
        return '—';
    return date('M d, Y', strtotime($dt));
}

// Worker field detector (schema-safe)
function fm_pick_first_key(array $row, array $keys): string
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && trim((string) $row[$k]) !== '')
            return (string) $row[$k];
    }
    return '';
}

$title = $job['title'] ?? '';
$desc = $job['description'] ?? '';
$loc = $job['location_text'] ?? '';
$budget = (int) ($job['budget'] ?? 0);

$categoryName = $job['category_name'] ?? '—';
$subCategoryName = $job['sub_category_name'] ?? '';
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

            <div class="flex flex-wrap gap-2">
                <a href="client-dashboard.php?page=jobs-status"
                    class="text-xs px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50">
                    Back to Jobs
                </a>

                <a href="client-dashboard.php?page=post-job&job_id=<?php echo (int) $jobId; ?>"
                    class="text-xs px-3 py-1.5 rounded-lg border border-indigo-200 text-indigo-700 hover:bg-indigo-50">
                    Edit Job
                </a>
            </div>
        </div>

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

                <span
                    class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium <?php echo $badgeClass; ?>">
                    <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span>
                    <?php echo fm_h($badgeText); ?>
                </span>
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
                        <?php echo fm_format_date($job['created_at'] ?? ''); ?>
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-[11px] text-slate-500">Deadline</p>
                    <p class="text-sm font-semibold text-slate-900">
                        <?php echo fm_format_date($job['expires_at'] ?? ''); ?>
                    </p>
                </div>
            </div>

            <!-- Description -->
            <div class="mt-5">
                <h4 class="text-sm font-semibold text-slate-900 mb-2">Job Description</h4>
                <div
                    class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 whitespace-pre-line">
                    <?php echo $desc ? fm_h($desc) : '—'; ?>
                </div>
            </div>

            <!-- Assigned worker -->
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
                    // If profile_image is stored as full URL, use as is. If stored as filename/path, adjust base if needed.
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
                                        <?php echo fm_h($workerName); ?>
                                    </p>

                                    <p class="text-xs text-slate-500 mt-1">
                                        Assigned at: <?php echo fm_format_dt($assignment['assigned_at'] ?? ''); ?>
                                    </p>

                                    <?php if (!empty($assignment['action'])): ?>
                                        <p class="text-xs text-slate-500 mt-1">
                                            Action: <?php echo fm_h($assignment['action']); ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if ($workerCity || $workerArea): ?>
                                        <p class="text-xs text-slate-500 mt-1">
                                            <?php echo fm_h(trim($workerCity . ($workerCity && $workerArea ? ' • ' : '') . $workerArea)); ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if ($workerAddress): ?>
                                        <p class="text-xs text-slate-500 mt-1">
                                            <?php echo fm_h($workerAddress); ?>
                                        </p>
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
                            $type = strtolower(trim($a['file_type'] ?? ''));
                            $url = $a['file_url'] ?? '';
                            $name = $url ? basename($url) : 'attachment';
                            ?>
                            <div class="rounded-xl border border-slate-200 p-3 bg-white">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs font-semibold text-slate-800 truncate"><?php echo fm_h($name); ?></p>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">
                                        <?php echo fm_h($type ?: 'file'); ?>
                                    </span>
                                </div>

                                <div class="mt-2">
                                    <?php if ($type === 'image'): ?>
                                        <a href="<?php echo fm_h($url); ?>" target="_blank">
                                            <img src="<?php echo fm_h($url); ?>"
                                                class="w-full h-36 object-cover rounded-lg border border-slate-200"
                                                alt="attachment">
                                        </a>
                                    <?php elseif ($type === 'video'): ?>
                                        <video controls class="w-full h-36 rounded-lg border border-slate-200 bg-black">
                                            <source src="<?php echo fm_h($url); ?>">
                                        </video>
                                    <?php elseif ($type === 'audio'): ?>
                                        <audio controls class="w-full">
                                            <source src="<?php echo fm_h($url); ?>">
                                        </audio>
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

        </div>
    </div>
</div>