<?php
// components/navbar.php (Client + Admin only, responsive, uses $app variables + existing colors from :root)
// RULES YOU WANT:
// ✅ If screen width <= 700px  → ONLY show: Logo + "Hi, Name" + Burger
// ✅ If screen width >= 701px  → show ALL: links + buttons + everything (no hiding)
// ✅ Only roles: client + admin
// ✅ Remove "Find a Job"
// ✅ Post a Job: guest -> login redirect, client -> dashboard post-job, admin -> hide
// ✅ No worker logic
// ✅ Same logo filename + use :root colors

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$app = $app ?? (file_exists(__DIR__ . '/../config/app.php') ? require __DIR__ . '/../config/app.php' : []);
$appName = $app['app_name'] ?? 'FixMate';

// Session
$isLoggedIn = isset($_SESSION['user_id']);
$userName   = $isLoggedIn ? ($_SESSION['name'] ?? '') : '';
$userRole   = strtolower(trim($isLoggedIn ? ($_SESSION['role'] ?? '') : '')); // client/admin/empty

// Safe display name
$displayName = 'User';
if ($userName !== '') {
    $parts = preg_split('/\s+/', trim($userName));
    $displayName = !empty($parts[0]) ? $parts[0] : $userName;
}

// Paths
$homeUrl     = '/fixmate/';
$aboutUrl    = '/fixmate/pages/about.php';
$contactUrl  = '/fixmate/pages/contact.php';

$loginUrl    = '/fixmate/pages/auth/login.php';
$registerUrl = '/fixmate/pages/auth/register.php';
$logoutUrl   = '/fixmate/pages/auth/logout.php';

$clientDashboard = '/fixmate/pages/dashboards/client/client-dashboard.php';
$adminDashboard  = '/fixmate/pages/dashboards/admin/admin-dashboard.php';

// Post Job destination (inside client dashboard routing)
$clientPostJobUrl = $clientDashboard . '?page=post-job';

// Logo
$logoPath = '/fixmate/assets/images/logo_fixmate.png';

// Decide dashboard link
$dashboardUrl = ($userRole === 'admin') ? $adminDashboard : $clientDashboard;

// Post a Job button visibility: guest + client only
$showPostJob = (!$isLoggedIn || $userRole === 'client');

// Post a Job target: guest -> login redirect, client -> post-job
$postJobHref = (!$isLoggedIn)
    ? ($loginUrl . '?redirect=client_post_job')
    : $clientPostJobUrl;

// Active link helper
function fm_active($needle) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return (strpos($uri, $needle) !== false) ? 'fm-active' : '';
}
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>

<style>
    /* Uses your existing :root variables. Fallbacks just in case */
    .fm-nav {
        background: var(--primary-dark, #1E293B) !important;
        position: sticky;
        top: 0;
        z-index: 50;
        width: 100%;
        box-shadow: 0 6px 18px rgba(0,0,0,0.25);
    }

    .fm-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 16px;
        height: 72px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }

    .fm-brand {
        display:flex;
        align-items:center;
        gap:10px;
        min-width: 160px;
    }

    .fm-brand img {
        height: 44px;
        width: auto;
        object-fit: contain;
        display:block;
    }

    .fm-links {
        display:flex;
        align-items:center;
        gap: 18px;
    }

    .fm-link {
        color: rgba(255,255,255,0.85) !important;
        font-weight: 600;
        font-size: 14px;
        text-decoration:none;
        padding: 10px 10px;
        border-radius: 10px;
        transition: all .2s ease;
        white-space: nowrap;
    }

    .fm-link:hover {
        background: rgba(255,255,255,0.06) !important;
        color: #fff;
    }

    .fm-active {
        color: #fff;
        background: rgba(255,255,255,0.08) !important;
    }

    .fm-btn {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        padding: 10px 14px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 13px;
        text-decoration:none;
        transition: all .2s ease;
        white-space: nowrap;
        border: 1px solid transparent !important;
        cursor: pointer;
        background: transparent !important;
    }

    .fm-btn-primary {
        background: var(--primary-color) !important;
        color: var(--white) !important;
    }
    .fm-btn-primary:hover { opacity: .92 !important; transform: translateY(-1px); }

    .fm-btn-outline {
        border-color: var(--primary-color) !important;
        color: var(--primary-color) !important;
        background: transparent !important;
    }
    .fm-btn-outline:hover {
        background: rgba(255,107,53,0.12) !important;
    }

    /* RIGHT AREA split into: greeting(always visible on <=700) + actions(hidden on <=700) */
    .fm-right-desktop {
        display:flex;
        align-items:center;
        gap: 10px;
    }

    .fm-user-greeting {
        display:flex;
        align-items:center;
        gap: 6px;
        color: rgba(255,255,255,0.92) !important;
        font-size: 13px;
        font-weight: 700;
        white-space: nowrap;
    }

    .fm-user-greeting small {
        color: rgba(255,255,255,0.65) !important;
        font-weight: 700;
    }

    .fm-right-actions {
        display:flex;
        align-items:center;
        gap: 10px;
    }

    /* Burger */
    .fm-burger {
        display:none;
        width: 42px;
        height: 42px;
        border-radius: 12px;
        border: 1px solid rgba(255,255,255,0.12) !important;
        background: rgba(255,255,255,0.06) !important;
        color: #fff;
        align-items:center;
        justify-content:center;
        cursor:pointer;
        transition: all .2s ease;
    }
    .fm-burger:hover { background: rgba(255,255,255,0.1) !important; }

    /* Mobile drawer */
    .fm-mobile {
        display:none;
        background: var(--dark-bg) !important;
        border-top: 1px solid rgba(255,255,255,0.08) !important;
        padding: 12px 16px 18px;
    }

    .fm-mobile .fm-link,
    .fm-mobile .fm-btn {
        width: 100%;
        justify-content: flex-start;
    }

    .fm-mobile .fm-block {
        display:flex;
        flex-direction: column;
        gap: 10px;
        margin-top: 10px;
    }

    /* =========================
       ✅ YOUR REQUIRED BREAKPOINT
       <= 700px : ONLY show greeting + burger (hide links + action buttons)
       >= 701px : show EVERYTHING
       ========================= */
    @media (max-width: 700px) {
        .fm-links { display:none; }
        .fm-right-actions { display:none; } /* hides Dashboard/Logout/Login/Register + role pill */
        .fm-burger { display:flex; }

        .fm-mobile[aria-hidden="false"] { display:block; }
    }
</style>

<nav class="fm-nav" role="navigation" aria-label="<?php echo htmlspecialchars($appName); ?> Navigation">
    <div class="fm-container">
        <!-- Brand -->
        <a class="fm-brand" href="<?php echo $homeUrl; ?>" aria-label="<?php echo htmlspecialchars($appName); ?> Home">
            <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="<?php echo htmlspecialchars($appName); ?> Logo">
        </a>

        <!-- Desktop Links (visible ONLY >= 701px) -->
        <div class="fm-links" aria-label="Primary">
            <a class="fm-link <?php echo fm_active('/fixmate/'); ?>" href="<?php echo $homeUrl; ?>">Home</a>
            <a class="fm-link <?php echo fm_active('/pages/about.php'); ?>" href="<?php echo $aboutUrl; ?>">About Us</a>

            <?php if ($showPostJob): ?>
                <a class="fm-link" href="<?php echo htmlspecialchars($postJobHref); ?>">Post a Job</a>
            <?php endif; ?>

            <a class="fm-link <?php echo fm_active('/pages/contact.php'); ?>" href="<?php echo $contactUrl; ?>">Contact Us</a>
        </div>

        <!-- Right side: Greeting + Actions -->
        <div class="fm-right-desktop">
            <!-- ✅ ALWAYS visible on <=700px -->
            <div class="fm-user-greeting">
                <small>Hi,</small>
                <span><?php echo htmlspecialchars($isLoggedIn ? $displayName : 'Guest'); ?></span>
            </div>

            <!-- ❌ Hidden on <=700px -->
            <div class="fm-right-actions">
                <?php if ($isLoggedIn): ?>
                    <a class="fm-btn fm-btn-primary" href="<?php echo htmlspecialchars($dashboardUrl); ?>">
                        <i class="fa-solid fa-gauge-high"></i> Dashboard
                    </a>

                    <a class="fm-btn fm-btn-outline" href="<?php echo $logoutUrl; ?>">
                        <i class="fa-solid fa-right-from-bracket"></i> Logout
                    </a>
                <?php else: ?>
                    <a class="fm-btn fm-btn-outline" href="<?php echo $loginUrl; ?>">
                        <i class="fa-solid fa-right-to-bracket"></i> Login
                    </a>
                    <a class="fm-btn fm-btn-primary" href="<?php echo $registerUrl; ?>">
                        <i class="fa-solid fa-user-plus"></i> Register
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Burger (visible ONLY <=700px) -->
        <button
            id="fmBurger"
            class="fm-burger"
            type="button"
            aria-label="Open menu"
            aria-expanded="false"
            aria-controls="fmMobileMenu"
        >
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>

    <!-- Mobile Menu -->
    <div id="fmMobileMenu" class="fm-mobile" aria-hidden="true">
        <div class="fm-block">
            <a class="fm-link" href="<?php echo $homeUrl; ?>">Home</a>
            <a class="fm-link" href="<?php echo $aboutUrl; ?>">About Us</a>

            <?php if ($showPostJob): ?>
                <a class="fm-link" href="<?php echo htmlspecialchars($postJobHref); ?>">Post a Job</a>
            <?php endif; ?>

            <a class="fm-link" href="<?php echo $contactUrl; ?>">Contact Us</a>

            <div style="height:1px; background: rgba(255,255,255,0.08) !important; margin: 6px 0;"></div>

            <?php if ($isLoggedIn): ?>
                <a class="fm-btn fm-btn-primary" href="<?php echo htmlspecialchars($dashboardUrl); ?>">
                    <i class="fa-solid fa-gauge-high"></i> Dashboard
                </a>

                <a class="fm-btn fm-btn-outline" href="<?php echo $logoutUrl; ?>">
                    <i class="fa-solid fa-right-from-bracket"></i> Logout
                </a>
            <?php else: ?>
                <a class="fm-btn fm-btn-outline" href="<?php echo $loginUrl; ?>">
                    <i class="fa-solid fa-right-to-bracket"></i> Login
                </a>
                <a class="fm-btn fm-btn-primary" href="<?php echo $registerUrl; ?>">
                    <i class="fa-solid fa-user-plus"></i> Register
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<script>
(function () {
    const burger = document.getElementById('fmBurger');
    const menu = document.getElementById('fmMobileMenu');

    if (!burger || !menu) return;

    function closeMenu() {
        burger.setAttribute('aria-expanded', 'false');
        menu.setAttribute('aria-hidden', 'true');
        const i = burger.querySelector('i');
        if (i) { i.classList.remove('fa-xmark'); i.classList.add('fa-bars'); }
    }

    function openMenu() {
        burger.setAttribute('aria-expanded', 'true');
        menu.setAttribute('aria-hidden', 'false');
        const i = burger.querySelector('i');
        if (i) { i.classList.remove('fa-bars'); i.classList.add('fa-xmark'); }
    }

    burger.addEventListener('click', function (e) {
        e.stopPropagation();
        const expanded = burger.getAttribute('aria-expanded') === 'true';
        expanded ? closeMenu() : openMenu();
    });

    document.addEventListener('click', function (e) {
        const expanded = burger.getAttribute('aria-expanded') === 'true';
        if (!expanded) return;
        if (!menu.contains(e.target) && !burger.contains(e.target)) closeMenu();
    });

    menu.querySelectorAll('a,button').forEach(el => {
        el.addEventListener('click', closeMenu);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMenu();
    });

    // ✅ Auto-close when resizing back to desktop (>= 701)
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 701) closeMenu();
    });
})();
</script>
