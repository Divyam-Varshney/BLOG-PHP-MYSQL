<?php
/**
 * admin/profile.php
 * -----------------
 * Admin panel page: Profile Management
 * - View and update admin profile (name, username, photo)
 * - Optional password change with validation
 * - Shows admin statistics and recent posts
 *
 * Notes:
 * - This is a cleaned, well-commented version of your original file.
 * - All original PHP logic and JS behavior are preserved.
 * - Auto-dismiss alerts have been added (4s) and a manual close button is kept.
 * - The page expects helper functions from ../data/Functions.php (app_* helpers, $pdo, etc.).
 */

require_once '../data/Functions.php';
app_start_session();

// -------------------------------
// Authorization
// -------------------------------
if (!app_is_admin_logged_in()) {
    header('Location: login.php');
    exit;
}

// Current admin used throughout the page
$current_admin = app_get_current_admin();

// -------------------------------
// State
// -------------------------------
$errors = [];
$success = '';

// -------------------------------
// Handle profile update POST
// -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    // Gather inputs (trim where appropriate)
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // -------------------------------
    // Validation
    // -------------------------------
    if ($first_name === '') {
        $errors[] = 'First name is required.';
    }
    if ($last_name === '') {
        $errors[] = 'Last name is required.';
    }

    if ($username === '') {
        $errors[] = 'Username is required.';
    } elseif (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Username can only contain letters, numbers, and underscores.';
    }

    // Check username uniqueness (exclude current admin)
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = :username AND id != :current_id");
        $stmt->bindValue(':username', $username);
        $stmt->bindValue(':current_id', $current_admin['id'], PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetch()) {
            $errors[] = 'Username is already taken.';
        }
    }

    // Password change validation (optional)
    $update_password = false;
    if ($current_password !== '' || $new_password !== '' || $confirm_password !== '') {
        if ($current_password === '') {
            $errors[] = 'Current password is required to change password.';
        } elseif (!app_verify_password($current_password, $current_admin['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif ($new_password === '') {
            $errors[] = 'New password is required.';
        } elseif (strlen($new_password) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        } elseif ($new_password !== $confirm_password) {
            $errors[] = 'New passwords do not match.';
        } else {
            $update_password = true;
        }
    }

    // -------------------------------
    // Profile photo upload (optional)
    // -------------------------------
    $profile_photo = null;
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/admins';
        $profile_photo = app_upload_file($_FILES['profile_photo'], $upload_dir, ['jpg', 'jpeg', 'png', 'gif']);
        if (!$profile_photo) {
            $errors[] = 'Failed to upload profile photo. Please ensure it\'s a valid image file.';
        }
    }

    // -------------------------------
    // Perform DB update if no errors
    // -------------------------------
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $update_fields = [];
            $params = [];

            $update_fields[] = 'first_name = :first_name';
            $params[':first_name'] = $first_name;

            $update_fields[] = 'last_name = :last_name';
            $params[':last_name'] = $last_name;

            $update_fields[] = 'username = :username';
            $params[':username'] = $username;

            if ($update_password) {
                $update_fields[] = 'password = :password';
                $params[':password'] = app_hash_password($new_password);
            }

            if ($profile_photo) {
                $update_fields[] = 'profile_photo = :profile_photo';
                $params[':profile_photo'] = $profile_photo;

                // Delete old photo (if not default)
                if (!empty($current_admin['profile_photo']) && $current_admin['profile_photo'] !== 'default-admin.png') {
                    $old_path = '../uploads/admins/' . $current_admin['profile_photo'];
                    if (file_exists($old_path)) {
                        @unlink($old_path);
                    }
                }
            }

            $sql = 'UPDATE admins SET ' . implode(', ', $update_fields) . ' WHERE id = :admin_id';
            $params[':admin_id'] = $current_admin['id'];

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                if ($k === ':admin_id') {
                    $stmt->bindValue($k, $v, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($k, $v);
                }
            }

            if ($stmt->execute()) {
                $pdo->commit();
                $success = 'Profile updated successfully!';

                // Refresh current admin data for display
                $current_admin = app_get_current_admin();
            } else {
                $pdo->rollback();
                $errors[] = 'Failed to update profile. Please try again.';
            }

        } catch (Exception $e) {
            $pdo->rollback();
            $errors[] = 'An error occurred while updating profile.';
        }
    }
}

// -------------------------------
// Admin statistics & recent posts (read-only queries)
// -------------------------------
$stmt = $pdo->prepare('SELECT COUNT(*) as total_posts FROM posts WHERE admin_id = :admin_id');
$stmt->bindValue(':admin_id', $current_admin['id'], PDO::PARAM_INT);
$stmt->execute();
$total_posts = (int)($stmt->fetch()['total_posts'] ?? 0);

$stmt = $pdo->prepare("SELECT COUNT(*) as published_posts FROM posts WHERE admin_id = :admin_id AND status = 'published'");
$stmt->bindValue(':admin_id', $current_admin['id'], PDO::PARAM_INT);
$stmt->execute();
$published_posts = (int)($stmt->fetch()['published_posts'] ?? 0);

$stmt = $pdo->prepare('SELECT COUNT(*) as total_comments FROM comments');
$stmt->execute();
$total_comments = (int)($stmt->fetch()['total_comments'] ?? 0);

$stmt = $pdo->prepare('SELECT COUNT(*) as replied_comments FROM comments WHERE admin_reply IS NOT NULL');
$stmt->execute();
$replied_comments = (int)($stmt->fetch()['replied_comments'] ?? 0);

// Recent posts by this admin
$stmt = $pdo->prepare("SELECT p.*, c.name as category_name,
                      (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) as likes_count,
                      (SELECT COUNT(*) FROM comments cm WHERE cm.post_id = p.id) as comments_count
                      FROM posts p
                      LEFT JOIN categories c ON p.category_id = c.id
                      WHERE p.admin_id = :admin_id
                      ORDER BY p.created_at DESC
                      LIMIT 5");
$stmt->bindValue(':admin_id', $current_admin['id'], PDO::PARAM_INT);
$stmt->execute();
$recent_posts = $stmt->fetchAll() ?: [];
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Admin Dashboard</title>

    <!-- Bootstrap + Icons + project admin stylesheet -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/admin.css" rel="stylesheet">

    <style>
    /* Minimal inline helpers kept here for safety; prefer adding rules to ../assets/css/admin.css */
    .profile-photo {
        width: 120px;
        height: 120px;
        object-fit: cover;
    }

    .image-preview {
        max-width: 120px;
        max-height: 120px;
        border-radius: 8px;
    }

    .password-strength {
        height: 6px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 4px;
        margin-top: 6px;
    }

    .password-strength-bar {
        height: 6px;
        width: 0;
        border-radius: 4px;
        transition: width .25s ease;
    }

    .password-strength-bar.strength-weak {
        width: 33%;
        background: #dc3545;
    }

    .password-strength-bar.strength-medium {
        width: 66%;
        background: #ffc107;
    }

    .password-strength-bar.strength-strong {
        width: 100%;
        background: #198754;
    }
    </style>
</head>

<body>
    <!-- ==========================
         Header / Navbar
         ========================== -->
    <header>
        <nav class="navbar navbar-expand-lg" aria-label="Main navigation">
            <div class="container-fluid">
                <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-journal-bookmark"></i> MyBlog
                    Admin</a>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                    aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="mainNav">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-house"></i>
                                Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="posts.php"><i class="bi bi-file-post"></i>
                                Posts</a></li>
                        <li class="nav-item"><a class="nav-link" href="categories.php"><i class="bi bi-folder"></i>
                                Categories</a></li>
                        <li class="nav-item"><a class="nav-link" href="comments.php"><i class="bi bi-chat-dots"></i>
                                Comments</a></li>
                        <li class="nav-item"><a class="nav-link" href="users.php"><i class="bi bi-people"></i> Users</a>
                        </li>
                        <li class="nav-item"><a class="nav-link active" href="profile.php"><i
                                    class="bi bi-person-gear"></i> Profile</a></li>
                        <li class="nav-item"><a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i>
                                Logout</a></li>
                    </ul>

                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item d-flex align-items-center">
                            <img src="../uploads/admins/<?= htmlspecialchars($current_admin['profile_photo'] ?? 'default-admin.png') ?>"
                                alt="<?= htmlspecialchars(trim(($current_admin['first_name'] ?? '') . ' ' . ($current_admin['last_name'] ?? ''))) ?>"
                                class="rounded-circle me-2" style="width:32px;height:32px;object-fit:cover;">
                            <span
                                class="nav-link mb-0"><?= htmlspecialchars(trim(($current_admin['first_name'] ?? '') . ' ' . ($current_admin['last_name'] ?? ''))) ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
    </header>

    <!-- ==========================
         Main content
         ========================== -->
    <main class="container-fluid py-4">
        <div class="row">
            <div class="col-12">

                <!-- Profile header -->
                <div class="profile-header mb-4">
                    <div class="row align-items-center text-center text-md-start">
                        <div class="col-12 col-md-3 mb-3 mb-md-0 text-center">
                            <img src="../uploads/admins/<?= htmlspecialchars($current_admin['profile_photo'] ?? 'default-admin.png') ?>"
                                alt="Profile Photo" class="profile-photo rounded-circle">
                        </div>
                        <div class="col-12 col-md-9">
                            <h2 class="mb-1">
                                <?= htmlspecialchars($current_admin['first_name'] . ' ' . $current_admin['last_name']) ?>
                            </h2>
                            <p class="mb-1">@<?= htmlspecialchars($current_admin['username']) ?></p>
                            <p class="text-muted mb-3">Administrator since
                                <?= date('F Y', strtotime($current_admin['created_at'] ?? 'now')) ?></p>

                            <div class="row text-center text-md-start g-3">
                                <div class="col-6 col-md-auto">
                                    <strong><?= $total_posts ?></strong>
                                    <small class="d-block">Total Posts</small>
                                </div>
                                <div class="col-6 col-md-auto">
                                    <strong><?= $published_posts ?></strong>
                                    <small class="d-block">Published</small>
                                </div>
                                <div class="col-6 col-md-auto">
                                    <strong><?= $replied_comments ?>/<?= $total_comments ?></strong>
                                    <small class="d-block">Comments Replied</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alerts (errors / success) - auto-dismiss enabled -->
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger auto-dismiss" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success auto-dismiss" role="alert">
                    <i class="bi bi-check-circle me-1"></i> <?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Profile Settings Form -->
                    <div class="col-lg-8 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-person-gear"></i> Profile Settings</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="first_name" class="form-label">First Name *</label>
                                            <input type="text" id="first_name" name="first_name" class="form-control"
                                                value="<?= htmlspecialchars($current_admin['first_name'] ?? '') ?>"
                                                required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="last_name" class="form-label">Last Name *</label>
                                            <input type="text" id="last_name" name="last_name" class="form-control"
                                                value="<?= htmlspecialchars($current_admin['last_name'] ?? '') ?>"
                                                required>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="username" class="form-label">Username *</label>
                                        <input type="text" id="username" name="username" class="form-control"
                                            value="<?= htmlspecialchars($current_admin['username'] ?? '') ?>" required>
                                        <small class="form-text text-muted">Only letters, numbers, and underscores
                                            allowed</small>
                                    </div>

                                    <div class="mb-4">
                                        <label for="profile_photo" class="form-label">Profile Photo</label>
                                        <div class="d-flex align-items-center gap-3">
                                            <img src="../uploads/admins/<?= htmlspecialchars($current_admin['profile_photo'] ?? 'default-admin.png') ?>"
                                                alt="Current Profile" width="60" height="60" class="rounded-circle"
                                                style="object-fit:cover;">
                                            <div>
                                                <input type="file" id="profile_photo" name="profile_photo"
                                                    accept="image/*" onchange="previewImage(this)">
                                                <small class="form-text text-muted d-block mt-1">Recommended: 300x300px,
                                                    JPG/PNG, max 2MB</small>
                                            </div>
                                        </div>
                                        <img id="imagePreview" class="image-preview"
                                            style="display:none; margin-top:10px;" alt="Preview">
                                    </div>

                                    <hr>

                                    <h6 class="mb-3"><i class="bi bi-key"></i> Change Password (Optional)</h6>

                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Current Password</label>
                                        <div class="input-group">
                                            <input type="password" id="current_password" name="current_password"
                                                class="form-control">
                                            <button type="button" class="btn btn-outline-secondary"
                                                onclick="togglePassword('current_password')"><i class="bi bi-eye"
                                                    id="current_password_icon"></i></button>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="new_password" class="form-label">New Password</label>
                                            <input type="password" id="new_password" name="new_password"
                                                class="form-control">
                                            <div class="password-strength">
                                                <div id="strengthBar" class="password-strength-bar"></div>
                                            </div>
                                            <small id="strengthText" class="form-text text-muted">Minimum 6
                                                characters</small>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="confirm_password" class="form-label">Confirm New
                                                Password</label>
                                            <input type="password" id="confirm_password" name="confirm_password"
                                                class="form-control">
                                            <small id="matchText" class="form-text"></small>
                                        </div>
                                    </div>

                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <button type="submit" name="update_profile" class="btn btn-primary"><i
                                                class="bi bi-save"></i> Update Profile</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Right column: Stats & Recent posts -->
                    <div class="col-lg-4">
                        <!-- Statistics -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-graph-up"></i> Your Statistics</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <div class="stats-card card">
                                            <div class="card-body">
                                                <div class="stats-number"><?= $total_posts ?></div><small>Total
                                                    Posts</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="stats-card card">
                                            <div class="card-body">
                                                <div class="stats-number"><?= $published_posts ?></div>
                                                <small>Published</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="stats-card card">
                                            <div class="card-body">
                                                <div class="stats-number"><?php
                                            $stmt = $pdo->prepare('SELECT SUM(views) as total_views FROM posts WHERE admin_id = :admin_id');
                                            $stmt->bindValue(':admin_id', $current_admin['id'], PDO::PARAM_INT);
                                            $stmt->execute();
                                            $total_views = (int)($stmt->fetch()['total_views'] ?? 0);
                                            echo number_format($total_views);
                                        ?></div><small>Total Views</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="stats-card card">
                                            <div class="card-body">
                                                <div class="stats-number"><?= $replied_comments ?></div>
                                                <small>Replies</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Posts -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Posts</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_posts)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-file-post display-4"></i>
                                    <p class="mt-2">No posts yet</p>
                                    <a href="posts.php?action=create" class="btn btn-primary btn-sm"><i
                                            class="bi bi-plus"></i> Create Your First Post</a>
                                </div>
                                <?php else: ?>
                                <div class="activity-timeline">
                                    <?php foreach ($recent_posts as $post): ?>
                                    <div class="activity-item mb-3">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1"><a
                                                        href="posts.php?action=view&id=<?= (int)$post['id'] ?>"
                                                        class="text-decoration-none text-light"><?= htmlspecialchars(app_truncate_text($post['title'], 30)) ?></a>
                                                </h6>
                                                <div class="d-flex gap-2 mb-1">
                                                    <span
                                                        class="badge bg-<?= $post['status'] === 'published' ? 'success' : ($post['status'] === 'unpublished' ? 'danger' : 'secondary') ?>"><?= htmlspecialchars(ucfirst($post['status'])) ?></span>
                                                    <?php if (!empty($post['category_name'])): ?><span
                                                        class="badge bg-info"><?= htmlspecialchars($post['category_name']) ?></span><?php endif; ?>
                                                </div>
                                                <small class="text-muted"><i class="bi bi-eye"></i>
                                                    <?= (int)$post['views'] ?> <i class="bi bi-heart ms-2"></i>
                                                    <?= (int)$post['likes_count'] ?> <i class="bi bi-chat ms-2"></i>
                                                    <?= (int)$post['comments_count'] ?></small>
                                            </div>
                                            <small
                                                class="text-muted"><?= app_time_elapsed_string($post['created_at']) ?></small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="text-center mt-3"><a href="posts.php"
                                        class="btn btn-outline-primary btn-sm"><i class="bi bi-eye"></i> View All
                                        Posts</a></div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- ==========================
         Scripts: Bootstrap + Helpers
         - Image preview
         - Password toggle
         - Password strength + match checks
         - Auto-dismiss alerts
         ========================== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Preview uploaded image
    function previewImage(input) {
        const preview = document.getElementById('imagePreview');
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(input.files[0]);
        } else {
            preview.style.display = 'none';
        }
    }

    // Toggle password visibility
    function togglePassword(fieldId) {
        const field = document.getElementById(fieldId);
        const icon = document.getElementById(fieldId + '_icon');
        if (!field) return;
        if (field.type === 'password') {
            field.type = 'text';
            if (icon) icon.className = 'bi bi-eye-slash';
        } else {
            field.type = 'password';
            if (icon) icon.className = 'bi bi-eye';
        }
    }

    // Password strength & match checks
    document.addEventListener('DOMContentLoaded', function() {
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');
        const matchText = document.getElementById('matchText');

        function checkStrength(password) {
            let strength = 0;
            if (password.length >= 6) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;

            strengthBar.className = 'password-strength-bar';
            if (strength === 0) {
                strengthText.textContent = 'Minimum 6 characters';
            } else if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
                strengthText.textContent = 'Weak password';
            } else if (strength === 3) {
                strengthBar.classList.add('strength-medium');
                strengthText.textContent = 'Medium strength';
            } else {
                strengthBar.classList.add('strength-strong');
                strengthText.textContent = 'Strong password';
            }
        }

        if (newPasswordInput) {
            newPasswordInput.addEventListener('input', function() {
                // reset classes first
                strengthBar.className = 'password-strength-bar';
                checkStrength(newPasswordInput.value);

                if (confirmPasswordInput && confirmPasswordInput.value.length > 0) {
                    if (newPasswordInput.value === confirmPasswordInput.value) {
                        matchText.textContent = 'Passwords match';
                        matchText.className = 'text-success';
                    } else {
                        matchText.textContent = 'Passwords do not match';
                        matchText.className = 'text-danger';
                    }
                }
            });
        }

        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', function() {
                if (newPasswordInput && newPasswordInput.value === confirmPasswordInput.value) {
                    matchText.textContent = 'Passwords match';
                    matchText.className = 'text-success';
                } else {
                    matchText.textContent = 'Passwords do not match';
                    matchText.className = 'text-danger';
                }
            });
        }

        // Auto-dismiss alerts after 4 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert.auto-dismiss').forEach(function(el) {
                try {
                    bootstrap.Alert.getOrCreateInstance(el).close();
                } catch (e) {
                    el.remove();
                }
            });
        }, 4000);
    });
    </script>
</body>

</html>