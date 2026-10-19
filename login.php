<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Redirect users who are already logged in.
if (isLoggedIn()) {

    $role = $_SESSION['role'] ?? '';

    if ($role === 'admin') {
        header("Location: " . BASE_URL . "/admin/dashboard.php");
        exit;

    } elseif ($role === 'teacher') {
        header("Location: " . BASE_URL . "/teacher/dashboard.php");
        exit;

    } elseif ($role === 'student') {
        header("Location: " . BASE_URL . "/student/dashboard.php");
        exit;

    } else {
        session_unset();
        session_destroy();

        header("Location: " . BASE_URL . "/login.php");
        exit;
    }
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {

        $error = "Invalid request. Please refresh the page.";

    } else {

        $identity = trim($_POST['identity'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($identity === '' || $password === '') {

            $error = "Please enter your username/email and password.";

        } else {

            // Find user by username OR email.
            $stmt = $pdo->prepare("
                SELECT
                    users.id,
                    users.username,
                    users.password_hash,
                    users.account_status,
                    users.must_change_password,
                    roles.role_name
                FROM users
                INNER JOIN roles
                    ON users.role_id = roles.id
                WHERE users.username = :username
                   OR users.email = :email
                LIMIT 1
            ");

            $stmt->execute([
                'username' => $identity,
                'email' => $identity
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                $user &&
                $user['account_status'] === 'active' &&
                password_verify($password, $user['password_hash'])
            ) {

                // Regenerate session ID after login.
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role_name'];

                // Keep the password-change flag for future implementation.
                $_SESSION['must_change_password'] =
                    (int)$user['must_change_password'];

                unset($_SESSION['csrf_token']);

                // Update last login time.
                $update = $pdo->prepare("
                    UPDATE users
                    SET last_login = NOW()
                    WHERE id = ?
                ");

                $update->execute([$user['id']]);

                // Redirect according to user role.
                if ($user['role_name'] === 'admin') {

                    header("Location: " . BASE_URL . "/admin/dashboard.php");

                } elseif ($user['role_name'] === 'teacher') {

                    header("Location: " . BASE_URL . "/teacher/dashboard.php");

                } elseif ($user['role_name'] === 'student') {

                    header("Location: " . BASE_URL . "/student/dashboard.php");

                } else {

                    session_unset();
                    session_destroy();

                    header("Location: " . BASE_URL . "/login.php");
                    exit;
                }

                exit;

            } else {

                $error = "Invalid login credentials or inactive account.";

            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Login | Carver Mission Academy</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="<?= BASE_URL ?>/assets/css/style.css">

</head>

<body class="login-body">

<div class="login-container">

    <div class="login-card">

        <div class="text-center mb-4">

            <img src="<?= BASE_URL ?>/assets/images/logo.jpg"
                 alt="Carver Mission Academy Logo"
                 class="login-logo">

            <h3 class="login-title">
                CARVER MISSION ACADEMY
            </h3>

            <p class="text-muted">
                School Management System
            </p>

            <h5>Welcome Back!</h5>

            <p class="text-muted small">
                Sign in to access your account.
            </p>

        </div>

        <?php if ($error): ?>

            <div class="alert alert-danger">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>

        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/login.php">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="mb-3">

                <label class="form-label">
                    Username or Email
                </label>

                <input type="text"
                       name="identity"
                       class="form-control"
                       placeholder="Enter your username or email"
                       autocomplete="username"
                       required>

            </div>

            <div class="mb-3">

                <label class="form-label">
                    Password
                </label>

                <input type="password"
                       name="password"
                       class="form-control"
                       placeholder="Enter your password"
                       autocomplete="current-password"
                       required>

            </div>

            <div class="d-grid">

                <button type="submit"
                        class="btn btn-primary btn-lg">
                    Sign In
                </button>

            </div>

        </form>

        <div class="text-center mt-4">

            <a href="<?= BASE_URL ?>/index.php" class="back-home">
                ← Back to Home
            </a>

        </div>

    </div>

</div>

</body>
</html>
