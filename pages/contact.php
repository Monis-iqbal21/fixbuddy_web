<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';

// ====== FLASH MESSAGES (show once) ======
$success = $_SESSION['contact_success'] ?? '';
$error   = $_SESSION['contact_error'] ?? '';

unset($_SESSION['contact_success'], $_SESSION['contact_error']);


// ====== HANDLE FORM SUBMIT (POST) ======
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name && $email && $subject && $message) {

        // INSERT INTO contact_form TABLE
        $stmt = $conn->prepare("
            INSERT INTO contact_form (name, email, subject, message)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('ssss', $name, $email, $subject, $message);
        $stmt->execute();
        $stmt->close();

        // Set success flash message
        $_SESSION['contact_success'] = "Your message has been sent successfully!";

        // 🔥 PRG PATTERN — prevents form resubmission on page reload
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {

        // Set error flash message
        $_SESSION['contact_error'] = "Please fill all required fields.";

        // Redirect back to avoid resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Contact Us - Fix Buddy</title>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Icons -->
    <link rel="stylesheet" href="https://unpkg.com/phosphor-icons@1.4.2/src/css/phosphor.css">
    <script src="https://unpkg.com/phosphor-icons"></script>
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

<body class="bg-gray-100">

    <!-- Navbar -->
    <?php include '../components/navbar.php'; ?>

    <!-- CONTACT SECTION -->
    <section class="py-16">
        <div class="max-w-6xl mx-auto px-4">

            <!-- Heading -->
            <div class="text-center mb-12">
                <h1 class="text-4xl font-bold text-[#1d1d2b]">Contact Us</h1>
                <p class="text-gray-600 mt-2">We're here to help and answer any question you might have.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10">

                <!-- Contact Info -->
                <div class="space-y-6">

                    <div class="bg-white shadow rounded-lg p-6 flex items-start gap-4">
                        <span class="ph-bold ph-phone-call text-orange-600 text-3xl"></span>
                        <div>
                            <h3 class="text-lg font-semibold">Phone</h3>
                            <p class="text-gray-600">+92 300 0000000</p>
                        </div>
                    </div>

                    <div class="bg-white shadow rounded-lg p-6 flex items-start gap-4">
                        <span class="ph-bold ph-envelope-simple-open text-orange-600 text-3xl"></span>
                        <div>
                            <h3 class="text-lg font-semibold">Email</h3>
                            <p class="text-gray-600">support@fixbuddy.com</p>
                        </div>
                    </div>

                    <div class="bg-white shadow rounded-lg p-6 flex items-start gap-4">
                        <span class="ph-bold ph-map-pin text-orange-600 text-3xl"></span>
                        <div>
                            <h3 class="text-lg font-semibold">Our Location</h3>
                            <p class="text-gray-600">Karachi, Pakistan</p>
                        </div>
                    </div>

                    <div class="bg-white shadow rounded-lg p-6">
                        <h3 class="text-lg font-semibold mb-2">Follow Us</h3>
                        <div class="flex items-center gap-4">
                            <a class="text-orange-600 hover:text-orange-700 text-2xl" href="#"><i class="ph-bold ph-facebook-logo"></i></a>
                            <a class="text-orange-600 hover:text-orange-700 text-2xl" href="#"><i class="ph-bold ph-instagram-logo"></i></a>
                            <a class="text-orange-600 hover:text-orange-700 text-2xl" href="#"><i class="ph-bold ph-whatsapp-logo"></i></a>
                        </div>
                    </div>

                </div>

                <!-- Contact Form -->
                <div class="bg-white shadow-lg rounded-lg p-8">

                    <?php if (!empty($success)): ?>
                        <div class="mb-4 p-3 bg-green-100 text-green-700 rounded">
                            <?= htmlspecialchars($success) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error)): ?>
                        <div class="mb-4 p-3 bg-red-100 text-red-700 rounded">
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form action="" method="POST" class="space-y-6">

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Full Name *</label>
                            <input type="text" name="name"
                                   class="w-full mt-1 px-4 py-2 bg-gray-100 rounded-md focus:ring-2 focus:ring-orange-500 outline-none"
                                   placeholder="Enter your name" required>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Email Address *</label>
                            <input type="email" name="email"
                                   class="w-full mt-1 px-4 py-2 bg-gray-100 rounded-md focus:ring-2 focus:ring-orange-500 outline-none"
                                   placeholder="Enter your email" required>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Subject *</label>
                            <input type="text" name="subject"
                                   class="w-full mt-1 px-4 py-2 bg-gray-100 rounded-md focus:ring-2 focus:ring-orange-500 outline-none"
                                   placeholder="Enter subject" required>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Message *</label>
                            <textarea name="message" rows="5"
                                      class="w-full mt-1 px-4 py-2 bg-gray-100 rounded-md focus:ring-2 focus:ring-orange-500 outline-none"
                                      placeholder="Write your message..." required></textarea>
                        </div>

                        <button type="submit"
                                class="w-full py-3 bg-orange-600 text-white font-semibold rounded-md hover:bg-orange-700 transition">
                            Send Message
                        </button>

                    </form>
                </div>
            </div>

            <!-- Map -->
            <div class="mt-14">
                <iframe class="w-full h-72 rounded-lg shadow"
                        src="https://www.google.com/maps/embed?pb=" allowfullscreen loading="lazy"></iframe>
            </div>

        </div>
    </section>

    <!-- Footer -->
    <?php include '../components/footer.php'; ?>

</body>
</html>
