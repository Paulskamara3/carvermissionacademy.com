<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Generate CSRF token
if (empty($_SESSION['edit_user_csrf'])) {
    $_SESSION['edit_user_csrf'] = bin2hex(random_bytes(32));
}

// Validate user ID
$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$userId || $userId <= 0) {
    header('Location: ' . BASE_URL . '/admin/users/?message=invalid_id');
    exit;
}

// Fetch user
$stmt = $pdo->prepare("
    SELECT
        id,
        role_id,
        username,
        email,
        account_status
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: ' . BASE_URL . '/admin/users/?message=user_not_found');
    exit;
}

// Fetch available roles
$stmt = $pdo->query("
    SELECT id, role_name
    FROM roles
    ORDER BY role_name ASC
");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = false;

$username = $user['username'];
$email = $user['email'] ?? '';
$roleId = $user['role_id'];
$status = $user['account_status'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $roleId = $_POST['role_id'] ?? '';
    $status = $_POST['account_status'] ?? '';

    // CSRF validation
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['edit_user_csrf'], $_POST['csrf_token'])
    ) {
        $errors[] = 'Invalid form submission. Refresh the page and try again.';
    }

    // Username validation
    if ($username === '') {
        $errors[] = 'Username is required.';
    } elseif (strlen($username) > 100) {
        $errors[] = 'Username cannot exceed 100 characters.';
    }

    // Email validation
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (strlen($email) > 150) {
        $errors[] = 'Email cannot exceed 150 characters.';
    }

    // Status validation
    if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
        $errors[] = 'Please select a valid account status.';
    }

    // Role validation
    $validRole = false;

    foreach ($roles as $role) {
        if ((string)$role['id'] === (string)$roleId) {
            $validRole = true;
            break;
        }
    }

    if (!$validRole) {
        $errors[] = 'Please select a valid user role.';
    }

    // Prevent duplicate username
    if (empty($errors)) {
        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE username = ?
              AND id != ?
            LIMIT 1
        ");
        $stmt->execute([$username, $userId]);

        if ($stmt->fetch()) {
            $errors[] = 'This username is already being used.';
        }
    }

    // Prevent duplicate email
    if (empty($errors) && $email !== '') {
        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = ?
              AND id != ?
            LIMIT 1
        ");
        $stmt->execute([$email, $userId]);

        if ($stmt->fetch()) {
            $errors[] = 'This email address is already being used.';
        }
    }

    // Update account
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE users
                SET
                    username = ?,
                    email = ?,
                    role_id = ?,
                    account_status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $username,
                $email !== '' ? $email : null,
                $roleId,
                $status,
                $userId
            ]);

            // Refresh current user information
            $stmt = $pdo->prepare("
                SELECT id, role_id, username, email, account_status
                FROM users
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $success = true;

            $_SESSION['edit_user_csrf'] = bin2hex(random_bytes(32));

        } catch (PDOException $e) {
            error_log('User update error: ' . $e->getMessage());
            $errors[] = 'Unable to update this account. Please try again.';
        }
    }
}

$activePage = 'users';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit User | CMA School Management System</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link href="<?php echo BASE_URL; ?>/assets/css/style.css" rel="stylesheet">
</head>

<body>

<?php require_once __DIR__ . '/../../includes/admin_sidebar.php'; ?>

<main class="admin-main">

    <div class="container-fluid py-4">

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">

            <div>
                <h3 class="fw-bold mb-1">Edit User</h3>
                <p class="text-muted mb-0">
                    Update user account information.
                </p>
            </div>

            <a href="<?php echo BASE_URL; ?>/admin/users/"
               class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Users
            </a>

        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill"></i>
                User account updated successfully.
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <strong>Please correct the following:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row justify-content-center">

            <div class="col-lg-8 col-xl-7">

                <div class="card border-0 shadow-sm">

                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-pencil-square text-primary"></i>
                            Account Information
                        </h5>
                    </div>

                    <div class="card-body p-4">

                        <form method="POST" action="">

                            <input type="hidden"
                                   name="csrf_token"
                                   value="<?php echo e($_SESSION['edit_user_csrf']); ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Username <span class="text-danger">*</span>
                                </label>

                                <input type="text"
                                       name="username"
                                       class="form-control"
                                       value="<?php echo e($username); ?>"
                                       maxlength="100"
                                       required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Email Address
                                </label>

                                <input type="email"
                                       name="email"
                                       class="form-control"
                                       value="<?php echo e($email); ?>"
                                       maxlength="150">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    User Role <span class="text-danger">*</span>
                                </label>

                                <select name="role_id"
                                        class="form-select"
                                        required>

                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo e($role['id']); ?>"
                                            <?php echo ((string)$roleId === (string)$role['id']) ? 'selected' : ''; ?>>
                                            <?php echo e(ucfirst($role['role_name'])); ?>
                                        </option>
                                    <?php endforeach; ?>

                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Account Status
                                </label>

                                <select name="account_status"
                                        class="form-select"
                                        required>

                                    <option value="active"
                                        <?php echo $status === 'active' ? 'selected' : ''; ?>>
                                        Active
                                    </option>

                                    <option value="inactive"
                                        <?php echo $status === 'inactive' ? 'selected' : ''; ?>>
                                        Inactive
                                    </option>

                                    <option value="suspended"
                                        <?php echo $status === 'suspended' ? 'selected' : ''; ?>>
                                        Suspended
                                    </option>

                                </select>
                            </div>

                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>

                                Changing account information does not change
                                the user's password or password-change requirement.
                            </div>

                            <div class="d-flex flex-wrap gap-2 mt-4">

                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i>
                                    Save Changes
                                </button>

                                <a href="<?php echo BASE_URL; ?>/admin/users/view.php?id=<?php echo (int)$userId; ?>"
                                   class="btn btn-light border">
                                    Cancel
                                </a>

                            </div>

                        </form>

                    </div>

                </div>

            </div>

        </div>

    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
