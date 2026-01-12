-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 12, 2026 at 04:47 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `fixmatenew`
--

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `description`) VALUES
(1, 'Electrician', 'Certified electricians for safe wiring, fault repairs, lighting installation, switch/socket replacement, breaker issues, and power load management—ensuring your home stays protected and fully function'),
(2, 'Plumber', 'Professional plumbing for leak detection, pipe repairs, drainage cleaning, fixture installation, water tank connections, and bathroom/kitchen maintenance—delivering smooth water flow with long-term re'),
(3, 'AC Technician', 'Expert AC technicians for installation, seasonal servicing, gas charging, cooling performance checks, filter cleaning, and quick troubleshooting—keeping your space comfortable with efficient and relia'),
(4, 'Car Mechanic', 'Skilled car mechanics for routine maintenance, engine diagnostics, oil and fluid changes, brake and suspension repairs, battery issues, and emergency breakdown support—so your vehicle runs smoothly.'),
(5, 'Flooring', 'Flooring specialists for tile, marble, laminate, vinyl, and wood work including installation, polishing, repairs, re-grouting, and leveling—giving your floors a clean finish and durable surface.'),
(6, 'Pest Control', 'Safe pest control services for termites, cockroaches, ants, mosquitoes, and rodents using planned treatments, sealing, and prevention—protecting your home with effective, family-friendly solutions.');

-- --------------------------------------------------------

--
-- Table structure for table `contact_form`
--

CREATE TABLE `contact_form` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `email` varchar(191) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `contact_form`
--

INSERT INTO `contact_form` (`id`, `name`, `email`, `subject`, `message`, `created_at`) VALUES
(1, 'wdawda', 'wawda@gmai.com', 'wdawd', 'awdaw', '2026-01-09 20:50:30');

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `title` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `sub_category_id` int(11) DEFAULT NULL,
  `location_text` varchar(255) NOT NULL,
  `budget` int(11) NOT NULL DEFAULT 0,
  `preferred_date` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `status` enum('live','assigned','inprogress','confirmation_needed','completed','expired','deleted') NOT NULL DEFAULT 'live',
  `worker_marked_done` tinyint(1) NOT NULL DEFAULT 0,
  `client_marked_done` tinyint(1) NOT NULL DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `is_attachments` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `jobs`
--

INSERT INTO `jobs` (`id`, `client_id`, `title`, `description`, `category_id`, `sub_category_id`, `location_text`, `budget`, `preferred_date`, `expires_at`, `status`, `worker_marked_done`, `client_marked_done`, `completed_at`, `is_attachments`, `created_at`, `updated_at`) VALUES
(1, 1, 'test 1', 'test 1', 4, 7, '123 house near dha 5 karachi', 800, '2026-01-09 14:40:00', '2026-01-09 23:59:59', 'live', 0, 0, NULL, 'yes', '2026-01-08 17:30:10', '2026-01-08 21:10:46'),
(2, 1, 'test 2', 'test 2', 6, 12, '123 house near dha 5 karachi', 500, '0000-00-00 00:00:00', '2026-01-12 23:59:59', 'completed', 0, 0, NULL, 'yes', '2026-01-08 19:01:09', '2026-01-09 19:01:37'),
(3, 1, 'wdaw', 'wdaw', 4, 7, 'wdaw', 900, '0000-00-00 00:00:00', '2026-01-16 23:59:59', 'completed', 0, 0, NULL, 'yes', '2026-01-09 18:42:46', '2026-01-09 21:31:05'),
(4, 1, 'fgrtgr', 'grgr', 3, 5, 'grgr', 7700, '0000-00-00 00:00:00', '2026-01-16 23:59:59', 'live', 0, 0, NULL, 'no', '2026-01-09 18:50:03', '2026-01-09 18:50:03'),
(5, 1, 'need worker', 'need worker', 1, 1, '123 house near dha 3 karachi', 1500, '0000-00-00 00:00:00', '2026-01-14 23:59:59', 'completed', 0, 0, NULL, 'yes', '2026-01-09 21:47:42', '2026-01-09 21:52:15');

-- --------------------------------------------------------

--
-- Table structure for table `job_attachments`
--

CREATE TABLE `job_attachments` (
  `id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `file_type` enum('image','video','audio') NOT NULL,
  `file_url` varchar(255) NOT NULL,
  `thumb_url` varchar(255) DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_attachments`
--

INSERT INTO `job_attachments` (`id`, `job_id`, `file_type`, `file_url`, `thumb_url`, `duration_seconds`, `created_at`) VALUES
(1, 1, 'image', '/fixmate/uploads/jobs/job_1_695fe9a2a6ef88.57656455.jpg', NULL, NULL, '2026-01-08 17:30:10'),
(2, 1, 'audio', '/fixmate/uploads/jobs/audio/job_1_voice_1767893410.webm', NULL, NULL, '2026-01-08 17:30:10'),
(3, 2, 'image', '/fixmate/uploads/jobs/job_2_695ffef554cbb2.89883621.jpg', NULL, NULL, '2026-01-08 19:01:09'),
(4, 3, 'image', '/fixmate/uploads/jobs/job_3_69614c260a85e2.46784028.jpg', NULL, NULL, '2026-01-09 18:42:46'),
(5, 3, 'audio', '/fixmate/uploads/jobs/audio/job_3_voice_1767984166.webm', NULL, NULL, '2026-01-09 18:42:46'),
(6, 5, 'image', '/fixmate/uploads/jobs/job_5_6961777eb97f07.47218668.jpg', NULL, NULL, '2026-01-09 21:47:42');

-- --------------------------------------------------------

--
-- Table structure for table `job_status_history`
--

CREATE TABLE `job_status_history` (
  `id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `old_status` varchar(40) NOT NULL,
  `new_status` varchar(40) NOT NULL,
  `note` text DEFAULT NULL,
  `changed_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_worker_assignments`
--

CREATE TABLE `job_worker_assignments` (
  `id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `worker_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `action` enum('assigned','replaced_previous','removed_previous') NOT NULL,
  `reason` text DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ended_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_worker_assignments`
--

INSERT INTO `job_worker_assignments` (`id`, `job_id`, `worker_id`, `admin_id`, `action`, `reason`, `assigned_at`, `ended_at`) VALUES
(1, 2, 1, 3, '', 'previous one was not available', '2026-01-09 18:53:16', '2026-01-09 23:55:04'),
(2, 2, 2, 3, 'assigned', 'previous one was not available', '2026-01-09 18:55:04', NULL),
(3, 3, 2, 3, 'assigned', '', '2026-01-09 19:14:07', NULL),
(4, 5, 2, 3, 'assigned', '', '2026-01-09 21:48:16', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `job_id` int(11) DEFAULT NULL,
  `type` enum('job_update','assigned','replaced','removed','message','system') NOT NULL,
  `title` varchar(160) NOT NULL,
  `body` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `job_id`, `type`, `title`, `body`, `link`, `is_read`, `created_at`) VALUES
(1, 1, 3, '', 'Job completed', 'Job #3 has been confirmed completed by admin.', '/fixmate/pages/dashboards/client/client-dashboard.php?page=job-detail&job_id=3', 0, '2026-01-09 21:31:05'),
(2, 1, 5, '', 'Worker assigned', 'A worker has been assigned to your job #5.', '/fixmate/pages/dashboards/client/client-dashboard.php?page=job-detail&job_id=5', 0, '2026-01-09 21:48:16'),
(3, 1, 5, '', 'Worker is coming', 'Your job #5 is in progress. The worker will come soon today.', '/fixmate/pages/dashboards/client/client-dashboard.php?page=job-detail&job_id=5', 0, '2026-01-09 21:48:28'),
(4, 1, 5, '', 'Job completed', 'Job #5 has been confirmed completed by admin.', '/fixmate/pages/dashboards/client/client-dashboard.php?page=job-detail&job_id=5', 0, '2026-01-09 21:51:42'),
(5, 1, 5, '', 'Job completed', 'Job #5 has been confirmed completed by admin.', '/fixmate/pages/dashboards/client/client-dashboard.php?page=job-detail&job_id=5', 0, '2026-01-09 21:52:15');

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `worker_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sub_categories`
--

CREATE TABLE `sub_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sub_categories`
--

INSERT INTO `sub_categories` (`id`, `category_id`, `name`, `is_active`, `created_at`) VALUES
(1, 1, 'Wiring & Rewiring', 1, '2026-01-07 21:53:16'),
(2, 1, 'Switches & Fixtures', 1, '2026-01-07 21:53:16'),
(3, 2, 'Leak Repair', 1, '2026-01-07 21:53:16'),
(4, 2, 'Drain Cleaning', 1, '2026-01-07 21:53:16'),
(5, 3, 'AC Installation', 1, '2026-01-07 21:53:16'),
(6, 3, 'AC Gas Refill', 1, '2026-01-07 21:53:16'),
(7, 4, 'Engine Diagnostics', 1, '2026-01-07 21:53:16'),
(8, 4, 'Oil Change', 1, '2026-01-07 21:53:16'),
(9, 5, 'Tile Installation', 1, '2026-01-07 21:53:16'),
(10, 5, 'Wood Flooring', 1, '2026-01-07 21:53:16'),
(11, 6, 'Termite Control', 1, '2026-01-07 21:53:16'),
(12, 6, 'General Pest Treatment', 1, '2026-01-07 21:53:16');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `phone` varchar(11) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('client','admin') NOT NULL DEFAULT 'client',
  `city` varchar(100) DEFAULT NULL,
  `area` varchar(120) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `cnic` varchar(15) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `phone`, `email`, `password_hash`, `role`, `city`, `area`, `address`, `profile_image`, `cnic`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'monis iqbal', '03378372427', 'monis1@gmail.com', '$2y$10$MZbpE7YBjIOpPPrvvlHxm.3ArWVBtXH66Pc6AVc4a/ObQLcdsYpZC', 'client', 'Karachi', 'DHA Phase 6', '13 house near dha phase 6 karachi', '/fixmate/uploads/profile_images/profile_1767816010_8431.jpg', '42101-3313531-1', 1, '2026-01-07 20:00:10', '2026-01-07 20:00:10'),
(2, 'haris', '03378372428', 'haris1@gmail.com', '$2y$10$kYuun16IO5fybr3woaNiPOL/alrY1XUvn.6RnOHjJmX/UPCwVWyji', 'client', 'Karachi', 'DHA Phase 4', '15 house near mart dha 4 karachi', '/fixmate/uploads/profile_images/profile_1767817925_8861.jpg', '42101-0323123-1', 1, '2026-01-07 20:32:05', '2026-01-07 20:32:05'),
(3, 'admin', '03378372421', 'admin1@gmail.com', '$2y$10$SbtJ9XdPvEEh69r5McQP5uAVt320UD2kl2QnW3KJ56QP7rOZVGygG', 'admin', 'Karachi', 'Saddar', '123 house saddar karachi', '/fixmate/uploads/profile_images/profile_1767908750_4958.jpg', '42101-3313576-8', 1, '2026-01-08 21:45:50', '2026-01-08 21:48:44');

-- --------------------------------------------------------

--
-- Table structure for table `workers`
--

CREATE TABLE `workers` (
  `id` int(11) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `phone` varchar(11) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `cnic` varchar(13) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `area` varchar(120) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `rating_avg` decimal(3,2) NOT NULL DEFAULT 0.00,
  `jobs_completed` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `workers`
--

INSERT INTO `workers` (`id`, `full_name`, `phone`, `email`, `cnic`, `city`, `area`, `address`, `profile_image`, `is_available`, `is_active`, `rating_avg`, `jobs_completed`, `created_at`, `updated_at`) VALUES
(1, 'worker 1', '03378372422', 'worker1@gmail.com', '4210133135352', 'karachi', 'dha 7', '12 house dha 7 karachi', '/fixmate/uploads/profile_images/worker1_35d9c9.jpg', 1, 1, 0.00, 0, '2026-01-09 15:22:26', '2026-01-09 18:07:48'),
(2, 'worker 2', '03378372423', 'worker2@gmail.com', '4210801273192', 'karachi', 'gulshan', '4 house gulshan karachi', '/fixmate/uploads/profile_images/worker2_7a4780.jpg', 1, 1, 0.00, 0, '2026-01-09 18:07:25', '2026-01-09 18:07:25');

-- --------------------------------------------------------

--
-- Table structure for table `worker_categories`
--

CREATE TABLE `worker_categories` (
  `worker_id` int(11) NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `worker_categories`
--

INSERT INTO `worker_categories` (`worker_id`, `category_id`) VALUES
(1, 4),
(1, 5),
(1, 6),
(2, 3),
(2, 4),
(2, 5);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `contact_form`
--
ALTER TABLE `contact_form`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `idx_jobs_sub_category_id` (`sub_category_id`);

--
-- Indexes for table `job_attachments`
--
ALTER TABLE `job_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_id` (`job_id`);

--
-- Indexes for table `job_status_history`
--
ALTER TABLE `job_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_id` (`job_id`),
  ADD KEY `changed_by_admin_id` (`changed_by_admin_id`);

--
-- Indexes for table `job_worker_assignments`
--
ALTER TABLE `job_worker_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_id` (`job_id`),
  ADD KEY `worker_id` (`worker_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `job_id` (`job_id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `job_id` (`job_id`),
  ADD KEY `worker_id` (`worker_id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `sub_categories`
--
ALTER TABLE `sub_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_category_subcategory` (`category_id`,`name`),
  ADD KEY `idx_sub_cat_category` (`category_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `workers`
--
ALTER TABLE `workers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_workers_phone` (`phone`);

--
-- Indexes for table `worker_categories`
--
ALTER TABLE `worker_categories`
  ADD PRIMARY KEY (`worker_id`,`category_id`),
  ADD KEY `worker_categories_ibfk_2` (`category_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `contact_form`
--
ALTER TABLE `contact_form`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `job_attachments`
--
ALTER TABLE `job_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `job_status_history`
--
ALTER TABLE `job_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_worker_assignments`
--
ALTER TABLE `job_worker_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sub_categories`
--
ALTER TABLE `sub_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `workers`
--
ALTER TABLE `workers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `jobs`
--
ALTER TABLE `jobs`
  ADD CONSTRAINT `jobs_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `job_attachments`
--
ALTER TABLE `job_attachments`
  ADD CONSTRAINT `job_attachments_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `job_status_history`
--
ALTER TABLE `job_status_history`
  ADD CONSTRAINT `job_status_history_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `job_status_history_ibfk_2` FOREIGN KEY (`changed_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `job_worker_assignments`
--
ALTER TABLE `job_worker_assignments`
  ADD CONSTRAINT `job_worker_assignments_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `job_worker_assignments_ibfk_2` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`),
  ADD CONSTRAINT `job_worker_assignments_ibfk_3` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`),
  ADD CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sub_categories`
--
ALTER TABLE `sub_categories`
  ADD CONSTRAINT `fk_sub_category_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `worker_categories`
--
ALTER TABLE `worker_categories`
  ADD CONSTRAINT `worker_categories_ibfk_1` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `worker_categories_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
