<?php
// ========================================
// Page: user/register.php
// Purpose: Handles user registration + OTP
// ========================================

require_once '../data/Functions.php';
app_start_session();

// Redirect if already logged in
if (app_is_user_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$success = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username         = strtolower(app_sanitize($_POST['username'] ?? '')); // normalize
    $email            = strtolower(app_sanitize($_POST['email'] ?? ''));
    $full_name        = app_sanitize($_POST['full_name'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // === VALIDATION ===
    if (!$username || !preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $errors[] = 'Username must be 3–20 characters (letters, numbers, underscores only).';
    }
    if (!$email || !app_validate_email($email)) {
        $errors[] = 'A valid email is required.';
    }
    if (!$full_name || strlen($full_name) < 3) {
        $errors[] = 'Full name must be at least 3 characters.';
    }
    if (!$password || strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif (
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        $errors[] = 'Password must include upper, lower, number, and special character.';
    }
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    // If valid so far → check DB
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1");
        $stmt->execute([':u' => $username, ':e' => $email]);

        if ($stmt->fetch()) {
            $errors[] = 'Username or email already exists.';
        } else {
            try {
                // Generate OTP
                $otp          = app_generate_otp();
                $otp_hash     = password_hash($otp, PASSWORD_DEFAULT);
                $otp_expires  = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                $hashed_pass  = app_hash_password($password);

                // Insert user
                $stmt = $pdo->prepare("
                    INSERT INTO users 
                        (username, email, full_name, password, 
                         otp_hash, otp_expires_at, 
                         otp_resend_count, otp_verify_attempts, otp_last_sent_at, created_at)
                    VALUES 
                        (:username, :email, :full_name, :password, 
                         :otp_hash, :otp_expires, 
                         0, 0, NOW(), NOW())
                ");
                $ok = $stmt->execute([
                    ':username'   => $username,
                    ':email'      => $email,
                    ':full_name'  => $full_name,
                    ':password'   => $hashed_pass,
                    ':otp_hash'   => $otp_hash,
                    ':otp_expires'=> $otp_expires
                ]);

                if ($ok) {
                    // Email with OTP
                    $subject = 'Your Verification Code - NextGen Devops Team';
                $message = "
<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'>
  <title>Email Verification</title>
</head>
<body style='margin:0;padding:20px;font-family:Arial,Helvetica,sans-serif;background:#f9fafb;color:#333;'>
  <table width='100%' border='0' cellspacing='0' cellpadding='0' style='max-width:520px;margin:auto;background:#ffffff;padding:30px;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.05);'>
    <tr>
      <td style='text-align:center;'>
        <h2 style='margin:0 0 10px;font-size:20px;color:#222;'>Verify Your Email</h2>
        <p style='margin:0 0 20px;font-size:15px;color:#555;'>
          Hi <strong>" . htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES, 'UTF-8') . "</strong>, use the code below to verify your email:
        </p>
        <div style='font-size:28px;font-weight:bold;letter-spacing:4px;color:#fc6075;padding:12px 20px;border:2px dashed #fc6075;display:inline-block;border-radius:6px;margin-bottom:20px;'>
          {$otp}
        </div>
        <p style='margin:0 0 10px;font-size:14px;color:#555;'>This code will expire in <strong>10 minutes</strong>.</p>
        <p style='margin:0;font-size:13px;color:#888;'>If you didn’t request this, you can ignore this email.</p>
        <hr style='border:none;border-top:1px solid #eee;margin:30px 0;'>
        <p style='margin:0;font-size:12px;color:#aaa;'>Best regards,<br>NextGen Devops Team</p>
      </td>
    </tr>
  </table>
</body>
</html>";

                    if (app_send_email($email, $subject, $message)) {
                        $_SESSION['registration_email'] = $email;
                        $_SESSION['success'] = 'Registration successful! Check your email for OTP verification.';
                        header('Location: verify-email.php');
                        exit;
                    } else {
                        $errors[] = 'Failed to send verification email. Please contact support.';
                    }
                } else {
                    $errors[] = 'Registration failed. Please try again later.';
                }
            } catch (Exception $e) {
                error_log("Registration error: " . $e->getMessage());
                $errors[] = 'Unexpected error occurred. Please try again later.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Register - NextGen Devops</title>

    <!-- Bootstrap + Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />
    <!-- Custom page styles (kept external) -->
    <link href="../assets/css/style.css" rel="stylesheet">
    <!-- Inline styles to match screenshot look -->
    <style>
    html,
    body {
        height: 100%;
    }

    body {
        margin: 0;
        min-height: 100%;
        background:
            radial-gradient(1200px 600px at 10% 20%, #ff668c08, transparent 8%),
            radial-gradient(1000px 400px at 90% 80%, #ffb86c05, transparent 8%),
            var(--bg);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
        color: #e9e9e9;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 20px;
    }

    .auth-container {
        width: 100%;
        max-width: 420px;
        /* match screenshot narrow card */
        margin: auto;
    }

    .brand-link {
        display: inline-flex;
        gap: .5rem;
        align-items: center;
        color: transparent;
        background: linear-gradient(90deg, var(--accent-start), var(--accent-end));
        -webkit-background-clip: text;
        background-clip: text;
        text-decoration: none;
    }


    /* override input-group to keep rounded corners when button exists */
    .input-group .btn {
        border-radius: 0 8px 8px 0;
        border: 1px solid rgba(255, 255, 255, 0.04);
        background: transparent;
        color: #cfd8dc;
    }



    .auth-header h2 {
        font-weight: 700;
        background: linear-gradient(90deg, var(--accent-start), var(--accent-end));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .auth-header p {
        margin: 6px 0 0;
        color: rgba(255, 255, 255, 0.55);
        font-size: .95rem;
    }

    /* input styling */
    .form-control {
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.01), rgba(255, 255, 255, 0.00));
        border: 1px solid rgba(255, 255, 255, 0.06);
        color: #e8e8e8;
        height: 48px;
        border-radius: 8px;
        box-shadow: none;
    }

    .input-group-text {
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0.00));
        border: 1px solid rgba(255, 255, 255, 0.04);
        color: #cfd8dc;
        border-radius: 8px 0 0 8px;
        min-width: 46px;
        justify-content: center;
    }

    /* Links */
    .link-sm {
        color: #ff6ec4;
        font-size: 0.9rem;
        transition: 0.3s;
    }

    .link-sm:hover {
        color: #f7971e;
        text-decoration: underline;
    }

    /* Strength bar states */
    #strengthBar {
        width: 0%;
        background: transparent;
        height: 100%;
        transition: width .35s ease;
        border-radius: 6px;
    }

    #strengthBar.strength-weak {
        background: #fc6075;
    }

    #strengthBar.strength-medium {
        background: #ff9b44;
    }

    #strengthBar.strength-strong {
        background: #27af79ff;
    }

    /* mobile tweaks */
    @media (max-width:576px) {
        body {
            padding: 18px;
        }

        .auth-card {
            padding: 20px;
        }
    }
    </style>
</head>

<body class="auth-page">

    <div class="auth-container">
        <!-- BRAND -->
        <div class="text-center mb-3">
            <a href="../index.php" class="brand-link fs-4 fw-bold">
                <i class="bi bi-journal-bookmark" aria-hidden="true"></i>
                <span style="margin-left:.25rem">NextGen Devops</span>
            </a>
        </div>

        <div class="auth-card">
            <!-- Header -->
            <div class="auth-header text-center mb-3">
                <h2><i class="bi bi-person-plus"></i> Create Account</h2>
                <p>Join our community and start Reading blogging</p>
            </div>

            <!-- Alerts -->
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger show" id="alertBox">
                <ul class="mb-0">
                    <?php foreach($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success show" id="alertBox">
                <?= htmlspecialchars($success) ?>
            </div>
            <?php endif; ?>

            <!-- Registration form -->
            <form method="POST" class="register-form needs-validation" novalidate aria-label="Create account form">
                <!-- Username -->
                <div class="mb-3">
                    <label for="username" class="form-label"><i class="bi bi-person" aria-hidden="true"></i>
                        Username</label>
                    <div class="input-group">
                        <span class="input-group-text" id="username-addon" aria-hidden="true"><i
                                class="bi bi-person"></i></span>
                        <input type="text" id="username" name="username" class="form-control"
                            placeholder="Choose a username" aria-describedby="username-addon"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                    </div>
                    <div class="invalid-feedback">Please enter a username (letters, numbers or underscore).</div>
                </div>

                <!-- Email -->
                <div class="mb-3">
                    <label for="email" class="form-label"><i class="bi bi-envelope" aria-hidden="true"></i>
                        Email</label>
                    <div class="input-group">
                        <span class="input-group-text" id="email-addon" aria-hidden="true"><i
                                class="bi bi-envelope"></i></span>
                        <input type="email" id="email" name="email" class="form-control" placeholder="you@example.com"
                            aria-describedby="email-addon" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            required>
                    </div>
                    <div class="invalid-feedback">Please enter a valid email address.</div>
                </div>

                <!-- Full name -->
                <div class="mb-3">
                    <label for="full_name" class="form-label"><i class="bi bi-person-badge" aria-hidden="true"></i> Full
                        Name</label>
                    <div class="input-group">
                        <span class="input-group-text" id="fullname-addon" aria-hidden="true"><i
                                class="bi bi-person-badge"></i></span>
                        <input type="text" id="full_name" name="full_name" class="form-control"
                            placeholder="Your full name" aria-describedby="fullname-addon"
                            value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                    </div>
                    <div class="invalid-feedback">Please enter your full name.</div>
                </div>

                <!-- Password -->
                <div class="mb-3">
                    <label for="password" class="form-label"><i class="bi bi-lock" aria-hidden="true"></i>
                        Password</label>
                    <div class="input-group">
                        <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
                        <input type="password" id="password" name="password" class="form-control"
                            placeholder="Create a password" aria-describedby="passwordHelp" required>
                        <button type="button" class="btn btn-outline-secondary" id="passwordToggleBtn"
                            aria-label="Show or hide password" aria-pressed="false" onclick="togglePassword()">
                            <i id="passwordIcon" class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="mt-2" id="passwordHelp" aria-live="polite">
                        <div class="password-strength"
                            style="height:8px; background: rgba(255,255,255,0.03); border-radius:6px; overflow:hidden;">
                            <div id="strengthBar" style="height:100%; width:0%; transition:width .35s;"></div>
                        </div>
                        <small id="strengthText" class="form-text text-muted">Minimum 6 characters</small>
                    </div>

                    <div class="invalid-feedback">Please provide a password (minimum 6 characters).</div>
                </div>

                <!-- Confirm password -->
                <div class="mb-3">
                    <label for="confirm_password" class="form-label"><i class="bi bi-lock-fill" aria-hidden="true"></i>
                        Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                            placeholder="Repeat your password" required>
                    </div>
                    <small id="matchText" class="form-text"></small>
                    <div class="invalid-feedback">Please confirm your password; both entries must match.</div>
                </div>

                <!-- CTA -->
                <div class="d-grid mb-2">
                    <button type="submit" class="btn btn-primary btn-lg" aria-label="Create account">
                        <i class="bi bi-person-plus me-2" aria-hidden="true"></i> Create Account
                    </button>
                </div>
            </form>

            <!-- Links -->
            <div class="text-center mt-2">
                <p style="color:rgba(255,255,255,0.6)">Already have an account? <a href="login.php" class="link-sm">Sign
                        in</a></p>
                <p class="mt-1"><a href="../index.php" class="link-sm"><i class="bi bi-arrow-left"></i> Back to Home</a>
                </p>
            </div>
        </div>
    </div>

    <!-- JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // === Auto-hide alerts after 3 seconds ===
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(a => a.remove());
    }, 3000);

    // Elements
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    const matchText = document.getElementById('matchText');

    // Password strength calculation + UI
    password.addEventListener('input', () => {
        const val = password.value;
        let score = 0;

        if (val.length >= 8) score++; // length
        if (/[a-z]/.test(val) && /[A-Z]/.test(val)) score++; // mixed case
        if (/\d/.test(val)) score++; // number
        if (/[^A-Za-z0-9]/.test(val)) score++; // special char

        // Visual width proportional to score (0-4)
        const pct = Math.min(100, (score / 4) * 100);
        strengthBar.style.width = pct + '%';

        // reset classes
        strengthBar.classList.remove('strength-weak', 'strength-medium', 'strength-strong');

        if (!val) {
            strengthText.textContent = 'Minimum 6 characters';
            strengthText.className = 'form-text text-muted';
            strengthBar.style.width = '0%';
        } else if (score <= 1) {
            strengthBar.classList.add('strength-weak');
            strengthText.textContent = 'Weak password';
            strengthText.className = 'text-danger';
        } else if (score === 2) {
            strengthBar.classList.add('strength-medium');
            strengthText.textContent = 'Medium password';
            strengthText.className = 'text-warning';
        } else {
            strengthBar.classList.add('strength-strong');
            strengthText.textContent = 'Strong password';
            strengthText.className = 'text-success';
        }

        checkMatch();
    });

    // Confirm password match
    confirmPassword.addEventListener('input', checkMatch);

    function checkMatch() {
        if (!confirmPassword.value) {
            matchText.textContent = '';
            matchText.className = 'form-text';
        } else if (password.value === confirmPassword.value) {
            matchText.textContent = 'Passwords match';
            matchText.className = 'text-success';
        } else {
            matchText.textContent = 'Passwords do not match';
            matchText.className = 'text-danger';
        }
    }

    // Password visibility toggle
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const passwordIcon = document.getElementById('passwordIcon');
        if (!passwordInput) return;
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            passwordIcon.className = 'bi bi-eye-slash';
        } else {
            passwordInput.type = 'password';
            passwordIcon.className = 'bi bi-eye';
        }
    }
    </script>
</body>

</html>