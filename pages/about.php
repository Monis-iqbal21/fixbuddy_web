<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ====== BASIC ROLE / ROUTE CONFIG FOR BUTTON LOGIC ======
$isLoggedIn = isset($_SESSION['user_id']);
$userRole   = $isLoggedIn ? ($_SESSION['role'] ?? 'guest') : 'guest';

$clientPostUrl = '/fixmate/pages/dashboards/client/post-job.php';
$workerHomeUrl = '/fixmate/pages/dashboards/worker-dashboard.php';
$adminHomeUrl  = '/fixmate/pages/admin/admin-dashboard.php';
$loginUrl      = '/fixmate/pages/login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>About Us - Fix Buddy</title>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Phosphor Icons -->
    <link rel="stylesheet" href="https://unpkg.com/phosphor-icons@1.4.2/src/css/phosphor.css">
    <script src="https://unpkg.com/phosphor-icons"></script>
</head>
<body class="bg-gray-50 text-gray-800">

    <!-- Navbar -->
    <?php include '../components/navbar.php'; ?>

    <!-- Main Content Wrapper -->
    <main class="pt-10 pb-16">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

            <!-- HERO SECTION -->
            <section class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center mb-16">
                <div>
                    <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-orange-100 text-orange-700 text-xs font-semibold">
                        <span class="ph-bold ph-sparkle text-sm"></span>
                        About Fix Buddy
                    </span>Fix Buddy
                    <h1 class="mt-4 text-3xl sm:text-4xl lg:text-5xl font-bold text-[#1d1d2b] leading-tight">
                        Connecting You with Trusted Home Service Experts, Anytime.
                    </h1>
                    <p class="mt-4 text-gray-600 text-sm sm:text-base">
                        Fix Buddy is a local home services marketplace that helps customers find verified
                        electricians, plumbers, A/C technicians, mechanics, flooring experts, pest control
                        professionals, and more — all in one place. Our mission is to make repairs, maintenance,
                        and installations simple, transparent, and stress-free.
                    </p>
                    <!-- <div class="mt-6 flex flex-wrap gap-3">
                        <a href="/fixmate/pages/viewAllJobs.php"
                           class="inline-flex items-center justify-center px-5 py-2.5 text-sm font-semibold rounded-md bg-orange-600 text-white hover:bg-orange-700 transition">
                            Find a Professional
                        </a>

                        <a href="/fixmate/pages/register.php"
                           class="inline-flex items-center justify-center px-5 py-2.5 text-sm font-semibold rounded-md border border-orange-600 text-orange-600 hover:bg-orange-50 transition">
                            Join as a Worker
                        </a>
                    </div> -->
                </div>

                <div class="relative">
                    <div class="rounded-2xl bg-[#1d1d2b] text-white p-6 sm:p-8 shadow-lg">
                        <h2 class="text-xl font-semibold mb-4">Why Fix Buddy Exists</h2>
                        <p class="text-sm text-gray-200">
                            We saw how difficult it is for people to find reliable, affordable and nearby
                            home service experts. At the same time, skilled workers struggle to find consistent
                            jobs and fair opportunities.
                        </p>
                        <p class="mt-3 text-sm text-gray-200">
                            Fix Buddy bridges this gap by creating a simple platform where clients can post jobs
                            and verified workers can apply, communicate, and complete work with clarity.
                        </p>

                        <div class="mt-5 grid grid-cols-3 gap-3 text-center text-xs">
                            <div class="border border-white/20 rounded-lg py-3">
                                <div class="font-bold text-lg">24/7</div>
                                <div class="text-gray-300">Job Posting</div>
                            </div>
                            <div class="border border-white/20 rounded-lg py-3">
                                <div class="font-bold text-lg">100+</div>
                                <div class="text-gray-300">Service Types</div>
                            </div>
                            <div class="border border-white/20 rounded-lg py-3">
                                <div class="font-bold text-lg">PK</div>
                                <div class="text-gray-300">Local Focus</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- OUR MISSION & VISION -->
            <section class="mb-16 grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="bg-white rounded-2xl shadow-sm p-6 border border-gray-100">
                    <div class="flex items-center gap-3 mb-3">
                        <span class="w-10 h-10 flex items-center justify-center rounded-full bg-orange-100 text-orange-600">
                            <span class="ph-bold ph-target text-xl"></span>
                        </span>
                        <h2 class="text-lg font-semibold text-[#1d1d2b]">Our Mission</h2>
                    </div>
                    <p class="text-sm text-gray-600 leading-relaxed">
                        Our mission is to empower both customers and skilled workers by providing a transparent,
                        secure and easy-to-use platform. We want every repair, installation, or maintenance job to
                        feel safe, professional, and fairly priced — for everyone involved.
                    </p>
                </div>

                <div class="bg-white rounded-2xl shadow-sm p-6 border border-gray-100">
                    <div class="flex items-center gap-3 mb-3">
                        <span class="w-10 h-10 flex items-center justify-center rounded-full bg-blue-100 text-blue-600">
                            <span class="ph-bold ph-eye text-xl"></span>
                        </span>
                        <h2 class="text-lg font-semibold text-[#1d1d2b]">Our Vision</h2>
                    </div>
                    <p class="text-sm text-gray-600 leading-relaxed">
                        We envision Fix Buddy as the go-to platform for home services — where clients find trusted
                        workers within minutes, and workers grow their business and reputation with consistent
                        high-quality jobs and reviews.
                    </p>
                </div>
            </section>

            <!-- HOW IT WORKS -->
            <section class="mb-16">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-[#1d1d2b]">How Fix Buddy Works</h2>
                        <p class="text-sm text-gray-600 mt-1">
                            We’ve kept the process simple for both clients and workers.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-white rounded-2xl shadow-sm p-6 border border-gray-100">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-9 h-9 flex items-center justify-center rounded-full bg-orange-600 text-white text-sm font-bold">1</span>
                            <h3 class="font-semibold text-[#1d1d2b] text-sm">Post Your Job</h3>
                        </div>
                        <p class="text-sm text-gray-600">
                            Clients quickly post a job with details like category, budget, location and timing.
                        </p>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border border-gray-100">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-9 h-9 flex items-center justify-center rounded-full bg-orange-600 text-white text-sm font-bold">2</span>
                            <h3 class="font-semibold text-[#1d1d2b] text-sm">Worker Assigned & work started</h3>
                        </div>
                        <p class="text-sm text-gray-600">
                            Professional worker send for job and deliver the work as per the requirement.
                        </p>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border border-gray-100">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-9 h-9 flex items-center justify-center rounded-full bg-orange-600 text-white text-sm font-bold">3</span>
                            <h3 class="font-semibold text-[#1d1d2b] text-sm">Work, Complete & Review</h3>
                        </div>
                        <p class="text-sm text-gray-600">
                            The work is completed as agreed and each job
                            can be rated — keeping the ecosystem fair and trustworthy.
                        </p>
                    </div>
                </div>
            </section>

            <!-- FOR CLIENTS & WORKERS -->
            <section class="mb-16 grid grid-cols-1 lg:grid-cols-1 gap-8">
                <div class="bg-[#1d1d2b] text-white rounded-2xl p-7 shadow-md">
                    <h2 class="text-xl font-semibold mb-3 flex items-center gap-2">
                        <span class="ph-bold ph-user-circle text-2xl"></span>
                        For Clients
                    </h2>
                    <ul class="mt-3 space-y-2 text-sm text-gray-100">
                        <li class="flex gap-2">
                            <span class="mt-1 ph-bold ph-check-circle text-green-400"></span>
                            <span>Post jobs for free and receive multiple offers from nearby experts.</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="mt-1 ph-bold ph-check-circle text-green-400"></span>
                            <span>View worker profiles, past jobs, and ratings before hiring.</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="mt-1 ph-bold ph-check-circle text-green-400"></span>
                            <span>Stay updated with job status, confirmations, and completion tracking.</span>
                        </li>
                    </ul>

                    <!-- POST YOUR FIRST JOB – uses same fm-nav-post-job logic as navbar -->
                    <button
                        type="button"
                        class="fm-nav-post-job inline-flex mt-5 px-4 py-2.5 text-xs sm:text-sm rounded-md bg-orange-500 hover:bg-orange-600 font-semibold"
                        data-logged-in="<?php echo $isLoggedIn ? '1' : '0'; ?>"
                        data-role="<?php echo htmlspecialchars($userRole); ?>"
                        data-client-post="<?php echo htmlspecialchars($clientPostUrl); ?>"
                        data-worker-home="<?php echo htmlspecialchars($workerHomeUrl); ?>"
                        data-admin-home="<?php echo htmlspecialchars($adminHomeUrl); ?>"
                        data-login="<?php echo htmlspecialchars($loginUrl); ?>"
                    >
                    </button>
                </div>

                <!-- <div class="bg-white border border-gray-100 rounded-2xl p-7 shadow-sm">
                    <h2 class="text-xl font-semibold mb-3 flex items-center gap-2 text-[#1d1d2b]">
                        <span class="ph-bold ph-briefcase text-2xl text-orange-500"></span>
                        For Workers / Vendors
                    </h2>
                    <ul class="mt-3 space-y-2 text-sm text-gray-700">
                        <li class="flex gap-2">
                            <span class="mt-1 ph-bold ph-check-circle text-orange-500"></span>
                            <span>Access a steady stream of local jobs related to your skills.</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="mt-1 ph-bold ph-check-circle text-orange-500"></span>
                            <span>Build your FixMate profile and grow your reputation through reviews.</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="mt-1 ph-bold ph-check-circle text-orange-500"></span>
                            <span>Transparent system for bids, job assignment, and completion confirmations.</span>
                        </li>
                    </ul>
                    <a href="/fixmate/pages/register.php"
                       class="inline-flex mt-5 px-4 py-2.5 text-xs sm:text-sm rounded-md border border-orange-500 text-orange-600 hover:bg-orange-50 font-semibold">
                    </a>
                </div> -->
            </section>

            <!-- WHY CHOOSE FIXMATE -->
            <section class="mb-16">
                <div class="text-center mb-8">
                    <h2 class="text-2xl font-bold text-[#1d1d2b]">Why Choose Fix Buddy?</h2>
                    <p class="mt-2 text-sm text-gray-600 max-w-2xl mx-auto">
                        We’re not just another listing website. Fix Buddy is a full workflow platform for managing
                        home service jobs from posting to completion.
                    </p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="bg-white rounded-2xl shadow-sm p-6 border border-gray-100">
                        <span class="ph-bold ph-shield-check text-2xl text-orange-500"></span>
                        <h3 class="mt-3 text-sm font-semibold text-[#1d1d2b]">Verified Workers</h3>
                        <p class="mt-2 text-xs text-gray-600">
                            We aim to maintain a network of trusted experts with profile checks and reviews.
                        </p>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border border-gray-100">
                        <span class="ph-bold ph-chats-circle text-2xl text-orange-500"></span>
                        <h3 class="mt-3 text-sm font-semibold text-[#1d1d2b]">Clear Communication</h3>
                        <p class="mt-2 text-xs text-gray-600">
                            Clients and workers stay aligned on job details, timing, and expectations.
                        </p>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border border-gray-100">
                        <span class="ph-bold ph-clock-afternoon text-2xl text-orange-500"></span>
                        <h3 class="mt-3 text-sm font-semibold text-[#1d1d2b]">Fast & Flexible</h3>
                        <p class="mt-2 text-xs text-gray-600">
                            Whether it’s an urgent fix or scheduled maintenance, you can find the right expert fast.
                        </p>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border border-gray-100">
                        <span class="ph-bold ph-star text-2xl text-orange-500"></span>
                        <h3 class="mt-3 text-sm font-semibold text-[#1d1d2b]">Review System</h3>
                        <p class="mt-2 text-xs text-gray-600">
                            Ratings and feedback help build trust and highlight top-performing workers.
                        </p>
                    </div>
                </div>
            </section>

            <!-- CTA SECTION -->
            <section class="bg-[#1d1d2b] text-white rounded-3xl px-6 sm:px-10 py-8 sm:py-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
                <div>
                    <h2 class="text-xl sm:text-2xl font-bold">Ready to experience hassle-free home services?</h2>
                    <p class="mt-2 text-sm text-gray-200 max-w-xl">
                        Whether you’re a client looking for a trusted professional or a skilled worker seeking
                        more opportunities, Fix Buddy is built for you.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <!-- CTA Post a Job – same fm-nav-post-job logic -->
                    <button
                        type="button"
                        class="fm-nav-post-job inline-flex items-center justify-center px-5 py-2.5 text-sm font-semibold rounded-md bg-orange-500 hover:bg-orange-600"
                        data-logged-in="<?php echo $isLoggedIn ? '1' : '0'; ?>"
                        data-role="<?php echo htmlspecialchars($userRole); ?>"
                        data-client-post="<?php echo htmlspecialchars($clientPostUrl); ?>"
                        data-worker-home="<?php echo htmlspecialchars($workerHomeUrl); ?>"
                        data-admin-home="<?php echo htmlspecialchars($adminHomeUrl); ?>"
                        data-login="<?php echo htmlspecialchars($loginUrl); ?>"
                    >
                    </button>

                    <a href="/fixmate/pages/auth/register.php"
                       class="inline-flex items-center justify-center px-5 py-2.5 text-sm font-semibold rounded-md border border-white/40 hover:bg-white hover:text-[#1d1d2b]">
                    </a>
                </div>
            </section>

        </div>
    </main>

    <!-- Footer -->
    <?php include '../components/footer.php'; ?>

</body>
</html>
<script>
document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('.fm-nav-post-job').forEach(function (btn) {

        btn.addEventListener('click', function () {

            const loggedIn   = btn.dataset.loggedIn === "1";
            const role       = btn.dataset.role;
            const loginUrl   = btn.dataset.login;

            // NEW corrected client post URL
            const postJobUrl = "/fixmate/pages/dashboards/client-dashboard.php?page=post-job";

            const workerHome = btn.dataset.workerHome;
            const adminHome  = btn.dataset.adminHome;

            // 1️⃣ IF NOT LOGGED IN → SEND TO LOGIN FIRST
            if (!loggedIn) {
                window.location.href = loginUrl + "?redirect=client_post_job";
                return;
            }

            // 2️⃣ CLIENT → Go directly to correct post-job page
            if (role === "client") {
                window.location.href = postJobUrl;
                return;
            }

            // 3️⃣ WORKER → Show warning + send to worker home
            if (role === "worker") {

                if (typeof showVendorWarningCard === "function") {
                    showVendorWarningCard();
                }

                setTimeout(() => {
                    window.location.href = workerHome;
                }, 1500);

                return;
            }

            // 4️⃣ ADMIN → Send to admin home
            if (role === "admin") {
                window.location.href = adminHome;
                return;
            }

            // fallback
            window.location.href = loginUrl;
        });
    });

});
</script>
