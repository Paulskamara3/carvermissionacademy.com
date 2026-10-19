<?php

require_once '../includes/auth.php';
require_once '../config/database.php';

requireRole('teacher');

// Get logged-in teacher's username.
$teacherUsername = $_SESSION['username'] ?? 'Teacher';

// Get teacher's profile photo using the users table relationship.
$teacherPhoto = null;

$stmt = $pdo->prepare("
    SELECT t.profile_picture
    FROM teachers AS t
    INNER JOIN users AS u ON t.user_id = u.id
    WHERE u.username = :username
    LIMIT 1
");

$stmt->execute([
    ':username' => $teacherUsername
]);

$teacher = $stmt->fetch(PDO::FETCH_ASSOC);

if ($teacher && !empty($teacher['profile_picture'])) {

    $photoFile = basename($teacher['profile_picture']);

    $photoPath = __DIR__ . '/../uploads/teachers/' . $photoFile;

    if (is_file($photoPath)) {
        $teacherPhoto = '../uploads/teachers/' . $photoFile;
    }
}

// Teacher portal navigation.
$navigation = [
    ['Dashboard', 'dashboard.php', 'bi-speedometer2'],
    ['My Classes', '#', 'bi-building'],
    ['Student Directory', '#', 'bi-people'],
    ['Take Attendance', '#', 'bi-calendar-check'],
    ['Attendance Records', '#', 'bi-clipboard-check'],
    ['Exams & Assessments', '#', 'bi-journal-text'],
    ['Enter Grades', '#', 'bi-award'],
    ['My Timetable', '#', 'bi-calendar3'],
    ['Class Performance', '#', 'bi-bar-chart'],
    ['My Profile', '#', 'bi-person-circle']
];

?>

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Teacher Dashboard | CMA</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet"
          href="../assets/css/style.css">

    <style>

        /* Teacher profile photo in top navigation */
        .topbar-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e9ecef;
            flex-shrink: 0;
        }

        .teacher-profile-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        .topbar-avatar i {
            font-size: 24px;
            color: #555;
        }

        /* Teacher profile photo in sidebar */
        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e9ecef;
            flex-shrink: 0;
        }

        .sidebar-teacher-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        .user-avatar i {
            font-size: 24px;
            color: #555;
        }

    </style>

</head>

<body class="cma-admin-body">

<div class="admin-layout">

    <!-- TEACHER SIDEBAR -->
    <aside class="admin-sidebar" id="teacherSidebar">

        <!-- SCHOOL BRAND -->
        <div class="sidebar-brand">

            <img src="../assets/images/logo.jpg"
                 alt="CMA Logo">

            <div>
                <h6>CARVER MISSION</h6>
                <small>ACADEMY</small>
            </div>

        </div>

        <div class="sidebar-divider"></div>

        <p class="sidebar-heading">TEACHER PORTAL</p>

        <!-- SIDEBAR MENU -->
        <ul class="sidebar-menu">

            <?php foreach ($navigation as $item): ?>

                <li>

                    <a href="<?= htmlspecialchars($item[1]) ?>"
                       class="<?= $item[0] === 'Dashboard' ? 'active' : '' ?>">

                        <i class="bi <?= htmlspecialchars($item[2]) ?>"></i>

                        <span><?= htmlspecialchars($item[0]) ?></span>

                    </a>

                </li>

            <?php endforeach; ?>

        </ul>

        <!-- TEACHER PROFILE AND LOGOUT -->
        <div class="sidebar-bottom">

            <div class="sidebar-user">

                <div class="user-avatar">

                    <?php if ($teacherPhoto): ?>

                        <img src="<?= htmlspecialchars($teacherPhoto) ?>"
                             alt="Teacher Profile Photo"
                             class="sidebar-teacher-photo">

                    <?php else: ?>

                        <i class="bi bi-person-fill"></i>

                    <?php endif; ?>

                </div>

                <div>

                    <strong>
                        <?= htmlspecialchars($teacherUsername) ?>
                    </strong>

                    <small>Teacher Account</small>

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

        <!-- TOP NAVBAR -->
        <header class="admin-topbar">

            <div class="d-flex align-items-center gap-3">

                <button class="sidebar-toggle"
                        id="teacherSidebarToggle"
                        type="button"
                        aria-label="Toggle sidebar">

                    <i class="bi bi-list"></i>

                </button>

                <div>

                    <h5>Teacher Dashboard</h5>

                    <small>Carver Mission Academy</small>

                </div>

            </div>

            <div class="admin-topbar-right">

                <span class="welcome-text">

                    Welcome,
                    <?= htmlspecialchars($teacherUsername) ?>

                </span>

                <div class="topbar-avatar">

                    <?php if ($teacherPhoto): ?>

                        <img src="<?= htmlspecialchars($teacherPhoto) ?>"
                             alt="Teacher Profile Photo"
                             class="teacher-profile-image">

                    <?php else: ?>

                        <i class="bi bi-person-fill"></i>

                    <?php endif; ?>

                </div>

            </div>

        </header>


        <!-- TEACHER DASHBOARD CONTENT -->
        <section class="admin-content">

            <!-- WELCOME SECTION -->
            <div class="dashboard-heading">

                <div>

                    <h3>Welcome to Your Teacher Portal</h3>

                    <p>
                        Manage your classes, students, attendance,
                        examinations and academic activities.
                    </p>

                </div>

                <div class="dashboard-date">

                    <i class="bi bi-calendar-event"></i>

                    <?= date('l, d F Y') ?>

                </div>

            </div>


            <!-- TEACHER OVERVIEW -->
            <div class="row g-4">

                <div class="col-sm-6 col-lg-4">

                    <div class="admin-stat-card">

                        <div class="stat-icon students-icon">

                            <i class="bi bi-building"></i>

                        </div>

                        <div>

                            <p>My Classes</p>

                            <h3>--</h3>

                        </div>

                    </div>

                </div>


                <div class="col-sm-6 col-lg-4">

                    <div class="admin-stat-card">

                        <div class="stat-icon teachers-icon">

                            <i class="bi bi-people-fill"></i>

                        </div>

                        <div>

                            <p>My Students</p>

                            <h3>--</h3>

                        </div>

                    </div>

                </div>


                <div class="col-sm-6 col-lg-4">

                    <div class="admin-stat-card">

                        <div class="stat-icon subjects-icon">

                            <i class="bi bi-journal-check"></i>

                        </div>

                        <div>

                            <p>Assessments</p>

                            <h3>--</h3>

                        </div>

                    </div>

                </div>

            </div>


            <!-- QUICK ACCESS -->
            <div class="section-header">

                <div>

                    <h4>Teaching Activities</h4>

                    <p>
                        Quick access to your teaching responsibilities.
                    </p>

                </div>

            </div>


            <div class="row g-4">

                <?php

                $modules = [
                    ['My Classes', 'View your assigned classes', 'bi-building'],
                    ['Student Directory', 'View your students information', 'bi-people-fill'],
                    ['Take Attendance', 'Record student attendance', 'bi-calendar-check'],
                    ['Attendance Records', 'Review attendance history', 'bi-clipboard-check'],
                    ['Exams & Assessments', 'Manage examinations and tests', 'bi-journal-text'],
                    ['Enter Grades', 'Record student examination grades', 'bi-award-fill'],
                    ['My Timetable', 'View your teaching schedule', 'bi-calendar3'],
                    ['Class Performance', 'Monitor student performance', 'bi-bar-chart-fill'],
                    ['My Profile', 'View and update your profile', 'bi-person-circle']
                ];

                ?>

                <?php foreach ($modules as $module): ?>

                    <div class="col-sm-6 col-lg-4 col-xl-3">

                        <a href="#"
                           class="admin-module-card">

                            <div class="module-icon">

                                <i class="bi <?= htmlspecialchars($module[2]) ?>"></i>

                            </div>

                            <h6>
                                <?= htmlspecialchars($module[0]) ?>
                            </h6>

                            <p>
                                <?= htmlspecialchars($module[1]) ?>
                            </p>

                            <span class="module-arrow">

                                <i class="bi bi-arrow-right"></i>

                            </span>

                        </a>

                    </div>

                <?php endforeach; ?>

            </div>


            <!-- FOOTER -->
            <footer class="admin-footer">

                <p>
                    &copy; <?= date('Y') ?> Carver Mission Academy.
                    All Rights Reserved.
                </p>

                <p>Teacher Management Portal</p>

            </footer>

        </section>

    </main>

</div>


<!-- JAVASCRIPT -->
<script>

const sidebarToggle = document.getElementById('teacherSidebarToggle');

const teacherSidebar = document.getElementById('teacherSidebar');

// Toggle sidebar on mobile.
sidebarToggle.addEventListener('click', function () {

    teacherSidebar.classList.toggle('show');

});

// Close sidebar when clicking outside.
document.addEventListener('click', function (event) {

    const clickedInsideSidebar = teacherSidebar.contains(event.target);

    const clickedToggle = sidebarToggle.contains(event.target);

    if (
        window.innerWidth <= 768 &&
        !clickedInsideSidebar &&
        !clickedToggle
    ) {

        teacherSidebar.classList.remove('show');

    }

});

</script>

</body>
</html>