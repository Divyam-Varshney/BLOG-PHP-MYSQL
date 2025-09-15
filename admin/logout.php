<?php
// admin/logout.php
// Admin logout functionality

require_once '../data/Functions.php';
app_start_session();

// Clear all session data
app_logout();

// Clear remember me cookie if it exists
if (isset($_COOKIE['admin_remember_token'])) {
    setcookie('admin_remember_token', '', time() - 3600, '/', '', false, true);
}

// Redirect to admin login with success message
$_SESSION['success'] = 'You have been successfully logged out from admin panel.';
header('Location: login.php');
exit;
?>