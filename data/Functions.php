<?php
// data/Functions.php
// Core functions for the blog website

require_once __DIR__ . '/../db_conn.php';
// Session management
function app_start_session() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
}

// Password hashing and verification
function app_hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function app_verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// Generate secure random token
function app_generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

// Generate OTP
function app_generate_otp($length = 6) {
    return str_pad(mt_rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

// Create slug from string
function app_create_slug($string) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string)));
    return trim($slug, '-');
}

// Sanitize input
function app_sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// Validate email
function app_validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// File upload handling
function app_upload_file($file, $upload_dir, $allowed_types = ['jpg', 'jpeg', 'png', 'gif']) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_types)) {
        return false;
    }
    
    $filename = uniqid() . '.' . $file_extension;
    $upload_path = $upload_dir . '/' . $filename;
    
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        return $filename;
    }
    
    return false;
}
// Send email using PHPMailer (Advanced Version)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function app_send_email($to, $subject, $message, $is_html = true, $attachments = []) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log("PHPMailer autoload not found at: $autoload");
        return false;
    }
    require_once $autoload;

    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PHPMailer class not found even after autoload. Check vendor/.");
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64'; // better for special chars

        // Encryption based on port
        $mail->SMTPSecure = ((int)SMTP_PORT === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;

        // Envelope
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->Sender   = SMTP_FROM_EMAIL;
        $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);

        // Optional: Add CC / BCC globally
        if (defined('SMTP_CC') && SMTP_CC) $mail->addCC(SMTP_CC);
        if (defined('SMTP_BCC') && SMTP_BCC) $mail->addBCC(SMTP_BCC);

        // Attachments
        if (!empty($attachments)) {
            foreach ($attachments as $file) {
                if (file_exists($file)) {
                    $mail->addAttachment($file);
                }
            }
        }

        // Content
        $mail->isHTML($is_html);
        $mail->Subject = "✅ " . $subject; // Add emoji prefix for visibility
        $mail->Body    = $message;
        $mail->AltBody = strip_tags($message);
        $mail->Hostname = $_SERVER['SERVER_NAME'] ?? 'nextgen-devops.site';

        // Tracking headers (optional, useful for debugging & deliverability)
        $mail->addCustomHeader('X-Mailer', 'NextGen-PHP-Mailer');
        $mail->addCustomHeader('X-Originating-IP', $_SERVER['SERVER_ADDR'] ?? 'unknown');

        // Send
        $mail->send();

        $msgId = method_exists($mail, 'getLastMessageID') ? $mail->getLastMessageID() : '';
        error_log("✅ Mail sent to {$to} | Message-ID: {$msgId}");
        return true;
    } catch (Exception $e) {
        $err = "❌ PHPMailer Exception: " . $e->getMessage();
        if (!empty($mail->ErrorInfo)) {
            $err .= " | ErrorInfo: " . $mail->ErrorInfo;
        }
        error_log($err);
        return false;
    } catch (\Exception $e) {
        error_log("❌ General mail exception: " . $e->getMessage());
        return false;
    }
}


// User authentication functions
function app_login_user($user_id) {
    app_start_session();
    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_type'] = 'user';
}

function app_login_admin($admin_id) {
    app_start_session();
    $_SESSION['admin_id'] = $admin_id;
    $_SESSION['user_type'] = 'admin';
}

function app_logout() {
    app_start_session();
    session_destroy();
}

function app_is_user_logged_in() {
    app_start_session();
    return isset($_SESSION['user_id']) && $_SESSION['user_type'] === 'user';
}

function app_is_admin_logged_in() {
    app_start_session();
    return isset($_SESSION['admin_id']) && $_SESSION['user_type'] === 'admin';
}

function app_get_current_user() {
    global $pdo;
    app_start_session();
    
    if (!app_is_user_logged_in()) {
        return null;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :user_id");
    $stmt->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch();
}

function app_get_current_admin() {
    global $pdo;
    app_start_session();
    
    if (!app_is_admin_logged_in()) {
        return null;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = :admin_id");
    $stmt->bindValue(':admin_id', $_SESSION['admin_id'], PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch();
}

// Post functions
function app_get_posts($limit = 10, $offset = 0, $category_id = null, $search = null, $status = 'published') {
    global $pdo;
    
    $sql = "SELECT p.*, c.name as category_name, c.slug as category_slug, a.first_name, a.last_name,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) as likes_count,
            (SELECT COUNT(*) FROM comments cm WHERE cm.post_id = p.id AND cm.status = 'approved') as comments_count
            FROM posts p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN admins a ON p.admin_id = a.id
            WHERE p.status = :status";
    
    $params = [':status' => $status];
    
    if ($category_id) {
        $sql .= " AND p.category_id = :category_id";
        $params[':category_id'] = $category_id;
    }
    
    if ($search) {
        $sql .= " AND (p.title LIKE :search OR p.content LIKE :search OR p.excerpt LIKE :search)";
        $params[':search'] = "%{$search}%";
    }
    
    $sql .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    
    // Bind string/normal params
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    // Bind limit/offset as integers
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    
    $stmt->execute();
    return $stmt->fetchAll();
}

function app_get_post_by_slug($slug) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT p.*, c.name as category_name, c.slug as category_slug, a.first_name, a.last_name,
                          (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) as likes_count,
                          (SELECT COUNT(*) FROM comments cm WHERE cm.post_id = p.id AND cm.status = 'approved') as comments_count
                          FROM posts p 
                          LEFT JOIN categories c ON p.category_id = c.id 
                          LEFT JOIN admins a ON p.admin_id = a.id
                          WHERE p.slug = :slug AND p.status = 'published'");
    $stmt->bindValue(':slug', $slug);
    $stmt->execute();
    return $stmt->fetch();
}

function app_increment_post_views($post_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("UPDATE posts SET views = views + 1 WHERE id = :post_id");
    $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
    $stmt->execute();
}

// Category functions
function app_get_categories($include_post_count = false) {
    global $pdo;
    
    if ($include_post_count) {
        $stmt = $pdo->prepare("SELECT c.*, COUNT(p.id) as post_count 
                              FROM categories c 
                              LEFT JOIN posts p ON c.id = p.category_id AND p.status = 'published'
                              GROUP BY c.id 
                              ORDER BY c.name");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM categories ORDER BY name");
    }
    
    $stmt->execute();
    return $stmt->fetchAll();
}

function app_get_category_by_slug($slug) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE slug = :slug");
    $stmt->bindValue(':slug', $slug);
    $stmt->execute();
    return $stmt->fetch();
}

// Comment functions
function app_get_post_comments($post_id, $status = 'approved') {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT c.*, u.username, u.profile_photo 
                          FROM comments c 
                          JOIN users u ON c.user_id = u.id 
                          WHERE c.post_id = :post_id AND c.status = :status AND c.parent_id IS NULL
                          ORDER BY c.created_at DESC");
    $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
    $stmt->bindValue(':status', $status);
    $stmt->execute();
    return $stmt->fetchAll();
}

function app_add_comment($post_id, $user_id, $content, $parent_id = null) {
    global $pdo;
    
    $stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, content, parent_id) VALUES (:post_id, :user_id, :content, :parent_id)");
    $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':content', $content);
    $stmt->bindValue(':parent_id', $parent_id, PDO::PARAM_INT);
    return $stmt->execute();
}

// Like functions
function app_toggle_like($post_id, $user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id FROM likes WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->fetch()) {
        // Unlike
        $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = :post_id AND user_id = :user_id");
        $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        return false;
    } else {
        // Like
        $stmt = $pdo->prepare("INSERT INTO likes (post_id, user_id) VALUES (:post_id, :user_id)");
        $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        return true;
    }
}

function app_is_post_liked($post_id, $user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id FROM likes WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch() !== false;
}

// Bookmark functions
function app_toggle_bookmark($post_id, $user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->fetch()) {
        // Remove bookmark
        $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE post_id = :post_id AND user_id = :user_id");
        $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        return false;
    } else {
        // Add bookmark
        $stmt = $pdo->prepare("INSERT INTO bookmarks (post_id, user_id) VALUES (:post_id, :user_id)");
        $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        return true;
    }
}

function app_is_post_bookmarked($post_id, $user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch() !== false;
}

function app_get_user_bookmarks($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT p.*, c.name as category_name, b.created_at as bookmarked_at
                          FROM bookmarks b
                          JOIN posts p ON b.post_id = p.id
                          LEFT JOIN categories c ON p.category_id = c.id
                          WHERE b.user_id = :user_id AND p.status = 'published'
                          ORDER BY b.created_at DESC");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
// Utility functions
function app_time_elapsed_string($datetime, bool $full = false): string {
    if (empty($datetime)) {
        return 'just now';
    }

    // Allow timestamps as well as date strings
    if (is_numeric($datetime)) {
        $datetime = '@' . $datetime; // convert timestamp to DateTime-compatible format
    }

    try {
        $now = new DateTime();
        $ago = new DateTime($datetime);
    } catch (Exception $e) {
        return 'just now';
    }

    $diff = $now->diff($ago);

    // Calculate weeks
    $weeks = floor($diff->d / 7);
    $days = $diff->d % 7;

    $units = [
        'y' => ['value' => $diff->y, 'label' => 'year'],
        'm' => ['value' => $diff->m, 'label' => 'month'],
        'w' => ['value' => $weeks, 'label' => 'week'],
        'd' => ['value' => $days, 'label' => 'day'],
        'h' => ['value' => $diff->h, 'label' => 'hour'],
        'i' => ['value' => $diff->i, 'label' => 'minute'],
        's' => ['value' => $diff->s, 'label' => 'second'],
    ];

    $parts = [];
    foreach ($units as $u) {
        if ($u['value'] > 0) {
            $parts[] = $u['value'] . ' ' . $u['label'] . ($u['value'] > 1 ? 's' : '');
        }
    }

    if (!$full) {
        $parts = array_slice($parts, 0, 1);
    }

    return $parts ? implode(', ', $parts) . ' ago' : 'just now';
}



function app_truncate_text($text, $length = 150) {
    if (strlen($text) <= $length) {
        return $text;
    }
    
    return substr($text, 0, strrpos(substr($text, 0, $length), ' ')) . '...';
}
?>