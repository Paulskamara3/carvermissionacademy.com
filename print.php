<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole('admin');

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

$type = $_GET['type'] ?? '';

$students = [];
$student = null;
$pageTitle = '';

try {

    /*
    |--------------------------------------------------------------------------
    | PRINT STUDENT LIST
    |--------------------------------------------------------------------------
    */

    if ($type === 'student_list') {

        $search = trim($_GET['search'] ?? '');

        if ($search !== '') {

            $sql = "
                SELECT admission_number, first_name, middle_name,
                       last_name, gender, phone, admission_date,
                       student_status
                FROM students
                WHERE admission_number LIKE :admission
                   OR first_name LIKE :first_name
                   OR middle_name LIKE :middle_name
                   OR last_name LIKE :last_name
                ORDER BY id DESC
            ";

            $stmt = $pdo->prepare($sql);

            $term = "%{$search}%";

            $stmt->execute([
                'admission' => $term,
                'first_name' => $term,
                'middle_name' => $term,
                'last_name' => $term
            ]);

        } else {

            $stmt = $pdo->query("
                SELECT admission_number, first_name, middle_name,
                       last_name, gender, phone, admission_date,
                       student_status
                FROM students
                ORDER BY id DESC
            ");
        }

        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pageTitle = 'Student List';

    }

    /*
    |--------------------------------------------------------------------------
    | PRINT INDIVIDUAL STUDENT PROFILE
    |--------------------------------------------------------------------------
    */

    elseif ($type === 'student_profile') {

        $studentId = filter_input(
            INPUT_GET,
            'id',
            FILTER_VALIDATE_INT
        );

        if (!$studentId || $studentId < 1) {
            die('Invalid student ID.');
        }

        $stmt = $pdo->prepare("
            SELECT
                admission_number,
                first_name,
                middle_name,
                last_name,
                gender,
                date_of_birth,
                phone,
                email,
                address,
                guardian_name,
                guardian_phone,
                admission_date,
                student_status
            FROM students
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute(['id' => $studentId]);

        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            die('Student record not found.');
        }

        $pageTitle = 'Student Profile';

    } else {

        http_response_code(400);
        die('Invalid printing request.');

    }

} catch (PDOException $exception) {

    error_log('Printing Error: ' . $exception->getMessage());

    die('Unable to prepare the requested document. Please check the database.');
}

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function fullStudentName($student) {
    return trim(
        ($student['first_name'] ?? '') . ' ' .
        ($student['middle_name'] ?? '') . ' ' .
        ($student['last_name'] ?? '')
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> | Carver Mission Academy</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
          rel="stylesheet">

    <style>

        body {
            background: #f1f5f9;
            font-family: Arial, sans-serif;
            color: #222;
        }

        .print-container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 40px;
            background: #fff;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
        }

        .school-header {
            text-align: center;
            border-bottom: 3px solid #172554;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }

        .school-header h2 {
            color: #172554;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .school-header p {
            margin: 3px 0;
            color: #555;
        }

        .document-title {
            text-align: center;
            margin: 25px 0;
            font-weight: 700;
            text-transform: uppercase;
            color: #172554;
        }

        .section-heading {
            background: #f1f5f9;
            padding: 10px;
            font-weight: 700;
            border-left: 4px solid #172554;
            margin-top: 25px;
            margin-bottom: 15px;
        }

        .profile-table {
            width: 100%;
            border-collapse: collapse;
        }

        .profile-table td {
            padding: 12px;
            border: 1px solid #dee2e6;
        }

        .profile-table td:first-child {
            width: 35%;
            font-weight: 700;
            background: #f8fafc;
        }

        .table th {
            background: #eaf0f8;
            color: #172554;
            white-space: nowrap;
        }

        .table td,
        .table th {
            border: 1px solid #dee2e6;
            padding: 10px;
            font-size: 13px;
        }

        .document-footer {
            margin-top: 45px;
            padding-top: 15px;
            border-top: 1px solid #ccc;
            text-align: center;
            font-size: 12px;
            color: #666;
        }

        .print-actions {
            max-width: 1000px;
            margin: 20px auto;
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }

        @page {
            size: A4 portrait;
            margin: 15mm;
        }

        @media print {

            body {
                background: #fff;
            }

            .print-container {
                width: 100%;
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            .print-actions {
                display: none !important;
            }

            .school-header,
            .section-heading,
            .table th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .table {
                page-break-inside: auto;
            }

            tr {
                page-break-inside: avoid;
            }

        }

        @media (max-width: 768px) {

            .print-container {
                margin: 10px;
                padding: 18px;
            }

            .print-actions {
                margin: 10px;
            }

        }

    </style>

</head>

<body>

<!-- PRINT ACTIONS -->

<div class="print-actions">

    <button type="button"
            onclick="goBack()"
            class="btn btn-outline-secondary">

        <i class="bi bi-arrow-left me-1"></i>
        Back

    </button>

    <button onclick="window.print()"
            class="btn btn-primary">

        <i class="bi bi-printer-fill me-1"></i>
        Print Document

    </button>

</div>


<div class="print-container">

    <!-- SCHOOL HEADER -->

    <div class="school-header">

        <h2>CARVER MISSION ACADEMY</h2>

        <p>Official School Management System</p>

        <p><?= e($pageTitle) ?></p>

    </div>


    <?php if ($type === 'student_list'): ?>

        <!-- STUDENT LIST -->

        <h4 class="document-title">
            Registered Students
        </h4>

        <p>
            <strong>Date Printed:</strong>
            <?= date('d F Y') ?>
        </p>

        <p>
            <strong>Total Records:</strong>
            <?= count($students) ?>
        </p>

        <div class="table-responsive">

            <table class="table">

                <thead>

                    <tr>
                        <th>#</th>
                        <th>Admission Number</th>
                        <th>Student Name</th>
                        <th>Gender</th>
                        <th>Phone</th>
                        <th>Admission Date</th>
                        <th>Status</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if (!empty($students)): ?>

                        <?php foreach ($students as $index => $item): ?>

                            <tr>

                                <td><?= $index + 1 ?></td>

                                <td>
                                    <?= e($item['admission_number']) ?>
                                </td>

                                <td>
                                    <?= e(fullStudentName($item)) ?>
                                </td>

                                <td>
                                    <?= e(ucfirst($item['gender'] ?? '')) ?>
                                </td>

                                <td>
                                    <?= e($item['phone']) ?>
                                </td>

                                <td>
                                    <?= e($item['admission_date']) ?>
                                </td>

                                <td>
                                    <?= e(ucfirst($item['student_status'] ?? '')) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7" class="text-center">
                                No student records found.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>


    <?php elseif ($type === 'student_profile'): ?>

        <!-- INDIVIDUAL STUDENT PROFILE -->

        <h4 class="document-title">
            Student Profile
        </h4>

        <div class="section-heading">
            Personal Information
        </div>

        <table class="profile-table">

            <tr>
                <td>Admission Number</td>
                <td><?= e($student['admission_number']) ?></td>
            </tr>

            <tr>
                <td>Full Name</td>
                <td><?= e(fullStudentName($student)) ?></td>
            </tr>

            <tr>
                <td>Gender</td>
                <td><?= e(ucfirst($student['gender'] ?? '')) ?></td>
            </tr>

            <tr>
                <td>Date of Birth</td>
                <td><?= e($student['date_of_birth']) ?></td>
            </tr>

            <tr>
                <td>Admission Date</td>
                <td><?= e($student['admission_date']) ?></td>
            </tr>

            <tr>
                <td>Student Status</td>
                <td><?= e(ucfirst($student['student_status'] ?? '')) ?></td>
            </tr>

        </table>


        <div class="section-heading">
            Contact Information
        </div>

        <table class="profile-table">

            <tr>
                <td>Phone Number</td>
                <td><?= e($student['phone']) ?></td>
            </tr>

            <tr>
                <td>Email Address</td>
                <td><?= e($student['email']) ?></td>
            </tr>

            <tr>
                <td>Residential Address</td>
                <td><?= e($student['address']) ?></td>
            </tr>

        </table>


        <div class="section-heading">
            Guardian Information
        </div>

        <table class="profile-table">

            <tr>
                <td>Guardian Name</td>
                <td><?= e($student['guardian_name']) ?></td>
            </tr>

            <tr>
                <td>Guardian Phone</td>
                <td><?= e($student['guardian_phone']) ?></td>
            </tr>

        </table>


        <p class="mt-4">
            <strong>Date Printed:</strong>
            <?= date('d F Y') ?>
        </p>

    <?php endif; ?>


    <!-- DOCUMENT FOOTER -->

    <div class="document-footer">

        Carver Mission Academy

        <br>

        Official Student Records

        <br>

        Printed on <?= date('d F Y, h:i A') ?>

    </div>

</div>


<!-- BACK BUTTON SCRIPT -->

<script>

function goBack() {

    const dashboardUrl = <?= json_encode(
        rtrim(BASE_URL, '/') . '/admin/dashboard.php'
    ) ?>;

    if (document.referrer) {

        try {

            const previousPage = new URL(document.referrer);

            if (previousPage.origin === window.location.origin) {

                window.history.back();
                return;

            }

        } catch (error) {

            // Use dashboard fallback.

        }

    }

    window.location.href = dashboardUrl;

}

</script>

</body>
</html>
