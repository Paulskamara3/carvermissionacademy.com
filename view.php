<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Get user ID
$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$userId || $userId <= 0) {
    header('Location: ' . BASE_URL . '/admin/users/?message=invalid_id');
    exit;
}

// Fetch user information
$stmt = $pdo->prepare("
    SELECT
        users.id,
        users.username,
        users.email,
        users.account_status,
        users.last_login,
        users.created_at,
        users.must_change_password,
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

// Status badge
$statusClasses = [
    'active' => 'success',
    'inactive' => 'secondary',
    'suspended' => 'danger'
];

$status = strtolower($user['account_status']);
$statusClass = $statusClasses[$status] ?? 'secondary';

$activePage = 'users';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>View User | CMA School Management System</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link href="<?php echo BASE_URL; ?>/assets/css/style.css" rel="stylesheet">
</head>

<body>

<?php require_once __DIR__ . '/../../includes/admin_sidebar.php'; ?>

<main class="admin-main">

    <div class="container-fluid py-4">

        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">

            <div>
                <h3 class="fw-bold mb-1">User Details</h3>
                <p class="text-muted mb-0">
                    View account information and login status.
                </p>
            </div>

            <a href="<?php echo BASE_URL; ?>/admin/users/"
               class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Users
            </a>

        </div>

        <div class="row">

            <!-- User Profile -->
            <div class="col-lg-4 mb-4">

                <div class="card border-0 shadow-sm text-center">

                    <div class="card-body p-4">

                        <div class="rounded-circle bg-primary bg-opacity-10
                                    text-primary d-flex align-items-center
                                    justify-content-center mx-auto mb-3"
                             style="width:100px;height:100px;font-size:40px;">

                            <i class="bi bi-person-fill"></i>

                        </div>

                        <h4 class="fw-bold mb-1">
                            <?php echo e($user['username']); ?>
                        </h4>

                        <p class="text-muted mb-3">
                            <?php echo e(ucfirst($user['role_name'] ?? 'Unknown')); ?>
                        </p>

                        <span class="badge bg-<?php echo e($statusClass); ?> px-3 py-2">
                            <?php echo e(ucfirst($status)); ?>
                        </span>

                        <hr>

                        <p class="text-muted small mb-1">
                            User ID
                        </p>

                        <h6 class="fw-bold">
                            #<?php echo e($user['id']); ?>
                        </h6>

                    </div>

                </div>

            </div>

            <!-- Account Information -->
            <div class="col-lg-8 mb-4">

                <div class="card border-0 shadow-sm">

                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-person-vcard text-primary"></i>
                            Account Information
                        </h5>
                    </div>

                    <div class="card-body p-4">

                        <div class="row mb-4">

                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">
                                    Username
                                </label>

                                <h6 class="fw-semibold">
                                    <?php echo e($user['username']); ?>
                                </h6>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">
                                    Email Address
                                </label>

                                <h6 class="fw-semibold">
                                    <?php echo $user['email']
                                        ? e($user['email'])
                                        : 'Not provided'; ?>
                                </h6>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">
                                    User Role
                                </label>

                                <h6 class="fw-semibold">
                                    <?php echo e(ucfirst($user['role_name'] ?? 'Unknown')); ?>
                                </h6>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">
                                    Account Status
                                </label>

                                <h6>
                                    <span class="badge bg-<?php echo e($statusClass); ?>">
                                        <?php echo e(ucfirst($status)); ?>
                                    </span>
                                </h6>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">
                                    Last Login
                                </label>

                                <h6 class="fw-semibold">
                                    <?php
                                    echo $user['last_login']
                                        ? e(date('d M Y, h:i A', strtotime($user['last_login'])))
                                        : 'Never logged in';
                                    ?>
                                </h6>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">
                                    Account Created
                                </label>

                                <h6 class="fw-semibold">
                                    <?php
                                    echo $user['created_at']
                                        ? e(date('d M Y, h:i A', strtotime($user['created_at'])))
                                        : 'Not available';
                                    ?>
                                </h6>
                            </div>

                        </div>

                        <hr>

                        <h6 class="fw-bold mb-3">
                            Password Information
                        </h6>

                        <?php if ((int)$user['must_change_password'] === 1): ?>

                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle-fill"></i>

                                <strong>Password Change Required</strong>

                                <p class="mb-0 mt-1">
                                    This user must change their temporary
                                    password during their next login.
                                </p>
                            </div>

                        <?php else: ?>

                            <div class="alert alert-success mb-0">
                                <i class="bi bi-check-circle-fill"></i>

                                This user is not currently required
                                to change their password.
                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>

        <!-- Bottom Actions -->
        <div class="d-flex flex-wrap gap-2">

            <a href="<?php echo BASE_URL; ?>/admin/users/edit.php?id=<?php echo (int)$user['id']; ?>"
               class="btn btn-primary">

                <i class="bi bi-pencil-square"></i>
                Edit User

            </a>

            <a href="<?php echo BASE_URL; ?>/admin/users/reset_password.php?id=<?php echo (int)$user['id']; ?>"
               class="btn btn-warning">

                <i class="bi bi-key"></i>
                Reset Password

            </a>

            <a href="<?php echo BASE_URL; ?>/admin/users/"
               class="btn btn-light border">

                <i class="bi bi-arrow-left"></i>
                Back to Users

            </a>

        </div>

    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
