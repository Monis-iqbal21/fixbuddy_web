<?php
// /fixmate/pages/dashboards/client/post-job.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/db.php';

// ===========================
// CONFIG (easy to change later)
// ===========================
$MIN_BUDGET_PKR = 500; // <-- minimum allowed budget (PKR)

// ---------------------------
// AUTH GUARD (client only)
// ---------------------------
if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'] ?? '')) !== 'client') {
    echo "<script>window.location.href='/fixmate/pages/404page.php';</script>";
    exit;

}

$clientId = (int) ($_SESSION['user_id'] ?? 0);

// ---------------------------
// EDIT MODE (NO UI change)
// ---------------------------
$editJobId = (int) ($_GET['job_id'] ?? 0);
$isEdit = ($editJobId > 0);

// ---------------------------
// AJAX: Load subcategories
// URL: post-job.php?ajax=subcategories&category_id=1
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'subcategories') {
    header('Content-Type: application/json; charset=utf-8');

    $categoryId = (int) ($_GET['category_id'] ?? 0);
    $rows = [];

    if ($categoryId > 0) {
        $stmt = $conn->prepare("
            SELECT id, name
            FROM sub_categories
            WHERE category_id = ? AND is_active = 1
            ORDER BY name ASC
        ");
        if ($stmt) {
            $stmt->bind_param("i", $categoryId);
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
// Load Categories (dynamic)
// ---------------------------
$categories = [];
$stmtCat = $conn->prepare("SELECT id, name FROM categories ORDER BY name ASC");
if ($stmtCat) {
    $stmtCat->execute();
    $resCat = $stmtCat->get_result();
    $categories = $resCat ? $resCat->fetch_all(MYSQLI_ASSOC) : [];
    $stmtCat->close();
}

// ---------------------------
// Helpers
// ---------------------------
function fm_detect_type_from_mime(string $mime): string
{
    if (strpos($mime, 'image/') === 0)
        return 'image';
    if (strpos($mime, 'video/') === 0)
        return 'video';
    return ''; // only allow image/video
}

function fm_audio_ext_from_mime(string $mime): string
{
    return match ($mime) {
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        default => 'webm',
    };
}

// Convert /fixmate/uploads/... to physical path (best effort)
function fm_public_url_to_path(string $publicUrl): string
{
    // publicUrl like: /fixmate/uploads/jobs/xxx.jpg
    $publicUrl = trim($publicUrl);
    if ($publicUrl === '')
        return '';

    // map "/fixmate/uploads/..." -> "/uploads/..." relative to project root
    $rel = $publicUrl;
    if (strpos($rel, '/fixmate/') === 0) {
        $rel = substr($rel, strlen('/fixmate/')); // "uploads/jobs/..."
    } elseif (strpos($rel, '/') === 0) {
        $rel = ltrim($rel, '/');
    }

    // this file is: /fixmate/pages/dashboards/client/post-job.php
    // project root is 4 levels up: pages -> dashboards -> client -> (file)
    $root = realpath(__DIR__ . '/../../../..'); // /fixmate
    if (!$root)
        return '';

    return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
}

// ---------------------------
// Form state
// ---------------------------
$errors = [];
$successMessage = '';
$jobId = null;

$title = '';
$description = '';
$location_text = '';
$category_id = 0;
$sub_category_id = 0;
$budget = $MIN_BUDGET_PKR; // default
$expires_date = '';

// Existing attachments (for edit mode display)
$existingAttachments = [];

// ---------------------------
// If EDIT mode and not POST yet: load job to prefill (NO UI change)
// ---------------------------
if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST' && !(isset($_GET['success']) && $_GET['success'] === '1')) {

    $stmtJ = $conn->prepare("SELECT * FROM jobs WHERE id = ? AND client_id = ? LIMIT 1");
    if (!$stmtJ) {
        echo "<script>window.location.href='/fixmate/pages/404page.php';</script>";
        exit;

    }
    $stmtJ->bind_param("ii", $editJobId, $clientId);
    $stmtJ->execute();
    $resJ = $stmtJ->get_result();
    $jobRow = $resJ ? $resJ->fetch_assoc() : null;
    $stmtJ->close();

    if (!$jobRow) {
        echo "<script>window.location.href='/fixmate/pages/404page.php';</script>";
        exit;

    }

    $jobId = $editJobId;

    // Prefill
    $title = (string) ($jobRow['title'] ?? '');
    $description = (string) ($jobRow['description'] ?? '');
    $location_text = (string) ($jobRow['location_text'] ?? '');
    $category_id = (int) ($jobRow['category_id'] ?? 0);
    $sub_category_id = (int) ($jobRow['sub_category_id'] ?? 0);
    $budget = (int) ($jobRow['budget'] ?? $MIN_BUDGET_PKR);

    $expiresAt = (string) ($jobRow['expires_at'] ?? '');
    if ($expiresAt && $expiresAt !== '0000-00-00 00:00:00') {
        $expires_date = date('Y-m-d', strtotime($expiresAt));
    }

    // Load existing attachments to show
    $stmtAtt = $conn->prepare("SELECT id, file_type, file_url, created_at FROM job_attachments WHERE job_id = ? ORDER BY id DESC");
    if ($stmtAtt) {
        $stmtAtt->bind_param("i", $editJobId);
        $stmtAtt->execute();
        $resAtt = $stmtAtt->get_result();
        $existingAttachments = $resAtt ? $resAtt->fetch_all(MYSQLI_ASSOC) : [];
        $stmtAtt->close();
    }
}

// ---------------------------
// DELETE ATTACHMENT (NO UI field added)
// URL: post-job.php?job_id=123&delete_attachment_id=55
// ---------------------------
if ($isEdit && isset($_GET['delete_attachment_id'])) {
    $attId = (int) $_GET['delete_attachment_id'];

    if ($attId > 0) {
        // verify job belongs to client
        $chk = $conn->prepare("SELECT id FROM jobs WHERE id = ? AND client_id = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param("ii", $editJobId, $clientId);
            $chk->execute();
            $r = $chk->get_result();
            $okJob = ($r && $r->num_rows > 0);
            $chk->close();

            if ($okJob) {
                // get file url for unlink
                $q = $conn->prepare("SELECT file_url FROM job_attachments WHERE id = ? AND job_id = ? LIMIT 1");
                $fileUrl = '';
                if ($q) {
                    $q->bind_param("ii", $attId, $editJobId);
                    $q->execute();
                    $rs = $q->get_result();
                    $row = $rs ? $rs->fetch_assoc() : null;
                    $fileUrl = (string) ($row['file_url'] ?? '');
                    $q->close();
                }

                $del = $conn->prepare("DELETE FROM job_attachments WHERE id = ? AND job_id = ? LIMIT 1");
                if ($del) {
                    $del->bind_param("ii", $attId, $editJobId);
                    $del->execute();
                    $del->close();
                }

                // delete file from disk best-effort
                if ($fileUrl !== '') {
                    $path = fm_public_url_to_path($fileUrl);
                    if ($path && is_file($path)) {
                        @unlink($path);
                    }
                }

                // Update jobs.is_attachments flag
                $cnt = $conn->prepare("SELECT COUNT(*) AS c FROM job_attachments WHERE job_id = ?");
                if ($cnt) {
                    $cnt->bind_param("i", $editJobId);
                    $cnt->execute();
                    $res = $cnt->get_result();
                    $row = $res ? $res->fetch_assoc() : null;
                    $cnt->close();
                    $hasAny = ((int) ($row['c'] ?? 0) > 0) ? 'yes' : 'no';

                    $u = $conn->prepare("UPDATE jobs SET is_attachments = ? WHERE id = ? AND client_id = ? LIMIT 1");
                    if ($u) {
                        $u->bind_param("sii", $hasAny, $editJobId, $clientId);
                        $u->execute();
                        $u->close();
                    }
                }
            }
        }
    }

    echo "<script>window.location.href='/fixmate/pages/dashboards/client/client-dashboard.php?page=post-job&job_id=" . (int) $editJobId . "';</script>";
    exit;

}

// ---------------------------
// HANDLE SUBMIT (INSERT or UPDATE)
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location_text = trim($_POST['location_text'] ?? '');

    $category_id = (int) ($_POST['category_id'] ?? 0);
    $sub_category_id = (int) ($_POST['sub_category_id'] ?? 0);
    $budget = (int) ($_POST['budget'] ?? 0);
    $expires_date = trim($_POST['expires_date'] ?? '');

    // Validation
    if ($title === '')
        $errors[] = "Job title is required.";
    if ($description === '')
        $errors[] = "Job description is required.";
    if ($category_id <= 0)
        $errors[] = "Please select a category.";

    if ($budget < $MIN_BUDGET_PKR) {
        $errors[] = "Budget must be at least {$MIN_BUDGET_PKR} PKR.";
    }

    $today = date('Y-m-d');
    if ($expires_date === '' || $expires_date < $today) {
        $errors[] = "Deadline must be today or a future date.";
    }

    $expires_at = $expires_date . ' 23:59:59';

    if (empty($errors)) {
        try {

            // ✅ If editing: verify job belongs to client
            if ($isEdit) {
                $chk = $conn->prepare("SELECT id FROM jobs WHERE id = ? AND client_id = ? LIMIT 1");
                if (!$chk) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $chk->bind_param("ii", $editJobId, $clientId);
                $chk->execute();
                $r = $chk->get_result();
                $okJob = ($r && $r->num_rows > 0);
                $chk->close();

                if (!$okJob) {
                    echo "<script>window.location.href='/fixmate/pages/404page.php';</script>";
                    exit;

                }

                // ✅ UPDATE job (NO UI changes)
                $stmt = $conn->prepare("
                    UPDATE jobs
                    SET title = ?,
                        description = ?,
                        budget = ?,
                        category_id = ?,
                        sub_category_id = ?,
                        location_text = ?,
                        expires_at = ?,
                        updated_at = NOW()
                    WHERE id = ? AND client_id = ?
                    LIMIT 1
                ");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }

                $stmt->bind_param(
                    "ssiiissii",
                    $title,
                    $description,
                    $budget,
                    $category_id,
                    $sub_category_id,
                    $location_text,
                    $expires_at,
                    $editJobId,
                    $clientId
                );

                if (!$stmt->execute()) {
                    throw new Exception("Update job failed: " . $stmt->error);
                }
                $stmt->close();

                $jobId = $editJobId;

            } else {
                // ✅ INSERT job (your original logic)
                $stmt = $conn->prepare("
                    INSERT INTO jobs
                        (client_id, title, description, budget, category_id, sub_category_id, location_text, expires_at, status, is_attachments, created_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, 'live', 'no', NOW())
                ");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }

                $stmt->bind_param(
                    "issiiiss",
                    $clientId,
                    $title,
                    $description,
                    $budget,
                    $category_id,
                    $sub_category_id,
                    $location_text,
                    $expires_at
                );

                if (!$stmt->execute()) {
                    throw new Exception("Insert job failed: " . $stmt->error);
                }

                $jobId = (int) $conn->insert_id;
                $stmt->close();

                if ($jobId <= 0) {
                    throw new Exception("Could not retrieve job ID after insert.");
                }
            }

            // ---------------------------
            // Attachments (same table logic)
            // ---------------------------
            $hasAttachments = false;

            // A) Images/Videos
            if (!empty($_FILES['media']['name'][0])) {
                $uploadDir = __DIR__ . '/../../../uploads/jobs/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }

                foreach ($_FILES['media']['name'] as $i => $originalName) {
                    if (($_FILES['media']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
                        continue;

                    $tmpName = $_FILES['media']['tmp_name'][$i] ?? '';
                    $size = (int) ($_FILES['media']['size'][$i] ?? 0);
                    if ($tmpName === '' || $size <= 0)
                        continue;

                    $mime = mime_content_type($tmpName) ?: '';
                    $type = fm_detect_type_from_mime($mime);
                    if ($type === '')
                        continue;

                    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
                    if ($ext === '')
                        $ext = ($type === 'image' ? 'jpg' : 'mp4');

                    $fileName = "job_{$jobId}_" . uniqid('', true) . "." . $ext;
                    $target = $uploadDir . $fileName;

                    if (!move_uploaded_file($tmpName, $target))
                        continue;

                    $publicUrl = "/fixmate/uploads/jobs/" . $fileName;

                    $stmtA = $conn->prepare("
                        INSERT INTO job_attachments (job_id, file_type, file_url, created_at)
                        VALUES (?, ?, ?, NOW())
                    ");
                    if ($stmtA) {
                        $stmtA->bind_param("iss", $jobId, $type, $publicUrl);
                        $stmtA->execute();
                        $stmtA->close();
                        $hasAttachments = true;
                    }
                }
            }

            // B) Audio (base64 from JS)
            if (!empty($_POST['audio_data'])) {
                $audioDataUrl = (string) $_POST['audio_data'];
                $audioMime = (string) ($_POST['audio_mime'] ?? 'audio/webm');

                if (strpos($audioDataUrl, 'base64,') !== false) {
                    [, $b64] = explode('base64,', $audioDataUrl, 2);
                    $bin = base64_decode($b64);

                    if ($bin !== false && strlen($bin) > 0) {
                        $audioDir = __DIR__ . '/../../../uploads/jobs/audio/';
                        if (!is_dir($audioDir)) {
                            mkdir($audioDir, 0775, true);
                        }

                        $ext = fm_audio_ext_from_mime($audioMime);
                        $fileName = "job_{$jobId}_voice_" . time() . "." . $ext;
                        $filePath = $audioDir . $fileName;
                        $publicUrl = "/fixmate/uploads/jobs/audio/" . $fileName;

                        file_put_contents($filePath, $bin);

                        $stmtA = $conn->prepare("
                            INSERT INTO job_attachments (job_id, file_type, file_url, created_at)
                            VALUES (?, 'audio', ?, NOW())
                        ");
                        if ($stmtA) {
                            $stmtA->bind_param("is", $jobId, $publicUrl);
                            $stmtA->execute();
                            $stmtA->close();
                            $hasAttachments = true;
                        }
                    }
                }
            }

            // ✅ If edit mode: job may already have attachments
            if ($isEdit && !$hasAttachments) {
                $stmtC = $conn->prepare("SELECT COUNT(*) AS c FROM job_attachments WHERE job_id = ?");
                if ($stmtC) {
                    $stmtC->bind_param("i", $jobId);
                    $stmtC->execute();
                    $r = $stmtC->get_result();
                    $row = $r ? $r->fetch_assoc() : null;
                    $stmtC->close();
                    if ((int) ($row['c'] ?? 0) > 0) {
                        $hasAttachments = true;
                    }
                }
            }

            // Update jobs.is_attachments
            $stmtU = $conn->prepare("UPDATE jobs SET is_attachments = ? WHERE id = ? LIMIT 1");
            if ($stmtU) {
                $flag = $hasAttachments ? 'yes' : 'no';
                $stmtU->bind_param("si", $flag, $jobId);
                $stmtU->execute();
                $stmtU->close();
            }

            // ✅ prevent resubmission
            // Keep your same success redirect. For edit, we still redirect with success=1.
            echo "<script>window.location.href='/fixmate/pages/dashboards/client/client-dashboard.php?page=post-job&success=1&job_id=" . (int) $jobId . "';</script>";
            exit;

        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// success after redirect
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $jobId = (int) ($_GET['job_id'] ?? 0);

    // If coming from edit, we should not reset the form.
    // We'll keep message generic but correct.
    $successMessage = $isEdit ? "Your job has been updated successfully!" : "Your job has been posted successfully!";

    // For create mode you were resetting form. Keep that behavior ONLY when not editing.
    if (!$isEdit) {
        $title = $description = $location_text = $expires_date = '';
        $category_id = $sub_category_id = 0;
        $budget = $MIN_BUDGET_PKR;
    }
}

// Load existing attachments for edit mode (also after success redirect)
if ($isEdit && $editJobId > 0) {
    $stmtAtt = $conn->prepare("SELECT id, file_type, file_url, created_at FROM job_attachments WHERE job_id = ? ORDER BY id DESC");
    if ($stmtAtt) {
        $stmtAtt->bind_param("i", $editJobId);
        $stmtAtt->execute();
        $resAtt = $stmtAtt->get_result();
        $existingAttachments = $resAtt ? $resAtt->fetch_all(MYSQLI_ASSOC) : [];
        $stmtAtt->close();
    }

    // Also if success redirect (edit), ensure form stays prefilled
    if ($successMessage !== '' && isset($_GET['success']) && $_GET['success'] === '1') {
        $stmtJ = $conn->prepare("SELECT * FROM jobs WHERE id = ? AND client_id = ? LIMIT 1");
        if ($stmtJ) {
            $stmtJ->bind_param("ii", $editJobId, $clientId);
            $stmtJ->execute();
            $resJ = $stmtJ->get_result();
            $jobRow = $resJ ? $resJ->fetch_assoc() : null;
            $stmtJ->close();
            if ($jobRow) {
                $title = (string) ($jobRow['title'] ?? '');
                $description = (string) ($jobRow['description'] ?? '');
                $location_text = (string) ($jobRow['location_text'] ?? '');
                $category_id = (int) ($jobRow['category_id'] ?? 0);
                $sub_category_id = (int) ($jobRow['sub_category_id'] ?? 0);
                $budget = (int) ($jobRow['budget'] ?? $MIN_BUDGET_PKR);
                $expiresAt = (string) ($jobRow['expires_at'] ?? '');
                if ($expiresAt && $expiresAt !== '0000-00-00 00:00:00') {
                    $expires_date = date('Y-m-d', strtotime($expiresAt));
                }
            }
        }
    }
}
?>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/phosphor-icons"></script>

<div class="min-h-screen">
    <main class="px-4 py-8">
        <div class="max-w-5xl mx-auto">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 mb-2">Post a Job</h1>
            <p class="text-sm text-slate-600 mb-6">Describe the work you need done so FixMate workers can bid on it.</p>

            <?php if (!empty($errors)): ?>
                <div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                    <ul class="list-disc list-inside space-y-1">
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($successMessage): ?>
                <div class="mb-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data"
                class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 sm:p-6 space-y-5">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Job Title <span
                            class="text-red-500">*</span></label>
                    <input type="text" name="title" required value="<?php echo htmlspecialchars($title); ?>"
                        class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Category <span
                                class="text-red-500">*</span></label>
                        <select id="category_id" name="category_id" required
                            class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Select category</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo ((int) $category_id === (int) $c['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Sub Category (optional)</label>
                        <select id="sub_category_id" name="sub_category_id"
                            class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Select sub category</option>
                        </select>
                        <p id="subcatHint" class="mt-1 text-xs text-slate-500 hidden"></p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Location</label>
                    <input type="text" name="location_text" value="<?php echo htmlspecialchars($location_text); ?>"
                        placeholder="City / Area"
                        class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                </div>

                <!-- Voice Note -->
                <div id="fm-audio-section">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Voice Note (optional)</label>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" id="fm-audio-record-btn"
                            class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-xs sm:text-sm font-semibold
                           !bg-emerald-600 text-white shadow-sm hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1">
                            <span class="ph-bold ph-microphone text-sm"></span>
                            <span class="fm-audio-record-label">Start Recording</span>
                        </button>
                        <span id="fm-audio-status" class="text-xs text-slate-500">No recording yet.</span>
                    </div>

                    <div id="fm-audio-preview" class="mt-3 hidden items-center gap-3">
                        <audio id="fm-audio-player" controls class="h-8"></audio>
                        <button type="button" id="fm-audio-delete-btn"
                            class="text-xs text-red-500 hover:text-red-700 font-medium">
                            Delete recording
                        </button>
                    </div>

                    <input type="hidden" name="audio_data" id="fm-audio-data">
                    <input type="hidden" name="audio_mime" id="fm-audio-mime">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Job Description <span
                            class="text-red-500">*</span></label>
                    <textarea name="description" rows="5" required
                        class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"><?php echo htmlspecialchars($description); ?></textarea>
                </div>

                <!-- Budget + Deadline -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">
                            Your Budget (PKR) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="budget" min="<?php echo (int) $MIN_BUDGET_PKR; ?>" step="1" required
                            value="<?php echo htmlspecialchars((string) $budget); ?>"
                            class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                        <p class="mt-1 text-[11px] text-slate-500">Minimum budget: <?php echo (int) $MIN_BUDGET_PKR; ?>
                            PKR</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">
                            Deadline <span class="text-red-500">*</span>
                        </label>
                        <?php $today = date('Y-m-d'); ?>
                        <input type="date" name="expires_date" min="<?php echo $today; ?>"
                            value="<?php echo htmlspecialchars($expires_date); ?>"
                            class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Attach Images / Short Videos</label>
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                        <button type="button" id="mediaAddBtn"
                            class="inline-flex items-center justify-center gap-2 rounded-lg !bg-indigo-600 px-4 py-2 text-xs sm:text-sm font-semibold !text-white shadow-sm hover:!bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1">
                            <span class="ph-bold ph-paperclip text-sm"></span>
                            <span>Add file</span>
                        </button>
                        <p class="text-sm text-slate-500">You can attach multiple images or short videos.</p>
                    </div>

                    <input type="file" id="mediaInput" name="media[]" multiple accept="image/*,video/*"
                        class="hidden" />
                    <div id="mediaPreviewList" class="mt-3 flex flex-wrap gap-2"></div>
                </div>

                <div class="pt-2">
                    <button type="submit"
                        class="w-full inline-flex items-center justify-center gap-2 !bg-indigo-600 text-white rounded-lg px-5 py-2 text-sm font-semibold shadow-sm hover:!bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1">
                        <span class="ph-bold ph-briefcase text-xl"></span>
                        <span>Post Job</span>
                    </button>
                </div>
            </form>

            <?php if (!empty($successMessage) && !empty($jobId)): ?>
                <div id="job-success-modal"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
                    <div class="bg-white rounded-2xl shadow-xl max-w-md w-full mx-4 p-6">
                        <h3 class="text-lg font-semibold text-slate-900">
                            <?php echo $isEdit ? 'Job updated!' : 'Job posted!'; ?>
                        </h3>
                        <p class="text-sm text-slate-600 mt-1">Redirecting you to job details…</p>
                        <div class="mt-6 flex items-center justify-end">
                            <button id="job-success-close"
                                class="inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                View Job Now
                            </button>
                        </div>
                    </div>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const closeBtn = document.getElementById('job-success-close');
                        const redirectUrl = "<?php echo '/fixmate/pages/dashboards/client/client-dashboard.php?page=job-detail&job_id=' . (int) $jobId; ?>";
                        setTimeout(() => window.location.href = redirectUrl, 2500);
                        closeBtn.addEventListener('click', () => window.location.href = redirectUrl);
                    });
                </script>
            <?php endif; ?>

        </div>
    </main>
</div>

<script>
    /** Media picker preview + EXISTING attachments (edit mode) */
    document.addEventListener('DOMContentLoaded', function () {
        const mediaInput = document.getElementById('mediaInput');
        const mediaAddBtn = document.getElementById('mediaAddBtn');
        const mediaPreviewList = document.getElementById('mediaPreviewList');
        if (!mediaInput || !mediaAddBtn || !mediaPreviewList) return;

        let mediaFiles = [];

        const existing = <?php echo json_encode($existingAttachments, JSON_UNESCAPED_SLASHES); ?>;
        const isEdit = <?php echo $isEdit ? 'true' : 'false'; ?>;
        const jobId = <?php echo (int) ($editJobId ?: 0); ?>;

        mediaAddBtn.addEventListener('click', () => mediaInput.click());

        mediaInput.addEventListener('change', function (e) {
            const files = Array.from(e.target.files || []);
            if (!files.length) return;
            files.forEach(f => mediaFiles.push(f));
            mediaInput.value = '';
            syncInputFiles();
            renderPreview();
        });

        function syncInputFiles() {
            const dt = new DataTransfer();
            mediaFiles.forEach(f => dt.items.add(f));
            mediaInput.files = dt.files;
        }

        function renderPreview() {
            mediaPreviewList.innerHTML = '';

            // 1) Show existing attachments first (edit mode)
            if (isEdit && Array.isArray(existing) && existing.length) {
                existing.forEach(att => {
                    const fileUrl = att.file_url || '';
                    const fileType = att.file_type || '';
                    const attId = parseInt(att.id || 0, 10);

                    const item = document.createElement('div');
                    item.className = 'relative flex items-center gap-2 border border-slate-200 rounded-lg px-2 py-1 bg-slate-50 max-w-[260px]';

                    let iconHtml = `<div class="h-8 w-8 rounded-full flex items-center justify-center bg-indigo-100">
                        <span class="ph-bold ph-paperclip text-xs text-indigo-700"></span>
                    </div>`;

                    if (fileType === 'image') {
                        iconHtml = `<img src="${fileUrl}" alt="" class="h-8 w-8 rounded object-cover flex-shrink-0" />`;
                    } else if (fileType === 'video') {
                        iconHtml = `<div class="h-8 w-8 rounded-full flex items-center justify-center bg-indigo-100">
                            <span class="ph-bold ph-video-camera text-xs text-indigo-700"></span>
                        </div>`;
                    } else if (fileType === 'audio') {
                        iconHtml = `<div class="h-8 w-8 rounded-full flex items-center justify-center bg-emerald-100">
                            <span class="ph-bold ph-microphone text-xs text-emerald-700"></span>
                        </div>`;
                    }

                    const name = (fileUrl.split('/').pop() || 'attachment');

                    item.innerHTML = `
                        ${iconHtml}
                        <div class="flex flex-col min-w-0">
                            <a href="${fileUrl}" target="_blank" class="truncate max-w-[170px] text-xs font-medium text-slate-700 hover:underline">${name}</a>
                            <span class="text-[10px] text-slate-400">${fileType || 'file'}</span>
                        </div>
                        <a href="/fixmate/pages/dashboards/client/post-job.php?job_id=${jobId}&delete_attachment_id=${attId}"
                           class="ml-2 text-slate-400 hover:text-red-500 text-xs flex-shrink-0">✕</a>
                    `;
                    mediaPreviewList.appendChild(item);
                });
            }

            // 2) Show newly selected files (same as your existing behavior)
            if (!mediaFiles.length && !(isEdit && existing && existing.length)) {
                mediaPreviewList.innerHTML = '<p class="text-xs text-slate-400">No files attached yet.</p>';
                return;
            }

            mediaFiles.forEach((file, index) => {
                const isImage = file.type.startsWith('image/');
                const item = document.createElement('div');
                item.className = 'relative flex items-center gap-2 border border-slate-200 rounded-lg px-2 py-1 bg-slate-50 max-w-[260px]';

                let thumbHtml = '';
                if (isImage) {
                    const url = URL.createObjectURL(file);
                    thumbHtml = `<img src="${url}" alt="" class="h-8 w-8 rounded object-cover flex-shrink-0" />`;
                } else {
                    thumbHtml = `<div class="h-8 w-8 rounded-full flex items-center justify-center bg-indigo-100">
                        <span class="ph-bold ph-video-camera text-xs text-indigo-700"></span>
                    </div>`;
                }

                item.innerHTML = `
                    ${thumbHtml}
                    <div class="flex flex-col min-w-0">
                        <span class="truncate max-w-[170px] text-xs font-medium text-slate-700">${file.name}</span>
                        <span class="text-[10px] text-slate-400">${(file.size / 1024).toFixed(1)} KB</span>
                    </div>
                    <button type="button"
                        class="ml-2 text-slate-400 hover:text-red-500 text-xs flex-shrink-0 fm-remove-file-btn"
                        data-index="${index}">✕</button>
                `;
                mediaPreviewList.appendChild(item);
            });

            mediaPreviewList.querySelectorAll('.fm-remove-file-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const idx = parseInt(this.getAttribute('data-index'), 10);
                    if (!isNaN(idx)) {
                        mediaFiles.splice(idx, 1);
                        syncInputFiles();
                        renderPreview();
                    }
                });
            });
        }

        renderPreview();
    });
</script>

<script>
    /** Subcategories loader (your same code) */
    document.addEventListener('DOMContentLoaded', function () {
        const cat = document.getElementById('category_id');
        const sub = document.getElementById('sub_category_id');
        const hint = document.getElementById('subcatHint');
        if (!cat || !sub) return;

        async function loadSubcategories(categoryId, selectedId = 0) {
            sub.innerHTML = '<option value="">Loading...</option>';
            if (hint) hint.classList.add('hidden');

            if (!categoryId) {
                sub.innerHTML = '<option value="">Select sub category</option>';
                return;
            }

            try {
                const url = "<?php echo '/fixmate/pages/dashboards/client/post-job.php?ajax=subcategories&category_id='; ?>" + encodeURIComponent(categoryId);
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const json = await res.json();
                const rows = (json && json.data) ? json.data : [];

                if (!rows.length) {
                    sub.innerHTML = '<option value="">No sub-categories</option>';
                    if (hint) {
                        hint.textContent = 'No sub-categories found for this category.';
                        hint.classList.remove('hidden');
                    }
                    return;
                }

                sub.innerHTML = '<option value="">Select sub category</option>';
                rows.forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r.id;
                    opt.textContent = r.name;
                    if (parseInt(selectedId, 10) === parseInt(r.id, 10)) opt.selected = true;
                    sub.appendChild(opt);
                });
            } catch (e) {
                sub.innerHTML = '<option value="">Failed to load</option>';
            }
        }

        const initialCat = parseInt(cat.value || "0", 10);
        const initialSub = <?php echo (int) $sub_category_id; ?>;
        if (initialCat > 0) loadSubcategories(initialCat, initialSub);

        cat.addEventListener('change', function () {
            loadSubcategories(parseInt(this.value || "0", 10), 0);
        });
    });
</script>

<script>
    /** Voice recorder (your same code) */
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('form');
        const recordBtn = document.getElementById('fm-audio-record-btn');
        const recordLabel = recordBtn?.querySelector('.fm-audio-record-label');
        const statusEl = document.getElementById('fm-audio-status');
        const previewWrap = document.getElementById('fm-audio-preview');
        const audioPlayer = document.getElementById('fm-audio-player');
        const deleteBtn = document.getElementById('fm-audio-delete-btn');
        const audioDataInput = document.getElementById('fm-audio-data');
        const audioMimeInput = document.getElementById('fm-audio-mime');
        if (!form || !recordBtn || !statusEl || !previewWrap || !audioPlayer || !deleteBtn || !audioDataInput || !audioMimeInput) return;

        let mediaRecorder = null;
        let audioChunks = [];
        let isRecording = false;
        let recordedBlob = null;
        let objectUrl = null;

        async function startRecording() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                audioChunks = [];
                mediaRecorder = new MediaRecorder(stream);

                mediaRecorder.onstart = () => {
                    isRecording = true;
                    recordedBlob = null;
                    audioDataInput.value = '';
                    audioMimeInput.value = '';

                    if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }

                    statusEl.textContent = 'Recording... (speak now)';
                    recordLabel.textContent = 'Stop Recording';
                    recordBtn.classList.remove('!bg-emerald-600', '!hover:bg-emerald-700');
                    recordBtn.classList.add('!bg-red-600', '!hover:bg-red-700');
                    previewWrap.classList.add('hidden');
                };

                mediaRecorder.ondataavailable = (e) => {
                    if (e.data && e.data.size > 0) audioChunks.push(e.data);
                };

                mediaRecorder.onstop = () => {
                    isRecording = false;
                    stream.getTracks().forEach(t => t.stop());

                    if (!audioChunks.length) {
                        statusEl.textContent = 'No audio captured.';
                        recordLabel.textContent = 'Start Recording';
                        recordBtn.classList.remove('!bg-red-600', '!hover:bg-red-700');
                        recordBtn.classList.add('!bg-emerald-600', '!hover:bg-emerald-700');
                        return;
                    }

                    recordedBlob = new Blob(audioChunks, { type: 'audio/webm' });
                    audioChunks = [];

                    objectUrl = URL.createObjectURL(recordedBlob);
                    audioPlayer.src = objectUrl;
                    previewWrap.classList.remove('hidden');

                    statusEl.textContent = 'Recording ready. You can play or delete it.';
                    recordLabel.textContent = 'Re-record';
                    recordBtn.classList.remove('!bg-red-600', '!hover:bg-red-700');
                    recordBtn.classList.add('!bg-emerald-600', '!hover:bg-emerald-700');
                };

                mediaRecorder.start();
            } catch (err) {
                statusEl.textContent = 'Microphone access denied or unavailable. (Needs HTTPS or localhost)';
            }
        }

        function stopRecording() {
            if (mediaRecorder && isRecording) mediaRecorder.stop();
        }

        recordBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                statusEl.textContent = (!window.isSecureContext)
                    ? 'Audio recording requires HTTPS or localhost.'
                    : 'Your browser does not support audio recording.';
                return;
            }
            isRecording ? stopRecording() : startRecording();
        });

        deleteBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;

            recordedBlob = null;
            audioPlayer.src = '';
            previewWrap.classList.add('hidden');
            audioDataInput.value = '';
            audioMimeInput.value = '';

            statusEl.textContent = 'Recording deleted. You can record again.';
            recordLabel.textContent = 'Start Recording';
            recordBtn.classList.remove('!bg-red-600', '!hover:bg-red-700');
            recordBtn.classList.add('!bg-emerald-600', '!hover:bg-emerald-700');
        });

        form.addEventListener('submit', function (e) {
            if (!recordedBlob || audioDataInput.value) return;

            e.preventDefault();
            const reader = new FileReader();
            reader.onloadend = function () {
                audioDataInput.value = reader.result;
                audioMimeInput.value = recordedBlob.type || 'audio/webm';
                form.submit();
            };
            reader.readAsDataURL(recordedBlob);
        });
    });
</script>