<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Legal - FixBuddy</title>

    <style>
        :root {
            --primary-color: #ff6b35;
            --dark-bg: #13151f;
            --card-dark: #1c1f2e;
            --light-bg: #f9f9f9;
            --text-dark: #1f2937;
            --text-gray: #6b7280;
            --white: #ffffff;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            background-color: var(--light-bg);
            color: var(--text-dark);
            line-height: 1.6;
        }

        /* Hero Header Section */
        .legal-header {
            background-color: var(--dark-bg);
            color: var(--white);
            padding: 60px 20px;
            text-align: center;
        }

        .legal-header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .legal-header p {
            color: var(--text-gray);
            font-size: 1.1rem;
            max-width: 760px;
            margin: 0 auto;
        }

        /* Main Content Container */
        .content-container {
            max-width: 1000px;
            margin: -40px auto 60px;
            padding: 0 20px;
        }

        .legal-card {
            background: var(--white);
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        /* Typography */
        h2 {
            color: var(--dark-bg);
            border-left: 4px solid var(--primary-color);
            padding-left: 15px;
            margin-top: 30px;
            font-size: 1.5rem;
        }

        h3 {
            margin-top: 18px;
            font-size: 1.05rem;
            color: var(--text-dark);
        }

        .last-updated {
            font-style: italic;
            color: var(--text-gray);
            margin-bottom: 20px;
            display: block;
        }

        .muted {
            color: var(--text-gray);
            font-size: 0.95rem;
        }

        /* Tabs */
        .legal-nav {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .legal-nav a {
            text-decoration: none;
            color: var(--text-gray);
            font-weight: 600;
            padding: 10px 20px;
            border-bottom: 2px solid transparent;
            transition: 0.3s;
        }

        .legal-nav a.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
        }

        /* Make anchor sections land nicely under sticky navbar */
        .anchor-offset {
            scroll-margin-top: 110px;
        }

        /* Lists */
        ul {
            padding-left: 18px;
        }

        li {
            margin: 6px 0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .legal-card {
                padding: 25px;
            }

            .legal-header h1 {
                font-size: 2rem;
            }

            .content-container {
                margin-top: -20px;
            }
        }
    </style>
</head>

<body>
    <?php include '../components/navbar.php'; ?>

    <section class="legal-header">
        <h1>Legal Center</h1>
        <p>Review our Terms of Service and Privacy Policy to understand how Fix Buddy works, your responsibilities, and
            how we protect your data.</p>
    </section>

    <main class="content-container">

        <div class="legal-nav">
            <a href="#tos" class="active" data-tab="tos">Terms of Service</a>
            <a href="#privacy" data-tab="privacy">Privacy Policy</a>
        </div>

        <!-- TERMS -->
        <article id="tos" class="legal-card anchor-offset">
            <span class="last-updated">Last Updated: January 12, 2026</span>

            <h2>1. Overview</h2>
            <p>
                Welcome to <strong>Fix Buddy</strong>. Fix Buddy is a marketplace that helps clients find and connect
                with
                independent service providers (e.g., electricians, plumbers, A/C technicians, mechanics, and other home
                service professionals). By using Fix Buddy, you agree to these Terms of Service.
            </p>
            <p class="muted">
                If you do not agree with these terms, please do not use the platform.
            </p>

            <h2>2. Eligibility & Accounts</h2>
            <p>
                You must provide accurate and complete information when creating an account or posting a job. You are
                responsible for safeguarding your login credentials and for all activity under your account.
            </p>

            <h2>3. Platform Role (Marketplace Disclaimer)</h2>
            <p>
                Fix Buddy provides a technology platform only. Service providers are <strong>independent</strong> and
                are
                not employees, agents, or partners of Fix Buddy. Any agreement for a job is made directly between the
                client and the service provider.
            </p>

            <h2>4. Job Posting, Quotes, and Booking</h2>
            <p>
                Clients may post job requests and service providers may submit offers/quotes. Clients are responsible
                for reviewing provider profiles, offers, and job details before confirming any work. Fix Buddy may show
                ratings/reviews to help informed decisions, but does not guarantee outcomes.
            </p>

            <h2>5. Payments, Fees, and Refunds</h2>
            <p>
                Where payments are enabled through the platform, transactions may be processed via third-party payment
                processors. Fix Buddy does not store full payment card details. Any applicable platform fees, if
                charged,
                will be clearly shown before payment.
            </p>
            <p class="muted">
                Refunds (if applicable) depend on the job status, evidence available, and processor rules. Fix Buddy may
                assist with dispute handling but does not guarantee refunds in every case.
            </p>

            <h2>6. Safety, Conduct, and Prohibited Use</h2>
            <p>Users must use Fix Buddy responsibly. You agree not to:</p>
            <ul>
                <li>Post false, misleading, or unlawful job requests.</li>
                <li>Harass, abuse, or threaten another user.</li>
                <li>Attempt to bypass the platform’s rules, security, or access controls.</li>
                <li>Upload malicious files or misuse content and data from the platform.</li>
            </ul>

            <h2>7. Reviews and Content</h2>
            <p>
                Reviews should be honest, relevant, and respectful. Fix Buddy may remove content that violates policies,
                appears fraudulent, or is reported with supporting evidence.
            </p>

            <h2>8. Limitation of Liability</h2>
            <p>
                To the maximum extent permitted by law, Fix Buddy is not liable for indirect, incidental, special, or
                consequential damages arising from the service provider’s work, delays, disputes, or platform use.
                Fix Buddy does not guarantee the quality, timing, or completion of any service.
            </p>

            <h2>9. Termination</h2>
            <p>
                Fix Buddy may suspend or terminate accounts that violate these terms, misuse the platform, or create
                safety risks. Users may also request account closure subject to legal and operational requirements.
            </p>

            <h2>10. Changes to Terms</h2>
            <p>
                We may update these Terms from time to time. Updates will be posted on this page with the revised date.
                Continued use of Fix Buddy after updates means you accept the changes.
            </p>

            <p class="muted">
                Contact: If you have questions about these terms, contact support via the email listed on the website
                footer/contact page.
            </p>
        </article>

        <!-- PRIVACY -->
        <article id="privacy" class="legal-card anchor-offset">
            <span class="last-updated">Last Updated: January 12, 2026</span>

            <h2>Privacy Policy</h2>
            <p>
                Fix Buddy values your privacy. This Privacy Policy explains what information we collect, how we use it,
                and the choices you have.
            </p>

            <h3>1) Information We Collect</h3>
            <ul>
                <li><strong>Account data:</strong> name, email, phone number, password (stored securely).</li>
                <li><strong>Job data:</strong> job details, location area, attachments you upload (images/videos/voice
                    notes).</li>
                <li><strong>Usage data:</strong> device and browser information, logs, pages visited, and basic
                    analytics.</li>
                <li><strong>Communication data:</strong> messages sent through Fix Buddy (when messaging is enabled).
                </li>
            </ul>

            <h3>2) How We Use Your Information</h3>
            <ul>
                <li>To create and manage your account.</li>
                <li>To connect clients with service providers and enable job fulfillment.</li>
                <li>To send notifications about job updates, account activity, and important platform messages.</li>
                <li>To improve platform performance, safety, and user experience.</li>
            </ul>

            <h3>3) Sharing of Information</h3>
            <p>
                We share limited information as needed to operate the marketplace:
            </p>
            <ul>
                <li><strong>Between users:</strong> job details and necessary contact/interaction info to complete the
                    service.</li>
                <li><strong>Service providers:</strong> may see job details and approximate location needed for quoting
                    and service.</li>
                <li><strong>Vendors:</strong> payment processors, hosting, and analytics providers (only what’s
                    necessary).</li>
                <li><strong>Legal:</strong> when required by law, court order, or to protect rights and safety.</li>
            </ul>

            <h3>4) Data Security</h3>
            <p>
                We use reasonable administrative, technical, and physical safeguards to protect your information.
                However, no system is 100% secure, and we cannot guarantee absolute security.
            </p>

            <h3>5) Data Retention</h3>
            <p>
                We keep your information only as long as needed to provide the service, meet legal requirements, resolve
                disputes, and enforce agreements.
            </p>

            <h3>6) Your Choices</h3>
            <ul>
                <li>You can update your profile information inside your account settings.</li>
                <li>You can request account deletion (subject to legal/operational retention rules).</li>
                <li>You may opt out of certain non-essential notifications where applicable.</li>
            </ul>

            <h3>7) Changes to this Policy</h3>
            <p>
                We may update this Privacy Policy from time to time. Updates will be posted on this page with the
                revised date.
            </p>

            <p class="muted">
                For privacy questions, contact support via the email listed on the website footer/contact page.
            </p>
        </article>

    </main>

    <?php include '../components/footer.php'; ?>

    <script>
        // ---------------------------
        // 1) Active tab highlight on click
        // 2) Smooth scroll (already via CSS, but keep this for offsets & consistent behavior)
        // 3) Make footer links scroll to sections too
        // ---------------------------

        function setActiveTab(hash) {
            const links = document.querySelectorAll('.legal-nav a');
            links.forEach(a => a.classList.remove('active'));
            const active = document.querySelector(`.legal-nav a[href="${hash}"]`);
            if (active) active.classList.add('active');
        }

        // Click handler for top tabs
        document.querySelectorAll('.legal-nav a').forEach(a => {
            a.addEventListener('click', function (e) {
                const hash = this.getAttribute('href');
                if (!hash || !hash.startsWith('#')) return;

                e.preventDefault();
                const el = document.querySelector(hash);
                if (!el) return;

                setActiveTab(hash);
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                history.replaceState(null, '', hash);
            });
        });

        // Footer links: any link that contains #tos or #privacy should scroll properly
        document.querySelectorAll('a[href*="#tos"], a[href*="#privacy"]').forEach(a => {
            a.addEventListener('click', function (e) {
                const href = this.getAttribute('href') || '';
                const hash = href.includes('#tos') ? '#tos' : (href.includes('#privacy') ? '#privacy' : '');
                if (!hash) return;

                // If we're already on this page, intercept and scroll
                const currentPath = window.location.pathname.replace(/\/+$/, '');
                const linkPath = href.split('#')[0].replace(/\/+$/, '');

                // If footer uses just "#tos" or "#privacy" OR same page link
                const isSamePage = linkPath === '' || linkPath === currentPath || linkPath === window.location.href.split('#')[0];

                if (isSamePage) {
                    e.preventDefault();
                    const el = document.querySelector(hash);
                    if (!el) return;
                    setActiveTab(hash);
                    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    history.replaceState(null, '', hash);
                }
            });
        });

        // On load: if URL has hash, scroll & set active
        window.addEventListener('load', () => {
            const hash = window.location.hash;
            if (hash === '#tos' || hash === '#privacy') {
                setActiveTab(hash);
                const el = document.querySelector(hash);
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                setActiveTab('#tos');
            }
        });

        // On scroll: update active tab depending on which section is in view
        const tosEl = document.getElementById('tos');
        const privacyEl = document.getElementById('privacy');

        function onScrollUpdateTab() {
            if (!tosEl || !privacyEl) return;

            const tosTop = tosEl.getBoundingClientRect().top;
            const privacyTop = privacyEl.getBoundingClientRect().top;

            // whichever is closer to top (but still below threshold)
            const threshold = 140;
            if (privacyTop <= threshold) {
                setActiveTab('#privacy');
            } else if (tosTop <= threshold) {
                setActiveTab('#tos');
            }
        }

        window.addEventListener('scroll', () => {
            window.requestAnimationFrame(onScrollUpdateTab);
        });
    </script>
</body>

</html>