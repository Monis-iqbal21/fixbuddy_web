<?php
session_start();

require_once '../../config/db.php';
$app = require '../../config/app.php';

// ----------------- FLASH (errors + sticky) -----------------
$error = $_SESSION['flash_error'] ?? '';
$phone = $_SESSION['flash_phone'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_phone']);

// ----------------- JOB_ID PARAM -----------------
$jobId = 0;
if (!empty($_GET['job_id']))  $jobId = (int)$_GET['job_id'];
if (!empty($_POST['job_id'])) $jobId = (int)$_POST['job_id'];

// ----------------- REDIRECT KEY -----------------
$redirectKeyInitial = $_GET['redirect'] ?? ($_POST['redirect'] ?? '');

// ----------------- REGISTER URL (preserve intent) -----------------
$queryParams = [];
if (!empty($redirectKeyInitial)) $queryParams['redirect'] = $redirectKeyInitial;
if (!empty($jobId)) $queryParams['job_id'] = $jobId;

$registerUrl = 'register.php';
if (!empty($queryParams)) $registerUrl .= '?' . http_build_query($queryParams);

// helper: redirect back to login with flash + preserve query
function fm_login_back(string $msg, string $phone, int $jobId, string $redirectKeyInitial) {
    $_SESSION['flash_error'] = $msg;
    $_SESSION['flash_phone'] = $phone;

    $q = [];
    if (!empty($redirectKeyInitial)) $q['redirect'] = $redirectKeyInitial;
    if (!empty($jobId)) $q['job_id'] = $jobId;

    $url = 'login.php';
    if (!empty($q)) $url .= '?' . http_build_query($q);

    header("Location: {$url}");
    exit;
}

// ----------------- LOGIN -----------------
if (isset($_POST['login_btn'])) {

    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $redirectKey = $_POST['redirect'] ?? ($_GET['redirect'] ?? '');

    if ($phone === '' || $password === '') {
        fm_login_back("Phone number and password are required.", $phone, $jobId, $redirectKeyInitial);
    }

    if (!preg_match('/^03[0-9]{9}$/', $phone)) {
        fm_login_back("Invalid phone number format.", $phone, $jobId, $redirectKeyInitial);
    }

    $stmt = $conn->prepare("
        SELECT id, name, phone, email, password_hash, role, is_active
        FROM users
        WHERE phone = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $res = $stmt->get_result();

    if (!$res || $res->num_rows !== 1) {
        $stmt->close();
        fm_login_back("No account found with this phone number.", $phone, $jobId, $redirectKeyInitial);
    }

    $user = $res->fetch_assoc();
    $stmt->close();

    if ((int)$user['is_active'] !== 1) {
        fm_login_back("Your account is inactive. Please contact support.", $phone, $jobId, $redirectKeyInitial);
    }

    if (!password_verify($password, $user['password_hash'])) {
        fm_login_back("Incorrect password.", $phone, $jobId, $redirectKeyInitial);
    }

    // ✅ SUCCESS: Session + redirect (PRG)
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['phone']   = $user['phone'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = strtolower($user['role']);
    $_SESSION['token']   = bin2hex(random_bytes(32));

    // --------- JOB REDIRECT ---------
    if ($jobId > 0) {
        if ($_SESSION['role'] === 'admin') {
            header("Location: /fixmate/pages/admin/job-detail.php?job_id={$jobId}");
        } elseif ($_SESSION['role'] === 'client') {
            header("Location: /fixmate/pages/dashboards/client/client-dashboard.php?page=job-detail&job_id={$jobId}");
        }else{
            header("Location: /fixmate/pages/404page.php");
        }
        exit;
    }

    // --------- SPECIAL REDIRECT ---------
    if ($_SESSION['role'] === 'client' && $redirectKey === 'client_post_job') {
        header("Location: /fixmate/pages/dashboards/client/client-dashboard.php?page=post-job");
        exit;
    }

    // --------- DASHBOARD ---------
    if ($_SESSION['role'] === 'admin') {
        header("Location: /fixmate/pages/dashboards/admin/admin-dashboard.php");
    } elseif ($_SESSION['role'] === 'client') {
        header("Location: /fixmate/pages/dashboards/client/client-dashboard.php");
    }else{
        header("Location: /fixmate/pages/404page.php");
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login – <?php echo $app['app_name']; ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary-dark: #1E293B;
            --secondary-color: #F97316;
            --btn-color: #06B6D4;
            --bg-color: #F8FAFC;
        }
    </style>
    <style>
        :root {
            --primary-color: #ff6b35;
            /* Orange accent */
            --dark-bg: #13151f;
            /* Dark Navy/Black */
            --card-dark: #1c1f2e;
            /* Slightly lighter dark for cards */
            --light-bg: #f9f9f9;
            --text-dark: #1f2937;
            --text-gray: #6b7280;
            --white: #ffffff;
        }
    </style>
</head>

<body class="bg-[var(--primary-dark)]">

    <?php include '../../components/navbar.php'; ?>

    <section class="relative min-h-[80vh] flex items-center justify-center py-10">

        <!-- <div
            class="absolute top-0 left-0 w-[500px] h-[500px] bg-[var(--secondary-color)]/10 rounded-full blur-[100px] -translate-x-1/2 -translate-y-1/2">
        </div>
        <div
            class="absolute bottom-0 right-0 w-[500px] h-[500px] bg-[var(--btn-color)]/10 rounded-full blur-[100px] translate-x-1/2 translate-y-1/2">
        </div> -->

        <div class="relative z-10 w-full max-w-md bg-white rounded-3xl shadow-xl p-8">

            <div class="h-2 w-full bg-[var(--secondary-color)] absolute top-0 left-0 rounded-t-3xl"></div>

            <div class="text-center mb-8">
                <span class="text-[var(--secondary-color)] text-xs font-bold uppercase">Welcome Back</span>
                <h2 class="text-3xl font-extrabold text-[var(--primary-dark)] mt-2">
                    Login to <?php echo $app['app_name']; ?>
                </h2>
                <p class="text-gray-400 text-sm mt-2">Use your phone number to continue</p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-600 p-4 mb-6 text-sm rounded">
                    <i class="fa-solid fa-circle-exclamation mr-2"></i><?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5">

                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectKeyInitial); ?>">
                <input type="hidden" name="job_id" value="<?php echo $jobId ?: ''; ?>">

                <div>
                    <label class="block text-sm font-semibold mb-2">Phone Number</label>
                    <input type="tel" name="phone" required maxlength="11"
                        value="<?php echo htmlspecialchars($phone); ?>" placeholder="03XXXXXXXXX" class="w-full px-4 py-3 rounded-xl border bg-[var(--bg-color)]
focus:ring-2 focus:ring-[var(--secondary-color)]/30 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2">Password</label>
                    <input type="password" name="password" required placeholder="••••••••" class="w-full px-4 py-3 rounded-xl border bg-[var(--bg-color)]
focus:ring-2 focus:ring-[var(--secondary-color)]/30 outline-none">
                </div>

                <button name="login_btn" class="w-full py-3 mt-4 rounded-xl text-white font-bold
bg-[var(--btn-color)] hover:opacity-90 transition">
                    Sign In
                </button>

                <div class="text-center text-sm mt-4">
                    Don’t have an account?
                    <a href="<?php echo $registerUrl; ?>"
                        class="font-bold text-[var(--secondary-color)] hover:underline">
                        Create account
                    </a>
                </div>

            </form>
        </div>
    </section>

    <?php include '../../components/footer.php'; ?>

    <script>
        document.querySelector('input[name="phone"]').addEventListener('input', function () {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
        });
    </script>

</body>

</html>