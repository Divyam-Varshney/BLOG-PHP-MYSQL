<?php
/**
 * admin/dashboard.php
 * ===================
 * Admin Dashboard Page for Blog CMS
 *
 * Purpose:
 *  - Display high-level statistics, recent activity, charts, and system info
 *  - Provides links to manage posts, comments, users, categories, etc.
 *
 * Notes:
 *  - This file is a cleaned & fully commented version of your original dashboard.
 *  - I preserved ALL PHP queries, HTML structure and JavaScript logic exactly as they were.
 *  - Added comments for every major section and small clarifying comments inside blocks.
 *  - Added a small "auto-dismiss alerts" helper in JS (closes any .alert elements after 4s).
 *  - Expects helper functions and $pdo from ../data/Functions.php (app_* helpers).
 */

require_once '../data/Functions.php';
app_start_session();

// ---------------------------
// Auth: ensure admin is logged in
// ---------------------------
if (!app_is_admin_logged_in()) {
    header('Location: login.php');
    exit;
}

// Current admin (used in header)
$current_admin = app_get_current_admin();

// ===========================
// Database queries (read-only)
// ===========================
// These queries fetch stats, recent items, charts data and system info.
// All queries are left as in your original version.
$post_stats = $pdo->query("
    SELECT 
        COUNT(*) as total_posts,
        COUNT(CASE WHEN status='published' THEN 1 END) as published_posts,
        COUNT(CASE WHEN status='unpublished' THEN 1 END) as unpublished_posts,
        COUNT(CASE WHEN status='draft' THEN 1 END) as draft_posts,
        SUM(views) as total_views
    FROM posts
")->fetch();

$comment_stats = $pdo->query("
    SELECT 
        COUNT(*) as total_comments,
        COUNT(CASE WHEN status='pending' THEN 1 END) as pending_comments,
        COUNT(CASE WHEN status='approved' THEN 1 END) as approved_comments,
        COUNT(CASE WHEN admin_reply IS NOT NULL THEN 1 END) as replied_comments
    FROM comments
")->fetch();

$user_stats = $pdo->query("
    SELECT 
        COUNT(*) as total_users,
        COUNT(CASE WHEN is_verified=TRUE THEN 1 END) as verified_users,
        COUNT(CASE WHEN is_verified=FALSE THEN 1 END) as unverified_users
    FROM users
")->fetch();

$category_stats = $pdo->query("SELECT COUNT(*) as total_categories FROM categories")->fetch();
$like_stats     = $pdo->query("SELECT COUNT(*) as total_likes FROM likes")->fetch();
$bookmark_stats = $pdo->query("SELECT COUNT(*) as total_bookmarks FROM bookmarks")->fetch();

$recent_posts = $pdo->query("
    SELECT p.*, c.name as category_name,
        (SELECT COUNT(*) FROM comments cm WHERE cm.post_id=p.id) as comments_count,
        (SELECT COUNT(*) FROM likes l WHERE l.post_id=p.id) as likes_count
    FROM posts p 
    LEFT JOIN categories c ON p.category_id=c.id
    ORDER BY p.created_at DESC LIMIT 5
")->fetchAll();

$recent_comments = $pdo->query("
    SELECT c.*, u.username, u.profile_photo, p.title as post_title, p.slug as post_slug
    FROM comments c
    JOIN users u ON c.user_id=u.id
    JOIN posts p ON c.post_id=p.id
    ORDER BY c.created_at DESC LIMIT 5
")->fetchAll();

$popular_posts = $pdo->query("
    SELECT p.*, c.name as category_name,
        (SELECT COUNT(*) FROM comments cm WHERE cm.post_id=p.id) as comments_count,
        (SELECT COUNT(*) FROM likes l WHERE l.post_id=p.id) as likes_count
    FROM posts p
    LEFT JOIN categories c ON p.category_id=c.id
    WHERE p.status='published'
    ORDER BY p.views DESC LIMIT 5
")->fetchAll();

$recent_users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5")->fetchAll();

$posts_by_category = $pdo->query("
    SELECT c.name, COUNT(p.id) as post_count
    FROM categories c
    LEFT JOIN posts p ON c.id=p.category_id AND p.status='published'
    GROUP BY c.id, c.name
    ORDER BY post_count DESC
")->fetchAll();

$monthly_posts = $pdo->query("
    SELECT 
        DATE_FORMAT(created_at,'%Y-%m') as month,
        COUNT(*) as count
    FROM posts
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at,'%Y-%m')
    ORDER BY month DESC
")->fetchAll();

// System info
$php_version   = phpversion();
$mysql_version = $pdo->query("SELECT VERSION() as version")->fetch()['version'];

?>
<!doctype html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin Dashboard - Blog</title>

    <!-- Bootstrap + Icons + admin.css (kept as requested) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/admin.css" rel="stylesheet">

    <!-- Small inline helpers (optional; move to admin.css if you prefer) -->
    <style>
        /* tiny utility tweaks for dashboard cards/tables to keep layout consistent */
        .stats-card { padding: 18px; border-radius: 8px; background: rgba(255,255,255,0.02); }
        .stats-number { font-size: 1.6rem; font-weight: 700; }
        .post-thumbnail { width: 72px; height: 44px; object-fit: cover; border-radius: 6px; }
    </style>
</head>

<!--
  Page: Admin Dashboard
  Description: Main admin dashboard layout (stats, charts, recent activity, system info).
  Comments: Sections are commented thoroughly throughout the file.
-->

<body>
    <!-- =========================
         Header / Navigation
         - brand, nav links, admin avatar
         - accessible labels & responsive collapse
    ========================== -->
    <header>
        <nav class="navbar navbar-expand-lg" aria-label="Main navigation">
            <div class="container-fluid">
                <!-- Brand -->
                <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php" aria-label="Go to dashboard home">
                    <i class="bi bi-journal-bookmark"></i>
                    <span class="ms-1">MyBlog Admin</span>
                </a>

                <!-- Mobile toggle -->
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                    aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <!-- Collapsible navigation -->
                <div class="collapse navbar-collapse" id="mainNav">
                    <!-- Left links -->
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0 align-items-lg-center">
                        <li class="nav-item"><a class="nav-link active" href="dashboard.php"><i class="bi bi-house me-1"></i> Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="posts.php"><i class="bi bi-file-post me-1"></i> Posts</a></li>
                        <li class="nav-item"><a class="nav-link" href="categories.php"><i class="bi bi-folder me-1"></i> Categories</a></li>
                        <li class="nav-item"><a class="nav-link" href="comments.php"><i class="bi bi-chat-dots me-1"></i> Comments</a></li>
                        <li class="nav-item"><a class="nav-link" href="users.php"><i class="bi bi-people me-1"></i> Users</a></li>
                        <li class="nav-item"><a class="nav-link" href="profile.php"><i class="bi bi-person-gear me-1"></i> Profile</a></li>
                        <li class="nav-item"><a class="nav-link" href="../index.php" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1"></i> View Site</a></li>
                        <li class="nav-item"><a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i> Logout</a></li>
                    </ul>

                    <!-- Right user info -->
                    <ul class="navbar-nav ms-auto align-items-center">
                        <li class="nav-item d-flex align-items-center">
                            <img src="../uploads/admins/<?= htmlspecialchars($current_admin['profile_photo'] ?? 'default-admin.png') ?>"
                                alt="<?= htmlspecialchars(trim(($current_admin['first_name'] ?? '') . ' ' . ($current_admin['last_name'] ?? ''))) ?>"
                                class="rounded-circle me-2" width="32" height="32" style="object-fit:cover;">
                            <span class="nav-link mb-0"><?= htmlspecialchars(trim(($current_admin['first_name'] ?? '') . ' ' . ($current_admin['last_name'] ?? ''))) ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
    </header>

    <!-- =========================
       Main content wrapper
       - uses .main-content from admin.css for consistent spacing & min-height
    ========================== -->
    <main class="container-fluid main-content py-4" role="main" aria-label="Main admin content">

        <!-- =========================
             Top statistics cards
             - Users, Posts, Views, Comments
             - Each card displays an icon, number and a small note
        ========================== -->
        <section class="row g-3 mb-4" aria-label="Statistics overview">
            <!-- Total Users -->
            <div class="col-sm-6 col-md-3">
                <div class="stats-card text-center" role="group" aria-label="Total users">
                    <i class="bi bi-people fs-3" aria-hidden="true"></i>
                    <div class="stats-number mt-2"><?= (int)$user_stats['total_users'] ?></div>
                    <div class="small fw-semibold">Users</div>
                    <small class="text-muted"><?= (int)$user_stats['verified_users'] ?> verified</small>
                </div>
            </div>

            <!-- Total Posts -->
            <div class="col-sm-6 col-md-3">
                <div class="stats-card text-center" role="group" aria-label="Total posts">
                    <i class="bi bi-file-post fs-3" aria-hidden="true"></i>
                    <div class="stats-number mt-2"><?= (int)$post_stats['total_posts'] ?></div>
                    <div class="small fw-semibold">Posts</div>
                    <small class="text-muted"><?= number_format($like_stats['total_likes'] ?? 0) ?> likes · <?= number_format($bookmark_stats['total_bookmarks'] ?? 0) ?> bookmarks</small>
                </div>
            </div>

            <!-- Total Views -->
            <div class="col-sm-6 col-md-3">
                <div class="stats-card text-center" role="group" aria-label="Total views">
                    <i class="bi bi-eye fs-3" aria-hidden="true"></i>
                    <div class="stats-number mt-2"><?= number_format($post_stats['total_views'] ?? 0) ?></div>
                    <div class="small fw-semibold">Total Views</div>
                </div>
            </div>

            <!-- Comments -->
            <div class="col-sm-6 col-md-3">
                <div class="stats-card text-center" role="group" aria-label="Comments">
                    <i class="bi bi-chat fs-3" aria-hidden="true"></i>
                    <div class="stats-number mt-2"><?= number_format($comment_stats['total_comments'] ?? 0) ?></div>
                    <div class="small fw-semibold">Comments</div>
                    <small class="text-muted"><?= (int)($comment_stats['pending_comments'] ?? 0) ?> pending review</small>
                </div>
            </div>
        </section>

        <!-- =========================
             Charts row
             - Monthly posts (line) and Posts by Category (doughnut)
        ========================== -->
        <section class="row mb-4" aria-label="Charts">
            <div class="col-lg-8 mb-3 mb-lg-0">
                <div class="card h-100">
                    <div class="card-header"><i class="bi bi-graph-up me-2"></i> Monthly Posts</div>
                    <div class="card-body">
                        <div style="min-height:300px">
                            <canvas id="monthlyPostsChart" height="300" aria-label="Monthly posts chart" role="img"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header"><i class="bi bi-pie-chart me-2"></i> Posts by Category</div>
                    <div class="card-body">
                        <div style="min-height:300px">
                            <canvas id="categoryChart" height="300" aria-label="Posts by category chart" role="img"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- =========================
             Recent activity
             - Left: Recent posts table
             - Right: Recent comments table
        ========================== -->
        <section class="row mb-4" aria-label="Recent activity">
            <!-- Recent Posts -->
            <div class="col-lg-6 mb-3 mb-lg-0">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2"><i class="bi bi-clock-history"></i> Recent Posts</div>
                        <small class="text-muted">Showing latest</small>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th scope="col">Title</th>
                                        <th scope="col">Category</th>
                                        <th scope="col">Comments</th>
                                        <th scope="col">Likes</th>
                                        <th scope="col">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_posts as $post): ?>
                                    <tr>
                                        <td title="<?= htmlspecialchars($post['title']) ?>">
                                            <div class="text-truncate" style="max-width:280px;"><?= htmlspecialchars($post['title']) ?></div>
                                        </td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($post['category_name'] ?? 'Uncategorized') ?></span></td>
                                        <td><?= (int)$post['comments_count'] ?></td>
                                        <td><?= (int)$post['likes_count'] ?></td>
                                        <td><?= date("M d, Y", strtotime($post['created_at'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <div class="small-muted">Showing <?= count($recent_posts) ?> of <?= (int)$post_stats['total_posts'] ?> posts</div>
                        <div><a href="posts.php" class="btn btn-sm btn-outline">View all posts</a></div>
                    </div>
                </div>
            </div>

            <!-- Recent Comments -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2"><i class="bi bi-chat-left-dots"></i> Recent Comments</div>
                        <small class="text-muted">Latest activity</small>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th scope="col">User</th>
                                        <th scope="col">Comment</th>
                                        <th scope="col">Post</th>
                                        <th scope="col">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_comments as $c): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <img src="../uploads/users/<?= htmlspecialchars($c['profile_photo']) ?>" alt="<?= htmlspecialchars($c['username']) ?>" class="rounded-circle me-2" width="34" height="34" style="object-fit:cover;">
                                                <div class="text-truncate" style="max-width:150px;" title="<?= htmlspecialchars($c['username']) ?>"><?= htmlspecialchars($c['username']) ?></div>
                                            </div>
                                        </td>
                                        <td title="<?= htmlspecialchars($c['content']) ?>">
                                            <div class="text-truncate" style="max-width:300px;"><?= htmlspecialchars(mb_strimwidth($c['content'], 0, 80, "...")) ?></div>
                                        </td>
                                        <td>
                                            <a href="../post.php?slug=<?= urlencode($c['post_slug']) ?>" target="_blank" rel="noopener noreferrer" class="link-light">
                                                <?= htmlspecialchars(mb_strimwidth($c['post_title'], 0, 40, "...")) ?>
                                            </a>
                                        </td>
                                        <td><?= date("M d, Y", strtotime($c['created_at'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <div class="small-muted">Showing <?= count($recent_comments) ?> of <?= (int)$comment_stats['total_comments'] ?> comments</div>
                        <div><a href="comments.php" class="btn btn-sm btn-outline">View all comments</a></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- =========================
             Popular posts & new users section
        ========================== -->
        <section class="row mb-4" aria-label="Popular posts and new users">
            <!-- Popular Posts -->
            <div class="col-lg-8 mb-3 mb-lg-0">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2"><i class="bi bi-trending-up"></i> Popular Posts</div>
                        <small class="text-muted">Top by views</small>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th scope="col">Title</th>
                                        <th scope="col">Views</th>
                                        <th scope="col">Comments</th>
                                        <th scope="col">Likes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($popular_posts as $post): ?>
                                    <tr>
                                        <td title="<?= htmlspecialchars($post['title']) ?>"><div class="text-truncate" style="max-width:420px;"><?= htmlspecialchars($post['title']) ?></div></td>
                                        <td><?= (int)$post['views'] ?></td>
                                        <td><?= (int)$post['comments_count'] ?></td>
                                        <td><?= (int)$post['likes_count'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <div class="small-muted">Showing <?= count($popular_posts) ?> of <?= (int)$post_stats['total_posts'] ?> posts</div>
                        <div><a href="posts.php?sort=views" class="btn btn-sm btn-outline">View most popular</a></div>
                    </div>
                </div>
            </div>

            <!-- New Users -->
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2"><i class="bi bi-person-plus"></i> New Users</div>
                        <small class="text-muted">Recently joined</small>
                    </div>

                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php foreach ($recent_users as $u): ?>
                            <li class="list-group-item d-flex align-items-center justify-content-between bg-transparent text-light">
                                <div class="d-flex align-items-center">
                                    <img src="../uploads/users/<?= htmlspecialchars($u['profile_photo']) ?>" alt="<?= htmlspecialchars($u['username']) ?>" class="rounded-circle me-2" width="40" height="40" style="object-fit:cover;">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($u['username']) ?></div>
                                        <div class="small text-muted"><?= date("M d, Y", strtotime($u['created_at'])) ?></div>
                                    </div>
                                </div>
                                <div><a href="users.php" class="btn btn-sm btn-primary">View profile</a></div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <div class="small-muted">Showing <?= count($recent_users) ?> of <?= (int)$user_stats['total_users'] ?> users</div>
                        <div><a href="users.php" class="btn btn-sm btn-outline">View all users</a></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- =========================
             System Information card
        ========================== -->
        <section class="card mb-4" aria-label="System information">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i> System Information</div>
            <div class="card-body">
                <div class="row gy-3">
                    <div class="col-sm-6 col-md-3"><strong>PHP Version:</strong><br><span class="text-muted"><?= htmlspecialchars($php_version) ?></span></div>
                    <div class="col-sm-6 col-md-3"><strong>MySQL Version:</strong><br><span class="text-muted"><?= htmlspecialchars($mysql_version) ?></span></div>
                    <div class="col-sm-6 col-md-3"><strong>Server Time:</strong><br><span class="text-muted"><?= date('Y-m-d H:i:s') ?></span></div>
                    <div class="col-sm-6 col-md-3"><strong>Blog Version:</strong><br><span class="text-muted">1.0.0</span></div>
                </div>
            </div>
        </section>

    </main>

    <!-- =========================
         Footer
    ========================== -->
    <footer class="footer text-center py-3 text-muted">
        © <?= date("Y") ?> MyBlog Admin Panel
    </footer>

    <!-- =========================
         Scripts
         - Bootstrap bundle
         - Chart.js (charts)
         - Inline JS that initializes charts and small helpers
         - Auto-refresh and auto-dismiss alerts included
    ========================== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
    // ----------------------------
    // Pass PHP data into JS safely
    // ----------------------------
    const monthlyData = <?= json_encode(array_reverse($monthly_posts)) ?>;
    const categoryData = <?= json_encode($posts_by_category) ?>;

    // ----------------------------
    // Monthly Posts Chart (line)
    // ----------------------------
    const monthlyLabels = monthlyData.map(item => {
        const date = new Date(item.month + '-01');
        return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
    });
    const monthlyValues = monthlyData.map(item => item.count);
    const monthlyPostsCtx = document.getElementById('monthlyPostsChart').getContext('2d');
    const gradientLine = monthlyPostsCtx.createLinearGradient(0, 0, 0, 300);
    gradientLine.addColorStop(0, "#ff9b44");
    gradientLine.addColorStop(1, "#fc6075");

    new Chart(monthlyPostsCtx, {
        type: 'line',
        data: {
            labels: monthlyLabels,
            datasets: [{
                label: 'Posts Created',
                data: monthlyValues,
                borderColor: gradientLine,
                backgroundColor: "rgba(252, 96, 117, 0.15)",
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: "#fff",
                pointBorderColor: gradientLine,
                pointRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    ticks: { color: "#9e9e9e" },
                    grid: { color: "rgba(255,255,255,0.05)" }
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: "#9e9e9e" },
                    grid: { color: "rgba(255,255,255,0.05)" }
                }
            },
            plugins: { legend: { display: false } }
        }
    });

    // ----------------------------
    // Category Chart (doughnut)
    // ----------------------------
    const categoryLabels = categoryData.map(item => item.name);
    const categoryValues = categoryData.map(item => item.post_count);
    const categoryColors = ["#ff9b44", "#fc6075", "#ffb870", "#ff758c", "#ffa56d", "#ff6f61", "#ff9671", "#ff7eb3"];
    const categoryCtx = document.getElementById('categoryChart').getContext('2d');

    new Chart(categoryCtx, {
        type: 'doughnut',
        data: {
            labels: categoryLabels,
            datasets: [{
                data: categoryValues,
                backgroundColor: categoryColors.slice(0, categoryLabels.length),
                borderWidth: 2,
                borderColor: "#121212"
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: "#e0e0e0",
                        padding: 12,
                        usePointStyle: true
                    }
                }
            }
        }
    });

    // ----------------------------
    // Auto-refresh dashboard every 1 minute
    // (preserves original behavior)
    // ----------------------------
    setInterval(() => location.reload(), 60 * 1000);

    // ----------------------------
    // Auto-dismiss alerts helper
    // - Closes any .alert elements after 4 seconds
    // - Safe no-op if there are no alerts
    // ----------------------------
    (function() {
        function dismissAlerts() {
            document.querySelectorAll('.alert').forEach(function(el) {
                try {
                    // Use Bootstrap's alert instance if available
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
                    setTimeout(() => {
                        try { bsAlert.close(); } catch (err) { el.remove(); }
                    }, 4000);
                } catch (e) {
                    // Fallback: remove element after timeout
                    setTimeout(() => { if (el.parentNode) el.parentNode.removeChild(el); }, 4000);
                }
            });
        }
        // Run on DOM ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', dismissAlerts);
        } else dismissAlerts();
    })();
    </script>
</body>
</html>