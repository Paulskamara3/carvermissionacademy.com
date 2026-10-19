<?php
$activePage = $activePage ?? '';

$navigation = [
    ['Dashboard', BASE_URL . '/admin/dashboard.php', 'bi-speedometer2', 'dashboard'],
    ['Students', BASE_URL . '/admin/students/index.php', 'bi-people', 'students'],
    ['Enrollments', BASE_URL . '/admin/enrollments/index.php', 'bi-person-plus', 'enrollments'],
    ['Teachers', BASE_URL . '/admin/teachers/index.php', 'bi-person-badge', 'teachers'],
    ['Classes & Sections', BASE_URL . '/admin/classes/index.php', 'bi-building', 'classes'],
    ['Subjects', BASE_URL . '/admin/subjects/index.php', 'bi-book', 'subjects'],
    ['Academic Years', BASE_URL . '/admin/academic_years/index.php', 'bi-calendar-range', 'academic_years'],
    ['Attendance', BASE_URL . '/admin/attendance/index.php', 'bi-calendar-check', 'attendance'],
    ['Exams & Results', BASE_URL . '/admin/exams/index.php', 'bi-journal-check', 'exams'],
    ['Fees & Payments', BASE_URL . '/admin/fees/index.php', 'bi-cash-stack', 'fees'],
    ['Timetable', BASE_URL . '/admin/timetable/index.php', 'bi-calendar3', 'timetable'],
    ['Reports', BASE_URL . '/admin/reports/index.php', 'bi-bar-chart', 'reports'],
    ['User Management', BASE_URL . '/admin/users/index.php', 'bi-shield-lock', 'users'],
    ['Contact Messages', BASE_URL . '/admin/contact_messages/index.php', 'bi-envelope', 'contact_messages'],
    ['Media Management', BASE_URL . '/admin/media/index.php', 'bi-images', 'media'],
];
?>

<aside class="admin-sidebar" id="adminSidebar">

    <div class="sidebar-brand">
        <img src="<?= BASE_URL ?>/assets/images/logo.jpg" alt="CMA Logo">

        <div>
            <h5>CARVER MISSION</h5>
            <small>ADMINISTRATION</small>
        </div>
    </div>

    <div class="sidebar-menu">

        <p class="sidebar-heading">MAIN MENU</p>

        <?php foreach ($navigation as $item): ?>

            <a href="<?= htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') ?>"
               class="sidebar-link <?= $activePage === $item[3] ? 'active' : '' ?>">

                <i class="bi <?= htmlspecialchars($item[2], ENT_QUOTES, 'UTF-8') ?>"></i>

                <span><?= htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') ?></span>

            </a>

        <?php endforeach; ?>

    </div>

    <div class="sidebar-bottom">

        <div class="sidebar-user">
            <i class="bi bi-person-circle"></i>

            <div>
                <strong>
                    <?= htmlspecialchars($_SESSION['username'] ?? 'Administrator', ENT_QUOTES, 'UTF-8') ?>
                </strong>
                <small>Administrator</small>
            </div>
        </div>

        <form action="<?= BASE_URL ?>/logout.php" method="POST">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <button type="submit" class="sidebar-logout">
                <i class="bi bi-box-arrow-right"></i>
                Logout
            </button>

        </form>

    </div>

</aside>
