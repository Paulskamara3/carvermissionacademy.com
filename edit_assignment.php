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

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: index.php');
    exit;
}

$errors = [];
$success = '';

try {
    // Load the assigned fee and related student information.
    $stmt = $pdo->prepare("
        SELECT
            sf.*,
            s.admission_number,
            s.first_name,
            s.middle_name,
            s.last_name,
            f.fee_name,
            c.class_name,
            ay.year_name
        FROM student_fees sf
        INNER JOIN students s ON sf.student_id = s.id
        INNER JOIN fees f ON sf.fee_id = f.id
        INNER JOIN enrollments en ON sf.enrollment_id = en.id
        INNER JOIN classes c ON en.class_id = c.id
        INNER JOIN academic_years ay ON en.academic_year_id = ay.id
        WHERE sf.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        header('Location: index.php');
        exit;
    }

    $amountDue = $assignment['amount_due'];
    $dueDate = $assignment['due_date'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $errors[] = 'Your session token is invalid. Please refresh the page and try again.';
        } else {
            $amountDue = trim($_POST['amount_due'] ?? '');
            $dueDate = trim($_POST['due_date'] ?? '');

            if ($amountDue === '' || !is_numeric($amountDue) || (float)$amountDue <= 0) {
                $errors[] = 'Please enter a valid amount greater than zero.';
            } elseif ((float)$amountDue < (float)$assignment['amount_paid']) {
                $errors[] = 'The amount due cannot be less than the amount already paid.';
            }

            if ($dueDate !== '') {
                $dateObject = DateTime::createFromFormat('Y-m-d', $dueDate);

                if (!$dateObject || $dateObject->format('Y-m-d') !== $dueDate) {
                    $errors[] = 'Please enter a valid due date.';
                }
            } else {
                $dueDate = null;
            }

            if (empty($errors)) {
                $newAmount = round((float)$amountDue, 2);
                $paidAmount = (float)$assignment['amount_paid'];

                // Preserve waived status; otherwise recalculate payment status.
                if ($assignment['status'] === 'waived') {
                    $newStatus = 'waived';
                } elseif ($paidAmount >= $newAmount) {
                    $newStatus = 'paid';
                } elseif ($paidAmount > 0) {
                    $newStatus = 'partial';
                } else {
                    $newStatus = 'unpaid';
                }

                $update = $pdo->prepare("
                    UPDATE student_fees
                    SET amount_due = ?, due_date = ?, status = ?
                    WHERE id = ?
                ");

                $update->execute([
                    $newAmount,
                    $dueDate,
                    $newStatus,
                    $id
                ]);

                header('Location: index.php?message=assignment_updated');
                exit;
            }
        }
    }
} catch (PDOException $e) {
    $errors[] = 'A database error occurred while processing this assignment.';
}

require_once __DIR__ . '/../../includes/admin_sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Fee Assignment - CMA</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<main class="admin-main">

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div>
            <h3 class="mb-1">Edit Fee Assignment</h3>
            <p class="text-muted mb-0">Update the amount due or payment deadline.</p>
        </div>

        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Fee Assignments
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>Please correct the following:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">Student and Fee Information</h5>
        </div>

        <div class="card-body">

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label text-muted">Student Name</label>
                    <div class="fw-semibold">
                        <?= e($assignment['first_name'] . ' ' .
                            ($assignment['middle_name'] ? $assignment['middle_name'] . ' ' : '') .
                            $assignment['last_name']) ?>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label text-muted">Admission Number</label>
                    <div class="fw-semibold"><?= e($assignment['admission_number']) ?></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label text-muted">Fee Name</label>
                    <div class="fw-semibold"><?= e($assignment['fee_name']) ?></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label text-muted">Class</label>
                    <div class="fw-semibold"><?= e($assignment['class_name']) ?></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label text-muted">Academic Year</label>
                    <div class="fw-semibold"><?= e($assignment['year_name']) ?></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label text-muted">Amount Already Paid</label>
                    <div class="fw-semibold text-success">
                        <?= number_format((float)$assignment['amount_paid'], 2) ?>
                    </div>
                </div>
            </div>

            <hr>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="amount_due" class="form-label">
                            Amount Due <span class="text-danger">*</span>
                        </label>

                        <input
                            type="number"
                            name="amount_due"
                            id="amount_due"
                            class="form-control"
                            min="<?= e(number_format((float)$assignment['amount_paid'], 2, '.', '')) ?>"
                            step="0.01"
                            value="<?= e($amountDue) ?>"
                            required
                        >

                        <small class="text-muted">
                            The amount cannot be lower than what the student has already paid.
                        </small>
                    </div>

                    <div class="col-md-6">
                        <label for="due_date" class="form-label">Due Date</label>

                        <input
                            type="date"
                            name="due_date"
                            id="due_date"
                            class="form-control"
                            value="<?= e($dueDate) ?>"
                        >

                        <small class="text-muted">Leave blank if there is no due date.</small>
                    </div>
                </div>

                <div class="alert alert-info mt-4">
                    <i class="bi bi-info-circle"></i>
                    Payment records and receipts will not be changed when you update this assignment.
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save Changes
                    </button>

                    <a href="index.php" class="btn btn-light border">
                        Cancel
                    </a>
                </div>
            </form>

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