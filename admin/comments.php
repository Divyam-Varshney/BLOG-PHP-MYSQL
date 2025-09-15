<?php
/**
 * admin/comments.php
 * ------------------
 * Admin panel page: Manage Comments
 * - List comments (filter / search / pagination)
 * - Bulk actions (approve / reject / delete / spam)
 * - Reply to a comment as admin, edit and delete admin replies
 *
 * Notes:
 * - This is a cleaned and commented version of the original file.
 * - All original PHP logic is preserved. I only reorganized, formatted and added comments
 *   and a small JS enhancement: auto-dismiss alerts and improved checkbox handling.
 * - This file expects $pdo and the data/Functions.php utilities to be available, as in
 *   your original project. Do not remove those dependencies.
 */

// -------------------------------
// Boot / Includes / Session
// -------------------------------
require_once '../data/Functions.php';
app_start_session();

// -------------------------------
// Authorization: ensure admin logged in
// -------------------------------
if (!app_is_admin_logged_in()) {
    header('Location: login.php');
    exit;
}

// Current admin (used in header/user avatar)
$current_admin = app_get_current_admin();

// -------------------------------
// Routing / Inputs
// -------------------------------
$action = $_GET['action'] ?? 'list';
$comment_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : null);
$errors = [];
$success = '';

// -------------------------------
// Form handling
// (POST actions: reply_comment, update_reply, delete_reply, change_status, bulk_action)
// -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST -> add reply
    if (isset($_POST['reply_comment'])) {
        $admin_reply = trim($_POST['admin_reply'] ?? '');

        if ($admin_reply === '') {
            $errors[] = 'Reply content is required';
        } else {
            $stmt = $pdo->prepare("UPDATE comments SET admin_reply = :admin_reply, admin_reply_at = NOW() WHERE id = :comment_id");
            $stmt->bindValue(':admin_reply', $admin_reply);
            $stmt->bindValue(':comment_id', $comment_id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $success = 'Reply posted successfully!';
                $action = 'list';
            } else {
                $errors[] = 'Failed to post reply';
            }
        }

    // POST -> update existing admin reply
    } elseif (isset($_POST['update_reply'])) {
        $admin_reply = trim($_POST['admin_reply'] ?? '');

        if ($admin_reply === '') {
            $errors[] = 'Reply content is required';
        } else {
            $stmt = $pdo->prepare("UPDATE comments SET admin_reply = :admin_reply WHERE id = :comment_id");
            $stmt->bindValue(':admin_reply', $admin_reply);
            $stmt->bindValue(':comment_id', $comment_id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $success = 'Reply updated successfully!';
                $action = 'list';
            } else {
                $errors[] = 'Failed to update reply';
            }
        }

    // POST -> delete admin reply only
    } elseif (isset($_POST['delete_reply'])) {
        $stmt = $pdo->prepare("UPDATE comments SET admin_reply = NULL, admin_reply_at = NULL WHERE id = :comment_id");
        $stmt->bindValue(':comment_id', $comment_id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            $success = 'Reply deleted successfully!';
            $action = 'list';
        } else {
            $errors[] = 'Failed to delete reply';
        }

    // POST -> change individual or multiple comment status
    } elseif (isset($_POST['change_status'])) {
        $new_status = $_POST['status'] ?? null;
        // selected_comments can come from checkboxes or single id
        $selected_comments = $_POST['comment_ids'] ?? (isset($comment_id) ? [$comment_id] : []);

        if (!empty($selected_comments) && $new_status !== null) {
            $placeholders = str_repeat('?,', count($selected_comments) - 1) . '?';
            $params = array_merge([$new_status], $selected_comments);

            $stmt = $pdo->prepare("UPDATE comments SET status = ? WHERE id IN ($placeholders)");
            if ($stmt->execute($params)) {
                $success = count($selected_comments) . ' comment(s) status updated to ' . htmlspecialchars($new_status) . '!';
            } else {
                $errors[] = 'Failed to update comment status';
            }
        }

    // POST -> bulk action: approve / reject / delete / mark_spam
    } elseif (isset($_POST['bulk_action'])) {
        $selected_comments = $_POST['selected_comments'] ?? [];
        $bulk_action = $_POST['bulk_action_type'] ?? '';

        if (!empty($selected_comments)) {
            $placeholders = str_repeat('?,', count($selected_comments) - 1) . '?';

            switch ($bulk_action) {
                case 'approve':
                    $stmt = $pdo->prepare("UPDATE comments SET status = 'approved' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_comments);
                    $success = count($selected_comments) . ' comments approved successfully!';
                    break;

                case 'reject':
                    $stmt = $pdo->prepare("UPDATE comments SET status = 'rejected' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_comments);
                    $success = count($selected_comments) . ' comments rejected successfully!';
                    break;

                case 'delete':
                    $stmt = $pdo->prepare("DELETE FROM comments WHERE id IN ($placeholders)");
                    $stmt->execute($selected_comments);
                    $success = count($selected_comments) . ' comments deleted successfully!';
                    break;

                case 'mark_spam':
                    $stmt = $pdo->prepare("UPDATE comments SET status = 'rejected' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_comments);
                    $success = count($selected_comments) . ' comments marked as spam!';
                    break;

                default:
                    $errors[] = 'Unknown bulk action';
                    break;
            }
        } else {
            $errors[] = 'No comments selected for bulk action';
        }
    }
}

// -------------------------------
// Data fetching for the list view
// -------------------------------
if ($action === 'list') {
    // Pagination inputs
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 15;
    $offset = ($page - 1) * $limit;

    // Filters
    $status_filter = $_GET['status'] ?? null;
    $search = $_GET['search'] ?? null;

    // Base query (joins users and posts to show helpful info)
    $sql = "
        SELECT c.*, u.username, u.profile_photo, p.title as post_title, p.slug as post_slug,
               a.first_name as admin_first_name, a.last_name as admin_last_name
        FROM comments c
        JOIN users u ON c.user_id = u.id
        JOIN posts p ON c.post_id = p.id
        LEFT JOIN admins a ON p.admin_id = a.id
        WHERE 1=1
    ";

    $params = [];

    if ($status_filter) {
        $sql .= " AND c.status = :status";
        $params[':status'] = $status_filter;
    }

    if ($search) {
        $sql .= " AND (c.content LIKE :search OR u.username LIKE :search OR p.title LIKE :search)";
        $params[':search'] = "%{$search}%";
    }

    $sql .= " ORDER BY c.created_at DESC LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $comments = $stmt->fetchAll();

    // Comment stats for dashboard
    $stmt = $pdo->prepare("\n        SELECT \n            COUNT(*) as total_comments,\n            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_comments,\n            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_comments,\n            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_comments,\n            SUM(CASE WHEN admin_reply IS NOT NULL THEN 1 ELSE 0 END) as replied_comments\n        FROM comments\n    ");
    $stmt->execute();
    $comment_stats = $stmt->fetch();

    // Safety: ensure non-null stats
    $comment_stats = $comment_stats ?: [];
    $comment_stats = array_merge([
        'total_comments'   => 0,
        'pending_comments' => 0,
        'approved_comments'=> 0,
        'rejected_comments'=> 0,
        'replied_comments' => 0
    ], $comment_stats);

    // Count total for pagination
    $count_sql = "
        SELECT COUNT(*) 
        FROM comments c
        JOIN users u ON c.user_id = u.id
        JOIN posts p ON c.post_id = p.id
        WHERE 1=1
    ";
    $count_params = [];
    if ($status_filter) {
        $count_sql .= " AND c.status = :status";
        $count_params[':status'] = $status_filter;
    }
    if ($search) {
        $count_sql .= " AND (c.content LIKE :search OR u.username LIKE :search OR p.title LIKE :search)";
        $count_params[':search'] = "%{$search}%";
    }
    $stmt = $pdo->prepare($count_sql);
    foreach ($count_params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->execute();
    $total_comments = (int)$stmt->fetchColumn();
    $total_pages = (int)ceil($total_comments / $limit);
}

// -------------------------------
// Fetch a single comment when replying / editing
// -------------------------------
if (($action === 'reply' || $action === 'edit_reply') && $comment_id) {
    $stmt = $pdo->prepare("\n        SELECT c.*, u.username, u.profile_photo, p.title as post_title, p.slug as post_slug\n        FROM comments c\n        JOIN users u ON c.user_id = u.id\n        JOIN posts p ON c.post_id = p.id\n        WHERE c.id = :comment_id\n    ");
    $stmt->bindValue(':comment_id', $comment_id, PDO::PARAM_INT);
    $stmt->execute();
    $comment = $stmt->fetch();

    if (!$comment) {
        $action = 'list';
        $errors[] = 'Comment not found';
    }
}

?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Comments - Admin</title>
    <!-- Bootstrap core + icons + project admin stylesheet -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/admin.css" rel="stylesheet">
</head>

<body>
    <!-- ==========================
         Header / Navbar
         ========================== -->
    <header>
        <nav class="navbar navbar-expand-lg" aria-label="Main navigation">
            <div class="container-fluid">
                <!-- Brand -->
                <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
                    <i class="bi bi-journal-bookmark"></i>
                    <span class="ms-1">MyBlog Admin</span>
                </a>

                <!-- Toggler for mobile -->
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                    aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <!-- Navigation links -->
                <div class="collapse navbar-collapse" id="mainNav">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0 align-items-lg-center">
                        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-house me-1"></i>
                                Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="posts.php"><i class="bi bi-file-post me-1"></i>
                                Posts</a></li>
                        <li class="nav-item"><a class="nav-link" href="categories.php"><i class="bi bi-folder me-1"></i>
                                Categories</a></li>
                        <li class="nav-item"><a class="nav-link active" href="comments.php"><i
                                    class="bi bi-chat-dots me-1"></i> Comments</a></li>
                        <li class="nav-item"><a class="nav-link" href="users.php"><i class="bi bi-people me-1"></i>
                                Users</a></li>
                        <li class="nav-item"><a class="nav-link" href="profile.php"><i
                                    class="bi bi-person-gear me-1"></i> Profile</a></li>
                        <li class="nav-item"><a class="nav-link" href="../index.php" target="_blank"><i
                                    class="bi bi-box-arrow-up-right me-1"></i> View Site</a></li>
                        <li class="nav-item"><a class="nav-link" href="logout.php"><i
                                    class="bi bi-box-arrow-right me-1"></i> Logout</a></li>
                    </ul>

                    <!-- Admin avatar / name on the right -->
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

    <!-- ==========================
         Main content
         ========================== -->
    <main class="container-fluid py-4">

        <!-- ==========================
             List View: stats, filters and table
             ========================== -->
        <?php if ($action === 'list'): ?>

        <!-- Page header with quick filters -->
        <div class="page-header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1"><i class="bi bi-chat-dots"></i> Manage Comments</h2>
                    <p class="mb-0 opacity-75">Review, approve, and reply to user comments</p>
                </div>
                <div class="btn-group">
                    <a href="comments.php?status=pending" class="btn btn-warning btn-sm"><i class="bi bi-clock"></i>
                        Pending (<?= $comment_stats['pending_comments'] ?>)</a>
                    <a href="comments.php?status=approved" class="btn btn-success btn-sm"><i
                            class="bi bi-check-circle"></i> Approved (<?= $comment_stats['approved_comments'] ?>)</a>
                </div>
            </div>
        </div>

        <!-- Success / Error alerts (auto-dismiss enabled) -->
        <?php if ($success): ?>
        <div class="alert alert-success auto-dismiss" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger auto-dismiss" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Small dashboard stats -->
        <div class="row text-center mb-4">
            <div class="col-md-2">
                <div class="stat-item">
                    <div class="stat-number"><?= $comment_stats['total_comments'] ?></div>
                    <div>Total</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-item">
                    <div class="stat-number text-warning"><?= $comment_stats['pending_comments'] ?></div>
                    <div>Pending</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-item">
                    <div class="stat-number text-success"><?= $comment_stats['approved_comments'] ?></div>
                    <div>Approved</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-item">
                    <div class="stat-number text-danger"><?= $comment_stats['rejected_comments'] ?></div>
                    <div>Rejected</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-item">
                    <div class="stat-number text-info"><?= $comment_stats['replied_comments'] ?></div>
                    <div>Replied</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-item">
                    <div class="stat-number text-primary">
                        <?= $comment_stats['total_comments']>0?round(($comment_stats['replied_comments']/$comment_stats['total_comments'])*100,1):0 ?>%
                    </div>
                    <div>Reply Rate</div>
                </div>
            </div>
        </div>

        <!-- Filters: status + search -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3" role="search">
                    <div class="col-md-3">
                        <select name="status" class="form-select" aria-label="Filter by status">
                            <option value="">All Status</option>
                            <option value="pending" <?= ($status_filter==='pending')?'selected':'' ?>>Pending</option>
                            <option value="approved" <?= ($status_filter==='approved')?'selected':'' ?>>Approved</option>
                            <option value="rejected" <?= ($status_filter==='rejected')?'selected':'' ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <input type="text" name="search" class="form-control" placeholder="Search comments..."
                            value="<?= htmlspecialchars($search ?? '') ?>" aria-label="Search comments">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Search</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Comments List -->
        <?php if ($comments): ?>
        <form method="POST">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-dark-theme table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all" aria-label="Select all comments"></th>
                                <th>User</th>
                                <th>Comment</th>
                                <th>Post</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comments as $c): ?>
                            <tr>
                                <td><input type="checkbox" name="selected_comments[]" value="<?= (int)$c['id'] ?>" aria-label="Select comment <?= (int)$c['id'] ?>"></td>
                                <td>
                                    <img src="../uploads/users/<?= htmlspecialchars($c['profile_photo']) ?>" class="rounded-circle user-avatar me-2" width="36" height="36" alt="<?= htmlspecialchars($c['username']) ?>">
                                    <?= htmlspecialchars($c['username']) ?>
                                </td>
                                <td>
                                    <div class="comment-card <?= 'comment-' . htmlspecialchars($c['status']) ?>">
                                        <?= nl2br(htmlspecialchars($c['content'])) ?>
                                        <?php if ($c['admin_reply']): ?>
                                        <div class="admin-reply mt-2">
                                            <strong>Admin Reply:</strong><br>
                                            <?= nl2br(htmlspecialchars($c['admin_reply'])) ?>
                                            <div class="small text-muted"><?= htmlspecialchars($c['admin_reply_at']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><a href="../post.php?slug=<?= urlencode($c['post_slug']) ?>" target="_blank" class="text-info"><?= htmlspecialchars($c['post_title']) ?></a></td>
                                <td>
                                    <?php if ($c['status']==='pending'): ?><span class="badge bg-warning">Pending</span>
                                    <?php elseif ($c['status']==='approved'): ?><span class="badge bg-success">Approved</span>
                                    <?php else: ?><span class="badge bg-danger">Rejected</span><?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($c['created_at']) ?></td>
                                <td>
                                    <a href="comments.php?action=reply&id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-primary" title="Reply"><i class="bi bi-reply"></i></a>
                                    <a href="comments.php?action=edit_reply&id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-warning" title="Edit Reply"><i class="bi bi-pencil-square"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Bulk actions -->
            <div class="d-flex align-items-center mt-3">
                <select name="bulk_action_type" class="form-select w-auto me-2" aria-label="Bulk action">
                    <option value="">Bulk Actions</option>
                    <option value="approve">Approve</option>
                    <option value="reject">Reject</option>
                    <option value="delete">Delete</option>
                    <option value="mark_spam">Mark as Spam</option>
                </select>
                <button type="submit" name="bulk_action" class="btn btn-primary">Apply</button>
            </div>
        </form>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-4" aria-label="Comments pagination">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="comments.php?page=<?= max(1,$page-1) ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>">Previous</a></li>

                <?php if ($page > 3): ?>
                <li class="page-item"><a class="page-link" href="comments.php?page=1&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>">1</a></li>
                <?php if ($page > 4): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                <li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="comments.php?page=<?= $i ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>"><?= $i ?></a></li>
                <?php endfor; ?>

                <?php if ($page < $total_pages-2): ?>
                <?php if ($page < $total_pages-3): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link" href="comments.php?page=<?= $total_pages ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>"><?= $total_pages ?></a></li>
                <?php endif; ?>

                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"><a class="page-link" href="comments.php?page=<?= min($total_pages,$page+1) ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>">Next</a></li>
            </ul>
        </nav>
        <?php endif; ?>

        <?php else: ?>
        <div class="alert alert-info"><i class="bi bi-info-circle"></i> No comments found.</div>
        <?php endif; ?>

        <!-- ==========================
             Reply / Edit Reply view
             ========================== -->
        <?php elseif (($action==='reply' || $action==='edit_reply') && isset($comment)): ?>

        <div class="page-header mb-4">
            <h2><?= $action==='reply'?'Reply to Comment':'Edit Reply' ?></h2>
        </div>

        <!-- Show the comment being replied to -->
        <div class="comment-card <?= 'comment-' . htmlspecialchars($comment['status']) ?> mb-3">
            <strong><?= htmlspecialchars($comment['username']) ?></strong> on
            <em><?= htmlspecialchars($comment['post_title']) ?></em><br>
            <p><?= nl2br(htmlspecialchars($comment['content'])) ?></p>
        </div>

        <!-- Reply form -->
        <form method="POST" class="card p-3">
            <input type="hidden" name="id" value="<?= (int)$comment['id'] ?>">
            <div class="mb-3">
                <label class="form-label">Admin Reply</label>
                <textarea name="admin_reply" rows="5" class="form-control"><?= htmlspecialchars($comment['admin_reply'] ?? '') ?></textarea>
            </div>
            <div class="d-flex gap-2">
                <?php if ($action==='reply'): ?>
                <button type="submit" name="reply_comment" class="btn btn-primary"><i class="bi bi-send"></i> Post Reply</button>
                <?php else: ?>
                <button type="submit" name="update_reply" class="btn btn-success"><i class="bi bi-save"></i> Update Reply</button>
                <button type="submit" name="delete_reply" class="btn btn-danger" onclick="return confirm('Delete reply? This cannot be undone.')"><i class="bi bi-trash"></i> Delete Reply</button>
                <?php endif; ?>
                <a href="comments.php" class="btn btn-secondary">Back</a>
            </div>
        </form>

        <?php endif; ?>

    </main>

    <!-- ==========================
         Scripts: Bootstrap + small interactivity
         - select-all checkbox handling
         - auto-dismiss alerts (uses Bootstrap Alert API)
         ========================== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Select/unselect all checkboxes
    (function () {
        const selectAll = document.getElementById('select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function (e) {
                document.querySelectorAll('input[name="selected_comments[]"]').forEach(cb => cb.checked = e.target.checked);
            });
        }
    })();

    // Auto-dismiss alerts that have class "auto-dismiss" after 4 seconds
    (function () {
        const AUTO_DISMISS_MS = 4000;
        window.addEventListener('load', function () {
            document.querySelectorAll('.alert.auto-dismiss').forEach(alertEl => {
                setTimeout(() => {
                    // Use Bootstrap's Alert API to close (clean teardown)
                    try {
                        const bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
                        bsAlert.close();
                    } catch (err) {
                        // fallback: just remove element
                        alertEl.remove();
                    }
                }, AUTO_DISMISS_MS);
            });
        });
    })();
    </script>
</body>

</html>
