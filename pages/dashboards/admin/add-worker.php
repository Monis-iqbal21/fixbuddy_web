<?php
// /fixmate/pages/dashboards/admin/add-worker.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/db.php';

// ---------------------------
// AUTH GUARD (admin only)
// ---------------------------
if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'] ?? '')) !== 'admin') {
    echo "<p class='text-red-600 p-4'>Access denied.</p>";
    exit;
}

$adminId = (int)($_SESSION['user_id'] ?? 0);

// ---------------------------
// CONFIG
// ---------------------------
$UPLOAD_DIR = __DIR__ . '/../../../uploads/profile_images/';
$UPLOAD_URL_BASE = '/fixmate/uploads/profile_images';

// ---------------------------
// MODE: ADD vs EDIT
// ---------------------------
$workerId = (int)($_GET['worker_id'] ?? 0);
$isEdit = ($workerId > 0);

// ---------------------------
// LOAD CATEGORIES
// ---------------------------
$categories = [];
$stmtCat = $conn->prepare("SELECT id, name FROM categories ORDER BY name ASC");
if ($stmtCat) {
    $stmtCat->execute();
    $res = $stmtCat->get_result();
    $categories = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmtCat->close();
}

// ---------------------------
// HELPERS
// ---------------------------
function fm_ext_from_mime(string $mime, string $fallbackExt): string {
    $map = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (isset($map[$mime])) return $map[$mime];

    $fallbackExt = strtolower(preg_replace('/[^a-z0-9]/i', '', $fallbackExt));
    return $fallbackExt ?: 'png';
}

function fm_try_delete_profile_image(string $publicUrl, string $uploadDir, string $uploadUrlBase): void {
    $publicUrl = trim($publicUrl);
    if ($publicUrl === '') return;
    if (strpos($publicUrl, $uploadUrlBase) !== 0) return;

    $fileName = basename($publicUrl);
    if ($fileName === '' || $fileName === '.' || $fileName === '..') return;

    $full = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;
    if (is_file($full)) {
        @unlink($full);
    }
}

function fm_format_cnic_display(string $digits): string {
    $digits = preg_replace('/\D/', '', $digits);
    $digits = substr($digits, 0, 13);
    if (strlen($digits) !== 13) return $digits;
    return substr($digits, 0, 5) . '-' . substr($digits, 5, 7) . '-' . substr($digits, 12, 1);
}

// ---------------------------
// FORM STATE
// ---------------------------
$errors = [];
$success = '';

$full_name = '';
$phone = '';
$email = '';
$cnic = '';
$city = '';
$area = '';
$address = '';
$is_available = 1;
$is_active = 1;
$selectedCategories = [];
$currentProfileImage = '';

// ---------------------------
// IF EDIT MODE: LOAD WORKER + CATEGORIES
// ---------------------------
if ($isEdit) {
    $stmtW = $conn->prepare("SELECT * FROM workers WHERE id = ? LIMIT 1");
    if (!$stmtW) {
        echo "<p class='text-red-600 p-4'>SQL error: " . htmlspecialchars($conn->error) . "</p>";
        exit;
    }
    $stmtW->bind_param("i", $workerId);
    $stmtW->execute();
    $resW = $stmtW->get_result();
    $rowW = $resW ? $resW->fetch_assoc() : null;
    $stmtW->close();

    if (!$rowW) {
        echo "<p class='text-red-600 p-4'>Worker not found.</p>";
        exit;
    }

    $full_name = (string)($rowW['full_name'] ?? '');
    $phone     = (string)($rowW['phone'] ?? '');
    $email     = (string)($rowW['email'] ?? '');
    $cnic      = fm_format_cnic_display((string)($rowW['cnic'] ?? ''));
    $city      = (string)($rowW['city'] ?? '');
    $area      = (string)($rowW['area'] ?? '');
    $address   = (string)($rowW['address'] ?? '');
    $is_available = (int)($rowW['is_available'] ?? 1) ? 1 : 0;
    $is_active    = (int)($rowW['is_active'] ?? 1) ? 1 : 0;
    $currentProfileImage = (string)($rowW['profile_image'] ?? '');

    // load selected categories
    $stmtWC = $conn->prepare("SELECT category_id FROM worker_categories WHERE worker_id = ?");
    if ($stmtWC) {
        $stmtWC->bind_param("i", $workerId);
        $stmtWC->execute();
        $resWC = $stmtWC->get_result();
        if ($resWC) {
            while ($r = $resWC->fetch_assoc()) {
                $selectedCategories[] = (string)((int)$r['category_id']);
            }
        }
        $stmtWC->close();
    }
}

// ---------------------------
// SUCCESS AFTER REDIRECT (PRG)
// ---------------------------
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $success = $isEdit ? "Worker updated successfully." : "Worker added successfully.";
}

// ---------------------------
// HANDLE SUBMIT (ADD or EDIT)
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // worker_id hidden field (important in edit)
    $postWorkerId = (int)($_POST['worker_id'] ?? 0);
    $isEditPost = ($postWorkerId > 0);

    $full_name = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $cnicInput = trim($_POST['cnic'] ?? '');

    // CNIC digits only for DB
    $cnic_digits = preg_replace('/\D/', '', $cnicInput);

    if ($cnicInput !== '' && strlen($cnic_digits) !== 13) {
        $errors[] = "CNIC must be exactly 13 digits (format: 42101-3356589-3).";
    }

    $cnic_db = $cnic_digits;          // save in DB without dashes
    $cnic    = fm_format_cnic_display($cnic_digits); // keep formatted for UI

    $city      = trim($_POST['city'] ?? '');
    $area      = trim($_POST['area'] ?? '');
    $address   = trim($_POST['address'] ?? '');

    $is_available = isset($_POST['is_available']) ? 1 : 0;
    $is_active    = isset($_POST['is_active']) ? 1 : 0;

    $selectedCategories = $_POST['categories'] ?? [];
    if (!is_array($selectedCategories)) $selectedCategories = [];
    $selectedCategories = array_map('strval', $selectedCategories);

    // ---------------------------
    // VALIDATION
    // ---------------------------
    if ($full_name === '') $errors[] = "Full name is required.";
    if ($phone === '') $errors[] = "Phone number is required.";

    $phoneNormalized = preg_replace('/[^\d\+]/', '', $phone);
    if ($phoneNormalized === '') {
        $errors[] = "Phone number looks invalid.";
    } else {
        $phone = $phoneNormalized;
    }

    // Phone uniqueness check (exclude self in edit)
    if (empty($errors)) {
        if ($isEditPost) {
            $chk = $conn->prepare("SELECT id FROM workers WHERE phone = ? AND id <> ? LIMIT 1");
            if ($chk) {
                $chk->bind_param("si", $phone, $postWorkerId);
                $chk->execute();
                $r = $chk->get_result();
                if ($r && $r->num_rows > 0) $errors[] = "This phone number is already used by another worker.";
                $chk->close();
            }
        } else {
            $chk = $conn->prepare("SELECT id FROM workers WHERE phone = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param("s", $phone);
                $chk->execute();
                $r = $chk->get_result();
                if ($r && $r->num_rows > 0) $errors[] = "This phone number is already used by another worker.";
                $chk->close();
            }
        }
    }

    if (empty($selectedCategories)) {
        $errors[] = "Please select at least 1 category for this worker.";
    }

    // ---------------------------
    // SAVE
    // ---------------------------
    if (empty($errors)) {
        $conn->begin_transaction();
        try {

            if ($isEditPost) {
                // Ensure exists
                $stmtExists = $conn->prepare("SELECT profile_image FROM workers WHERE id = ? LIMIT 1");
                if (!$stmtExists) throw new Exception("Prepare failed: " . $conn->error);
                $stmtExists->bind_param("i", $postWorkerId);
                $stmtExists->execute();
                $rEx = $stmtExists->get_result();
                $exRow = $rEx ? $rEx->fetch_assoc() : null;
                $stmtExists->close();

                if (!$exRow) throw new Exception("Worker not found.");

                $oldImage = (string)($exRow['profile_image'] ?? '');

                // Update base fields
                $stmtU = $conn->prepare("
                    UPDATE workers
                    SET full_name = ?,
                        phone = ?,
                        email = ?,
                        cnic = ?,
                        city = ?,
                        area = ?,
                        address = ?,
                        is_available = ?,
                        is_active = ?,
                        updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");
                if (!$stmtU) throw new Exception("Prepare failed: " . $conn->error);

                $stmtU->bind_param(
                    "sssssssiii",
                    $full_name,
                    $phone,
                    $email,
                    $cnic_db,
                    $city,
                    $area,
                    $address,
                    $is_available,
                    $is_active,
                    $postWorkerId
                );
                if (!$stmtU->execute()) throw new Exception("Update worker failed: " . $stmtU->error);
                $stmtU->close();

                // Replace profile image if uploaded
                if (!empty($_FILES['profile_image']['name']) && ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {

                    if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0775, true);

                    $tmpName = $_FILES['profile_image']['tmp_name'] ?? '';
                    if ($tmpName && is_uploaded_file($tmpName)) {

                        $mime = mime_content_type($tmpName) ?: '';
                        if (strpos($mime, 'image/') !== 0) {
                            throw new Exception("Profile image must be an image file.");
                        }

                        // delete old image file
                        fm_try_delete_profile_image($oldImage, $UPLOAD_DIR, $UPLOAD_URL_BASE);

                        $origName = (string)($_FILES['profile_image']['name'] ?? 'image.png');
                        $fallbackExt = pathinfo($origName, PATHINFO_EXTENSION);
                        $ext = fm_ext_from_mime($mime, $fallbackExt);

                        $rand = substr(bin2hex(random_bytes(6)), 0, 6);
                        $fileName = "worker{$postWorkerId}_{$rand}." . $ext;

                        $target = rtrim($UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $fileName;
                        if (!move_uploaded_file($tmpName, $target)) {
                            throw new Exception("Failed to upload profile image.");
                        }

                        $publicUrl = rtrim($UPLOAD_URL_BASE, '/') . '/' . $fileName;

                        $up = $conn->prepare("UPDATE workers SET profile_image = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                        if ($up) {
                            $up->bind_param("si", $publicUrl, $postWorkerId);
                            $up->execute();
                            $up->close();
                        }

                        $currentProfileImage = $publicUrl;
                    }
                }

                // Update categories pivot
                $delWC = $conn->prepare("DELETE FROM worker_categories WHERE worker_id = ?");
                if (!$delWC) throw new Exception("Prepare delete worker_categories failed: " . $conn->error);
                $delWC->bind_param("i", $postWorkerId);
                if (!$delWC->execute()) throw new Exception("Delete worker categories failed: " . $delWC->error);
                $delWC->close();

                $insWC = $conn->prepare("INSERT INTO worker_categories (worker_id, category_id) VALUES (?, ?)");
                if (!$insWC) throw new Exception("Prepare worker_categories failed: " . $conn->error);

                foreach ($selectedCategories as $cid) {
                    $cid = (int)$cid;
                    if ($cid <= 0) continue;
                    $insWC->bind_param("ii", $postWorkerId, $cid);
                    if (!$insWC->execute()) throw new Exception("Insert worker category failed: " . $insWC->error);
                }
                $insWC->close();

                $conn->commit();

                // Redirect (no header, safe inside include)
                $redirectUrl = "admin-dashboard.php?page=add-worker&worker_id=" . (int)$postWorkerId . "&success=1";
                echo "<script>window.location.href=" . json_encode($redirectUrl) . ";</script>";
                exit;

            } else {
                // ADD MODE
                $profile_image = '';

                $stmt = $conn->prepare("
                    INSERT INTO workers
                        (full_name, phone, email, cnic, city, area, address, profile_image, is_available, is_active, rating_avg, jobs_completed, created_at, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW(), NOW())
                ");
                if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

                // ✅ fixed types
                $stmt->bind_param(
                    "ssssssssii",
                    $full_name,
                    $phone,
                    $email,
                    $cnic_db,
                    $city,
                    $area,
                    $address,
                    $profile_image,
                    $is_available,
                    $is_active
                );

                if (!$stmt->execute()) throw new Exception("Insert worker failed: " . $stmt->error);

                $newWorkerId = (int)$conn->insert_id;
                $stmt->close();

                if ($newWorkerId <= 0) throw new Exception("Could not get worker ID.");

                // Upload profile image if provided
                if (!empty($_FILES['profile_image']['name']) && ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {

                    if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0775, true);

                    $tmpName = $_FILES['profile_image']['tmp_name'] ?? '';
                    if ($tmpName && is_uploaded_file($tmpName)) {

                        $mime = mime_content_type($tmpName) ?: '';
                        if (strpos($mime, 'image/') !== 0) {
                            throw new Exception("Profile image must be an image file.");
                        }

                        $origName = (string)($_FILES['profile_image']['name'] ?? 'image.png');
                        $fallbackExt = pathinfo($origName, PATHINFO_EXTENSION);
                        $ext = fm_ext_from_mime($mime, $fallbackExt);

                        $rand = substr(bin2hex(random_bytes(6)), 0, 6);
                        $fileName = "worker{$newWorkerId}_{$rand}." . $ext;

                        $target = rtrim($UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $fileName;
                        if (!move_uploaded_file($tmpName, $target)) {
                            throw new Exception("Failed to upload profile image.");
                        }

                        $publicUrl = rtrim($UPLOAD_URL_BASE, '/') . '/' . $fileName;

                        $up = $conn->prepare("UPDATE workers SET profile_image = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                        if ($up) {
                            $up->bind_param("si", $publicUrl, $newWorkerId);
                            $up->execute();
                            $up->close();
                        }
                    }
                }

                // Insert worker categories
                $insWC = $conn->prepare("INSERT INTO worker_categories (worker_id, category_id) VALUES (?, ?)");
                if (!$insWC) throw new Exception("Prepare worker_categories failed: " . $conn->error);

                foreach ($selectedCategories as $cid) {
                    $cid = (int)$cid;
                    if ($cid <= 0) continue;
                    $insWC->bind_param("ii", $newWorkerId, $cid);
                    if (!$insWC->execute()) throw new Exception("Insert worker category failed: " . $insWC->error);
                }
                $insWC->close();

                $conn->commit();

                // Redirect (prevents resubmission)
                echo "<script>window.location.href=" . json_encode("admin-dashboard.php?page=add-worker&success=1") . ";</script>";
                exit;
            }

        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = $e->getMessage();
        }
    }
}
?>

<div class="mx-8">
    <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
        <h2 class="text-lg font-semibold text-slate-900"><?php echo $isEdit ? 'Edit Worker' : 'Add Worker'; ?></h2>

        <?php if ($isEdit): ?>
            <a href="admin-dashboard.php?page=workers"
               class="text-xs px-3 py-1 rounded-md border border-slate-200 text-slate-700 hover:bg-slate-50">
               Back to Workers
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            <ul class="list-disc pl-5">
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="bg-white border border-slate-200 rounded-xl p-4">
        <!-- IMPORTANT: keep worker_id hidden for edit -->
        <input type="hidden" name="worker_id" value="<?php echo (int)$workerId; ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

            <div class="md:col-span-2">
                <label class="text-xs font-semibold text-slate-700">Full Name <span class="text-rose-500">*</span></label>
                <input type="text" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" required
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700">Phone (unique) <span class="text-rose-500">*</span></label>
                <input type="text" name="phone" value="<?php echo htmlspecialchars($phone); ?>" required
                       placeholder="03xx1234567"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700">Email</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700">CNIC</label>
                <input type="text" name="cnic" value="<?php echo htmlspecialchars($cnic); ?>"
                       placeholder="42101-1234567-1"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700">City</label>
                <input type="text" name="city" value="<?php echo htmlspecialchars($city); ?>"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700">Area</label>
                <input type="text" name="area" value="<?php echo htmlspecialchars($area); ?>"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
            </div>

            <div class="md:col-span-2">
                <label class="text-xs font-semibold text-slate-700">Address</label>
                <input type="text" name="address" value="<?php echo htmlspecialchars($address); ?>"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
            </div>

            <div class="md:col-span-2">
                <label class="text-xs font-semibold text-slate-700">Profile Image</label>

                <?php if ($isEdit && !empty($currentProfileImage)): ?>
                    <div class="mt-2 flex items-center gap-3">
                        <img src="<?php echo htmlspecialchars($currentProfileImage); ?>"
                             class="w-14 h-14 rounded-full object-cover border border-slate-200"
                             alt="Current profile">
                        <p class="text-[11px] text-slate-500">Current image (upload a new one to replace)</p>
                    </div>
                <?php endif; ?>

                <input type="file" name="profile_image" accept="image/*"
                       class="mt-2 block w-full text-sm text-slate-600
                              file:mr-3 file:rounded-lg file:border-0
                              file:bg-slate-100 file:px-4 file:py-2
                              file:text-sm file:font-semibold
                              file:text-slate-700 hover:file:bg-slate-200" />
                <p class="text-[11px] text-slate-400 mt-1">Saved as /fixmate/uploads/profile_images/worker{ID}_xxxxxx.ext</p>
            </div>

            <div class="md:col-span-2">
                <label class="text-xs font-semibold text-slate-700">Categories <span class="text-rose-500">*</span></label>
                <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                    <?php foreach ($categories as $c): ?>
                        <?php $cid = (int)$c['id']; ?>
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 bg-slate-50">
                            <input type="checkbox" name="categories[]"
                                   value="<?php echo $cid; ?>"
                                   <?php echo in_array((string)$cid, array_map('strval', $selectedCategories), true) ? 'checked' : ''; ?>
                                   class="rounded border-slate-300" />
                            <span class="text-sm text-slate-700"><?php echo htmlspecialchars($c['name']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="md:col-span-2 flex items-center gap-6 mt-2">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="is_available" <?php echo $is_available ? 'checked' : ''; ?>
                           class="rounded border-slate-300" />
                    <span class="text-sm text-slate-700">Available</span>
                </label>

                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="is_active" <?php echo $is_active ? 'checked' : ''; ?>
                           class="rounded border-slate-300" />
                    <span class="text-sm text-slate-700">Active</span>
                </label>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <button type="submit"
                    class="text-sm px-4 py-2 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700">
                <?php echo $isEdit ? 'Update Worker' : 'Save Worker'; ?>
            </button>
        </div>
    </form>

    <div class="mt-4 text-[12px] text-slate-500">
        <p class="font-semibold text-slate-700">How “jobs completed” will be calculated later:</p>
        <p>
            We will count rows from <code class="px-1 py-0.5 bg-slate-100 rounded">job_worker_assignments</code>
            where <code class="px-1 py-0.5 bg-slate-100 rounded">ended_at IS NOT NULL</code>
            and/or jobs.status = completed.
        </p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const cnicInput = document.querySelector('input[name="cnic"]');
    if (!cnicInput) return;

    cnicInput.setAttribute('maxlength', '15');
    cnicInput.setAttribute('placeholder', '42101-3356589-3');

    cnicInput.addEventListener('input', function () {
        let digits = this.value.replace(/\D/g, '');
        digits = digits.substring(0, 13);

        let formatted = '';
        if (digits.length > 0) formatted = digits.substring(0, 5);
        if (digits.length >= 6) formatted += '-' + digits.substring(5, 12);
        if (digits.length >= 13) formatted += '-' + digits.substring(12, 13);

        this.value = formatted;
    });
});
</script>
