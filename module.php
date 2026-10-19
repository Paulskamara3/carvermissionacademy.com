<?php

require_once '../includes/auth.php';
require_once '../config/database.php';

requireRole('admin');

// All recognized system modules
$modules = [

    'students' => [
        'title' => 'Student Management',
        'description' => 'Register, view, update and manage student records.',
        'icon' => 'bi-people-fill'
    ],

    'enrollments' => [
        'title' => 'Enrollment Management',
        'description' => 'Manage student admissions and academic enrollment.',
        'icon' => 'bi-person-plus-fill'
    ],

    'teachers' => [
        'title' => 'Teacher Management',
        'description' => 'Manage teacher profiles and employment information.',
        'icon' => 'bi-person-badge-fill'
    ],

    'classes' => [
        'title' => 'Classes & Sections',
        'description' => 'Create and manage classes and sections.',
        'icon' => 'bi-building'
    ],

    'subjects' => [
        'title' => 'Subject Management',
        'description' => 'Create and manage academic subjects.',
        'icon' => 'bi-book-fill'
    ],
    'academic_years' => [
        'title' => 'Academic Year Management',
        'description' => 'Create and manage academic years and terms.',
        'icon' => 'bi-calendar-range-fill'
    ],

    'attendance' => [
        'title' => 'Attendance Management',
        'description' => 'Monitor and manage student attendance.',
        'icon' => 'bi-calendar-check-fill'
    ],

    'exams' => [
        'title' => 'Exams & Results',
        'description' => 'Manage examinations, assessments and student results.',
        'icon' => 'bi-journal-check'
    ],

    'fees' => [
        'title' => 'Fees & Payments',
        'description' => 'Manage school fees, payments and balances.',
        'icon' => 'bi-cash-stack'
    ],

    'timetable' => [
        'title' => 'Timetable Management',
        'description' => 'Create and manage class timetables.',
        'icon' => 'bi-calendar3'
    ],

    'reports' => [
        'title' => 'School Reports',
        'description' => 'View and generate school management reports.',
        'icon' => 'bi-bar-chart-fill'
    ],

    'users' => [
        'title' => 'User Management',
        'description' => 'Manage administrator, teacher and student accounts.',
        'icon' => 'bi-shield-lock-fill'
    ],

    'settings' => [
        'title' => 'Settings & Audit Logs',
        'description' => 'Manage system settings and review system activity.',
        'icon' => 'bi-gear-fill'
    ]

];

// Get selected module
$moduleKey = $_GET['module'] ?? '';

// Validate module
if (!isset($modules[$moduleKey])) {
    http_response_code(404);
    exit('Module not found.');
}

$currentModule = $modules[$moduleKey];

// Check whether a module page exists.
// Module files should be stored in admin/modules/
$moduleFile = __DIR__ . '/modules/' . $moduleKey . '.php';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= htmlspecialchars($currentModule['title']) ?> | CMA
    </title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet"
          href="../assets/css/style.css">

</head>

<body class="cma-admin-body">

<div class="admin-layout">

    <!-- SIDEBAR -->
    <aside class="admin-sidebar">

        <div class="sidebar-brand">

            <img src="../assets/images/logo.jpg" alt="CMA Logo">

            <div>
                <h6>CARVER MISSION</h6>
                <small>ACADEMY</small>
            </div>

        </div>

        <div class="sidebar-divider"></div>

        <p class="sidebar-heading">MAIN MENU</p>

        <ul class="sidebar-menu">

            <li>
                <a href="dashboard.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <?php foreach ($modules as $key => $module): ?>

                <li>

                    <a href="module.php?module=<?= urlencode($key) ?>"
                       class="<?= $key === $moduleKey ? 'active' : '' ?>">

                        <i class="bi <?= htmlspecialchars($module['icon']) ?>"></i>

                        <span>
                            <?= htmlspecialchars($module['title']) ?>
                        </span>

                    </a>

                </li>

            <?php endforeach; ?>

        </ul>

        <!-- SIDEBAR BOTTOM -->
        <div class="sidebar-bottom">

            <div class="sidebar-user">

                <div class="user-avatar">
                    <i class="bi bi-person-fill"></i>
                </div>

                <div>
                    <strong>
                        <?= htmlspecialchars($_SESSION['username'] ?? 'Administrator') ?>
                    </strong>

                    <small>Administrator</small>
                </div>

            </div>

            <form method="POST" action="../logout.php">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= htmlspecialchars(generateCsrfToken()) ?>">

                <button type="submit" class="sidebar-logout">

                    <i class="bi bi-box-arrow-right"></i>

                    Logout

                </button>

            </form>

        </div>

    </aside>


    <!-- MAIN CONTENT -->
    <main class="admin-main">

        <!-- TOPBAR -->
        <header class="admin-topbar">

            <div>

                <h5>
                    <?= htmlspecialchars($currentModule['title']) ?>
                </h5>

                <small>Carver Mission Academy</small>

            </div>

            <div class="admin-topbar-right">

                <span class="welcome-text">

                    Welcome,
                    <?= htmlspecialchars($_SESSION['username'] ?? 'Administrator') ?>

                </span>

                <div class="topbar-avatar">
                    <i class="bi bi-person-fill"></i>
                </div>

            </div>

        </header>


        <!-- MODULE CONTENT -->
        <section class="admin-content">

            <div class="dashboard-heading">

                <div>

                    <h3>
                        <?= htmlspecialchars($currentModule['title']) ?>
                    </h3>

                    <p>
                        <?= htmlspecialchars($currentModule['description']) ?>
                    </p>

                </div>

            </div>


            <?php if (is_file($moduleFile)): ?>

                <!-- LOAD EXISTING MODULE -->

                <?php require $moduleFile; ?>


            <?php else: ?>

                <!-- MODULE NOT YET DEVELOPED -->

                <div class="card border-0 shadow-sm p-4">

                    <div class="text-center py-5">

                        <div class="module-icon mx-auto mb-3"
                             style="width:65px;height:65px;font-size:28px;">

                            <i class="bi <?= htmlspecialchars($currentModule['icon']) ?>"></i>

                        </div>

                        <h4 class="fw-bold">

                            <?= htmlspecialchars($currentModule['title']) ?>

                        </h4>

                        <p class="text-muted">

                            This module has been registered successfully.

                            Its management features are not yet available.
                            They will appear here once developed.

                        </p>

                        <span class="badge bg-warning text-dark">

                            <i class="bi bi-clock-history"></i>
                            Under Development

                        </span>

                        <div class="mt-4">

                            <a href="dashboard.php"
                               class="btn btn-primary">

                                <i class="bi bi-arrow-left"></i>

                                Back to Dashboard

                            </a>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </section>

    </main>

</div>

</body>
</html>
