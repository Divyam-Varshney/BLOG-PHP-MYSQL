<?php
// admin/login.php

require_once '../db_conn.php';
require_once '../data/Functions.php';
app_start_session();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = app_sanitize(trim($_POST['username']));
    $password = $_POST['password'];
    $remember_me = isset($_POST['remember_me']);

    if (!$username) $errors[] = 'Username is required';
    if (!$password) $errors[] = 'Password is required';

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = :username");
        $stmt->bindValue(':username', $username);
        $stmt->execute();
        $admin = $stmt->fetch();

        if ($admin && app_verify_password($password, $admin['password'])) {
            app_login_admin($admin['id']);

            if ($remember_me) {
                $token = app_generate_token();
                $stmt = $pdo->prepare("UPDATE admins SET remember_token = :token WHERE id = :id");
                $stmt->bindValue(':token', $token);
                $stmt->bindValue(':id', $admin['id']);
                $stmt->execute();

                setcookie('admin_remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', false, true);
            }

            header('Location: dashboard.php');
            exit;
        } else {
            $errors[] = 'Invalid username or password';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Blog</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/admin.css" rel="stylesheet">
    <style>
    body {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        background: #121212;
    }

    .login-card {
        max-width: 420px;
        width: 100%;
        padding: 2rem;
        background: #1e1e1e;
        border-radius: 12px;
        box-shadow: 0 6px 20px rgba(0, 0, 0, .5);
    }

    .login-header {
        text-align: center;
        margin-bottom: 1.5rem;
    }

    .login-header .bi {
        font-size: 3rem;
        color: #ff9b44;
    }

    .security-note {
        text-align: center;
        font-size: .85rem;
        color: #aaa;
        margin-top: 1rem;
    }

    .back-link {
        margin-top: 1rem;
        text-align: center;
    }
    </style>
</head>

<body>
    <div class="login-card">
        <div class="login-header">
            <i class="bi bi-shield-lock"></i>
            <h2 class="mt-2">Admin Login</h2>
            <p class="text-muted">Access the administration panel securely</p>
        </div>

        <!-- Error messages -->
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Success message -->
        <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- Login form -->
        <form method="POST" id="adminLoginForm" novalidate>
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="Enter username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label d-flex justify-content-between">
                    <span>Password</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="togglePassword()"
                        aria-label="Show/hide password">
                        <i class="bi bi-eye" id="passwordIcon"></i>
                    </button>
                </label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Enter password"
                    required>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me">
                <label class="form-check-label" for="remember_me">Keep me signed in</label>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right"></i> Login
            </button>
        </form>

        <div class="security-note">
            <i class="bi bi-shield-fill-check"></i> Secured with 256-bit encryption
        </div>

        <div class="back-link">
            <a href="../index.php" class="text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to Website
            </a>
        </div>
    </div>

    <script>
    function togglePassword() {
        const input = document.getElementById('password');
        const icon = document.getElementById('passwordIcon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'bi bi-eye';
        }
    }
    </script>
</body>

</html>