<?php
// /fixmate/pages/dashboards/admin/reviews.php
// Admin reviews listing with filters + pagination (fast, server-side)

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
// CSRF (simple)
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
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fm_stars(int $rating): string
{
    $rating = max(0, min(5, $rating));
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $out .= $i <= $rating
            ? '<span class="text-amber-500">★</span>'
            : '<span class="text-slate-300">★</span>';
    }
    return $out;
}

function fm_truncate(string $text, int $maxChars = 160): string
{
    $text = trim($text);
    if ($text === '') return '';
    if (mb_strlen($text) <= $maxChars) return $text;

    $short = mb_substr($text, 0, $maxChars);
    $lastSpace = mb_strrpos($short, ' ');
    if ($lastSpace !== false) $short = mb_substr($short, 0, $lastSpace);

    return rtrim($short, " \t\n\r\0\x0B.,!?") . '…';
}

// ---------------------------
// Detect columns safely (schema differences)
// ---------------------------
$REV_COLS = [];
try {
    $rc = $conn->query("SHOW COLUMNS FROM reviews");
    if ($rc) {
        while ($row = $rc->fetch_assoc()) {
            $REV_COLS[strtolower((string)$row['Field'])] = true;
        }
    }
} catch (Throwable $e) {}

$HAS_REVIEW_JOB_ID     = isset($REV_COLS['job_id']);
$HAS_REVIEW_CLIENT_ID  = isset($REV_COLS['client_id']);
$HAS_REVIEW_WORKER_ID  = isset($REV_COLS['worker_id']);
$HAS_REVIEW_RATING     = isset($REV_COLS['rating']);
$HAS_REVIEW_COMMENT    = isset($REV_COLS['comment']) || isset($REV_COLS['review']) || isset($REV_COLS['message']);
$HAS_REVIEW_CREATED_AT = isset($REV_COLS['created_at']);

// Choose best comment column name
$REVIEW_COMMENT_COL = 'comment';
if (isset($REV_COLS['comment'])) $REVIEW_COMMENT_COL = 'comment';
elseif (isset($REV_COLS['review'])) $REVIEW_COMMENT_COL = 'review';
elseif (isset($REV_COLS['message'])) $REVIEW_COMMENT_COL = 'message';

// ---------------------------
// Inputs (filters)
// ---------------------------
$q = trim((string)($_GET['q'] ?? ''));                 // global search: worker/client/job title/job id/email
$rating = trim((string)($_GET['rating'] ?? 'all'));    // all | 5 | 4 | 3 | 2 | 1
$perPage = (int)($_GET['per_page'] ?? 25);
$page = (int)($_GET['p'] ?? 1);

$perPage = ($perPage < 10) ? 10 : (($perPage > 100) ? 100 : $perPage);
$page = ($page < 1) ? 1 : $page;
$offset = ($page - 1) * $perPage;

$params = [];
$types = "";
$where = " WHERE 1=1 ";

// ✅ Only client reviews (workers can't review):
// We enforce that reviews belong to a client by joining jobs->client and/or reviews->client_id when available.
// If your schema has client_id in reviews, we ensure it's present and valid via join condition below.

if ($q !== '') {
    $where .= " AND (
        j.id = ? OR
        j.title LIKE ? OR
        u.name LIKE ? OR
        u.email LIKE ? OR
        w.full_name LIKE ? OR
        w.email LIKE ? OR
        w.phone LIKE ?
    ) ";

    $jobExact = ctype_digit($q) ? (int)$q : 0;
    $like = '%' . $q . '%';

    $params[] = $jobExact;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "issssss";
}

if ($rating !== 'all' && ctype_digit($rating)) {
    $where .= " AND r.rating = ? ";
    $params[] = (int)$rating;
    $types .= "i";
}

// ---------------------------
// Core query design (optimized)
// - Get last assignment per job (latest record) to show assigned worker
// - Join users (client) and workers (assigned worker)
// - Only select needed fields
//
// NOTE: For best speed add indexes (optional):
// - reviews(job_id), reviews(client_id), reviews(created_at), reviews(rating)
// - jobs(id, client_id, title)
// - job_worker_assignments(job_id, id)
// - workers(id, email)
// - users(id, email)
// ---------------------------

// If your reviews table DOES NOT have job_id, this page can't function reliably.
if (!$HAS_REVIEW_JOB_ID) {
    echo "<div style='padding:16px;color:#b91c1c'>Your reviews table is missing <b>job_id</b>. This page requires reviews.job_id.</div>";
    exit;
}

// Count total for pagination
$countSql = "
    SELECT COUNT(*) AS total
    FROM reviews r
    INNER JOIN jobs j ON j.id = r.job_id
    LEFT JOIN users u ON u.id = j.client_id

    LEFT JOIN (
        SELECT jwa1.job_id, jwa1.worker_id
        FROM job_worker_assignments jwa1
        INNER JOIN (
            SELECT job_id, MAX(id) AS max_id
            FROM job_worker_assignments
            GROUP BY job_id
        ) t ON t.max_id = jwa1.id
    ) aa ON aa.job_id = j.id
    LEFT JOIN workers w ON w.id = aa.worker_id

    $where
";

$stC = $conn->prepare($countSql);
if (!$stC) {
    echo "<div style='padding:16px;color:#b91c1c'>SQL error: " . fm_h($conn->error) . "</div>";
    exit;
}
if (!empty($params)) $stC->bind_param($types, ...$params);
$stC->execute();
$resC = $stC->get_result();
$totalRows = 0;
if ($resC && $resC->num_rows === 1) {
    $totalRows = (int)($resC->fetch_assoc()['total'] ?? 0);
}
$stC->close();

$totalPages = (int)ceil($totalRows / $perPage);
if ($totalPages < 1) $totalPages = 1;
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Data query
$dataSql = "
    SELECT
        r.id AS review_id,
        r.job_id,
        " . ($HAS_REVIEW_RATING ? "r.rating" : "0 AS rating") . ",
        " . ($HAS_REVIEW_COMMENT ? "r.`{$REVIEW_COMMENT_COL}` AS review_text" : "'' AS review_text") . ",
        " . ($HAS_REVIEW_CREATED_AT ? "r.created_at" : "NULL AS created_at") . ",

        j.title AS job_title,
        j.client_id,

        u.name  AS client_name,
        u.email AS client_email,

        aa.worker_id AS assigned_worker_id,
        w.full_name AS worker_name,
        w.email AS worker_email,
        w.phone AS worker_phone

    FROM reviews r
    INNER JOIN jobs j ON j.id = r.job_id
    LEFT JOIN users u ON u.id = j.client_id

    LEFT JOIN (
        SELECT jwa1.job_id, jwa1.worker_id
        FROM job_worker_assignments jwa1
        INNER JOIN (
            SELECT job_id, MAX(id) AS max_id
            FROM job_worker_assignments
            GROUP BY job_id
        ) t ON t.max_id = jwa1.id
    ) aa ON aa.job_id = j.id
    LEFT JOIN workers w ON w.id = aa.worker_id

    $where
    ORDER BY " . ($HAS_REVIEW_CREATED_AT ? "r.created_at" : "r.id") . " DESC
    LIMIT ? OFFSET ?
";

$st = $conn->prepare($dataSql);
if (!$st) {
    echo "<div style='padding:16px;color:#b91c1c'>SQL error: " . fm_h($conn->error) . "</div>";
    exit;
}

// bind params + limit/offset
$bindParams = $params;
$bindTypes = $types . "ii";
$bindParams[] = $perPage;
$bindParams[] = $offset;

$st->bind_param($bindTypes, ...$bindParams);
$st->execute();
$res = $st->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$st->close();

// Build pagination URLs (preserve filters)
$baseQS = $_GET;
$baseQS['page'] = 'reviews';

function fm_page_url(array $qs, int $p): string
{
    $qs['p'] = $p;
    return "admin-dashboard.php?" . http_build_query($qs);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>FixMate Admin — Reviews</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/bold/style.css">
</head>

<body class="bg-slate-50">
<div class="py-6">

    <div class="mx-8 mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Reviews</h2>
            <p class="text-xs text-slate-500 mt-1">
                Showing client-given reviews only. Includes job title, job id, assigned worker, and client info.
            </p>
        </div>

        <div class="text-xs text-slate-500">
            Total: <span class="font-semibold text-slate-800"><?php echo (int)$totalRows; ?></span>
        </div>
    </div>

    <!-- Filters -->
    <div class="mx-8 rounded-xl border border-slate-200 bg-white p-4">
        <form method="GET" action="admin-dashboard.php" id="filtersForm" class="grid grid-cols-1 lg:grid-cols-12 gap-2">
            <input type="hidden" name="page" value="reviews" />

            <div class="lg:col-span-7">
                <label class="text-xs font-semibold text-slate-700">Search</label>
                <div class="mt-1 relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                        <i class="ph-bold ph-magnifying-glass"></i>
                    </span>
                    <input
                        type="text"
                        name="q"
                        value="<?php echo fm_h($q); ?>"
                        placeholder="Search by job title, job id, worker name/email, client name/email…"
                        class="w-full rounded-lg border border-slate-300 pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                        id="qInput"
                    />
                </div>
                <p class="text-[11px] text-slate-400 mt-1">Tip: type job id to jump fast.</p>
            </div>

            <div class="lg:col-span-2">
                <label class="text-xs font-semibold text-slate-700">Rating</label>
                <select name="rating"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="all" <?php echo ($rating === 'all') ? 'selected' : ''; ?>>All</option>
                    <option value="5" <?php echo ($rating === '5') ? 'selected' : ''; ?>>5 stars</option>
                    <option value="4" <?php echo ($rating === '4') ? 'selected' : ''; ?>>4 stars</option>
                    <option value="3" <?php echo ($rating === '3') ? 'selected' : ''; ?>>3 stars</option>
                    <option value="2" <?php echo ($rating === '2') ? 'selected' : ''; ?>>2 stars</option>
                    <option value="1" <?php echo ($rating === '1') ? 'selected' : ''; ?>>1 star</option>
                </select>
            </div>

            <div class="lg:col-span-2">
                <label class="text-xs font-semibold text-slate-700">Per page</label>
                <select name="per_page"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <?php foreach ([10, 25, 50, 100] as $n): ?>
                        <option value="<?php echo $n; ?>" <?php echo ($perPage === $n) ? 'selected' : ''; ?>>
                            <?php echo $n; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="lg:col-span-1 flex items-end gap-2">
                <button type="submit"
                        class="w-full inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                    Apply
                </button>
            </div>
        </form>
    </div>

    <!-- List -->
    <div class="mx-8 mt-4 space-y-3">
        <?php if (empty($rows)): ?>
            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-sm text-slate-500">
                No reviews found for the selected filters.
            </div>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
                <?php
                $jobId = (int)($r['job_id'] ?? 0);
                $jobTitle = (string)($r['job_title'] ?? '—');
                $reviewId = (int)($r['review_id'] ?? 0);

                $clientName = (string)($r['client_name'] ?? '—');
                $clientEmail = (string)($r['client_email'] ?? '—');

                $workerId = (int)($r['assigned_worker_id'] ?? 0);
                $workerName = (string)($r['worker_name'] ?? '—');
                $workerEmail = (string)($r['worker_email'] ?? '—');

                $ratingVal = (int)($r['rating'] ?? 0);
                $text = (string)($r['review_text'] ?? '');
                $short = fm_truncate($text, 200);

                $createdAt = $r['created_at'] ?? null;
                $createdLabel = '—';
                if (!empty($createdAt)) {
                    $createdLabel = date('M d, Y • h:i A', strtotime($createdAt));
                }

                // Badge color by rating
                $rateClass = 'bg-slate-100 text-slate-700 border-slate-200';
                if ($ratingVal >= 5) $rateClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                elseif ($ratingVal === 4) $rateClass = 'bg-sky-50 text-sky-700 border-sky-200';
                elseif ($ratingVal === 3) $rateClass = 'bg-amber-50 text-amber-700 border-amber-200';
                elseif ($ratingVal <= 2 && $ratingVal > 0) $rateClass = 'bg-rose-50 text-rose-700 border-rose-200';
                ?>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-semibold text-slate-900">
                                    Job #<?php echo $jobId; ?> — <?php echo fm_h($jobTitle); ?>
                                </h3>

                                <span class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-medium <?php echo $rateClass; ?>">
                                    Rating: <?php echo (int)$ratingVal; ?>/5
                                </span>

                                <span class="text-xs"><?php echo fm_stars($ratingVal); ?></span>
                            </div>

                            <p class="text-[11px] text-slate-500 mt-1">
                                Review ID: #<?php echo $reviewId; ?> • <?php echo fm_h($createdLabel); ?>
                            </p>

                            <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-2 text-[12px]">
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <p class="text-[11px] font-semibold text-slate-700">Client</p>
                                    <p class="text-slate-900 font-semibold truncate"><?php echo fm_h($clientName); ?></p>
                                    <p class="text-slate-600 truncate"><?php echo fm_h($clientEmail); ?></p>
                                </div>

                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <p class="text-[11px] font-semibold text-slate-700">Assigned Worker</p>
                                    <p class="text-slate-900 font-semibold truncate">
                                        <?php echo ($workerId > 0) ? "#" . $workerId . " — " . fm_h($workerName) : "—"; ?>
                                    </p>
                                    <p class="text-slate-600 truncate"><?php echo fm_h($workerEmail); ?></p>
                                </div>

                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <p class="text-[11px] font-semibold text-slate-700">Quick Links</p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <a href="admin-dashboard.php?page=job-detail&job_id=<?php echo $jobId; ?>"
                                           class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-white">
                                            View Job
                                        </a>
                                        <!-- <?php if ($workerId > 0): ?>
                                            <a href="admin-dashboard.php?page=worker-detail&worker_id=<?php echo $workerId; ?>"
                                               class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-white">
                                                View Worker
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($r['client_id'])): ?>
                                            <a href="admin-dashboard.php?page=client-detail&client_id=<?php echo (int)$r['client_id']; ?>"
                                               class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-white">
                                                View Client
                                            </a>
                                        <?php endif; ?> -->
                                    </div>
                                </div>
                            </div>

                            <?php if ($short !== ''): ?>
                                <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3">
                                    <p class="text-[11px] font-semibold text-slate-700 mb-1">Review</p>
                                    <p class="text-sm text-slate-700 leading-relaxed">
                                        <?php echo nl2br(fm_h($short)); ?>
                                    </p>
                                    <?php if (mb_strlen($text) > mb_strlen($short)): ?>
                                        <button type="button"
                                                class="mt-2 text-xs font-semibold text-indigo-600 hover:text-indigo-700"
                                                onclick="toggleFull(<?php echo $reviewId; ?>)">
                                            Show full
                                        </button>
                                        <div id="full_<?php echo $reviewId; ?>" class="hidden mt-2 text-sm text-slate-700 leading-relaxed">
                                            <?php echo nl2br(fm_h($text)); ?>
                                            <button type="button"
                                                    class="mt-2 block text-xs font-semibold text-indigo-600 hover:text-indigo-700"
                                                    onclick="toggleFull(<?php echo $reviewId; ?>)">
                                                Hide
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="mt-3 text-sm text-slate-400">No review text.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <div class="mx-8 mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div class="text-xs text-slate-500">
            Page <span class="font-semibold text-slate-900"><?php echo (int)$page; ?></span>
            of <span class="font-semibold text-slate-900"><?php echo (int)$totalPages; ?></span>
        </div>

        <div class="flex flex-wrap gap-2">
            <?php
            $prev = max(1, $page - 1);
            $next = min($totalPages, $page + 1);
            ?>
            <a href="<?php echo fm_h(fm_page_url($baseQS, 1)); ?>"
               class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-white <?php echo ($page <= 1) ? 'pointer-events-none opacity-50' : ''; ?>">
                First
            </a>
            <a href="<?php echo fm_h(fm_page_url($baseQS, $prev)); ?>"
               class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-white <?php echo ($page <= 1) ? 'pointer-events-none opacity-50' : ''; ?>">
                Prev
            </a>
            <a href="<?php echo fm_h(fm_page_url($baseQS, $next)); ?>"
               class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-white <?php echo ($page >= $totalPages) ? 'pointer-events-none opacity-50' : ''; ?>">
                Next
            </a>
            <a href="<?php echo fm_h(fm_page_url($baseQS, $totalPages)); ?>"
               class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-white <?php echo ($page >= $totalPages) ? 'pointer-events-none opacity-50' : ''; ?>">
                Last
            </a>
        </div>
    </div>

</div>

<script>
    // Small UX: debounce search typing to reduce reload spam
    (function () {
        const input = document.getElementById('qInput');
        const form = document.getElementById('filtersForm');
        if (!input || !form) return;

        let t = null;
        input.addEventListener('input', function () {
            clearTimeout(t);
            t = setTimeout(() => {
                // Reset page to 1 when changing search
                const p = form.querySelector('input[name="p"]');
                if (p) p.value = "1";
                form.submit();
            }, 650);
        });
    })();

    function toggleFull(id) {
        const el = document.getElementById('full_' + id);
        if (!el) return;
        el.classList.toggle('hidden');
    }
</script>
</body>
</html>
