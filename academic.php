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
$examId = trim($_GET['exam_id'] ?? '');
$classId = trim($_GET['class_id'] ?? '');
$subjectId = trim($_GET['subject_id'] ?? '');
$search = trim($_GET['search'] ?? '');

/* --------------------------------
   DROPDOWN DATA
--------------------------------- */
$exams = $pdo->query("
    SELECT
        e.id,
        e.exam_name,
        e.exam_date,
        c.class_name,
        s.subject_name
    FROM exams e
    LEFT JOIN classes c ON c.id = e.class_id
    LEFT JOIN subjects s ON s.id = e.subject_id
    ORDER BY e.exam_date DESC, e.exam_name
")->fetchAll();

$classes = $pdo->query("
    SELECT id, class_name
    FROM classes
    ORDER BY class_name
")->fetchAll();

$subjects = $pdo->query("
    SELECT id, subject_name
    FROM subjects
    ORDER BY subject_name
")->fetchAll();

/* --------------------------------
   BUILD REPORT QUERY
--------------------------------- */
$where = [];
$params = [];

if ($examId !== '' && ctype_digit($examId)) {
    $where[] = "e.id = :exam_id";
    $params[':exam_id'] = (int)$examId;
}

if ($classId !== '' && ctype_digit($classId)) {
    $where[] = "e.class_id = :class_id";
    $params[':class_id'] = (int)$classId;
}

if ($subjectId !== '' && ctype_digit($subjectId)) {
    $where[] = "e.subject_id = :subject_id";
    $params[':subject_id'] = (int)$subjectId;
}

if ($search !== '') {
    $where[] = "(
        st.first_name LIKE :search1
        OR st.last_name LIKE :search2
        OR st.admission_number LIKE :search3
        OR CONCAT(st.first_name, ' ', st.last_name) LIKE :search4
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
        r.id AS result_id,
        r.marks_obtained,
        r.grade,
        r.remarks,
        e.id AS exam_id,
        e.exam_name,
        e.exam_date,
        e.total_marks,
        st.admission_number,
        st.first_name,
        st.last_name,
        c.class_name,
        sub.subject_name,
        t.term_name,
        t.semester_name
    FROM results r
    INNER JOIN exams e ON e.id = r.exam_id
    LEFT JOIN students st ON st.id = r.student_id
    LEFT JOIN classes c ON c.id = e.class_id
    LEFT JOIN subjects sub ON sub.id = e.subject_id
    LEFT JOIN terms t ON t.id = e.term_id
    $whereSQL
    ORDER BY e.exam_date DESC, e.exam_name, st.first_name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

/* --------------------------------
   SUMMARY
--------------------------------- */
$totalResults = count($records);
$totalMarks = 0;
$validMarkCount = 0;
$passedCount = 0;
$failedCount = 0;

foreach ($records as $record) {
    if (is_numeric($record['marks_obtained'])) {
        $totalMarks += (float)$record['marks_obtained'];
        $validMarkCount++;
    }

    $grade = strtoupper(trim($record['grade'] ?? ''));

    if (in_array($grade, ['F', 'FAIL'], true)) {
        $failedCount++;
    } elseif ($grade !== '') {
        $passedCount++;
    }
}

$averageMarks = $validMarkCount > 0
    ? $totalMarks / $validMarkCount
    : 0;

/* --------------------------------
   CSV EXPORT
--------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=academic_report.csv');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Exam',
        'Exam Date',
        'Term',
        'Class',
        'Subject',
        'Admission Number',
        'Student Name',
        'Marks Obtained',
        'Total Marks',
        'Grade',
        'Remarks'
    ]);

    foreach ($records as $record) {
        $studentName = trim(
            ($record['first_name'] ?? '') . ' ' .
            ($record['last_name'] ?? '')
        );

        fputcsv($output, [
            $record['exam_name'],
            $record['exam_date'],
            $record['term_name'],
            $record['class_name'],
            $record['subject_name'],
            $record['admission_number'],
            $studentName,
            $record['marks_obtained'],
            $record['total_marks'],
            $record['grade'],
            $record['remarks']
        ]);
    }

    fclose($output);
    exit;
}

/* --------------------------------
   URL HELPER
--------------------------------- */
function academicReportUrl($extra = []) {
    $filters = [
        'exam_id' => $_GET['exam_id'] ?? '',
        'class_id' => $_GET['class_id'] ?? '',
        'subject_id' => $_GET['subject_id'] ?? '',
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

    <title>Academic Report | Carver Mission Academy</title>

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
        }
    </style>
</head>

<body>

<main class="admin-main">
    <div class="reports-content">

        <div class="page-heading">
            <div>
                <h2>Academic Report</h2>
                <p class="text-muted mb-0">
                    Review examination results and student academic performance.
                </p>
            </div>

            <div class="d-flex gap-2 flex-wrap no-print">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Reports
                </a>

                <a href="<?= e(academicReportUrl(['export' => 'csv'])) ?>"
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
            <h4>ACADEMIC PERFORMANCE REPORT</h4>
            <p>Generated: <?= date('d M Y, h:i A') ?></p>
            <p>
                <strong>Examination:</strong>
                <?php
                $selectedExamName = 'All examinations';
                foreach ($exams as $exam) {
                    if ((string)$exam['id'] === $examId) {
                        $selectedExamName = $exam['exam_name'];
                        break;
                    }
                }
                echo e($selectedExamName);
                ?>
            </p>
            <p>
                <strong>Search:</strong> <?= e($search ?: 'None') ?>
            </p>
        </div>

        <!-- FILTERS -->
        <div class="filter-card no-print">
            <form method="GET" action="academic.php">
                <div class="row g-3">

                    <div class="col-md-3">
                        <label class="form-label">Examination</label>
                        <select name="exam_id" class="form-select">
                            <option value="">All Examinations</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int)$exam['id'] ?>"
                                    <?= $examId == $exam['id'] ? 'selected' : '' ?>>
                                    <?= e($exam['exam_name']) ?>
                                    (<?= e($exam['exam_date']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                        <label class="form-label">Subject</label>
                        <select name="subject_id" class="form-select">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= (int)$subject['id'] ?>"
                                    <?= $subjectId == $subject['id'] ? 'selected' : '' ?>>
                                    <?= e($subject['subject_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Search Student</label>
                        <input type="text" name="search" class="form-control"
                               placeholder="Name or admission number"
                               value="<?= e($search) ?>">
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Filter Report
                        </button>

                        <a href="academic.php" class="btn btn-outline-secondary">
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
                            <h6>Total Results</h6>
                            <h3><?= number_format($totalResults) ?></h3>
                        </div>
                        <i class="bi bi-journal-check summary-icon"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Average Marks</h6>
                            <h3><?= number_format($averageMarks, 2) ?></h3>
                        </div>
                        <i class="bi bi-graph-up summary-icon"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Graded Results</h6>
                            <h3><?= number_format($passedCount) ?></h3>
                        </div>
                        <i class="bi bi-check-circle summary-icon text-success"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Failed Results</h6>
                            <h3><?= number_format($failedCount) ?></h3>
                        </div>
                        <i class="bi bi-x-circle summary-icon text-danger"></i>
                    </div>
                </div>
            </div>

        </div>

        <!-- RESULTS TABLE -->
        <div class="report-table-card">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Student Examination Results</h5>
                <span class="text-muted">
                    <?= number_format($totalResults) ?> result(s)
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Examination</th>
                            <th>Date</th>
                            <th>Term</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Admission No.</th>
                            <th>Student Name</th>
                            <th>Marks</th>
                            <th>Total Marks</th>
                            <th>Grade</th>
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

                                    $grade = strtoupper(trim($record['grade'] ?? ''));

                                    $gradeClass = 'bg-secondary';

                                    if (in_array($grade, ['A', 'A+', 'A-', 'B', 'B+', 'B-'], true)) {
                                        $gradeClass = 'bg-success';
                                    } elseif (in_array($grade, ['F', 'FAIL'], true)) {
                                        $gradeClass = 'bg-danger';
                                    } elseif ($grade !== '') {
                                        $gradeClass = 'bg-warning text-dark';
                                    }
                                ?>

                                <tr>
                                    <td><?= $index + 1 ?></td>

                                    <td><?= e($record['exam_name']) ?></td>

                                    <td>
                                        <?= e(
                                            $record['exam_date']
                                                ? date('d M Y', strtotime($record['exam_date']))
                                                : ''
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e($record['term_name'] ?: $record['semester_name'] ?: 'N/A') ?>
                                    </td>

                                    <td><?= e($record['class_name'] ?: 'N/A') ?></td>

                                    <td><?= e($record['subject_name'] ?: 'N/A') ?></td>

                                    <td><?= e($record['admission_number']) ?></td>

                                    <td><?= e($studentName) ?></td>

                                    <td><?= e($record['marks_obtained']) ?></td>

                                    <td><?= e($record['total_marks']) ?></td>

                                    <td>
                                        <span class="badge <?= e($gradeClass) ?>">
                                            <?= e($record['grade'] ?: 'N/A') ?>
                                        </span>
                                    </td>

                                    <td><?= e($record['remarks'] ?: '-') ?></td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    No academic results found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="text-muted mt-3">
                Average marks are calculated from numeric marks in the filtered results.
            </div>

        </div>

    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
