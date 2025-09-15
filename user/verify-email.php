<?php
// ==============================================
// Page: user/verify-email.php
// Purpose: Handle email verification via OTP (secure + throttled)
// ==============================================

require_once '../data/Functions.php';
app_start_session();

if (!isset($_SESSION['registration_email'])) {
    header('Location: register.php');
    exit;
}

$email   = $_SESSION['registration_email'];
$errors  = [];
$success = '';

// -------------------------------
// Config (tune to your needs)
// -------------------------------
const OTP_TTL_SECONDS            = 600; // 10 minutes
const RESEND_COOLDOWN_SECONDS    = 60;  // 1 minute cooldown
const MAX_RESENDS_PER_HOUR       = 5;
const MAX_VERIFY_ATTEMPTS_HOURLY = 5;

// Optional: store plaintext OTP for a short migration period (set false to disable)
const STORE_PLAINTEXT_OTP        = false;

// Utility: now in DB format
function now_db(): string {
    return date('Y-m-d H:i:s');
}

// Fetch the unverified user row by email
function fetch_unverified_user(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, is_verified,
               otp_hash, otp, otp_expires_at,
               otp_resend_count, otp_last_sent_at,
               otp_verify_attempts, otp_last_attempt_at
        FROM users
        WHERE email = :email
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['is_verified'] === 1) {
        return null;
    }
    return $row;
}

// Reset a counter if the last timestamp is older than $windowSeconds
function window_reset_if_needed(int $count, ?string $lastAt, int $windowSeconds): int {
    if (empty($lastAt)) return 0;
    $lastTs = strtotime($lastAt);
    if ($lastTs === false) return 0;
    return (time() - $lastTs) > $windowSeconds ? 0 : $count;
}

// Issue and save a fresh OTP (hash + TTL) and update resend counters
function issue_and_store_otp(PDO $pdo, int $userId): array {
    $otp        = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash    = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt  = date('Y-m-d H:i:s', time() + OTP_TTL_SECONDS);
    $now        = now_db();

    // Update resend counters with 1-hour window
    $stmt = $pdo->prepare("
        UPDATE users
        SET otp_hash = :hash,
            otp = :otp_plain,
            otp_expires_at = :exp,
            otp_resend_count = CASE
                WHEN (otp_last_sent_at IS NULL OR TIMESTAMPDIFF(SECOND, otp_last_sent_at, :now) > 3600)
                THEN 1
                ELSE otp_resend_count + 1
            END,
            otp_last_sent_at = :now
        WHERE id = :uid
        LIMIT 1
    ");
    $stmt->execute([
        ':hash'      => $otpHash,
        ':otp_plain' => STORE_PLAINTEXT_OTP ? $otp : null,
        ':exp'       => $expiresAt,
        ':now'       => $now,
        ':uid'       => $userId,
    ]);

    return [$otp, $expiresAt];
}

// Verify an OTP with attempt throttle
function verify_otp(PDO $pdo, array $userRow, string $inputOtp, array &$errors): bool {
    $userId = (int)$userRow['id'];

    // Enforce hourly attempt window
    $attempts = window_reset_if_needed(
        (int)$userRow['otp_verify_attempts'],
        $userRow['otp_last_attempt_at'],
        3600
    );
    if ($attempts >= MAX_VERIFY_ATTEMPTS_HOURLY) {
        $errors[] = 'Too many attempts. Please wait and try again later or resend a new code.';
        return false;
    }

    // Basic checks
    if (empty($userRow['otp_hash']) || empty($userRow['otp_expires_at'])) {
        $errors[] = 'No active code. Please resend a new verification code.';
        // bump attempts counter anyway to prevent probing
        bump_attempts($pdo, $userId, $attempts + 1);
        return false;
    }
    if (strtotime($userRow['otp_expires_at']) < time()) {
        $errors[] = 'The code has expired. Please resend a new verification code.';
        bump_attempts($pdo, $userId, $attempts + 1);
        return false;
    }

    $ok = password_verify($inputOtp, $userRow['otp_hash']);
    if (!$ok) {
        $errors[] = 'Invalid code. Please try again.';
        bump_attempts($pdo, $userId, $attempts + 1);
        return false;
    }

    // Success: verify and clear OTP fields/counters
    $stmt = $pdo->prepare("
        UPDATE users
        SET is_verified = 1,
            otp = NULL,
            otp_hash = NULL,
            otp_expires_at = NULL,
            otp_resend_count = 0,
            otp_last_sent_at = NULL,
            otp_verify_attempts = 0,
            otp_last_attempt_at = NULL,
            updated_at = NOW()
        WHERE id = :uid
        LIMIT 1
    ");
    $stmt->execute([':uid' => $userId]);

    return true;
}

function bump_attempts(PDO $pdo, int $userId, int $newCount): void {
    $stmt = $pdo->prepare("
        UPDATE users
        SET otp_verify_attempts = :cnt,
            otp_last_attempt_at = :now
        WHERE id = :uid
        LIMIT 1
    ");
    $stmt->execute([
        ':cnt' => $newCount,
        ':now' => now_db(),
        ':uid' => $userId
    ]);
}

// ------------------------------------------------
// Handle POST
// ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // === VERIFY ===
    if (isset($_POST['verify_otp'])) {
        $otp = trim($_POST['otp'] ?? '');

        if ($otp === '') {
            $errors[] = 'Please enter the code.';
        } elseif (!ctype_digit($otp) || strlen($otp) !== 6) {
            $errors[] = 'The code must be exactly 6 digits.';
        } else {
            $user = fetch_unverified_user($pdo, $email);
            if (!$user) {
                $errors[] = 'Account not found or already verified.';
            } else {
                if (verify_otp($pdo, $user, $otp, $errors)) {
                    unset($_SESSION['registration_email']);
                    $_SESSION['success'] = 'Email verified successfully! You can now log in.';
                    header('Location: login.php');
                    exit;
                }
            }
        }
    }

    // === RESEND ===
    if (isset($_POST['resend_otp'])) {
        $user = fetch_unverified_user($pdo, $email);
        if (!$user) {
            $errors[] = 'Account not found or already verified.';
        } else {
            // Cooldown check
            $lastSent  = $user['otp_last_sent_at'];
            $resends   = window_reset_if_needed((int)$user['otp_resend_count'], $lastSent, 3600);

            if (!empty($lastSent) && (time() - strtotime($lastSent)) < RESEND_COOLDOWN_SECONDS) {
                $errors[] = 'Please wait a moment before requesting another code.';
            } elseif ($resends >= MAX_RESENDS_PER_HOUR) {
                $errors[] = 'You have reached the hourly resend limit. Please try again later.';
            } else {
                // Issue new OTP
                [$otp, $expiresAt] = issue_and_store_otp($pdo, (int)$user['id']);

                // Build email
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
                    $success = 'A new verification code has been sent to your email.';
                    // Reset the countdown via inline script hook
                    echo "<script>
                        document.addEventListener('DOMContentLoaded', () => {
                            if (typeof resetCountdown === 'function') resetCountdown();
                        });
                    </script>";
                } else {
                    $errors[] = 'Failed to send the verification code. Please try again later.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Verify Email - NextGen Devops</title>

    <!-- Bootstrap CSS + Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />

    <!-- Main site stylesheet -->
    <link href="/assets/css/style.css" rel="stylesheet" />
</head>
<body>
<main class="container py-5" id="verify-email-page">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="card p-4">
                <div class="text-center mb-3">
                    <a href="../index.php" class="text-decoration-none fw-bold" style="color:var(--accent-start);">
                        <i class="bi bi-journal-bookmark"></i> NextGen Devops
                    </a>
                </div>

                <div class="text-center mb-4">
                    <div class="mb-2">
                        <i class="bi bi-envelope-check" style="font-size:2.4rem;color:var(--accent-start)"></i>
                    </div>
                    <h2 class="h4 mb-1">Verify Your Email</h2>
                    <p class="mb-0">We've sent a 6-digit code to</p>
                    <p class="mb-0 fw-semibold" style="color:var(--accent-end);">
                        <?= htmlspecialchars($email) ?>
                    </p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <div class="mb-3 text-center">
                        <label for="otp" class="form-label visually-hidden">Enter Verification Code</label>
                        <div class="d-flex justify-content-center">
                            <input id="otp" name="otp" type="text" class="form-control text-center"
                                   style="max-width:220px; font-size:1.25rem; letter-spacing:0.28em;"
                                   placeholder="000000" maxlength="6" pattern="[0-9]{6}"
                                   autocomplete="one-time-code" inputmode="numeric" required>
                        </div>
                        <small class="d-block mt-2">
                            Code expires in
                            <span id="countdown" style="font-weight:700;color:var(--accent-start)">10:00</span>
                        </small>
                    </div>

                    <button type="submit" name="verify_otp" class="btn btn-primary w-100 mb-2">
                        <i class="bi bi-check-circle me-2"></i> Verify Email
                    </button>
                </form>

                <div class="text-center mt-3">
                    <p class="mb-2">Didn't receive the code?</p>
                    <form method="POST" class="d-inline">
                        <button type="submit" name="resend_otp" class="btn btn-ghost">
                            <i class="bi bi-arrow-clockwise me-1"></i> Resend Code
                        </button>
                    </form>
                </div>

                <hr style="border-color: rgba(255,255,255,0.04);">

                <div class="text-center">
                    <a href="register.php" class="text-decoration-none small">
                        <i class="bi bi-arrow-left"></i> Back to Registration
                    </a>
                </div>

            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Countdown + OTP UX
document.addEventListener('DOMContentLoaded', function() {
    const otpInput = document.getElementById('otp');
    const countdownElement = document.getElementById('countdown');
    let countdownInterval;

    if (otpInput) {
        otpInput.focus();
        otpInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    }

    function startCountdown(durationSeconds = 600) {
        let timeLeft = durationSeconds;

        function update() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;

            if (countdownElement) {
                countdownElement.textContent =
                    `${minutes}:${seconds.toString().padStart(2, '0')}`;

                if (timeLeft <= 0) {
                    countdownElement.textContent = 'Expired';
                    countdownElement.classList.add('text-danger');
                    if (otpInput) otpInput.disabled = true;
                    clearInterval(countdownInterval);
                } else if (timeLeft <= 60) {
                    countdownElement.classList.add('text-danger');
                } else if (timeLeft <= 300) {
                    countdownElement.classList.add('text-warning');
                } else {
                    countdownElement.classList.remove('text-danger', 'text-warning');
                }
            }
            timeLeft--;
        }

        clearInterval(countdownInterval);
        update();
        countdownInterval = setInterval(update, 1000);
    }

    // Called after successful resend (server prints a small script hook)
    window.resetCountdown = function() {
        if (otpInput) {
            otpInput.disabled = false;
            otpInput.value = '';
            otpInput.placeholder = '000000';
            otpInput.focus();
        }
        if (countdownElement) countdownElement.classList.remove('text-danger', 'text-warning');
        startCountdown(600);
    };

    startCountdown(600);
});
</script>
</body>
</html>
