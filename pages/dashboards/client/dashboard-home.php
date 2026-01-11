<?php
// /fixmate/pages/dashboards/client/home.php
// Include inside: client-dashboard.php?page=home (or whatever your home route is)

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

$clientId = (int)$_SESSION['user_id'];

// ---------------------------
// CSRF (optional future use)
// ---------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_token'];

// ---------------------------
// Helpers
// ---------------------------
function fm_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fm_safe_redirect(string $url): void
{
    // allow only internal routes (basic safety)
    $u = trim($url);
    if ($u === '') {
        return;
    }
    if (strpos($u, 'http://') === 0 || strpos($u, 'https://') === 0) {
        return;
    }
    if ($u[0] !== '/' && strpos($u, 'client-dashboard.php') !== 0) {
        return;
    }
    echo "<script>window.location.href=" . json_encode($u) . ";</script>";
    exit;
}

function fm_is_expired_row(array $job): bool
{
    $status = strtolower(trim((string)($job['status'] ?? 'live')));
    if (in_array($status, ['completed', 'cancelled', 'deleted'], true)) return false;

    $expiresAt = (string)($job['expires_at'] ?? '');
    if ($expiresAt === '' || $expiresAt === '0000-00-00 00:00:00') return false;

    try {
        $exp = new DateTime($expiresAt);
        $now = new DateTime();
        return $exp < $now;
    } catch (Throwable $e) {
        return false;
    }
}

function fm_status_badge(string $status, bool $expired): array
{
    $s = strtolower(trim($status));
    if ($expired) return ['Expired', 'bg-rose-100 text-rose-700 border-rose-200'];

    if ($s === 'completed') return ['Completed', 'bg-indigo-100 text-indigo-700 border-indigo-200'];
    if ($s === 'cancelled') return ['Cancelled', 'bg-slate-200 text-slate-700 border-slate-300'];
    if ($s === 'deleted')   return ['Deleted',   'bg-slate-200 text-slate-700 border-slate-300'];

    if ($s === 'assigned') return ['Assigned', 'bg-sky-50 text-sky-800 border-sky-200'];
    if ($s === 'worker_coming') return ['Worker Coming', 'bg-amber-50 text-amber-800 border-amber-200'];
    if ($s === 'in_progress' || $s === 'inprogress') return ['In Progress', 'bg-violet-50 text-violet-800 border-violet-200'];
    if ($s === 'waiting_client_confirmation') return ['Waiting Confirmation', 'bg-cyan-50 text-cyan-800 border-cyan-200'];

    return ['Live', 'bg-emerald-100 text-emerald-700 border-emerald-200'];
}

// ---------------------------
// Detect notifications columns (schema-safe)
// ---------------------------
$NOTIF_COLS = [];
try {
    $rc = $conn->query("SHOW COLUMNS FROM notifications");
    if ($rc) {
        while ($row = $rc->fetch_assoc()) {
            $NOTIF_COLS[strtolower((string)$row['Field'])] = true;
        }
    }
} catch (Throwable $e) {}

// ---------------------------
// 1) Mark notification read then redirect (is_read functionality)
// Usage:
// client-dashboard.php?page=home&open_notif=ID
// - sets is_read=1 for this user
// - redirects to notification.link (or notifications page if missing)
// ---------------------------
if (isset($_GET['open_notif'])) {
    $nid = (int)$_GET['open_notif'];
    if ($nid > 0 && isset($NOTIF_COLS['id']) && isset($NOTIF_COLS['user_id'])) {

        // fetch notif to get link (and ensure it belongs to this client)
        $notif = null;
        $st = $conn->prepare("SELECT id, user_id, link FROM notifications WHERE id = ? AND user_id = ? LIMIT 1");
        if ($st) {
            $st->bind_param("ii", $nid, $clientId);
            $st->execute();
            $r = $st->get_result();
            $notif = ($r && $r->num_rows === 1) ? $r->fetch_assoc() : null;
            $st->close();
        }

        if ($notif) {
            if (isset($NOTIF_COLS['is_read'])) {
                $up = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? LIMIT 1");
                if ($up) {
                    $up->bind_param("ii", $nid, $clientId);
                    $up->execute();
                    $up->close();
                }
            }

            $link = (string)($notif['link'] ?? '');
            if ($link !== '') {
                fm_safe_redirect($link);
            } else {
                fm_safe_redirect("client-dashboard.php?page=notifications");
            }
        }
    }
}

// ---------------------------
// Counts (cards)
// ---------------------------
// We'll compute in PHP from a lightweight query (safe + simple).
$jobCounts = [
    'completed' => 0,
    'active' => 0,
    'in_progress' => 0,
    'waiting_confirmation' => 0,
    'expired' => 0,
];

$allJobsForCount = [];
$stC = $conn->prepare("SELECT id, status, expires_at FROM jobs WHERE client_id = ?");
if ($stC) {
    $stC->bind_param("i", $clientId);
    $stC->execute();
    $rC = $stC->get_result();
    $allJobsForCount = $rC ? $rC->fetch_all(MYSQLI_ASSOC) : [];
    $stC->close();
}

foreach ($allJobsForCount as $j) {
    $status = strtolower(trim((string)($j['status'] ?? 'live')));
    $expired = fm_is_expired_row($j);

    if ($expired) {
        $jobCounts['expired']++;
        continue;
    }

    if ($status === 'completed') $jobCounts['completed']++;
    elseif ($status === 'in_progress' || $status === 'inprogress') $jobCounts['in_progress']++;
    elseif ($status === 'waiting_client_confirmation') $jobCounts['waiting_confirmation']++;
    elseif (!in_array($status, ['cancelled','deleted'], true)) {
        // treat everything else non-final as active (live/assigned/worker_coming/etc)
        $jobCounts['active']++;
    }
}

// ---------------------------
// Latest 5 Jobs
// ---------------------------
$latestJobs = [];
$stJ = $conn->prepare("
    SELECT id, title, status, budget, location_text, created_at, expires_at
    FROM jobs
    WHERE client_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
if ($stJ) {
    $stJ->bind_param("i", $clientId);
    $stJ->execute();
    $rJ = $stJ->get_result();
    $latestJobs = $rJ ? $rJ->fetch_all(MYSQLI_ASSOC) : [];
    $stJ->close();
}

// ---------------------------
// Latest 5 Notifications for this client
// ---------------------------
$notifications = [];
$unreadCount = 0;

if (isset($NOTIF_COLS['user_id'])) {
    $stN = $conn->prepare("
        SELECT id, job_id, type, title, body, link, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    if ($stN) {
        $stN->bind_param("i", $clientId);
        $stN->execute();
        $rN = $stN->get_result();
        $notifications = $rN ? $rN->fetch_all(MYSQLI_ASSOC) : [];
        $stN->close();
    }

    if (isset($NOTIF_COLS['is_read'])) {
        foreach ($notifications as $n) {
            if ((int)($n['is_read'] ?? 0) === 0) $unreadCount++;
        }
    }
}
?>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/phosphor-icons"></script>

<div class="px-4 sm:px-6 lg:px-8 py-6">
    <div class="max-w-6xl">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-5">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">Client Dashboard</h2>
                <p class="text-sm text-slate-500">Quick overview of your jobs and notifications.</p>
            </div>

            <div class="flex gap-2 flex-wrap">
                <a href="client-dashboard.php?page=post-job"
                   class="text-xs px-3 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">
                    Post a Job
                </a>
                <a href="client-dashboard.php?page=jobs-status"
                   class="text-xs px-3 py-2 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50">
                    View All Jobs
                </a>
            </div>
        </div>

        <!-- Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[11px] text-slate-500">Active Jobs</p>
                        <p class="text-2xl font-semibold text-slate-900 mt-1"><?php echo (int)$jobCounts['active']; ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center">
                        <span class="ph ph-activity text-xl"></span>
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-2">Live / Assigned / Worker coming.</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[11px] text-slate-500">In Progress</p>
                        <p class="text-2xl font-semibold text-slate-900 mt-1"><?php echo (int)$jobCounts['in_progress']; ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-violet-50 text-violet-700 flex items-center justify-center">
                        <span class="ph ph-timer text-xl"></span>
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-2">Work started and ongoing.</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[11px] text-slate-500">Waiting Confirmation</p>
                        <p class="text-2xl font-semibold text-slate-900 mt-1"><?php echo (int)$jobCounts['waiting_confirmation']; ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-cyan-50 text-cyan-700 flex items-center justify-center">
                        <span class="ph ph-check-circle text-xl"></span>
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-2">Worker marked done.</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[11px] text-slate-500">Completed Jobs</p>
                        <p class="text-2xl font-semibold text-slate-900 mt-1"><?php echo (int)$jobCounts['completed']; ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-700 flex items-center justify-center">
                        <span class="ph ph-seal-check text-xl"></span>
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-2">Handshake completed.</p>
            </div>
        </div>

        <!-- Latest jobs -->
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="px-4 sm:px-5 py-4 border-b border-slate-200 flex items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Latest Jobs</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Showing up to 5 most recent job posts.</p>
                </div>
                <a href="client-dashboard.php?page=jobs-status"
                   class="text-xs px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50">
                    View All
                </a>
            </div>

            <div class="p-4 sm:p-5">
                <?php if (empty($latestJobs)): ?>
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500">
                        No jobs found. <a class="text-indigo-600 font-semibold" href="client-dashboard.php?page=post-job">Post your first job</a>.
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($latestJobs as $j): ?>
                            <?php
                                $jid = (int)($j['id'] ?? 0);
                                $expired = fm_is_expired_row($j);
                                [$stText, $stClass] = fm_status_badge((string)($j['status'] ?? 'live'), $expired);
                                $title = (string)($j['title'] ?? 'Untitled');
                                $loc = trim((string)($j['location_text'] ?? ''));
                                $budget = (int)($j['budget'] ?? 0);
                                $created = !empty($j['created_at']) ? date('M d, Y', strtotime($j['created_at'])) : '—';
                                $detailUrl = "client-dashboard.php?page=job-detail&job_id=" . $jid;
                            ?>
                            <a href="<?php echo fm_h($detailUrl); ?>"
                               class="block rounded-xl border border-slate-200 hover:border-slate-300 hover:bg-slate-50 transition p-4">
                                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-start gap-2">
                                            <h4 class="text-sm font-semibold text-slate-900 truncate">
                                                #<?php echo $jid; ?> — <?php echo fm_h($title); ?>
                                            </h4>
                                        </div>

                                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-slate-500">
                                            <span class="inline-flex items-center gap-1">
                                                <span class="ph ph-map-pin"></span>
                                                <?php echo fm_h($loc !== '' ? $loc : 'No location'); ?>
                                            </span>
                                            <span class="text-slate-300">•</span>
                                            <span class="inline-flex items-center gap-1">
                                                <span class="ph ph-currency-circle-dollar"></span>
                                                <span class="font-semibold text-slate-900"><?php echo number_format($budget); ?> PKR</span>
                                            </span>
                                            <span class="text-slate-300">•</span>
                                            <span class="inline-flex items-center gap-1">
                                                <span class="ph ph-calendar"></span>
                                                <?php echo fm_h($created); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium <?php echo $stClass; ?>">
                                        <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span>
                                        <?php echo fm_h($stText); ?>
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notifications -->
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="px-4 sm:px-5 py-4 border-b border-slate-200 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Notifications</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Only notifications for your account.</p>
                    </div>

                    <?php if ($unreadCount > 0): ?>
                        <span class="inline-flex items-center rounded-full bg-rose-100 text-rose-700 text-[11px] font-semibold px-2 py-0.5">
                            <?php echo (int)$unreadCount; ?> unread
                        </span>
                    <?php endif; ?>
                </div>

                <a href="client-dashboard.php?page=notifications"
                   class="text-xs px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50">
                    View All
                </a>
            </div>

            <div class="p-4 sm:p-5">
                <?php if (empty($notifications)): ?>
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500">
                        No notifications yet.
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($notifications as $n): ?>
                            <?php
                                $nid = (int)($n['id'] ?? 0);
                                $title = (string)($n['title'] ?? 'Notification');
                                $body  = (string)($n['body'] ?? '');
                                $when  = !empty($n['created_at']) ? date('M d, Y h:i A', strtotime($n['created_at'])) : '—';
                                $isRead = (int)($n['is_read'] ?? 0) === 1;

                                // Clicking should mark read and redirect to link
                                $openUrl = "client-dashboard.php?page=home&open_notif=" . $nid;
                            ?>
                            <a href="<?php echo fm_h($openUrl); ?>"
                               class="block rounded-xl border border-slate-200 hover:border-slate-300 hover:bg-slate-50 transition p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <?php if (!$isRead): ?>
                                                <span class="inline-block w-2 h-2 rounded-full bg-indigo-600 mt-1"></span>
                                            <?php else: ?>
                                                <span class="inline-block w-2 h-2 rounded-full bg-slate-300 mt-1"></span>
                                            <?php endif; ?>

                                            <h4 class="text-sm font-semibold text-slate-900 truncate">
                                                <?php echo fm_h($title); ?>
                                            </h4>
                                        </div>

                                        <?php if (trim($body) !== ''): ?>
                                            <p class="text-xs text-slate-600 mt-2 line-clamp-2">
                                                <?php echo fm_h($body); ?>
                                            </p>
                                        <?php endif; ?>

                                        <div class="mt-2 text-[11px] text-slate-400">
                                            <?php echo fm_h($when); ?>
                                        </div>
                                    </div>

                                    <div class="flex-shrink-0 text-slate-400">
                                        <span class="ph ph-caret-right"></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<style>
/* tailwind line-clamp fallback (if plugin not enabled) */
.line-clamp-2{
    display:-webkit-box;
    -webkit-box-orient:vertical;
    -webkit-line-clamp:2;
    overflow:hidden;
}
</style>
