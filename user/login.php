<?php
/**
 * Page: user/login.php
 * ====================
 * Secure login handler with:
 *  - Verified user check
 *  - Brute-force attempt limiting
 *  - Remember-me token (hashed + stored)
 *  - Last login tracking
 */

require_once '../data/Functions.php';
app_start_session();
global $pdo;

// Redirect if already logged in
if (app_is_user_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$success = '';

// Session success message (from register/verify)
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

// Max failed login attempts before lockout
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES', 15);

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username_email = trim($_POST['username_email'] ?? '');
    $password       = $_POST['password'] ?? '';
    $remember_me    = isset($_POST['remember_me']);

    if ($username_email === '') $errors[] = 'Username or email is required.';
    if ($password === '') $errors[] = 'Password is required.';

    if (empty($errors)) {
        // Lookup user
        $stmt = $pdo->prepare("
            SELECT id, username, email, password, is_verified,
                   login_attempts, last_login_attempt_at
            FROM users 
            WHERE (username = :ue OR email = :ue) 
            LIMIT 1
        ");
        $stmt->execute([':ue' => $username_email]);
        $user = $stmt->fetch();

        if ($user) {
            // Check lockout
            if ($user['login_attempts'] >= MAX_LOGIN_ATTEMPTS && 
                strtotime($user['last_login_attempt_at'] ?? '1970-01-01') > strtotime('-' . LOCKOUT_MINUTES . ' minutes')) {
                $errors[] = "Account locked due to too many failed attempts. Try again in " . LOCKOUT_MINUTES . " minutes.";
            } elseif (!$user['is_verified']) {
                $errors[] = 'Please verify your email before logging in.';
            } elseif (app_verify_password($password, $user['password'])) {
                // ✅ Success
                app_login_user($user['id']);

                // Reset failed attempts
                $pdo->prepare("UPDATE users SET login_attempts = 0, last_login_at = NOW() WHERE id = :id")
                    ->execute([':id' => $user['id']]);

                // Remember-me token
                if ($remember_me) {
                    $token = app_generate_token(32);
                    $token_hash = password_hash($token, PASSWORD_DEFAULT);

                    $pdo->prepare("UPDATE users SET remember_token = :t WHERE id = :id")
                        ->execute([':t' => $token_hash, ':id' => $user['id']]);

                    setcookie(
                        'remember_token',
                        $user['id'] . ':' . $token,
                        time() + (30 * 24 * 60 * 60),
                        '/',
                        '',
                        isset($_SERVER['HTTPS']),
                        true
                    );
                }

                $redirect = $_SESSION['redirect_after_login'] ?? 'dashboard.php';
                unset($_SESSION['redirect_after_login']);
                header("Location: $redirect");
                exit;
            } else {
                // ❌ Wrong password
                $pdo->prepare("
                    UPDATE users SET login_attempts = login_attempts + 1, last_login_attempt_at = NOW()
                    WHERE id = :id
                ")->execute([':id' => $user['id']]);

                $errors[] = 'Invalid username/email or password.';
            }
        } else {
            $errors[] = 'Invalid username/email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - NextGen DevOps</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>

<body class="auth-page">

    <div class="auth-container">
        <div class="text-center mb-4">
            <a href="../index.php" class="brand-link fs-4 fw-bold text-gradient">
                <i class="bi bi-journal-bookmark"></i> NextGen DevOps
            </a>
        </div>

        <div class="auth-card">
            <div class="auth-header text-center mb-4">
                <h2><i class="bi bi-box-arrow-in-right"></i> Welcome Back</h2>
                <p class="small text-muted">Login to continue</p>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger fade show" role="alert" aria-live="polite">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success fade show" role="alert" aria-live="polite">
                <?= htmlspecialchars($success) ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="auth-form needs-validation" novalidate>
                <div class="mb-3">
                    <label for="username_email" class="form-label">Username or Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" id="username_email" name="username_email" class="form-control"
                            placeholder="Enter your username or email"
                            value="<?= htmlspecialchars($_POST['username_email'] ?? '') ?>" required autofocus>
                        <div class="invalid-feedback">Please enter your username or email.</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" id="password" name="password" class="form-control"
                            placeholder="Enter your password" required>
                        <button type="button" class="btn btn-outline-secondary" id="passwordToggleBtn"
                            aria-pressed="false">
                            <i id="passwordIcon" class="bi bi-eye"></i>
                        </button>
                        <div class="invalid-feedback">Please provide your password.</div>
                    </div>
                </div>

                <div class="mb-3 d-flex justify-content-between align-items-center">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me"
                            <?= isset($_POST['remember_me']) ? 'checked' : '' ?>>
                        <label for="remember_me" class="form-check-label">Remember me</label>
                    </div>
                    <a href="forgot-password.php" class="link-sm">Forgot Password?</a>
                </div>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary btn-lg" id="loginBtn">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                    </button>
                </div>

                <div class="text-center">
                    <p class="small">Don’t have an account? <a href="register.php">Create Account</a></p>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // Password toggle
        const toggleBtn = document.getElementById('passwordToggleBtn');
        const passwordInput = document.getElementById('password');
        const icon = document.getElementById('passwordIcon');
        if (toggleBtn && passwordInput) {
            toggleBtn.addEventListener('click', () => {
                const isHidden = passwordInput.type === 'password';
                passwordInput.type = isHidden ? 'text' : 'password';
                icon.className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
                toggleBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
            });
        }

        // Autofocus password if username filled
        const usernameInput = document.getElementById('username_email');
        if (usernameInput?.value.trim() && passwordInput) passwordInput.focus();

        // Auto-dismiss alerts after 5s
        document.querySelectorAll('.alert').forEach(alertEl => {
            setTimeout(() => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
                bsAlert.close();
            }, 5000);
        });

        // Disable login button on submit
        const loginForm = document.querySelector('.auth-form');
        const loginBtn = document.getElementById('loginBtn');
        if (loginForm && loginBtn) {
            loginForm.addEventListener('submit', () => {
                loginBtn.disabled = true;
                loginBtn.innerHTML =
                    '<span class="spinner-border spinner-border-sm"></span> Signing in...';
            });
        }
    });
    </script>
</body>

</html>