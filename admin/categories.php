<?php
/**
 * admin/categories.php
 *
 * Admin panel — Category management page
 * - List, create, edit, delete categories
 * - Bulk delete support (moves posts to "Uncategorized" id=1)
 * - Uses prepared statements (PDO)
 * - Expects Functions.php to provide: app_start_session(), app_is_admin_logged_in(),
 *   app_get_current_admin(), app_create_slug(), app_truncate_text(), app_time_elapsed_string()
 *
 * Notes:
 * - This file intentionally preserves your server-side logic and behavior.
 * - Client-side: Bootstrap CSS/JS expected (we include via CDN in <head> and before </body>).
 * - Alerts auto-dismiss after 6s but remain dismissible by the user.
 */

/* --------------------------
   Bootstrap / app bootstrap
   -------------------------- */
require_once '../data/Functions.php';
app_start_session();

/* --------------------------
   Ensure DB connection ($pdo)
   -------------------------- */
if (!isset($pdo)) {
    // If your Functions.php always sets $pdo, you may remove this guard.
    throw new Exception('Database connection ($pdo) not found. Make sure Functions.php provides $pdo.');
}

/* --------------------------
   Authentication
   -------------------------- */
if (!app_is_admin_logged_in()) {
    header('Location: login.php');
    exit;
}

/* --------------------------
   Initial variables
   -------------------------- */
$current_admin = app_get_current_admin();
$action = $_GET['action'] ?? 'list';
$category_id = $_GET['id'] ?? null;

$success = '';
$errors = [];

/* ==========================
   POST HANDLERS
   ========================== */

/* --------------------------
   Bulk actions handler
   -------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = $_POST['bulk_action'];
    $selected_categories = $_POST['selected_categories'] ?? [];

    if (empty($selected_categories) || !is_array($selected_categories)) {
        $errors[] = 'Please select at least one category.';
    } else {
        switch ($bulk_action) {
            case 'delete':
                $deleted_count = 0;
                foreach ($selected_categories as $cid) {
                    $cid = (int)$cid;

                    // Check if category is deletable
                    $stmt = $pdo->prepare("SELECT is_deletable FROM categories WHERE id = :id");
                    $stmt->bindValue(':id', $cid, PDO::PARAM_INT);
                    $stmt->execute();
                    $category = $stmt->fetch();

                    if ($category && $category['is_deletable']) {
                        // Move posts to uncategorized (id = 1) before deleting
                        $stmt = $pdo->prepare("UPDATE posts SET category_id = 1 WHERE category_id = :id");
                        $stmt->bindValue(':id', $cid, PDO::PARAM_INT);
                        $stmt->execute();

                        // Delete category
                        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = :id");
                        $stmt->bindValue(':id', $cid, PDO::PARAM_INT);
                        if ($stmt->execute()) {
                            $deleted_count++;
                        }
                    }
                }
                $success = "Successfully deleted {$deleted_count} category(ies).";
                break;

            default:
                $errors[] = 'Unknown bulk action.';
                break;
        }
    }
}

/* --------------------------
   Single actions handler
   -------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['single_action'])) {
    $single_action = $_POST['single_action'];
    $posted_cat_id = (int)($_POST['category_id'] ?? 0);

    switch ($single_action) {
        case 'delete':
            // Check if category is deletable
            $stmt = $pdo->prepare("SELECT is_deletable FROM categories WHERE id = :id");
            $stmt->bindValue(':id', $posted_cat_id, PDO::PARAM_INT);
            $stmt->execute();
            $category = $stmt->fetch();

            if ($category && $category['is_deletable']) {
                // Move posts to uncategorized (id = 1) before deleting
                $stmt = $pdo->prepare("UPDATE posts SET category_id = 1 WHERE category_id = :id");
                $stmt->bindValue(':id', $posted_cat_id, PDO::PARAM_INT);
                $stmt->execute();

                // Delete category
                $stmt = $pdo->prepare("DELETE FROM categories WHERE id = :id");
                $stmt->bindValue(':id', $posted_cat_id, PDO::PARAM_INT);
                if ($stmt->execute()) {
                    $success = 'Category deleted successfully.';
                } else {
                    $errors[] = 'Failed to delete category.';
                }
            } else {
                $errors[] = 'This category cannot be deleted.';
            }
            break;
    }
}

/* --------------------------
   Create / Edit handler
   -------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $edit_id = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;

    // Validation
    if ($name === '') {
        $errors[] = 'Category name is required.';
    }

    if (empty($errors)) {
        $slug = app_create_slug($name);

        // Check for slug existence (exclude current category if editing)
        if ($edit_id) {
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE slug = :slug AND id != :edit_id");
            $stmt->bindValue(':slug', $slug);
            $stmt->bindValue(':edit_id', $edit_id, PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE slug = :slug");
            $stmt->bindValue(':slug', $slug);
        }
        $stmt->execute();

        if ($stmt->fetch()) {
            $slug = $slug . '-' . time(); // make unique
        }

        if ($edit_id) {
            // Update existing category
            $stmt = $pdo->prepare("UPDATE categories SET name = :name, slug = :slug, description = :description WHERE id = :edit_id");
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':slug', $slug);
            $stmt->bindValue(':description', $description);
            $stmt->bindValue(':edit_id', $edit_id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $success = 'Category updated successfully.';
                // Refresh category_data for edit view if needed
                $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
                $stmt->bindValue(':id', $edit_id, PDO::PARAM_INT);
                $stmt->execute();
                $category_data = $stmt->fetch();
                $action = 'edit';
            } else {
                $errors[] = 'Failed to update category.';
            }
        } else {
            // Create new category
            $stmt = $pdo->prepare("INSERT INTO categories (name, slug, description, is_deletable) VALUES (:name, :slug, :description, TRUE)");
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':slug', $slug);
            $stmt->bindValue(':description', $description);

            if ($stmt->execute()) {
                $success = 'Category created successfully.';
                $action = 'list';
            } else {
                $errors[] = 'Failed to create category.';
            }
        }
    }
}

/* ==========================
   DATA FETCHING: list / edit / create
   ========================== */
switch ($action) {
    case 'list':
        $search = trim((string)($_GET['search'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = 10;
        $offset = ($page - 1) * $per_page;

        // Build where clause
        $where_conditions = [];
        $params = [];

        if ($search !== '') {
            $where_conditions[] = "(c.name LIKE :search OR c.description LIKE :search)";
            $params[':search'] = "%{$search}%";
        }

        $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Get categories with published post count
        $sql = "SELECT c.*, COUNT(p.id) as post_count
                FROM categories c
                LEFT JOIN posts p ON c.id = p.category_id AND p.status = 'published'
                {$where_clause}
                GROUP BY c.id
                ORDER BY c.name
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $categories = $stmt->fetchAll();

        // Total for pagination
        $count_sql = "SELECT COUNT(*) as total FROM categories c {$where_clause}";
        $stmt = $pdo->prepare($count_sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total_categories = (int)$stmt->fetch()['total'];
        $total_pages = (int)ceil($total_categories / $per_page);
        break;

    case 'create':
        // nothing special to fetch
        break;

    case 'edit':
        if ($category_id) {
            $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
            $stmt->bindValue(':id', $category_id, PDO::PARAM_INT);
            $stmt->execute();
            $category_data = $stmt->fetch();

            if (!$category_data) {
                $errors[] = 'Category not found.';
                $action = 'list';
                // fall through to list handling in next request
            }
        } else {
            $errors[] = 'No category specified for editing.';
            $action = 'list';
        }
        break;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <!--
      Head: includes Bootstrap CSS first, then admin.css so your custom theme overrides Bootstrap.
      Page expects Bootstrap JS bundle (used for collapse, alerts) to be loaded before </body>.
    -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars(ucfirst($action)) ?> Categories - Admin Dashboard</title>

    <!-- Load Bootstrap CSS (recommended) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Load your theme AFTER bootstrap so it can override colors, spacing, etc -->
    <link href="../assets/css/admin.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />

    <!-- Inline styles for alert animation (keeps theme file untouched) -->
    <style>
    /* Add subtle transition for manual removal fallback (Bootstrap handles fade if .fade is present) */
    .js-auto-dismiss {
        transition: opacity .32s ease, transform .32s ease;
    }
    </style>
</head>

<body>
    <!-- =========================
         HEADER / NAVBAR
         - Uses Bootstrap navbar classes; admin.css provides theming
         ========================= -->
    <header>
        <nav class="navbar navbar-expand-lg" aria-label="Main navigation">
            <div class="container-fluid">
                <!-- Brand -->
                <a class="navbar-brand fw-bold" href="dashboard.php">
                    <i class="bi bi-journal-bookmark"></i> MyBlog Admin
                </a>

                <!-- Mobile toggle -->
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                    aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <!-- Collapsible content -->
                <div class="collapse navbar-collapse" id="mainNav">
                    <!-- Left links -->
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-house"></i>
                                Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="posts.php"><i class="bi bi-file-post"></i>
                                Posts</a></li>
                        <li class="nav-item"><a class="nav-link active" href="categories.php"><i
                                    class="bi bi-folder"></i> Categories</a></li>
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

                    <!-- Right user info -->
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item d-flex align-items-center">
                            <img src="../uploads/admins/<?= htmlspecialchars($current_admin['profile_photo'] ?? 'default-admin.png') ?>"
                                alt="<?= htmlspecialchars($current_admin['first_name'] . ' ' . $current_admin['last_name']) ?>"
                                class="rounded-circle me-2" style="width:32px;height:32px;object-fit:cover;">
                            <span class="nav-link mb-0">
                                <?= htmlspecialchars($current_admin['first_name'] . ' ' . $current_admin['last_name']) ?>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
    </header>

    <!-- =========================
         MAIN CONTENT
         Container for list / create / edit views
         ========================= -->
    <main class="container-fluid py-4">
        <div class="main-content container">

            <!-- =========================
                 Alerts (errors & success)
                 - Alerts are dismissible and will auto-dismiss after 6s.
                 - We add the Bootstrap classes: alert-dismissible, fade, show
                 ========================= -->
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show js-auto-dismiss" role="alert" aria-live="polite">
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show js-auto-dismiss" role="alert"
                aria-live="polite">
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <?= htmlspecialchars($success) ?>
            </div>
            <?php endif; ?>

            <!-- =========================
                 LIST VIEW: show categories, search, bulk actions
                 ========================= -->
            <?php if ($action === 'list'): ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-folder"></i> Manage Categories</h2>
                <a href="?action=create" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Create New Category
                </a>
            </div>

            <!-- Search -->
            <div class="search-filters mb-4">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="action" value="list" />
                    <div class="col-md-8">
                        <input type="text" class="form-control" name="search" placeholder="Search categories..."
                            value="<?= htmlspecialchars($search ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Search
                        </button>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <form method="POST" id="bulkForm">
                <div class="bulk-actions mb-3">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <select name="bulk_action" class="form-select" required>
                                <option value="">Bulk Actions</option>
                                <option value="delete">Delete Selected</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-warning" onclick="return confirmBulkAction();">
                                <i class="bi bi-gear"></i> Apply
                            </button>
                        </div>
                        <div class="col-md-5 text-end">
                            <small class="text-muted">
                                Total Categories: <?= isset($total_categories) ? (int)$total_categories : 0 ?> |
                                Showing: <?= isset($categories) ? count($categories) : 0 ?> categories
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Categories Table -->
                <div class="card">
                    <div class="table-responsive">
                            <table class="table table-dark table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th width="30">
                                        <input type="checkbox" id="selectAll" class="form-check-input">
                                    </th>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th width="80">Posts</th>
                                    <th width="100">Status</th>
                                    <th width="120">Created</th>
                                    <th width="150">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($categories)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <i class="bi bi-folder display-1 text-muted"></i>
                                        <p class="text-muted mt-3">No categories found.</p>
                                        <a href="?action=create" class="btn btn-primary">
                                            <i class="bi bi-plus-circle"></i> Create Your First Category
                                        </a>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($categories as $category): ?>
                                <?php
                                                // Normalize values for safety
                                                $cat_id = (int)$category['id'];
                                                $cat_name = $category['name'] ?? '';
                                                $cat_slug = $category['slug'] ?? '';
                                                $cat_desc = $category['description'] ?? '';
                                                $cat_post_count = (int)($category['post_count'] ?? 0);
                                                $cat_deletable = !empty($category['is_deletable']);
                                            ?>
                                <tr>
                                    <td>
                                        <?php if ($cat_deletable): ?>
                                        <input type="checkbox" name="selected_categories[]" value="<?= $cat_id ?>"
                                            class="form-check-input category-checkbox">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?= htmlspecialchars($cat_name) ?></strong>
                                            <?php if (!$cat_deletable): ?>
                                            <span class="badge bg-secondary ms-1">Protected</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">Slug: <?= htmlspecialchars($cat_slug) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($cat_desc): ?>
                                        <?= htmlspecialchars(app_truncate_text($cat_desc, 100)) ?>
                                        <?php else: ?>
                                        <em class="text-muted">No description</em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-info"><?= $cat_post_count ?></span>
                                    </td>
                                    <td>
                                        <?php if ($cat_deletable): ?>
                                        <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-warning">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            <?= date('M j, Y', strtotime($category['created_at'])) ?><br>
                                            <span
                                                class="text-muted"><?= app_time_elapsed_string($category['created_at']) ?></span>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="?action=edit&id=<?= $cat_id ?>" class="btn btn-outline-warning"
                                                title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="../index.php?category=<?= $cat_id ?>" target="_blank"
                                                class="btn btn-outline-info" title="View Posts">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($cat_deletable): ?>
                                            <button type="button" class="btn btn-outline-danger"
                                                onclick="deleteCategory(<?= $cat_id ?>)" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            <?php else: ?>
                                            <button type="button" class="btn btn-outline-secondary" disabled
                                                title="Cannot delete system category">
                                                <i class="bi bi-lock"></i>
                                            </button>
                                            <?php endif; ?>
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
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link"
                            href="?action=list&page=<?= $page - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">Previous</a>
                    </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link"
                            href="?action=list&page=<?= $i ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link"
                            href="?action=list&page=<?= $page + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">Next</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>

            <!-- =========================
                 CREATE / EDIT VIEW
                 ========================= -->
            <?php elseif ($action === 'create' || $action === 'edit'): ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>
                    <i class="bi bi-<?= $action === 'create' ? 'plus-circle' : 'pencil' ?>"></i>
                    <?= htmlspecialchars(ucfirst($action)) ?> Category
                </h2>
                <a href="?action=list" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Categories
                </a>
            </div>

            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Category Information</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <?php if ($action === 'edit' && !empty($category_data)): ?>
                                <input type="hidden" name="edit_id" value="<?= (int)$category_data['id'] ?>">
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label for="name" class="form-label">Category Name *</label>
                                    <input type="text" class="form-control" id="name" name="name"
                                        value="<?= htmlspecialchars($category_data['name'] ?? '') ?>" required
                                        maxlength="100">
                                    <small class="form-text text-muted">The category name will be displayed on the
                                        frontend.</small>
                                </div>

                                <div class="mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="4"
                                        placeholder="Brief description of this category..."><?= htmlspecialchars($category_data['description'] ?? '') ?></textarea>
                                    <small class="form-text text-muted">Optional. Describe what kind of posts belong to
                                        this category.</small>
                                </div>

                                <?php if ($action === 'edit' && !empty($category_data)): ?>
                                <div class="mb-3">
                                    <label class="form-label">Current Slug</label>
                                    <input type="text" class="form-control"
                                        value="<?= htmlspecialchars($category_data['slug']) ?>" readonly>
                                    <small class="form-text text-muted">The slug will be updated automatically based on
                                        the name.</small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Posts in this Category</label>
                                    <div class="alert alert-info">
                                        <?php
                                                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM posts WHERE category_id = :id");
                                                    $stmt->bindValue(':id', $category_data['id'], PDO::PARAM_INT);
                                                    $stmt->execute();
                                                    $post_count = (int)$stmt->fetch()['count'];
                                                ?>
                                        This category currently has <strong><?= $post_count ?></strong> post(s).
                                        <?php if (empty($category_data['is_deletable'])): ?>
                                        <br><em class="text-warning">This is a system category and cannot be
                                            deleted.</em>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div class="d-flex justify-content-between">
                                    <div>
                                        <button type="submit" name="save_category" class="btn btn-primary">
                                            <i class="bi bi-save"></i>
                                            <?= $action === 'create' ? 'Create Category' : 'Update Category' ?>
                                        </button>
                                    </div>

                                    <div>
                                        <?php if ($action === 'edit' && !empty($category_data)): ?>
                                        <a href="../index.php?category=<?= (int)$category_data['id'] ?>" target="_blank"
                                            class="btn btn-outline-info me-2">
                                            <i class="bi bi-eye"></i> View Posts
                                        </a>
                                        <?php endif; ?>

                                        <a href="?action=list" class="btn btn-outline-secondary">
                                            <i class="bi bi-x"></i> Cancel
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; /* end main conditional views */ ?>
        </div>
    </main>

    <!-- =========================
         Hidden form used for single-item actions
         (populated & submitted by JS when performing single deletes)
         ========================= -->
    <form id="singleActionForm" method="POST" class="d-none">
        <input type="hidden" name="single_action" id="singleAction">
        <input type="hidden" name="category_id" id="singleCategoryId">
    </form>

    <!-- =========================
         Scripts: Bootstrap bundle + page JS
         - Auto-dismiss alerts: uses Bootstrap's Alert API if available
         - Select all checkboxes, bulk confirm, single delete remain intact
         ========================= -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function() {
        /* DOM ready */
        document.addEventListener('DOMContentLoaded', () => {
            // Elements
            const selectAllCheckbox = document.getElementById('selectAll');
            const categoryCheckboxes = Array.from(document.querySelectorAll('.category-checkbox'));
            const autoAlerts = Array.from(document.querySelectorAll('.js-auto-dismiss'));

            /* ----------------------------
               Auto-dismiss alerts (6s)
               - Uses Bootstrap's Alert API if available
               - Alerts are dismissible by user
               ---------------------------- */
            autoAlerts.forEach(alertEl => {
                // Auto-dismiss after 6 seconds
                const timeout = 6000;
                setTimeout(() => {
                    try {
                        if (window.bootstrap && bootstrap.Alert) {
                            // getOrCreateInstance in v5: use getOrCreateInstance if present, else new instance
                            if (typeof bootstrap.Alert.getOrCreateInstance === 'function') {
                                bootstrap.Alert.getOrCreateInstance(alertEl).close();
                            } else {
                                // fallback
                                new bootstrap.Alert(alertEl).close();
                            }
                        } else {
                            // Basic fallback: fade then remove
                            alertEl.classList.remove('show');
                            alertEl.style.opacity = '0';
                            setTimeout(() => {
                                if (alertEl.parentNode) alertEl.parentNode
                                    .removeChild(alertEl);
                            }, 350);
                        }
                    } catch (e) {
                        // ignore errors
                    }
                }, timeout);
            });

            /* ----------------------------
               Select All / individual checkbox handling
               ---------------------------- */
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', () => {
                    categoryCheckboxes.forEach(cb => cb.checked = selectAllCheckbox.checked);
                });

                categoryCheckboxes.forEach(cb => {
                    cb.addEventListener('change', () => {
                        const checkedCount = document.querySelectorAll(
                            '.category-checkbox:checked').length;
                        selectAllCheckbox.checked = checkedCount === categoryCheckboxes
                            .length && categoryCheckboxes.length > 0;
                        selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount <
                            categoryCheckboxes.length;
                    });
                });
            }
        });

        /* ----------------------------
           Bulk action confirmation (called from form onsubmit)
           - returns true to allow submit or false to prevent
           ---------------------------- */
        window.confirmBulkAction = function() {
            const selected = document.querySelectorAll('.category-checkbox:checked');
            const actionElem = document.querySelector('[name="bulk_action"]');
            const action = actionElem ? actionElem.value : '';

            if (!selected.length) {
                alert('Please select at least one category.');
                return false;
            }
            if (!action) {
                alert('Please select an action.');
                return false;
            }
            if (action === 'delete') {
                return confirm(
                    `Are you sure you want to delete ${selected.length} category(ies)? All posts in these categories will be moved to "Uncategorized".`
                );
            }
            return confirm(`Are you sure you want to ${action} ${selected.length} category(ies)?`);
        };

        /* ----------------------------
           Single category delete helper
           - Pops up confirm dialog, sets hidden form values, then submits
           ---------------------------- */
        window.deleteCategory = function(categoryId) {
            if (!categoryId) return;
            if (confirm(
                    'Are you sure you want to delete this category? All posts in this category will be moved to "Uncategorized".'
                )) {
                const singleActionEl = document.getElementById('singleAction');
                const singleCatEl = document.getElementById('singleCategoryId');
                if (singleActionEl && singleCatEl) {
                    singleActionEl.value = 'delete';
                    singleCatEl.value = categoryId;
                    document.getElementById('singleActionForm').submit();
                }
            }
        };
    })();
    </script>
</body>

</html>