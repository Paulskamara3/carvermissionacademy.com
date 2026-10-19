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
$assignedCount = 0;
$skippedCount = 0;

$academicYears = [];
$classes = [];
$fees = [];

$selectedYear = (int)($_POST['academic_year_id'] ?? $_GET['academic_year_id'] ?? 0);
$selectedClass = (int)($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
$selectedFee = (int)($_POST['fee_id'] ?? 0);

try {
    $stmt = $pdo->query("
        SELECT id, year_name
        FROM academic_years
        ORDER BY start_date DESC
    ");
    $academicYears = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT id, class_name
        FROM classes
        ORDER BY class_name
    ");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT f.id, f.fee_name, f.amount, f.class_id,
               f.academic_year_id, f.due_date, ay.year_name,
               c.class_name
        FROM fees f
        INNER JOIN academic_years ay ON ay.id = f.academic_year_id
        LEFT JOIN classes c ON c.id = f.class_id
        WHERE f.status = 'active'
        ORDER BY ay.start_date DESC, f.fee_name
    ");
    $fees = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $errors[] = 'Unable to load the fee assignment information.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    }

    if ($selectedYear <= 0) {
        $errors[] = 'Please select an academic year.';
    }

    if ($selectedClass <= 0) {
        $errors[] = 'Please select a class.';
    }

    if ($selectedFee <= 0) {
        $errors[] = 'Please select a fee.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Confirm that the academic year exists.
            $stmt = $pdo->prepare("
                SELECT id
                FROM academic_years
                WHERE id = ?
            ");
            $stmt->execute([$selectedYear]);

            if (!$stmt->fetch()) {
                throw new Exception('The selected academic year does not exist.');
            }

            // Confirm that the class exists.
            $stmt = $pdo->prepare("
                SELECT id
                FROM classes
                WHERE id = ?
            ");
            $stmt->execute([$selectedClass]);

            if (!$stmt->fetch()) {
                throw new Exception('The selected class does not exist.');
            }

            // Get fee information and verify its academic year.
            $stmt = $pdo->prepare("
                SELECT id, fee_name, amount, class_id,
                       academic_year_id, due_date
                FROM fees
                WHERE id = ?
                  AND status = 'active'
                FOR UPDATE
            ");
            $stmt->execute([$selectedFee]);
            $fee = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$fee) {
                throw new Exception('The selected fee does not exist or is inactive.');
            }

            if ((int)$fee['academic_year_id'] !== $selectedYear) {
                throw new Exception('The selected fee does not belong to the selected academic year.');
            }

            // A class-specific fee can only be assigned to its designated class.
            if (
                $fee['class_id'] !== null &&
                (int)$fee['class_id'] !== $selectedClass
            ) {
                throw new Exception('This fee is assigned to a different class.');
            }

            // Get active students enrolled in the selected class and academic year.
            $stmt = $pdo->prepare("
                SELECT
                    e.id AS enrollment_id,
                    e.student_id
                FROM enrollments e
                INNER JOIN students s ON s.id = e.student_id
                WHERE e.academic_year_id = ?
                  AND e.class_id = ?
                  AND e.enrollment_status = 'active'
                  AND s.student_status = 'active'
                ORDER BY s.first_name, s.last_name
            ");

            $stmt->execute([$selectedYear, $selectedClass]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($students)) {
                throw new Exception(
                    'No active students are enrolled in the selected class and academic year.'
                );
            }

            // Prepare assignment statement.
            $insert = $pdo->prepare("
                INSERT IGNORE INTO student_fees
                    (student_id, enrollment_id, fee_id, amount_due, amount_paid, due_date, status)
                VALUES
                    (?, ?, ?, ?, 0.00, ?, 'unpaid')
            ");

            foreach ($students as $student) {

                $insert->execute([
                    $student['student_id'],
                    $student['enrollment_id'],
                    $selectedFee,
                    $fee['amount'],
                    $fee['due_date']
                ]);

                if ($insert->rowCount() > 0) {
                    $assignedCount++;
                } else {
                    $skippedCount++;
                }
            }

            $pdo->commit();

            $successMessage = 'Fee assignment completed successfully.';

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = $e->getMessage();

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'An error occurred while assigning fees. Please try again.';
        }
    }
}

$csrfToken = generateCsrfToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Fees | CMA School Management System</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>

<?php require_once __DIR__ . '/../../includes/admin_sidebar.php'; ?>

<main class="admin-main">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h2 class="mb-1">Assign Fees</h2>
            <p class="text-muted mb-0">
                Automatically assign fees to all active students in a selected class.
            </p>
        </div>

        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Fees
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>Please check the following:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="alert alert-success">
            <h5 class="alert-heading">
                <i class="bi bi-check-circle me-2"></i>
                <?= e($successMessage) ?>
            </h5>

            <p class="mb-1">
                Students successfully assigned:
                <strong><?= (int)$assignedCount ?></strong>
            </p>

            <p class="mb-0">
                Students skipped because they already had this fee:
                <strong><?= (int)$skippedCount ?></strong>
            </p>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="bi bi-people me-2"></i>
                Fee Assignment Information
            </h5>
        </div>

        <div class="card-body">

            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                The selected fee will automatically be assigned to every active student
                enrolled in the selected class and academic year. Existing assignments
                will not be duplicated.
            </div>

            <form method="POST" action="">

                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                <div class="row g-3">

                    <div class="col-md-6">
                        <label for="academic_year_id" class="form-label">
                            Academic Year <span class="text-danger">*</span>
                        </label>

                        <select name="academic_year_id" id="academic_year_id"
                                class="form-select" required>
                            <option value="">Select Academic Year</option>

                            <?php foreach ($academicYears as $year): ?>
                                <option value="<?= (int)$year['id'] ?>"
                                    <?= $selectedYear === (int)$year['id'] ? 'selected' : '' ?>>
                                    <?= e($year['year_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="class_id" class="form-label">
                            Class <span class="text-danger">*</span>
                        </label>

                        <select name="class_id" id="class_id"
                                class="form-select" required>
                            <option value="">Select Class</option>

                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int)$class['id'] ?>"
                                    <?= $selectedClass === (int)$class['id'] ? 'selected' : '' ?>>
                                    <?= e($class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label for="fee_id" class="form-label">
                            Fee Type <span class="text-danger">*</span>
                        </label>

                        <select name="fee_id" id="fee_id"
                                class="form-select" required>
                            <option value="">Select Fee</option>

                            <?php foreach ($fees as $fee): ?>
                                <option
                                    value="<?= (int)$fee['id'] ?>"
                                    data-year="<?= (int)$fee['academic_year_id'] ?>"
                                    data-class="<?= $fee['class_id'] === null ? 'all' : (int)$fee['class_id'] ?>"
                                    data-amount="<?= e(number_format((float)$fee['amount'], 2)) ?>"
                                    <?= $selectedFee === (int)$fee['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($fee['fee_name']) ?>
                                    - <?= e($fee['year_name']) ?>
                                    - <?= number_format((float)$fee['amount'], 2) ?>
                                    (<?= $fee['class_id'] === null ? 'All Classes' : e($fee['class_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <small class="text-muted">
                            Only fees belonging to the selected academic year and
                            applicable to the selected class can be assigned.
                        </small>
                    </div>

                    <div class="col-12">
                        <div id="feeDetails" class="alert alert-light border d-none">
                            <strong>Selected Fee Amount:</strong>
                            <span id="feeAmount">0.00</span>
                        </div>
                    </div>

                </div>

                <hr class="my-4">

                <div class="d-flex justify-content-end gap-2 flex-wrap">
                    <a href="index.php" class="btn btn-light border">
                        Cancel
                    </a>

                    <button type="submit" class="btn btn-primary"
                            onclick="return confirm('Are you sure you want to assign this fee to all active students in the selected class? Existing assignments will be skipped.');">
                        <i class="bi bi-check-circle me-1"></i>
                        Assign Fee to All Students
                    </button>
                </div>

            </form>

        </div>
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const yearSelect = document.getElementById('academic_year_id');
const classSelect = document.getElementById('class_id');
const feeSelect = document.getElementById('fee_id');
const feeDetails = document.getElementById('feeDetails');
const feeAmount = document.getElementById('feeAmount');

function filterFees() {
    const year = yearSelect.value;
    const selectedClass = classSelect.value;

    let selectedOptionValid = false;

    Array.from(feeSelect.options).forEach((option, index) => {
        if (index === 0) return;

        const feeYear = option.dataset.year;
        const feeClass = option.dataset.class;

        const matchesYear = year !== '' && feeYear === year;
        const matchesClass =
            selectedClass !== '' &&
            (feeClass === 'all' || feeClass === selectedClass);

        const visible = matchesYear && matchesClass;

        option.hidden = !visible;
        option.disabled = !visible;

        if (visible && option.selected) {
            selectedOptionValid = true;
        }
    });

    if (!selectedOptionValid) {
        feeSelect.value = '';
    }

    showFeeDetails();
}

function showFeeDetails() {
    const option = feeSelect.options[feeSelect.selectedIndex];

    if (option && option.value !== '' && !option.disabled) {
        feeAmount.textContent = option.dataset.amount;
        feeDetails.classList.remove('d-none');
    } else {
        feeDetails.classList.add('d-none');
    }
}

yearSelect.addEventListener('change', filterFees);
classSelect.addEventListener('change', filterFees);
feeSelect.addEventListener('change', showFeeDetails);

filterFees();

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
