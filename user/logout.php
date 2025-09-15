<?php
// user/logout.php
// User logout functionality

require_once '../data/Functions.php';
app_start_session();

// Clear all session data
app_logout();

// Clear remember me cookie if it exists
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

// Redirect to home page with success message
$_SESSION['success'] = 'You have been successfully logged out.';
header('Location: ../index.php');
exit;
?>