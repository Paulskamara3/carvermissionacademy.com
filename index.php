<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$activePage = 'users';

$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$users = [];
$roles = [];
$totalUsers = 0;
$activeUsers = 0;
$inactiveUsers = 0;
$suspendedUsers = 0;
$errorMessage = '';

$messages = [
    'created' => ['success', 'User account created successfully.'],
    'updated' => ['success', 'User account updated successfully.'],
    'password_reset' => ['success', 'User password reset successfully.'],
    'deleted' => ['success', 'User account deleted successfully.'],
    'status_updated' => ['success', 'User account status updated successfully.'],
    'not_found' => ['danger', 'User account was not found.'],
    'action_error' => ['danger', 'The requested action could not be completed.']
];

$message = $_GET['message'] ?? '';

try {

    // ACCOUNT STATISTICS

    $totalUsers = (int)$pdo->query("
        SELECT COUNT(*) FROM users
    ")->fetchColumn();

    $activeUsers = (int)$pdo->query("
        SELECT COUNT(*) FROM users
        WHERE account_status = 'active'
    ")->fetchColumn();

    $inactiveUsers = (int)$pdo->query("
        SELECT COUNT(*) FROM users
        WHERE account_status = 'inactive'
    ")->fetchColumn();

    $suspendedUsers = (int)$pdo->query("
        SELECT COUNT(*) FROM users
        WHERE account_status = 'suspended'
    ")->fetchColumn();


    // AVAILABLE ROLES

    $roles = $pdo->query("
        SELECT id, role_name
        FROM roles
        ORDER BY role_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);


    // USER RECORDS

    $sql = "
        SELECT
            u.id,
            u.role_id,
            u.username,
            u.email,
            u.account_status,
            u.last_login,
            u.created_at,
            u.must_change_password,
            r.role_name
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE 1 = 1
    ";

    $params = [];

    if ($search !== '') {

        $sql .= "
            AND (
                u.username LIKE :username
                OR u.email LIKE :email
            )
        ";

        $term = "%{$search}%";

        $params['username'] = $term;
        $params['email'] = $term;
    }

    if ($roleFilter !== '' && ctype_digit($roleFilter)) {

        $sql .= " AND u.role_id = :role_id";

        $params['role_id'] = (int)$roleFilter;
    }

    $allowedStatuses = ['active', 'inactive', 'suspended'];

    if (in_array($statusFilter, $allowedStatuses, true)) {

        $sql .= " AND u.account_status = :status";

        $params['status'] = $statusFilter;
    }

    $sql .= " ORDER BY u.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $exception) {

    error_log('User Management Error: ' . $exception->getMessage());

    $errorMessage = 'Unable to load user accounts. Please check the database.';
}


// STATUS BADGE

function userStatusBadge($status) {

    switch ($status) {

        case 'active':
            return 'success';

        case 'inactive':
            return 'secondary';

        case 'suspended':
            return 'danger';

        default:
            return 'secondary';
    }
}

require_once __DIR__ . '/../../includes/admin_sidebar.php';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>User Management | Carver Mission Academy</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
          rel="stylesheet">

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>

        body {
            background: #f4f6fa;
            font-family: Arial, sans-serif;
        }

        .users-content {
            padding: 30px;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .page-heading h2 {
            font-weight: 700;
            color: #172554;
            margin-bottom: 5px;
        }

        .summary-card,
        .user-table-card {
            background: #fff;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.05);
        }

        .summary-card {
            display: flex;
            align-items: center;
            gap: 18px;
            height: 100%;
        }

        .summary-icon {
            width: 55px;
            height: 55px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e8efff;
            color: #1d4ed8;
            border-radius: 12px;
            font-size: 25px;
            flex-shrink: 0;
        }

        .summary-card h3 {
            margin: 0;
            font-weight: 700;
            color: #172554;
        }

        .summary-card p {
            margin: 3px 0 0;
            color: #64748b;
            font-size: 13px;
        }

        .user-table-card {
            margin-top: 25px;
            overflow: hidden;
        }

        .table thead th {
            background: #f1f5f9;
            color: #334155;
            font-size: 13px;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
            font-size: 13px;
        }

        .search-box {
            max-width: 100%;
        }

        .username-text {
            font-weight: 600;
            color: #172554;
        }

        .role-badge {
            background: #e8efff;
            color: #172554;
        }

        .empty-state {
            text-align: center;
            padding: 45px 15px;
            color: #64748b;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            min-width: 145px;
        }

        .action-buttons .btn {
            font-size: 12px;
        }

        .action-form {
            display: inline-block;
            margin: 0;
        }

        .password-warning {
            font-size: 10px;
            white-space: nowrap;
        }

        @media (max-width: 768px) {

            .users-content {
                padding: 18px;
                padding-top: 70px;
            }

            .page-heading .btn {
                font-size: 13px;
            }

        }

    </style>

</head>

<body>

<?php
$activePage = 'users';
require_once __DIR__ . '/../../includes/admin_sidebar.php';
?>

<button class="sidebar-toggle" id="sidebarToggle" type="button">
    <i class="bi bi-list"></i>
</button>


<main class="admin-main">

<div class="users-content">

    <!-- PAGE HEADING -->

    <div class="page-heading">

        <div>

            <h2>User Management</h2>

            <p class="text-muted mb-0">
                Create, view and manage administrator, teacher and student accounts.
            </p>

        </div>

        <div class="d-flex gap-2 flex-wrap">

            <a href="<?= e(rtrim(BASE_URL, '/') . '/admin/dashboard.php') ?>"
               class="btn btn-outline-secondary">

                <i class="bi bi-arrow-left"></i>
                Dashboard

            </a>

            <a href="add.php" class="btn btn-primary">

                <i class="bi bi-person-plus-fill"></i>
                Add New User

            </a>

        </div>

    </div>


    <!-- ALERT MESSAGES -->

    <?php if ($errorMessage !== ''): ?>

        <div class="alert alert-danger alert-dismissible fade show">
            <?= e($errorMessage) ?>

            <button type="button" class="btn-close"
                    data-bs-dismiss="alert"></button>
        </div>

    <?php endif; ?>


    <?php if (isset($messages[$message])): ?>

        <div class="alert alert-<?= e($messages[$message][0]) ?> alert-dismissible fade show">

            <?= e($messages[$message][1]) ?>

            <button type="button" class="btn-close"
                    data-bs-dismiss="alert"></button>

        </div>

    <?php endif; ?>


    <?php if (isset($_SESSION['success'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <?= e($_SESSION['success']) ?>

            <button type="button" class="btn-close"
                    data-bs-dismiss="alert"></button>

        </div>

        <?php unset($_SESSION['success']); ?>

    <?php endif; ?>


    <?php if (isset($_SESSION['error'])): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <?= e($_SESSION['error']) ?>

            <button type="button" class="btn-close"
                    data-bs-dismiss="alert"></button>

        </div>

        <?php unset($_SESSION['error']); ?>

    <?php endif; ?>


    <!-- ACCOUNT STATISTICS -->

    <div class="row g-3 mb-4">

        <div class="col-md-6 col-xl-3">

            <div class="summary-card">

                <div class="summary-icon">
                    <i class="bi bi-people-fill"></i>
                </div>

                <div>
                    <h3><?= number_format($totalUsers) ?></h3>
                    <p>Total Users</p>
                </div>

            </div>

        </div>


        <div class="col-md-6 col-xl-3">

            <div class="summary-card">

                <div class="summary-icon">
                    <i class="bi bi-person-check-fill"></i>
                </div>

                <div>
                    <h3><?= number_format($activeUsers) ?></h3>
                    <p>Active Accounts</p>
                </div>

            </div>

        </div>


        <div class="col-md-6 col-xl-3">

            <div class="summary-card">

                <div class="summary-icon">
                    <i class="bi bi-person-dash-fill"></i>
                </div>

                <div>
                    <h3><?= number_format($inactiveUsers) ?></h3>
                    <p>Inactive Accounts</p>
                </div>

            </div>

        </div>


        <div class="col-md-6 col-xl-3">

            <div class="summary-card">

                <div class="summary-icon">
                    <i class="bi bi-person-x-fill"></i>
                </div>

                <div>
                    <h3><?= number_format($suspendedUsers) ?></h3>
                    <p>Suspended Accounts</p>
                </div>

            </div>

        </div>

    </div>


    <!-- USER RECORDS -->

    <div class="user-table-card">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

            <div>

                <h5 class="fw-bold mb-1">
                    System User Accounts
                </h5>

                <p class="text-muted mb-0">
                    View and manage registered system users.
                </p>

            </div>

            <span class="badge bg-light text-dark border">
                <?= count($users) ?> matching account(s)
            </span>

        </div>


        <!-- SEARCH AND FILTER -->

        <form method="GET" action="index.php" class="row g-2 mb-4">

            <div class="col-md-4">

                <input
                    type="search"
                    name="search"
                    class="form-control"
                    placeholder="Search username or email..."
                    value="<?= e($search) ?>"
                >

            </div>


            <div class="col-md-3">

                <select name="role" class="form-select">

                    <option value="">All Roles</option>

                    <?php foreach ($roles as $role): ?>

                        <option value="<?= (int)$role['id'] ?>"
                            <?= $roleFilter == $role['id'] ? 'selected' : '' ?>>

                            <?= e(ucfirst($role['role_name'])) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-3">

                <select name="status" class="form-select">

                    <option value="">All Account Statuses</option>

                    <option value="active"
                        <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>

                    <option value="inactive"
                        <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                        Inactive
                    </option>

                    <option value="suspended"
                        <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>
                        Suspended
                    </option>

                </select>

            </div>


            <div class="col-md-2 d-flex gap-2">

                <button type="submit" class="btn btn-primary w-100">

                    <i class="bi bi-search"></i>
                    Search

                </button>

                <a href="index.php"
                   class="btn btn-outline-secondary"
                   title="Clear Filters">

                    <i class="bi bi-arrow-counterclockwise"></i>

                </a>

            </div>

        </form>


        <!-- USER TABLE -->

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>

                    <tr>

                        <th>#</th>
                        <th>Username</th>
                        <th>Email Address</th>
                        <th>Role</th>
                        <th>Account Status</th>
                        <th>Last Login</th>
                        <th>Date Created</th>
                        <th>Actions</th>

                    </tr>

                </thead>


                <tbody>

                <?php if (!empty($users)): ?>

                    <?php foreach ($users as $index => $user): ?>

                        <tr>

                            <td>
                                <?= $index + 1 ?>
                            </td>


                            <td class="username-text">

                                <?= e($user['username']) ?>

                                <?php if ((int)$user['must_change_password'] === 1): ?>

                                    <br>

                                    <span class="badge bg-warning text-dark password-warning">
                                        Password Change Required
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>
                                <?= e($user['email'] ?: 'Not provided') ?>
                            </td>


                            <td>

                                <span class="badge role-badge">

                                    <?= e(ucfirst($user['role_name'] ?? 'Unknown')) ?>

                                </span>

                            </td>


                            <td>

                                <span class="badge bg-<?= e(userStatusBadge($user['account_status'])) ?>">

                                    <?= e(ucfirst($user['account_status'] ?? 'Unknown')) ?>

                                </span>

                            </td>


                            <td>

                                <?php if (!empty($user['last_login'])): ?>

                                    <?= e(date('d M Y, h:i A', strtotime($user['last_login']))) ?>

                                <?php else: ?>

                                    <span class="text-muted">Never logged in</span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?= e(date('d M Y', strtotime($user['created_at']))) ?>

                            </td>


                            <!-- USER ACTIONS -->

                            <td>

                                <div class="action-buttons">

                                    <a href="view.php?id=<?= (int)$user['id'] ?>"
                                       class="btn btn-sm btn-info text-white"
                                       title="View User">

                                        <i class="bi bi-eye"></i>
                                        View

                                    </a>


                                    <a href="edit.php?id=<?= (int)$user['id'] ?>"
                                       class="btn btn-sm btn-outline-primary"
                                       title="Edit User">

                                        <i class="bi bi-pencil-square"></i>
                                        Edit

                                    </a>


                                    <a href="reset_password.php?id=<?= (int)$user['id'] ?>"
                                       class="btn btn-sm btn-outline-warning"
                                       title="Reset Password">

                                        <i class="bi bi-key"></i>
                                        Reset

                                    </a>


                                    <form method="POST"
                                          action="action.php"
                                          class="action-form"
                                          onsubmit="return confirm('Are you sure you want to delete this user account? This action cannot be undone.');">

                                        <input type="hidden"
                                               name="user_id"
                                               value="<?= (int)$user['id'] ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="delete">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= e(generateCsrfToken()) ?>">

                                        <button type="submit"
                                                class="btn btn-sm btn-outline-danger">

                                            <i class="bi bi-trash"></i>
                                            Delete

                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="8">

                            <div class="empty-state">

                                <i class="bi bi-people fs-1"></i>

                                <h5 class="mt-3">
                                    No User Accounts Found
                                </h5>

                                <p>

                                    <?= $search !== '' || $roleFilter !== '' || $statusFilter !== ''
                                        ? 'No accounts match your search or selected filters.'
                                        : 'There are currently no registered user accounts.' ?>

                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <div class="text-muted small mt-3">

            Displaying <?= count($users) ?> matching account(s).

        </div>

    </div>


    <!-- FOOTER -->

    <div class="text-center text-muted small mt-4">

        &copy; <?= date('Y') ?> Carver Mission Academy.
        User Management System.

    </div>

</div>

</main>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>

const sidebarToggle = document.getElementById('sidebarToggle');
const adminSidebar = document.getElementById('adminSidebar');

if (sidebarToggle && adminSidebar) {

    sidebarToggle.addEventListener('click', function () {

        adminSidebar.classList.toggle('show');

    });

}

</script>

</body>
</html>
