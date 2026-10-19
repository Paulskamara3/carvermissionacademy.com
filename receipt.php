<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$paymentId = filter_input(INPUT_GET, 'payment_id', FILTER_VALIDATE_INT);

if (!$paymentId || $paymentId <= 0) {
    http_response_code(400);
    exit('Invalid payment receipt request.');
}

try {
    $stmt = $pdo->prepare("
        SELECT
            p.id AS payment_id,
            p.amount,
            p.payment_date,
            p.payment_method,
            p.reference_number,
            p.notes,
            p.created_at,

            s.admission_number,
            s.first_name,
            s.middle_name,
            s.last_name,
            s.guardian_name,

            sf.amount_due,
            sf.amount_paid,
            sf.due_date,

            f.fee_name,
            f.description AS fee_description,

            ay.year_name,
            c.class_name,

            sec.section_name,

            u.username AS received_by

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

        LEFT JOIN sections sec
            ON sec.id = en.section_id

        LEFT JOIN users u
            ON u.id = p.received_by

        WHERE p.id = ?
        LIMIT 1
    ");

    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        http_response_code(404);
        exit('Payment receipt not found.');
    }

    // Calculate total paid against this fee.
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM payments
        WHERE student_fee_id = (
            SELECT student_fee_id
            FROM payments
            WHERE id = ?
        )
    ");

    $stmt->execute([$paymentId]);
    $totalPaid = (float)$stmt->fetchColumn();

    $amountDue = (float)$payment['amount_due'];
    $balance = max(0, $amountDue - $totalPaid);

} catch (PDOException $e) {
    http_response_code(500);
    exit('Unable to load payment receipt.');
}

$studentName = trim(
    $payment['first_name'] . ' ' .
    ($payment['middle_name'] ?? '') . ' ' .
    $payment['last_name']
);

$receiptNumber = 'CMA-' . date('Y') . '-' .
    str_pad((string)$payment['payment_id'], 6, '0', STR_PAD_LEFT);

$schoolName = 'CARVER MISSION ACADEMY';
$schoolAddress = 'School Address';
$schoolPhone = 'School Contact Number';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Payment Receipt <?= e($receiptNumber) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background: #f1f3f5;
            font-family: Arial, sans-serif;
            color: #222;
        }

        .receipt-container {
            max-width: 850px;
            margin: 35px auto;
            background: #fff;
            padding: 45px;
            border: 1px solid #ddd;
            box-shadow: 0 4px 20px rgba(0,0,0,0.07);
        }

        .school-header {
            text-align: center;
            border-bottom: 3px solid #1e3a5f;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }

        .school-header h2 {
            font-weight: 800;
            color: #1e3a5f;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        .receipt-title {
            text-align: center;
            margin: 25px 0;
        }

        .receipt-title h3 {
            font-weight: 700;
            letter-spacing: 2px;
        }

        .receipt-info {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .info-block {
            flex: 1;
            min-width: 220px;
        }

        .info-block p {
            margin-bottom: 7px;
        }

        .table th {
            background: #edf1f5;
        }

        .total-box {
            max-width: 350px;
            margin-left: auto;
            margin-top: 20px;
        }

        .amount-paid {
            background: #e8f5e9;
            padding: 18px;
            border-radius: 6px;
            margin-top: 20px;
            text-align: center;
        }

        .amount-paid h2 {
            color: #198754;
            font-weight: 800;
        }

        .signature-area {
            display: flex;
            justify-content: space-between;
            gap: 40px;
            margin-top: 70px;
        }

        .signature {
            width: 45%;
            text-align: center;
            border-top: 1px solid #333;
            padding-top: 10px;
        }

        .receipt-footer {
            border-top: 1px dashed #999;
            margin-top: 40px;
            padding-top: 15px;
            text-align: center;
            color: #666;
        }

        @media print {
            body {
                background: #fff;
            }

            .receipt-container {
                max-width: 100%;
                margin: 0;
                padding: 25px;
                border: none;
                box-shadow: none;
            }

            .no-print {
                display: none !important;
            }

            @page {
                size: A4;
                margin: 12mm;
            }
        }

        @media (max-width: 576px) {
            .receipt-container {
                margin: 10px;
                padding: 20px;
            }

            .signature-area {
                gap: 20px;
            }
        }
    </style>
</head>

<body>

<div class="container no-print mt-3 d-flex justify-content-between flex-wrap gap-2">
    <a href="history.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>
        Back to Payment History
    </a>

    <button onclick="window.print()" class="btn btn-primary">
        <i class="bi bi-printer me-1"></i>
        Print Receipt
    </button>
</div>

<div class="receipt-container">

    <div class="school-header">

        <h2><?= e($schoolName) ?></h2>

        <p class="mb-1"><?= e($schoolAddress) ?></p>

        <p class="mb-0"><?= e($schoolPhone) ?></p>

    </div>

    <div class="receipt-title">
        <h3>OFFICIAL PAYMENT RECEIPT</h3>
        <p class="text-muted mb-0">School Fee Payment Confirmation</p>
    </div>

    <div class="receipt-info">

        <div class="info-block">

            <p>
                <strong>Receipt Number:</strong>
                <?= e($receiptNumber) ?>
            </p>

            <p>
                <strong>Payment ID:</strong>
                #<?= (int)$payment['payment_id'] ?>
            </p>

            <p>
                <strong>Payment Date:</strong>
                <?= e(date('d F Y', strtotime($payment['payment_date']))) ?>
            </p>

        </div>

        <div class="info-block text-md-end">

            <p>
                <strong>Academic Year:</strong>
                <?= e($payment['year_name']) ?>
            </p>

            <p>
                <strong>Payment Method:</strong>
                <?= e($payment['payment_method']) ?>
            </p>

            <p>
                <strong>Reference Number:</strong>
                <?= e($payment['reference_number'] ?: 'N/A') ?>
            </p>

        </div>

    </div>

    <h5 class="mb-3">Student Information</h5>

    <div class="row mb-4">

        <div class="col-md-6 mb-2">
            <strong>Student Name:</strong><br>
            <?= e($studentName) ?>
        </div>

        <div class="col-md-6 mb-2">
            <strong>Admission Number:</strong><br>
            <?= e($payment['admission_number']) ?>
        </div>

        <div class="col-md-6 mb-2">
            <strong>Class:</strong><br>
            <?= e($payment['class_name']) ?>
        </div>

        <div class="col-md-6 mb-2">
            <strong>Section:</strong><br>
            <?= e($payment['section_name'] ?: 'N/A') ?>
        </div>

    </div>

    <h5 class="mb-3">Payment Details</h5>

    <div class="table-responsive">

        <table class="table table-bordered">

            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>

            <tbody>

                <tr>
                    <td>
                        <?= e($payment['fee_name']) ?>

                        <?php if (!empty($payment['fee_description'])): ?>
                            <br>
                            <small class="text-muted">
                                <?= e($payment['fee_description']) ?>
                            </small>
                        <?php endif; ?>
                    </td>

                    <td class="text-end">
                        <?= number_format((float)$payment['amount'], 2) ?>
                    </td>
                </tr>

            </tbody>

        </table>

    </div>

    <div class="total-box">

        <div class="d-flex justify-content-between mb-2">
            <span>Total Fee Amount:</span>
            <strong><?= number_format($amountDue, 2) ?></strong>
        </div>

        <div class="d-flex justify-content-between mb-2">
            <span>Total Paid to Date:</span>
            <strong><?= number_format($totalPaid, 2) ?></strong>
        </div>

        <div class="d-flex justify-content-between mb-2">
            <span>Outstanding Balance:</span>
            <strong><?= number_format($balance, 2) ?></strong>
        </div>

    </div>

    <div class="amount-paid">

        <p class="mb-1">AMOUNT RECEIVED</p>

        <h2><?= number_format((float)$payment['amount'], 2) ?></h2>

        <p class="mb-0">
            <?= $balance <= 0 ? 'Fee fully paid' : 'Partial payment received' ?>
        </p>

    </div>

    <?php if (!empty($payment['notes'])): ?>
        <div class="mt-4">
            <strong>Payment Notes:</strong>
            <p><?= nl2br(e($payment['notes'])) ?></p>
        </div>
    <?php endif; ?>

    <div class="signature-area">

        <div class="signature">
            Student/Parent Signature
        </div>

        <div class="signature">
            Authorized School Official
            <?php if (!empty($payment['received_by'])): ?>
                <br>
                <small><?= e($payment['received_by']) ?></small>
            <?php endif; ?>
        </div>

    </div>

    <div class="receipt-footer">

        <strong>Thank you for your payment!</strong>

        <p class="mb-0 mt-1">
            This receipt is computer-generated and records the payment shown above.
        </p>

        <small>
            Receipt generated on <?= e(date('d F Y')) ?>
        </small>

    </div>

</div>

</body>
</html>
