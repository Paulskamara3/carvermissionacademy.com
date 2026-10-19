<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function money($value) {
    return number_format((float)$value, 2);
}

$studentId = (int)($_GET['student_id'] ?? 0);
$academicYearId = (int)($_GET['academic_year_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

$student = null;
$academicYears = [];
$feeRecords = [];
$paymentRecords = [];
$searchResults = [];
$errors = [];

$totalDue = 0;
$totalPaid = 0;
$totalBalance = 0;

try {
    // Academic years
    $stmt = $pdo->query("
        SELECT id, year_name
        FROM academic_years
        ORDER BY start_date DESC
    ");
    $academicYears = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Validate academic year
    if ($academicYearId > 0) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM academic_years WHERE id = ?
        ");
        $stmt->execute([$academicYearId]);

        if (!$stmt->fetchColumn()) {
            $academicYearId = 0;
            $errors[] = 'The selected academic year was not found.';
        }
    }

    // Find student
    if ($studentId > 0) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM students
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            $errors[] = 'The selected student could not be found.';
        }

    } elseif ($search !== '') {
        $stmt = $pdo->prepare("
            SELECT *
            FROM students
            WHERE admission_number = ?
               OR CONCAT(first_name, ' ', last_name) LIKE ?
               OR first_name LIKE ?
               OR last_name LIKE ?
            ORDER BY first_name, last_name
            LIMIT 20
        ");

        $likeSearch = '%' . $search . '%';

        $stmt->execute([
            $search,
            $likeSearch,
            $likeSearch,
            $likeSearch
        ]);

        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($searchResults) === 1) {
            $student = $searchResults[0];
            $studentId = (int)$student['id'];
        } elseif (count($searchResults) === 0) {
            $errors[] = 'No student matched your search.';
        }
    }

    if ($student) {
        $yearCondition = '';
        $params = [(int)$student['id']];

        if ($academicYearId > 0) {
            $yearCondition = ' AND en.academic_year_id = ? ';
            $params[] = $academicYearId;
        }

        // Assigned fees
        $feeSql = "
            SELECT
                sf.id AS student_fee_id,
                f.fee_name,
                f.description,
                sf.amount_due,
                COALESCE(pay.total_paid, sf.amount_paid, 0) AS amount_paid,
                GREATEST(
                    sf.amount_due - COALESCE(pay.total_paid, sf.amount_paid, 0),
                    0
                ) AS balance,
                sf.due_date,
                sf.status,
                en.academic_year_id,
                ay.year_name,
                c.class_name,
                sec.section_name

            FROM student_fees sf
            INNER JOIN fees f ON f.id = sf.fee_id
            INNER JOIN enrollments en ON en.id = sf.enrollment_id
            INNER JOIN academic_years ay ON ay.id = en.academic_year_id
            INNER JOIN classes c ON c.id = en.class_id
            LEFT JOIN sections sec ON sec.id = en.section_id

            LEFT JOIN (
                SELECT student_fee_id, SUM(amount) AS total_paid
                FROM payments
                GROUP BY student_fee_id
            ) pay ON pay.student_fee_id = sf.id

            WHERE sf.student_id = ?
            $yearCondition

            ORDER BY ay.start_date DESC, sf.created_at DESC
        ";

        $stmt = $pdo->prepare($feeSql);
        $stmt->execute($params);
        $feeRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($feeRecords as $fee) {
            if ($fee['status'] !== 'waived') {
                $totalDue += (float)$fee['amount_due'];
                $totalPaid += (float)$fee['amount_paid'];
                $totalBalance += (float)$fee['balance'];
            }
        }

        // Payment history
        $paymentSql = "
            SELECT
                p.id,
                p.amount,
                p.payment_date,
                p.payment_method,
                p.reference_number,
                p.notes,
                f.fee_name,
                ay.year_name,
                u.username AS received_by

            FROM payments p
            INNER JOIN student_fees sf ON sf.id = p.student_fee_id
            INNER JOIN fees f ON f.id = sf.fee_id
            INNER JOIN enrollments en ON en.id = sf.enrollment_id
            INNER JOIN academic_years ay ON ay.id = en.academic_year_id
            LEFT JOIN users u ON u.id = p.received_by

            WHERE sf.student_id = ?
            $yearCondition

            ORDER BY p.payment_date DESC, p.id DESC
        ";

        $stmt = $pdo->prepare($paymentSql);
        $stmt->execute($params);
        $paymentRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $errors[] = 'A database error occurred while loading the student statement.';
}

$activePage = 'fees';

$academicYearName = 'All Academic Years';

if ($academicYearId > 0) {
    foreach ($academicYears as $year) {
        if ((int)$year['id'] === $academicYearId) {
            $academicYearName = $year['year_name'];
            break;
        }
    }
}

$printTitle = 'CMA Fee Statement';

if ($student) {
    $printTitle = 'CMA Fee Statement - ' . $student['admission_number'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($printTitle) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
        :root {
            --cma-navy: #102b50;
            --cma-blue: #174b80;
            --cma-gold: #e5ad35;
            --cma-light: #f3f6fb;
            --cma-green: #198754;
            --cma-red: #dc3545;
        }

        body {
            background: var(--cma-light);
        }

        .statement-card {
            border: 1px solid #e4e9f0;
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(16,43,80,.07);
            overflow: hidden;
        }

        .school-heading {
            background: linear-gradient(135deg, #102b50, #1c5489);
            color: white;
            padding: 25px 15px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 25px;
            border-bottom: 5px solid var(--cma-gold);
        }

        .school-logo {
            width: 95px;
            height: 95px;
            object-fit: contain;
            background: white;
            padding: 6px;
            border-radius: 50%;
            margin-bottom: 12px;
            border: 3px solid var(--cma-gold);
        }

        .school-heading h3 {
            font-weight: 800;
            letter-spacing: 1.5px;
            color: white;
        }

        .school-heading h5 {
            color: #f5c65d;
            font-weight: 700;
            letter-spacing: 2px;
        }

        .school-heading p {
            color: #e2eaf4;
        }

        .statement-info {
            background: #f8fafc;
            border: 1px solid #e5eaf1;
            border-radius: 10px;
            padding: 18px;
        }

        .section-heading {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--cma-navy);
            margin-bottom: 15px;
            padding-left: 12px;
            border-left: 4px solid var(--cma-gold);
        }

        .summary-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--cma-navy);
        }

        .summary-label {
            color: #6c757d;
            font-size: .9rem;
            margin-top: 5px;
        }

        .summary-box {
            border-top: 4px solid var(--cma-blue);
        }

        .summary-box.paid {
            border-top-color: var(--cma-green);
        }

        .summary-box.balance {
            border-top-color: var(--cma-red);
        }

        .summary-icon {
            font-size: 1.8rem;
            color: var(--cma-gold);
            margin-bottom: 8px;
        }

        .table {
            vertical-align: middle;
        }

        .table thead th {
            background: var(--cma-navy) !important;
            color: white !important;
            font-weight: 600;
            white-space: nowrap;
        }

        .table td {
            padding: 11px;
        }

        .statement-footer {
            background: var(--cma-navy);
            color: white;
            border-top: 4px solid var(--cma-gold);
            padding: 15px;
            border-radius: 8px;
        }

        .statement-footer p {
            margin-bottom: 3px;
        }

        .btn-cma {
            background: var(--cma-navy);
            color: white;
            border: 1px solid var(--cma-navy);
        }

        .btn-cma:hover {
            background: var(--cma-blue);
            color: white;
        }

        .search-card {
            border-top: 4px solid var(--cma-gold);
        }

        #printStatus {
            display: none;
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm;
            }

            html, body {
                background: white !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                height: auto !important;
                overflow: visible !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body * {
                visibility: visible !important;
            }

            .admin-sidebar,
            .sidebar-toggle,
            .no-print,
            .btn,
            nav,
            .navbar {
                display: none !important;
            }

            .admin-main {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                min-height: auto !important;
            }

            .container-fluid {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .statement-card {
                box-shadow: none !important;
                border: 1px solid #d5dce5 !important;
                border-radius: 5px !important;
                overflow: visible !important;
            }

            .school-heading {
                background: #102b50 !important;
                color: white !important;
                border-bottom: 5px solid #e5ad35 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .school-heading h3,
            .school-heading h5,
            .school-heading p {
                color: white !important;
            }

            .school-logo {
                display: inline-block !important;
            }

            .statement-info {
                background: #f8fafc !important;
                break-inside: avoid;
            }

            .summary-box {
                break-inside: avoid;
            }

            .table-responsive {
                overflow: visible !important;
            }

            .table {
                width: 100% !important;
                font-size: 9px !important;
                border-collapse: collapse !important;
            }

            .table th,
            .table td {
                padding: 6px !important;
                border: 1px solid #ccc !important;
            }

            .table thead th {
                background: #102b50 !important;
                color: white !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .statement-footer {
                background: #102b50 !important;
                color: white !important;
                border-top: 4px solid #e5ad35 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                break-inside: avoid;
            }

            .statement-footer p {
                color: white !important;
            }

            .row {
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../../includes/admin_sidebar.php'; ?>

<button type="button" class="sidebar-toggle no-print" id="sidebarToggle">
    <i class="bi bi-list"></i>
</button>

<main class="admin-main">
<div class="container-fluid py-4">

    <!-- Page heading and print controls -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 no-print">

        <div>
            <h3 class="fw-bold mb-1" style="color:var(--cma-navy)">
                <i class="bi bi-wallet2 me-2"></i>
                Student Fee Statement
            </h3>
            <p class="text-muted mb-0">
                View a student's fee assignments, payments and outstanding balances.
            </p>
        </div>

        <?php if ($student): ?>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" onclick="printStatement()" class="btn btn-cma">
                    <i class="bi bi-printer me-1"></i>
                    Print Statement
                </button>

                <button type="button" onclick="saveStatementPDF()" class="btn btn-success">
                    <i class="bi bi-file-earmark-pdf me-1"></i>
                    Save as PDF
                </button>
            </div>
        <?php endif; ?>

    </div>

    <div id="printStatus" class="alert alert-info no-print" role="status"></div>

    <!-- Errors -->
    <?php if ($errors): ?>
        <div class="alert alert-danger no-print">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Search -->
    <div class="card statement-card search-card mb-4 no-print">
        <div class="card-body">

            <h5 class="section-heading">
                <i class="bi bi-search me-1"></i>
                Find Student Statement
            </h5>

            <form method="GET" class="row g-3 align-items-end">

                <div class="col-md-5">
                    <label class="form-label fw-semibold">Search Student</label>
                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Admission number or student name"
                        value="<?= e($search) ?>"
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Academic Year</label>
                    <select name="academic_year_id" class="form-select">
                        <option value="0">All Academic Years</option>

                        <?php foreach ($academicYears as $year): ?>
                            <option
                                value="<?= (int)$year['id'] ?>"
                                <?= $academicYearId === (int)$year['id'] ? 'selected' : '' ?>
                            >
                                <?= e($year['year_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-cma flex-fill">
                        <i class="bi bi-search me-1"></i> Search
                    </button>

                    <a href="student_statement.php" class="btn btn-outline-secondary">
                        Reset
                    </a>
                </div>

            </form>
        </div>
    </div>

    <!-- Multiple search results -->
    <?php if (!$student && count($searchResults) > 1): ?>
        <div class="card statement-card mb-4 no-print">
            <div class="card-body">

                <h5 class="section-heading">Select a Student</h5>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Admission Number</th>
                                <th>Student Name</th>
                                <th>Gender</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($searchResults as $result): ?>
                                <tr>
                                    <td><?= e($result['admission_number']) ?></td>
                                    <td><?= e($result['first_name'] . ' ' . $result['last_name']) ?></td>
                                    <td><?= e(ucfirst($result['gender'])) ?></td>
                                    <td>
                                        <a
                                            href="student_statement.php?student_id=<?= (int)$result['id'] ?>&academic_year_id=<?= $academicYearId ?>"
                                            class="btn btn-sm btn-cma"
                                        >
                                            <i class="bi bi-eye"></i> View Statement
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    <?php endif; ?>

    <?php if ($student): ?>

        <!-- PRINTABLE STATEMENT -->
        <div id="printArea">

            <!-- School header and student details -->
            <div class="card statement-card mb-4">
                <div class="card-body">

                    <div class="school-heading">

                        <img
                            src="<?= BASE_URL ?>/assets/images/school-logo.png"
                            alt="Carver Mission Academy Logo"
                            class="school-logo"
                            onerror="this.style.display='none';"
                        >

                        <h3 class="mb-2">CARVER MISSION ACADEMY</h3>
                        <h5 class="mb-2">STUDENT FEE STATEMENT</h5>
                        <p class="mb-0">Academic and Financial Records</p>

                    </div>

                    <div class="statement-info">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <p class="mb-2">
                                    <strong>Student Name:</strong>
                                    <?= e($student['first_name'] . ' ' . $student['last_name']) ?>
                                </p>

                                <p class="mb-2">
                                    <strong>Admission Number:</strong>
                                    <?= e($student['admission_number']) ?>
                                </p>

                                <p class="mb-0">
                                    <strong>Gender:</strong>
                                    <?= e(ucfirst($student['gender'])) ?>
                                </p>
                            </div>

                            <div class="col-md-6">
                                <p class="mb-2">
                                    <strong>Statement Date:</strong>
                                    <?= date('d M Y') ?>
                                </p>

                                <p class="mb-2">
                                    <strong>Academic Year:</strong>
                                    <?= e($academicYearName) ?>
                                </p>

                                <p class="mb-0">
                                    <strong>Student Status:</strong>
                                    <?= e(ucfirst($student['student_status'])) ?>
                                </p>
                            </div>

                        </div>
                    </div>

                </div>
            </div>

            <!-- Financial summary -->
            <div class="row g-3 mb-4">

                <div class="col-md-4">
                    <div class="card statement-card summary-box h-100">
                        <div class="card-body">
                            <i class="bi bi-wallet2 summary-icon"></i>
                            <div class="summary-value"><?= money($totalDue) ?></div>
                            <div class="summary-label">Total Fees Assigned</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card statement-card summary-box paid h-100">
                        <div class="card-body">
                            <i class="bi bi-check-circle summary-icon text-success"></i>
                            <div class="summary-value text-success"><?= money($totalPaid) ?></div>
                            <div class="summary-label">Total Amount Paid</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card statement-card summary-box balance h-100">
                        <div class="card-body">
                            <i class="bi bi-exclamation-circle summary-icon text-danger"></i>
                            <div class="summary-value text-danger"><?= money($totalBalance) ?></div>
                            <div class="summary-label">Outstanding Balance</div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Assigned fees -->
            <div class="card statement-card mb-4">
                <div class="card-body">

                    <h5 class="section-heading">
                        <i class="bi bi-receipt me-1"></i>
                        Assigned Fees
                    </h5>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle">

                            <thead>
                                <tr>
                                    <th>Academic Year</th>
                                    <th>Class</th>
                                    <th>Section</th>
                                    <th>Fee Name</th>
                                    <th>Amount Due</th>
                                    <th>Amount Paid</th>
                                    <th>Balance</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if ($feeRecords): ?>

                                    <?php foreach ($feeRecords as $fee): ?>
                                        <tr>
                                            <td><?= e($fee['year_name']) ?></td>
                                            <td><?= e($fee['class_name']) ?></td>
                                            <td><?= e($fee['section_name'] ?: '-') ?></td>
                                            <td><?= e($fee['fee_name']) ?></td>
                                            <td><?= money($fee['amount_due']) ?></td>
                                            <td class="text-success fw-semibold"><?= money($fee['amount_paid']) ?></td>
                                            <td class="text-danger fw-semibold"><?= money($fee['balance']) ?></td>
                                            <td>
                                                <?= !empty($fee['due_date'])
                                                    ? e(date('d M Y', strtotime($fee['due_date'])))
                                                    : '-' ?>
                                            </td>
                                            <td>
                                                <?php if ($fee['status'] === 'paid'): ?>
                                                    <span class="badge bg-success">Paid</span>
                                                <?php elseif ($fee['status'] === 'partial'): ?>
                                                    <span class="badge bg-warning text-dark">Partial</span>
                                                <?php elseif ($fee['status'] === 'waived'): ?>
                                                    <span class="badge bg-secondary">Waived</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Unpaid</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <tr class="table-light fw-bold">
                                        <td colspan="4">TOTAL</td>
                                        <td><?= money($totalDue) ?></td>
                                        <td class="text-success"><?= money($totalPaid) ?></td>
                                        <td class="text-danger"><?= money($totalBalance) ?></td>
                                        <td colspan="2"></td>
                                    </tr>

                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">
                                            No fee assignments found for this student.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>

                        </table>
                    </div>

                </div>
            </div>

            <!-- Payment history -->
            <div class="card statement-card mb-4">
                <div class="card-body">

                    <h5 class="section-heading">
                        <i class="bi bi-clock-history me-1"></i>
                        Payment History
                    </h5>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle">

                            <thead>
                                <tr>
                                    <th>Receipt Number</th>
                                    <th>Payment Date</th>
                                    <th>Fee Name</th>
                                    <th>Academic Year</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Reference Number</th>
                                    <th>Received By</th>
                                    <th class="no-print">Receipt</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if ($paymentRecords): ?>

                                    <?php foreach ($paymentRecords as $payment): ?>
                                        <tr>
                                            <td>
                                                CMA-<?= date('Y') ?>-<?= str_pad((string)$payment['id'], 6, '0', STR_PAD_LEFT) ?>
                                            </td>

                                            <td>
                                                <?= e(date('d M Y', strtotime($payment['payment_date']))) ?>
                                            </td>

                                            <td><?= e($payment['fee_name']) ?></td>
                                            <td><?= e($payment['year_name']) ?></td>
                                            <td class="text-success fw-semibold"><?= money($payment['amount']) ?></td>
                                            <td><?= e($payment['payment_method']) ?></td>
                                            <td><?= e($payment['reference_number'] ?: '-') ?></td>
                                            <td><?= e($payment['received_by'] ?: '-') ?></td>

                                            <td class="no-print">
                                                <a
                                                    href="receipt.php?payment_id=<?= (int)$payment['id'] ?>"
                                                    target="_blank"
                                                    class="btn btn-sm btn-outline-primary"
                                                >
                                                    <i class="bi bi-receipt"></i> Receipt
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">
                                            No payment history found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>

                        </table>
                    </div>

                </div>
            </div>

            <!-- Footer -->
            <div class="statement-footer text-center mt-4">
                <p class="fw-bold">CARVER MISSION ACADEMY</p>
                <p class="small">School Management System | Student Financial Records</p>
                <p class="small mb-0">End of Student Fee Statement</p>
            </div>

        </div>
        <!-- END PRINTABLE STATEMENT -->

    <?php elseif (empty($errors)): ?>

        <div class="card statement-card">
            <div class="card-body text-center py-5">
                <i class="bi bi-person-search display-4 text-muted"></i>
                <h5 class="mt-3">Search for a Student</h5>
                <p class="text-muted mb-0">
                    Enter a student's admission number or name to view their fee statement.
                </p>
            </div>
        </div>

    <?php endif; ?>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Sidebar toggle
    const sidebarToggle = document.getElementById('sidebarToggle');
    const adminSidebar = document.getElementById('adminSidebar');

    if (sidebarToggle && adminSidebar) {
        sidebarToggle.addEventListener('click', function () {
            adminSidebar.classList.toggle('show');
        });
    }

    // Prepare the document title for PDF saving
    const originalTitle = document.title;

    function preparePrint() {
        const printArea = document.getElementById('printArea');

        if (!printArea) {
            alert('Please search for and select a student first.');
            return false;
        }

        const admissionNumber = <?= json_encode($student['admission_number'] ?? '') ?>;

        document.title = 'CMA-Fee-Statement-' + admissionNumber;

        return true;
    }

    // Print statement
    function printStatement() {
        if (!preparePrint()) {
            return;
        }

        window.focus();

        setTimeout(function () {
            window.print();
        }, 300);
    }

    // Save as PDF using the browser print dialog
    function saveStatementPDF() {
        if (!preparePrint()) {
            return;
        }

        window.focus();

        setTimeout(function () {
            window.print();
        }, 300);
    }

    // Restore original page title after printing
    window.addEventListener('afterprint', function () {
        document.title = originalTitle;
    });

    console.log('CMA Student Fee Statement loaded.');
</script>

</body>
</html>
