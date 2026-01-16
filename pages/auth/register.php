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

  // Fields hidden/optional for now
  $address = trim($_POST['address'] ?? '');
  $area = trim($_POST['area'] ?? '');
  $city = "Karachi";
  $cnic = trim($_POST['cnic'] ?? '');

  $phone = trim($_POST['phone'] ?? '');

  $redirectKey = $_POST['redirect'] ?? ($_GET['redirect'] ?? '');

  $sticky = compact('name', 'email', 'address', 'area', 'phone', 'cnic');

  // validations
  if ($name === '') {
    fm_register_back("Name is required!", $sticky, $jobId, $redirectKeyInitial);
  }
  // Phone is required, Area is commented out so we removed it from check
  if ($phone === '') {
    fm_register_back("Phone Number is required.", $sticky, $jobId, $redirectKeyInitial);
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

  // CNIC validation commented out as field is hidden/optional
  /*
  if ($cnic !== '' && !preg_match("/^[0-9]{5}-[0-9]{7}-[0-9]{1}$/", $cnic)) {
    fm_register_back("Invalid CNIC format! Use XXXXX-XXXXXXX-X", $sticky, $jobId, $redirectKeyInitial);
  }
  */

  // escape
  $db_name = $conn->real_escape_string($name);
  $db_email = $email !== '' ? $conn->real_escape_string($email) : null;
  $db_address = $conn->real_escape_string($address);
  $db_area = $conn->real_escape_string($area);
  $db_city = $conn->real_escape_string($city);
  $db_phone = $conn->real_escape_string($phone);
  $db_cnic = $cnic !== '' ? $conn->real_escape_string($cnic) : null;

  // 1. Check if PHONE already exists
  $checkPhone = $conn->query("SELECT id FROM users WHERE phone='$db_phone' LIMIT 1");
  if ($checkPhone && $checkPhone->num_rows > 0) {
    fm_register_back("Phone number already exists!", $sticky, $jobId, $redirectKeyInitial);
  }

  // 2. Check if EMAIL already exists (only if user provided one)
  // Logic: Phone is new (passed check 1), but email might be taken by someone else
  if ($db_email) {
    $checkEmail = $conn->query("SELECT id FROM users WHERE email='$db_email' LIMIT 1");
    if ($checkEmail && $checkEmail->num_rows > 0) {
      fm_register_back("This email is already registered with another account.", $sticky, $jobId, $redirectKeyInitial);
    }
  }

  // profile image (optional - keeping code but field is hidden in HTML)
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
      --secondary-color: #ff6b35;
      /* Orange matched from SS */
      --btn-color: #ff6b35;
      /* Orange Button */
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
      --dark-bg: #13151f;
      --card-dark: #1c1f2e;
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

    <div class="container relative z-10 mx-auto px-4">
      <div class="max-w-md mx-auto bg-white rounded-3xl shadow-[0_20px_50px_rgba(0,0,0,0.30)] relative overflow-hidden">

        <div class="absolute top-0 left-0 w-full h-2 bg-[var(--secondary-color)]"></div>

        <div class="p-8 md:p-12">
          <div class="text-center mb-8">
            <h3 class="text-2xl font-bold text-[var(--primary-dark)]">
              Create Account
            </h3>
            <p class="text-gray-500 mt-1 text-sm">Register to get started</p>
          </div>

          <?php if (!empty($error)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-600 p-4 mb-6 rounded-r-lg text-sm flex items-center">
              <i class="fas fa-exclamation-circle mr-2"></i>
              <?php echo $error; ?>
            </div>
          <?php endif; ?>

          <form class="space-y-5" method="POST" enctype="multipart/form-data">

            <input type="hidden" name="redirect"
              value="<?php echo htmlspecialchars($redirectKeyInitial, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="job_id" value="<?php echo (int) $jobId; ?>">

            <div>
              <label class="block text-sm font-bold text-gray-700 mb-2">Full Name</label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <i class="fa-regular fa-user text-gray-400"></i>
                </div>
                <input type="text" name="name" required placeholder="John Doe"
                  value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
                  class="w-full h-[50px] pl-10 pr-4 bg-[var(--bg-color)] border border-gray-200 rounded-lg focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none">
              </div>
            </div>

            <div>
              <label class="block text-sm font-bold text-gray-700 mb-2">Email (Optional)</label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <i class="fa-regular fa-envelope text-gray-400"></i>
                </div>
                <input type="email" name="email" placeholder="user@example.com"
                  value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                  class="w-full h-[50px] pl-10 pr-4 bg-[var(--bg-color)] border border-gray-200 rounded-lg focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none">
              </div>
            </div>

            <div>
              <label class="block text-sm font-bold text-gray-700 mb-2">Phone Number</label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <i class="fa-solid fa-phone text-gray-400 text-sm"></i>
                </div>
                <input type="tel" id="phone" name="phone" required placeholder="03XXXXXXXXX" maxlength="11"
                  value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>"
                  class="w-full h-[50px] pl-10 pr-4 bg-[var(--bg-color)] border border-gray-200 rounded-lg focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none">
              </div>
            </div>

            <div>
              <label class="block text-sm font-bold text-gray-700 mb-2">Password</label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <i class="fa-solid fa-lock text-gray-400"></i>
                </div>
                <input type="password" name="password" required placeholder="........"
                  class="w-full h-[50px] pl-10 pr-4 bg-[var(--bg-color)] border border-gray-200 rounded-lg focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none">
              </div>
            </div>

            <div>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <i class="fa-solid fa-lock text-gray-400"></i>
                </div>
                <input type="password" name="cpassword" required placeholder="Confirm Password"
                  class="w-full h-[50px] pl-10 pr-4 bg-[var(--bg-color)] border border-gray-200 rounded-lg focus:ring-2 focus:ring-[var(--secondary-color)]/20 focus:border-[var(--secondary-color)] transition-all outline-none">
              </div>
            </div>

            <button name="register_btn"
              class="w-full py-3 text-white font-bold rounded-lg text-lg shadow-md transition-all duration-300 hover:-translate-y-1 mt-6 bg-[var(--btn-color)] hover:opacity-90">
              Register
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

            <div class="text-center pt-4">
              <span class="text-gray-400 text-sm">Already have an account?</span>
              <a class="text-[var(--secondary-color)] font-bold hover:underline ml-1 text-sm"
                href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>">
                Login here
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
    // document.getElementById('cnic').addEventListener('input', function (e) {
    //   let x = e.target.value.replace(/\D/g, '').match(/(\d{0,5})(\d{0,7})(\d{0,1})/);
    //   e.target.value = !x[2] ? x[1] : x[1] + '-' + x[2] + (x[3] ? '-' + x[3] : '');
    // });

    // phone numeric-only, max 11
    document.getElementById('phone').addEventListener('input', function () {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
    });
  </script>

</body>

</html>