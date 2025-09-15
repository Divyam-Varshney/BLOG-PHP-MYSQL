<?php
// ==========================================
// user/profile.php
// User profile management page:
// - Edit profile information (username, full name)
// - Change password
// - Update profile photo
// ==========================================

// Include core functions and start session
require_once '../data/Functions.php';
app_start_session();

// ------------------------------------------
// Authentication: redirect to login if not logged in
// ------------------------------------------
if (!app_is_user_logged_in()) {
    $_SESSION['redirect_after_login'] = 'profile.php';
    header('Location: login.php');
    exit;
}

// Fetch current logged-in user
$current_user = app_get_current_user();
$errors = [];
$success = '';

// Fallback if user could not be loaded
if (!$current_user) {
    $errors[] = 'Unable to load user data. Please log in again.';
}

// ------------------------------------------
// Safe profile photo defaults
// Prevents undefined variable and basename warnings
// ------------------------------------------
$profile_photo_file = 'default-user.png';
if (!empty($current_user['profile_photo'])) {
    $profile_photo_file = (string)$current_user['profile_photo']; // ensure string
}
$profile_photo_basename = basename($profile_photo_file);
$profile_photo_path = "../uploads/users/" . $profile_photo_basename;

// ------------------------------------------
// Handle form submissions (POST requests)
// ------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ===============================
    // PROFILE INFO UPDATE
    // ===============================
    if (isset($_POST['update_profile'])) {
        $username   = trim($_POST['username'] ?? '');
        $full_name  = trim($_POST['full_name'] ?? '');

        // Validate username
        if ($username === '') {
            $errors[] = 'Username is required';
        } elseif (strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors[] = 'Username can only contain letters, numbers, and underscores';
        } else {
            // Check uniqueness
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username AND id != :user_id");
            $stmt->bindValue(':username', $username);
            $stmt->bindValue(':user_id', $current_user['id'], PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->fetch()) {
                $errors[] = 'Username is already taken';
            }
        }

        // Validate full name
        if ($full_name === '') {
            $errors[] = 'Full name is required';
        }

        // Update profile if valid
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET username = :username, full_name = :full_name, updated_at = NOW() 
                    WHERE id = :user_id
                ");
                $stmt->bindValue(':username', $username);
                $stmt->bindValue(':full_name', $full_name);
                $stmt->bindValue(':user_id', $current_user['id'], PDO::PARAM_INT);

                if ($stmt->execute()) {
                    $success = 'Profile updated successfully.';
                    $current_user = app_get_current_user(); // refresh user data
                } else {
                    $errors[] = 'Failed to update profile. Please try again.';
                }
            } catch (Exception $e) {
                $errors[] = 'Database error while updating profile.';
            }
        }
    }

    // ===============================
    // CHANGE PASSWORD
    // ===============================
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate required fields
        if ($current_password === '') $errors[] = 'Current password is required';
        if ($new_password === '') $errors[] = 'New password is required';
        if ($confirm_password === '') $errors[] = 'Confirm new password is required';

        if (empty($errors)) {
            // Verify current password
            if (!app_verify_password($current_password, $current_user['password'])) {
                $errors[] = 'Current password is incorrect';
            } elseif (strlen($new_password) < 6) {
                $errors[] = 'New password must be at least 6 characters';
            } elseif ($new_password !== $confirm_password) {
                $errors[] = 'New passwords do not match';
            } else {
                // Update password
                try {
                    $hashed = app_hash_password($new_password);
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET password = :password, updated_at = NOW() 
                        WHERE id = :user_id
                    ");
                    $stmt->bindValue(':password', $hashed);
                    $stmt->bindValue(':user_id', $current_user['id'], PDO::PARAM_INT);

                    if ($stmt->execute()) {
                        $success = 'Password changed successfully.';
                        $current_user = app_get_current_user();
                        // Optional: force logout other sessions
                    } else {
                        $errors[] = 'Failed to update password. Please try again.';
                    }
                } catch (Exception $e) {
                    $errors[] = 'Database error while updating password.';
                }
            }
        }
    }

    // ===============================
    // PROFILE PHOTO UPDATE
    // ===============================
    if (isset($_POST['update_photo'])) {
        if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please select a valid image file.';
        } else {
            $file = $_FILES['profile_photo'];
            $maxBytes = 8 * 1024 * 1024; // 2 MB
            $allowed_ext = ['jpg', 'jpeg', 'png'];
            $allowed_mimes = ['image/jpeg', 'image/png'];

            // Validate file size
            if ($file['size'] > $maxBytes) {
                $errors[] = 'Image must be 2MB or smaller.';
            } else {
                // Validate file extension
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext, true)) {
                    $errors[] = 'Only JPG and PNG images are allowed.';
                } else {
                    // Validate MIME type
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name'] ?? '');
                    if (!in_array($mime, $allowed_mimes, true)) {
                        $errors[] = 'Invalid image type. Only JPG and PNG allowed.';
                    }
                }
            }

            // If all checks passed → upload file
            if (empty($errors)) {
                $upload_dir = __DIR__ . '/../uploads/users';
                $filename = app_upload_file($file, $upload_dir, $allowed_ext);

                if ($filename) {
                    // Delete old photo if not default
                    if (!empty($current_user['profile_photo']) && $current_user['profile_photo'] !== 'default-user.png') {
                        $old_photo = $upload_dir . '/' . $current_user['profile_photo'];
                        if (file_exists($old_photo)) @unlink($old_photo);
                    }

                    // Update DB
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE users 
                            SET profile_photo = :photo, updated_at = NOW() 
                            WHERE id = :user_id
                        ");
                        $stmt->bindValue(':photo', $filename);
                        $stmt->bindValue(':user_id', $current_user['id'], PDO::PARAM_INT);

                        if ($stmt->execute()) {
                            $success = 'Profile photo updated successfully.';
                            $current_user = app_get_current_user();

                            // Refresh local vars
                            $profile_photo_file = (string)$filename;
                            $profile_photo_basename = basename($profile_photo_file);
                            $profile_photo_path = "../uploads/users/" . $profile_photo_basename;
                        } else {
                            $errors[] = 'Failed to update profile photo in database.';
                        }
                    } catch (Exception $e) {
                        $errors[] = 'Database error while updating profile photo.';
                    }
                } else {
                    $errors[] = 'Failed to move uploaded file. Ensure webserver has write permissions.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile - NextGen Devops</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Custom User Styles -->
    <link href="../assets/css/user.css" rel="stylesheet">
</head>

<body>
    <!-- =========================
         NAVBAR
    ========================== -->
    <nav class="navbar navbar-expand-lg navbar-dark user-navbar">
        <div class="container">
            <!-- Brand -->
            <a class="navbar-brand fw-bold" href="../index.php">
                <i class="bi bi-journal-bookmark"></i>NextGen Devops
            </a>

            <!-- Mobile Toggle -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Navigation -->
            <div class="collapse navbar-collapse" id="nav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="../index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                </ul>

                <!-- User Dropdown -->
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle user-nav-profile d-flex align-items-center"
                           href="#" data-bs-toggle="dropdown">
                            <!-- User Avatar -->
                            <img src="<?= htmlspecialchars($profile_photo_path) ?>"
                                 width="36" height="36"
                                 class="rounded-circle me-1"
                                 alt="<?= htmlspecialchars($current_user['full_name'] ?: $current_user['username']) ?>">

                            <!-- Username -->
                            <?= htmlspecialchars($current_user['username'] ?? 'User') ?>
                        </a>

                        <!-- Dropdown Menu -->
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="dashboard.php">Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- =========================
         ALERTS (Errors / Success)
    ========================== -->
    <div class="container py-4">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger user-alert" role="alert">
                <ul class="mb-0">
                    <?php foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success user-alert" role="status">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- =========================
                 SIDEBAR
            ========================== -->
            <div class="col-lg-3 mb-4">
                <div class="row">
                    <div class="sidebar p-3 rounded shadow-sm">
                        <h6 class="text-uppercase fw-bold text-muted mb-3">Profile Settings</h6>
                        <nav class="nav flex-column gap-2">
                            <a class="nav-link active" href="profile.php">
                                <i class="bi bi-person-gear"></i> Edit Profile
                            </a>
                            <a class="nav-link" href="dashboard.php">
                                <i class="bi bi-house"></i> Dashboard
                            </a>
                            <a class="nav-link" href="logout.php">
                                <i class="bi bi-box-arrow-right"></i> Logout
                            </a>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- =========================
                 MAIN CONTENT
            ========================== -->
            <div class="col-lg-9">

                <!-- USER STATS CARD -->
                <div class="card user-stats mb-4">
                    <div class="card-body row align-items-center">
                        <!-- Profile Photo -->
                        <div class="col-md-3 text-center">
                            <img id="current-photo"
                                 src="<?= htmlspecialchars($profile_photo_path) ?>"
                                 alt="<?= htmlspecialchars($current_user['full_name'] ?: $current_user['username']) ?>'s profile photo"
                                 class="profile-photo rounded-circle mb-3"
                                 width="120" height="120">
                        </div>

                        <!-- User Details -->
                        <div class="col-md-9">
                            <h2 class="mb-1">
                                <?= htmlspecialchars($current_user['full_name'] ?: $current_user['username']) ?>
                            </h2>
                            <p class="mb-1"><i class="bi bi-at"></i>
                                <?= htmlspecialchars($current_user['username'] ?? '') ?></p>
                            <p class="mb-1"><i class="bi bi-envelope"></i>
                                <?= htmlspecialchars($current_user['email'] ?? '') ?></p>
                            <p class="mb-0"><i class="bi bi-calendar"></i>
                                Member since <?= htmlspecialchars(date('F Y', strtotime($current_user['created_at'] ?? 'now'))) ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- =========================
                     PROFILE PHOTO SECTION
                ========================== -->
                <section id="photo-section" class="mb-5">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-dark text-white d-flex align-items-center">
                            <i class="bi bi-camera me-2"></i>
                            <h5 class="mb-0">Profile Photo</h5>
                        </div>
                        <div class="card-body">
                            <!-- File Upload Form -->
                            <form action="profile.php" method="POST" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label for="profile_photo" class="form-label fw-semibold">
                                        Upload New Photo
                                    </label>
                                    <input type="file" name="profile_photo" id="profile_photo"
                                           class="form-control" accept="image/*"
                                           onchange="previewImage(this)">
                                    <small class="text-muted d-block mt-2">
                                        Allowed formats: JPG, PNG. Max size: 8MB.
                                    </small>
                                </div>
                                <button type="submit" name="update_photo" class="btn btn-primary">
                                    <i class="bi bi-upload"></i> Upload Photo
                                </button>
                            </form>
                        </div>
                    </div>
                </section>

                <!-- =========================
                     PROFILE INFORMATION SECTION
                ========================== -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-person-lines-fill"></i> Profile Information
                    </div>
                    <div class="card-body">
                        <form method="POST" id="profileForm" novalidate>
                            <div class="row">
                                <!-- Username -->
                                <div class="col-md-6 mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control" id="username" name="username"
                                           value="<?= htmlspecialchars($current_user['username'] ?? '') ?>"
                                           required aria-describedby="usernameHelp">
                                    <div id="usernameHelp" class="form-text">
                                        Only letters, numbers, and underscores allowed
                                    </div>
                                </div>

                                <!-- Email (readonly) -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control"
                                           value="<?= htmlspecialchars($current_user['email'] ?? '') ?>"
                                           disabled readonly>
                                    <div class="form-text">Email cannot be changed</div>
                                </div>
                            </div>

                            <!-- Full Name -->
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="full_name" name="full_name"
                                       value="<?= htmlspecialchars($current_user['full_name'] ?? '') ?>" required>
                            </div>

                            <!-- Submit -->
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Update Profile
                            </button>
                        </form>
                    </div>
                </div>

                <!-- =========================
                     CHANGE PASSWORD SECTION
                ========================== -->
                <div class="card" id="password-section">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-dark text-white d-flex align-items-center">
                            <i class="bi bi-shield-lock me-2"></i>
                            <h5 class="mb-0">Change Password</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="passwordForm" novalidate>
                                <!-- Current Password -->
                                <div class="mb-3">
                                    <label for="current_password" class="form-label fw-semibold">Current Password</label>
                                    <input type="password" class="form-control" id="current_password"
                                           name="current_password" required>
                                </div>

                                <!-- New Password -->
                                <div class="mb-3">
                                    <label for="new_password" class="form-label fw-semibold">New Password</label>
                                    <input type="password" class="form-control" id="new_password"
                                           name="new_password" required minlength="6">

                                    <!-- Strength Meter -->
                                    <div class="password-strength mt-2" aria-hidden="true">
                                        <div class="password-strength-bar" id="strengthBar"></div>
                                    </div>
                                    <small id="strengthText" class="form-text text-muted">
                                        Must be at least 6 characters.
                                    </small>
                                </div>

                                <!-- Confirm Password -->
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label fw-semibold">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password"
                                           name="confirm_password" required>
                                    <small id="matchText" class="form-text text-muted"></small>
                                </div>

                                <!-- Warning -->
                                <div class="alert alert-warning small" role="note">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    Changing your password will log you out of all other sessions.
                                </div>

                                <!-- Submit -->
                                <button type="submit" name="change_password" class="btn btn-primary">
                                    <i class="bi bi-shield-check"></i> Update Password
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================
             FOOTER
        ========================== -->
        <footer class="user-footer mt-4">
            <div class="container text-center small text-muted">
                &copy; <?= date('Y') ?> NextGen Devops Website. All rights reserved.
            </div>
        </footer>
    </div>

    <!-- =========================
         SCRIPTS
    ========================== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // -----------------------------
    // Preview uploaded profile image
    // -----------------------------
    function previewImage(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('current-photo').src = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // -----------------------------
    // Password Strength + Match
    // -----------------------------
    const passwordInput = document.getElementById('new_password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    const matchText = document.getElementById('matchText');

    if (passwordInput) {
        passwordInput.addEventListener('input', function() {
            const pw = this.value;
            const strength = checkPasswordStrength(pw);

            strengthBar.className = 'password-strength-bar';
            if (pw.length === 0) {
                strengthText.textContent = 'Minimum 6 characters';
                strengthText.className = 'form-text';
            } else if (strength === 1) {
                strengthBar.classList.add('strength-weak');
                strengthText.textContent = 'Weak password';
                strengthText.className = 'text-danger';
            } else if (strength === 2) {
                strengthBar.classList.add('strength-medium');
                strengthText.textContent = 'Medium password';
                strengthText.className = 'text-warning';
            } else {
                strengthBar.classList.add('strength-strong');
                strengthText.textContent = 'Strong password';
                strengthText.className = 'text-success';
            }

            checkPasswordMatch();
        });
    }

    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
    }

    function checkPasswordStrength(password) {
        if (password.length < 6) return 0;
        let score = 0;
        if (password.length >= 8) score++;
        if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
        if (/\d/.test(password)) score++;
        if (/[^A-Za-z0-9]/.test(password)) score++;
        return Math.min(score, 3);
    }

    function checkPasswordMatch() {
        if (!passwordInput || !confirmPasswordInput || !matchText) return;
        const pw = passwordInput.value;
        const cp = confirmPasswordInput.value;
        if (cp.length === 0) {
            matchText.textContent = '';
            matchText.className = 'form-text';
        } else if (pw === cp) {
            matchText.textContent = 'Passwords match';
            matchText.className = 'text-success';
        } else {
            matchText.textContent = 'Passwords do not match';
            matchText.className = 'text-danger';
        }
    }

    // -----------------------------
    // Username Input Validation (client-side)
    // -----------------------------
    const usernameEl = document.getElementById('username');
    if (usernameEl) {
        usernameEl.addEventListener('input', function() {
            if (!/^[a-zA-Z0-9_]*$/.test(this.value)) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });
    }
    </script>
</body>
</html>
