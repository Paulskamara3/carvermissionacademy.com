<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Escape output
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Generate CSRF token
if (empty($_SESSION['add_user_csrf'])) {
    $_SESSION['add_user_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;
$temporaryPassword = '';

$username = '';
$email = '';
$selectedRole = '';
$status = 'active';

// Load available roles
$stmt = $pdo->query("
    SELECT id, role_name
    FROM roles
    WHERE LOWER(role_name) IN ('admin', 'teacher')
    ORDER BY role_name ASC
");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $selectedRole = $_POST['role_id'] ?? '';
    $status = $_POST['account_status'] ?? 'active';

    // Validate CSRF token
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['add_user_csrf'], $_POST['csrf_token'])
    ) {
        $errors[] = 'Invalid form submission. Please refresh the page and try again.';
    }

    if ($username === '') {
        $errors[] = 'Username is required.';
    } elseif (strlen($username) > 100) {
        $errors[] = 'Username cannot exceed 100 characters.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (strlen($email) > 150) {
        $errors[] = 'Email cannot exceed 150 characters.';
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors[] = 'Invalid account status selected.';
    }

    // Confirm selected role exists and is allowed
    $roleExists = false;

    foreach ($roles as $role) {
        if ((string)$role['id'] === (string)$selectedRole) {
            $roleExists = true;
            break;
        }
    }

    if (!$roleExists) {
        $errors[] = 'Please select a valid Admin or Teacher role.';
    }

    // Check duplicate username
    if (empty($errors)) {
        $stmt = $pdo->prepare("
            SELECT id FROM users
            WHERE username = ?
            LIMIT 1
        ");
        $stmt->execute([$username]);

        if ($stmt->fetch()) {
            $errors[] = 'This username already exists.';
        }
    }

    // Check duplicate email
    if (empty($errors) && $email !== '') {
        $stmt = $pdo->prepare("
            SELECT id FROM users
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $errors[] = 'This email address is already in use.';
        }
    }

    // Create account
    if (empty($errors)) {
        try {
            // Generate a secure temporary password
            $temporaryPassword = bin2hex(random_bytes(6));

            $passwordHash = password_hash(
                $temporaryPassword,
                PASSWORD_DEFAULT
            );

            $stmt = $pdo->prepare("
                INSERT INTO users (
                    role_id,
                    username,
                    email,
                    password_hash,
                    account_status,
                    must_change_password
                ) VALUES (?, ?, ?, ?, ?, 1)
            ");

            $stmt->execute([
                $selectedRole,
                $username,
                $email !== '' ? $email : null,
                $passwordHash,
                $status
            ]);

            $success = true;

            // Clear the form after successful creation
            $username = '';
            $email = '';
            $selectedRole = '';
            $status = 'active';

            // Generate a fresh CSRF token
            $_SESSION['add_user_csrf'] = bin2hex(random_bytes(32));

        } catch (PDOException $e) {
            error_log('User creation error: ' . $e->getMessage());
            $errors[] = 'Unable to create the account. Please try again.';
            $temporaryPassword = '';
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
    <title>Add New User | CMA School Management System</title>

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
                <h3 class="fw-bold mb-1">Add New User</h3>
                <p class="text-muted mb-0">
                    Create an administrator or teacher account.
                </p>
            </div>

            <a href="<?php echo BASE_URL; ?>/admin/users/"
               class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Users
            </a>
        </div>

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

        <?php if ($success): ?>
            <div class="alert alert-success">
                <h5 class="alert-heading">
                    <i class="bi bi-check-circle-fill"></i>
                    Account Created Successfully!
                </h5>

                <p class="mb-2">
                    The new user account has been created.
                    Give the temporary password below to the user.
                </p>

                <div class="bg-white border rounded p-3">
                    <p class="mb-2">
                        <strong>Temporary Password:</strong>
                    </p>

                    <div class="d-flex align-items-center gap-2">
                        <code class="fs-5" id="temporaryPassword"><?php echo e($temporaryPassword); ?></code>

                        <button type="button"
                                class="btn btn-sm btn-outline-primary"
                                onclick="copyPassword()">
                            <i class="bi bi-clipboard"></i> Copy
                        </button>
                    </div>

                    <small class="text-muted d-block mt-2">
                        This password will not be displayed again.
                    </small>
                </div>

                <p class="mt-3 mb-0">
                    <strong>Important:</strong> The user must change this
                    password when logging in for the first time.
                </p>
            </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-lg-8 col-xl-7">

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">
                            <i class="bi bi-person-plus text-primary"></i>
                            User Account Information
                        </h5>
                    </div>

                    <div class="card-body p-4">

                        <form method="POST" action="">

                            <input type="hidden"
                                   name="csrf_token"
                                   value="<?php echo e($_SESSION['add_user_csrf']); ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Username <span class="text-danger">*</span>
                                </label>

                                <input type="text"
                                       name="username"
                                       class="form-control"
                                       placeholder="Enter username"
                                       value="<?php echo e($username); ?>"
                                       maxlength="100"
                                       required>

                                <small class="text-muted">
                                    The user will use this username to log in.
                                </small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Email Address
                                </label>

                                <input type="email"
                                       name="email"
                                       class="form-control"
                                       placeholder="Enter email address"
                                       value="<?php echo e($email); ?>"
                                       maxlength="150">

                                <small class="text-muted">
                                    Email address is optional.
                                </small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    User Role <span class="text-danger">*</span>
                                </label>

                                <select name="role_id"
                                        class="form-select"
                                        required>

                                    <option value="">-- Select Role --</option>

                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo e($role['id']); ?>"
                                            <?php echo ((string)$selectedRole === (string)$role['id']) ? 'selected' : ''; ?>>
                                            <?php echo e(ucfirst($role['role_name'])); ?>
                                        </option>
                                    <?php endforeach; ?>

                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Account Status
                                </label>

                                <select name="account_status" class="form-select">
                                    <option value="active"
                                        <?php echo $status === 'active' ? 'selected' : ''; ?>>
                                        Active
                                    </option>

                                    <option value="inactive"
                                        <?php echo $status === 'inactive' ? 'selected' : ''; ?>>
                                        Inactive
                                    </option>
                                </select>
                            </div>

                            <div class="alert alert-info">
                                <i class="bi bi-shield-lock"></i>
                                <strong>Password Security</strong>
                                <p class="mb-0 mt-1">
                                    A secure temporary password will be generated
                                    automatically. The user must change it after
                                    the first successful login.
                                </p>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mt-4">

                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-person-check"></i>
                                    Create User Account
                                </button>

                                <a href="<?php echo BASE_URL; ?>/admin/users/"
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

<script>
function copyPassword() {
    const password = document.getElementById('temporaryPassword').textContent;

    navigator.clipboard.writeText(password).then(function () {
        alert('Temporary password copied successfully.');
    }).catch(function () {
        alert('Unable to copy automatically. Please select and copy the password.');
    });
}
</script>

</body>
</html>
