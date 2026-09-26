<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['admin']);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/audit.php';

ensure_session_started();

$roles = ['admin', 'staff', 'viewer'];
$message = '';

$search = cleanText($_GET['search'] ?? '', 100);
$roleFilter = cleanText($_GET['role'] ?? '', 30);
$editId = cleanInt($_GET['edit_id'] ?? 0);
$editRecord = null;

/* =========================
   SAVE / UPDATE / RESET
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['save_user'])) {
        $userId = cleanInt($_POST['user_id'] ?? 0);
        $username = cleanText($_POST['username'] ?? '', 100);
        $role = cleanText($_POST['role'] ?? '', 30);
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '') {
            $message = "<div class='alert alert-danger'>Username is required.</div>";
        } elseif (!in_array($role, $roles, true)) {
            $message = "<div class='alert alert-danger'>Invalid role.</div>";
        } elseif ($userId === 0 && $password === '') {
            $message = "<div class='alert alert-danger'>Password is required for new users.</div>";
        } else {
            $dupeStmt = $pdo->prepare("
                SELECT user_id
                FROM users
                WHERE username = ?
                  AND user_id <> ?
                LIMIT 1
            ");
            $dupeStmt->execute([$username, $userId]);

            if ($dupeStmt->fetchColumn()) {
                $message = "<div class='alert alert-danger'>Username is already in use.</div>";
            } elseif ($userId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET username = ?,
                        role = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([$username, $role, $userId]);

                logActivity('UPDATE', 'users', $userId, 'Updated user ' . $username);

                header('Location: users_admin.php?updated=1');
                exit;
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO users
                    (username, password, role, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role
                ]);

                $newUserId = (int) $pdo->lastInsertId();
                logActivity('CREATE', 'users', $newUserId, 'Created user ' . $username);

                header('Location: users_admin.php?created=1');
                exit;
            }
        }
    }

    if (isset($_POST['reset_password'])) {
        $userId = cleanInt($_POST['user_id'] ?? 0);
        $password = (string) ($_POST['new_password'] ?? '');

        if ($userId > 0 && $password !== '') {
            $stmt = $pdo->prepare("
                UPDATE users
                SET password = ?
                WHERE user_id = ?
            ");
            $stmt->execute([
                password_hash($password, PASSWORD_DEFAULT),
                $userId
            ]);

            logActivity('UPDATE', 'users', $userId, 'Reset password for user #' . $userId);
        }

        header('Location: users_admin.php?password=1');
        exit;
    }
}

/* =========================
   MESSAGES
========================= */
if (isset($_GET['created'])) {
    $message = "<div class='alert alert-success'>User created.</div>";
} elseif (isset($_GET['updated'])) {
    $message = "<div class='alert alert-success'>User updated.</div>";
} elseif (isset($_GET['password'])) {
    $message = "<div class='alert alert-success'>Password reset.</div>";
}

/* =========================
   EDIT RECORD
========================= */
if ($editId > 0) {
    $stmt = $pdo->prepare("
        SELECT user_id, username, role, created_at
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$editId]);
    $editRecord = $stmt->fetch();
}

/* =========================
   FILTERS
========================= */
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "username LIKE ?";
    $params[] = '%' . $search . '%';
}

if ($roleFilter !== '' && in_array($roleFilter, $roles, true)) {
    $where[] = "role = ?";
    $params[] = $roleFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT user_id, username, role, created_at
    FROM users
    $whereSql
    ORDER BY created_at DESC, username ASC
");
$stmt->execute($params);
$users = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Users Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background:#f4f6f9; }

        .card {
            border:0;
            border-radius:16px;
            box-shadow:0 8px 22px rgba(0,0,0,.06);
        }

        .table-wrap {
            max-height:650px;
            overflow-y:auto;
        }
    </style>
</head>

<body>

<div class="container-fluid app-page">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Users Admin</h3>
        <small class="page-subtitle">Manage application users and access roles.</small>
    </div>

    <?= $message ?>

    <div class="row g-3">

        <div class="col-lg-4">

            <div class="card mb-3">
                <div class="card-header bg-primary text-white fw-bold">
                    <?= $editRecord ? 'Edit User' : 'Create User' ?>
                </div>

                <div class="card-body">

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="save_user" value="1">
                        <input type="hidden" name="user_id" value="<?= e($editRecord['user_id'] ?? 0) ?>">

                        <label class="form-label fw-bold">Username</label>
                        <input name="username"
                               class="form-control mb-2"
                               maxlength="100"
                               required
                               value="<?= e($editRecord['username'] ?? '') ?>">

                        <label class="form-label fw-bold">Role</label>
                        <select name="role" class="form-select mb-2" required>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= e($role) ?>"
                                    <?= (($editRecord['role'] ?? 'viewer') === $role) ? 'selected' : '' ?>>
                                    <?= e($role) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if (!$editRecord): ?>
                            <label class="form-label fw-bold">Password</label>
                            <input type="password"
                                   name="password"
                                   class="form-control mb-3"
                                   required>
                        <?php endif; ?>

                        <button class="btn btn-primary w-100">
                            <?= $editRecord ? 'Update User' : 'Create User' ?>
                        </button>

                        <?php if ($editRecord): ?>
                            <a href="users_admin.php" class="btn btn-outline-secondary w-100 mt-2">
                                Cancel Edit
                            </a>
                        <?php endif; ?>
                    </form>

                </div>
            </div>

            <?php if ($editRecord): ?>
                <div class="card">
                    <div class="card-header bg-warning fw-bold">
                        Reset Password
                    </div>

                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="reset_password" value="1">
                            <input type="hidden" name="user_id" value="<?= e($editRecord['user_id']) ?>">

                            <label class="form-label fw-bold">New Password</label>
                            <input type="password"
                                   name="new_password"
                                   class="form-control mb-3"
                                   required>

                            <button class="btn btn-warning w-100">
                                Reset Password
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <div class="col-lg-8">

            <div class="card mb-3">
                <div class="card-header bg-white fw-bold">
                    Search Users
                </div>

                <div class="card-body">
                    <form method="GET" class="row g-2">

                        <div class="col-md-7">
                            <label class="form-label small fw-bold">Username</label>
                            <input name="search"
                                   class="form-control"
                                   value="<?= e($search) ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Role</label>
                            <select name="role" class="form-select">
                                <option value="">All roles</option>

                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= e($role) ?>" <?= $roleFilter === $role ? 'selected' : '' ?>>
                                        <?= e($role) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2 d-flex align-items-end">
                            <button class="btn btn-primary w-100">
                                Filter
                            </button>
                        </div>

                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-white fw-bold d-flex justify-content-between">
                    <span>Users</span>
                    <span class="text-muted"><?= e(count($users)) ?></span>
                </div>

                <div class="table-wrap">
                    <table class="table table-hover align-middle mb-0">

                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td class="fw-semibold">
                                        <?= e($user['username']) ?>
                                    </td>

                                    <td>
                                        <span class="badge bg-secondary">
                                            <?= e($user['role']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= e($user['created_at']) ?>
                                    </td>

                                    <td class="text-end">
                                        <a href="users_admin.php?edit_id=<?= e($user['user_id']) ?>"
                                           class="btn btn-sm btn-outline-primary">
                                            Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (count($users) === 0): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        No users found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>

                    </table>
                </div>
            </div>

        </div>

    </div>

</div>

</body>
</html>
