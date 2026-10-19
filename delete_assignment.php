<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Verify CSRF token.
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    header('Location: index.php?error=invalid_token');
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: index.php?error=invalid_assignment');
    exit;
}

try {
    /*
     * First check that the assignment exists and get
     * basic information for validation.
     */
    $stmt = $pdo->prepare("
        SELECT
            sf.id,
            sf.student_id,
            sf.amount_due,
            sf.amount_paid,
            sf.status,
            s.admission_number,
            s.first_name,
            s.last_name,
            f.fee_name
        FROM student_fees sf
        INNER JOIN students s ON sf.student_id = s.id
        INNER JOIN fees f ON sf.fee_id = f.id
        WHERE sf.id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        header('Location: index.php?error=assignment_not_found');
        exit;
    }

    /*
     * Never delete a fee assignment that has payments.
     * Payment history and receipts must be preserved.
     */
    $paymentCheck = $pdo->prepare("
        SELECT COUNT(*)
        FROM payments
        WHERE student_fee_id = ?
    ");

    $paymentCheck->execute([$id]);
    $paymentCount = (int)$paymentCheck->fetchColumn();

    if ($paymentCount > 0) {
        header('Location: index.php?error=has_payments');
        exit;
    }

    /*
     * Also protect against deleting an assignment that
     * somehow has a recorded amount paid.
     */
    if ((float)$assignment['amount_paid'] > 0) {
        header('Location: index.php?error=has_paid_amount');
        exit;
    }

    /*
     * Delete the fee assignment.
     */
    $delete = $pdo->prepare("
        DELETE FROM student_fees
        WHERE id = ?
    ");

    $delete->execute([$id]);

    if ($delete->rowCount() !== 1) {
        header('Location: index.php?error=delete_failed');
        exit;
    }

    header('Location: index.php?message=assignment_deleted');
    exit;

} catch (PDOException $e) {

    /*
     * If the database prevents deletion because of a
     * foreign-key relationship, show a safe message.
     */
    header('Location: index.php?error=delete_failed');
    exit;
}