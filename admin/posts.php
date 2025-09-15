<?php
/**
 * admin/posts.php
 * ----------------
 * Admin Post Management Page (Summernote integrated)
 *
 * Notes:
 *  - Summernote integrated (image upload endpoint at ?upload_summernote=1).
 *  - Keep existing logic intact, only editor changes added.
 */

require_once '../data/Functions.php';
app_start_session();

// ---------------------------
// In-file Summernote upload handler
// ---------------------------
// Summernote will POST file(s) to this same script with ?upload_summernote=1
if (isset($_GET['upload_summernote'])) {
    // Only allow logged-in admins to upload
    if (!app_is_admin_logged_in()) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }

    // Expect file field named 'file' (we'll accept both 'file' and 'upload' names)
    $inputName = null;
    if (!empty($_FILES['file'])) $inputName = 'file';
    elseif (!empty($_FILES['upload'])) $inputName = 'upload';
    elseif (!empty($_FILES['image'])) $inputName = 'image';

    if (!$inputName || empty($_FILES[$inputName])) {
        http_response_code(400);
        echo "No file uploaded.";
        exit;
    }

    $file = $_FILES[$inputName];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    // Max 5MB
    $maxSize = 5 * 1024 * 1024;

    if (!in_array($ext, $allowed)) {
        http_response_code(400);
        echo "Invalid file type.";
        exit;
    }
    if ($file['size'] > $maxSize) {
        http_response_code(400);
        echo "File too large. Max 5MB.";
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/posts/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $filename = time() . '_' . uniqid() . '.' . $ext;
    $target = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        http_response_code(500);
        echo "Upload failed.";
        exit;
    }

    // Return a URL Summernote can use. Use root-relative so front-end pages can load it.
    // Adjust this if your site uses a subfolder.
    $publicUrl = '/uploads/posts/' . $filename;
    echo $publicUrl;
    exit;
}

// ---------------------------
// Authorization check
// ---------------------------
if (!app_is_admin_logged_in()) {
    header('Location: login.php');
    exit;
}

// Current admin (used in header/profile)
$current_admin = app_get_current_admin();

// Input defaults
$action = $_GET['action'] ?? 'list';
$post_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

$success = '';
$errors = [];

/* ---------------------------
   BULK ACTIONS (POST)
   --------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = $_POST['bulk_action'];
    $selected_posts = $_POST['selected_posts'] ?? [];

    if (empty($selected_posts)) {
        $errors[] = 'Please select at least one post.';
    } else {
        switch ($bulk_action) {
            case 'delete':
                $deleted_count = 0;
                foreach ($selected_posts as $pid) {
                    $stmt = $pdo->prepare("DELETE FROM posts WHERE id = :id");
                    $stmt->bindValue(':id', (int)$pid, PDO::PARAM_INT);
                    if ($stmt->execute()) $deleted_count++;
                }
                $success = "Successfully deleted {$deleted_count} post(s).";
                break;

            case 'publish':
                $updated_count = 0;
                foreach ($selected_posts as $pid) {
                    $stmt = $pdo->prepare("UPDATE posts SET status = 'published' WHERE id = :id");
                    $stmt->bindValue(':id', (int)$pid, PDO::PARAM_INT);
                    if ($stmt->execute()) $updated_count++;
                }
                $success = "Successfully published {$updated_count} post(s).";
                break;

            case 'unpublish':
                $updated_count = 0;
                foreach ($selected_posts as $pid) {
                    $stmt = $pdo->prepare("UPDATE posts SET status = 'unpublished' WHERE id = :id");
                    $stmt->bindValue(':id', (int)$pid, PDO::PARAM_INT);
                    if ($stmt->execute()) $updated_count++;
                }
                $success = "Successfully unpublished {$updated_count} post(s).";
                break;
        }
    }
}

/* ---------------------------
   SINGLE ACTIONS (POST)
   --------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['single_action'])) {
    $single_action = $_POST['single_action'];
    $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : null;

    switch ($single_action) {
        case 'delete':
            $stmt = $pdo->prepare("DELETE FROM posts WHERE id = :id");
            $stmt->bindValue(':id', $post_id, PDO::PARAM_INT);
            if ($stmt->execute()) {
                $success = 'Post deleted successfully.';
            } else {
                $errors[] = 'Failed to delete post.';
            }
            break;

        case 'toggle_status':
            // get current status then flip
            $stmt = $pdo->prepare("SELECT status FROM posts WHERE id = :id");
            $stmt->bindValue(':id', $post_id, PDO::PARAM_INT);
            $stmt->execute();
            $post = $stmt->fetch();
            $new_status = ($post['status'] === 'published') ? 'unpublished' : 'published';

            $stmt = $pdo->prepare("UPDATE posts SET status = :status WHERE id = :id");
            $stmt->bindValue(':status', $new_status);
            $stmt->bindValue(':id', $post_id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $success = "Post {$new_status} successfully.";
            } else {
                $errors[] = 'Failed to update post status.';
            }
            break;
    }
}

/* ---------------------------
   CREATE / EDIT POST (POST)
   --------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_post'])) {
    $title = trim($_POST['title'] ?? '');
    $content = $_POST['content'] ?? ''; // Summernote content (HTML)
    $excerpt = trim($_POST['excerpt'] ?? '');
    $category_id = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
    $status = $_POST['status'] ?? 'draft';
    $edit_id = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;

    // Validation
    if ($title === '') $errors[] = 'Title is required.';
    if (trim($content) === '') $errors[] = 'Content is required.';

    if (empty($errors)) {
        $slug = app_create_slug($title);

        // ensure unique slug (exclude current post when editing)
        if ($edit_id) {
            $stmt = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug AND id != :edit_id");
            $stmt->bindValue(':slug', $slug);
            $stmt->bindValue(':edit_id', $edit_id, PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug");
            $stmt->bindValue(':slug', $slug);
        }
        $stmt->execute();
        if ($stmt->fetch()) {
            $slug = $slug . '-' . time();
        }

        // Featured image upload
        $featured_image = null;
        if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/posts';
            $featured_image = app_upload_file($_FILES['featured_image'], $upload_dir);
            if (!$featured_image) {
                $errors[] = 'Failed to upload featured image.';
            }
        }

        // Insert or update
        if (empty($errors)) {
            if ($edit_id) {
                // Update existing post
                $sql = "UPDATE posts SET title = :title, slug = :slug, content = :content, excerpt = :excerpt,
                        category_id = :category_id, status = :status";
                $params = [
                    ':title' => $title,
                    ':slug' => $slug,
                    ':content' => $content,
                    ':excerpt' => $excerpt,
                    ':category_id' => $category_id,
                    ':status' => $status
                ];

                if ($featured_image) {
                    $sql .= ", featured_image = :featured_image";
                    $params[':featured_image'] = $featured_image;
                }

                $sql .= " WHERE id = :edit_id";
                $params[':edit_id'] = $edit_id;

                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $value) {
                    if ($key === ':edit_id' || $key === ':category_id') {
                        $stmt->bindValue($key, $value, PDO::PARAM_INT);
                    } else {
                        $stmt->bindValue($key, $value);
                    }
                }

                if ($stmt->execute()) {
                    $success = 'Post updated successfully.';
                    $action = 'list';
                } else {
                    $errors[] = 'Failed to update post.';
                }
            } else {
                // Create new post
                $stmt = $pdo->prepare("INSERT INTO posts (title, slug, content, excerpt, category_id, admin_id, status, featured_image)
                                     VALUES (:title, :slug, :content, :excerpt, :category_id, :admin_id, :status, :featured_image)");
                $stmt->bindValue(':title', $title);
                $stmt->bindValue(':slug', $slug);
                $stmt->bindValue(':content', $content);
                $stmt->bindValue(':excerpt', $excerpt);
                $stmt->bindValue(':category_id', $category_id, PDO::PARAM_INT);
                $stmt->bindValue(':admin_id', $current_admin['id'], PDO::PARAM_INT);
                $stmt->bindValue(':status', $status);
                $stmt->bindValue(':featured_image', $featured_image);

                if ($stmt->execute()) {
                    $success = 'Post created successfully.';
                    $action = 'list';
                } else {
                    $errors[] = 'Failed to create post.';
                }
            }
        }
    }
}

/* ---------------------------
   DATA LAYERS: GET DATA FOR VIEWS
   --------------------------- */
switch ($action) {
    case 'list':
        $search = $_GET['search'] ?? '';
        $status_filter = $_GET['status'] ?? '';
        $category_filter = $_GET['category'] ?? '';
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $per_page = 10;
        $offset = ($page - 1) * $per_page;

        // Build where clause and params
        $where_conditions = [];
        $params = [];

        if ($search) {
            $where_conditions[] = "(p.title LIKE :search OR p.content LIKE :search)";
            $params[':search'] = "%{$search}%";
        }
        if ($status_filter) {
            $where_conditions[] = "p.status = :status";
            $params[':status'] = $status_filter;
        }
        if ($category_filter) {
            $where_conditions[] = "p.category_id = :category";
            $params[':category'] = (int)$category_filter;
        }
        $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Fetch posts
        $sql = "SELECT p.*, c.name as category_name, a.first_name, a.last_name,
                (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) as likes_count,
                (SELECT COUNT(*) FROM comments cm WHERE cm.post_id = p.id) as comments_count
                FROM posts p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN admins a ON p.admin_id = a.id
                {$where_clause}
                ORDER BY p.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll();

        // Total for pagination
        $count_sql = "SELECT COUNT(*) as total FROM posts p {$where_clause}";
        $stmt = $pdo->prepare($count_sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total_posts = (int)($stmt->fetch()['total'] ?? 0);
        $total_pages = (int)ceil($total_posts / $per_page);
        break;

    case 'create':
    case 'edit':
        // categories for select
        $categories = app_get_categories();

        // if editing, fetch post
        if ($action === 'edit' && $post_id) {
            $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = :id");
            $stmt->bindValue(':id', $post_id, PDO::PARAM_INT);
            $stmt->execute();
            $post_data = $stmt->fetch();
            if (!$post_data) {
                $errors[] = 'Post not found.';
                $action = 'list';
            }
        }
        break;

    case 'view':
        if ($post_id) {
            $stmt = $pdo->prepare("SELECT p.*, c.name as category_name, a.first_name, a.last_name,
                                  (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) as likes_count,
                                  (SELECT COUNT(*) FROM comments cm WHERE cm.post_id = p.id) as comments_count
                                  FROM posts p
                                  LEFT JOIN categories c ON p.category_id = c.id
                                  LEFT JOIN admins a ON p.admin_id = a.id
                                  WHERE p.id = :id");
            $stmt->bindValue(':id', $post_id, PDO::PARAM_INT);
            $stmt->execute();
            $post_data = $stmt->fetch();
            if (!$post_data) {
                $errors[] = 'Post not found.';
                $action = 'list';
            }
        }
        break;
}

// ---------------------------
// End PHP data layer; Begin HTML Output
// ---------------------------
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= htmlspecialchars(ucfirst($action)) ?> Posts - Admin Dashboard</title>

    <!-- Bootstrap + Icons + optional admin.css -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/admin.css" rel="stylesheet">

    <!-- Summernote Lite CSS -->
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css" rel="stylesheet">

    <style>
    /* Minimal helper styles - move to admin.css if you want */
    .post-thumbnail {
        width: 88px;
        height: 56px;
        object-fit: cover;
        border-radius: 6px;
    }

    .table-compact td,
    .table-compact th {
        padding: .5rem .75rem;
    }

    .badge-success {
        background-color: #198754 !important;
    }

    .badge-warning {
        background-color: #ffc107 !important;
        color: #212529 !important;
    }

    .badge-secondary {
        background-color: #6c757d !important;
    }

    /* Summernote editor height */
    .note-editor .note-editable {
        min-height: 320px;
    }
    </style>
</head>

<body>

    <!-- HEADER / NAV -->
    <header>
        <nav class="navbar navbar-expand-lg" aria-label="Main navigation">
            <div class="container-fluid">
                <a class="navbar-brand fw-bold" href="dashboard.php">
                    <i class="bi bi-journal-bookmark"></i> MyBlog Admin
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                    aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="mainNav">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-house"></i>
                                Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link active" href="posts.php"><i class="bi bi-file-post"></i>
                                Posts</a></li>
                        <li class="nav-item"><a class="nav-link" href="categories.php"><i class="bi bi-folder"></i>
                                Categories</a></li>
                        <li class="nav-item"><a class="nav-link" href="comments.php"><i class="bi bi-chat-dots"></i>
                                Comments</a></li>
                        <li class="nav-item"><a class="nav-link" href="users.php"><i class="bi bi-people"></i> Users</a>
                        </li>
                        <li class="nav-item"><a class="nav-link" href="profile.php"><i class="bi bi-person-gear"></i>
                                Profile</a></li>
                        <li class="nav-item"><a class="nav-link" href="../index.php" target="_blank"><i
                                    class="bi bi-box-arrow-up-right"></i> View Site</a></li>
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

    <!-- MAIN -->
    <main class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <!-- Alerts: errors and success (auto-dismiss enabled) -->
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger auto-dismiss" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success auto-dismiss" role="alert">
                    <?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- LIST PAGE -->
        <?php if ($action === 'list'): ?>
        <div class="row align-items-center mb-3">
            <div class="col-md-8">
                <h2 class="h4 mb-0"><i class="bi bi-file-post me-2"></i> Manage Posts</h2>
                <div class="text-muted small">Manage, edit and publish your posts</div>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="?action=create" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Create New
                    Post</a>
            </div>
        </div>

        <!-- Filters -->
        <section class="mb-4">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="list">
                <div class="col-sm-12 col-md-4">
                    <label class="form-label visually-hidden">Search</label>
                    <input type="text" class="form-control" name="search" placeholder="Search posts..."
                        value="<?= htmlspecialchars($search ?? '') ?>">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label visually-hidden">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="published" <?= ($status_filter ?? '') === 'published' ? 'selected' : '' ?>>
                            Published</option>
                        <option value="unpublished" <?= ($status_filter ?? '') === 'unpublished' ? 'selected' : '' ?>>
                            Unpublished</option>
                        <option value="draft" <?= ($status_filter ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                    </select>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label visually-hidden">Category</label>
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php
                            $filter_categories = app_get_categories();
                            foreach ($filter_categories as $cat):
                        ?>
                        <option value="<?= (int)$cat['id'] ?>"
                            <?= (isset($category_filter) && $category_filter == $cat['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i> Filter</button>
                </div>
            </form>
        </section>

        <!-- Bulk actions + posts table -->
        <form method="POST" id="bulkForm" class="mb-3">
            <div class="row g-2 align-items-center mb-2">
                <div class="col-sm-12 col-md-4">
                    <select name="bulk_action" class="form-select" required>
                        <option value="">Bulk Actions</option>
                        <option value="delete">Delete Selected</option>
                        <option value="publish">Publish Selected</option>
                        <option value="unpublish">Unpublish Selected</option>
                    </select>
                </div>
                <div class="col-sm-6 col-md-3 d-grid">
                    <button type="submit" class="btn btn-warning" onclick="return confirmBulkAction()"><i
                            class="bi bi-gear me-1"></i> Apply</button>
                </div>
                <div class="col-sm-6 col-md-5 text-end small text-muted">
                    Total Posts: <?= (int)($total_posts ?? 0) ?> &nbsp;|&nbsp; Showing: <?= count($posts ?? []) ?>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="table table-dark table-hover table-compact mb-0 align-middle">
                        <thead>
                            <tr>
                                <th style="width:36px;"><input type="checkbox" id="selectAll" class="form-check-input">
                                </th>
                                <th style="width:92px;">Image</th>
                                <th>Title</th>
                                <th style="width:140px;">Category</th>
                                <th style="width:160px;">Author</th>
                                <th style="width:110px;">Status</th>
                                <th style="width:110px;">Stats</th>
                                <th style="width:120px;">Date</th>
                                <th style="width:150px;">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($posts)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <i class="bi bi-file-post display-1 text-muted"></i>
                                    <p class="text-muted mt-3">No posts found.</p>
                                    <a href="?action=create" class="btn btn-primary"><i
                                            class="bi bi-plus-circle me-1"></i> Create Your First Post</a>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($posts as $post): ?>
                            <?php
                                        $postStatus = $post['status'] ?? 'draft';
                                        $badgeClass = 'badge-secondary';
                                        if ($postStatus === 'published') $badgeClass = 'badge-success';
                                        elseif ($postStatus === 'unpublished') $badgeClass = 'badge-warning';
                                        elseif ($postStatus === 'draft') $badgeClass = 'badge-secondary';
                                    ?>
                            <tr>
                                <td><input type="checkbox" name="selected_posts[]" value="<?= (int)$post['id'] ?>"
                                        class="form-check-input post-checkbox"></td>

                                <td>
                                    <?php if (!empty($post['featured_image'])): ?>
                                    <img src="../uploads/posts/<?= htmlspecialchars($post['featured_image']) ?>"
                                        alt="Thumb" class="post-thumbnail">
                                    <?php else: ?>
                                    <div
                                        class="post-thumbnail bg-secondary d-flex align-items-center justify-content-center text-muted">
                                        <i class="bi bi-image"></i>
                                    </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="fw-semibold">
                                        <?= htmlspecialchars(app_truncate_text($post['title'], 60)) ?></div>
                                    <div class="text-muted small">
                                        <?= htmlspecialchars(app_truncate_text(strip_tags($post['excerpt'] ?: $post['content']), 100)) ?>
                                    </div>
                                </td>

                                <td><span
                                        class="badge bg-info"><?= htmlspecialchars($post['category_name'] ?? 'Uncategorized') ?></span>
                                </td>

                                <td><?= htmlspecialchars(trim($post['first_name'] . ' ' . $post['last_name'])) ?></td>

                                <td><span class="badge <?= $badgeClass ?>"><?= ucfirst($postStatus) ?></span></td>

                                <td>
                                    <small class="d-block"><i
                                            class="bi bi-eye me-1"></i><?= (int)($post['views'] ?? 0) ?></small>
                                    <small class="d-block"><i
                                            class="bi bi-heart me-1"></i><?= (int)($post['likes_count'] ?? 0) ?></small>
                                    <small class="d-block"><i
                                            class="bi bi-chat me-1"></i><?= (int)($post['comments_count'] ?? 0) ?></small>
                                </td>

                                <td>
                                    <small><?= !empty($post['created_at']) ? date('M j, Y', strtotime($post['created_at'])) : '—' ?></small><br>
                                    <small
                                        class="text-muted"><?= !empty($post['created_at']) ? date('H:i', strtotime($post['created_at'])) : '' ?></small>
                                </td>

                                <td>
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Actions">
                                        <a href="?action=view&id=<?= (int)$post['id'] ?>" class="btn btn-outline-info"
                                            title="View"><i class="bi bi-eye"></i></a>
                                        <a href="?action=edit&id=<?= (int)$post['id'] ?>"
                                            class="btn btn-outline-warning" title="Edit"><i
                                                class="bi bi-pencil"></i></a>
                                        <button type="button" class="btn btn-outline-success"
                                            onclick="toggleStatus(<?= (int)$post['id'] ?>)" title="Toggle Status">
                                            <i
                                                class="bi bi-toggle-<?= $postStatus === 'published' ? 'on' : 'off' ?>"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger"
                                            onclick="deletePost(<?= (int)$post['id'] ?>)" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>

        <!-- Pagination -->
        <?php if (!empty($total_pages) && $total_pages > 1): ?>
        <nav class="mt-4" aria-label="Posts pagination">
            <ul class="pagination justify-content-center flex-wrap">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link"
                        href="?action=list&page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?><?= $category_filter ? '&category=' . urlencode($category_filter) : '' ?>">Previous</a>
                </li>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link"
                        href="?action=list&page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?><?= $category_filter ? '&category=' . urlencode($category_filter) : '' ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link"
                        href="?action=list&page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?><?= $category_filter ? '&category=' . urlencode($category_filter) : '' ?>">Next</a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>

        <!-- CREATE / EDIT PAGE -->
        <?php elseif ($action === 'create' || $action === 'edit'): ?>
        <div class="row align-items-center mb-3">
            <div class="col-md-8">
                <h2 class="h4 mb-0"><i
                        class="bi bi-<?= $action === 'create' ? 'plus-circle' : 'pencil' ?> me-2"></i><?= htmlspecialchars(ucfirst($action)) ?>
                    Post</h2>
                <div class="small text-muted">Write and publish a new post or update an existing one.</div>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="?action=list" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back to
                    Posts</a>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?php if ($action === 'edit'): ?>
            <input type="hidden" name="edit_id" value="<?= (int)($post_data['id'] ?? 0) ?>">
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Post Content</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3 title-container">
                                <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                                <input id="title" name="title" class="form-control" type="text" required
                                    value="<?= htmlspecialchars($post_data['title'] ?? '') ?>">
                            </div>

                            <div class="mb-3 excerpt-container">
                                <label for="excerpt" class="form-label">Excerpt</label>
                                <textarea id="excerpt" name="excerpt" rows="3"
                                    class="form-control"><?= htmlspecialchars($post_data['excerpt'] ?? '') ?></textarea>
                                <div class="form-text">Optional. Short summary shown in listings.</div>
                            </div>

                            <div class="mb-3">
                                <label for="content" class="form-label">Content <span
                                        class="text-danger">*</span></label>
                                <textarea id="content" name="content"
                                    required><?= htmlspecialchars($post_data['content'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <aside class="col-lg-4">
                    <div class="card mb-3 post-info">
                        <div class="card-header">
                            <h5 class="mb-0">Publish</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select id="status" name="status" class="form-select" required>
                                    <option value="draft"
                                        <?= ($post_data['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft
                                    </option>
                                    <option value="published"
                                        <?= ($post_data['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published
                                    </option>
                                    <option value="unpublished"
                                        <?= ($post_data['status'] ?? '') === 'unpublished' ? 'selected' : '' ?>>
                                        Unpublished</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="category_id" class="form-label">Category</label>
                                <select id="category_id" name="category_id" class="form-select">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int)$category['id'] ?>"
                                        <?= (isset($post_data['category_id']) && $post_data['category_id'] == $category['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($category['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" name="save_post" class="btn btn-primary"><i
                                        class="bi bi-save me-1"></i><?= $action === 'create' ? 'Create Post' : 'Update Post' ?></button>
                                <?php if ($action === 'edit'): ?>
                                <a href="?action=view&id=<?= (int)$post_data['id'] ?>" class="btn btn-outline-info"><i
                                        class="bi bi-eye me-1"></i> Preview</a>
                                <?php endif; ?>
                                <a href="?action=list" class="btn btn-outline-secondary"><i class="bi bi-x me-1"></i>
                                    Cancel</a>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-header">
                            <h5 class="mb-0">Featured Image</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($action === 'edit' && !empty($post_data['featured_image'])): ?>
                            <div class="mb-3">
                                <img src="../uploads/posts/<?= htmlspecialchars($post_data['featured_image']) ?>"
                                    alt="Featured" class="img-fluid rounded mb-2">
                                <div class="form-text">Current featured image.</div>
                            </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <input id="featured_image" name="featured_image" type="file" accept="image/*"
                                    class="form-control">
                                <div class="form-text">Recommended: 1200×600px — JPG/PNG</div>
                            </div>
                        </div>
                    </div>

                    <?php if ($action === 'edit'): ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Post Information</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless mb-0">
                                <tbody>
                                    <tr>
                                        <td class="text-muted small" style="width:40%;">Created</td>
                                        <td><?= date('F j, Y \a\t H:i', strtotime($post_data['created_at'])) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted small">Updated</td>
                                        <td><?= date('F j, Y \a\t H:i', strtotime($post_data['updated_at'])) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted small">Views</td>
                                        <td><?= number_format($post_data['views'] ?? 0) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted small">Slug</td>
                                        <td><code><?= htmlspecialchars($post_data['slug']) ?></code></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </aside>
            </div>
        </form>

        <!-- VIEW PAGE -->
        <?php elseif ($action === 'view' && !empty($post_data)): ?>
        <div class="row mb-3">
            <div class="col-md-8">
                <h2 class="h4 mb-0"><i class="bi bi-eye me-2"></i> View Post</h2>
                <div class="small text-muted">Preview post and manage status / actions.</div>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="?action=edit&id=<?= (int)$post_data['id'] ?>" class="btn btn-warning me-2"><i
                        class="bi bi-pencil me-1"></i> Edit Post</a>
                <a href="?action=list" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <article class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <?php
                                $viewBadgeClass = 'badge-secondary';
                                if ($post_data['status'] === 'published') $viewBadgeClass = 'badge-success';
                                elseif ($post_data['status'] === 'unpublished') $viewBadgeClass = 'badge-warning';
                            ?>
                            <span class="badge <?= $viewBadgeClass ?> me-2"><?= ucfirst($post_data['status']) ?></span>
                            <?php if (!empty($post_data['category_name'])): ?>
                            <span class="badge bg-info"><?= htmlspecialchars($post_data['category_name']) ?></span>
                            <?php endif; ?>
                        </div>
                        <small
                            class="text-muted"><?= date('F j, Y \a\t H:i', strtotime($post_data['created_at'])) ?></small>
                    </div>

                    <div class="card-body">
                        <?php if (!empty($post_data['featured_image'])): ?>
                        <img src="../uploads/posts/<?= htmlspecialchars($post_data['featured_image']) ?>"
                            alt="<?= htmlspecialchars($post_data['title']) ?>" class="img-fluid rounded mb-4">
                        <?php endif; ?>

                        <h1 class="h3 mb-3"><?= htmlspecialchars($post_data['title']) ?></h1>

                        <?php if (!empty($post_data['excerpt'])): ?>
                        <div class="alert alert-info"><strong>Excerpt:</strong>
                            <?= htmlspecialchars($post_data['excerpt']) ?></div>
                        <?php endif; ?>

                        <div class="post-content"><?= $post_data['content'] ?></div>
                    </div>

                    <div class="card-footer">
                        <div class="row text-center">
                            <div class="col">
                                <i class="bi bi-eye display-6"></i>
                                <div><small>Views</small></div>
                                <div><strong><?= number_format($post_data['views'] ?? 0) ?></strong></div>
                            </div>
                            <div class="col">
                                <i class="bi bi-heart display-6"></i>
                                <div><small>Likes</small></div>
                                <div><strong><?= (int)($post_data['likes_count'] ?? 0) ?></strong></div>
                            </div>
                            <div class="col">
                                <i class="bi bi-chat display-6"></i>
                                <div><small>Comments</small></div>
                                <div><strong><?= (int)($post_data['comments_count'] ?? 0) ?></strong></div>
                            </div>
                        </div>
                    </div>
                </article>
            </div>

            <aside class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">Post Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="?action=edit&id=<?= (int)$post_data['id'] ?>" class="btn btn-warning"><i
                                    class="bi bi-pencil me-1"></i> Edit Post</a>
                            <button type="button" class="btn btn-success"
                                onclick="toggleStatus(<?= (int)$post_data['id'] ?>)"><i
                                    class="bi bi-toggle-<?= $post_data['status'] === 'published' ? 'on' : 'off' ?> me-1"></i><?= $post_data['status'] === 'published' ? 'Unpublish' : 'Publish' ?>
                                Post</button>
                            <a href="../post.php?slug=<?= urlencode($post_data['slug']) ?>" target="_blank"
                                class="btn btn-info"><i class="bi bi-box-arrow-up-right me-1"></i> View on Site</a>
                            <button type="button" class="btn btn-danger"
                                onclick="deletePost(<?= (int)$post_data['id'] ?>)"><i class="bi bi-trash me-1"></i>
                                Delete Post</button>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Post Details</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <td class="text-muted small">Author</td>
                                    <td><?= htmlspecialchars($post_data['first_name'] . ' ' . $post_data['last_name']) ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted small">Status</td>
                                    <td><span
                                            class="badge <?= $viewBadgeClass ?>"><?= ucfirst($post_data['status']) ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted small">Category</td>
                                    <td><?= htmlspecialchars($post_data['category_name'] ?? 'Uncategorized') ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted small">Created</td>
                                    <td><?= date('M j, Y H:i', strtotime($post_data['created_at'])) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted small">Updated</td>
                                    <td><?= date('M j, Y H:i', strtotime($post_data['updated_at'])) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted small">Slug</td>
                                    <td><code><?= htmlspecialchars($post_data['slug']) ?></code></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </aside>
        </div>
        <?php endif; ?>
    </main>

    <!-- Hidden single-action form (used by JS helpers) -->
    <form id="singleActionForm" method="POST" style="display:none;">
        <input type="hidden" name="single_action" id="singleAction">
        <input type="hidden" name="post_id" id="singlePostId">
    </form>

    <!-- jQuery (required by Summernote) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Summernote Lite JS -->
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js"></script>

    <!-- Editor init + page scripts -->
    <?php if ($action === 'create' || $action === 'edit'): ?>
    <script>
    (function() {
        'use strict';

        // Helper: returns current editor content (Summernote)
        function getEditorData() {
            try {
                if (window.$ && $('#content').length && $('#content').summernote) {
                    return $('#content').summernote('code');
                }
            } catch (_) {}
            // fallback to textarea value
            return document.getElementById('content')?.value || '';
        }

        // Initialize Summernote on the #content textarea
        function initSummernote() {
            if (!window.$ || !$('#content').length) return;

            $('#content').summernote({
                placeholder: 'Write your article here...',
                tabsize: 2,
                height: 320,
                toolbar: [
                    // Customize toolbar as needed
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
                    ['fontname', ['fontname']],
                    ['fontsize', ['fontsize']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['insert', ['link', 'picture', 'video', 'table']],
                    ['view', ['fullscreen', 'codeview', 'help']],
                ],
                callbacks: {
                    onImageUpload: function(files) {
                        // upload each file
                        for (let i = 0; i < files.length; i++) {
                            uploadImage(files[i]);
                        }
                    }
                }
            });

            // Ensure the editor content is set from server-rendered content (textarea had content)
            // Summernote automatically reads textarea content on init.
        }

        // Upload image to server endpoint in this file
        function uploadImage(file) {
            var data = new FormData();
            data.append('file', file);

            // Posts to this same php file with query param to trigger handler
            $.ajax({
                url: window.location.pathname + '?upload_summernote=1',
                type: 'POST',
                data: data,
                contentType: false,
                processData: false,
                success: function(url) {
                    // Summernote expects the image URL; insert into editor
                    try {
                        $('#content').summernote('insertImage', url);
                    } catch (e) {
                        console.error('Insert image error:', e);
                    }
                },
                error: function(xhr) {
                    var msg = xhr.responseText || 'Upload failed';
                    alert('Image upload failed: ' + msg);
                }
            });
        }

        // Sync: ensure textarea contains latest editor HTML before submit
        function ensureSyncOnSubmit() {
            const form = document.querySelector("form[enctype='multipart/form-data']") || document.querySelector('form');
            if (!form) return;
            form.addEventListener('submit', function(e) {
                try {
                    if (window.$ && $('#content').length && $('#content').summernote) {
                        const html = $('#content').summernote('code');
                        document.getElementById('content').value = html;
                    }
                } catch (err) {
                    console.error('Sync error', err);
                }
            }, { capture: true });
        }

        document.addEventListener('DOMContentLoaded', function() {
            initSummernote();
            ensureSyncOnSubmit();
        });
    })();
    </script>
    <?php endif; ?>

    <script>
    (function() {
        'use strict';

        // Auto-dismiss alerts after 4 seconds
        function initAutoDismissAlerts() {
            setTimeout(function() {
                document.querySelectorAll('.alert.auto-dismiss').forEach(function(el) {
                    try {
                        bootstrap.Alert.getOrCreateInstance(el).close();
                    } catch (e) {
                        el.remove();
                    }
                });
            }, 4000);
        }
        document.addEventListener('DOMContentLoaded', initAutoDismissAlerts);

        // Select all / row checkboxes
        document.addEventListener('DOMContentLoaded', function() {
            const master = document.getElementById('selectAll');

            function getRowBoxes() {
                return Array.from(document.querySelectorAll('.post-checkbox'));
            }

            function updateMasterState() {
                const boxes = getRowBoxes();
                if (!master || !boxes.length) return;
                const checkedCount = boxes.filter(b => b.checked).length;
                master.checked = checkedCount === boxes.length && boxes.length > 0;
                master.indeterminate = checkedCount > 0 && checkedCount < boxes.length;
            }
            if (master) {
                master.addEventListener('change', function() {
                    getRowBoxes().forEach(cb => cb.checked = master.checked);
                    updateMasterState();
                });
            }
            document.addEventListener('change', function(e) {
                if (e.target && e.target.classList && e.target.classList.contains('post-checkbox'))
                    updateMasterState();
            });
            updateMasterState();
        });

        // Bulk action confirmation
        window.confirmBulkAction = function() {
            const selected = document.querySelectorAll('.post-checkbox:checked');
            const action = document.querySelector('[name="bulk_action"]')?.value || '';
            if (selected.length === 0) {
                alert('Please select at least one post.');
                return false;
            }
            if (!action) {
                alert('Please select an action.');
                return false;
            }
            const actionText = action === 'delete' ? 'delete' : action;
            return confirm(`Are you sure you want to ${actionText} ${selected.length} post(s)?`);
        };

        // Single actions helpers
        function setAndSubmitSingle(action, postId) {
            const form = document.getElementById('singleActionForm');
            if (!form) return;
            document.getElementById('singleAction').value = action;
            document.getElementById('singlePostId').value = postId;
            form.submit();
        }

        window.toggleStatus = function(postId) {
            if (confirm('Are you sure you want to change the status of this post?')) {
                setAndSubmitSingle('toggle_status', postId);
            }
        };

        window.deletePost = function(postId) {
            if (confirm('Are you sure you want to delete this post? This action cannot be undone.')) {
                setAndSubmitSingle('delete', postId);
            }
        };

        // Auto-save logic for create/edit (keeps your current implementation, adapted to Summernote)
        <?php if ($action === 'create' || $action === 'edit'): ?>
        let autoSaveTimer = null;
        const autoSaveInterval = 60 * 1000;
        let lastSignature = '';

        function currentSignature() {
            const title = document.getElementById('title')?.value || '';
            const excerpt = document.getElementById('excerpt')?.value || '';
            const category = document.getElementById('category_id')?.value || '';
            const content = (window.$ && $('#content').length && $('#content').summernote) ? $('#content').summernote('code') : (document.getElementById('content')?.value || '');
            return [title, excerpt, category, content].join('||');
        }

        function autoSaveDraft() {
            const sig = currentSignature();
            if (sig === lastSignature) return;
            lastSignature = sig;
            if (true) {
                // placeholder for AJAX auto-save
                console.log('Auto-save triggered');
            }
        }

        function startAutoSave() {
            stopAutoSave();
            autoSaveTimer = setInterval(autoSaveDraft, autoSaveInterval);
        }
        window.stopAutoSave = function() {
            if (autoSaveTimer) {
                clearInterval(autoSaveTimer);
                autoSaveTimer = null;
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            const titleInput = document.getElementById('title');
            const excerptInput = document.getElementById('excerpt');
            const categoryInput = document.getElementById('category_id');
            [titleInput, excerptInput, categoryInput].forEach(el => {
                if (el) el.addEventListener('input', startAutoSave);
            });
            const ensureEditorHook = setInterval(() => {
                // for Summernote, listen to input events via callback above or use this interval
                if (window.$ && $('#content').length && $('#content').summernote) {
                    // we can trigger auto-save on summernote.change
                    try {
                        $('#content').on('summernote.change', function(we, contents, $editable) {
                            startAutoSave();
                        });
                    } catch (_) {}
                    clearInterval(ensureEditorHook);
                }
            }, 300);
            startAutoSave();
        });

        document.addEventListener('visibilitychange', function() {
            if (document.hidden) stopAutoSave();
            else startAutoSave();
        });
        window.addEventListener('beforeunload', stopAutoSave);
        <?php endif; ?>
    })();
    </script>
</body>

</html>
