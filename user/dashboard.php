<?php
/**
 * user/dashboard.php
 * -----------------------------------
 * Page: User Dashboard
 *
 * Purpose:
 * - Ensure user is logged in (redirect to login if not)
 * - Retrieve current user info, bookmarks, comment replies, and stats
 * - Render dashboard UI: welcome, stats cards, bookmarks & admin replies
 * - Provide modals to view all bookmarks and replies
 *
 * Notes:
 * - All existing DB interactions, session logic, and JS behavior are preserved.
 * - Defensive handling for profile photo is included to avoid undefined/basename warnings.
 */

require_once __DIR__ . '/../data/Functions.php';
app_start_session();

/* =========================================================================
   1) Auth check & current user
   ========================================================================= */
if (!app_is_user_logged_in()) {
    $_SESSION['redirect_after_login'] = 'dashboard.php';
    header('Location: login.php');
    exit;
}

$current_user = app_get_current_user();
if (!$current_user) {
    // In case user cannot be loaded, log out and force login
    app_logout();
    header('Location: login.php');
    exit;
}

/* =========================================================================
   2) Friendly display name for header
   ========================================================================= */
$display_name = 'Guest';
if (!empty($current_user['full_name'])) {
    $display_name = $current_user['full_name'];
} elseif (!empty($current_user['first_name']) || !empty($current_user['last_name'])) {
    $display_name = trim(($current_user['first_name'] ?? '') . ' ' . ($current_user['last_name'] ?? ''));
} elseif (!empty($current_user['username'])) {
    $display_name = $current_user['username'];
}

/* =========================================================================
   3) Safe profile photo resolution
   - Accepts URL stored in DB or filename stored in uploads/users/
   - Falls back to default avatar path
   ========================================================================= */
$default_web_path   = '../uploads/users/default-user.png';
$raw_profile = isset($current_user['profile_photo']) ? (string)$current_user['profile_photo'] : '';
$profile_photo_url = $default_web_path;

if ($raw_profile !== '') {
    if (filter_var($raw_profile, FILTER_VALIDATE_URL)) {
        // DB contains a full URL
        $profile_photo_url = $raw_profile;
    } else {
        // Treat as local file in uploads/users/
        $uploads_dir = __DIR__ . '/../uploads/users';
        $base = basename($raw_profile);
        $server_path = $uploads_dir . '/' . $base;
        if (is_file($server_path) && is_readable($server_path)) {
            $profile_photo_url = '../uploads/users/' . rawurlencode($base);
        }
    }
}

/* =========================================================================
   4) Fetch bookmarks, comment replies, and stats (DB interactions)
   - Wrap DB calls in try/catch to avoid fatal errors on exceptions
   ========================================================================= */
$bookmarks = [];
try {
    $bookmarks = app_get_user_bookmarks($current_user['id']);
    if (!is_array($bookmarks)) $bookmarks = [];
} catch (Throwable $e) {
    $bookmarks = [];
}

$comment_replies = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, p.title AS post_title, p.slug AS post_slug, c.admin_reply, 
               COALESCE(c.admin_reply_at, c.updated_at, c.created_at) AS admin_reply_at
        FROM comments c
        JOIN posts p ON c.post_id = p.id
        WHERE c.user_id = :uid AND c.admin_reply IS NOT NULL
        ORDER BY admin_reply_at DESC
        LIMIT 10
    ");
    $stmt->bindValue(':uid', (int)$current_user['id'], PDO::PARAM_INT);
    $stmt->execute();
    $comment_replies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($comment_replies)) $comment_replies = [];
} catch (Throwable $e) {
    $comment_replies = [];
}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_comments,
               SUM(CASE WHEN admin_reply IS NOT NULL THEN 1 ELSE 0 END) AS replied_comments
        FROM comments WHERE user_id = :uid
    ");
    $stmt->bindValue(':uid', (int)$current_user['id'], PDO::PARAM_INT);
    $stmt->execute();
    $comment_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$comment_stats) $comment_stats = ['total_comments' => 0, 'replied_comments' => 0];
} catch (Throwable $e) {
    $comment_stats = ['total_comments' => 0, 'replied_comments' => 0];
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total_likes FROM likes WHERE user_id = :uid");
    $stmt->bindValue(':uid', (int)$current_user['id'], PDO::PARAM_INT);
    $stmt->execute();
    $like_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$like_stats) $like_stats = ['total_likes' => 0];
} catch (Throwable $e) {
    $like_stats = ['total_likes' => 0];
}

/* =========================================================================
   5) Safe esc() helper for template output
   ========================================================================= */
function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <!-- HEAD: meta + styles -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dashboard - NextGen Devops</title>

    <!-- Bootstrap + Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Custom dashboard styles (ensure this file exists) -->
    <link href="../assets/css/user.css" rel="stylesheet">
</head>
<body>
    <!-- NAVBAR: user, links -->
    <nav class="navbar navbar-expand-lg navbar-dark shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold gradient-text" href="../index.php">
                <i class="bi bi-journal-bookmark"></i> NextGen Devops
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <!-- left nav -->
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="../index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link active" href="dashboard.php">Dashboard</a></li>
                </ul>

                <!-- user dropdown -->
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#"
                            data-bs-toggle="dropdown">
                            <img src="<?= esc($profile_photo_url) ?>" alt="<?= esc($display_name) ?>" width="32" height="32" class="rounded-circle me-2 border-gradient">
                            <?= esc($current_user['username'] ?? '') ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person-gear"></i> Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- MAIN CONTAINER -->
    <div class="container py-5">
        <!-- WELCOME: avatar, greeting, quick actions -->
        <div class="welcome-section text-center text-md-start mb-5">
            <div class="row align-items-center g-3">
                <div class="col-md-3 text-center mb-3 mb-md-0">
                    <div class="avatar-wrapper" aria-hidden="false">
                        <!-- profile image (lazy + onerror fallback) -->
                        <img src="<?= esc($profile_photo_url) ?>" alt="<?= esc($display_name) ?>'s profile photo"
                            class="rounded-circle" width="120" height="120" loading="lazy"
                            onerror="this.onerror=null;this.src='<?= esc($default_web_path) ?>';">

                        <!-- quick link to change photo -->
                        <a href="profile.php" class="edit-avatar-btn" title="Change photo" aria-label="Change profile photo">
                            <i class="bi bi-camera"></i>
                        </a>
                    </div>
                </div>

                <div class="col-md-9">
                    <h2 class="fw-bold">Welcome back, <?= esc($display_name) ?>!</h2>

                    <!-- optional meta (username/email/member since) -->
                    <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center gap-2 gap-sm-4 mt-2">
                        <div class="small text-muted">
                            <strong class="text-white">@<?= esc($current_user['username'] ?? '') ?></strong>
                            <?php if (!empty($current_user['email'])): ?> &middot; <?= esc($current_user['email']) ?> <?php endif; ?>
                        </div>

                        <?php if (!empty($current_user['created_at'])): ?>
                            <div class="small text-muted">Member since <?= date('M Y', strtotime($current_user['created_at'])) ?></div>
                        <?php endif; ?>
                    </div>

                    <p class="text-muted mt-3 mb-3">Here’s what’s happening with your blog activity today.</p>

                    <div class="quick-actions mt-1 justify-content-md-start">
                        <a href="../index.php" class="quick-action-btn"><i class="bi bi-house"></i> Browse Posts</a>
                        <a href="profile.php" class="quick-action-btn"><i class="bi bi-person-gear"></i> Edit Profile</a>
                        <a href="#bookmarks" class="quick-action-btn"><i class="bi bi-bookmark-heart"></i> My Bookmarks</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- SIDEBAR: dashboard menu -->
            <div class="col-lg-3 mb-4">
                <div class="sidebar p-3 rounded shadow-sm">
                    <h6 class="text-uppercase fw-bold text-muted mb-3">Dashboard Menu</h6>
                    <nav class="nav flex-column gap-2">
                        <a class="nav-link active" href="dashboard.php"><i class="bi bi-house"></i> Overview</a>
                        <a class="nav-link" href="#bookmarks"><i class="bi bi-bookmark"></i> Bookmarks</a>
                        <a class="nav-link" href="#replies"><i class="bi bi-reply"></i> Comment Replies</a>
                        <a class="nav-link" href="profile.php"><i class="bi bi-person-gear"></i> Profile Settings</a>
                    </nav>
                </div>
            </div>

            <!-- MAIN: stats & lists -->
            <div class="col-lg-9">
                <!-- STATS : bookmarks, comments, replies, likes -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="card stats-card shadow-sm">
                            <div class="card-body text-center">
                                <div class="stats-number"><?= (int)count($bookmarks) ?></div>
                                <div>Bookmarks</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <div class="card stats-card shadow-sm">
                            <div class="card-body text-center">
                                <div class="stats-number"><?= (int)$comment_stats['total_comments'] ?></div>
                                <div>Comments</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <div class="card stats-card shadow-sm">
                            <div class="card-body text-center">
                                <div class="stats-number"><?= (int)$comment_stats['replied_comments'] ?></div>
                                <div>Replies</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <div class="card stats-card shadow-sm">
                            <div class="card-body text-center">
                                <div class="stats-number"><?= (int)$like_stats['total_likes'] ?></div>
                                <div>Likes Given</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BOOKMARKS & REPLIES: shows recent items & 'View All' buttons -->
                <div class="row">
                    <!-- Bookmarks -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow-sm border-0">
                            <div class="card-header d-flex justify-content-between align-items-center bg-dark text-white rounded-top">
                                <h5 class="mb-0" id="bookmarks"><i class="bi bi-bookmark-heart-fill text-warning"></i> My Bookmarks</h5>
                                <span class="badge bg-gradient px-3 py-1 rounded-pill"><?= (int)count($bookmarks) ?></span>
                            </div>
                            <div class="card-body">
                                <?php if (empty($bookmarks)): ?>
                                    <div class="empty-state text-center p-4">
                                        <i class="bi bi-bookmark fs-2 text-secondary"></i>
                                        <h6 class="mt-2">No bookmarks yet</h6>
                                        <p class="text-muted small">Start bookmarking posts you want to read later!</p>
                                        <a href="../index.php" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Browse Posts</a>
                                    </div>
                                <?php else: ?>
                                    <?php foreach (array_slice($bookmarks, 0, 4) as $bookmark): ?>
                                        <div class="bookmark-card p-3 mb-2 rounded d-flex align-items-start gap-2 border-hover">
                                            <i class="bi bi-bookmark-fill text-gradient"></i>
                                            <div>
                                                <h6 class="mb-1 fw-semibold">
                                                    <a href="../post.php?slug=<?= urlencode($bookmark['slug']) ?>" class="text-decoration-none text-light">
                                                        <?= esc(app_truncate_text($bookmark['title'] ?? 'Untitled', 50)) ?>
                                                    </a>
                                                </h6>
                                                <small class="text-muted">
                                                    <i class="bi bi-tag"></i> <?= esc($bookmark['category_name'] ?? 'Uncategorized') ?> ·
                                                    <i class="bi bi-clock"></i> <?= esc(app_time_elapsed_string($bookmark['bookmarked_at'] ?? date('Y-m-d H:i:s'))) ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if (count($bookmarks) > 4): ?>
                                        <div class="text-center">
                                            <a href="#" class="btn btn-outline-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#bookmarksModal">View All</a>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Admin Replies -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow-sm border-0">
                            <div class="card-header d-flex justify-content-between align-items-center bg-dark text-white rounded-top">
                                <h5 class="mb-0" id="replies"><i class="bi bi-reply-all-fill text-info"></i> Admin Replies</h5>
                                <span class="badge bg-gradient px-3 py-1 rounded-pill"><?= (int)count($comment_replies) ?></span>
                            </div>
                            <div class="card-body">
                                <?php if (empty($comment_replies)): ?>
                                    <div class="empty-state text-center p-4">
                                        <i class="bi bi-chat-dots fs-2 text-secondary"></i>
                                        <h6 class="mt-2">No replies yet</h6>
                                        <p class="text-muted small">Admin replies to your comments will appear here!</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach (array_slice($comment_replies, 0, 3) as $reply): ?>
                                        <div class="reply-card p-3 mb-3 rounded shadow-sm border-hover">
                                            <h6 class="fw-semibold">
                                                <i class="bi bi-file-text text-gradient"></i>
                                                <a href="../post.php?slug=<?= urlencode($reply['post_slug'] ?? '') ?>" class="text-decoration-none text-light">
                                                    <?= esc(app_truncate_text($reply['post_title'] ?? 'Untitled', 60)) ?>
                                                </a>
                                            </h6>
                                            <p class="small mb-1"><i class="bi bi-chat-left-quote"></i> <?= esc(app_truncate_text($reply['content'] ?? '', 80)) ?></p>

                                            <div class="admin-reply p-2 rounded bg-dark-subtle">
                                                <small class="text-primary fw-bold"><i class="bi bi-person-badge"></i> Admin Reply:</small>
                                                <p class="mb-0"><?= nl2br(esc($reply['admin_reply'] ?? '')) ?></p>
                                            </div>

                                            <small class="text-muted d-block mt-1"><i class="bi bi-clock"></i> <?= esc(app_time_elapsed_string($reply['admin_reply_at'] ?? $reply['created_at'] ?? date('Y-m-d H:i:s'))) ?></small>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if (count($comment_replies) > 3): ?>
                                        <div class="text-center">
                                            <a href="#" class="btn btn-outline-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#repliesModal">View All</a>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div> <!-- /row (Bookmarks & Replies) -->
            </div> <!-- /main -->
        </div> <!-- /row -->
    </div> <!-- /container -->

    <!-- BOOKMARKS MODAL: shows all bookmarks -->
    <div class="modal fade" id="bookmarksModal" tabindex="-1" aria-labelledby="bookmarksModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content bg-dark text-white">
                <div class="modal-header border-0">
                    <h5 class="modal-title gradient-text" id="bookmarksModalLabel"><i class="bi bi-bookmark-heart-fill"></i> All My Bookmarks</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($bookmarks)): ?>
                        <?php foreach ($bookmarks as $bookmark): ?>
                            <div class="bookmark-card p-3 mb-2 rounded d-flex align-items-start gap-2 border-hover">
                                <i class="bi bi-bookmark-fill text-gradient"></i>
                                <div>
                                    <h6 class="mb-1 fw-semibold">
                                        <a href="../post.php?slug=<?= urlencode($bookmark['slug']) ?>" class="text-decoration-none text-light">
                                            <?= esc($bookmark['title'] ?? 'Untitled') ?>
                                        </a>
                                    </h6>
                                    <small class="text-muted"><i class="bi bi-tag"></i> <?= esc($bookmark['category_name'] ?? 'Uncategorized') ?> · <i class="bi bi-clock"></i> <?= esc(app_time_elapsed_string($bookmark['bookmarked_at'] ?? date('Y-m-d H:i:s'))) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No bookmarks found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- REPLIES MODAL: shows all admin replies -->
    <div class="modal fade" id="repliesModal" tabindex="-1" aria-labelledby="repliesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content bg-dark text-white">
                <div class="modal-header border-0">
                    <h5 class="modal-title gradient-text" id="repliesModalLabel"><i class="bi bi-reply-all-fill"></i> All Admin Replies</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($comment_replies)): ?>
                        <?php foreach ($comment_replies as $reply): ?>
                            <div class="reply-card p-3 mb-3 rounded shadow-sm border-hover">
                                <h6 class="fw-semibold">
                                    <i class="bi bi-file-text text-gradient"></i>
                                    <a href="../post.php?slug=<?= urlencode($reply['post_slug'] ?? '') ?>" class="text-decoration-none text-light"><?= esc($reply['post_title'] ?? 'Untitled') ?></a>
                                </h6>
                                <p class="small mb-1"><i class="bi bi-chat-left-quote"></i> <?= esc($reply['content'] ?? '') ?></p>
                                <div class="admin-reply p-2 rounded bg-dark-subtle">
                                    <small class="text-primary fw-bold"><i class="bi bi-person-badge"></i> Admin Reply:</small>
                                    <p class="mb-0"><?= nl2br(esc($reply['admin_reply'] ?? '')) ?></p>
                                </div>
                                <small class="text-muted d-block mt-1"><i class="bi bi-clock"></i> <?= esc(app_time_elapsed_string($reply['admin_reply_at'] ?? $reply['created_at'] ?? date('Y-m-d H:i:s'))) ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No replies found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
