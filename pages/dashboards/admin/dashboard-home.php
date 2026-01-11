<?php
// /fixmate/pages/dashboards/admin/admin-home.php
// Admin Home (KPI cards + latest jobs + quick stats)

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

// ---------------------------
// Helpers
// ---------------------------
function fm_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fm_truncate(string $text, int $max = 90): string {
    $text = trim($text);
    if ($text === '') return '';
    if (mb_strlen($text) <= $max) return $text;
    $short = mb_substr($text, 0, $max);
    $lastSpace = mb_strrpos($short, ' ');
    if ($lastSpace !== false) $short = mb_substr($short, 0, $lastSpace);
    return rtrim($short, " \t\n\r\0\x0B.,!?") . '…';
}

function fm_badge(string $label, string $class): string {
    return '<span class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium '.$class.'">
        <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span>'.fm_h($label).'</span>';
}

// ---------------------------
// Detect jobs columns (for handshake counts)
// ---------------------------
$JOB_COLS = [];
try {
    $rc = $conn->query("SHOW COLUMNS FROM jobs");
    if ($rc) {
        while ($row = $rc->fetch_assoc()) {
            $JOB_COLS[strtolower((string)$row['Field'])] = true;
        }
    }
} catch (Throwable $e) {}

$hasWorkerMarkedDone = isset($JOB_COLS['worker_marked_done']) || isset($JOB_COLS['worker_mark_done']) || isset($JOB_COLS['worker_done']);
$hasClientMarkedDone = isset($JOB_COLS['client_marked_done']) || isset($JOB_COLS['client_mark_done']) || isset($JOB_COLS['client_done']);

// Normalize “in progress” values across versions
$INPROGRESS_VALUES = ["inprogress", "in_progress"]; // your system uses inprogress mostly, keep both safe.

// ---------------------------
// KPI Counts (fast)
// ---------------------------
$counts = [
    'live' => 0,
    'inprogress' => 0,
    'completed' => 0,
    'wait_client' => 0,
    'wait_worker' => 0,
    'available_workers' => 0,
];

try {
    // Live count
    $st = $conn->prepare("SELECT COUNT(*) AS c FROM jobs WHERE LOWER(status) = 'live'");
    if ($st) {
        $st->execute();
        $r = $st->get_result();
        if ($r && $r->num_rows) $counts['live'] = (int)($r->fetch_assoc()['c'] ?? 0);
        $st->close();
    }

    // In Progress count (supports both strings)
    $sqlIn = "SELECT COUNT(*) AS c FROM jobs WHERE LOWER(status) IN (?, ?)";
    $st = $conn->prepare($sqlIn);
    if ($st) {
        $a = $INPROGRESS_VALUES[0];
        $b = $INPROGRESS_VALUES[1];
        $st->bind_param("ss", $a, $b);
        $st->execute();
        $r = $st->get_result();
        if ($r && $r->num_rows) $counts['inprogress'] = (int)($r->fetch_assoc()['c'] ?? 0);
        $st->close();
    }

    // Completed count (all time)
    $st = $conn->prepare("SELECT COUNT(*) AS c FROM jobs WHERE LOWER(status) = 'completed'");
    if ($st) {
        $st->execute();
        $r = $st->get_result();
        if ($r && $r->num_rows) $counts['completed'] = (int)($r->fetch_assoc()['c'] ?? 0);
        $st->close();
    }

    // Available workers
    $st = $conn->prepare("SELECT COUNT(*) AS c FROM workers WHERE is_active = 1 AND is_available = 1");
    if ($st) {
        $st->execute();
        $r = $st->get_result();
        if ($r && $r->num_rows) $counts['available_workers'] = (int)($r->fetch_assoc()['c'] ?? 0);
        $st->close();
    }

    // Waiting confirmations (only if columns exist)
    if ($hasWorkerMarkedDone && $hasClientMarkedDone) {
        // Resolve best column names
        $wCol = isset($JOB_COLS['worker_marked_done']) ? 'worker_marked_done' : (isset($JOB_COLS['worker_mark_done']) ? 'worker_mark_done' : 'worker_done');
        $cCol = isset($JOB_COLS['client_marked_done']) ? 'client_marked_done' : (isset($JOB_COLS['client_mark_done']) ? 'client_mark_done' : 'client_done');

        // Waiting client: worker done=1, client done=0 (keep status in progress)
        $sqlWc = "
            SELECT COUNT(*) AS c
            FROM jobs
            WHERE ($wCol = 1)
              AND (IFNULL($cCol,0) = 0)
              AND LOWER(status) IN (?, ?)
        ";
        $st = $conn->prepare($sqlWc);
        if ($st) {
            $a = $INPROGRESS_VALUES[0];
            $b = $INPROGRESS_VALUES[1];
            $st->bind_param("ss", $a, $b);
            $st->execute();
            $r = $st->get_result();
            if ($r && $r->num_rows) $counts['wait_client'] = (int)($r->fetch_assoc()['c'] ?? 0);
            $st->close();
        }

        // Waiting worker: client done=1, worker done=0
        $sqlWw = "
            SELECT COUNT(*) AS c
            FROM jobs
            WHERE ($cCol = 1)
              AND (IFNULL($wCol,0) = 0)
              AND LOWER(status) IN (?, ?)
        ";
        $st = $conn->prepare($sqlWw);
        if ($st) {
            $a = $INPROGRESS_VALUES[0];
            $b = $INPROGRESS_VALUES[1];
            $st->bind_param("ss", $a, $b);
            $st->execute();
            $r = $st->get_result();
            if ($r && $r->num_rows) $counts['wait_worker'] = (int)($r->fetch_assoc()['c'] ?? 0);
            $st->close();
        }
    }

} catch (Throwable $e) {
    // swallow errors on dashboard
}

// ---------------------------
// Latest 5 Live Jobs
// ---------------------------
$latestLive = [];
try {
    $sqlLatest = "
        SELECT
            j.id, j.title, j.budget, j.created_at, j.location_text, j.status,
            u.name AS client_name, u.email AS client_email,
            c.name AS category_name,
            sc.name AS sub_category_name
        FROM jobs j
        LEFT JOIN users u ON u.id = j.client_id
        LEFT JOIN categories c ON c.id = j.category_id
        LEFT JOIN sub_categories sc ON sc.id = j.sub_category_id
        WHERE LOWER(j.status) = 'live'
        ORDER BY j.created_at DESC
        LIMIT 5
    ";
    $st = $conn->prepare($sqlLatest);
    if ($st) {
        $st->execute();
        $r = $st->get_result();
        $latestLive = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        $st->close();
    }
} catch (Throwable $e) {}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>FixMate Admin — Home</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/bold/style.css">
</head>

<body class="bg-slate-50">
<div class="py-6">

    <div class="mx-6 sm:mx-8 flex items-center justify-between gap-3 flex-wrap">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Admin Dashboard</h2>
            <p class="text-xs text-slate-500 mt-1">Quick overview of FixMate operations</p>
        </div>

        <a href="admin-dashboard.php?page=jobs"
           class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
            <i class="ph-bold ph-clipboard-text text-base"></i>
            Manage Jobs
        </a>
    </div>

    <!-- KPI Cards -->
    <div class="mx-6 sm:mx-8 mt-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-6 gap-3">
        <!-- Live -->
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500">Live Jobs</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900"><?php echo (int)$counts['live']; ?></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center">
                    <i class="ph-bold ph-broadcast text-lg"></i>
                </div>
            </div>
            <a href="admin-dashboard.php?page=jobs&status=live"
               class="mt-3 inline-flex text-xs font-semibold text-emerald-700 hover:text-emerald-800">
                View live jobs →
            </a>
        </div>

        <!-- In Progress -->
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500">In Progress</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900"><?php echo (int)$counts['inprogress']; ?></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-violet-50 text-violet-700 flex items-center justify-center">
                    <i class="ph-bold ph-timer text-lg"></i>
                </div>
            </div>
            <a href="admin-dashboard.php?page=jobs&status=inprogress"
               class="mt-3 inline-flex text-xs font-semibold text-violet-700 hover:text-violet-800">
                View in progress →
            </a>
        </div>

        <!-- Completed -->
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500">Completed Jobs</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900"><?php echo (int)$counts['completed']; ?></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-700 flex items-center justify-center">
                    <i class="ph-bold ph-check-circle text-lg"></i>
                </div>
            </div>
            <a href="admin-dashboard.php?page=jobs&status=completed"
               class="mt-3 inline-flex text-xs font-semibold text-indigo-700 hover:text-indigo-800">
                View completed →
            </a>
        </div>

        <!-- Waiting Client Confirmation -->
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500">Waiting Client</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900"><?php echo (int)$counts['wait_client']; ?></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-cyan-50 text-cyan-700 flex items-center justify-center">
                    <i class="ph-bold ph-user-circle-check text-lg"></i>
                </div>
            </div>
            <p class="mt-2 text-[11px] text-slate-400">Worker marked done, client pending</p>
        </div>

        <!-- Waiting Worker Confirmation -->
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500">Waiting Worker</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900"><?php echo (int)$counts['wait_worker']; ?></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-sky-50 text-sky-700 flex items-center justify-center">
                    <i class="ph-bold ph-hard-hat text-lg"></i>
                </div>
            </div>
            <p class="mt-2 text-[11px] text-slate-400">Client marked done, worker pending</p>
        </div>

        <!-- Available Workers -->
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500">Available Workers</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900"><?php echo (int)$counts['available_workers']; ?></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center">
                    <i class="ph-bold ph-users-three text-lg"></i>
                </div>
            </div>
            <a href="admin-dashboard.php?page=workers"
               class="mt-3 inline-flex text-xs font-semibold text-amber-700 hover:text-amber-800">
                View workers →
            </a>
        </div>
    </div>

    <!-- Latest Live Jobs -->
    <div class="mx-6 sm:mx-8 mt-6 rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between gap-2 flex-wrap">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Latest Live Jobs</h3>
                <p class="text-xs text-slate-500 mt-1">Most recent jobs waiting for assignment</p>
            </div>

            <a href="admin-dashboard.php?page=jobs&status=live"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                See All
                <i class="ph-bold ph-arrow-right text-sm"></i>
            </a>
        </div>

        <?php if (empty($latestLive)): ?>
            <div class="px-4 py-8 text-sm text-slate-500">
                No live jobs found.
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach ($latestLive as $j): ?>
                    <?php
                        $jobId = (int)($j['id'] ?? 0);
                        $title = (string)($j['title'] ?? '');
                        $budget = (int)($j['budget'] ?? 0);
                        $loc = (string)($j['location_text'] ?? '');
                        $clientName = (string)($j['client_name'] ?? '—');
                        $clientEmail = (string)($j['client_email'] ?? '—');

                        $cat = (string)($j['category_name'] ?? '—');
                        $sub = trim((string)($j['sub_category_name'] ?? ''));

                        $created = !empty($j['created_at']) ? date('M d, Y • h:i A', strtotime($j['created_at'])) : '—';
                    ?>
                    <div class="px-4 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="text-sm font-semibold text-slate-900">
                                    #<?php echo $jobId; ?> — <?php echo fm_h($title); ?>
                                </p>
                                <?php echo fm_badge('Live', 'bg-emerald-100 text-emerald-700 border-emerald-200'); ?>
                            </div>

                            <p class="text-xs text-slate-600 mt-1">
                                <?php echo fm_h($cat); ?>
                                <?php if ($sub !== ''): ?> • <?php echo fm_h($sub); ?><?php endif; ?>
                                <?php if ($loc !== ''): ?> • <?php echo fm_h($loc); ?><?php endif; ?>
                            </p>

                            <p class="text-[11px] text-slate-400 mt-1">
                                Client: <?php echo fm_h($clientName); ?> (<?php echo fm_h($clientEmail); ?>)
                                • Budget: <?php echo $budget; ?> PKR
                                • Posted: <?php echo fm_h($created); ?>
                            </p>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <a href="admin-dashboard.php?page=job-detail&job_id=<?php echo $jobId; ?>"
                               class="inline-flex items-center justify-center rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                View
                            </a>
                            <a href="admin-dashboard.php?page=jobs&assign_job_id=<?php echo $jobId; ?>"
                               class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700">
                                Assign Worker
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Actions / Shortcuts -->
    <div class="mx-6 sm:mx-8 mt-6 grid grid-cols-1 lg:grid-cols-3 gap-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-900">Quick Actions</h4>
            <p class="text-xs text-slate-500 mt-1">Fast navigation to common admin tasks</p>

            <div class="mt-4 grid grid-cols-1 gap-2">
                <a href="admin-dashboard.php?page=jobs"
                   class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                    Manage Jobs
                    <span class="block text-xs text-slate-500 font-normal mt-1">Assign, update status, track progress</span>
                </a>

                <a href="admin-dashboard.php?page=workers"
                   class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                    Manage Workers
                    <span class="block text-xs text-slate-500 font-normal mt-1">Availability, active/inactive, profiles</span>
                </a>

                <a href="admin-dashboard.php?page=reviews"
                   class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                    View Reviews
                    <span class="block text-xs text-slate-500 font-normal mt-1">Monitor quality and user feedback</span>
                </a>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-900">Operations Notes</h4>
            <p class="text-xs text-slate-500 mt-1">Reminders for smooth workflow</p>
            <ul class="mt-4 space-y-2 text-sm text-slate-700">
                <li class="flex gap-2">
                    <span class="mt-1 w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                    Live jobs should be assigned quickly to keep trust.
                </li>
                <li class="flex gap-2">
                    <span class="mt-1 w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                    Keep jobs in <b>In Progress</b> until both confirmations are done.
                </li>
                <li class="flex gap-2">
                    <span class="mt-1 w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                    Follow up on “Waiting Client/Worker” to close jobs faster.
                </li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-900">Today Snapshot</h4>
            <p class="text-xs text-slate-500 mt-1">At-a-glance counters</p>

            <div class="mt-4 space-y-2 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">Live Jobs</span>
                    <span class="font-semibold text-slate-900"><?php echo (int)$counts['live']; ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">In Progress</span>
                    <span class="font-semibold text-slate-900"><?php echo (int)$counts['inprogress']; ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">Waiting Client</span>
                    <span class="font-semibold text-slate-900"><?php echo (int)$counts['wait_client']; ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">Waiting Worker</span>
                    <span class="font-semibold text-slate-900"><?php echo (int)$counts['wait_worker']; ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">Available Workers</span>
                    <span class="font-semibold text-slate-900"><?php echo (int)$counts['available_workers']; ?></span>
                </div>
            </div>

            <div class="mt-4">
                <a href="admin-dashboard.php?page=jobs"
                   class="inline-flex items-center gap-2 text-xs font-semibold text-indigo-600 hover:text-indigo-700">
                    Open full jobs list
                    <i class="ph-bold ph-arrow-right text-sm"></i>
                </a>
            </div>
        </div>
    </div>

</div>
</body>
</html>
