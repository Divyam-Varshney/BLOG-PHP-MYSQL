<?php
// ============================
// File: db_conn.php
// Purpose: Database + SMTP Configuration (with localhost/hosting switch)
// ============================

// 🌐 Always use IST for PHP
date_default_timezone_set('Asia/Kolkata');

// Detect environment automatically
$isLocal = in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1']);

// ============================
// Database Configuration
// ============================
if ($isLocal) {
    // 🖥️ Localhost settings
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'blog_db');
} else {
    // 🌍 Hosting settings
    define('DB_HOST', 'localhost'); // or your host’s DB server if different
    define('DB_USER', 'nextgend_admin');
    define('DB_PASS', 'myJoker@19');
    define('DB_NAME', 'nextgend_root');
}

try {
    // ✅ PDO connection with recommended options
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT         => true, // Reuse connections (better performance)
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:30'"
        ]
    );
} catch (PDOException $e) {
    // Log real error
    error_log("Database connection failed: " . $e->getMessage());
    die("⚠️ Service temporarily unavailable. Please try again later.");
}

// ============================
// SMTP Configuration (Same for both)
// ============================
define('SMTP_HOST', 'mail.nextgen-devops.site');
define('SMTP_PORT', 465);
define('SMTP_USERNAME', 'noreply@nextgen-devops.site'); 
define('SMTP_PASSWORD', 'myJoker@19'); 
define('SMTP_FROM_EMAIL', 'noreply@nextgen-devops.site'); 
define('SMTP_FROM_NAME', 'NextGen DevOps');

define('SMTP_SECURE', (SMTP_PORT == 465) ? 'ssl' : 'tls'); 
define('SMTP_DEBUG', 0); // 0 = prod, 2 = debug

?>