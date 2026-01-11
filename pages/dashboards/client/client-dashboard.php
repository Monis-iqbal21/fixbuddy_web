<?php
// client-dashboard.php

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../../config/db.php';

// ---------------------------
// SECURITY HELPERS
// ---------------------------
function fm_redirect_login() {
    header("Location: ../../auth/login.php");
    exit;
}

function fm_redirect_404() {
    header("Location: ../../404page.php");
    exit;
}

function loginWithToken(mysqli $conn, string $token): bool {
    $token = trim($token);
    if ($token === '') return false;

    $stmt = $conn->prepare("SELECT id, name, email, role, token FROM users WHERE token = ? LIMIT 1");
    if (!$stmt) return false;

    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $user = $res->fetch_assoc();
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['name']    = $user['name'] ?? '';
        $_SESSION['email']   = $user['email'] ?? '';
        $_SESSION['role']    = $user['role'] ?? '';
        $_SESSION['token']   = $user['token'] ?? '';
        $stmt->close();
        return true;
    }
    $stmt->close();
    return false;
}

// ---------------------------
// AUTH GUARD
// ---------------------------
if (!isset($_SESSION['user_id'])) {
    if (!empty($_GET['token'])) {
        if (!loginWithToken($conn, (string)$_GET['token'])) {
            fm_redirect_login();
        }
    } else {
        fm_redirect_login();
    }
}

// Role guard (ONLY client)
if (strtolower(trim($_SESSION['role'] ?? '')) !== 'client') {
    fm_redirect_404();
}

$clientId = (int)($_SESSION['user_id'] ?? 0);
$stmtRole = $conn->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
if (!$stmtRole) fm_redirect_404();
$stmtRole->bind_param("i", $clientId);
$stmtRole->execute();
$roleRes = $stmtRole->get_result();
if (!$roleRes || $roleRes->num_rows !== 1) {
    $stmtRole->close();
    fm_redirect_login();
}
$dbUser = $roleRes->fetch_assoc();
$stmtRole->close();

if (strtolower(trim($dbUser['role'] ?? '')) !== 'client') {
    fm_redirect_404();
}

// ---------------------------
// FETCH CLIENT INFO
// ---------------------------
$clientName   = $_SESSION['name'] ?? 'Client';
$clientEmail  = $_SESSION['email'] ?? '';
$clientAvatar = '/fixmate/assets/images/avatar-default.png';

$stmtUser = $conn->prepare("SELECT name, email, profile_image FROM users WHERE id = ? LIMIT 1");
if ($stmtUser) {
    $stmtUser->bind_param('i', $clientId);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result();
    if ($resUser && $resUser->num_rows === 1) {
        $u = $resUser->fetch_assoc();
        $clientName  = $u['name'] ?? $clientName;
        $clientEmail = $u['email'] ?? $clientEmail;
        if (!empty($u['profile_image'])) {
            $clientAvatar = $u['profile_image'];
        }
    }
    $stmtUser->close();
}

// ---------------------------
// PAGE ROUTING
// ---------------------------
$page = $_GET['page'] ?? 'dashboard';

$pages = [
    'dashboard'     => './dashboard-home.php',
    'your-jobs'     => './jobs-status.php',
    'post-job'      => './post-job.php',
    'reviews'       => './reviews.php',
    'profile'       => './profile.php',
    'notifications' => './notifications.php',
    'messages'      => './messages.php',
    'job-detail'    => './job-detail.php',
    'logout'        => '../auth/logout.php',
];

if (!array_key_exists($page, $pages)) {
    $page = 'dashboard';
}

$contentFile = __DIR__ . '/' . $pages[$page];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Dashboard</title>

    <link rel="stylesheet" href="../../assets/css/custom.css">
    <link rel="stylesheet" href="../../assets/css/leaflet.css">
    <link rel="stylesheet" href="../../assets/css/output-scss.css">
    <link rel="stylesheet" href="../../assets/css/quil.snow.css">
    <link rel="stylesheet" href="../../assets/css/slick.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/swiper-bundle.min.css">
    <link rel="stylesheet" href="../../assets/css/apexcharts.css">
    <link rel="stylesheet" href="../../assets/css/output-tailwind.css">

    <script src="https://unpkg.com/phosphor-icons"></script>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@3.3.3/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        /* Smooth transition for sidebar on mobile */
        #sidebar-menu {
            transition: transform 0.3s ease-in-out;
        }
        /* Active link styling */
        .link.active {
            background-color: #F3F4F6; /* gray-100 */
            color: #1F2937; /* gray-800 */
        }
    </style>
</head>

<body class="bg-gray-50 h-screen w-full flex overflow-hidden">

    <div id="sidebar-overlay" onclick="closeSidebar()" 
         class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden glass transition-opacity">
    </div>

    <aside id="sidebar-menu" 
           class="fixed lg:static inset-y-0 left-0 z-40 w-[280px] bg-white border-r border-gray-200 transform -translate-x-full lg:translate-x-0 flex flex-col h-full flex-shrink-0">
        
        <div class="h-16 flex items-center px-6 border-b border-gray-100">
            <a href="/fixmate/" class="flex items-center gap-2">
                <img src="/fixmate/assets/images/fixmate_dark.png" alt="FixMate" class="h-8 w-auto object-contain">
            </a>
            <button onclick="closeSidebar()" class="ml-auto lg:hidden text-gray-500 hover:text-red-500">
                <span class="ph ph-x text-2xl"></span>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto py-6 px-4 scrollbar_custom">
            
            <div class="mb-6 p-3 bg-gray-50 rounded-xl border border-gray-100">
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars($clientAvatar, ENT_QUOTES, 'UTF-8'); ?>" 
                         alt="Profile" 
                         class="w-10 h-10 rounded-full object-cover border border-gray-200">
                    <div class="min-w-0 overflow-hidden">
                        <p class="text-sm font-bold text-gray-800 truncate">
                            <?php echo htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <p class="text-xs text-gray-500 truncate">
                            <?php echo htmlspecialchars($clientEmail, ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    </div>
                </div>
                <div class="mt-2 text-right">
                    <a href="client-dashboard.php?page=profile" class="text-xs text-blue-600 font-medium hover:underline">
                        Manage Profile &rarr;
                    </a>
                </div>
            </div>

            <nav class="space-y-1">
                <p class="px-2 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Menu</p>

                <a href="client-dashboard.php?page=dashboard" 
                   class="link flex items-center gap-3 px-4 py-3 rounded-lg text-gray-600 hover:bg-gray-50 hover:text-black transition-colors <?php echo $page === 'dashboard' ? 'active font-semibold' : ''; ?>">
                    <span class="ph-duotone ph-squares-four text-xl"></span>
                    <span>Dashboard</span>
                </a>

                <a href="client-dashboard.php?page=your-jobs" 
                   class="link flex items-center gap-3 px-4 py-3 rounded-lg text-gray-600 hover:bg-gray-50 hover:text-black transition-colors <?php echo $page === 'your-jobs' ? 'active font-semibold' : ''; ?>">
                    <span class="ph-duotone ph-briefcase text-xl"></span>
                    <span>Your Jobs</span>
                </a>

                <a href="client-dashboard.php?page=post-job" 
                   class="link flex items-center gap-3 px-4 py-3 rounded-lg text-gray-600 hover:bg-gray-50 hover:text-black transition-colors <?php echo $page === 'post-job' ? 'active font-semibold' : ''; ?>">
                    <span class="ph-duotone ph-chalkboard-teacher text-xl"></span>
                    <span>Post a Job</span>
                </a>

                <a href="client-dashboard.php?page=notifications" 
                   class="link flex items-center gap-3 px-4 py-3 rounded-lg text-gray-600 hover:bg-gray-50 hover:text-black transition-colors <?php echo $page === 'notifications' ? 'active font-semibold' : ''; ?>">
                    <span class="ph-duotone ph-bell text-xl"></span>
                    <span>Notifications</span>
                </a>

                <a href="client-dashboard.php?page=reviews" 
                   class="link flex items-center gap-3 px-4 py-3 rounded-lg text-gray-600 hover:bg-gray-50 hover:text-black transition-colors <?php echo $page === 'reviews' ? 'active font-semibold' : ''; ?>">
                    <span class="ph-duotone ph-star text-xl"></span>
                    <span>Reviews</span>
                </a>

                <a href="client-dashboard.php?page=profile" 
                   class="link flex items-center gap-3 px-4 py-3 rounded-lg text-gray-600 hover:bg-gray-50 hover:text-black transition-colors <?php echo $page === 'profile' ? 'active font-semibold' : ''; ?>">
                    <span class="ph-duotone ph-user-circle text-xl"></span>
                    <span>Profile</span>
                </a>

                <div class="pt-4 mt-4 border-t border-gray-100">
                    <a href="../../auth/logout.php" 
                       class="link flex items-center gap-3 px-4 py-3 rounded-lg text-red-500 hover:bg-red-50 transition-colors">
                        <span class="ph-duotone ph-sign-out text-xl"></span>
                        <span>Logout</span>
                    </a>
                </div>
            </nav>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-full relative overflow-hidden">
        
        <header class="lg:hidden bg-white h-16 flex items-center justify-between px-4 border-b border-gray-200 flex-shrink-0">
            <div class="flex items-center gap-3">
                <button onclick="openSidebar()" class="p-2 -ml-2 rounded-md text-gray-600 hover:bg-gray-100 focus:outline-none">
                    <span class="ph ph-list text-2xl"></span>
                </button>
                <span class="font-bold text-lg text-gray-800">FixMate</span>
            </div>
            <div class="w-8 h-8 rounded-full overflow-hidden border border-gray-200">
                <img src="<?php echo htmlspecialchars($clientAvatar); ?>" class="w-full h-full object-cover">
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
            
            <div class="max-w-9xl mx-auto">
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">
                        Hello, <span class="text-indigo-600"><?php echo htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8'); ?></span>
                    </h1>
                    <p class="text-sm text-gray-500">Welcome back to your dashboard.</p>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 min-h-[400px] p-1">
                    <?php
                    if (file_exists($contentFile)) {
                        include $contentFile;
                    } else {
                        echo '<div class="p-8 text-center text-red-500">Error: Content file not found.</div>';
                    }
                    ?>
                </div>

                <div class="mt-8 py-6 text-center border-t border-gray-200 text-xs text-gray-400">
                    &copy;<?php echo date('Y'); ?> FixMate. All Rights Reserved.
                </div>
            </div>

        </div>
    </main>

    <div class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 opacity-0 transition-opacity" id="delete-modal">
        <div class="bg-white rounded-lg w-[90vw] max-w-lg shadow-xl transform scale-95 transition-transform">
            <div class="flex items-center justify-between py-4 px-6 border-b border-gray-100">
                <h5 class="text-lg font-bold text-red-600">Delete</h5>
                <button class="text-gray-400 hover:text-gray-600" onclick="/* close modal logic here */">
                    <span class="ph ph-x text-2xl"></span>
                </button>
            </div>
            </div>
    </div>

    <script src="../../assets/js/jquery.min.js"></script>
    <script src="../../assets/js/phosphor-icons.js"></script>
    <script src="../../assets/js/slick.min.js"></script>
    <script src="../../assets/js/leaflet.js"></script>
    <script src="../../assets/js/swiper-bundle.min.js"></script>
    <script src="../../assets/js/main.js"></script>

    <script>
        const sidebar = document.getElementById('sidebar-menu');
        const overlay = document.getElementById('sidebar-overlay');

        function openSidebar() {
            // Remove the hidden class (which puts it off screen) and slide it in
            sidebar.classList.remove('-translate-x-full');
            // Show overlay
            overlay.classList.remove('hidden');
        }

        function closeSidebar() {
            // Slide it back off screen
            sidebar.classList.add('-translate-x-full');
            // Hide overlay
            overlay.classList.add('hidden');
        }
    </script>
</body>
</html>