<?php
// /fixmate/pages/dashboards/shared/profile-manage.php
// ✅ Paste same code for BOTH:
// - /fixmate/pages/dashboards/client/profile-manage.php
// - /fixmate/pages/dashboards/admin/profile-manage.php
//
// Features (client/admin):
// - Change password (current + new + confirm) with hashing
// - Update profile picture (upload) with safe validation
// - Schema-safe: detects available columns (profile_image/avatar/image, password/password_hash)
// - Uses sessions for flash messages
//
// Assumptions:
// - users table stores client/admin users (common in your project)
// - users.id = session user_id
// - users.role column exists (or session role already trusted)
// - profile image column could be: profile_image OR avatar OR image
// - password column could be: password OR password_hash
//
// If your project stores admin in another table, tell me table name and I’ll adjust.

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
// Detect users table columns (schema-safe)
// ---------------------------
$USER_COLS = [];
try {
    $rc = $conn->query("SHOW COLUMNS FROM users");
    if ($rc) {
        while ($row = $rc->fetch_assoc()) {
            $USER_COLS[strtolower((string)$row['Field'])] = true;
        }
    }
} catch (Throwable $e) {}

$HAS = function (string $col) use ($USER_COLS): bool {
    return isset($USER_COLS[strtolower($col)]);
};

function fm_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fm_pick_user_col(array $candidates, callable $HAS): string
{
    foreach ($candidates as $c) {
        if ($HAS($c)) return $c;
    }
    return '';
}

function fm_redirect(string $url): void
{
    echo "<script>window.location.href=" . json_encode($url) . ";</script>";
    exit;
}

function fm_is_image_mime(string $mime): bool
{
    $mime = strtolower(trim($mime));
    return in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true);
}

function fm_safe_ext_from_mime(string $mime): string
{
    $mime = strtolower(trim($mime));
    return match ($mime) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => 'jpg',
    };
}

// ---------------------------
// Column mapping
// ---------------------------
$PASSWORD_COL = fm_pick_user_col(['password_hash', 'password'], $HAS);
$IMAGE_COL    = fm_pick_user_col(['profile_image', 'avatar', 'image', 'profile_pic'], $HAS);

if ($PASSWORD_COL === '') {
    echo "<p class='text-red-600 p-4'>users table must have password_hash or password column.</p>";
    return;
}
if ($IMAGE_COL === '') {
    // still allow password change even if no image column
}

// ---------------------------
// Fetch current user
// ---------------------------
$user = null;

$selectCols = "id";
if ($HAS('name'))  $selectCols .= ", name";
if ($HAS('email')) $selectCols .= ", email";
$selectCols .= ", {$PASSWORD_COL}";
if ($IMAGE_COL !== '') $selectCols .= ", {$IMAGE_COL}";

$st = $conn->prepare("SELECT {$selectCols} FROM users WHERE id = ? LIMIT 1");
if ($st) {
    $st->bind_param("i", $userId);
    $st->execute();
    $r = $st->get_result();
    $user = ($r && $r->num_rows === 1) ? $r->fetch_assoc() : null;
    $st->close();
}

if (!$user) {
    echo "<p class='text-red-600 p-4'>User not found.</p>";
    return;
}

// ---------------------------
// Flash
// ---------------------------
$flashErr = (string)($_SESSION['flash_profile_err'] ?? '');
$flashOk  = (string)($_SESSION['flash_profile_ok'] ?? '');
unset($_SESSION['flash_profile_err'], $_SESSION['flash_profile_ok']);

// ---------------------------
// Handle POST
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = (string)($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    if (!hash_equals($CSRF, $token)) {
        $_SESSION['flash_profile_err'] = "Security check failed (CSRF). Refresh and try again.";
        fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
    }

    // Refresh user for latest password hash
    $fresh = null;
    $st2 = $conn->prepare("SELECT id, {$PASSWORD_COL}" . ($IMAGE_COL ? ", {$IMAGE_COL}" : "") . " FROM users WHERE id = ? LIMIT 1");
    if ($st2) {
        $st2->bind_param("i", $userId);
        $st2->execute();
        $r2 = $st2->get_result();
        $fresh = ($r2 && $r2->num_rows === 1) ? $r2->fetch_assoc() : null;
        $st2->close();
    }
    if (!$fresh) {
        $_SESSION['flash_profile_err'] = "User not found.";
        fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
    }

    // ---------------------------
    // Change Password
    // ---------------------------
    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new1    = (string)($_POST['new_password'] ?? '');
        $new2    = (string)($_POST['confirm_password'] ?? '');

        $current = trim($current);
        $new1 = trim($new1);
        $new2 = trim($new2);

        if ($current === '' || $new1 === '' || $new2 === '') {
            $_SESSION['flash_profile_err'] = "Please fill all password fields.";
            fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
        }
        if ($new1 !== $new2) {
            $_SESSION['flash_profile_err'] = "New password and confirm password do not match.";
            fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
        }
        if (strlen($new1) < 8) {
            $_SESSION['flash_profile_err'] = "New password must be at least 8 characters.";
            fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
        }

        $stored = (string)($fresh[$PASSWORD_COL] ?? '');
        $stored = trim($stored);

        $verified = false;

        // If stored looks like bcrypt hash, verify. Otherwise fallback to plain compare (for legacy).
        if ($stored !== '' && (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$argon2'))) {
            $verified = password_verify($current, $stored);
        } else {
            // legacy plain-text (not recommended but common in older code)
            $verified = hash_equals($stored, $current);
        }

        if (!$verified) {
            $_SESSION['flash_profile_err'] = "Current password is incorrect.";
            fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
        }

        $newHash = password_hash($new1, PASSWORD_BCRYPT);

        $up = $conn->prepare("UPDATE users SET {$PASSWORD_COL} = ? WHERE id = ? LIMIT 1");
        if (!$up) {
            $_SESSION['flash_profile_err'] = "Failed to update password.";
            fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
        }

        $up->bind_param("si", $newHash, $userId);
        $ok = $up->execute();
        $up->close();

        if (!$ok) {
            $_SESSION['flash_profile_err'] = "Failed to update password.";
        } else {
            $_SESSION['flash_profile_ok'] = "Password updated successfully.";
        }

        fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
    }

    // ---------------------------
    // Update Profile Picture
    // ---------------------------
    if ($action === 'update_photo') {
        if ($IMAGE_COL === '') {
            $_SESSION['flash_profile_err'] = "Your users table has no profile image column.";
            fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
        }

        if (!isset($_FILES['profile_image']) || !is_array($_FILES['profile_image'])) {
            $_SESSION['flash_profile_err'] = "No file uploaded.";
            fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
        }

        $f = $_FILES['profile_image'];

        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $_SESSION['flash_profile_err'] = "Upload failed. Please try again.";
            fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
        }

        $maxBytes = 3 * 1024 * 1024; // 3MB
        $size = (int)($f['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            $_SESSION['flash_profile_err'] = "Image size must be less than 3MB.";
            fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
        }

        $tmp = (string)($f['tmp_name'] ?? '');
        $mime = '';
        if (function_exists('mime_content_type')) {
            $mime = (string)@mime_content_type($tmp);
        }
        if (!$mime) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)@finfo_file($finfo, $tmp);
                @finfo_close($finfo);
            }
        }

        if (!fm_is_image_mime($mime)) {
            $_SESSION['flash_profile_err'] = "Only JPG, PNG, WEBP images are allowed.";
            fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
        }

        $ext = fm_safe_ext_from_mime($mime);

        // Destination directory (web accessible)
        $uploadDir = __DIR__ . '/../../../uploads/profile';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $filename = "u{$userId}_" . date('YmdHis') . "_" . bin2hex(random_bytes(4)) . "." . $ext;
        $destPath = rtrim($uploadDir, '/') . '/' . $filename;

        if (!@move_uploaded_file($tmp, $destPath)) {
            $_SESSION['flash_profile_err'] = "Failed to save uploaded image.";
            fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
        }

        // Save URL path
        $publicUrl = "/fixmate/uploads/profile/" . $filename;

        // Optional: delete old file (only if it’s in same folder)
        $old = trim((string)($fresh[$IMAGE_COL] ?? ''));
        if ($old !== '' && str_starts_with($old, "/fixmate/uploads/profile/")) {
            $oldName = basename($old);
            $oldPath = rtrim($uploadDir, '/') . '/' . $oldName;
            if (is_file($oldPath)) @unlink($oldPath);
        }

        $up = $conn->prepare("UPDATE users SET {$IMAGE_COL} = ? WHERE id = ? LIMIT 1");
        if (!$up) {
            $_SESSION['flash_profile_err'] = "Failed to update profile picture.";
            fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
        }

        $up->bind_param("si", $publicUrl, $userId);
        $ok = $up->execute();
        $up->close();

        if (!$ok) {
            $_SESSION['flash_profile_err'] = "Failed to update profile picture.";
        } else {
            // update session name/image if you use it elsewhere
            $_SESSION['flash_profile_ok'] = "Profile picture updated successfully.";
        }

        fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
    }

    $_SESSION['flash_profile_err'] = "Unknown action.";
    fm_redirect(($role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php') . "?page=profile-manage");
}

// ---------------------------
// Render
// ---------------------------
$name  = (string)($user['name'] ?? ($_SESSION['name'] ?? 'User'));
$email = (string)($user['email'] ?? '');
$img   = $IMAGE_COL !== '' ? trim((string)($user[$IMAGE_COL] ?? '')) : '';
if ($img === '') $img = "/fixmate/assets/images/avatar-default.png";

$dash = $role === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php';
?>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/phosphor-icons"></script>

<div class="px-4 sm:px-6 lg:px-8 py-6">
    <div class="max-w-4xl">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">Profile Settings</h2>
                <p class="text-sm text-slate-500">Update your profile picture and password.</p>
            </div>

            <a href="<?php echo fm_h($dash . '?page=home'); ?>"
               class="text-xs px-3 py-2 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50">
                Back to Dashboard
            </a>
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

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

            <!-- Profile card -->
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-3">Your Profile</h3>

                <div class="flex items-center gap-3">
                    <img src="<?php echo fm_h($img); ?>"
                         class="w-14 h-14 rounded-full object-cover border border-slate-200"
                         alt="Profile">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900 truncate"><?php echo fm_h($name); ?></p>
                        <?php if ($email !== ''): ?>
                            <p class="text-xs text-slate-500 truncate"><?php echo fm_h($email); ?></p>
                        <?php endif; ?>
                        <p class="text-[11px] text-slate-400 mt-1">Role: <?php echo fm_h($role); ?></p>
                    </div>
                </div>

                <?php if ($IMAGE_COL !== ''): ?>
                    <form method="POST" enctype="multipart/form-data" class="mt-4 space-y-2">
                        <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
                        <input type="hidden" name="action" value="update_photo">

                        <label class="text-xs font-semibold text-slate-700">Change profile picture</label>
                        <input type="file" name="profile_image" accept="image/png,image/jpeg,image/webp"
                               class="block w-full text-sm text-slate-700 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">

                        <p class="text-[11px] text-slate-500">Allowed: JPG, PNG, WEBP • Max 3MB</p>

                        <button type="submit"
                                class="mt-1 inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            Update Photo
                        </button>
                    </form>
                <?php else: ?>
                    <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                        Your database doesn’t have a profile image column, so photo update is disabled.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Password card -->
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-3">Change Password</h3>

                <form method="POST" class="space-y-3" onsubmit="return validatePwForm();">
                    <input type="hidden" name="csrf_token" value="<?php echo fm_h($CSRF); ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div>
                        <label class="text-xs font-semibold text-slate-700">Current password</label>
                        <input type="password" name="current_password" required
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-700">New password</label>
                        <input id="newPw" type="password" name="new_password" required minlength="8"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <p class="text-[11px] text-slate-500 mt-1">Minimum 8 characters.</p>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-700">Confirm new password</label>
                        <input id="confirmPw" type="password" name="confirm_password" required minlength="8"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <p id="pwHint" class="text-[11px] text-rose-600 mt-1 hidden">Passwords do not match.</p>
                    </div>

                    <button type="submit"
                            class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                        Update Password
                    </button>
                </form>

                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[11px] text-slate-600">
                    Tip: Use a strong password (mix letters, numbers, and symbols).
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function validatePwForm(){
    const n = document.getElementById('newPw');
    const c = document.getElementById('confirmPw');
    const hint = document.getElementById('pwHint');

    if(!n || !c || !hint) return true;
    if(n.value !== c.value){
        hint.classList.remove('hidden');
        return false;
    }
    hint.classList.add('hidden');
    return true;
}
</script>
