<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$activePage = 'fees';

$search = trim($_GET['search'] ?? '');
$paymentMethod = trim($_GET['payment_method'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$errors = [];
$payments = [];
$totalPayments = 0;
$totalAmount = 0;

$validMethods = [
    'Cash',
    'Bank Transfer',
    'Mobile Money',
    'Cheque',
    'Other'
];

// Validate filters.
if ($paymentMethod !== '' && !in_array($paymentMethod, $validMethods, true)) {
    $errors[] = 'Invalid payment method selected.';
    $paymentMethod = '';
}

foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $field => $date) {
    if ($date !== '') {
        $dateObject = DateTime::createFromFormat('Y-m-d', $date);

        if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
            $errors[] = 'Please enter valid dates.';
            break;
        }
    }
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $errors[] = 'The start date cannot be later than the end date.';
}

try {
    if (empty($errors)) {

        $sql = "
            SELECT
                p.id AS payment_id,
                p.amount,
                p.payment_date,
                p.payment_method,
                p.reference_number,
                p.notes,
                p.created_at,

                s.id AS student_id,
                s.admission_number,
                s.first_name,
                s.last_name,

                f.fee_name,
                sf.amount_due,

                ay.year_name,
                c.class_name,

                u.username AS received_by_name

            FROM payments p

            INNER JOIN student_fees sf
                ON sf.id = p.student_fee_id

            INNER JOIN students s
                ON s.id = sf.student_id

            INNER JOIN fees f
                ON f.id = sf.fee_id

            INNER JOIN enrollments en
                ON en.id = sf.enrollment_id

            INNER JOIN academic_years ay
                ON ay.id = en.academic_year_id

            INNER JOIN classes c
                ON c.id = en.class_id

            LEFT JOIN users u
                ON u.id = p.received_by

            WHERE 1 = 1
        ";

        $params = [];

        if ($search !== '') {
            $sql .= "
                AND (
                    s.admission_number LIKE ?
                    OR s.first_name LIKE ?
                    OR s.last_name LIKE ?
                    OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?
                    OR f.fee_name LIKE ?
                    OR p.reference_number LIKE ?
                )
            ";

            $term = '%' . $search . '%';

            array_push(
                $params,
                $term,
                $term,
                $term,
                $term,
                $term,
                $term
            );
        }

        if ($paymentMethod !== '') {
            $sql .= " AND p.payment_method = ?";
            $params[] = $paymentMethod;
        }

        if ($dateFrom !== '') {
            $sql .= " AND p.payment_date >= ?";
            $params[] = $dateFrom;
        }

        if ($dateTo !== '') {
            $sql .= " AND p.payment_date <= ?";
            $params[] = $dateTo;
        }

        $sql .= " ORDER BY p.payment_date DESC, p.id DESC LIMIT 500";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($payments as $payment) {
            $totalAmount += (float)$payment['amount'];
        }

        $totalPayments = count($payments);
    }

} catch (PDOException $e) {
    $errors[] = 'Unable to load payment history. Please try again.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Payment History | CMA School Management System</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>

<?php require_once __DIR__ . '/../../includes/admin_sidebar.php'; ?>

<main class="admin-main">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

        <div>
            <h2 class="mb-1">Payment History</h2>
            <p class="text-muted mb-0">
                View and search all recorded student payments.
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <a href="payment.php" class="btn btn-success">
                <i class="bi bi-plus-circle me-1"></i>
                Record Payment
            </a>

            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>
                Back to Fees
            </a>
        </div>

    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="row g-3 mb-4">

        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-muted mb-1">Payments Found</p>
                            <h3 class="mb-0"><?= number_format($totalPayments) ?></h3>
                        </div>

                        <div class="fs-2 text-primary">
                            <i class="bi bi-receipt"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-muted mb-1">Total Payments</p>
                            <h3 class="mb-0">
                                <?= number_format($totalAmount, 2) ?>
                            </h3>
                        </div>

                        <div class="fs-2 text-success">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="bi bi-funnel me-2"></i>
                Search and Filter Payments
            </h5>
        </div>

        <div class="card-body">

            <form method="GET" action="history.php">

                <div class="row g-3">

                    <div class="col-md-6">
                        <label for="search" class="form-label">
                            Search
                        </label>

                        <input type="text"
                               name="search"
                               id="search"
                               class="form-control"
                               value="<?= e($search) ?>"
                               placeholder="Student name, admission number, fee or reference">
                    </div>

                    <div class="col-md-6">
                        <label for="payment_method" class="form-label">
                            Payment Method
                        </label>

                        <select name="payment_method"
                                id="payment_method"
                                class="form-select">

                            <option value="">All Payment Methods</option>

                            <?php foreach ($validMethods as $method): ?>
                                <option value="<?= e($method) ?>"
                                    <?= $paymentMethod === $method ? 'selected' : '' ?>>
                                    <?= e($method) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-md-5">
                        <label for="date_from" class="form-label">
                            From Date
                        </label>

                        <input type="date"
                               name="date_from"
                               id="date_from"
                               class="form-control"
                               value="<?= e($dateFrom) ?>">
                    </div>

                    <div class="col-md-5">
                        <label for="date_to" class="form-label">
                            To Date
                        </label>

                        <input type="date"
                               name="date_to"
                               id="date_to"
                               class="form-control"
                               value="<?= e($dateTo) ?>">
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>
                            Filter
                        </button>
                    </div>

                </div>

                <div class="mt-3">
                    <a href="history.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Clear Filters
                    </a>
                </div>

            </form>

        </div>
    </div>

    <!-- Payment Records -->
    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="bi bi-clock-history me-2"></i>
                Recorded Payments
            </h5>
        </div>

        <div class="card-body">

            <?php if (empty($payments)): ?>

                <div class="text-center py-5">
                    <i class="bi bi-receipt-cutoff fs-1 text-muted"></i>

                    <h5 class="mt-3">No Payment Records Found</h5>

                    <p class="text-muted mb-3">
                        No payments match your search or filter criteria.
                    </p>

                    <a href="payment.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>
                        Record a Payment
                    </a>
                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-hover align-middle">

                        <thead class="table-light">
                            <tr>
                                <th>Payment ID</th>
                                <th>Student</th>
                                <th>Fee</th>
                                <th>Academic Year</th>
                                <th>Amount</th>
                                <th>Payment Date</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Received By</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($payments as $payment): ?>

                                <tr>

                                    <td>
                                        <strong>#<?= (int)$payment['payment_id'] ?></strong>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= e($payment['first_name'] . ' ' . $payment['last_name']) ?>
                                        </strong>
                                        <br>

                                        <small class="text-muted">
                                            <?= e($payment['admission_number']) ?>
                                        </small>
                                    </td>

                                    <td><?= e($payment['fee_name']) ?></td>

                                    <td><?= e($payment['year_name']) ?></td>

                                    <td class="fw-semibold text-success">
                                        <?= number_format((float)$payment['amount'], 2) ?>
                                    </td>

                                    <td>
                                        <?= e(date('d M Y', strtotime($payment['payment_date']))) ?>
                                    </td>

                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?= e($payment['payment_method']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= $payment['reference_number']
                                            ? e($payment['reference_number'])
                                            : '<span class="text-muted">N/A</span>' ?>
                                    </td>

                                    <td>
                                        <?= $payment['received_by_name']
                                            ? e($payment['received_by_name'])
                                            : '<span class="text-muted">N/A</span>' ?>
                                    </td>

                                    <td>
                                        <a href="receipt.php?payment_id=<?= (int)$payment['payment_id'] ?>"
                                           class="btn btn-sm btn-outline-primary"
                                           title="View Receipt">
                                            <i class="bi bi-printer"></i>
                                            Receipt
                                        </a>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                        <tfoot class="table-light">
                            <tr>
                                <th colspan="4" class="text-end">
                                    Total
                                </th>

                                <th class="text-success">
                                    <?= number_format($totalAmount, 2) ?>
                                </th>

                                <th colspan="5"></th>
                            </tr>
                        </tfoot>

                    </table>

                </div>

                <p class="text-muted small mt-3 mb-0">
                    Showing up to 500 payment records. Use the filters to narrow your results.
                </p>

            <?php endif; ?>

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
