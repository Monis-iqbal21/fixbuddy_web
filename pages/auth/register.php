<?php
session_start();

require_once '../../config/db.php';
$app = require '../../config/app.php';

// ----------------- FLASH (errors + sticky) -----------------
$error = $_SESSION['flash_error'] ?? '';
$name = $_SESSION['flash_name'] ?? '';
$email = $_SESSION['flash_email'] ?? '';
$address = $_SESSION['flash_address'] ?? '';
$area = $_SESSION['flash_area'] ?? '';
$phone = $_SESSION['flash_phone'] ?? '';
$cnic = $_SESSION['flash_cnic'] ?? '';

unset(
  $_SESSION['flash_error'],
  $_SESSION['flash_name'],
  $_SESSION['flash_email'],
  $_SESSION['flash_address'],
  $_SESSION['flash_area'],
  $_SESSION['flash_phone'],
  $_SESSION['flash_cnic']
);

// ----------------- OPTIONAL: JOB_ID PARAM -----------------
$jobId = 0;
if (!empty($_GET['job_id']))
  $jobId = (int) $_GET['job_id'];
if (!empty($_POST['job_id']))
  $jobId = (int) $_POST['job_id'];

// Redirect key (optional)
$redirectKeyInitial = $_GET['redirect'] ?? ($_POST['redirect'] ?? '');

// Karachi Areas
$karachi_areas = [
  "DHA Phase 1",
  "DHA Phase 2",
  "DHA Phase 3",
  "DHA Phase 4",
  "DHA Phase 5",
  "DHA Phase 6",
  "DHA Phase 7",
  "DHA Phase 8",
  "Clifton",
  "Saddar",
  "Gulshan-e-Iqbal",
  "Gulistan-e-Johar",
  "North Nazimabad",
  "Nazimabad",
  "Federal B Area",
  "PECHS",
  "Bahria Town",
  "Malir Cantt",
  "Korangi",
  "Landhi",
  "Liaquatabad",
  "Lyari",
  "Shah Faisal Colony"
];

// helper: redirect back with flash + keep query
function fm_register_back(string $msg, array $data, int $jobId, string $redirectKeyInitial)
{
  $_SESSION['flash_error'] = $msg;

  $_SESSION['flash_name'] = $data['name'] ?? '';
  $_SESSION['flash_email'] = $data['email'] ?? '';
  $_SESSION['flash_address'] = $data['address'] ?? '';
  $_SESSION['flash_area'] = $data['area'] ?? '';
  $_SESSION['flash_phone'] = $data['phone'] ?? '';
  $_SESSION['flash_cnic'] = $data['cnic'] ?? '';

  $q = [];
  if (!empty($redirectKeyInitial))
    $q['redirect'] = $redirectKeyInitial;
  if (!empty($jobId))
    $q['job_id'] = $jobId;

  $url = 'register.php';
  if (!empty($q))
    $url .= '?' . http_build_query($q);

  header("Location: {$url}");
  exit;
}

// ----------------- REGISTER -----------------
if (isset($_POST['register_btn'])) {

  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';
  $cpassword = $_POST['cpassword'] ?? '';

  $address = trim($_POST['address'] ?? '');
  $area = trim($_POST['area'] ?? '');
  $city = "Karachi";
  $phone = trim($_POST['phone'] ?? '');
  $cnic = trim($_POST['cnic'] ?? '');

  $redirectKey = $_POST['redirect'] ?? ($_GET['redirect'] ?? '');

  $sticky = compact('name', 'email', 'address', 'area', 'phone', 'cnic');

  // validations
  if ($name === '') {
    fm_register_back("Name is required!", $sticky, $jobId, $redirectKeyInitial);
  }
  if ($area === '' || $phone === '') {
    fm_register_back("Please fill all required fields (Area, Phone).", $sticky, $jobId, $redirectKeyInitial);
  }
  if (!preg_match("/^03[0-9]{9}$/", $phone)) {
    fm_register_back("Invalid Phone Number! Must start with 03 and have 11 digits.", $sticky, $jobId, $redirectKeyInitial);
  }
  if (strlen($password) < 8) {
    fm_register_back("Password must be at least 8 characters!", $sticky, $jobId, $redirectKeyInitial);
  }
  if ($password !== $cpassword) {
    fm_register_back("Passwords do not match!", $sticky, $jobId, $redirectKeyInitial);
  }
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fm_register_back("Invalid email format!", $sticky, $jobId, $redirectKeyInitial);
  }
  if ($cnic !== '' && !preg_match("/^[0-9]{5}-[0-9]{7}-[0-9]{1}$/", $cnic)) {
    fm_register_back("Invalid CNIC format! Use XXXXX-XXXXXXX-X", $sticky, $jobId, $redirectKeyInitial);
  }

  // escape
  $db_name = $conn->real_escape_string($name);
  $db_email = $email !== '' ? $conn->real_escape_string($email) : null;
  $db_address = $conn->real_escape_string($address);
  $db_area = $conn->real_escape_string($area);
  $db_city = $conn->real_escape_string($city);
  $db_phone = $conn->real_escape_string($phone);
  $db_cnic = $cnic !== '' ? $conn->real_escape_string($cnic) : null;

  // duplicates
  if ($db_email) {
    $checkSql = "SELECT id FROM users WHERE phone='$db_phone' OR email='$db_email' LIMIT 1";
  } else {
    $checkSql = "SELECT id FROM users WHERE phone='$db_phone' LIMIT 1";
  }

  $check = $conn->query($checkSql);
  if ($check && $check->num_rows > 0) {
    fm_register_back($db_email ? "Phone or Email already exists!" : "Phone already exists!", $sticky, $jobId, $redirectKeyInitial);
  }

  // profile image (optional)
  $profileImagePath = null;
  if (!empty($_FILES['profile_image']['name'])) {

    $uploadDir = __DIR__ . '/../../uploads/profile_images/';
    if (!is_dir($uploadDir))
      mkdir($uploadDir, 0777, true);

    $imgTmp = $_FILES['profile_image']['tmp_name'];
    $imgName = $_FILES['profile_image']['name'];
    $imgType = $imgTmp ? mime_content_type($imgTmp) : '';

    if ($imgTmp && strpos($imgType, 'image/') === 0) {
      $ext = strtolower(pathinfo($imgName, PATHINFO_EXTENSION));
      if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
        $safeName = 'profile_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        $fullPath = $uploadDir . $safeName;
        $publicPath = '/fixmate/uploads/profile_images/' . $safeName;

        if (move_uploaded_file($imgTmp, $fullPath)) {
          $profileImagePath = $conn->real_escape_string($publicPath);
        }
      }
    }
  }

  $hashed = password_hash($password, PASSWORD_DEFAULT);

  $insertSql = "
    INSERT INTO users
      (name, phone, email, password_hash, role, city, area, address, profile_image, cnic, is_active)
    VALUES
      ('$db_name',
       '$db_phone',
       " . ($db_email ? "'$db_email'" : "NULL") . ",
       '$hashed',
       'client',
       '$db_city',
       '$db_area',
       '$db_address',
       " . ($profileImagePath ? "'$profileImagePath'" : "NULL") . ",
       " . ($db_cnic ? "'$db_cnic'" : "NULL") . ",
       1
      )
  ";

  $insert = $conn->query($insertSql);

  if (!$insert) {
    fm_register_back("Error: " . $conn->error, $sticky, $jobId, $redirectKeyInitial);
  }

  // success
  $new_user_id = $conn->insert_id;

  $_SESSION['user_id'] = $new_user_id;
  $_SESSION['name'] = $name;
  $_SESSION['role'] = 'client';
  $_SESSION['phone'] = $phone;
  $_SESSION['email'] = $email;
  $_SESSION['token'] = bin2hex(random_bytes(32));

  if ($jobId > 0) {
    header("Location: /fixmate/pages/dashboards/client/client-dashboard.php?page=job-detail&job_id={$jobId}");
    exit;
  }

  if ($redirectKey === 'client_post_job') {
    header("Location: /fixmate/pages/dashboards/client/client-dashboard.php?page=post-job");
    exit;
  }

  header("Location: /fixmate/pages/dashboards/client/client-dashboard.php");
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account – <?php echo $app['app_name']; ?></title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

  <style>
    :root {
      --primary-dark: #1E293B;
      --secondary-color: #F97316;
      --btn-color: #06B6D4;
      --bg-color: #F8FAFC;
    }

    select option {
      background-color: var(--primary-dark);
      color: white;
      padding: 10px;
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

<body class="bg-[var(--primary-dark)] font-sans antialiased text-gray-800">

  <?php include("../../components/navbar.php"); ?>

  <section class="relative min-h-screen py-20 flex items-center justify-center overflow-hidden">

    <!-- <div
      class="absolute top-0 left-0 w-[520px] h-[520px] bg-[var(--secondary-color)]/10 rounded-full blur-[110px] -translate-x-1/2 -translate-y-1/2 pointer-events-none">
    </div>
    <div
      class="absolute bottom-0 right-0 w-[520px] h-[520px] bg-[var(--btn-color)]/10 rounded-full blur-[110px] translate-x-1/2 translate-y-1/2 pointer-events-none">
    </div> -->

    <div class="container relative z-10 mx-auto px-4">
      <div
        class="max-w-3xl mx-auto bg-white rounded-3xl shadow-[0_20px_50px_rgba(0,0,0,0.30)] relative overflow-hidden">

        <div class="absolute top-0 left-0 w-full h-2 bg-[var(--secondary-color)]"></div>

        <div class="p-8 md:p-12">
          <div class="text-center mb-10">
            <span class="text-[var(--secondary-color)] font-bold tracking-wider uppercase text-xs">
              Join <?php echo $app['app_name']; ?>
            </span>
            <h3 class="text-3xl font-extrabold text-[var(--primary-dark)] mt-2">
              Create Your <?php echo $app['app_name']; ?> Account
            </h3>
            <p class="text-gray-500 mt-2 text-sm">Register as a client to post jobs and track progress.</p>
          </div>

          <?php if (!empty($error)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-600 p-4 mb-8 rounded-r-lg text-sm flex items-center">
              <i class="fas fa-exclamation-circle mr-2"></i>
              <?php echo $error; ?>
            </div>
          <?php endif; ?>

          <form class="space-y-6" method="POST" enctype="multipart/form-data">

            <!-- Hidden fields -->
            <input type="hidden" name="redirect"
              value="<?php echo htmlspecialchars($redirectKeyInitial, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="job_id" value="<?php echo (int) $jobId; ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Account Type</label>
                <div class="relative">
                  <select
                    class="w-full h-[50px] px-4 bg-[var(--bg-color)] border border-gray-200 rounded-xl text-gray-600 cursor-not-allowed outline-none pointer-events-none appearance-none">
                    <option selected>Client (I need a service)</option>
                  </select>
                  <i class="fas fa-lock absolute right-4 top-4 text-gray-400"></i>
                </div>
              </div>

              <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">City*</label>
                <div class="relative">
                  <select
                    class="w-full h-[50px] px-4 bg-[var(--bg-color)] border border-gray-200 rounded-xl text-gray-500 cursor-not-allowed outline-none pointer-events-none appearance-none">
                    <option value="Karachi" selected>Karachi</option>
                  </select>
                  <i class="fas fa-lock absolute right-4 top-4 text-gray-400"></i>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Full Name*</label>
                <input type="text" name="name" required placeholder="John Doe"
                  value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
                  class="w-full h-[50px] px-4 bg-[var(--bg-color)] border border-gray-200 rounded-xl focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none">
              </div>

              <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Email Address (Optional)</label>
                <input type="email" name="email" placeholder="name@example.com"
                  value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                  class="w-full h-[50px] px-4 bg-[var(--bg-color)] border border-gray-200 rounded-xl focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none">
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Phone Number*</label>
                <input type="tel" id="phone" name="phone" required placeholder="03XXXXXXXXX" maxlength="11"
                  value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>"
                  class="w-full h-[50px] px-4 bg-[var(--bg-color)] border border-gray-200 rounded-xl focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none">
                <p class="text-xs text-gray-400 mt-1">Format: 03XXXXXXXXX (11 digits)</p>
              </div>

              <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">CNIC (Optional)</label>
                <input type="text" id="cnic" name="cnic" placeholder="42101-1234567-1" maxlength="15"
                  value="<?php echo htmlspecialchars($cnic, ENT_QUOTES, 'UTF-8'); ?>"
                  class="w-full h-[50px] px-4 bg-[var(--bg-color)] border border-gray-200 rounded-xl focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none">
                <p class="text-xs text-gray-400 mt-1">Format: XXXXX-XXXXXXX-X</p>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Select Area*</label>
                <select name="area" required
                  class="w-full h-[50px] px-4 bg-[var(--bg-color)] border border-gray-200 rounded-xl focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none cursor-pointer">
                  <option value="" disabled <?php echo empty($area) ? 'selected' : ''; ?>>Select Area in Karachi
                  </option>
                  <?php foreach ($karachi_areas as $a): ?>
                    <option value="<?php echo htmlspecialchars($a, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($area === $a) ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($a, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Address (Optional)</label>
                <input type="text" name="address" placeholder="Flat / Street / Block"
                  value="<?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?>"
                  class="w-full h-[50px] px-4 bg-[var(--bg-color)] border border-gray-200 rounded-xl focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none">
              </div>
            </div>

            <div>
              <label class="block text-sm font-bold text-gray-700 mb-2">Profile Picture (Optional)</label>
              <input type="file" id="profile_image" name="profile_image" accept="image/*"
                class="w-full px-4 py-3 bg-[var(--bg-color)] border border-gray-200 rounded-xl">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Password*</label>
                <input type="password" name="password" required placeholder="Min 8 chars"
                  class="w-full h-[50px] px-4 bg-[var(--bg-color)] border border-gray-200 rounded-xl focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none">
              </div>

              <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Confirm Password*</label>
                <input type="password" name="cpassword" required placeholder="Repeat password"
                  class="w-full h-[50px] px-4 bg-[var(--bg-color)] border border-gray-200 rounded-xl focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none">
              </div>
            </div>

            <button name="register_btn"
              class="w-full py-4 text-white font-bold rounded-xl text-lg shadow-lg transition-all duration-300 hover:-translate-y-1 mt-4 bg-[var(--btn-color)] hover:opacity-95 focus:ring-4 focus:outline-none focus:ring-[var(--btn-color)]/30">
              Create Account
            </button>

            <?php
            // build login URL preserve intent
            $loginQuery = [];
            if (!empty($redirectKeyInitial))
              $loginQuery['redirect'] = $redirectKeyInitial;
            if (!empty($jobId))
              $loginQuery['job_id'] = $jobId;

            $loginUrl = 'login.php';
            if (!empty($loginQuery))
              $loginUrl .= '?' . http_build_query($loginQuery);
            ?>

            <div class="text-center pt-4 border-t border-gray-100">
              <span class="text-gray-500">Already have an account?</span>
              <a class="text-[var(--secondary-color)] font-bold hover:underline ml-1"
                href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>">
                Log In
              </a>
            </div>

          </form>
        </div>
      </div>
    </div>
  </section>

  <?php include("../../components/footer.php"); ?>

  <script>
    // CNIC auto-format
    document.getElementById('cnic').addEventListener('input', function (e) {
      let x = e.target.value.replace(/\D/g, '').match(/(\d{0,5})(\d{0,7})(\d{0,1})/);
      e.target.value = !x[2] ? x[1] : x[1] + '-' + x[2] + (x[3] ? '-' + x[3] : '');
    });

    // phone numeric-only, max 11
    document.getElementById('phone').addEventListener('input', function () {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
    });
  </script>

</body>

</html>