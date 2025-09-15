<?php
// ========================================
// Page: forgot-password.php
// Purpose: Secure password reset flow
// ========================================

require_once '../data/Functions.php';
app_start_session();
global $pdo;

// Redirect logged-in users
if (app_is_user_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$success = '';
$valid_token = false;
$token = trim((string)($_GET['token'] ?? ''));

// -------------------------------
// Config
// -------------------------------
const RESET_TTL_SECONDS = 3600;   // 1 hour
const RESET_RATE_LIMIT  = 3;      // Max 3 reset emails/hour

// -------------------------------
// Helpers
// -------------------------------
function make_reset_token(): array {
    $raw  = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    return [$raw, $hash];
}

function fetch_user_by_token(string $rawToken) {
    global $pdo;
    if ($rawToken === '') return null;
    $hash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare("
        SELECT * FROM users 
        WHERE reset_token = :hash 
          AND reset_expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([':hash' => $hash]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetch_user_by_email(string $email) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// -------------------------------
// Validate token if present
// -------------------------------
if ($token !== '') {
    $user = fetch_user_by_token($token);
    if ($user) {
        $valid_token = true;
        session_regenerate_id(true); // prevent fixation
        $_SESSION['reset_user_id'] = (int)$user['id'];
    } else {
        $errors[] = 'Invalid or expired reset link. Please request again.';
        unset($_SESSION['reset_user_id']);
    }
}

// -------------------------------
// Handle POST
// -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Step 1: Request reset link
    if (isset($_POST['send_reset'])) {
        $email = trim($_POST['email'] ?? '');
        if ($email === '' || !app_validate_email($email)) {
            $errors[] = 'Please enter a valid email.';
        } else {
            $user = fetch_user_by_email($email);
            if ($user && (int)$user['is_verified'] === 1) {
                // Rate limit check
                $tooMany = false;
                if (!empty($user['reset_last_sent_at'])) {
                    $lastTs = strtotime($user['reset_last_sent_at']);
                    $count  = (int)$user['reset_request_count'];
                    if ($lastTs && (time() - $lastTs) < 3600 && $count >= RESET_RATE_LIMIT) {
                        $tooMany = true;
                    }
                }

                if (!$tooMany) {
                    [$rawToken, $hash] = make_reset_token();
                    $expires = date('Y-m-d H:i:s', time() + RESET_TTL_SECONDS);

                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET reset_token = :hash,
                            reset_expires_at = :exp,
                            reset_last_sent_at = NOW(),
                            reset_request_count = CASE
                                WHEN reset_last_sent_at IS NULL OR TIMESTAMPDIFF(SECOND, reset_last_sent_at, NOW()) > 3600
                                THEN 1 ELSE reset_request_count + 1 END
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':hash' => $hash,
                        ':exp'  => $expires,
                        ':id'   => (int)$user['id'],
                    ]);

                    // Build link
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $link   = "{$scheme}://{$_SERVER['HTTP_HOST']}/user/forgot-password.php?token=" . urlencode($rawToken);

                    $subject = 'Password Reset Request';
                    $name = htmlspecialchars($user['full_name'] ?: $user['username']);
                    $message = "
<!DOCTYPE html>
<html><body>
<p>Hi <strong>{$name}</strong>,</p>
<p>You requested a password reset. Click below to set a new one (expires in 1 hour):</p>
<p><a href='{$link}' style='background:#4a90e2;color:#fff;padding:10px 20px;text-decoration:none;border-radius:5px;'>Reset Password</a></p>
<p>If you didn’t request this, you can ignore it.</p>
<p>Thanks,<br>NextGen DevOps Team</p>
</body></html>
";
                    app_send_email($email, $subject, $message);
                }
            }
            $success = 'If that email exists, a reset link has been sent.';
        }
    }

    // Step 2: Reset password
    if (isset($_POST['reset_password'])) {
        $newPass = trim($_POST['new_password'] ?? '');
        $confirm = trim($_POST['confirm_password'] ?? '');
        $userId  = $_SESSION['reset_user_id'] ?? null;

        if (!$userId) {
            $errors[] = 'Session expired. Please start again.';
        }
        if ($newPass === '') {
            $errors[] = 'New password is required.';
        } elseif (strlen($newPass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $newPass) ||
                  !preg_match('/[a-z]/', $newPass) ||
                  !preg_match('/[0-9]/', $newPass) ||
                  !preg_match('/[^A-Za-z0-9]/', $newPass)) {
            $errors[] = 'Password must include uppercase, lowercase, number, and special character.';
        } elseif ($newPass !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $hashed = app_hash_password($newPass);
            $stmt = $pdo->prepare("
                UPDATE users
                SET password = :pwd, reset_token = NULL, reset_expires_at = NULL,
                    reset_request_count = 0, reset_last_sent_at = NULL
                WHERE id = :id
            ");
            if ($stmt->execute([':pwd' => $hashed, ':id' => $userId])) {
                unset($_SESSION['reset_user_id']);
                $_SESSION['success'] = 'Password reset successfully! You can log in.';
                header('Location: login.php');
                exit;
            } else {
                $errors[] = 'Password update failed. Please retry later.';
            }
        }
    }
}

// Step indicator
$show_reset_form = (!empty($_SESSION['reset_user_id']) || $valid_token);
$step = $show_reset_form ? 2 : 1;
?>


<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Password Reset - NextGen DevOps</title>

  <!-- Bootstrap + Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

  <!-- Custom styles -->
  <link href="../assets/css/style.css" rel="stylesheet">
</head>

<body class="auth-page">
  <div class="auth-container">

    <!-- Logo -->
    <div class="text-center mb-4">
      <a href="../index.php" class="brand-link fs-4 fw-bold text-gradient">
        <i class="bi bi-journal-bookmark"></i> NextGen DevOps
      </a>
    </div>

    <div class="auth-card">

      <!-- Header -->
      <div class="auth-header text-center mb-4">
        <h2><i class="bi bi-shield-lock"></i> Password Reset</h2>
        <p>Securely reset your account password</p>
      </div>

      <!-- Alerts -->
      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger show" id="alertBox" aria-live="polite">
          <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
              <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success show" id="alertBox" aria-live="polite">
          <?= htmlspecialchars($success) ?>
        </div>
      <?php endif; ?>

      <!-- ========================
           Step 2: Reset Password
      ========================== -->
      <?php if (!empty($_SESSION['reset_user_id']) || $valid_token): ?>
        <div class="step-indicator mb-3 text-center">
          <span class="badge rounded-pill bg-success me-2">1</span>
          <span class="badge rounded-pill bg-primary">2</span>
        </div>

        <form method="post" novalidate>
          <!-- New Password -->
          <div class="mb-3 position-relative">
            <label class="form-label"><i class="bi bi-lock"></i> New Password</label>
            <input name="new_password" id="new_password" type="password"
                   class="form-control" required minlength="8" autocomplete="new-password"
                   placeholder="Enter new password">
            <div class="password-strength">
              <div id="strengthBar" class="password-strength-bar"></div>
            </div>
            <small id="strengthText" class="form-text">
              Minimum 8 chars, must include upper/lower/number/special
            </small>
          </div>

          <!-- Confirm Password -->
          <div class="mb-3 position-relative">
            <label class="form-label"><i class="bi bi-lock-fill"></i> Confirm Password</label>
            <input name="confirm_password" id="confirm_password" type="password"
                   class="form-control" required autocomplete="new-password"
                   placeholder="Confirm new password">
            <small id="matchText" class="form-text"></small>
          </div>

          <button type="submit" name="reset_password" class="btn btn-gradient w-100">
            Reset Password
          </button>
        </form>

      <?php else: ?>
      <!-- ========================
           Step 1: Request Reset
      ========================== -->
        <div class="step-indicator mb-3 text-center">
          <span class="badge rounded-pill bg-primary me-2">1</span>
          <span class="badge rounded-pill bg-secondary">2</span>
        </div>

        <form method="post" novalidate>
          <div class="mb-3">
            <label class="form-label"><i class="bi bi-envelope"></i> Registered Email</label>
            <input type="email" name="email" class="form-control" autocomplete="email"
                   placeholder="you@example.com" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>

          <button type="submit" name="send_reset" class="btn btn-gradient w-100">
            Send Reset Link
          </button>

          <div class="text-center small-muted mt-2">
            Link expires in 1 hour • No email if account doesn’t exist
          </div>
        </form>
      <?php endif; ?>

      <hr class="divider">
      <div class="text-center">
        <a href="login.php" class="link-sm">
          <i class="bi bi-arrow-left"></i> Back to login
        </a>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

  <script>
  // Password Strength + Match Validation
  document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('new_password');
    const confirmInput = document.getElementById('confirm_password');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    const matchText = document.getElementById('matchText');
    const alertBox = document.getElementById('alertBox');

    // Optional: auto-hide alerts
    if (alertBox) {
      setTimeout(() => alertBox.classList.remove('show'), 6000);
    }

    function checkStrength(pwd) {
      let score = 0;
      if (pwd.length >= 8) score++;
      if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) score++;
      if (/\d/.test(pwd)) score++;
      if (/[^A-Za-z0-9]/.test(pwd)) score++;
      return Math.min(score, 3);
    }

    if (passwordInput) {
      passwordInput.addEventListener('input', function() {
        const s = checkStrength(this.value);
        if (!this.value) {
          strengthBar.style.width = '0';
          strengthText.textContent = 'Minimum 8 chars, must include upper/lower/number/special';
          strengthText.className = 'form-text';
        } else if (s === 1) {
          strengthBar.style.width = '33%';
          strengthBar.style.background = '#dc3545';
          strengthText.textContent = 'Weak password';
          strengthText.className = 'text-danger';
        } else if (s === 2) {
          strengthBar.style.width = '66%';
          strengthBar.style.background = '#ffc107';
          strengthText.textContent = 'Medium password';
          strengthText.className = 'text-warning';
        } else {
          strengthBar.style.width = '100%';
          strengthBar.style.background = '#28a745';
          strengthText.textContent = 'Strong password';
          strengthText.className = 'text-success';
        }
      });
    }

    if (confirmInput) {
      confirmInput.addEventListener('input', function() {
        if (!passwordInput) return;
        if (!this.value) {
          matchText.textContent = '';
          matchText.className = 'form-text';
        } else if (this.value === passwordInput.value) {
          matchText.textContent = 'Passwords match';
          matchText.className = 'text-success';
        } else {
          matchText.textContent = 'Passwords do not match';
          matchText.className = 'text-danger';
        }
      });
    }
  });
  </script>
</body>
</html>
