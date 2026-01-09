<?php
// index.php (Dynamic Home Page — uses global $app + dynamic categories + app phone variable)
// NOTE: UI/design unchanged. Only categories + search + CTA phone + app name are dynamic.

if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/config/db.php';
$app = require __DIR__ . '/config/app.php';

// ---------- Paths ----------
$clientPostJobPage = "/fixmate/pages/dashboards/client/client-dashboard.php?page=post-job";

// Detect login + role
$isLoggedIn = isset($_SESSION['user_id']);
$role = strtolower(trim($_SESSION['role'] ?? ''));

// Book Now logic
$bookNowUrl = ($isLoggedIn && $role === 'client')
    ? $clientPostJobPage
    : "/fixmate/pages/auth/login.php?redirect=client_post_job";

// ---------- Categories (Dynamic) ----------
$categories = [];
try {
    $catSql = "SELECT id, name FROM categories ORDER BY name ASC";
    $catRes = $conn->query($catSql);
    if ($catRes) {
        $categories = $catRes->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) {
    $categories = [];
}

// category icon map (keep your UI; icons only)
$categoryIconMap = [
    'electrician' => 'fa-bolt',
    'plumber' => 'fa-faucet',
    'ac technician' => 'fa-snowflake',
    'a/c technician' => 'fa-snowflake',
    'ac solutions' => 'fa-snowflake',
    'car mechanic' => 'fa-car',
    'flooring' => 'fa-layer-group',
    'pest control' => 'fa-bug',
];

// category description map (keep UI; only text)
$categoryDescMap = [
    'electrician' => 'Expert service for wiring, installation and repairs.',
    'plumber' => 'Reliable leak detection, pipe cleaning and drain services.',
    'ac technician' => 'Installation, repair and maintenance for your cooling systems.',
    'a/c technician' => 'Installation, repair and maintenance for your cooling systems.',
    'car mechanic' => 'Professional repair, oil changes and engine diagnostics.',
    'flooring' => 'Installation and repair of wood, laminate, tile and more.',
    'pest control' => 'Effective treatments for termite, insect and rodent control.',
];

// helper: slug
function fm_slug(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s, '_');
}

// ---------- Reviews (Testimonials) ----------
$reviews = [];
try {
    $reviewSql = "
        SELECT 
            r.rating, 
            r.comment, 
            u.name AS reviewer_name, 
            u.role AS reviewer_position 
        FROM reviews r
        JOIN users u ON r.reviewer_id = u.id
        WHERE r.reviewer_role = 'client'
          AND r.is_public = 1
        ORDER BY r.created_at DESC
        LIMIT 9
    ";
    $reviewStmt = $conn->prepare($reviewSql);
    if ($reviewStmt) {
        $reviewStmt->execute();
        $reviews = $reviewStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $reviewStmt->close();
    }
} catch (Throwable $e) {
    $reviews = [];
}

// ---------- Safe app vars ----------
$appName = htmlspecialchars($app['app_name'] ?? 'FixMate', ENT_QUOTES, 'UTF-8');
$appPhone = htmlspecialchars($app['app_phone'] ?? '111-222-333', ENT_QUOTES, 'UTF-8'); // add in app.php

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $appName; ?> - Home</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/phosphor-icons"></script>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@3.3.3/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--white);
            color: var(--text-dark);
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
        }

        ul {
            list-style: none;
        }

        /* Utilities */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .text-primary {
            color: var(--primary-color);
        }

        .btn {
            display: inline-block;
            padding: 12px 28px;
            border-radius: 5px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            border: none;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: var(--white);
        }

        .btn-primary:hover {
            opacity: 0.9;
        }

        .section-padding {
            padding: 80px 0;
        }

        .section-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .section-subtitle {
            color: var(--text-gray);
            margin-bottom: 40px;
            font-size: 14px;
        }

        /* --- 1. HERO SECTION --- */
        .hero {
            background-color: #f4f6f8;
            padding: 100px 0;
            position: relative;
            overflow: hidden;
        }

        .hero-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .hero-content {
            flex: 1;
            max-width: 600px;
        }

        .hero-tag {
            color: var(--primary-color);
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 10px;
            display: block;
        }

        .hero-title {
            font-size: 56px;
            line-height: 1.2;
            color: #111;
            margin-bottom: 20px;
        }

        .hero-desc {
            color: var(--text-gray);
            margin-bottom: 30px;
            font-size: 16px;
        }

        .hero-search {
            background: var(--white);
            padding: 8px;
            border-radius: 50px;
            display: flex;
            align-items: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            max-width: 500px;
        }

        .hero-search select {
            border: none;
            padding: 10px 20px;
            outline: none;
            background: transparent;
            border-right: 1px solid #eee;
            color: var(--text-dark);
            cursor: pointer;
        }

        .hero-search input {
            border: none;
            padding: 10px 20px;
            flex: 1;
            outline: none;
        }

        .hero-search button {
            background: var(--primary-color);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hero-image {
            flex: 1;
            display: flex;
            justify-content: flex-end;
        }

        .hero-image img {
            max-width: 100%;
            height: auto;
            border-radius: 10px;
        }

        /* --- 2. SERVICES SECTION (Dark) --- */
        .services {
            background-color: var(--dark-bg);
            color: var(--white);
        }

        .services .section-subtitle {
            color: #aaa;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }

        .service-card {
            background: var(--card-dark);
            padding: 30px;
            border-radius: 12px;
            border: 1px solid #2a2d3e;
            transition: 0.3s;
        }

        .service-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary-color);
        }

        .service-icon {
            color: var(--primary-color);
            font-size: 24px;
            margin-bottom: 20px;
            background: rgba(255, 107, 53, 0.1);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }

        .service-card h3 {
            font-size: 20px;
            margin-bottom: 10px;
        }

        .service-card p {
            color: #aaa;
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .service-link {
            color: var(--primary-color);
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* --- 3. HOW IT WORKS --- */
        .process {
            text-align: center;
        }

        .process-steps {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-top: 50px;
        }

        .step-item {
            flex: 1;
            min-width: 250px;
            position: relative;
            padding: 20px;
            text-align: left;
        }

        .step-number {
            position: absolute;
            top: -20px;
            right: 20px;
            font-size: 80px;
            font-weight: 900;
            color: rgba(0, 0, 0, 0.1);
            line-height: 1;
        }

        .step-icon {
            width: 60px;
            height: 60px;
            background: #1f2937;
            color: var(--white);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 20px;
        }

        .step-item:nth-child(2) .step-icon {
            background: var(--primary-color);
        }

        .step-item h4 {
            font-size: 18px;
            margin-bottom: 10px;
        }

        .step-item p {
            font-size: 14px;
            color: var(--text-gray);
            line-height: 1.6;
        }

        /* --- 4. CTA BANNER (Dark) --- */
        .cta-banner {
            background-color: var(--dark-bg);
            color: white;
            padding: 0;
            overflow: hidden;
        }

        .cta-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .cta-content {
            padding: 80px 0;
            max-width: 500px;
        }

        .cta-content h2 {
            font-size: 42px;
            margin-bottom: 20px;
        }

        .cta-content p {
            color: #aaa;
            margin-bottom: 30px;
        }

        .cta-image img {
            max-height: 500px;
            margin-bottom: -5px;
            /* Remove gap at bottom */
        }

        /* --- 5. WHY CHOOSE US --- */
        .why-choose {
            background: var(--white);
        }

        .why-wrapper {
            display: flex;
            align-items: center;
            gap: 50px;
            flex-wrap: wrap;
        }

        .why-text {
            flex: 1;
            min-width: 300px;
        }

        .why-image {
            flex: 1;
            min-width: 300px;
        }

        .why-image img {
            width: 100%;
            border-radius: 10px;
        }

        .feature-list {
            margin-top: 30px;
        }

        .feature-item {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .feature-icon {
            color: var(--primary-color);
            font-size: 20px;
            margin-top: 5px;
        }

        .feature-text h4 {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .feature-text p {
            font-size: 14px;
            color: var(--text-gray);
        }

        /* --- 6. LATEST REQUESTS --- */
        .latest-requests {
            background: #fafafa;
        }

        .request-placeholder {
            background: white;
            border: 1px dashed #ccc;
            padding: 50px;
            text-align: center;
            border-radius: 10px;
            color: #999;
        }

        /* --- 7. CLIENT FEEDBACK --- */
        .feedback {
            text-align: center;
        }

        .feedback-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            margin-top: 40px;
        }

        .feedback-card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            text-align: center;
        }

        .quote-icon {
            color: #fcd34d;
            font-size: 30px;
            margin-bottom: 20px;
        }

        .feedback-text {
            font-style: italic;
            color: #555;
            margin-bottom: 20px;
            font-size: 16px;
        }

        .rating {
            color: #fcd34d;
            margin-bottom: 10px;
            font-size: 12px;
        }

        .client-name {
            font-weight: 700;
            font-size: 14px;
        }

        /* --- 8. EXPERT FREELANCERS --- */
        .freelancers {
            background: #f9f9f9;
        }

        .freelancer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .freelancer-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            border: 1px solid #eee;
        }

        .avatar {
            width: 60px;
            height: 60px;
            background: #eee;
            border-radius: 50%;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ccc;
        }

        .f-name {
            font-weight: 700;
            margin-bottom: 5px;
        }

        .f-role {
            font-size: 12px;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .f-desc {
            font-size: 12px;
            color: #777;
        }

        /* --- 9. APP COMING SOON --- */
        .app-coming {
            text-align: center;
            padding-bottom: 50px;
        }

        .app-coming h2 {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .app-coming p {
            color: #777;
            font-size: 14px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 36px;
            }

            .hero-wrapper,
            .cta-wrapper,
            .why-wrapper {
                flex-direction: column;
                text-align: center;
            }

            .hero-search {
                margin: 0 auto;
            }

            .hero-image,
            .why-image,
            .cta-image {
                margin-top: 40px;
            }

            .step-item {
                text-align: center;
            }

            .step-icon {
                margin: 0 auto 20px;
            }

            .step-number {
                left: 50%;
                transform: translateX(-50%);
            }
        }

        /* Tablet: hide top links earlier (optional) */
        
    </style>
</head>

<body>
    <?php include __DIR__ . '/components/navbar.php'; ?>
    <section class="hero">
        <div class="container hero-wrapper">
            <div class="hero-content">
                <span class="hero-tag">Best Home Services</span>
                <h1 class="hero-title">Find Your Perfect <br><span class="text-primary">Technician</span></h1>
                <p class="hero-desc">Enjoy your fresh home with the help of <?php echo $appName; ?> Professional
                    services.</p>

                <!-- Dynamic category search (keeps UI) -->
                <!-- <form class="hero-search" action="/fixmate/pages/viewAllJobs.php" method="GET">
                    <select name="category" aria-label="Select category">
                        <option value="all">All Categories</option>
                        <?php foreach ($categories as $c): ?>
                            <?php
                            $cid = (int) $c['id'];
                            $cname = htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8');
                            ?>
                            <option value="<?php echo $cid; ?>"><?php echo $cname; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="q" placeholder="Search for a service...">
                    <button type="submit" aria-label="Search"><i class="fas fa-search"></i></button>
                </form> -->
            </div>

            <div class="hero-image">
                <img src="https://placehold.co/500x500/e2e8f0/1e293b?text=Technician+Image" alt="Technician">
            </div>
        </div>
    </section>

    <section class="services section-padding">
        <div class="container">
            <h6 class="text-primary"
                style="text-transform:uppercase; font-size:12px; letter-spacing:1px; margin-bottom:5px;">Our Services
            </h6>
            <h2 class="section-title">Professional <span class="text-primary">Services</span></h2>
            <p class="section-subtitle">We provide best home maintenance services tailored to your needs.</p>

            <!-- Dynamic categories render (same card UI) -->
            <div class="services-grid">
                <?php if (empty($categories)): ?>
                    <div class="service-card">
                        <div class="service-icon"><i class="fas fa-layer-group"></i></div>
                        <h3>No Categories</h3>
                        <p>We will add categories soon.</p>
                        <a href="<?php echo htmlspecialchars($bookNowUrl, ENT_QUOTES, 'UTF-8'); ?>"
                            class="service-link">Post a Job <i class="fas fa-arrow-right"></i></a>
                    </div>
                <?php else: ?>
                    <?php foreach ($categories as $c): ?>
                        <?php
                        $cid = (int) $c['id'];
                        $title = trim((string) $c['name']);
                        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
                        $slug = fm_slug($title);

                        $icon = $categoryIconMap[$slug] ?? 'fa-screwdriver-wrench';
                        $desc = $categoryDescMap[$slug] ?? 'Book trusted professionals for quality service at your doorstep.';

                        // link to jobs list filtered by category id
                        $categoryUrl = "/fixmate/pages/viewAllJobs.php?category=" . $cid;
                        ?>
                        <div class="service-card">
                            <div class="service-icon"><i class="fas <?php echo $icon; ?>"></i></div>
                            <h3><?php echo $safeTitle; ?></h3>
                            <p><?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?></p>
                            <!-- <a href="<?php echo htmlspecialchars($categoryUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                class="service-link">View Jobs <i class="fas fa-arrow-right"></i></a> -->
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="process section-padding">
        <div class="container">
            <h6 class="text-primary" style="text-transform:uppercase; font-size:12px;">Client Flow</h6>
            <h2 class="section-title">How <span class="text-primary"><?php echo $appName; ?></span> Works</h2>
            <p class="section-subtitle">Post a job, compare bids, message vendors, and close the job with confidence.
            </p>

            <div class="process-steps">
                <div class="step-item">
                    <div class="step-number">01</div>
                    <div class="step-icon"><i class="fas fa-file-alt"></i></div>
                    <h4>Post a Job</h4>
                    <p>Describe what you need, add location/area, set your budget, and upload photos so workers can
                        understand the task.</p>
                </div>

                <div class="step-item">
                    <div class="step-number">02</div>
                    <div class="step-icon"><i class="fas fa-users"></i></div>
                    <h4>Worker Assign</h4>
                    <p>We assign a professional worker based on your job description and location.</p>
                </div>

                <div class="step-item">
                    <div class="step-number">03</div>
                    <div class="step-icon"><i class="fas fa-check-circle"></i></div>
                    <h4>Job Complete & Review Us</h4>
                    <p>Track progress, work complete, and leave a review to help the community choose better.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="cta-banner">
        <div class="container cta-wrapper">
            <div class="cta-content">
                <h6 class="text-primary" style="text-transform:uppercase; font-size:12px; margin-bottom:10px;">Do you
                    need help with maintenance?</h6>
                <h2>Fix Home Problems <br><span class="text-primary">Faster</span> & Reliable.</h2>
                <p>Get the best service direct to your door. Simple, fast and reliable. We ensure quality work at fair
                    prices.</p>

                <div style="display:flex; gap:20px; align-items:center; flex-wrap:wrap;">
                    <a href="<?php echo htmlspecialchars($bookNowUrl, ENT_QUOTES, 'UTF-8'); ?>"
                        class="btn btn-primary">Post a Job</a>
                    <span style="font-size:14px; color:#fff;">
                        <i class="fas fa-phone-alt text-primary"></i> Call us: <?php echo $appPhone; ?>
                    </span>
                </div>
            </div>
            <div class="cta-image">
                <img src="https://placehold.co/400x500/1f2937/ffffff?text=Worker+Img" alt="Worker">
            </div>
        </div>
    </section>

    <section class="why-choose section-padding">
        <div class="container why-wrapper">
            <div class="why-text">
                <h2 class="section-title">Why Choose <?php echo $appName; ?>?</h2>
                <p class="section-subtitle">We offer fast, reliable and quality home services backed by our satisfaction
                    guarantee.</p>

                <div class="feature-list">
                    <div class="feature-item">
                        <div class="feature-icon"><i class="far fa-check-circle"></i></div>
                        <div class="feature-text">
                            <h4>Affordable & Transparent Pricing</h4>
                            <p>No hidden charges. Choose the quote that fits your budget.</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon"><i class="fas fa-user-shield"></i></div>
                        <div class="feature-text">
                            <h4>Certified & Skilled Technicians</h4>
                            <p>All professionals are verified and equipped to handle tasks.</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon"><i class="fas fa-headset"></i></div>
                        <div class="feature-text">
                            <h4>Customer Support</h4>
                            <p>We are here to help you with your queries.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="why-image">
                <img src="https://placehold.co/500x350/ddd/333?text=Flooring+Installation" alt="Working">
            </div>
        </div>
    </section>

    <!-- <section class="latest-requests section-padding">
        <div class="container">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
                <h2 class="section-title" style="font-size:24px;">Latest Service Requests</h2>
                <a href="/fixmate/pages/viewAllJobs.php" class="text-primary" style="font-size:14px;">View All Jobs <i class="fas fa-arrow-right"></i></a>
            </div>

            <div class="request-placeholder">
                <div style="font-size:40px; color:#ddd; margin-bottom:10px;"><i class="fas fa-clipboard-list"></i></div>
                <p>Latest job requests will appear here dynamically.</p>
            </div>
        </div>
    </section> -->

    <section class="feedback section-padding">
        <div class="container">
            <h6 class="text-primary">TESTIMONIALS</h6>
            <h2 class="section-title">Client Feedback</h2>

            <div class="feedback-grid">
                <?php if (empty($reviews)): ?>
                    <div class="feedback-card">
                        <div class="quote-icon"><i class="fas fa-quote-left"></i></div>
                        <p class="feedback-text">"No reviews available yet."</p>
                        <p class="client-name"><?php echo $appName; ?></p>
                        <p style="font-size:12px; color:#999;">Support Team</p>
                    </div>
                <?php else: ?>
                    <?php foreach (array_slice($reviews, 0, 6) as $rev): ?>
                        <?php
                        $rating = max(1, min(5, (int) ($rev['rating'] ?? 5)));
                        $name = htmlspecialchars($rev['reviewer_name'] ?? 'Client', ENT_QUOTES, 'UTF-8');
                        $pos = htmlspecialchars(ucfirst($rev['reviewer_position'] ?? 'Home Owner'), ENT_QUOTES, 'UTF-8');
                        $comment = htmlspecialchars($rev['comment'] ?? 'Great service!', ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="feedback-card">
                            <div class="quote-icon"><i class="fas fa-quote-left"></i></div>
                            <p class="feedback-text">"<?php echo $comment; ?>"</p>
                            <div class="rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star" style="opacity:<?php echo $i <= $rating ? '1' : '0.25'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <p class="client-name"><?php echo $name; ?></p>
                            <p style="font-size:12px; color:#999;"><?php echo $pos; ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- <section class="freelancers section-padding">
        <div class="container">
            <h2 class="section-title" style="font-size:22px;">Expert Freelancers</h2>
            <p class="section-subtitle">Top rated professionals ready to help you.</p>

            <div class="freelancer-grid">
                <?php
                // Keep placeholder data (same UI) — you can later replace with dynamic workers query.
                $freelancers = ['John Doe', 'Mike Smith', 'Emily Davis', 'Chris Wilson'];
                foreach ($freelancers as $f):
                    ?>
                    <div class="freelancer-card">
                        <div class="avatar"><i class="fas fa-user"></i></div>
                        <h4 class="f-name"><?php echo htmlspecialchars($f, ENT_QUOTES, 'UTF-8'); ?></h4>
                        <p class="f-role">Electrician</p>
                        <p class="f-desc">5.0 <i class="fas fa-star" style="color:#fcd34d;"></i> (12 Jobs)</p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section> -->

    <section class="app-coming section-padding">
        <div class="container">
            <h2>App Coming soon!</h2>
            <p>Download our app for better experience (Android/iOS)</p>
        </div>
    </section>

    <?php include __DIR__ . '/components/footer.php'; ?>

</body>

</html>