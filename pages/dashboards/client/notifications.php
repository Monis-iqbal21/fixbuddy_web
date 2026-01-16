<?php
// /fixmate/pages/dashboards/shared/notifications.php
// ✅ Paste same file for:
// - /fixmate/pages/dashboards/client/notifications.php
// - /fixmate/pages/dashboards/admin/notifications.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/db.php';

// ---------------------------
// AUTH GUARD (client/admin)
// ---------------------------
$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!isset($_SESSION['user_id']) || !in_array($role, ['client', 'admin'], true)) {
    echo "<p class='text-red-600 p-4'>Access denied.</p>";
    return;
}

$userId = (int)$_SESSION['user_id'];

// ---------------------------
// CSRF
// ---------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_token'];

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

// Required column guard
if (!isset($NOTIF_COLS['user_id'])) {
    echo "<p class='text-red-600 p-4'>notifications table missing required column: user_id</p>";
    return;
}

$HAS = function (string $col) use ($NOTIF_COLS): bool {
    return isset($NOTIF_COLS[strtolower($col)]);
};

// ---------------------------
// Helpers
// ---------------------------
function fm_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fm_redirect(string $url): void
{
    echo "<script>window.location.href=" . json_encode($url) . ";</script>";
    exit;
}

function fm_base_dashboard(string $role): string
{
    return $role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php';
}

function fm_default_fallback_page(string $role): string
{
    return fm_base_dashboard($role) . "?page=notifications";
}

/**
 * ✅ SECURITY: allow ONLY safe internal redirects
 * - blocks http/https
 * - allows relative internal php routes
 * - allows paths starting with /fixmate/...
 */
function fm_is_internal_link(string $link): bool
{
    $u = trim($link);
    if ($u === '') return false;

    // block absolute external URLs
    if (stripos($u, 'http://') === 0 || stripos($u, 'https://') === 0) return false;
    if (stripos($u, '//') === 0) return false;

    // allow absolute internal paths (your app root)
    if ($u[0] === '/') return true;

    // allow dashboard php routes
    if (strpos($u, 'client-dashboard.php') === 0) return true;
    if (strpos($u, 'admin-dashboard.php') === 0) return true;

    // allow other internal relative links if needed
    if (preg_match('#^[a-zA-Z0-9_\-\/]+\.php(\?.*)?$#', $u)) return true;

    return false;
}

/**
 * ✅ NEW: Resolve universal token links to WEB routes.
 * Token examples:
 * - job:detail:45
 * - jobs:list
 * - notifications:list
 *
 * If link is already a valid internal link, it returns as-is.
 * If token is unknown, returns fallback.
 */
function fm_resolve_notification_link(string $role, string $link, int $jobIdFallback = 0): string
{
    $link = trim($link);
    $dash = fm_base_dashboard($role);

    // If already a safe internal link (old system), keep it
    if ($link !== '' && fm_is_internal_link($link)) {
        return $link;
    }

    // If empty, fallback
    if ($link === '') {
        return fm_default_fallback_page($role);
    }

    // Parse tokens
    // 1) job:detail:ID
    if (preg_match('/^job:detail:(\d+)$/i', $link, $m)) {
        $jobId = (int)$m[1];
        if ($jobId > 0) {
            // You said admin actions on web only, still okay to open job detail for admin too if exists
            // If your admin detail page name differs, adjust here
            $page = ($role === 'admin') ? 'job-detail' : 'job-detail';
            return $dash . '?page=' . $page . '&job_id=' . $jobId;
        }
    }

    // 2) notifications:list
    if (preg_match('/^notifications:list$/i', $link)) {
        return $dash . '?page=notifications';
    }

    // 3) jobs:list
    if (preg_match('/^jobs:list$/i', $link)) {
        // adjust if your client jobs listing page is different
        $page = ($role === 'admin') ? 'jobs' : 'my-jobs';
        return $dash . '?page=' . $page;
    }

    // 4) If token unknown but job_id exists, try open job detail
    if ($jobIdFallback > 0) {
        $page = ($role === 'admin') ? 'job-detail' : 'job-detail';
        return $dash . '?page=' . $page . '&job_id=' . (int)$jobIdFallback;
    }

    // Default fallback
    return fm_default_fallback_page($role);
}

function fm_build_url(string $dash, array $qs): string
{
    return $dash . '?' . http_build_query($qs);
}

// ---------------------------
// Actions
// ---------------------------
$flashErr = '';
$flashOk  = '';

/**
 * Open notification (mark read + redirect)
 * URL: ?page=notifications&open=ID
 */
if (isset($_GET['open'])) {
    $nid = (int)$_GET['open'];
    if ($nid > 0) {

        // fetch notification (ensure belongs to this user)
        $notif = null;

        $cols = "id, user_id";
        if ($HAS('job_id')) $cols .= ", job_id";
        if ($HAS('link')) $cols .= ", link";
        if ($HAS('is_read')) $cols .= ", is_read";

        $st = $conn->prepare("SELECT {$cols} FROM notifications WHERE id = ? AND user_id = ? LIMIT 1");
        if ($st) {
            $st->bind_param("ii", $nid, $userId);
            $st->execute();
            $r = $st->get_result();
            $notif = ($r && $r->num_rows === 1) ? $r->fetch_assoc() : null;
            $st->close();
        }

        if ($notif) {

            // mark read (silent)
            if ($HAS('is_read')) {
                $up = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? LIMIT 1");
                if ($up) {
                    $up->bind_param("ii", $nid, $userId);
                    $up->execute();
                    $up->close();
                }
            }

            $jobIdFallback = (int)($notif['job_id'] ?? 0);
            $rawLink = (string)($notif['link'] ?? '');

            // ✅ Resolve token/internal link to correct web route
            $target = fm_resolve_notification_link($role, $rawLink, $jobIdFallback);

            // Final safety check
            if (fm_is_internal_link($target)) {
                fm_redirect($target);
            }

            // If somehow invalid, fallback
            fm_redirect(fm_default_fallback_page($role));
        } else {
            $flashErr = "Notification not found.";
        }
    } else {
        $flashErr = "Invalid notification.";
    }
}

/**
 * POST actions:
 * - mark_all_read
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    if (!hash_equals($CSRF, $token)) {
        $flashErr = "Security check failed (CSRF). Refresh and try again.";
    } else {
        if ($action === 'mark_all_read') {
            if ($HAS('is_read')) {
                $up = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
                if ($up) {
                    $up->bind_param("i", $userId);
                    $up->execute();
                    $up->close();
                    $flashOk = "All notifications marked as read.";
                } else {
                    $flashErr = "Failed to update notifications.";
                }
            } else {
                $flashErr = "This schema has no is_read column.";
            }
        }
    }

    // redirect back without POST
    $dash = fm_base_dashboard($role);
    $qs = $_GET;
    unset($qs['open']);
    $qs['page'] = 'notifications';
    $back = $dash . '?' . http_build_query($qs);
    $_SESSION['flash_notif_err'] = $flashErr;
    $_SESSION['flash_notif_ok']  = $flashOk;
    fm_redirect($back);
}

// session flash
if (isset($_SESSION['flash_notif_err'])) {
    $flashErr = (string)$_SESSION['flash_notif_err'];
    unset($_SESSION['flash_notif_err']);
}
if (isset($_SESSION['flash_notif_ok'])) {
    $flashOk = (string)$_SESSION['flash_notif_ok'];
    unset($_SESSION['flash_notif_ok']);
}

// ---------------------------
// Filters / Pagination
// ---------------------------
$q = trim((string)($_GET['q'] ?? ''));
$readFilter = trim((string)($_GET['read'] ?? 'all')); // all | unread | read

$pageNum = (int)($_GET['p'] ?? 1);
if ($pageNum < 1) $pageNum = 1;
$perPage = 10;
$offset = ($pageNum - 1) * $perPage;

// WHERE
$where = " WHERE user_id = ? ";
$params = [$userId];
$types = "i";

if ($HAS('is_read')) {
    if ($readFilter === 'unread') {
        $where .= " AND (is_read = 0 OR is_read IS NULL) ";
    } elseif ($readFilter === 'read') {
        $where .= " AND is_read = 1 ";
    }
}

if ($q !== '') {
    $like = '%' . $q . '%';
    $parts = [];

    if ($HAS('title')) $parts[] = "title LIKE ?";
    if ($HAS('body'))  $parts[] = "body LIKE ?";
    if ($HAS('type'))  $parts[] = "`type` LIKE ?";
    if ($HAS('job_id')) $parts[] = "CAST(job_id AS CHAR) LIKE ?";

    if (!empty($parts)) {
        $where .= " AND (" . implode(" OR ", $parts) . ") ";
        foreach ($parts as $_) {
            $params[] = $like;
            $types .= "s";
        }
    }
}

// total count
$total = 0;
$stCount = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications {$where}");
if ($stCount) {
    $stCount->bind_param($types, ...$params);
    $stCount->execute();
    $r = $stCount->get_result();
    if ($r && $r->num_rows === 1) {
        $total = (int)($r->fetch_assoc()['cnt'] ?? 0);
    }
    $stCount->close();
}
$totalPages = max(1, (int)ceil($total / $perPage));

// list query
$cols = "id, user_id";
if ($HAS('job_id')) $cols .= ", job_id";
if ($HAS('type')) $cols .= ", `type`";
if ($HAS('title')) $cols .= ", title";
if ($HAS('body')) $cols .= ", body";
if ($HAS('link')) $cols .= ", link";
if ($HAS('is_read')) $cols .= ", is_read";
if ($HAS('created_at')) $cols .= ", created_at";

$notifications = [];
$sql = "SELECT {$cols} FROM notifications {$where} ORDER BY " . ($HAS('created_at') ? "created_at" : "id") . " DESC LIMIT {$perPage} OFFSET {$offset}";
$st = $conn->prepare($sql);
if ($st) {
    $st->bind_param($types, ...$params);
    $st->execute();
    $r = $st->get_result();
    $notifications = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
}

// unread count (for header badge)
$unreadCount = 0;
if ($HAS('is_read')) {
    $stU = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND (is_read = 0 OR is_read IS NULL)");
    if ($stU) {
        $stU->bind_param("i", $userId);
        $stU->execute();
        $rU = $stU->get_result();
        if ($rU && $rU->num_rows === 1) $unreadCount = (int)($rU->fetch_assoc()['cnt'] ?? 0);
        $stU->close();
    }
}

// URLs
$dash = fm_base_dashboard($role);
$baseQS = $_GET;
$baseQS['page'] = 'notifications';
?>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/phosphor-icons"></script>

<div class="px-4 sm:px-6 lg:px-8 py-6">
    <div class="max-w-5xl">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-xl font-semibold text-slate-900">Notifications</h2>

                    <?php if ($HAS('is_read') && $unreadCount > 0): ?>
                        <span class="inline-flex items-center rounded-full bg-rose-100 text-rose-700 text-[11px] font-semibold px-2 py-0.5">
                            <?php echo (int)$unreadCount; ?> unread
                        </span>
                    <?php endif; ?>
                </div>
                <p class="text-sm text-slate-500">Click any notification to open it (it will be marked as read).</p>
            </div>

            <div class="flex gap-2 flex-wrap">
                <a href="<?php echo fm_h($dash . '?page=home'); ?>"
                   class="text-xs px-3 py-2 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50">
                    Back to Dashboard
                </a>

                <?php if ($HAS('is_read')): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit"
                                class="text-xs px-3 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">
                            Mark All Read
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flashErr): ?>
            <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                <?php echo fm_h($flashErr); ?>
            </div>
        <?php endif; ?>

        <?php if ($flashOk): ?>
            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                <?php echo fm_h($flashOk); ?>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <form method="GET" action="<?php echo fm_h($dash); ?>" class="mb-4">
            <input type="hidden" name="page" value="notifications">

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <input type="text" name="q" value="<?php echo fm_h($q); ?>"
                       placeholder="Search (title, body, type, job id)…"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">

                <select name="read"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="all" <?php echo $readFilter === 'all' ? 'selected' : ''; ?>>All</option>
                    <?php if ($HAS('is_read')): ?>
                        <option value="unread" <?php echo $readFilter === 'unread' ? 'selected' : ''; ?>>Unread</option>
                        <option value="read" <?php echo $readFilter === 'read' ? 'selected' : ''; ?>>Read</option>
                    <?php endif; ?>
                </select>

                <button type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                    Apply
                </button>
            </div>
        </form>

        <!-- List -->
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="px-4 sm:px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                <p class="text-sm font-semibold text-slate-900">
                    Showing <?php echo (int)count($notifications); ?> of <?php echo (int)$total; ?>
                </p>
                <p class="text-xs text-slate-500">Page <?php echo (int)$pageNum; ?> / <?php echo (int)$totalPages; ?></p>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="p-5">
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500">
                        No notifications found.
                    </div>
                </div>
            <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($notifications as $n): ?>
                        <?php
                        $nid = (int)($n['id'] ?? 0);
                        $title = (string)($n['title'] ?? 'Notification');
                        $body  = (string)($n['body'] ?? '');
                        $type  = (string)($n['type'] ?? '');
                        $jobId = (int)($n['job_id'] ?? 0);
                        $when  = !empty($n['created_at']) ? date('M d, Y h:i A', strtotime($n['created_at'])) : '—';
                        $isRead = $HAS('is_read') ? ((int)($n['is_read'] ?? 0) === 1) : true;

                        // open link that marks read and redirects
                        $qs = $baseQS;
                        $qs['open'] = $nid;
                        unset($qs['p']);
                        $openUrl = fm_build_url($dash, $qs);
                        ?>
                        <a href="<?php echo fm_h($openUrl); ?>"
                           class="block px-4 sm:px-5 py-4 hover:bg-slate-50 transition">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-block w-2 h-2 rounded-full <?php echo $isRead ? 'bg-slate-300' : 'bg-indigo-600'; ?> mt-1"></span>
                                        <p class="text-sm font-semibold text-slate-900 truncate">
                                            <?php echo fm_h($title); ?>
                                        </p>
                                    </div>

                                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-slate-500">
                                        <?php if ($type !== ''): ?>
                                            <span class="inline-flex items-center gap-1">
                                                <span class="ph ph-tag"></span><?php echo fm_h($type); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($jobId > 0): ?>
                                            <span class="inline-flex items-center gap-1">
                                                <span class="ph ph-briefcase"></span>Job #<?php echo (int)$jobId; ?>
                                            </span>
                                        <?php endif; ?>
                                        <span class="inline-flex items-center gap-1">
                                            <span class="ph ph-clock"></span><?php echo fm_h($when); ?>
                                        </span>
                                    </div>

                                    <?php if (trim($body) !== ''): ?>
                                        <p class="mt-2 text-xs text-slate-600 line-clamp-2">
                                            <?php echo fm_h($body); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <div class="flex-shrink-0 text-slate-400 mt-1">
                                    <span class="ph ph-caret-right"></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="px-4 sm:px-5 py-4 border-t border-slate-200 flex items-center justify-between gap-2">
                    <?php
                    $prev = max(1, $pageNum - 1);
                    $next = min($totalPages, $pageNum + 1);

                    $qsPrev = $baseQS; $qsPrev['p'] = $prev;
                    $qsNext = $baseQS; $qsNext['p'] = $next;

                    $prevUrl = fm_build_url($dash, $qsPrev);
                    $nextUrl = fm_build_url($dash, $qsNext);
                    ?>
                    <div class="flex gap-2">
                        <a href="<?php echo fm_h($prevUrl); ?>"
                           class="text-xs px-3 py-2 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 <?php echo ($pageNum <= 1) ? 'pointer-events-none opacity-50' : ''; ?>">
                            Prev
                        </a>
                        <a href="<?php echo fm_h($nextUrl); ?>"
                           class="text-xs px-3 py-2 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 <?php echo ($pageNum >= $totalPages) ? 'pointer-events-none opacity-50' : ''; ?>">
                            Next
                        </a>
                    </div>

                    <div class="text-xs text-slate-500">
                        Page <span class="font-semibold text-slate-700"><?php echo (int)$pageNum; ?></span>
                        of <span class="font-semibold text-slate-700"><?php echo (int)$totalPages; ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.line-clamp-2{
    display:-webkit-box;
    -webkit-box-orient:vertical;
    -webkit-line-clamp:2;
    overflow:hidden;
}
</style>
