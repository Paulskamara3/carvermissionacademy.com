<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money')) {
    function money($value) {
        return number_format((float)$value, 2);
    }
}

/**
 * Validate a date using the exact YYYY-MM-DD format.
 */
function isValidReportDate($date) {
    if ($date === '') {
        return true;
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed && $parsed->format('Y-m-d') === $date;
}

$errors = [];

$academicYears = [];
$summary = [];
$totalCollected = 0;
$classReports = [];
$unpaidStudents = [];
$dailyReports = [];

$selectedYear = isset($_GET['academic_year_id'])
    ? (int)$_GET['academic_year_id']
    : 0;

$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');

try {

    /*
     * Load academic years.
     */
    $stmt = $pdo->query("
        SELECT id, year_name
        FROM academic_years
        ORDER BY start_date DESC
    ");

    $academicYears = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Validate selected academic year.
     */
    if ($selectedYear > 0) {
        $checkYear = $pdo->prepare("
            SELECT COUNT(*)
            FROM academic_years
            WHERE id = ?
        ");

        $checkYear->execute([$selectedYear]);

        if (!$checkYear->fetchColumn()) {
            $selectedYear = 0;
            $errors[] = 'The selected academic year does not exist.';
        }
    }

    /*
     * Validate payment dates.
     */
    if (!isValidReportDate($startDate)) {
        $errors[] = 'Please enter a valid payment start date.';
        $startDate = '';
    }

    if (!isValidReportDate($endDate)) {
        $errors[] = 'Please enter a valid payment end date.';
        $endDate = '';
    }

    if (
        $startDate !== '' &&
        $endDate !== '' &&
        $startDate > $endDate
    ) {
        $errors[] = 'The start date cannot be later than the end date.';
        $startDate = '';
        $endDate = '';
    }

    /*
     * Academic year condition.
     *
     * All queries using this condition must include
     * the enrollments table with alias e.
     */
    $yearCondition = '';
    $yearParams = [];

    if ($selectedYear > 0) {
        $yearCondition = ' AND e.academic_year_id = ? ';
        $yearParams[] = $selectedYear;
    }

    /*
     * Payment date conditions.
     *
     * These conditions apply only to payment collection
     * totals and daily payment reports.
     */
    $paymentDateCondition = '';
    $paymentParams = [];

    if ($startDate !== '') {
        $paymentDateCondition .= ' AND p.payment_date >= ? ';
        $paymentParams[] = $startDate;
    }

    if ($endDate !== '') {
        $paymentDateCondition .= ' AND p.payment_date <= ? ';
        $paymentParams[] = $endDate;
    }

    /*
     * 1. FINANCIAL SUMMARY
     *
     * The summary is based on the selected academic year.
     * Payment date filters do not affect assigned fees
     * or outstanding balances.
     */
    $summarySql = "
        SELECT
            COUNT(DISTINCT sf.student_id) AS total_students,

            COALESCE(
                SUM(sf.amount_due),
                0
            ) AS total_assigned,

            COALESCE(
                SUM(
                    GREATEST(
                        sf.amount_due -
                        COALESCE(pay.total_paid, sf.amount_paid, 0),
                        0
                    )
                ),
                0
            ) AS total_outstanding,

            COALESCE(
                SUM(
                    CASE
                        WHEN sf.status = 'paid' THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS paid_records,

            COALESCE(
                SUM(
                    CASE
                        WHEN sf.status = 'partial' THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS partial_records,

            COALESCE(
                SUM(
                    CASE
                        WHEN sf.status = 'unpaid' THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS unpaid_records

        FROM student_fees sf

        INNER JOIN enrollments e
            ON e.id = sf.enrollment_id

        LEFT JOIN (
            SELECT
                student_fee_id,
                SUM(amount) AS total_paid
            FROM payments
            GROUP BY student_fee_id
        ) pay
            ON pay.student_fee_id = sf.id

        WHERE sf.status <> 'waived'
        $yearCondition
    ";

    $stmt = $pdo->prepare($summarySql);
    $stmt->execute($yearParams);

    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    /*
     * 2. TOTAL PAYMENTS COLLECTED
     *
     * Academic year and payment date filters apply.
     */
    $collectedSql = "
        SELECT
            COALESCE(SUM(p.amount), 0)

        FROM payments p

        INNER JOIN student_fees sf
            ON sf.id = p.student_fee_id

        INNER JOIN enrollments e
            ON e.id = sf.enrollment_id

        WHERE sf.status <> 'waived'
        $yearCondition
        $paymentDateCondition
    ";

    $stmt = $pdo->prepare($collectedSql);

    $stmt->execute(
        array_merge($yearParams, $paymentParams)
    );

    $totalCollected = (float)$stmt->fetchColumn();

    /*
     * 3. FEE SUMMARY BY CLASS
     *
     * Shows all payments recorded against fee assignments
     * in the selected academic year.
     */
    $classSql = "
        SELECT
            c.id,
            c.class_name,

            COUNT(DISTINCT sf.student_id) AS student_count,

            COALESCE(
                SUM(sf.amount_due),
                0
            ) AS total_assigned,

            COALESCE(
                SUM(
                    COALESCE(pay.total_paid, sf.amount_paid, 0)
                ),
                0
            ) AS total_paid,

            COALESCE(
                SUM(
                    GREATEST(
                        sf.amount_due -
                        COALESCE(pay.total_paid, sf.amount_paid, 0),
                        0
                    )
                ),
                0
            ) AS outstanding

        FROM student_fees sf

        INNER JOIN enrollments e
            ON e.id = sf.enrollment_id

        INNER JOIN classes c
            ON c.id = e.class_id

        LEFT JOIN (
            SELECT
                student_fee_id,
                SUM(amount) AS total_paid
            FROM payments
            GROUP BY student_fee_id
        ) pay
            ON pay.student_fee_id = sf.id

        WHERE sf.status <> 'waived'
        $yearCondition

        GROUP BY c.id, c.class_name

        ORDER BY c.class_name ASC
    ";

    $stmt = $pdo->prepare($classSql);
    $stmt->execute($yearParams);

    $classReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * 4. STUDENTS WITH UNPAID OR PARTIALLY PAID FEES
     */
    $unpaidSql = "
        SELECT
            s.id AS student_id,
            s.admission_number,
            s.first_name,
            s.last_name,

            c.class_name,
            sec.section_name,

            f.fee_name,

            sf.amount_due,

            COALESCE(
                pay.total_paid,
                sf.amount_paid,
                0
            ) AS amount_paid,

            GREATEST(
                sf.amount_due -
                COALESCE(pay.total_paid, sf.amount_paid, 0),
                0
            ) AS balance,

            sf.due_date,
            sf.status

        FROM student_fees sf

        INNER JOIN students s
            ON s.id = sf.student_id

        INNER JOIN enrollments e
            ON e.id = sf.enrollment_id

        INNER JOIN classes c
            ON c.id = e.class_id

        LEFT JOIN sections sec
            ON sec.id = e.section_id

        INNER JOIN fees f
            ON f.id = sf.fee_id

        LEFT JOIN (
            SELECT
                student_fee_id,
                SUM(amount) AS total_paid
            FROM payments
            GROUP BY student_fee_id
        ) pay
            ON pay.student_fee_id = sf.id

        WHERE sf.status IN ('unpaid', 'partial')
        $yearCondition

        ORDER BY
            s.first_name ASC,
            s.last_name ASC

        LIMIT 500
    ";

    $stmt = $pdo->prepare($unpaidSql);
    $stmt->execute($yearParams);

    $unpaidStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * 5. DAILY PAYMENT COLLECTIONS
     */
    $dailySql = "
        SELECT
            p.payment_date,

            COUNT(p.id) AS payment_count,

            COALESCE(
                SUM(p.amount),
                0
            ) AS amount_collected

        FROM payments p

        INNER JOIN student_fees sf
            ON sf.id = p.student_fee_id

        INNER JOIN enrollments e
            ON e.id = sf.enrollment_id

        WHERE sf.status <> 'waived'
        $yearCondition
        $paymentDateCondition

        GROUP BY p.payment_date

        ORDER BY p.payment_date DESC

        LIMIT 100
    ";

    $stmt = $pdo->prepare($dailySql);

    $stmt->execute(
        array_merge($yearParams, $paymentParams)
    );

    $dailyReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {

    error_log('Fee Reports Error: ' . $ex->getMessage());

    $errors[] = 'A database error occurred while generating the reports. Please try again.';

    $summary = [];
    $totalCollected = 0;
    $classReports = [];
    $unpaidStudents = [];
    $dailyReports = [];
}

$activePage = 'fees';
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Fee Reports | CMA School Management</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>/assets/css/style.css"
    >

    <style>

        .report-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .06);
            height: 100%;
        }

        .report-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            background: #eef2ff;
            color: #3347a9;
        }

        .report-value {
            font-size: 1.45rem;
            font-weight: 700;
            margin-top: 12px;
            overflow-wrap: anywhere;
        }

        .report-label {
            color: #6c757d;
            font-size: .9rem;
        }

        .table th {
            white-space: nowrap;
        }

        .section-heading {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 15px;
        }

        .report-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .filter-section {
            background: #fff;
        }

        @media print {

            @page {
                size: landscape;
                margin: 12mm;
            }

            body {
                background: white !important;
                font-size: 11px;
            }

            .admin-sidebar,
            .sidebar-toggle,
            .filter-section,
            .no-print,
            .btn {
                display: none !important;
            }

            .admin-main {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            .container-fluid {
                padding: 0 !important;
            }

            .report-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                break-inside: avoid;
            }

            .table {
                font-size: 10px;
            }

            .table th,
            .table td {
                padding: 5px !important;
            }

            .report-value {
                font-size: 18px;
            }

            .card {
                break-inside: avoid;
            }

            h3,
            h5 {
                color: #000 !important;
            }

        }

    </style>

</head>

<body>

<?php require_once __DIR__ . '/../../includes/admin_sidebar.php'; ?>

<button
    class="sidebar-toggle no-print"
    id="sidebarToggle"
    type="button"
    aria-label="Toggle navigation"
>
    <i class="bi bi-list"></i>
</button>

<main class="admin-main">

    <div class="container-fluid py-4">

        <!-- PAGE HEADER -->

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">

            <div>

                <h3 class="fw-bold mb-1">
                    <i class="bi bi-bar-chart-line me-2"></i>
                    Fee Reports
                </h3>

                <p class="text-muted mb-0">
                    Financial overview, collections and outstanding student balances.
                </p>

            </div>

            <div class="report-actions no-print">

                <a
                    href="history.php"
                    class="btn btn-outline-success"
                >
                    <i class="bi bi-clock-history me-1"></i>
                    Payment History
                </a>

                <a
                    href="index.php"
                    class="btn btn-outline-primary"
                >
                    <i class="bi bi-arrow-left me-1"></i>
                    Fees Management
                </a>

                <button
                    type="button"
                    class="btn btn-dark"
                    onclick="window.print()"
                >
                    <i class="bi bi-printer me-1"></i>
                    Print Reports
                </button>

            </div>

        </div>

        <!-- ERROR MESSAGES -->

        <?php if (!empty($errors)): ?>

            <div class="alert alert-danger">

                <strong>
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Please check the following:
                </strong>

                <?php foreach ($errors as $error): ?>

                    <div><?= e($error) ?></div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <!-- FILTER SECTION -->

        <div class="card report-card mb-4 filter-section">

            <div class="card-body">

                <h5 class="section-heading">
                    <i class="bi bi-funnel me-2"></i>
                    Filter Fee Reports
                </h5>

                <form method="GET" class="row g-3 align-items-end">

                    <div class="col-md-3">

                        <label class="form-label">
                            Academic Year
                        </label>

                        <select
                            name="academic_year_id"
                            class="form-select"
                        >

                            <option value="0">
                                All Academic Years
                            </option>

                            <?php foreach ($academicYears as $year): ?>

                                <option
                                    value="<?= (int)$year['id'] ?>"
                                    <?= $selectedYear === (int)$year['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($year['year_name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-3">

                        <label class="form-label">
                            Payment Start Date
                        </label>

                        <input
                            type="date"
                            name="start_date"
                            class="form-control"
                            value="<?= e($startDate) ?>"
                        >

                    </div>

                    <div class="col-md-3">

                        <label class="form-label">
                            Payment End Date
                        </label>

                        <input
                            type="date"
                            name="end_date"
                            class="form-control"
                            value="<?= e($endDate) ?>"
                        >

                    </div>

                    <div class="col-md-3 d-flex gap-2">

                        <button
                            type="submit"
                            class="btn btn-primary flex-fill"
                        >
                            <i class="bi bi-filter me-1"></i>
                            Apply
                        </button>

                        <a
                            href="reports.php"
                            class="btn btn-outline-secondary"
                        >
                            Reset
                        </a>

                    </div>

                </form>

                <div class="small text-muted mt-3">

                    <i class="bi bi-info-circle me-1"></i>

                    Payment date filters affect the collection total and daily collection report.
                    Fee assignments and outstanding balances are calculated for the selected academic year.

                </div>

            </div>

        </div>

        <!-- FINANCIAL SUMMARY CARDS -->

        <div class="row g-3 mb-4">

            <!-- TOTAL ASSIGNED -->

            <div class="col-xl-3 col-md-6">

                <div class="card report-card">

                    <div class="card-body">

                        <div class="report-icon">
                            <i class="bi bi-cash-stack"></i>
                        </div>

                        <div class="report-value">
                            <?= money($summary['total_assigned'] ?? 0) ?>
                        </div>

                        <div class="report-label">
                            Total Fees Assigned
                        </div>

                    </div>

                </div>

            </div>

            <!-- TOTAL COLLECTED -->

            <div class="col-xl-3 col-md-6">

                <div class="card report-card">

                    <div class="card-body">

                        <div class="report-icon">
                            <i class="bi bi-wallet2"></i>
                        </div>

                        <div class="report-value text-success">
                            <?= money($totalCollected) ?>
                        </div>

                        <div class="report-label">
                            Payments Collected
                        </div>

                        <small class="text-muted">
                            Based on selected payment dates
                        </small>

                    </div>

                </div>

            </div>

            <!-- OUTSTANDING -->

            <div class="col-xl-3 col-md-6">

                <div class="card report-card">

                    <div class="card-body">

                        <div class="report-icon">
                            <i class="bi bi-exclamation-circle"></i>
                        </div>

                        <div class="report-value text-danger">
                            <?= money($summary['total_outstanding'] ?? 0) ?>
                        </div>

                        <div class="report-label">
                            Outstanding Balances
                        </div>

                    </div>

                </div>

            </div>

            <!-- STUDENTS -->

            <div class="col-xl-3 col-md-6">

                <div class="card report-card">

                    <div class="card-body">

                        <div class="report-icon">
                            <i class="bi bi-people"></i>
                        </div>

                        <div class="report-value">
                            <?= number_format((int)($summary['total_students'] ?? 0)) ?>
                        </div>

                        <div class="report-label">
                            Students with Assigned Fees
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- PAYMENT STATUS SUMMARY -->

        <div class="card report-card mb-4">

            <div class="card-body">

                <h5 class="section-heading">
                    <i class="bi bi-pie-chart me-2"></i>
                    Payment Status Summary
                </h5>

                <div class="row text-center g-3">

                    <div class="col-md-4">

                        <div class="p-3 bg-light rounded">

                            <h4 class="text-success mb-1">

                                <?= number_format((int)($summary['paid_records'] ?? 0)) ?>

                            </h4>

                            <span class="text-muted">
                                Fully Paid Fee Records
                            </span>

                        </div>

                    </div>

                    <div class="col-md-4">

                        <div class="p-3 bg-light rounded">

                            <h4 class="text-warning mb-1">

                                <?= number_format((int)($summary['partial_records'] ?? 0)) ?>

                            </h4>

                            <span class="text-muted">
                                Partially Paid Fee Records
                            </span>

                        </div>

                    </div>

                    <div class="col-md-4">

                        <div class="p-3 bg-light rounded">

                            <h4 class="text-danger mb-1">

                                <?= number_format((int)($summary['unpaid_records'] ?? 0)) ?>

                            </h4>

                            <span class="text-muted">
                                Unpaid Fee Records
                            </span>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- CLASS FEE SUMMARY -->

        <div class="card report-card mb-4">

            <div class="card-body">

                <h5 class="section-heading">
                    <i class="bi bi-mortarboard me-2"></i>
                    Fee Summary by Class
                </h5>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover align-middle">

                        <thead class="table-light">

                            <tr>
                                <th>Class</th>
                                <th>Students</th>
                                <th>Total Assigned</th>
                                <th>Total Paid</th>
                                <th>Outstanding</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if (!empty($classReports)): ?>

                                <?php foreach ($classReports as $class): ?>

                                    <tr>

                                        <td class="fw-semibold">
                                            <?= e($class['class_name']) ?>
                                        </td>

                                        <td>
                                            <?= number_format((int)$class['student_count']) ?>
                                        </td>

                                        <td>
                                            <?= money($class['total_assigned']) ?>
                                        </td>

                                        <td class="text-success">
                                            <?= money($class['total_paid']) ?>
                                        </td>

                                        <td class="text-danger fw-semibold">
                                            <?= money($class['outstanding']) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="5" class="text-center text-muted py-4">

                                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>

                                        No class fee records found.

                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        <!-- DAILY COLLECTION REPORT -->

        <div class="card report-card mb-4">

            <div class="card-body">

                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">

                    <h5 class="section-heading mb-0">
                        <i class="bi bi-calendar-check me-2"></i>
                        Payments Collected by Date
                    </h5>

                    <a
                        href="history.php"
                        class="btn btn-sm btn-outline-success no-print"
                    >
                        <i class="bi bi-clock-history me-1"></i>
                        View All Transactions
                    </a>

                </div>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover align-middle">

                        <thead class="table-light">

                            <tr>
                                <th>Payment Date</th>
                                <th>Number of Payments</th>
                                <th>Amount Collected</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if (!empty($dailyReports)): ?>

                                <?php foreach ($dailyReports as $daily): ?>

                                    <tr>

                                        <td>
                                            <?= e(date('d M Y', strtotime($daily['payment_date']))) ?>
                                        </td>

                                        <td>
                                            <?= number_format((int)$daily['payment_count']) ?>
                                        </td>

                                        <td class="text-success fw-semibold">
                                            <?= money($daily['amount_collected']) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="3" class="text-center text-muted py-4">

                                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>

                                        No payments found for the selected dates.

                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <?php if (count($dailyReports) >= 100): ?>

                    <small class="text-muted">
                        Showing the latest 100 daily collection records.
                    </small>

                <?php endif; ?>

            </div>

        </div>

        <!-- UNPAID AND PARTIALLY PAID STUDENTS -->

        <div class="card report-card mb-4">

            <div class="card-body">

                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">

                    <h5 class="section-heading mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Students with Unpaid or Partially Paid Fees
                    </h5>

                    <span class="badge bg-danger">
                        <?= number_format(count($unpaidStudents)) ?> Records
                    </span>

                </div>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover align-middle">

                        <thead class="table-light">

                            <tr>
                                <th>Admission Number</th>
                                <th>Student Name</th>
                                <th>Class</th>
                                <th>Section</th>
                                <th>Fee</th>
                                <th>Amount Due</th>
                                <th>Amount Paid</th>
                                <th>Balance</th>
                                <th>Due Date</th>
                                <th>Status</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if (!empty($unpaidStudents)): ?>

                                <?php foreach ($unpaidStudents as $student): ?>

                                    <tr>

                                        <td>
                                            <?= e($student['admission_number']) ?>
                                        </td>

                                        <td class="fw-semibold">
                                            <?= e($student['first_name'] . ' ' . $student['last_name']) ?>
                                        </td>

                                        <td>
                                            <?= e($student['class_name']) ?>
                                        </td>

                                        <td>
                                            <?= e($student['section_name'] ?: '-') ?>
                                        </td>

                                        <td>
                                            <?= e($student['fee_name']) ?>
                                        </td>

                                        <td>
                                            <?= money($student['amount_due']) ?>
                                        </td>

                                        <td class="text-success">
                                            <?= money($student['amount_paid']) ?>
                                        </td>

                                        <td class="text-danger fw-semibold">
                                            <?= money($student['balance']) ?>
                                        </td>

                                        <td>

                                            <?php if (!empty($student['due_date'])): ?>

                                                <?= e(date(
                                                    'd M Y',
                                                    strtotime($student['due_date'])
                                                )) ?>

                                            <?php else: ?>

                                                -

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <?php if ($student['status'] === 'partial'): ?>

                                                <span class="badge bg-warning text-dark">
                                                    Partial
                                                </span>

                                            <?php else: ?>

                                                <span class="badge bg-danger">
                                                    Unpaid
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="10" class="text-center text-muted py-4">

                                        <i class="bi bi-check-circle fs-4 d-block mb-2"></i>

                                        No unpaid or partially paid fees found.

                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <?php if (count($unpaidStudents) >= 500): ?>

                    <small class="text-muted">
                        Showing up to 500 records. Additional records may exist.
                    </small>

                <?php endif; ?>

            </div>

        </div>

        <!-- FOOTER -->

        <div class="text-muted small text-center mt-4">

            Carver Mission Academy — School Management System

            <br>

            Fee Reports

        </div>

    </div>

</main>

<script>

    // Mobile sidebar toggle.

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