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

/* --------------------------------
   FILTERS
--------------------------------- */
$classId = trim($_GET['class_id'] ?? '');
$academicYearId = trim($_GET['academic_year_id'] ?? '');
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

/* --------------------------------
   DROPDOWN DATA
--------------------------------- */
$classes = $pdo->query("
    SELECT id, class_name
    FROM classes
    ORDER BY class_name
")->fetchAll();

$academicYears = $pdo->query("
    SELECT id, year_name
    FROM academic_years
    ORDER BY start_date DESC
")->fetchAll();

$statuses = $pdo->query("
    SELECT DISTINCT enrollment_status
    FROM enrollments
    WHERE enrollment_status IS NOT NULL
      AND enrollment_status <> ''
    ORDER BY enrollment_status
")->fetchAll(PDO::FETCH_COLUMN);

/* --------------------------------
   GENERAL COUNTS
--------------------------------- */
$totalClasses = (int)$pdo->query("
    SELECT COUNT(*) FROM classes
")->fetchColumn();

$totalSections = (int)$pdo->query("
    SELECT COUNT(*) FROM sections
")->fetchColumn();

/* --------------------------------
   BUILD ENROLLMENT QUERY
--------------------------------- */
$where = [];
$params = [];

if ($classId !== '' && ctype_digit($classId)) {
    $where[] = "en.class_id = :class_id";
    $params[':class_id'] = (int)$classId;
}

if ($academicYearId !== '' && ctype_digit($academicYearId)) {
    $where[] = "en.academic_year_id = :academic_year_id";
    $params[':academic_year_id'] = (int)$academicYearId;
}

if ($status !== '') {
    $where[] = "en.enrollment_status = :status";
    $params[':status'] = $status;
}

if ($search !== '') {
    $where[] = "(
        s.first_name LIKE :search1
        OR s.last_name LIKE :search2
        OR s.admission_number LIKE :search3
        OR CONCAT(s.first_name, ' ', s.last_name) LIKE :search4
        OR c.class_name LIKE :search5
        OR sec.section_name LIKE :search6
    )";

    $searchTerm = '%' . $search . '%';

    $params[':search1'] = $searchTerm;
    $params[':search2'] = $searchTerm;
    $params[':search3'] = $searchTerm;
    $params[':search4'] = $searchTerm;
    $params[':search5'] = $searchTerm;
    $params[':search6'] = $searchTerm;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        en.id AS enrollment_id,
        en.enrollment_date,
        en.enrollment_status,

        s.id AS student_id,
        s.admission_number,
        s.first_name,
        s.last_name,

        c.id AS class_id,
        c.class_name,

        sec.id AS section_id,
        sec.section_name,

        ay.year_name

    FROM enrollments en

    INNER JOIN students s
        ON s.id = en.student_id

    LEFT JOIN classes c
        ON c.id = en.class_id

    LEFT JOIN sections sec
        ON sec.id = en.section_id

    LEFT JOIN academic_years ay
        ON ay.id = en.academic_year_id

    $whereSQL

    ORDER BY
        c.class_name ASC,
        sec.section_name ASC,
        s.first_name ASC,
        s.last_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

/* --------------------------------
   SUMMARY CALCULATIONS
--------------------------------- */
$totalEnrollments = count($records);

$activeEnrollments = 0;
$otherEnrollments = 0;
$uniqueStudents = [];

$classGroups = [];

foreach ($records as $record) {
    $studentId = $record['student_id'];

    if ($studentId !== null) {
        $uniqueStudents[$studentId] = true;
    }

    $enrollmentStatus = strtolower(
        trim($record['enrollment_status'] ?? '')
    );

    if (in_array($enrollmentStatus, ['active', 'enrolled'], true)) {
        $activeEnrollments++;
    } else {
        $otherEnrollments++;
    }

    $groupKey = implode('|', [
        $record['class_id'] ?? '',
        $record['section_id'] ?? '',
        $record['year_name'] ?? '',
        $record['enrollment_status'] ?? ''
    ]);

    if (!isset($classGroups[$groupKey])) {
        $classGroups[$groupKey] = [
            'class_name' => $record['class_name'],
            'section_name' => $record['section_name'],
            'year_name' => $record['year_name'],
            'status' => $record['enrollment_status'],
            'student_count' => 0
        ];
    }

    $classGroups[$groupKey]['student_count']++;
}

$totalUniqueStudents = count($uniqueStudents);
$totalGroups = count($classGroups);

/* --------------------------------
   CSV EXPORT
--------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=class_enrollment_report.csv');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Admission Number',
        'Student Name',
        'Class',
        'Section',
        'Academic Year',
        'Enrollment Date',
        'Enrollment Status'
    ]);

    foreach ($records as $record) {
        $studentName = trim(
            ($record['first_name'] ?? '') . ' ' .
            ($record['last_name'] ?? '')
        );

        fputcsv($output, [
            $record['admission_number'],
            $studentName,
            $record['class_name'],
            $record['section_name'],
            $record['year_name'],
            $record['enrollment_date'],
            $record['enrollment_status']
        ]);
    }

    fclose($output);
    exit;
}

/* --------------------------------
   URL HELPER
--------------------------------- */
function classesReportUrl($extra = []) {
    $filters = [
        'class_id' => $_GET['class_id'] ?? '',
        'academic_year_id' => $_GET['academic_year_id'] ?? '',
        'status' => $_GET['status'] ?? '',
        'search' => $_GET['search'] ?? ''
    ];

    foreach ($filters as $key => $value) {
        if ($value === '') {
            unset($filters[$key]);
        }
    }

    return '?' . http_build_query(array_merge($filters, $extra));
}

require_once __DIR__ . '/../../includes/admin_sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Class Report | Carver Mission Academy</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
        .reports-content {
            padding: 25px;
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
            margin-bottom: 5px;
        }

        .filter-card,
        .report-table-card,
        .summary-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
        }

        .filter-card {
            padding: 20px;
            margin-bottom: 25px;
        }

        .summary-card {
            padding: 20px;
            height: 100%;
        }

        .summary-card h6 {
            color: #6b7280;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .summary-card h3 {
            font-weight: 700;
            margin: 0;
        }

        .summary-icon {
            font-size: 25px;
            color: #2563eb;
        }

        .report-table-card {
            padding: 20px;
            margin-top: 25px;
        }

        .table th {
            background: #f3f4f6;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .print-header {
            display: none;
        }

        @media (max-width: 768px) {
            .reports-content {
                padding: 15px;
            }
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 12mm;
            }

            html,
            body {
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                background: #fff !important;
                color: #000 !important;
                font-size: 9px !important;
            }

            body * {
                visibility: hidden;
            }

            .reports-content,
            .reports-content * {
                visibility: visible !important;
            }

            #adminSidebar,
            .admin-sidebar,
            .sidebar,
            #sidebarToggle,
            .sidebar-toggle,
            .no-print,
            .filter-card,
            nav {
                display: none !important;
            }

            .admin-main,
            main.admin-main,
            .reports-content,
            .report-table-card {
                display: block !important;
                position: static !important;
                width: 100% !important;
                max-width: none !important;
                height: auto !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                transform: none !important;
                box-shadow: none !important;
                border: none !important;
                background: #fff !important;
            }

            .page-heading {
                display: block !important;
                margin: 0 0 12px !important;
                padding-bottom: 8px !important;
                border-bottom: 1px solid #000;
            }

            .page-heading h2 {
                color: #000 !important;
                font-size: 20px !important;
            }

            .print-header {
                display: block !important;
                visibility: visible !important;
                margin-bottom: 15px !important;
                color: #000 !important;
            }

            .print-header * {
                visibility: visible !important;
            }

            .summary-card {
                display: block !important;
                height: auto !important;
                padding: 10px !important;
                border: 1px solid #777 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                background: #fff !important;
            }

            .summary-card h3 {
                font-size: 16px !important;
                color: #000 !important;
            }

            .summary-card h6 {
                color: #000 !important;
            }

            .summary-icon {
                display: none !important;
            }

            .row {
                display: flex !important;
                flex-wrap: wrap !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }

            .summary-column {
                width: 25% !important;
                flex: 0 0 25% !important;
                max-width: 25% !important;
                padding: 4px !important;
            }

            .report-table-card {
                margin-top: 15px !important;
            }

            .table-responsive {
                display: block !important;
                width: 100% !important;
                overflow: visible !important;
            }

            table,
            .table {
                display: table !important;
                width: 100% !important;
                border-collapse: collapse !important;
                table-layout: auto !important;
                font-size: 9px !important;
                color: #000 !important;
            }

            thead {
                display: table-header-group !important;
            }

            tbody {
                display: table-row-group !important;
            }

            tr {
                display: table-row !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            th,
            td {
                display: table-cell !important;
                padding: 6px !important;
                border: 1px solid #555 !important;
                color: #000 !important;
                background: #fff !important;
                white-space: normal !important;
            }

            a {
                color: #000 !important;
                text-decoration: none !important;
            }

            .badge {
                color: #000 !important;
                background: #fff !important;
                border: 1px solid #777 !important;
            }
        }
    </style>
</head>

<body>

<main class="admin-main">
    <div class="reports-content">

        <!-- PAGE HEADING -->
        <div class="page-heading">
            <div>
                <h2>Class Report</h2>
                <p class="text-muted mb-0">
                    Review class sections and student enrollment records.
                </p>
            </div>

            <div class="d-flex gap-2 flex-wrap no-print">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Reports
                </a>

                <a href="<?= e(classesReportUrl(['export' => 'csv'])) ?>"
                   class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                </a>

                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Report
                </button>
            </div>
        </div>

        <!-- PRINT HEADER -->
        <div class="print-header">
            <h2>CARVER MISSION ACADEMY</h2>
            <h4>CLASS AND STUDENT ENROLLMENT REPORT</h4>
            <p>Generated: <?= date('d M Y, h:i A') ?></p>
            <p>
                <strong>Class:</strong>
                <?php
                $selectedClassName = 'All Classes';

                foreach ($classes as $class) {
                    if ((string)$class['id'] === $classId) {
                        $selectedClassName = $class['class_name'];
                        break;
                    }
                }

                echo e($selectedClassName);
                ?>
                &nbsp; | &nbsp;
                <strong>Academic Year:</strong>
                <?php
                $selectedYearName = 'All Academic Years';

                foreach ($academicYears as $year) {
                    if ((string)$year['id'] === $academicYearId) {
                        $selectedYearName = $year['year_name'];
                        break;
                    }
                }

                echo e($selectedYearName);
                ?>
                &nbsp; | &nbsp;
                <strong>Status:</strong> <?= e($status ?: 'All statuses') ?>
            </p>
            <p><strong>Search:</strong> <?= e($search ?: 'None') ?></p>
        </div>

        <!-- FILTERS -->
        <div class="filter-card no-print">
            <form method="GET" action="classes.php">
                <div class="row g-3">

                    <div class="col-md-3">
                        <label class="form-label">Class</label>
                        <select name="class_id" class="form-select">
                            <option value="">All Classes</option>

                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int)$class['id'] ?>"
                                    <?= $classId == $class['id'] ? 'selected' : '' ?>>
                                    <?= e($class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Academic Year</label>
                        <select name="academic_year_id" class="form-select">
                            <option value="">All Academic Years</option>

                            <?php foreach ($academicYears as $year): ?>
                                <option value="<?= (int)$year['id'] ?>"
                                    <?= $academicYearId == $year['id'] ? 'selected' : '' ?>>
                                    <?= e($year['year_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Enrollment Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>

                            <?php foreach ($statuses as $item): ?>
                                <option value="<?= e($item) ?>"
                                    <?= $status === $item ? 'selected' : '' ?>>
                                    <?= e(ucfirst($item)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text"
                               name="search"
                               class="form-control"
                               placeholder="Student, class, or section"
                               value="<?= e($search) ?>">
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Filter Report
                        </button>

                        <a href="classes.php" class="btn btn-outline-secondary">
                            Reset
                        </a>
                    </div>

                </div>
            </form>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="row g-3">

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Total Classes</h6>
                            <h3><?= number_format($totalClasses) ?></h3>
                        </div>
                        <i class="bi bi-building summary-icon"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Total Sections</h6>
                            <h3><?= number_format($totalSections) ?></h3>
                        </div>
                        <i class="bi bi-diagram-3 summary-icon"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Enrolled Students</h6>
                            <h3><?= number_format($totalUniqueStudents) ?></h3>
                        </div>
                        <i class="bi bi-people summary-icon"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Enrollment Records</h6>
                            <h3><?= number_format($totalEnrollments) ?></h3>
                        </div>
                        <i class="bi bi-journal-check summary-icon"></i>
                    </div>
                </div>
            </div>

        </div>

        <!-- ENROLLMENT SUMMARY -->
        <div class="report-table-card">

            <h5 class="mb-3">Enrollment Summary</h5>

            <div class="row g-3">

                <div class="col-md-6">
                    <div class="p-3 border rounded">
                        <small class="text-muted">Active / Enrolled Records</small>
                        <h4 class="text-success mb-0">
                            <?= number_format($activeEnrollments) ?>
                        </h4>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="p-3 border rounded">
                        <small class="text-muted">Other Status Records</small>
                        <h4 class="text-warning mb-0">
                            <?= number_format($otherEnrollments) ?>
                        </h4>
                    </div>
                </div>

            </div>

        </div>

        <!-- CLASS GROUP SUMMARY -->
        <div class="report-table-card">

            <h5 class="mb-3">Students by Class and Section</h5>

            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Class</th>
                            <th>Section</th>
                            <th>Academic Year</th>
                            <th>Enrollment Status</th>
                            <th>Student Count</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!empty($classGroups)): ?>

                            <?php $groupIndex = 1; ?>

                            <?php foreach ($classGroups as $group): ?>
                                <tr>
                                    <td><?= $groupIndex++ ?></td>
                                    <td><?= e($group['class_name'] ?: 'N/A') ?></td>
                                    <td><?= e($group['section_name'] ?: 'N/A') ?></td>
                                    <td><?= e($group['year_name'] ?: 'N/A') ?></td>
                                    <td><?= e($group['status'] ?: 'N/A') ?></td>
                                    <td><?= number_format($group['student_count']) ?></td>
                                </tr>
                            <?php endforeach; ?>

                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    No enrollment summary found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <!-- STUDENT ENROLLMENT DETAILS -->
        <div class="report-table-card">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Student Enrollment Details</h5>

                <span class="text-muted">
                    <?= number_format($totalEnrollments) ?> record(s)
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover">

                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Admission Number</th>
                            <th>Student Name</th>
                            <th>Class</th>
                            <th>Section</th>
                            <th>Academic Year</th>
                            <th>Enrollment Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!empty($records)): ?>

                            <?php foreach ($records as $index => $record): ?>

                                <?php
                                $studentName = trim(
                                    ($record['first_name'] ?? '') . ' ' .
                                    ($record['last_name'] ?? '')
                                );

                                $enrollmentStatus = strtolower(
                                    trim($record['enrollment_status'] ?? '')
                                );

                                $badgeClass = 'bg-secondary';

                                if (in_array($enrollmentStatus, ['active', 'enrolled'], true)) {
                                    $badgeClass = 'bg-success';
                                } elseif (in_array($enrollmentStatus, ['inactive', 'withdrawn', 'cancelled'], true)) {
                                    $badgeClass = 'bg-danger';
                                }
                                ?>

                                <tr>
                                    <td><?= $index + 1 ?></td>

                                    <td><?= e($record['admission_number']) ?></td>

                                    <td><?= e($studentName) ?></td>

                                    <td><?= e($record['class_name'] ?: 'N/A') ?></td>

                                    <td><?= e($record['section_name'] ?: 'N/A') ?></td>

                                    <td><?= e($record['year_name'] ?: 'N/A') ?></td>

                                    <td>
                                        <?= $record['enrollment_date']
                                            ? e(date('d M Y', strtotime($record['enrollment_date'])))
                                            : 'N/A' ?>
                                    </td>

                                    <td>
                                        <span class="badge <?= e($badgeClass) ?>">
                                            <?= e(ucfirst($record['enrollment_status'] ?? 'N/A')) ?>
                                        </span>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    No student enrollment records found.
                                </td>
                            </tr>

                        <?php endif; ?>
                    </tbody>

                </table>
            </div>

        </div>

    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
