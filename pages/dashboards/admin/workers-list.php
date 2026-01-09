<?php
// /fixmate/pages/dashboards/admin/workers-list.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/db.php';

// ---------------------------
// AUTH GUARD (admin only)
// NOTE: do NOT header() redirect here because this file is included inside dashboard layout.
// ---------------------------
if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'] ?? '')) !== 'admin') {
    echo "<p class='text-red-600 p-4'>Access denied.</p>";
    return;
}

$adminId = (int) ($_SESSION['user_id'] ?? 0);

// ---------------------------
// CSRF token (simple)
// ---------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_token'];

// ---------------------------
// Helpers
// ---------------------------
function fm_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function fm_badge($text, $class)
{
    return '<span class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium ' . $class . '">
        <span class="w-1.5 h-1.5 rounded-full bg-current mr-2"></span>' . fm_h($text) . '</span>';
}

function fm_format_cnic_for_ui($cnicDigits)
{
    $d = preg_replace('/\D/', '', (string) $cnicDigits);
    if (strlen($d) !== 13)
        return (string) $cnicDigits;
    return substr($d, 0, 5) . '-' . substr($d, 5, 7) . '-' . substr($d, 12, 1);
}

function fm_current_query_string_except(array $excludeKeys = [])
{
    $q = $_GET ?? [];
    foreach ($excludeKeys as $k)
        unset($q[$k]);
    return http_build_query($q);
}

// ---------------------------
// Flash (session-based, survives redirect)
// ---------------------------
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ---------------------------
// Handle Activate/Deactivate (POST) with safe redirect (JS) to prevent resubmission
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {

    $token = (string) ($_POST['csrf_token'] ?? '');
    $workerId = (int) ($_POST['worker_id'] ?? 0);
    $newStatus = (int) ($_POST['new_status'] ?? -1);

    if (!hash_equals($CSRF, $token)) {
        $_SESSION['flash_error'] = "Security check failed (CSRF). Please refresh and try again.";
    } elseif ($workerId <= 0 || !in_array($newStatus, [0, 1], true)) {
        $_SESSION['flash_error'] = "Invalid request.";
    } else {
        $stmt = $conn->prepare("UPDATE workers SET is_active = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
        if (!$stmt) {
            $_SESSION['flash_error'] = "SQL error: " . $conn->error;
        } else {
            $stmt->bind_param('ii', $newStatus, $workerId);
            $ok = $stmt->execute();
            $stmt->close();

            if ($ok) {
                $_SESSION['flash_success'] = ($newStatus === 1)
                    ? "Worker activated successfully."
                    : "Worker deactivated successfully.";
            } else {
                $_SESSION['flash_error'] = "Failed to update worker status.";
            }
        }
    }

    // Redirect back to same filtered list (NO header redirect because included file)
    $qs = fm_current_query_string_except([]);
    $target = "admin-dashboard.php" . ($qs ? ("?" . $qs) : "");
    echo "<script>window.location.href = " . json_encode($target) . ";</script>";
    return;
}

// ---------------------------
// Filters (GET)
// ---------------------------
$search = trim($_GET['search'] ?? '');           // worker search
$categoryId = (int) ($_GET['category_id'] ?? 0);      // worker category filter
$active = trim($_GET['active'] ?? 'all');        // all|active|inactive
$available = trim($_GET['available'] ?? 'all');     // all|yes|no
$minRating = trim($_GET['min_rating'] ?? '');       // numeric
$jobsMin = trim($_GET['jobs_min'] ?? '');
$jobsMax = trim($_GET['jobs_max'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$sort = trim($_GET['sort'] ?? 'newest');       // newest|oldest|rating|jobs

// ---------------------------
// Load categories for dropdown
// ---------------------------
$categories = [];
$resCat = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($resCat)
    $categories = $resCat->fetch_all(MYSQLI_ASSOC);

// ---------------------------
// Build WHERE
// ---------------------------
$where = " WHERE 1=1 ";
$params = [];
$types = "";

// Search in workers fields
if ($search !== '') {
    $where .= " AND (
        w.full_name LIKE ?
        OR w.phone LIKE ?
        OR w.email LIKE ?
        OR w.cnic LIKE ?
        OR w.city LIKE ?
        OR w.area LIKE ?
        OR w.address LIKE ?
    ) ";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
    $types .= "sssssss";
}

// Active filter
if ($active === 'active') {
    $where .= " AND w.is_active = 1 ";
} elseif ($active === 'inactive') {
    $where .= " AND w.is_active = 0 ";
}

// Availability filter
if ($available === 'yes') {
    $where .= " AND w.is_available = 1 ";
} elseif ($available === 'no') {
    $where .= " AND w.is_available = 0 ";
}

// Min rating
if ($minRating !== '' && is_numeric($minRating)) {
    $where .= " AND IFNULL(w.rating_avg, 0) >= ? ";
    $params[] = (float) $minRating;
    $types .= "d";
}

// Jobs completed range
if ($jobsMin !== '' && is_numeric($jobsMin)) {
    $where .= " AND IFNULL(w.jobs_completed, 0) >= ? ";
    $params[] = (int) $jobsMin;
    $types .= "i";
}
if ($jobsMax !== '' && is_numeric($jobsMax)) {
    $where .= " AND IFNULL(w.jobs_completed, 0) <= ? ";
    $params[] = (int) $jobsMax;
    $types .= "i";
}

// Date range (created_at)
if ($dateFrom !== '') {
    $where .= " AND DATE(w.created_at) >= ? ";
    $params[] = $dateFrom;
    $types .= "s";
}
if ($dateTo !== '') {
    $where .= " AND DATE(w.created_at) <= ? ";
    $params[] = $dateTo;
    $types .= "s";
}

// Category filter via EXISTS
if ($categoryId > 0) {
    $where .= " AND EXISTS (
        SELECT 1 FROM worker_categories wc2
        WHERE wc2.worker_id = w.id AND wc2.category_id = ?
    ) ";
    $params[] = $categoryId;
    $types .= "i";
}

// Sorting
$orderBy = " ORDER BY w.created_at DESC ";
if ($sort === 'oldest') {
    $orderBy = " ORDER BY w.created_at ASC ";
} elseif ($sort === 'rating') {
    $orderBy = " ORDER BY IFNULL(w.rating_avg,0) DESC, w.created_at DESC ";
} elseif ($sort === 'jobs') {
    $orderBy = " ORDER BY IFNULL(w.jobs_completed,0) DESC, w.created_at DESC ";
}

// ---------------------------
// Fetch workers + category names
// ---------------------------
$sql = "
    SELECT
        w.*,
        GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS category_names
    FROM workers w
    LEFT JOIN worker_categories wc ON wc.worker_id = w.id
    LEFT JOIN categories c ON c.id = wc.category_id
    $where
    GROUP BY w.id
    $orderBy
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "<p class='text-red-600 p-4'>SQL error: " . fm_h($conn->error) . "</p>";
    return;
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();
$workers = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();
?>

<div class="mb-4 mx-6 sm:mx-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <h2 class="text-lg font-semibold text-slate-900">Workers</h2>

    <a href="admin-dashboard.php?page=add-worker"
        class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
        <span class="ph-bold ph-plus text-sm mr-1"></span>
        Add Worker
    </a>
</div>

<?php if ($flashError): ?>
    <div class="mx-6 sm:mx-8 mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        <?php echo fm_h($flashError); ?>
    </div>
<?php endif; ?>

<?php if ($flashSuccess): ?>
    <div class="mx-6 sm:mx-8 mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        <?php echo fm_h($flashSuccess); ?>
    </div>
<?php endif; ?>

<!-- FILTER BAR -->
<form method="GET" action="admin-dashboard.php" class="mx-6 sm:mx-8 mb-4">
    <input type="hidden" name="page" value="workers">

    <div class="bg-white border border-slate-200 rounded-xl p-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="md:col-span-1">
                <label class="text-xs font-semibold text-slate-700">Search worker</label>
                <input type="text" name="search" value="<?php echo fm_h($search); ?>"
                    placeholder="name, phone, email, cnic, city, area, address…"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700">Category</label>
                <select name="category_id"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="0">All categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo ($categoryId === (int) $c['id']) ? 'selected' : ''; ?>>
                            <?php echo fm_h($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700">Active</label>
                <select name="active"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="all" <?php echo $active === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="active" <?php echo $active === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $active === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700">Availability</label>
                <select name="available"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="all" <?php echo $available === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="yes" <?php echo $available === 'yes' ? 'selected' : ''; ?>>Available</option>
                    <option value="no" <?php echo $available === 'no' ? 'selected' : ''; ?>>Not Available</option>
                </select>
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700">Min rating</label>
                <input type="number" name="min_rating" min="0" max="5" step="0.1"
                    value="<?php echo fm_h($minRating); ?>"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="text-xs font-semibold text-slate-700">Jobs min</label>
                    <input type="number" name="jobs_min" min="0" step="1" value="<?php echo fm_h($jobsMin); ?>"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-700">Jobs max</label>
                    <input type="number" name="jobs_max" min="0" step="1" value="<?php echo fm_h($jobsMax); ?>"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="text-xs font-semibold text-slate-700">Created from</label>
                    <input type="date" name="date_from" value="<?php echo fm_h($dateFrom); ?>"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-700">Created to</label>
                    <input type="date" name="date_to" value="<?php echo fm_h($dateTo); ?>"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                </div>
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700">Sort</label>
                <select name="sort"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
                    <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Highest rating</option>
                    <option value="jobs" <?php echo $sort === 'jobs' ? 'selected' : ''; ?>>Most jobs completed</option>
                </select>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
            <div class="text-xs text-slate-500">
                Showing <span class="font-semibold text-slate-700"><?php echo (int) count($workers); ?></span> workers
            </div>
            <div class="flex gap-2">
                <a href="admin-dashboard.php?page=workers"
                    class="text-sm px-4 py-2 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50">
                    Reset
                </a>
                <button type="submit"
                    class="text-sm px-4 py-2 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700">
                    Apply Filters
                </button>
            </div>
        </div>
    </div>
</form>

<?php if (empty($workers)): ?>
    <div class="mx-6 sm:mx-8 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-500">
        No workers found for the selected filters.
    </div>
<?php else: ?>
    <div class="space-y-3 mx-6 sm:mx-8 pb-6">
        <?php foreach ($workers as $w): ?>
            <?php
            $wid = (int) ($w['id'] ?? 0);
            $name = $w['full_name'] ?? '—';
            $phone = $w['phone'] ?? '—';
            $email = $w['email'] ?? '';
            $city = $w['city'] ?? '';
            $area = $w['area'] ?? '';
            $cnicUi = fm_format_cnic_for_ui($w['cnic'] ?? '');
            $rating = $w['rating_avg'] ?? 0;
            $jobsCompleted = $w['jobs_completed'] ?? 0;

            $isActive = (int) ($w['is_active'] ?? 0) === 1;
            $isAvail = (int) ($w['is_available'] ?? 0) === 1;

            $avatar = trim((string) ($w['profile_image'] ?? ''));
            if ($avatar === '')
                $avatar = '/fixmate/assets/images/avatar-default.png';

            $catNames = trim((string) ($w['category_names'] ?? ''));
            if ($catNames === '')
                $catNames = '—';

            $activeBadge = $isActive
                ? fm_badge('Active', 'bg-emerald-100 text-emerald-700 border-emerald-200')
                : fm_badge('Inactive', 'bg-rose-100 text-rose-700 border-rose-200');

            $availBadge = $isAvail
                ? fm_badge('Available', 'bg-sky-100 text-sky-700 border-sky-200')
                : fm_badge('Not available', 'bg-slate-200 text-slate-700 border-slate-300');

            $toggleTo = $isActive ? 0 : 1;
            $toggleText = $isActive ? 'Deactivate' : 'Activate';
            $toggleClass = $isActive
                ? 'border-rose-200 text-rose-600 hover:bg-rose-50'
                : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50';
            ?>

            <div
                class="border border-slate-200 rounded-xl p-4 bg-white flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-3 min-w-0">
                    <img src="<?php echo fm_h($avatar); ?>" class="w-12 h-12 rounded-full object-cover border border-slate-200"
                        alt="Worker">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-slate-900 truncate"><?php echo fm_h($name); ?></h3>
                            <span class="text-[11px] text-slate-400">#<?php echo $wid; ?></span>
                        </div>

                        <p class="text-xs text-slate-600 mt-0.5">
                            <?php echo fm_h($phone); ?>
                            <?php if ($email !== ''): ?> • <?php echo fm_h($email); ?><?php endif; ?>
                            <?php if ($cnicUi !== ''): ?> • CNIC: <?php echo fm_h($cnicUi); ?><?php endif; ?>
                        </p>

                        <p class="text-xs text-slate-500 mt-0.5">
                            <?php echo fm_h($city); ?>        <?php echo ($city && $area) ? ' • ' : ''; ?>        <?php echo fm_h($area); ?>
                        </p>

                        <p class="text-[11px] text-slate-400 mt-1">
                            Categories: <?php echo fm_h($catNames); ?>
                        </p>

                        <p class="text-[11px] text-slate-400 mt-1">
                            Rating: <span class="font-semibold text-slate-700"><?php echo fm_h($rating); ?></span>
                            • Jobs completed: <span
                                class="font-semibold text-slate-700"><?php echo fm_h($jobsCompleted); ?></span>
                            • Created:
                            <?php echo !empty($w['created_at']) ? date('M d, Y', strtotime($w['created_at'])) : '—'; ?>
                        </p>
                    </div>
                </div>

                <div class="flex flex-col items-end gap-2">
                    <div class="flex flex-wrap justify-end gap-2">
                        <?php echo $activeBadge; ?>
                        <?php echo $availBadge; ?>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2 mt-1">
                        <a href="admin-dashboard.php?page=add-worker&worker_id=<?php echo $wid; ?>"
                            class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-slate-50">
                            Edit
                        </a>

                        <button type="button" class="text-xs px-3 py-1 rounded-md border <?php echo $toggleClass; ?>"
                            onclick="openToggleModal(<?php echo $wid; ?>, <?php echo $toggleTo; ?>, '<?php echo fm_h($name); ?>')">
                            <?php echo fm_h($toggleText); ?>
                        </button>
                    </div>
                </div>
            </div>

        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Confirmation Modal -->
<div id="toggleModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-lg w-full max-w-sm p-5">
        <h3 id="toggleTitle" class="text-sm font-semibold text-slate-900 mb-2">Confirm</h3>
        <p id="toggleDesc" class="text-xs text-slate-600 mb-4"></p>

        <form method="POST" class="flex justify-end gap-2 text-xs">
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
            <input type="hidden" name="worker_id" id="toggle_worker_id" value="0">
            <input type="hidden" name="new_status" id="toggle_new_status" value="0">

            <button type="button" class="px-3 py-1 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50"
                onclick="closeToggleModal()">
                Cancel
            </button>

            <button type="submit" id="toggleConfirmBtn"
                class="px-3 py-1 rounded-md bg-rose-600 text-white hover:bg-rose-700">
                Confirm
            </button>
        </form>
    </div>
</div>

<script>
    function openToggleModal(workerId, newStatus, workerName) {
        const modal = document.getElementById('toggleModal');
        const title = document.getElementById('toggleTitle');
        const desc = document.getElementById('toggleDesc');
        const widEl = document.getElementById('toggle_worker_id');
        const stEl = document.getElementById('toggle_new_status');
        const btn = document.getElementById('toggleConfirmBtn');

        widEl.value = workerId;
        stEl.value = newStatus;

        const isActivate = (parseInt(newStatus, 10) === 1);

        title.textContent = isActivate ? 'Activate worker?' : 'Deactivate worker?';
        desc.textContent = isActivate
            ? `This will activate "${workerName}" and allow assignment again.`
            : `This will deactivate "${workerName}". Worker will not be removed from database.`;

        btn.textContent = isActivate ? 'Activate' : 'Deactivate';
        btn.className = isActivate
            ? 'px-3 py-1 rounded-md bg-emerald-600 text-white hover:bg-emerald-700'
            : 'px-3 py-1 rounded-md bg-rose-600 text-white hover:bg-rose-700';

        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeToggleModal() {
        const modal = document.getElementById('toggleModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
</script>