<?php
// components/footer.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
$app = $app ?? (file_exists(__DIR__ . '/../config/app.php') ? require __DIR__ . '/../config/app.php' : []);

$appName  = $app['app_name'] ?? 'FixMate';
$homeUrl  = '/fixmate/';
$aboutUrl = '/fixmate/pages/about.php';
$contactUrl = '/fixmate/pages/contact.php';

$loginUrl = '/fixmate/pages/auth/login.php';
$clientDashboard = '/fixmate/pages/dashboards/client-dashboard.php';
$adminDashboard  = '/fixmate/pages/admin/admin-dashboard.php';
$clientPostJobUrl = $clientDashboard . '?page=post-job';

// session
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = strtolower(trim($isLoggedIn ? ($_SESSION['role'] ?? '') : ''));

// Post a Job visibility: guest + client only
$showPostJob = (!$isLoggedIn || $userRole === 'client');
$postJobHref = (!$isLoggedIn)
    ? ($loginUrl . '?redirect=client_post_job')
    : $clientPostJobUrl;

// dynamic categories
$categories = [];
try {
    $sql = "SELECT id, name FROM categories ORDER BY name ASC LIMIT 5";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $categories[] = $row;
        }
    }
} catch (Throwable $e) {
    $categories = [];
}

function fm_category_url($id) {
    return '/fixmate/pages/categories.php?category_id=' . urlencode((string)$id);
}

$social = [
    'facebook'  => 'https://www.facebook.com/',
    'linkedin'  => 'https://www.linkedin.com/',
    'twitter'   => 'https://www.twitter.com/',
    'instagram' => 'https://www.instagram.com/',
    'youtube'   => 'https://www.youtube.com/',
];

$logoPath = '/fixmate/assets/images/logo_fixmate.png';
?>

<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>

<style>
    /* Custom CSS for Footer */
    .fm-footer {
        background-color: #050816;
        color: #b3b3b3;
        font-family: 'Inter', sans-serif; /* Or your site's font */
        padding-top: 60px;
        padding-bottom: 30px;
        border-top: 1px solid rgba(255, 255, 255, 0.05);
        font-size: 14px;
        line-height: 1.6;
    }

    .fm-footer-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
    }

    /* Top Row: Logo & Social */
    .fm-footer-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-bottom: 30px;
        margin-bottom: 40px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        flex-wrap: wrap;
        gap: 20px;
    }

    .fm-footer-logo img {
        height: 40px; /* Restricts logo size */
        width: auto;
        display: block;
    }

    .fm-footer-social-wrapper {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .fm-footer-social-label {
        color: #888;
        font-size: 14px;
    }

    .fm-footer-social-icons {
        display: flex;
        gap: 10px;
    }

    .fm-social-link {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        color: #fff;
        text-decoration: none;
        transition: all 0.3s ease;
        font-size: 18px;
    }

    .fm-social-link:hover {
        background-color: #fff;
        color: #000;
        border-color: #fff;
    }

    /* Middle Row: Columns */
    .fm-footer-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr); /* 4 Columns */
        gap: 30px;
        margin-bottom: 50px;
    }

    .fm-footer-col h3 {
        color: #fff;
        font-size: 14px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 20px;
        margin-top: 0;
    }

    .fm-footer-links {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .fm-footer-links li {
        margin-bottom: 12px;
    }

    .fm-footer-links a {
        color: #b3b3b3;
        text-decoration: none;
        transition: color 0.2s ease;
    }

    .fm-footer-links a:hover {
        color: #f97316; /* Orange hover */
    }

    .fm-disabled-link {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .fm-coming-soon {
        display: inline-block;
        margin-top: 15px;
        padding: 4px 12px;
        border: 1px solid rgba(249, 115, 22, 0.5);
        color: #f97316;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Bottom Row: Copyright */
    .fm-footer-bottom {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 30px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 13px;
        color: #666;
        flex-wrap: wrap;
        gap: 15px;
    }

    .fm-footer-bottom-links {
        display: flex;
        gap: 15px;
        align-items: center;
    }

    .fm-footer-bottom-links a {
        color: #b3b3b3;
        text-decoration: none;
        transition: color 0.2s;
    }

    .fm-footer-bottom-links a:hover {
        color: #fff;
    }

    .fm-separator {
        color: #444;
    }

    /* Responsive */
    @media (max-width: 992px) {
        .fm-footer-grid {
            grid-template-columns: repeat(2, 1fr); /* 2 cols on tablet */
        }
    }

    @media (max-width: 576px) {
        .fm-footer-top {
            flex-direction: column;
            align-items: flex-start;
        }
        .fm-footer-grid {
            grid-template-columns: 1fr; /* 1 col on mobile */
        }
        .fm-footer-bottom {
            flex-direction: column;
            align-items: flex-start;
        }
    }
</style>

<footer class="fm-footer">
    <div class="fm-footer-container">
        
        <div class="fm-footer-top">
            <a href="<?php echo $homeUrl; ?>" class="fm-footer-logo">
                <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="<?php echo htmlspecialchars($appName); ?>">
            </a>

            <div class="fm-footer-social-wrapper">
                <span class="fm-footer-social-label">Follow Us:</span>
                <div class="fm-footer-social-icons">
                    <?php 
                    $icons = [
                        'facebook' => 'ph-facebook-logo',
                        'linkedin' => 'ph-linkedin-logo',
                        'twitter' => 'ph-twitter-logo',
                        'instagram' => 'ph-instagram-logo',
                        'youtube' => 'ph-youtube-logo'
                    ];
                    foreach($social as $key => $url): ?>
                        <a href="<?php echo htmlspecialchars($url); ?>" class="fm-social-link" target="_blank">
                            <i class="ph-bold <?php echo $icons[$key]; ?>"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="fm-footer-grid">
            
            <div class="fm-footer-col">
                <h3>Categories</h3>
                <ul class="fm-footer-links">
                    <?php if (!empty($categories)): ?>
                        <?php foreach ($categories as $cat): ?>
                            <li>
                                <a href="<?php echo fm_category_url($cat['id']); ?>">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li>No categories yet</li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="fm-footer-col">
                <h3>Jobs</h3>
                <ul class="fm-footer-links">
                    <?php if ($showPostJob): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars($postJobHref); ?>">Post a Job</a>
                        </li>
                    <?php else: ?>
                        <li class="fm-disabled-link">Post a Job (Admin)</li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="fm-footer-col">
                <h3>Support</h3>
                <ul class="fm-footer-links">
                    <li><a href="<?php echo $aboutUrl; ?>">About Us</a></li>
                    <li><a href="<?php echo $contactUrl; ?>">Help & Support</a></li>
                    <li><a href="/fixmate/pages/services.php">Services</a></li>
                    <li><a href="#">FAQs</a></li>
                    <li><a href="<?php echo $contactUrl; ?>">Contact Us</a></li>
                </ul>
            </div>

            <div class="fm-footer-col">
                <h3>Download App</h3>
                <p style="margin-bottom: 15px; color: #888;">Soon you’ll be able to manage your jobs and services directly from our mobile app.</p>
                <div class="fm-coming-soon">Coming soon</div>
            </div>

        </div>

        <div class="fm-footer-bottom">
            <div>
                &copy;<?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. All Rights Reserved.
            </div>
            <div class="fm-footer-bottom-links">
                <a href="/fixmate/pages/termsAndPrivacy.php#tos">Terms of Service</a>
                <span class="fm-separator">|</span>
                <a href="/fixmate/pages/termsAndPrivacy.php#privacy">Privacy Policy</a>
            </div>
        </div>

    </div>
</footer>