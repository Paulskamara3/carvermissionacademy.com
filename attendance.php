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
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$classId = trim($_GET['class_id'] ?? '');
$sectionId = trim($_GET['section_id'] ?? '');
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

function validDate($date) {
    if ($date === '') {
        return true;
    }

    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

if (!validDate($dateFrom) || !validDate($dateTo)) {
    die('Invalid date format. Please use YYYY-MM-DD.');
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    die('The start date cannot be later than the end date.');
}

/* --------------------------------
   DROPDOWN DATA
--------------------------------- */
$classes = $pdo->query("
    SELECT id, class_name
    FROM classes
    ORDER BY class_name
")->fetchAll();

$sections = $pdo->query("
    SELECT id, section_name
    FROM sections
    ORDER BY section_name
")->fetchAll();

$statuses = $pdo->query("
    SELECT DISTINCT attendance_status
    FROM attendance
    WHERE attendance_status IS NOT NULL
      AND attendance_status <> ''
    ORDER BY attendance_status
")->fetchAll(PDO::FETCH_COLUMN);

/* --------------------------------
   BUILD REPORT QUERY
--------------------------------- */
$where = [];
$params = [];

if ($dateFrom !== '') {
    $where[] = "a.attendance_date >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = "a.attendance_date <= :date_to";
    $params[':date_to'] = $dateTo;
}

if ($classId !== '' && ctype_digit($classId)) {
    $where[] = "a.class_id = :class_id";
    $params[':class_id'] = (int)$classId;
}

if ($sectionId !== '' && ctype_digit($sectionId)) {
    $where[] = "a.section_id = :section_id";
    $params[':section_id'] = (int)$sectionId;
}

if ($status !== '') {
    $where[] = "a.attendance_status = :status";
    $params[':status'] = $status;
}

if ($search !== '') {
    $where[] = "(
        s.first_name LIKE :search1
        OR s.last_name LIKE :search2
        OR s.admission_number LIKE :search3
        OR CONCAT(s.first_name, ' ', s.last_name) LIKE :search4
    )";

    $searchTerm = '%' . $search . '%';

    $params[':search1'] = $searchTerm;
    $params[':search2'] = $searchTerm;
    $params[':search3'] = $searchTerm;
    $params[':search4'] = $searchTerm;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        a.id,
        a.attendance_date,
        a.attendance_status,
        a.remarks,
        s.admission_number,
        s.first_name,
        s.last_name,
        c.class_name,
        sec.section_name
    FROM attendance a
    LEFT JOIN students s ON s.id = a.student_id
    LEFT JOIN classes c ON c.id = a.class_id
    LEFT JOIN sections sec ON sec.id = a.section_id
    $whereSQL
    ORDER BY a.attendance_date DESC, s.first_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

/* --------------------------------
   SUMMARY COUNTS
--------------------------------- */
$totalRecords = count($records);
$presentCount = 0;
$absentCount = 0;
$lateCount = 0;
$otherCount = 0;

foreach ($records as $record) {
    $attendanceStatus = strtolower(trim($record['attendance_status'] ?? ''));

    if ($attendanceStatus === 'present') {
        $presentCount++;
    } elseif ($attendanceStatus === 'absent') {
        $absentCount++;
    } elseif ($attendanceStatus === 'late') {
        $lateCount++;
    } else {
        $otherCount++;
    }
}

/* --------------------------------
   CSV EXPORT
--------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=attendance_report.csv');

    $output = fopen('php://output', 'w');

    // Protect spreadsheet software from interpreting values as formulas.
    fputcsv($output, [
        'Date',
        'Admission Number',
        'Student Name',
        'Class',
        'Section',
        'Status',
        'Remarks'
    ]);

    foreach ($records as $record) {
        $studentName = trim(
            ($record['first_name'] ?? '') . ' ' .
            ($record['last_name'] ?? '')
        );

        fputcsv($output, [
            $record['attendance_date'],
            $record['admission_number'],
            $studentName,
            $record['class_name'],
            $record['section_name'],
            $record['attendance_status'],
            $record['remarks']
        ]);
    }

    fclose($output);
    exit;
}

/* --------------------------------
   URL HELPER
--------------------------------- */
function attendanceReportUrl($extra = []) {
    $filters = [
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'class_id' => $_GET['class_id'] ?? '',
        'section_id' => $_GET['section_id'] ?? '',
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
    <title>Attendance Report | Carver Mission Academy</title>

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

        .table-responsive {
            overflow-x: auto;
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

            .page-heading {
                align-items: flex-start;
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
                font-size: 10px !important;
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
                padding: 0 0 10px !important;
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
                min-height: 0 !important;
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

            th {
                font-weight: bold !important;
            }

            .badge {
                color: #000 !important;
                background: #fff !important;
                border: 1px solid #777 !important;
            }

            .text-muted {
                color: #000 !important;
            }

            a {
                color: #000 !important;
                text-decoration: none !important;
            }
        }
    </style>
</head>

<body>

<main class="admin-main">
    <div class="reports-content">

        <div class="page-heading">
            <div>
                <h2>Attendance Report</h2>
                <p class="text-muted mb-0">Review and analyze student attendance records.</p>
            </div>

            <div class="d-flex gap-2 flex-wrap no-print">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Reports
                </a>

                <a href="<?= e(attendanceReportUrl(['export' => 'csv'])) ?>"
                   class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                </a>

                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Report
                </button>
            </div>
        </div>

        <div class="print-header">
            <h2>CARVER MISSION ACADEMY</h2>
            <h4>STUDENT ATTENDANCE REPORT</h4>
            <p>Generated: <?= date('d M Y, h:i A') ?></p>

            <p>
                <strong>Date range:</strong>
                <?= e($dateFrom ?: 'All dates') ?> to <?= e($dateTo ?: 'All dates') ?>
                &nbsp; | &nbsp;
                <strong>Status:</strong> <?= e($status ?: 'All statuses') ?>
            </p>

            <p>
                <strong>Search:</strong> <?= e($search ?: 'None') ?>
            </p>
        </div>

        <!-- FILTERS -->
        <div class="filter-card no-print">
            <form method="GET" action="attendance.php">
                <div class="row g-3">

                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control"
                               value="<?= e($dateFrom) ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control"
                               value="<?= e($dateTo) ?>">
                    </div>

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
                        <label class="form-label">Section</label>
                        <select name="section_id" class="form-select">
                            <option value="">All Sections</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?= (int)$section['id'] ?>"
                                    <?= $sectionId == $section['id'] ? 'selected' : '' ?>>
                                    <?= e($section['section_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Attendance Status</label>
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

                    <div class="col-md-5">
                        <label class="form-label">Search Student</label>
                        <input type="text" name="search" class="form-control"
                               placeholder="Name or admission number"
                               value="<?= e($search) ?>">
                    </div>

                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Filter
                        </button>

                        <a href="attendance.php" class="btn btn-outline-secondary">
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
                            <h6>Total Records</h6>
                            <h3><?= number_format($totalRecords) ?></h3>
                        </div>
                        <i class="bi bi-clipboard-data summary-icon"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Present</h6>
                            <h3><?= number_format($presentCount) ?></h3>
                        </div>
                        <i class="bi bi-check-circle summary-icon text-success"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Absent</h6>
                            <h3><?= number_format($absentCount) ?></h3>
                        </div>
                        <i class="bi bi-x-circle summary-icon text-danger"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Late</h6>
                            <h3><?= number_format($lateCount) ?></h3>
                        </div>
                        <i class="bi bi-clock summary-icon text-warning"></i>
                    </div>
                </div>
            </div>

        </div>

        <!-- ATTENDANCE TABLE -->
        <div class="report-table-card">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Attendance Records</h5>
                <span class="text-muted">
                    <?= number_format($totalRecords) ?> record(s)
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Admission Number</th>
                            <th>Student Name</th>
                            <th>Class</th>
                            <th>Section</th>
                            <th>Status</th>
                            <th>Remarks</th>
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

                                    $currentStatus = strtolower(
                                        trim($record['attendance_status'] ?? '')
                                    );

                                    $badgeClass = 'bg-secondary';

                                    if ($currentStatus === 'present') {
                                        $badgeClass = 'bg-success';
                                    } elseif ($currentStatus === 'absent') {
                                        $badgeClass = 'bg-danger';
                                    } elseif ($currentStatus === 'late') {
                                        $badgeClass = 'bg-warning text-dark';
                                    }
                                ?>

                                <tr>
                                    <td><?= $index + 1 ?></td>

                                    <td>
                                        <?= e(
                                            $record['attendance_date']
                                                ? date('d M Y', strtotime($record['attendance_date']))
                                                : ''
                                        ) ?>
                                    </td>

                                    <td><?= e($record['admission_number']) ?></td>

                                    <td><?= e($studentName) ?></td>

                                    <td><?= e($record['class_name'] ?: 'N/A') ?></td>

                                    <td><?= e($record['section_name'] ?: 'N/A') ?></td>

                                    <td>
                                        <span class="badge <?= e($badgeClass) ?>">
                                            <?= e(ucfirst($record['attendance_status'])) ?>
                                        </span>
                                    </td>

                                    <td><?= e($record['remarks'] ?: '-') ?></td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    No attendance records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="text-muted mt-3">
                Other statuses: <?= number_format($otherCount) ?>
            </div>

        </div>

    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
