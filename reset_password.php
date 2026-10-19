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

// CSRF token
if (empty($_SESSION['reset_user_csrf'])) {
    $_SESSION['reset_user_csrf'] = bin2hex(random_bytes(32));
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
        users.id,
        users.username,
        users.email,
        users.account_status,
        roles.role_name
    FROM users
    LEFT JOIN roles ON users.role_id = roles.id
    WHERE users.id = ?
    LIMIT 1
");

$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: ' . BASE_URL . '/admin/users/?message=user_not_found');
    exit;
}

$errors = [];
$temporaryPassword = '';
$success = false;

// Process password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['reset_user_csrf'], $_POST['csrf_token'])
    ) {
        $errors[] = 'Invalid form submission. Please refresh the page.';
    }

    if (empty($errors)) {
        try {
            // Generate a secure temporary password
            $temporaryPassword = bin2hex(random_bytes(6));

            $passwordHash = password_hash(
                $temporaryPassword,
                PASSWORD_DEFAULT
            );

            $stmt = $pdo->prepare("
                UPDATE users
                SET
                    password_hash = ?,
                    must_change_password = 1
                WHERE id = ?
            ");

            $stmt->execute([$passwordHash, $userId]);

            $success = true;

            // Prevent accidental repeated submission
            $_SESSION['reset_user_csrf'] = bin2hex(random_bytes(32));

        } catch (PDOException $e) {
            error_log('Password reset error: ' . $e->getMessage());

            $temporaryPassword = '';
            $errors[] = 'Unable to reset the password. Please try again.';
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

    <title>Reset Password | CMA School Management System</title>

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
                <h3 class="fw-bold mb-1">Reset User Password</h3>
                <p class="text-muted mb-0">
                    Generate a new temporary password.
                </p>
            </div>

            <a href="<?php echo BASE_URL; ?>/admin/users/"
               class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Users
            </a>

        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <p class="mb-0"><?php echo e($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>

            <div class="alert alert-success">
                <h5>
                    <i class="bi bi-check-circle-fill"></i>
                    Password Reset Successfully!
                </h5>

                <p>
                    A new temporary password has been generated for
                    <strong><?php echo e($user['username']); ?></strong>.
                </p>

                <div class="bg-white border rounded p-3">

                    <label class="form-label fw-semibold">
                        New Temporary Password
                    </label>

                    <div class="d-flex align-items-center gap-2 flex-wrap">

                        <code id="temporaryPassword" class="fs-5">
                            <?php echo e($temporaryPassword); ?>
                        </code>

                        <button type="button"
                                class="btn btn-sm btn-outline-primary"
                                onclick="copyPassword()">
                            <i class="bi bi-clipboard"></i>
                            Copy Password
                        </button>

                    </div>

                    <small class="text-muted d-block mt-2">
                        Save this password before leaving this page.
                    </small>

                </div>

                <div class="mt-3">
                    <strong>Important:</strong>
                    The user must change this password after their next
                    successful login.
                </div>

            </div>

        <?php else: ?>

            <div class="row justify-content-center">

                <div class="col-lg-7 col-xl-6">

                    <div class="card border-0 shadow-sm">

                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0 fw-bold">
                                <i class="bi bi-shield-lock text-warning"></i>
                                Confirm Password Reset
                            </h5>
                        </div>

                        <div class="card-body p-4">

                            <div class="text-center mb-4">

                                <div class="rounded-circle bg-warning bg-opacity-10
                                            text-warning d-flex align-items-center
                                            justify-content-center mx-auto mb-3"
                                     style="width:80px;height:80px;font-size:32px;">

                                    <i class="bi bi-key-fill"></i>

                                </div>

                                <h5 class="fw-bold">
                                    <?php echo e($user['username']); ?>
                                </h5>

                                <p class="text-muted mb-1">
                                    <?php echo e(ucfirst($user['role_name'] ?? 'Unknown')); ?>
                                </p>

                                <span class="badge bg-<?php
                                    echo $user['account_status'] === 'active'
                                        ? 'success'
                                        : ($user['account_status'] === 'suspended'
                                            ? 'danger'
                                            : 'secondary');
                                ?>">
                                    <?php echo e(ucfirst($user['account_status'])); ?>
                                </span>

                            </div>

                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle-fill"></i>

                                <strong>Warning:</strong>

                                <p class="mb-0 mt-1">
                                    Resetting this password will replace the
                                    user's current password. The user will
                                    need the new temporary password to log in.
                                </p>
                            </div>

                            <form method="POST" action="">

                                <input type="hidden"
                                       name="csrf_token"
                                       value="<?php echo e($_SESSION['reset_user_csrf']); ?>">

                                <button type="submit"
                                        class="btn btn-warning w-100"
                                        onclick="return confirm('Are you sure you want to reset this user password?');">

                                    <i class="bi bi-arrow-clockwise"></i>
                                    Generate New Temporary Password

                                </button>

                            </form>

                        </div>

                    </div>

                </div>

            </div>

        <?php endif; ?>

        <div class="mt-4">

            <a href="<?php echo BASE_URL; ?>/admin/users/view.php?id=<?php echo (int)$userId; ?>"
               class="btn btn-light border">

                <i class="bi bi-person"></i>
                View User Details

            </a>

        </div>

    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function copyPassword() {
    const password = document.getElementById('temporaryPassword').textContent.trim();

    navigator.clipboard.writeText(password).then(function () {
        alert('Temporary password copied successfully.');
    }).catch(function () {
        alert('Unable to copy automatically. Please copy the password manually.');
    });
}
</script>

</body>
</html>
