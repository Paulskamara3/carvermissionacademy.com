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

$errors = [];
$successMessage = '';

$search = trim($_GET['search'] ?? '');

$selectedStudentFee = (int)($_POST['student_fee_id'] ?? 0);
$amount = trim($_POST['amount'] ?? '');
$paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
$paymentMethod = trim($_POST['payment_method'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$students = [];
$studentFees = [];

$validMethods = [
    'Cash',
    'Bank Transfer',
    'Mobile Money',
    'Cheque',
    'Other'
];

/*
|--------------------------------------------------------------------------
| Generate a unique payment reference number
|--------------------------------------------------------------------------
| Example: CMA-PAY-20261018-A1B2C3
*/
function generatePaymentReference(PDO $pdo) {

    for ($attempt = 0; $attempt < 10; $attempt++) {

        $reference = 'CMA-PAY-' .
            date('Ymd') . '-' .
            strtoupper(bin2hex(random_bytes(3)));

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM payments
            WHERE reference_number = ?
        ");

        $stmt->execute([$reference]);

        if ((int)$stmt->fetchColumn() === 0) {
            return $reference;
        }
    }

    throw new Exception(
        'Unable to generate a unique payment reference. Please try again.'
    );
}

/*
|--------------------------------------------------------------------------
| Generate reference preview
|--------------------------------------------------------------------------
*/
$referenceNumber = '';

try {
    $referenceNumber = generatePaymentReference($pdo);
} catch (Throwable $e) {
    $errors[] = 'Unable to generate payment reference.';
}

/*
|--------------------------------------------------------------------------
| Record payment
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    }

    if ($selectedStudentFee <= 0) {
        $errors[] = 'Please select a student fee.';
    }

    if (!is_numeric($amount) || (float)$amount <= 0) {
        $errors[] = 'Enter a valid payment amount greater than zero.';
    }

    if (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate) ||
        !DateTime::createFromFormat('!Y-m-d', $paymentDate) ||
        DateTime::createFromFormat('!Y-m-d', $paymentDate)->format('Y-m-d') !== $paymentDate
    ) {
        $errors[] = 'Enter a valid payment date.';
    }

    if (!in_array($paymentMethod, $validMethods, true)) {
        $errors[] = 'Select a valid payment method.';
    }

    if (strlen($notes) > 1000) {
        $errors[] = 'Payment notes must not exceed 1,000 characters.';
    }

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            /*
             * Lock the selected student fee record.
             */
            $stmt = $pdo->prepare("
                SELECT
                    sf.id,
                    sf.amount_due,
                    sf.amount_paid,
                    sf.status,
                    sf.student_id,
                    sf.enrollment_id
                FROM student_fees sf
                WHERE sf.id = ?
                FOR UPDATE
            ");

            $stmt->execute([$selectedStudentFee]);

            $studentFee = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$studentFee) {
                throw new Exception('The selected student fee was not found.');
            }

            if ($studentFee['status'] === 'waived') {
                throw new Exception('Payment cannot be recorded for a waived fee.');
            }

            /*
             * Calculate actual payments from the payments table.
             */
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount), 0)
                FROM payments
                WHERE student_fee_id = ?
            ");

            $stmt->execute([$selectedStudentFee]);

            $totalPaid = (float)$stmt->fetchColumn();

            /*
             * If no payment transaction exists, use the stored amount_paid.
             */
            if ($totalPaid <= 0 && (float)$studentFee['amount_paid'] > 0) {
                $totalPaid = (float)$studentFee['amount_paid'];
            }

            $amountDue = (float)$studentFee['amount_due'];
            $paymentAmount = round((float)$amount, 2);

            $outstanding = round($amountDue - $totalPaid, 2);

            if ($outstanding <= 0) {
                throw new Exception('This fee has already been fully paid.');
            }

            if ($paymentAmount > $outstanding + 0.001) {
                throw new Exception(
                    'Payment exceeds the outstanding balance of ' .
                    number_format(max(0, $outstanding), 2) . '.'
                );
            }

            /*
             * Calculate the new payment balance.
             */
            $newTotalPaid = round($totalPaid + $paymentAmount, 2);

            $newStatus = ($newTotalPaid >= $amountDue - 0.001)
                ? 'paid'
                : 'partial';

            /*
             * Generate the reference number on the server.
             * Do not rely on a reference supplied by the browser.
             */
            $referenceNumber = generatePaymentReference($pdo);

            /*
             * Get logged-in administrator.
             */
            $receivedBy = $_SESSION['user_id'] ?? null;

            /*
             * Save payment transaction.
             */
            $stmt = $pdo->prepare("
                INSERT INTO payments (
                    student_fee_id,
                    amount,
                    payment_date,
                    payment_method,
                    reference_number,
                    notes,
                    received_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $selectedStudentFee,
                $paymentAmount,
                $paymentDate,
                $paymentMethod,
                $referenceNumber,
                $notes !== '' ? $notes : null,
                $receivedBy
            ]);

            /*
             * Update student fee record.
             */
            $stmt = $pdo->prepare("
                UPDATE student_fees
                SET amount_paid = ?,
                    status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $newTotalPaid,
                $newStatus,
                $selectedStudentFee
            ]);

            $pdo->commit();

            /*
             * Redirect to prevent duplicate submissions.
             */
            header(
                'Location: payment.php?success=1&student_fee_id=' .
                $selectedStudentFee
            );

            exit;

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Payment Database Error: ' . $e->getMessage());

            $errors[] = 'Unable to record payment because of a database error. Please try again.';

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Success message
|--------------------------------------------------------------------------
*/
if (isset($_GET['success'])) {
    $successMessage = 'Payment recorded successfully.';
}

/*
|--------------------------------------------------------------------------
| Load students with outstanding fees
|--------------------------------------------------------------------------
*/
try {

    $studentSql = "
        SELECT DISTINCT
            s.id,
            s.admission_number,
            s.first_name,
            s.last_name

        FROM students s

        INNER JOIN student_fees sf
            ON sf.student_id = s.id

        LEFT JOIN (
            SELECT
                student_fee_id,
                SUM(amount) AS total_paid
            FROM payments
            GROUP BY student_fee_id
        ) pay
            ON pay.student_fee_id = sf.id

        WHERE sf.status <> 'waived'

        AND (
            sf.amount_due -
            COALESCE(pay.total_paid, sf.amount_paid, 0)
        ) > 0.001
    ";

    $studentParams = [];

    if ($search !== '') {

        $studentSql .= "
            AND (
                s.admission_number LIKE ?
                OR s.first_name LIKE ?
                OR s.last_name LIKE ?
                OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?
            )
        ";

        $term = '%' . $search . '%';

        $studentParams = [
            $term,
            $term,
            $term,
            $term
        ];
    }

    $studentSql .= "
        ORDER BY s.first_name ASC, s.last_name ASC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($studentSql);
    $stmt->execute($studentParams);

    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Determine which student's fees should be displayed.
     */
    $feeStudentId = (int)($_GET['student_id'] ?? 0);

    /*
     * Load outstanding fees.
     *
     * IMPORTANT:
     * Actual payments are calculated from the payments table.
     * This avoids hiding fees because amount_paid is outdated.
     */
    $feeSql = "
        SELECT
            sf.id AS student_fee_id,
            sf.student_id,
            sf.amount_due,
            sf.amount_paid,
            sf.due_date,
            sf.status,

            s.admission_number,
            s.first_name,
            s.last_name,

            f.fee_name,
            ay.year_name,
            c.class_name,

            COALESCE(
                pay.total_paid,
                sf.amount_paid,
                0
            ) AS actual_paid,

            GREATEST(
                sf.amount_due -
                COALESCE(pay.total_paid, sf.amount_paid, 0),
                0
            ) AS balance

        FROM student_fees sf

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

        LEFT JOIN (
            SELECT
                student_fee_id,
                SUM(amount) AS total_paid
            FROM payments
            GROUP BY student_fee_id
        ) pay
            ON pay.student_fee_id = sf.id

        WHERE sf.status <> 'waived'

        AND (
            sf.amount_due -
            COALESCE(pay.total_paid, sf.amount_paid, 0)
        ) > 0.001
    ";

    $feeParams = [];

    if ($feeStudentId > 0) {

        $feeSql .= " AND sf.student_id = ? ";

        $feeParams[] = $feeStudentId;

    } elseif ($selectedStudentFee > 0) {

        $feeSql .= " AND sf.id = ? ";

        $feeParams[] = $selectedStudentFee;

    } elseif ($search !== '') {

        $feeSql .= "
            AND (
                s.admission_number LIKE ?
                OR s.first_name LIKE ?
                OR s.last_name LIKE ?
                OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?
            )
        ";

        $term = '%' . $search . '%';

        array_push(
            $feeParams,
            $term,
            $term,
            $term,
            $term
        );

    } else {

        $feeSql .= " AND 1 = 0 ";
    }

    $feeSql .= "
        ORDER BY
            s.first_name ASC,
            s.last_name ASC,
            f.fee_name ASC
    ";

    $stmt = $pdo->prepare($feeSql);
    $stmt->execute($feeParams);

    $studentFees = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log('Load Student Fees Error: ' . $e->getMessage());

    $errors[] = 'Unable to load student fee information. Please check the database.';
}

/*
 * If a fee was selected through the URL, use it as the selected fee.
 */
if ($selectedStudentFee <= 0 && isset($_GET['student_fee_id'])) {
    $selectedStudentFee = (int)$_GET['student_fee_id'];
}

/*
 * Generate a fresh preview if necessary.
 */
if ($referenceNumber === '') {
    try {
        $referenceNumber = generatePaymentReference($pdo);
    } catch (Throwable $e) {
        $referenceNumber = '';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Record Payment | CMA School Management System</title>

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

        .payment-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .06);
        }

        .reference-field {
            background: #f1f5f9;
            font-weight: 700;
            letter-spacing: .5px;
        }

        .balance-text {
            font-weight: 600;
        }

        .table th {
            white-space: nowrap;
        }

    </style>

</head>

<body>

<?php require_once __DIR__ . '/../../includes/admin_sidebar.php'; ?>

<button
    class="sidebar-toggle"
    id="sidebarToggle"
    type="button"
    aria-label="Toggle navigation"
>
    <i class="bi bi-list"></i>
</button>

<main class="admin-main">

    <div class="container-fluid py-4">

        <!-- PAGE HEADER -->

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

            <div>

                <h2 class="mb-1 fw-bold">
                    <i class="bi bi-cash-coin me-2"></i>
                    Record Student Payment
                </h2>

                <p class="text-muted mb-0">
                    Record full or partial payments for assigned student fees.
                </p>

            </div>

            <div class="d-flex gap-2 flex-wrap">

                <a href="history.php" class="btn btn-outline-success">
                    <i class="bi bi-clock-history me-1"></i>
                    Payment History
                </a>

                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Fees
                </a>

            </div>

        </div>

        <!-- SUCCESS MESSAGE -->

        <?php if ($successMessage): ?>

            <div class="alert alert-success">

                <i class="bi bi-check-circle me-2"></i>

                <?= e($successMessage) ?>

                <?php if (isset($_GET['student_fee_id'])): ?>

                    <div class="mt-2">

                        <a
                            href="student_statement.php?student_fee_id=<?= (int)$_GET['student_fee_id'] ?>"
                            class="alert-link"
                        >
                            View student statement
                        </a>

                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>

        <!-- ERRORS -->

        <?php if (!empty($errors)): ?>

            <div class="alert alert-danger">

                <strong>
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Please check the following:
                </strong>

                <ul class="mb-0 mt-2">

                    <?php foreach ($errors as $error): ?>

                        <li><?= e($error) ?></li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>

        <!-- STUDENT SEARCH -->

        <div class="card payment-card mb-4">

            <div class="card-header bg-white py-3">

                <h5 class="mb-0">
                    <i class="bi bi-search me-2"></i>
                    Find Student
                </h5>

            </div>

            <div class="card-body">

                <form method="GET" class="row g-3">

                    <div class="col-md-9">

                        <label class="form-label">
                            Student Name or Admission Number
                        </label>

                        <input
                            type="text"
                            name="search"
                            class="form-control"
                            value="<?= e($search) ?>"
                            placeholder="Enter student name or admission number"
                        >

                    </div>

                    <div class="col-md-3 d-flex align-items-end">

                        <button type="submit" class="btn btn-primary w-100">

                            <i class="bi bi-search me-1"></i>
                            Search

                        </button>

                    </div>

                </form>

                <?php if ($search !== ''): ?>

                    <div class="mt-3">

                        <?php if (empty($students)): ?>

                            <p class="text-muted mb-0">
                                No students with outstanding fees were found.
                            </p>

                        <?php else: ?>

                            <p class="fw-semibold mb-2">
                                Search Results
                            </p>

                            <div class="list-group">

                                <?php foreach ($students as $student): ?>

                                    <a
                                        href="payment.php?student_id=<?= (int)$student['id'] ?>"
                                        class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                    >

                                        <span>

                                            <strong>
                                                <?= e($student['first_name'] . ' ' . $student['last_name']) ?>
                                            </strong>

                                            <br>

                                            <small class="text-muted">
                                                <?= e($student['admission_number']) ?>
                                            </small>

                                        </span>

                                        <i class="bi bi-chevron-right"></i>

                                    </a>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

        <!-- OUTSTANDING STUDENT FEES -->

        <?php if (!empty($studentFees)): ?>

            <div class="card payment-card mb-4">

                <div class="card-header bg-white py-3">

                    <h5 class="mb-0">
                        <i class="bi bi-receipt me-2"></i>
                        Outstanding Student Fees
                    </h5>

                </div>

                <div class="card-body">

                    <div class="table-responsive">

                        <table class="table table-hover align-middle">

                            <thead class="table-light">

                                <tr>
                                    <th>Student</th>
                                    <th>Fee</th>
                                    <th>Academic Year</th>
                                    <th>Amount Due</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Action</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($studentFees as $fee): ?>

                                    <?php
                                        $paid = (float)$fee['actual_paid'];
                                        $due = (float)$fee['amount_due'];
                                        $balance = max(0, $due - $paid);
                                    ?>

                                    <tr>

                                        <td>

                                            <strong>
                                                <?= e($fee['first_name'] . ' ' . $fee['last_name']) ?>
                                            </strong>

                                            <br>

                                            <small class="text-muted">
                                                <?= e($fee['admission_number']) ?>
                                            </small>

                                        </td>

                                        <td>
                                            <?= e($fee['fee_name']) ?>
                                        </td>

                                        <td>
                                            <?= e($fee['year_name']) ?>
                                        </td>

                                        <td>
                                            <?= number_format($due, 2) ?>
                                        </td>

                                        <td class="text-success">
                                            <?= number_format($paid, 2) ?>
                                        </td>

                                        <td class="text-danger fw-semibold">
                                            <?= number_format($balance, 2) ?>
                                        </td>

                                        <td>

                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary select-fee"
                                                data-id="<?= (int)$fee['student_fee_id'] ?>"
                                                data-balance="<?= e(number_format($balance, 2, '.', '')) ?>"
                                            >

                                                <i class="bi bi-cash me-1"></i>
                                                Pay

                                            </button>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        <?php elseif (isset($_GET['student_id'])): ?>

            <div class="alert alert-info">

                <i class="bi bi-info-circle me-2"></i>

                This student has no outstanding fee balance.

            </div>

        <?php endif; ?>

        <!-- PAYMENT FORM -->

        <div class="card payment-card">

            <div class="card-header bg-white py-3">

                <h5 class="mb-0">
                    <i class="bi bi-cash-coin me-2"></i>
                    Payment Information
                </h5>

            </div>

            <div class="card-body">

                <form method="POST" action="payment.php">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(generateCsrfToken()) ?>"
                    >

                    <div class="row g-3">

                        <!-- STUDENT FEE -->

                        <div class="col-md-6">

                            <label for="student_fee_id" class="form-label">

                                Student Fee

                                <span class="text-danger">*</span>

                            </label>

                            <select
                                name="student_fee_id"
                                id="student_fee_id"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select Outstanding Fee
                                </option>

                                <?php foreach ($studentFees as $fee): ?>

                                    <?php
                                        $balance = max(
                                            0,
                                            (float)$fee['amount_due'] -
                                            (float)$fee['actual_paid']
                                        );
                                    ?>

                                    <option
                                        value="<?= (int)$fee['student_fee_id'] ?>"
                                        data-balance="<?= e(number_format($balance, 2, '.', '')) ?>"
                                        <?= $selectedStudentFee === (int)$fee['student_fee_id'] ? 'selected' : '' ?>
                                    >

                                        <?= e($fee['first_name'] . ' ' . $fee['last_name']) ?>

                                        -
                                        <?= e($fee['fee_name']) ?>

                                        -
                                        Balance: <?= number_format($balance, 2) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <?php if (empty($studentFees)): ?>

                                <small class="text-danger">
                                    Search for a student and select one with an outstanding fee first.
                                </small>

                            <?php endif; ?>

                        </div>

                        <!-- PAYMENT AMOUNT -->

                        <div class="col-md-6">

                            <label for="amount" class="form-label">

                                Payment Amount

                                <span class="text-danger">*</span>

                            </label>

                            <input
                                type="number"
                                name="amount"
                                id="amount"
                                class="form-control"
                                step="0.01"
                                min="0.01"
                                value="<?= e($amount) ?>"
                                placeholder="Enter payment amount"
                                required
                            >

                            <small class="text-muted balance-text" id="balanceText">

                                Select a student fee to see the outstanding balance.

                            </small>

                        </div>

                        <!-- PAYMENT DATE -->

                        <div class="col-md-6">

                            <label for="payment_date" class="form-label">

                                Payment Date

                                <span class="text-danger">*</span>

                            </label>

                            <input
                                type="date"
                                name="payment_date"
                                id="payment_date"
                                class="form-control"
                                value="<?= e($paymentDate) ?>"
                                required
                            >

                        </div>

                        <!-- PAYMENT METHOD -->

                        <div class="col-md-6">

                            <label for="payment_method" class="form-label">

                                Payment Method

                                <span class="text-danger">*</span>

                            </label>

                            <select
                                name="payment_method"
                                id="payment_method"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select Payment Method
                                </option>

                                <?php foreach ($validMethods as $method): ?>

                                    <option
                                        value="<?= e($method) ?>"
                                        <?= $paymentMethod === $method ? 'selected' : '' ?>
                                    >

                                        <?= e($method) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- REFERENCE NUMBER -->

                        <div class="col-md-6">

                            <label for="reference_number" class="form-label">

                                Payment Reference Number

                            </label>

                            <input
                                type="text"
                                id="reference_number"
                                class="form-control reference-field"
                                value="<?= e($referenceNumber) ?>"
                                readonly
                            >

                            <small class="text-success">

                                <i class="bi bi-shield-check me-1"></i>

                                Automatically generated when payment is recorded.

                            </small>

                        </div>

                        <!-- NOTES -->

                        <div class="col-md-6">

                            <label for="notes" class="form-label">
                                Notes
                            </label>

                            <textarea
                                name="notes"
                                id="notes"
                                class="form-control"
                                rows="2"
                                maxlength="1000"
                                placeholder="Optional payment notes"
                            ><?= e($notes) ?></textarea>

                        </div>

                    </div>

                    <hr class="my-4">

                    <div class="d-flex justify-content-end gap-2 flex-wrap">

                        <a href="index.php" class="btn btn-light border">
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="btn btn-success"
                            <?= empty($studentFees) ? 'disabled' : '' ?>
                        >

                            <i class="bi bi-check-circle me-1"></i>

                            Record Payment

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/*
|--------------------------------------------------------------------------
| Update outstanding balance when a fee is selected
|--------------------------------------------------------------------------
*/

const feeSelect = document.getElementById('student_fee_id');
const amountInput = document.getElementById('amount');
const balanceText = document.getElementById('balanceText');

function updateBalance() {

    const option = feeSelect.options[feeSelect.selectedIndex];

    if (option && option.value !== '') {

        const balance = parseFloat(option.dataset.balance || 0);

        balanceText.textContent =
            'Outstanding balance: ' + balance.toFixed(2);

        amountInput.max = balance.toFixed(2);

    } else {

        balanceText.textContent =
            'Select a student fee to see the outstanding balance.';

        amountInput.removeAttribute('max');

    }

}

feeSelect.addEventListener('change', updateBalance);

/*
|--------------------------------------------------------------------------
| Select a fee from the outstanding fees table
|--------------------------------------------------------------------------
*/

document.querySelectorAll('.select-fee').forEach(button => {

    button.addEventListener('click', function () {

        feeSelect.value = this.dataset.id;

        updateBalance();

        amountInput.focus();

        document.getElementById('amount').scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });

    });

});

/*
|--------------------------------------------------------------------------
| Sidebar toggle
|--------------------------------------------------------------------------
*/

const sidebarToggle = document.getElementById('sidebarToggle');
const adminSidebar = document.getElementById('adminSidebar');

if (sidebarToggle && adminSidebar) {

    sidebarToggle.addEventListener('click', function () {

        adminSidebar.classList.toggle('show');

    });

}

updateBalance();

</script>

</body>
</html>