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

$statuses = $pdo->query("
    SELECT DISTINCT status
    FROM student_fees
    WHERE status IS NOT NULL AND status <> ''
    ORDER BY status
")->fetchAll(PDO::FETCH_COLUMN);

/* --------------------------------
   BUILD REPORT QUERY
--------------------------------- */
$where = [];
$params = [];

if ($classId !== '' && ctype_digit($classId)) {
    $where[] = "en.class_id = :class_id";
    $params[':class_id'] = (int)$classId;
}

if ($status !== '') {
    $where[] = "sf.status = :status";
    $params[':status'] = $status;
}

if ($search !== '') {
    $where[] = "(
        s.first_name LIKE :search1
        OR s.last_name LIKE :search2
        OR s.admission_number LIKE :search3
        OR CONCAT(s.first_name, ' ', s.last_name) LIKE :search4
        OR f.fee_name LIKE :search5
    )";

    $searchTerm = '%' . $search . '%';

    $params[':search1'] = $searchTerm;
    $params[':search2'] = $searchTerm;
    $params[':search3'] = $searchTerm;
    $params[':search4'] = $searchTerm;
    $params[':search5'] = $searchTerm;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        sf.id AS student_fee_id,
        sf.amount_due,
        sf.amount_paid,
        sf.due_date,
        sf.status AS fee_status,

        s.admission_number,
        s.first_name,
        s.last_name,

        f.fee_name,

        c.class_name,
        sec.section_name,

        ay.year_name,

        COALESCE(p.payment_total, 0) AS payment_total,
        p.last_payment_date,
        p.payment_methods,
        p.payment_count

    FROM student_fees sf

    INNER JOIN students s
        ON s.id = sf.student_id

    INNER JOIN fees f
        ON f.id = sf.fee_id

    LEFT JOIN enrollments en
        ON en.id = sf.enrollment_id

    LEFT JOIN classes c
        ON c.id = en.class_id

    LEFT JOIN sections sec
        ON sec.id = en.section_id

    LEFT JOIN academic_years ay
        ON ay.id = en.academic_year_id

    LEFT JOIN (
        SELECT
            student_fee_id,
            SUM(amount) AS payment_total,
            MAX(payment_date) AS last_payment_date,
            GROUP_CONCAT(
                DISTINCT payment_method
                ORDER BY payment_method
                SEPARATOR ', '
            ) AS payment_methods,
            COUNT(*) AS payment_count
        FROM payments
        GROUP BY student_fee_id
    ) p ON p.student_fee_id = sf.id

    $whereSQL

    ORDER BY s.first_name ASC, s.last_name ASC, f.fee_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

/* --------------------------------
   SUMMARY CALCULATIONS
--------------------------------- */
$totalAssigned = 0;
$totalPaid = 0;
$totalBalance = 0;

$fullyPaidCount = 0;
$unpaidCount = 0;
$partialCount = 0;

foreach ($records as $record) {
    $amountDue = (float)$record['amount_due'];

    // Use recorded payment transactions for the payment total.
    $amountPaid = (float)$record['payment_total'];

    // Prevent a negative outstanding balance.
    $balance = max(0, $amountDue - $amountPaid);

    $totalAssigned += $amountDue;
    $totalPaid += $amountPaid;
    $totalBalance += $balance;

    if ($amountDue > 0 && $amountPaid >= $amountDue) {
        $fullyPaidCount++;
    } elseif ($amountPaid <= 0) {
        $unpaidCount++;
    } else {
        $partialCount++;
    }
}

$totalFeeRecords = count($records);

/* --------------------------------
   CSV EXPORT
--------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=fees_report.csv');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Admission Number',
        'Student Name',
        'Class',
        'Section',
        'Academic Year',
        'Fee Name',
        'Amount Due',
        'Amount Paid',
        'Balance',
        'Fee Status',
        'Due Date',
        'Last Payment Date',
        'Payment Methods',
        'Number of Payments'
    ]);

    foreach ($records as $record) {
        $studentName = trim(
            ($record['first_name'] ?? '') . ' ' .
            ($record['last_name'] ?? '')
        );

        $amountDue = (float)$record['amount_due'];
        $amountPaid = (float)$record['payment_total'];
        $balance = max(0, $amountDue - $amountPaid);

        fputcsv($output, [
            $record['admission_number'],
            $studentName,
            $record['class_name'],
            $record['section_name'],
            $record['year_name'],
            $record['fee_name'],
            number_format($amountDue, 2, '.', ''),
            number_format($amountPaid, 2, '.', ''),
            number_format($balance, 2, '.', ''),
            $record['fee_status'],
            $record['due_date'],
            $record['last_payment_date'],
            $record['payment_methods'],
            $record['payment_count']
        ]);
    }

    fclose($output);
    exit;
}

/* --------------------------------
   URL HELPER
--------------------------------- */
function feesReportUrl($extra = []) {
    $filters = [
        'class_id' => $_GET['class_id'] ?? '',
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

    <title>Fees Report | Carver Mission Academy</title>

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
            font-size: 21px;
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
                margin: 10mm;
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
                margin: 0 0 10px !important;
                padding-bottom: 8px !important;
                border-bottom: 1px solid #000;
            }

            .page-heading h2 {
                color: #000 !important;
                font-size: 19px !important;
            }

            .print-header {
                display: block !important;
                visibility: visible !important;
                margin-bottom: 12px !important;
                color: #000 !important;
            }

            .print-header * {
                visibility: visible !important;
            }

            .summary-card {
                display: block !important;
                height: auto !important;
                padding: 8px !important;
                border: 1px solid #777 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                background: #fff !important;
            }

            .summary-card h3 {
                font-size: 14px !important;
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
                margin-top: 12px !important;
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
                font-size: 8px !important;
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
                padding: 5px !important;
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
                <h2>Fees Report</h2>
                <p class="text-muted mb-0">
                    Review student fees, payments, and outstanding balances.
                </p>
            </div>

            <div class="d-flex gap-2 flex-wrap no-print">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Reports
                </a>

                <a href="<?= e(feesReportUrl(['export' => 'csv'])) ?>"
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
            <h4>STUDENT FEES AND PAYMENTS REPORT</h4>
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
                <strong>Status:</strong> <?= e($status ?: 'All statuses') ?>
                &nbsp; | &nbsp;
                <strong>Search:</strong> <?= e($search ?: 'None') ?>
            </p>
        </div>

        <!-- FILTERS -->
        <div class="filter-card no-print">
            <form method="GET" action="fees.php">
                <div class="row g-3">

                    <div class="col-md-4">
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

                    <div class="col-md-4">
                        <label class="form-label">Fee Status</label>
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

                    <div class="col-md-4">
                        <label class="form-label">Search Student or Fee</label>
                        <input type="text"
                               name="search"
                               class="form-control"
                               placeholder="Name, admission number, or fee"
                               value="<?= e($search) ?>">
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Filter Report
                        </button>

                        <a href="fees.php" class="btn btn-outline-secondary">
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
                            <h6>Total Assigned</h6>
                            <h3><?= number_format($totalAssigned, 2) ?></h3>
                        </div>
                        <i class="bi bi-receipt summary-icon"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Total Collected</h6>
                            <h3><?= number_format($totalPaid, 2) ?></h3>
                        </div>
                        <i class="bi bi-cash-stack summary-icon text-success"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Outstanding Balance</h6>
                            <h3><?= number_format($totalBalance, 2) ?></h3>
                        </div>
                        <i class="bi bi-exclamation-circle summary-icon text-danger"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3 summary-column">
                <div class="summary-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6>Fee Records</h6>
                            <h3><?= number_format($totalFeeRecords) ?></h3>
                        </div>
                        <i class="bi bi-people summary-icon"></i>
                    </div>
                </div>
            </div>

        </div>

        <!-- PAYMENT SUMMARY -->
        <div class="report-table-card">

            <h5 class="mb-3">Payment Summary</h5>

            <div class="row g-3">

                <div class="col-md-4">
                    <div class="p-3 border rounded">
                        <small class="text-muted">Fully Paid Fee Records</small>
                        <h4 class="text-success mb-0">
                            <?= number_format($fullyPaidCount) ?>
                        </h4>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="p-3 border rounded">
                        <small class="text-muted">Partially Paid Fee Records</small>
                        <h4 class="text-warning mb-0">
                            <?= number_format($partialCount) ?>
                        </h4>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="p-3 border rounded">
                        <small class="text-muted">Unpaid Fee Records</small>
                        <h4 class="text-danger mb-0">
                            <?= number_format($unpaidCount) ?>
                        </h4>
                    </div>
                </div>

            </div>

        </div>

        <!-- FEES TABLE -->
        <div class="report-table-card">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Student Fees and Payment Records</h5>

                <span class="text-muted">
                    <?= number_format($totalFeeRecords) ?> record(s)
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover">

                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Admission No.</th>
                            <th>Student Name</th>
                            <th>Class</th>
                            <th>Section</th>
                            <th>Academic Year</th>
                            <th>Fee Name</th>
                            <th>Amount Due</th>
                            <th>Amount Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Last Payment</th>
                            <th>Payment Method(s)</th>
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

                                $amountDue = (float)$record['amount_due'];
                                $amountPaid = (float)$record['payment_total'];
                                $balance = max(0, $amountDue - $amountPaid);

                                if ($amountDue > 0 && $amountPaid >= $amountDue) {
                                    $paymentLabel = 'Fully Paid';
                                    $badgeClass = 'bg-success';
                                } elseif ($amountPaid <= 0) {
                                    $paymentLabel = 'Unpaid';
                                    $badgeClass = 'bg-danger';
                                } else {
                                    $paymentLabel = 'Partially Paid';
                                    $badgeClass = 'bg-warning text-dark';
                                }
                                ?>

                                <tr>
                                    <td><?= $index + 1 ?></td>

                                    <td><?= e($record['admission_number']) ?></td>

                                    <td><?= e($studentName) ?></td>

                                    <td><?= e($record['class_name'] ?: 'N/A') ?></td>

                                    <td><?= e($record['section_name'] ?: 'N/A') ?></td>

                                    <td><?= e($record['year_name'] ?: 'N/A') ?></td>

                                    <td><?= e($record['fee_name']) ?></td>

                                    <td><?= number_format($amountDue, 2) ?></td>

                                    <td><?= number_format($amountPaid, 2) ?></td>

                                    <td><?= number_format($balance, 2) ?></td>

                                    <td>
                                        <span class="badge <?= e($badgeClass) ?>">
                                            <?= e($paymentLabel) ?>
                                        </span>
                                        <small class="d-block text-muted">
                                            <?= e($record['fee_status']) ?>
                                        </small>
                                    </td>

                                    <td>
                                        <?= $record['last_payment_date']
                                            ? e(date('d M Y', strtotime($record['last_payment_date'])))
                                            : 'No payment' ?>
                                    </td>

                                    <td><?= e($record['payment_methods'] ?: '-') ?></td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="13" class="text-center py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    No fee records found.
                                </td>
                            </tr>

                        <?php endif; ?>
                    </tbody>

                </table>
            </div>

            <div class="text-muted mt-3">
                Payment totals are calculated from recorded payment transactions.
                Outstanding balances cannot be less than zero.
            </div>

        </div>

    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
