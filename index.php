<?php
/**
 * index.php (Homepage)
 * -----------------------------------
 * Main entry page for the Blog Website
 * - Displays posts in a grid layout
 * - Supports categories, search, and pagination
 * - Handles AJAX for likes & bookmarks
 * - Provides navigation, hero, sidebar, and footer
 */

require_once 'data/Functions.php';
app_start_session();

// --------------------
// Page Parameters
// --------------------
$page        = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$category_id = $_GET['category'] ?? null;
$search      = $_GET['search'] ?? null;
$limit       = 6;
$offset      = ($page - 1) * $limit;

// --------------------
// Fetch Data
// --------------------
$posts        = app_get_posts($limit, $offset, $category_id, $search);
$categories   = app_get_categories(true);
$current_user = app_get_current_user();

// Total pages (if helper is available)
$total_posts = null;
if (function_exists('app_count_posts')) {
    $total_posts = (int) app_count_posts($category_id, $search);
}
$total_pages = ($total_posts !== null && $total_posts > 0) ? (int) ceil($total_posts / $limit) : null;

// --------------------
// AJAX: Toggle Like
// --------------------
if (isset($_POST['action']) && $_POST['action'] === 'toggle_like' && app_is_user_logged_in()) {
    $post_id = (int)$_POST['post_id'];
    $liked   = app_toggle_like($post_id, $_SESSION['user_id']);

    $stmt = $pdo->prepare("SELECT COUNT(*) as likes_count FROM likes WHERE post_id = :post_id");
    $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch();

    header('Content-Type: application/json');
    echo json_encode([
        'success'      => true,
        'liked'        => $liked,
        'likes_count'  => $result['likes_count']
    ]);
    exit;
}

// --------------------
// AJAX: Toggle Bookmark
// --------------------
if (isset($_POST['action']) && $_POST['action'] === 'toggle_bookmark' && app_is_user_logged_in()) {
    $post_id    = (int)$_POST['post_id'];
    $bookmarked = app_toggle_bookmark($post_id, $_SESSION['user_id']);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'bookmarked' => $bookmarked]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>NextGen Devops - Home</title>

    <!-- Google Fonts & Bootstrap -->
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Main stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <!-- ======================
         NAVBAR
         ====================== -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top" id="main-nav">
        <div class="container">
            <!-- Brand -->
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="index.php">
                <i class="bi bi-journal-bookmark-fill"></i> <span>NextGen Devops</span>
            </a>

            <!-- Mobile Toggle -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Nav Links -->
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                </ul>

                <!-- Search -->
                <form class="d-flex me-3" method="GET" role="search">
                    <input class="form-control me-2" type="search" name="search" placeholder="Search blogs..."
                        value="<?= htmlspecialchars($search ?? '') ?>">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                </form>

                <!-- Auth Links -->
                <ul class="navbar-nav">
                    <?php if ($current_user): ?>
                    <!-- Logged-in dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#"
                            data-bs-toggle="dropdown">
                            <img src="uploads/users/<?= htmlspecialchars($current_user['profile_photo']) ?>" width="36"
                                height="36" class="rounded-circle me-2" alt="Profile">
                            <?= htmlspecialchars($current_user['username']) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="user/dashboard.php">Dashboard</a></li>
                            <li><a class="dropdown-item" href="user/profile.php">Profile</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="user/logout.php">Logout</a></li>
                        </ul>
                    </li>
                    <?php else: ?>
                    <!-- Guest links -->
                    <li class="nav-item"><a class="nav-link" href="user/login.php">Login</a></li>
                    <li class="nav-item"><a class="nav-link" href="user/register.php">Register</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- ======================
         HERO (only on homepage)
         ====================== -->
    <?php if (!$search && !$category_id): ?>
    <section class="hero-section">
        <div class="hero-bubbles" aria-hidden="true">
            <?php for ($i=0; $i<20; $i++): ?><span></span><?php endfor; ?>
        </div>
        <div class="container">
            <h1 class="display-6 fw-bold">🚀 Learn, <span style="color:var(--accent-start)">Build</span>, Automatic,
                <span style="color:var(--accent-end)">Deploy</span>
            </h1>
            <p class="mb-4">Welcome to NextGen DevOps — tutorials & tips on coding, CI/CD, containers, cloud & Linux.
            </p>
            <?php if (!$current_user): ?>
            <a href="user/register.php" class="btn btn-primary btn-sm me-3">Get Started</a>
            <a href="#posts" class="btn btn-ghost btn-sm">Read Blogs</a>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ======================
         MAIN CONTENT
         ====================== -->
    <main class="container py-4" id="page-main">
        <div class="row">
            <!-- Posts (Left Column) -->
            <div class="col-lg-8">
                <div id="posts" class="row">
                    <?php if (empty($posts)): ?>
                    <div class="col-12">
                        <div class="alert alert-info"><i class="bi bi-info-circle"></i> No posts available.</div>
                    </div>
                    <?php else: foreach ($posts as $post): ?>
                    <div class="col-md-6 mb-4">
                        <article class="card h-100">
                            <!-- Featured Image -->
                            <?php if (!empty($post['featured_image'])): ?>
                            <img src="uploads/posts/<?= htmlspecialchars($post['featured_image']) ?>"
                                class="card-img-top" alt="<?= htmlspecialchars($post['title']) ?>">
                            <?php endif; ?>

                            <div class="card-body d-flex flex-column">
                                <!-- Category -->
                                <div class="mb-2">
                                    <span class="badge bg-gradient">
                                        <?= htmlspecialchars($post['category_name'] ?? 'Uncategorized') ?>
                                    </span>
                                </div>

                                <!-- Title -->
                                <h5 class="card-title">
                                    <a href="post.php?slug=<?= urlencode($post['slug']) ?>"
                                        class="text-white text-decoration-none">
                                        <?= htmlspecialchars($post['title']) ?>
                                    </a>
                                </h5>

                                <!-- Excerpt -->
                                <p class="card-text text-muted mb-3">
                                    <?= htmlspecialchars(app_truncate_text(strip_tags($post['excerpt'] ?: $post['content']), 120)) ?>
                                </p>

                                <!-- Meta -->
                                <div class="post-meta text-muted mb-3 small">
                                    <i class="bi bi-person"></i>
                                    <?= htmlspecialchars($post['first_name'] . ' ' . $post['last_name']) ?>
                                    <i class="bi bi-calendar ms-2"></i>
                                    <?= date('M j, Y', strtotime($post['created_at'])) ?>
                                </div>

                                <!-- Footer -->
                                <div class="mt-auto d-flex justify-content-between align-items-center">
                                    <a href="post.php?slug=<?= urlencode($post['slug']) ?>"
                                        class="btn-read btn-sm">Discover More</a>
                                    <div class="post-actions small text-muted">
                                        <span class="me-3"><i class="bi bi-heart"></i>
                                            <?= (int)$post['likes_count'] ?></span>
                                        <span class="me-3"><i class="bi bi-chat"></i>
                                            <?= (int)$post['comments_count'] ?></span>
                                        <span class="me-3"><i class="bi bi-eye"></i>
                                            <?= (int)($post['views'] ?? 0) ?></span>

                                        <?php if ($current_user): ?>
                                        <button
                                            class="like-btn ms-2 <?= app_is_post_liked($post['id'], $current_user['id']) ? 'liked' : '' ?>"
                                            data-post-id="<?= (int)$post['id'] ?>"><i
                                                class="bi bi-heart<?= app_is_post_liked($post['id'], $current_user['id']) ? '-fill' : '' ?>"></i></button>
                                        <button
                                            class="bookmark-btn <?= app_is_post_bookmarked($post['id'], $current_user['id']) ? 'bookmarked' : '' ?>"
                                            data-post-id="<?= (int)$post['id'] ?>"><i
                                                class="bi bi-bookmark<?= app_is_post_bookmarked($post['id'], $current_user['id']) ? '-fill' : '' ?>"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- Sidebar (Right Column) -->
            <div class="col-lg-4">
                <aside class="sidebar">
                    <!-- Categories -->
                    <div class="card p-3 mb-4">
                        <h5 class="fw-bold mb-3"><i class="bi bi-folder me-2"></i> Categories</h5>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="index.php" class="category-pill <?= !$category_id ? 'active' : '' ?>">All</a>
                            <?php foreach ($categories as $category): ?>
                            <a href="index.php?category=<?= $category['id'] ?>"
                                class="category-pill <?= $category_id == $category['id'] ? 'active' : '' ?>">
                                <?= htmlspecialchars($category['name']) ?> <span
                                    class="count"><?= (int)$category['post_count'] ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Recent Posts -->
                    <div class="card p-3">
                        <h5 class="fw-bold mb-3"><i class="bi bi-clock me-2"></i> Recent Posts</h5>
                        <?php $recent_posts = app_get_posts(5, 0); foreach ($recent_posts as $recent_post): ?>
                        <div class="recent-card">
                            <?php if (!empty($recent_post['featured_image'])): ?>
                            <img src="uploads/posts/<?= htmlspecialchars($recent_post['featured_image']) ?>" alt=""
                                class="thumb">
                            <?php endif; ?>
                            <div>
                                <a href="post.php?slug=<?= urlencode($recent_post['slug']) ?>" class="title">
                                    <?= htmlspecialchars(app_truncate_text($recent_post['title'], 50)) ?>
                                </a>
                                <div class="meta text-muted small"><i class="bi bi-calendar"></i>
                                    <?= date('M j', strtotime($recent_post['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>
        </div>
    </main>

    <!-- ======================
         PAGINATION
         ====================== -->
    <?php if ($total_pages !== null && $total_pages > 1): ?>
    <?php /* Full pagination with numbers */ ?>
    <?php else: ?>
    <?php /* Simple prev/next fallback */ ?>
    <?php endif; ?>

    <!-- ======================
         FOOTER
         ====================== -->
    <footer class="footer mt-5 py-5 text-light">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-4">
                    <h5 class="fw-bold"><i class="bi bi-journal-bookmark me-2"></i> NextGen Devops</h5>
                    <p class="text-muted">Find step-by-step tutorials, hands-on guides, and practical tips covering
                        everything
                        from CI/CD pipelines and containers to Linux tricks and cloud platforms.</p>
                </div>
                <div class="col-md-4">
                    <h6 class="fw-bold mb-3">Quick Links</h6>
                    <ul class="list-unstyled small">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="user/register.php">Register</a></li>
                        <li><a href="user/login.php">Login</a></li>
                    </ul>
                </div>
                <div class="col-md-4 text-md-end">
                    <h6 class="fw-bold mb-3">Follow Us</h6>
                    <div class="social">
                        <a href="#"><i class="bi bi-twitter"></i></a>
                        <a href="#"><i class="bi bi-facebook"></i></a>
                        <a href="#"><i class="bi bi-github"></i></a>
                    </div>
                </div>
            </div>
            <hr class="my-4 border-secondary">
            <div class="text-center small text-muted">&copy; <?= date('Y') ?> NextGen Devops Website. All rights
                reserved.</div>
        </div>
    </footer>

    <!-- ======================
         SCRIPTS
         ====================== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!--
      Small JS utilities:
      1) Fix for content-under-fixed-navbar (set body padding-top to nav height)
      2) Like / bookmark AJAX handlers (defensive)
    -->
    <script>
    (function() {
        function updateBodyTopPadding() {
            var nav = document.getElementById('main-nav');
            if (!nav) return;
            var h = nav.offsetHeight;
            if (window.getComputedStyle(nav).position === 'fixed' || nav.classList.contains('fixed-top')) {
                document.body.style.paddingTop = h + 'px';
            } else {
                document.body.style.paddingTop = '';
            }
        }
        window.addEventListener('load', updateBodyTopPadding);
        window.addEventListener('resize', function() {
            clearTimeout(window.__navPaddingTimeout);
            window.__navPaddingTimeout = setTimeout(updateBodyTopPadding, 80);
        });
        updateBodyTopPadding();
    })();

    document.addEventListener('DOMContentLoaded', function() {
        const likeButtons = document.querySelectorAll('.like-btn');
        const bookmarkButtons = document.querySelectorAll('.bookmark-btn');

        if (likeButtons && likeButtons.length) {
            likeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const postId = this.dataset.postId;
                    <?php if (!$current_user): ?>
                    alert('Please login to like posts');
                    return;
                    <?php endif; ?>

                    fetch('index.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: `action=toggle_like&post_id=${encodeURIComponent(postId)}`
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (!data || !data.success) return;
                            const icon = this.querySelector('i');
                            if (data.liked) {
                                this.classList.add('liked');
                                if (icon) icon.className = 'bi bi-heart-fill';
                            } else {
                                this.classList.remove('liked');
                                if (icon) icon.className = 'bi bi-heart';
                            }
                            try {
                                const container = this.closest('.post-actions');
                                if (container) {
                                    const likesSpan = container.querySelector('.bi-heart')
                                        ?.parentElement;
                                    if (likesSpan) likesSpan.innerHTML =
                                        `<i class="bi bi-heart"></i> ${data.likes_count}`;
                                }
                            } catch (e) {
                                /* ignore */
                            }
                        })
                        .catch(() => {
                            /* silent network error */
                        });
                });
            });
        }

        if (bookmarkButtons && bookmarkButtons.length) {
            bookmarkButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const postId = this.dataset.postId;
                    <?php if (!$current_user): ?>
                    alert('Please login to bookmark posts');
                    return;
                    <?php endif; ?>

                    fetch('index.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: `action=toggle_bookmark&post_id=${encodeURIComponent(postId)}`
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (!data || !data.success) return;
                            const icon = this.querySelector('i');
                            if (data.bookmarked) {
                                this.classList.add('bookmarked');
                                if (icon) icon.className = 'bi bi-bookmark-fill';
                            } else {
                                this.classList.remove('bookmarked');
                                if (icon) icon.className = 'bi bi-bookmark';
                            }
                        })
                        .catch(() => {
                            /* silent */
                        });
                });
            });
        }
    });
    </script>
</body>

</html>