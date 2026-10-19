<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

$activePage = 'reports';

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// --------------------------------------------------
// FILTERS
// --------------------------------------------------

$search = trim($_GET['search'] ?? '');
$gender = trim($_GET['gender'] ?? '');
$status = trim($_GET['status'] ?? '');

$academicYearId = (int)($_GET['academic_year_id'] ?? 0);
$classId = (int)($_GET['class_id'] ?? 0);
$sectionId = (int)($_GET['section_id'] ?? 0);

$allowedGenders = ['male', 'female'];

$allowedStatuses = [
    'active',
    'inactive',
    'graduated',
    'transferred',
    'suspended'
];

if (!in_array($gender, $allowedGenders, true)) {
    $gender = '';
}

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

try {

    // --------------------------------------------------
    // ACADEMIC YEARS
    // --------------------------------------------------

    $yearStmt = $pdo->query("
        SELECT id, year_name, status
        FROM academic_years
        ORDER BY start_date DESC
    ");

    $academicYears = $yearStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!isset($_GET['academic_year_id'])) {

        foreach ($academicYears as $year) {
            if ($year['status'] === 'active') {
                $academicYearId = (int)$year['id'];
                break;
            }
        }

        if ($academicYearId === 0 && !empty($academicYears)) {
            $academicYearId = (int)$academicYears[0]['id'];
        }
    }

    $validYearIds = array_map(
        'intval',
        array_column($academicYears, 'id')
    );

    if (
        $academicYearId !== 0 &&
        !in_array($academicYearId, $validYearIds, true)
    ) {
        $academicYearId = 0;
    }

    // --------------------------------------------------
    // CLASSES
    // --------------------------------------------------

    $classStmt = $pdo->query("
        SELECT id, class_name
        FROM classes
        ORDER BY class_name ASC
    ");

    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    $validClassIds = array_map(
        'intval',
        array_column($classes, 'id')
    );

    if (
        $classId !== 0 &&
        !in_array($classId, $validClassIds, true)
    ) {
        $classId = 0;
    }

    // --------------------------------------------------
    // SECTIONS
    // --------------------------------------------------

    $sectionStmt = $pdo->query("
        SELECT id, class_id, section_name
        FROM sections
        ORDER BY section_name ASC
    ");

    $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC);

    $validSectionIds = array_map(
        'intval',
        array_column($sections, 'id')
    );

    if (
        $sectionId !== 0 &&
        !in_array($sectionId, $validSectionIds, true)
    ) {
        $sectionId = 0;
    }

    // --------------------------------------------------
    // STUDENT REPORT QUERY
    // --------------------------------------------------

    $yearCondition = '';
    $params = [];

    if ($academicYearId > 0) {
        $yearCondition = " AND e2.academic_year_id = :join_year_id";
        $params['join_year_id'] = $academicYearId;
    }

    $sql = "
        SELECT
            s.id,
            s.admission_number,
            s.first_name,
            s.middle_name,
            s.last_name,
            s.gender,
            s.phone,
            s.email,
            s.admission_date,
            s.student_status,

            c.class_name,
            sec.section_name

        FROM students s

        LEFT JOIN enrollments en
            ON en.id = (
                SELECT MAX(e2.id)
                FROM enrollments e2
                WHERE e2.student_id = s.id
                $yearCondition
            )

        LEFT JOIN classes c
            ON c.id = en.class_id

        LEFT JOIN sections sec
            ON sec.id = en.section_id

        WHERE 1 = 1
    ";

    if ($search !== '') {

        $sql .= "
            AND (
                s.admission_number LIKE :search1
                OR s.first_name LIKE :search2
                OR s.middle_name LIKE :search3
                OR s.last_name LIKE :search4
                OR s.phone LIKE :search5
            )
        ";

        $term = "%{$search}%";

        $params['search1'] = $term;
        $params['search2'] = $term;
        $params['search3'] = $term;
        $params['search4'] = $term;
        $params['search5'] = $term;
    }

    if ($gender !== '') {
        $sql .= " AND s.gender = :gender";
        $params['gender'] = $gender;
    }

    if ($status !== '') {
        $sql .= " AND s.student_status = :student_status";
        $params['student_status'] = $status;
    }

    if ($classId > 0) {
        $sql .= " AND en.class_id = :class_id";
        $params['class_id'] = $classId;
    }

    if ($sectionId > 0) {
        $sql .= " AND en.section_id = :section_id";
        $params['section_id'] = $sectionId;
    }

    $sql .= "
        ORDER BY s.first_name ASC, s.last_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --------------------------------------------------
    // REPORT STATISTICS
    // --------------------------------------------------

    $totalStudents = count($students);
    $maleStudents = 0;
    $femaleStudents = 0;
    $activeStudents = 0;
    $otherStudents = 0;

    foreach ($students as $student) {

        if (strtolower($student['gender'] ?? '') === 'male') {
            $maleStudents++;
        }

        if (strtolower($student['gender'] ?? '') === 'female') {
            $femaleStudents++;
        }

        if (strtolower($student['student_status'] ?? '') === 'active') {
            $activeStudents++;
        } else {
            $otherStudents++;
        }
    }

    // --------------------------------------------------
    // CSV EXPORT
    // --------------------------------------------------

    if (($_GET['export'] ?? '') === 'csv') {

        $filename = 'CMA_Student_Report_' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, [
            'Admission Number',
            'First Name',
            'Middle Name',
            'Last Name',
            'Gender',
            'Phone',
            'Email',
            'Class',
            'Section',
            'Admission Date',
            'Student Status'
        ]);

        foreach ($students as $student) {

            fputcsv($output, [
                $student['admission_number'],
                $student['first_name'],
                $student['middle_name'],
                $student['last_name'],
                $student['gender'],
                $student['phone'],
                $student['email'],
                $student['class_name'],
                $student['section_name'],
                $student['admission_date'],
                $student['student_status']
            ]);
        }

        fclose($output);
        exit;
    }

} catch (PDOException $exception) {

    error_log("Student Reports Error: " . $exception->getMessage());

    die("Unable to generate student reports. Please check your database.");
}

// --------------------------------------------------
// HELPER FUNCTIONS
// --------------------------------------------------

function statusBadge($status) {

    switch (strtolower($status ?? '')) {

        case 'active':
            return 'success';

        case 'inactive':
            return 'secondary';

        case 'graduated':
            return 'primary';

        case 'transferred':
            return 'info';

        case 'suspended':
            return 'danger';

        default:
            return 'secondary';
    }
}

function reportUrl($changes = []) {

    $filters = [
        'search' => $_GET['search'] ?? '',
        'gender' => $_GET['gender'] ?? '',
        'status' => $_GET['status'] ?? '',
        'academic_year_id' => $_GET['academic_year_id'] ?? '',
        'class_id' => $_GET['class_id'] ?? '',
        'section_id' => $_GET['section_id'] ?? ''
    ];

    $filters = array_merge($filters, $changes);

    foreach ($filters as $key => $value) {

        if ($value === '' || $value === null) {
            unset($filters[$key]);
        }
    }

    return 'students.php?' . http_build_query($filters);
}

// --------------------------------------------------
// PRINT INFORMATION
// --------------------------------------------------

$generatedDate = date('d F Y');
$generatedTime = date('h:i A');

$selectedYearName = 'All Academic Years';

foreach ($academicYears as $year) {

    if ((int)$year['id'] === $academicYearId) {
        $selectedYearName = $year['year_name'];
        break;
    }
}

$selectedClassName = 'All Classes';

foreach ($classes as $class) {

    if ((int)$class['id'] === $classId) {
        $selectedClassName = $class['class_name'];
        break;
    }
}

$selectedSectionName = 'All Sections';

foreach ($sections as $section) {

    if ((int)$section['id'] === $sectionId) {
        $selectedSectionName = $section['section_name'];
        break;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Student Reports | Carver Mission Academy</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="<?= BASE_URL ?>/assets/css/style.css">

    <style>

        body {
            background: #f4f6fa;
            font-family: Arial, sans-serif;
        }

        .reports-content {
            padding: 30px;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .page-heading h2 {
            font-weight: 700;
            color: #172554;
        }

        .summary-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.05);
            height: 100%;
        }

        .summary-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
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

        .filter-card,
        .report-table-card {
            background: #fff;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.05);
        }

        .report-table-card {
            margin-top: 25px;
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

        .student-name {
            font-weight: 600;
            color: #172554;
            min-width: 150px;
        }

        .filter-card label {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 6px;
        }

        .report-heading {
            color: #172554;
            font-weight: 700;
        }

        .empty-state {
            text-align: center;
            padding: 45px 15px;
            color: #64748b;
        }

        .print-header {
            display: none;
        }

        @media (max-width: 768px) {

            .reports-content {
                padding: 18px;
                padding-top: 70px;
            }
        }

        /* --------------------------------------------------
           PRINT DESIGN
        -------------------------------------------------- */

        @media print {

            @page {
                size: A4 landscape;
                margin: 10mm;
            }

            html,
            body {
                width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                background: #fff !important;
                color: #000 !important;
                font-family: Arial, sans-serif !important;
                font-size: 10px !important;
            }

            /* Make report content visible */

            .reports-content,
            .reports-content * {
                visibility: visible !important;
            }

            /* Hide navigation and controls */

            .sidebar,
            #adminSidebar,
            .sidebar-toggle,
            .no-print,
            .filter-card,
            .page-heading,
            nav,
            button {
                display: none !important;
            }

            /* Reset dashboard layout */

            .admin-main,
            main.admin-main {
                display: block !important;
                position: static !important;
                width: 100% !important;
                max-width: none !important;
                min-width: 0 !important;
                height: auto !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                transform: none !important;
                float: none !important;
            }

            .reports-content {
                display: block !important;
                position: static !important;
                width: 100% !important;
                max-width: none !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                transform: none !important;
            }

            /* School and report heading */

            .print-header {
                display: block !important;
                position: static !important;
                width: 100% !important;
                margin: 0 0 18px 0 !important;
                padding: 0 !important;
                text-align: center !important;
                color: #000 !important;
            }

            .print-header h2 {
                font-size: 22px !important;
                font-weight: bold !important;
                color: #000 !important;
                margin-bottom: 5px !important;
            }

            .print-header h4 {
                font-size: 16px !important;
                font-weight: bold !important;
                color: #000 !important;
                margin-bottom: 5px !important;
            }

            .print-header p {
                color: #000 !important;
                margin-bottom: 8px !important;
            }

            .print-details {
                display: flex !important;
                justify-content: space-between !important;
                flex-wrap: wrap !important;
                gap: 8px !important;
                width: 100% !important;
                text-align: left !important;
                font-size: 10px !important;
                margin-top: 8px !important;
                color: #000 !important;
            }

            /* Summary cards */

            .reports-content > .row {
                display: flex !important;
                flex-wrap: nowrap !important;
                width: 100% !important;
                margin: 0 0 12px 0 !important;
                gap: 8px !important;
            }

            .reports-content > .row > div {
                display: block !important;
                flex: 1 1 25% !important;
                width: 25% !important;
                max-width: 25% !important;
                padding: 0 !important;
            }

            .summary-card {
                display: flex !important;
                align-items: center !important;
                width: 100% !important;
                height: auto !important;
                min-height: 55px !important;
                padding: 9px !important;
                border: 1px solid #777 !important;
                border-radius: 0 !important;
                background: #fff !important;
                box-shadow: none !important;
                color: #000 !important;
            }

            .summary-card h3 {
                color: #000 !important;
                font-size: 16px !important;
                margin: 0 !important;
            }

            .summary-card p {
                color: #000 !important;
                font-size: 10px !important;
                margin: 2px 0 0 !important;
            }

            .summary-icon {
                display: none !important;
            }

            /* Report table card */

            .report-table-card {
                display: block !important;
                position: static !important;
                width: 100% !important;
                max-width: none !important;
                height: auto !important;
                margin: 10px 0 0 !important;
                padding: 0 !important;
                border: none !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                background: #fff !important;
                overflow: visible !important;
            }

            .report-table-card > .d-flex {
                display: block !important;
                width: 100% !important;
                margin-bottom: 10px !important;
            }

            .report-table-card h5 {
                color: #000 !important;
                font-size: 14px !important;
                font-weight: bold !important;
            }

            .report-table-card p,
            .report-table-card .text-muted {
                color: #000 !important;
            }

            /* Remove responsive table clipping */

            .table-responsive {
                display: block !important;
                position: static !important;
                width: 100% !important;
                max-width: none !important;
                height: auto !important;
                overflow: visible !important;
                transform: none !important;
            }

            /* Table */

            table.table {
                display: table !important;
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                border-collapse: collapse !important;
                border-spacing: 0 !important;
                table-layout: auto !important;
                font-size: 9px !important;
                color: #000 !important;
                overflow: visible !important;
            }

            .table thead {
                display: table-header-group !important;
            }

            .table tbody {
                display: table-row-group !important;
            }

            .table tr {
                display: table-row !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            .table th,
            .table td {
                display: table-cell !important;
                visibility: visible !important;
                border: 1px solid #555 !important;
                padding: 5px 4px !important;
                color: #000 !important;
                background: #fff !important;
                font-size: 9px !important;
                vertical-align: middle !important;
                white-space: normal !important;
            }

            .table thead th {
                background: #e9ecef !important;
                color: #000 !important;
                font-weight: bold !important;
            }

            .student-name {
                color: #000 !important;
                min-width: 0 !important;
            }

            .badge {
                color: #000 !important;
                background: #fff !important;
                border: 1px solid #777 !important;
                font-size: 8px !important;
                font-weight: normal !important;
            }

            .text-muted {
                color: #000 !important;
            }

            .empty-state {
                display: block !important;
                padding: 15px !important;
                color: #000 !important;
            }

            .empty-state i {
                display: none !important;
            }

            /* Report footer */

            .reports-content > .text-center {
                display: block !important;
                width: 100% !important;
                margin-top: 15px !important;
                padding-top: 8px !important;
                border-top: 1px solid #777 !important;
                color: #000 !important;
                text-align: center !important;
            }

            /* Improve printed colors */

            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                text-shadow: none !important;
            }

            a {
                color: #000 !important;
                text-decoration: none !important;
            }
        }

    </style>

</head>

<body>

<?php require_once __DIR__ . '/../../includes/admin_sidebar.php'; ?>

<button class="sidebar-toggle no-print"
        id="sidebarToggle"
        type="button">

    <i class="bi bi-list"></i>

</button>

<main class="admin-main">

    <div class="reports-content">

        <!-- PAGE HEADING -->

        <div class="page-heading">

            <div>

                <h2>Student Reports</h2>

                <p class="text-muted mb-0">
                    View and generate student enrollment and admission reports.
                </p>

            </div>

            <div class="d-flex gap-2 flex-wrap no-print">

                <a href="index.php"
                   class="btn btn-outline-secondary">

                    <i class="bi bi-arrow-left"></i>
                    Reports Dashboard

                </a>

                <button type="button"
                        onclick="window.print()"
                        class="btn btn-outline-primary">

                    <i class="bi bi-printer"></i>
                    Print Report

                </button>

                <a href="<?= e(reportUrl(['export' => 'csv'])) ?>"
                   class="btn btn-success">

                    <i class="bi bi-file-earmark-spreadsheet"></i>
                    Export CSV

                </a>

            </div>

        </div>

        <!-- PRINT HEADER -->

        <div class="print-header">

            <h2>CARVER MISSION ACADEMY</h2>

            <h4>STUDENT REPORT</h4>

            <p>Student Enrollment and Admission Records</p>

            <hr>

            <div class="print-details">

                <span>
                    <strong>Generated:</strong>
                    <?= e($generatedDate) ?> at <?= e($generatedTime) ?>
                </span>

                <span>
                    <strong>Academic Year:</strong>
                    <?= e($selectedYearName) ?>
                </span>

            </div>

            <div class="print-details">

                <span>
                    <strong>Class:</strong>
                    <?= e($selectedClassName) ?>
                </span>

                <span>
                    <strong>Section:</strong>
                    <?= e($selectedSectionName) ?>
                </span>

            </div>

            <?php if ($search !== ''): ?>

                <p class="text-start mt-2">
                    <strong>Search:</strong> <?= e($search) ?>
                </p>

            <?php endif; ?>

            <?php if ($gender !== ''): ?>

                <p class="text-start mb-1">
                    <strong>Gender Filter:</strong> <?= e(ucfirst($gender)) ?>
                </p>

            <?php endif; ?>

            <?php if ($status !== ''): ?>

                <p class="text-start mb-1">
                    <strong>Status Filter:</strong> <?= e(ucfirst($status)) ?>
                </p>

            <?php endif; ?>

        </div>

        <!-- SUMMARY CARDS -->

        <div class="row g-3 mb-4">

            <div class="col-xl-3 col-md-6 col-6">

                <div class="summary-card">

                    <div class="summary-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-people-fill"></i>
                    </div>

                    <div>
                        <h3><?= e($totalStudents) ?></h3>
                        <p>Total Students</p>
                    </div>

                </div>

            </div>

            <div class="col-xl-3 col-md-6 col-6">

                <div class="summary-card">

                    <div class="summary-icon bg-info bg-opacity-10 text-info">
                        <i class="bi bi-gender-male"></i>
                    </div>

                    <div>
                        <h3><?= e($maleStudents) ?></h3>
                        <p>Male Students</p>
                    </div>

                </div>

            </div>

            <div class="col-xl-3 col-md-6 col-6">

                <div class="summary-card">

                    <div class="summary-icon bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-gender-female"></i>
                    </div>

                    <div>
                        <h3><?= e($femaleStudents) ?></h3>
                        <p>Female Students</p>
                    </div>

                </div>

            </div>

            <div class="col-xl-3 col-md-6 col-6">

                <div class="summary-card">

                    <div class="summary-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-person-check-fill"></i>
                    </div>

                    <div>
                        <h3><?= e($activeStudents) ?></h3>
                        <p>Active Students</p>
                    </div>

                </div>

            </div>

        </div>

        <!-- FILTERS -->

        <div class="filter-card no-print">

            <h5 class="report-heading mb-3">

                <i class="bi bi-funnel me-2"></i>
                Filter Student Reports

            </h5>

            <form method="GET" action="students.php">

                <div class="row g-3">

                    <div class="col-lg-4 col-md-6">

                        <label>Search Student</label>

                        <input type="search"
                               name="search"
                               class="form-control"
                               placeholder="Name, admission number or phone"
                               value="<?= e($search) ?>">

                    </div>

                    <div class="col-lg-2 col-md-6">

                        <label>Gender</label>

                        <select name="gender" class="form-select">

                            <option value="">All Genders</option>

                            <option value="male"
                                <?= $gender === 'male' ? 'selected' : '' ?>>
                                Male
                            </option>

                            <option value="female"
                                <?= $gender === 'female' ? 'selected' : '' ?>>
                                Female
                            </option>

                        </select>

                    </div>

                    <div class="col-lg-2 col-md-6">

                        <label>Student Status</label>

                        <select name="status" class="form-select">

                            <option value="">All Statuses</option>

                            <?php foreach ($allowedStatuses as $studentStatus): ?>

                                <option value="<?= e($studentStatus) ?>"
                                    <?= $status === $studentStatus ? 'selected' : '' ?>>

                                    <?= e(ucfirst($studentStatus)) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-4 col-md-6">

                        <label>Academic Year</label>

                        <select name="academic_year_id" class="form-select">

                            <option value="0"
                                <?= $academicYearId === 0 ? 'selected' : '' ?>>
                                All Available Enrollment Years
                            </option>

                            <?php foreach ($academicYears as $year): ?>

                                <option value="<?= (int)$year['id'] ?>"
                                    <?= $academicYearId === (int)$year['id'] ? 'selected' : '' ?>>

                                    <?= e($year['year_name']) ?>

                                    <?= $year['status'] === 'active' ? '(Active)' : '' ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-4 col-md-6">

                        <label>Class</label>

                        <select name="class_id" class="form-select">

                            <option value="0">All Classes</option>

                            <?php foreach ($classes as $class): ?>

                                <option value="<?= (int)$class['id'] ?>"
                                    <?= $classId === (int)$class['id'] ? 'selected' : '' ?>>

                                    <?= e($class['class_name']) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-4 col-md-6">

                        <label>Section</label>

                        <select name="section_id" class="form-select">

                            <option value="0">All Sections</option>

                            <?php foreach ($sections as $section): ?>

                                <option value="<?= (int)$section['id'] ?>"
                                    <?= $sectionId === (int)$section['id'] ? 'selected' : '' ?>>

                                    <?= e($section['section_name']) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-4 d-flex align-items-end gap-2">

                        <button type="submit" class="btn btn-primary">

                            <i class="bi bi-search"></i>
                            Generate Report

                        </button>

                        <a href="students.php"
                           class="btn btn-outline-secondary">

                            <i class="bi bi-arrow-counterclockwise"></i>
                            Reset

                        </a>

                    </div>

                </div>

            </form>

        </div>

        <!-- STUDENT REPORT TABLE -->

        <div class="report-table-card">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

                <div>

                    <h5 class="report-heading mb-1">
                        Student Records
                    </h5>

                    <p class="text-muted small mb-0">
                        <?= e($totalStudents) ?> student record(s) found.
                    </p>

                </div>

                <div class="small text-muted no-print">
                    Generated: <?= e($generatedDate) ?>, <?= e($generatedTime) ?>
                </div>

            </div>

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead>

                        <tr>
                            <th>#</th>
                            <th>Admission Number</th>
                            <th>Student Name</th>
                            <th>Gender</th>
                            <th>Phone</th>
                            <th>Class</th>
                            <th>Section</th>
                            <th>Admission Date</th>
                            <th>Status</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php if (!empty($students)): ?>

                        <?php foreach ($students as $index => $student): ?>

                            <tr>

                                <td><?= $index + 1 ?></td>

                                <td class="fw-semibold">
                                    <?= e($student['admission_number']) ?>
                                </td>

                                <td class="student-name">

                                    <?= e($student['first_name']) ?>

                                    <?= e($student['middle_name']) ?>

                                    <?= e($student['last_name']) ?>

                                </td>

                                <td>
                                    <?= e(ucfirst($student['gender'])) ?>
                                </td>

                                <td>
                                    <?= e($student['phone']) ?>
                                </td>

                                <td>
                                    <?= e($student['class_name'] ?? 'Not Enrolled') ?>
                                </td>

                                <td>
                                    <?= e($student['section_name'] ?? '-') ?>
                                </td>

                                <td>
                                    <?= e($student['admission_date']) ?>
                                </td>

                                <td>

                                    <span class="badge bg-<?= e(statusBadge($student['student_status'])) ?>">

                                        <?= e(ucfirst($student['student_status'])) ?>

                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="9">

                                <div class="empty-state">

                                    <i class="bi bi-people fs-1"></i>

                                    <h5 class="mt-3">
                                        No Students Found
                                    </h5>

                                    <p>
                                        No student records match the selected filters.
                                    </p>

                                </div>

                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <div class="text-muted small mt-3">

                Showing <?= e($totalStudents) ?> matching student record(s).

            </div>

        </div>

        <!-- FOOTER -->

        <div class="text-center text-muted small mt-4">

            &copy; <?= date('Y') ?> Carver Mission Academy.
            Student Reports.

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
