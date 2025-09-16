-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Sep 16, 2025 at 04:27 AM
-- Server version: 8.4.3
-- PHP Version: 8.3.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `blog_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(255) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT 'default-admin.png',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `password`, `remember_token`, `first_name`, `last_name`, `profile_photo`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$T.Lq2Zkyy9DqVtW9yzMVWOZC37As8KFAS1UxHaV3BdRLI7otOmdOy', '42dc5bfba349d8b5c041b7afc000b368eea72caeae5a8382c2661e59b6edba1d', 'Admin', 'Team', 'default-user.png', '2025-08-31 04:54:43', '2025-09-07 09:11:18');

-- --------------------------------------------------------

--
-- Table structure for table `bookmarks`
--

CREATE TABLE `bookmarks` (
  `id` int NOT NULL,
  `post_id` int NOT NULL,
  `user_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text,
  `is_deletable` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `slug`, `description`, `is_deletable`, `created_at`, `updated_at`) VALUES
(1, 'Uncategorized', 'uncategorized', 'Default category for posts', 0, '2025-08-31 04:54:43', '2025-08-31 04:54:43'),
(14, 'Devops', 'devops', 'Devops', 1, '2025-09-07 09:18:49', '2025-09-07 09:18:49'),
(15, 'Cloudflare', 'cloudflare', 'Cloudflare', 1, '2025-09-07 09:19:03', '2025-09-07 09:19:03');

-- --------------------------------------------------------

--
-- Table structure for table `comments`
--

CREATE TABLE `comments` (
  `id` int NOT NULL,
  `post_id` int NOT NULL,
  `user_id` int NOT NULL,
  `content` text NOT NULL,
  `parent_id` int DEFAULT NULL,
  `admin_reply` text,
  `admin_reply_at` datetime DEFAULT NULL,
  `status` enum('approved','pending','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'approved',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `likes`
--

CREATE TABLE `likes` (
  `id` int NOT NULL,
  `post_id` int NOT NULL,
  `user_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `posts`
--

CREATE TABLE `posts` (
  `id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `excerpt` text,
  `featured_image` varchar(255) DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `admin_id` int DEFAULT NULL,
  `status` enum('published','unpublished','draft') DEFAULT 'draft',
  `views` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `posts`
--

INSERT INTO `posts` (`id`, `title`, `slug`, `content`, `excerpt`, `featured_image`, `category_id`, `admin_id`, `status`, `views`, `created_at`, `updated_at`) VALUES
(1, 'Cloudflare: Powering a Faster and Safer Internet', 'cloudflare-powering-a-faster-and-safer-internet', '<h2>Introduction</h2><p>Have you ever clicked on a website and wondered how it loads so quickly and securely, no matter where you are in the world? Chances are, <strong>Cloudflare</strong> is behind it. Known as one of the most popular web performance and security companies, Cloudflare helps businesses keep their websites fast, safe, and always available.</p><p>Whether you’re a beginner exploring website management or a business owner looking for reliable online protection, Cloudflare is a name you can’t ignore.</p><h2>What is Cloudflare?</h2><p>At its core, <strong>Cloudflare</strong> is a global network that makes websites faster, more secure, and more reliable. It works as a <strong>Content Delivery Network (CDN)</strong>, a <strong>Web Application Firewall (WAF)</strong>, and a <strong>DDoS protection service</strong> — all rolled into one.</p><p>Simply put, Cloudflare sits between your website’s server and your visitors. It filters malicious traffic, delivers content quickly from the nearest server, and ensures your site stays online even during traffic spikes.</p><h2>Background of Cloudflare</h2><p>Cloudflare was founded in 2009 with the mission to “help build a better Internet.” Initially starting as a security solution, it has now grown into a full-fledged platform powering <strong>millions of websites worldwide</strong>, including startups, Fortune 500 companies, and even government portals.</p><p>Over the years, it has expanded beyond just security, offering performance optimization, analytics, and even domain registration.</p><h2>Key Features of Cloudflare</h2><ul><li><strong>Content Delivery Network (CDN):</strong> Delivers your website content from servers closest to the visitor.</li><li><strong>DDoS Protection:</strong> Shields your site from large-scale traffic attacks.</li><li><strong>Web Application Firewall (WAF):</strong> Blocks malicious requests before they hit your server.</li><li><strong>SSL/TLS Encryption:</strong> Provides free HTTPS to secure your website.</li><li><strong>Performance Optimization:</strong> Features like image compression, caching, and load balancing.</li><li><strong>Analytics Dashboard:</strong> Real-time insights on traffic and threats.</li></ul><h2>Pros of Cloudflare</h2><ul><li>Free plan available for individuals and small websites</li><li>Enhanced website speed and reduced latency</li><li>Strong DDoS protection and security features</li><li>Easy to set up and manage</li><li>Global network of servers ensures high availability</li><li>SSL certificates at no cost</li></ul><h3>Cons of Cloudflare</h3><ul><li>Advanced features require paid plans</li><li>Can sometimes block legitimate traffic if not configured properly</li><li>Limited support options on the free plan</li><li>May not be suitable for very small websites with minimal traffic</li></ul><h2>Real-World Use Cases of Cloudflare</h2><ul><li><strong>Bloggers &amp; Small Businesses:</strong> Use the free plan for faster, safer websites.</li><li><strong>E-commerce Stores:</strong> Protect against fraud, DDoS, and downtime during peak sales.</li><li><strong>Large Enterprises:</strong> Ensure global customers experience fast load times and secure transactions.</li><li><strong>Government Websites:</strong> Safeguard against cyberattacks while maintaining uptime.</li></ul><h2>Conclusion</h2><p>Cloudflare is more than just a security tool — it’s a powerful platform that ensures websites are <strong>fast, secure, and always accessible</strong>. From small blogs to enterprise websites, it offers scalable solutions for everyone.</p><h2>Key Takeaway</h2><p><strong>Cloudflare simplifies website performance and security.</strong> Whether you’re just starting or running a global business, Cloudflare helps you deliver a better, safer online experience.</p>', 'Discover what Cloudflare is, its features, pros & cons, and real-world use cases. A beginner-friendly guide to faster, safer, and more reliable websites.', '68bd4de5ec0b9.png', 14, 1, 'published', 91, '2025-08-31 05:45:26', '2025-09-07 09:19:25'),
(29, 'What is DevOps? A Complete Roadmap', 'what-is-devops-a-complete-roadmap', '<h2><span style=\"font-family: &quot;Comic Sans MS&quot;;\">DevOps: The Bridge Between Development and Operations</span></h2><h2><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Introduction</span></h2><p><span style=\"font-family: &quot;Comic Sans MS&quot;;\">In today’s fast-paced digital world, businesses can’t afford to wait weeks or months to release software updates. Users demand quick fixes, new features, and smooth experiences. This is where DevOps comes in — a modern approach that bridges the gap between developers and IT operations. It’s not just a buzzword, but a cultural shift that helps organizations deliver software faster, smarter, and more reliably.</span></p><h2><span style=\"font-family: &quot;Comic Sans MS&quot;;\">What is DevOps?</span></h2><p><span style=\"font-family: &quot;Comic Sans MS&quot;;\">At its core, DevOps is the combination of Development (Dev) and Operations (Ops). It’s a set of practices, tools, and cultural philosophies that enable teams to build, test, and deploy software continuously. Instead of working in silos, developers and IT operations collaborate closely, making software delivery more efficient and less error-prone.</span></p><p><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Think of DevOps as a team sport where everyone plays together to score one goal — delivering value to the end user.</span></p><h2><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Background of DevOps</span></h2><p><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Before DevOps, companies followed traditional models like Waterfall or Agile. While Agile improved collaboration between developers and testers, there was still a huge gap between development and operations. Code would work fine in a developer’s environment but break when deployed live.</span></p><p><span style=\"font-family: &quot;Comic Sans MS&quot;;\">The frustration led to the birth of DevOps around 2007–2008, with a mission to break these barriers and foster a culture of shared responsibility.</span></p><h3><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Key Features of DevOps</span></h3><ul><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Continuous Integration (CI): Developers frequently merge code changes into a shared repository.</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Continuous Delivery (CD): Automates the release process so new features can be delivered quickly.</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Automation: From code testing to deployment, automation reduces errors and saves time.</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Collaboration: Teams share accountability for the success of the product.</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Monitoring: Real-time tracking ensures issues are caught before they affect end users.</span></li></ul><h3><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Pros of DevOps</span></h3><ul><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Faster delivery of features and updates</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Higher software quality due to continuous testing</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Better collaboration between teams</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Increased efficiency with automation</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Improved customer satisfaction</span></li></ul><h3><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Cons of DevOps</span></h3><ul><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Requires cultural change, which can be challenging</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Initial setup of tools and processes can be costly</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">May not fit small projects with limited resources</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Continuous delivery can feel overwhelming without the right team structure</span></li></ul><h3><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Real-World Use Cases of DevOps</span></h3><ul><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Netflix: Uses DevOps to deploy thousands of code changes daily, ensuring seamless streaming.</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Amazon: Achieves rapid innovation by continuously updating its services using DevOps pipelines.</span></li><li><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Etsy: Transitioned from painful weekly deployments to multiple deployments a day with fewer failures.</span></li></ul><h3><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Conclusion</span></h3><p><span style=\"font-family: &quot;Comic Sans MS&quot;;\">DevOps is not just a set of tools — it’s a mindset. By bringing developers and operations together, it creates a culture of collaboration, speed, and reliability. While it comes with challenges, the long-term benefits far outweigh the hurdles.</span></p><h3><span style=\"font-family: &quot;Comic Sans MS&quot;;\">Key Takeaway</span></h3><p><span style=\"font-family: &quot;Comic Sans MS&quot;;\">DevOps is the future of software development and delivery. For businesses looking to stay competitive, adopting DevOps practices is no longer optional — it’s essential.</span></p><p><span style=\"font-family: &quot;Comic Sans MS&quot;;\">&nbsp;</span><img src=\"/uploads/posts/1756811402_68b6d08a4f1d6.png\" style=\"width: 100%;\"></p><p><span style=\"font-family: &quot;Comic Sans MS&quot;;\">&nbsp;</span></p>', 'Learn what DevOps is, its features, pros & cons, and real-world use cases. A beginner-friendly guide to faster, smarter software delivery.', '68bd4d7a936a9.png', 14, 1, 'published', 35, '2025-09-02 05:04:36', '2025-09-07 09:19:20');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(200) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT 'default-user.png',
  `is_verified` tinyint(1) DEFAULT '0',
  `login_attempts` int NOT NULL DEFAULT '0',
  `last_login_attempt_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `otp` varchar(10) DEFAULT NULL,
  `otp_hash` varchar(255) DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `otp_resend_count` int NOT NULL DEFAULT '0',
  `otp_last_sent_at` datetime DEFAULT NULL,
  `otp_verify_attempts` int NOT NULL DEFAULT '0',
  `otp_last_attempt_at` datetime DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `reset_request_count` int NOT NULL DEFAULT '0',
  `reset_last_sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `full_name`, `profile_photo`, `is_verified`, `login_attempts`, `last_login_attempt_at`, `last_login_at`, `otp`, `otp_hash`, `otp_expires_at`, `otp_resend_count`, `otp_last_sent_at`, `otp_verify_attempts`, `otp_last_attempt_at`, `reset_token`, `reset_expires_at`, `created_at`, `updated_at`, `reset_request_count`, `reset_last_sent_at`) VALUES
(1, 'Divyam', 'divyamdrive002@gmail.com', '$2y$10$ckwHwQr7O2SlF8EH5hitreM5zzWEk6cRIfBqHZCb6bbNtyEOqR0lG', 'Divyam Varshney', 'default-user.png', 1, 0, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, '2025-09-03 11:06:36', '2025-09-03 11:21:47', 0, NULL),
(2, 'Divyam2', 'divyam10june@gmail.com', '$2y$10$JZVfItR/qW21d10zZRCijO.TXlTSsf04zTRUIGxJk3/EbzcvBEud6', 'Divyam Varshney', 'default-user.png', 1, 0, NULL, '2025-09-03 17:28:01', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, '2025-09-03 11:09:49', '2025-09-03 11:58:01', 0, NULL),
(3, 'divyam_vars', 'divyam10june+12@gmail.com', '$2y$10$ZHuZRH.MSq70N2j7JPKfO.2sUWZwxAJlzEF9shWWBC8q3TOAn8xxi', 'Divyam Varshney', 'default-user.png', 1, 0, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, '2025-09-03 11:52:40', '2025-09-03 11:53:23', 0, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `bookmarks`
--
ALTER TABLE `bookmarks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_bookmark` (`post_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `post_id` (`post_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `likes`
--
ALTER TABLE `likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_like` (`post_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `posts`
--
ALTER TABLE `posts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_otp_last_sent_at` (`otp_last_sent_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `bookmarks`
--
ALTER TABLE `bookmarks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `comments`
--
ALTER TABLE `comments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `likes`
--
ALTER TABLE `likes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `posts`
--
ALTER TABLE `posts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookmarks`
--
ALTER TABLE `bookmarks`
  ADD CONSTRAINT `bookmarks_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookmarks_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `comments`
--
ALTER TABLE `comments`
  ADD CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `comments_ibfk_3` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `likes`
--
ALTER TABLE `likes`
  ADD CONSTRAINT `likes_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `likes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `posts`
--
ALTER TABLE `posts`
  ADD CONSTRAINT `posts_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `posts_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
