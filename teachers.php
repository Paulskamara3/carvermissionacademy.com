<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

$activePage = 'reports';

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

// --------------------------------------------------
// FILTERS
// --------------------------------------------------

$search = trim($_GET['search'] ?? '');
$gender = trim($_GET['gender'] ?? '');
$status = trim($_GET['status'] ?? '');

$allowedGenders = ['male', 'female'];

$allowedStatuses = [
    'active',
    'inactive'
];

if (!in_array($gender, $allowedGenders, true)) {
    $gender = '';
}

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

try {

    // --------------------------------------------------
    // TEACHER REPORT QUERY
    // --------------------------------------------------

    $sql = "
        SELECT
            id,
            employee_number,
            first_name,
            last_name,
            gender,
            phone,
            email,
            qualification,
            employment_date,
            teacher_status
        FROM teachers
        WHERE 1 = 1
    ";

    $params = [];

    if ($search !== '') {

        $sql .= "
            AND (
                employee_number LIKE :employee
                OR first_name LIKE :first_name
                OR last_name LIKE :last_name
                OR phone LIKE :phone
                OR email LIKE :email
            )
        ";

        $term = "%{$search}%";

        $params['employee'] = $term;
        $params['first_name'] = $term;
        $params['last_name'] = $term;
        $params['phone'] = $term;
        $params['email'] = $term;
    }

    if ($gender !== '') {
        $sql .= " AND gender = :gender";
        $params['gender'] = $gender;
    }

    if ($status !== '') {
        $sql .= " AND teacher_status = :status";
        $params['status'] = $status;
    }

    $sql .= " ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --------------------------------------------------
    // STATISTICS
    // --------------------------------------------------

    $totalTeachers = count($teachers);
    $maleTeachers = 0;
    $femaleTeachers = 0;
    $activeTeachers = 0;
    $inactiveTeachers = 0;

    foreach ($teachers as $teacher) {

        if (strtolower($teacher['gender'] ?? '') === 'male') {
            $maleTeachers++;
        }

        if (strtolower($teacher['gender'] ?? '') === 'female') {
            $femaleTeachers++;
        }

        if (strtolower($teacher['teacher_status'] ?? '') === 'active') {
            $activeTeachers++;
        } else {
            $inactiveTeachers++;
        }
    }

    // --------------------------------------------------
    // CSV EXPORT
    // --------------------------------------------------

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {

        $filename = 'CMA_Teacher_Report_' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');

        header(
            'Content-Disposition: attachment; filename="' .
            $filename . '"'
        );

        $output = fopen('php://output', 'w');

        // UTF-8 BOM for Excel.
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, [
            'Employee Number',
            'First Name',
            'Last Name',
            'Gender',
            'Phone',
            'Email',
            'Qualification',
            'Employment Date',
            'Employment Status'
        ]);

        foreach ($teachers as $teacher) {

            fputcsv($output, [
                $teacher['employee_number'],
                $teacher['first_name'],
                $teacher['last_name'],
                $teacher['gender'],
                $teacher['phone'],
                $teacher['email'],
                $teacher['qualification'],
                $teacher['employment_date'],
                $teacher['teacher_status']
            ]);
        }

        fclose($output);
        exit;
    }

} catch (PDOException $exception) {

    error_log("Teacher Reports Error: " . $exception->getMessage());

    die("Unable to generate teacher reports. Please check your database.");
}

// --------------------------------------------------
// HELPER FUNCTIONS
// --------------------------------------------------

function statusBadge($status) {

    switch (strtolower($status ?? '')) {

        case 'active':
            return 'success';

        case 'inactive':
            return 'secondary';

        default:
            return 'secondary';
    }
}

function teacherReportUrl($changes = []) {

    $filters = [
        'search' => $_GET['search'] ?? '',
        'gender' => $_GET['gender'] ?? '',
        'status' => $_GET['status'] ?? ''
    ];

    $filters = array_merge($filters, $changes);

    foreach ($filters as $key => $value) {

        if ($value === '' || $value === null) {
            unset($filters[$key]);
        }
    }

    return 'teachers.php?' . http_build_query($filters);
}

// --------------------------------------------------
// PRINT INFORMATION
// --------------------------------------------------

$generatedDate = date('d F Y');
$generatedTime = date('h:i A');

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Teacher Reports | Carver Mission Academy</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="<?= BASE_URL ?>/assets/css/style.css">

    <style>

        body {
            background: #f4f6fa;
            font-family: Arial, sans-serif;
        }

        .reports-content {
            padding: 30px;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .page-heading h2 {
            font-weight: 700;
            color: #172554;
        }

        .summary-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.05);
            height: 100%;
        }

        .summary-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
        }

        .summary-card h3 {
            margin: 0;
            font-weight: 700;
            color: #172554;
        }

        .summary-card p {
            margin: 3px 0 0;
            color: #64748b;
            font-size: 13px;
        }

        .filter-card,
        .report-table-card {
            background: #fff;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.05);
        }

        .report-table-card {
            margin-top: 25px;
        }

        .table thead th {
            background: #f1f5f9;
            color: #334155;
            font-size: 13px;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
            font-size: 13px;
        }

        .teacher-name {
            font-weight: 600;
            color: #172554;
            min-width: 140px;
        }

        .filter-card label {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 6px;
        }

        .report-heading {
            color: #172554;
            font-weight: 700;
        }

        .empty-state {
            text-align: center;
            padding: 45px 15px;
            color: #64748b;
        }

        /* Print header is hidden on screen */

        .print-header {
            display: none;
        }

        @media (max-width: 768px) {

            .reports-content {
                padding: 18px;
                padding-top: 70px;
            }

        }

        /* --------------------------------------------------
           PRINT DESIGN
        -------------------------------------------------- */

        @media print {

            @page {
                size: A4 landscape;
                margin: 10mm;
            }

            html,
            body {
                width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                background: #fff !important;
                color: #000 !important;
                font-family: Arial, sans-serif !important;
                font-size: 10px !important;
            }

            /* Ensure report content is visible */

            .reports-content,
            .reports-content * {
                visibility: visible !important;
            }

            /* Hide navigation and controls */

            .sidebar,
            #adminSidebar,
            .sidebar-toggle,
            .no-print,
            .filter-card,
            .page-heading,
            nav,
            button {
                display: none !important;
            }

            /* Reset dashboard layout */

            .admin-main,
            main.admin-main {
                display: block !important;
                position: static !important;
                width: 100% !important;
                max-width: none !important;
                min-width: 0 !important;
                height: auto !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                transform: none !important;
                float: none !important;
            }

            .reports-content {
                display: block !important;
                position: static !important;
                width: 100% !important;
                max-width: none !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                transform: none !important;
            }

            /* School report heading */

            .print-header {
                display: block !important;
                position: static !important;
                width: 100% !important;
                margin: 0 0 18px 0 !important;
                padding: 0 !important;
                text-align: center !important;
                color: #000 !important;
            }

            .print-header h2 {
                font-size: 22px !important;
                font-weight: bold !important;
                color: #000 !important;
                margin-bottom: 5px !important;
            }

            .print-header h4 {
                font-size: 16px !important;
                font-weight: bold !important;
                color: #000 !important;
                margin-bottom: 5px !important;
            }

            .print-header p {
                color: #000 !important;
                margin-bottom: 8px !important;
            }

            .print-details {
                display: flex !important;
                justify-content: space-between !important;
                flex-wrap: wrap !important;
                gap: 8px !important;
                width: 100% !important;
                text-align: left !important;
                font-size: 10px !important;
                margin-top: 8px !important;
                color: #000 !important;
            }

            /* Summary cards */

            .reports-content > .row {
                display: flex !important;
                flex-wrap: nowrap !important;
                width: 100% !important;
                margin: 0 0 12px 0 !important;
                gap: 8px !important;
            }

            .reports-content > .row > div {
                display: block !important;
                flex: 1 1 25% !important;
                width: 25% !important;
                max-width: 25% !important;
                padding: 0 !important;
            }

            .summary-card {
                display: flex !important;
                align-items: center !important;
                width: 100% !important;
                height: auto !important;
                min-height: 55px !important;
                padding: 9px !important;
                border: 1px solid #777 !important;
                border-radius: 0 !important;
                background: #fff !important;
                box-shadow: none !important;
                color: #000 !important;
            }

            .summary-card h3 {
                color: #000 !important;
                font-size: 16px !important;
                margin: 0 !important;
            }

            .summary-card p {
                color: #000 !important;
                font-size: 10px !important;
                margin: 2px 0 0 !important;
            }

            .summary-icon {
                display: none !important;
            }

            /* Report table card */

            .report-table-card {
                display: block !important;
                position: static !important;
                width: 100% !important;
                max-width: none !important;
                height: auto !important;
                margin: 10px 0 0 !important;
                padding: 0 !important;
                border: none !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                background: #fff !important;
                overflow: visible !important;
            }

            .report-table-card > .d-flex {
                display: block !important;
                width: 100% !important;
                margin-bottom: 10px !important;
            }

            .report-table-card h5 {
                color: #000 !important;
                font-size: 14px !important;
                font-weight: bold !important;
            }

            .report-table-card p,
            .report-table-card .text-muted {
                color: #000 !important;
            }

            /* Prevent responsive table clipping */

            .table-responsive {
                display: block !important;
                position: static !important;
                width: 100% !important;
                max-width: none !important;
                height: auto !important;
                overflow: visible !important;
                transform: none !important;
            }

            /* Table formatting */

            table.table {
                display: table !important;
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                border-collapse: collapse !important;
                border-spacing: 0 !important;
                table-layout: auto !important;
                font-size: 9px !important;
                color: #000 !important;
                overflow: visible !important;
            }

            .table thead {
                display: table-header-group !important;
            }

            .table tbody {
                display: table-row-group !important;
            }

            .table tr {
                display: table-row !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            .table th,
            .table td {
                display: table-cell !important;
                visibility: visible !important;
                border: 1px solid #555 !important;
                padding: 5px 4px !important;
                color: #000 !important;
                background: #fff !important;
                font-size: 9px !important;
                vertical-align: middle !important;
                white-space: normal !important;
                overflow-wrap: anywhere !important;
            }

            .table thead th {
                background: #e9ecef !important;
                color: #000 !important;
                font-weight: bold !important;
            }

            .teacher-name {
                color: #000 !important;
                min-width: 0 !important;
            }

            .badge {
                color: #000 !important;
                background: #fff !important;
                border: 1px solid #777 !important;
                font-size: 8px !important;
                font-weight: normal !important;
            }

            .text-muted {
                color: #000 !important;
            }

            .empty-state {
                display: block !important;
                padding: 15px !important;
                color: #000 !important;
            }

            .empty-state i {
                display: none !important;
            }

            /* Report footer */

            .reports-content > .text-center {
                display: block !important;
                width: 100% !important;
                margin-top: 15px !important;
                padding-top: 8px !important;
                border-top: 1px solid #777 !important;
                color: #000 !important;
                text-align: center !important;
            }

            /* Print colors */

            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                text-shadow: none !important;
            }

            a {
                color: #000 !important;
                text-decoration: none !important;
            }

        }

    </style>

</head>

<body>

<?php require_once __DIR__ . '/../../includes/admin_sidebar.php'; ?>

<button class="sidebar-toggle no-print"
        id="sidebarToggle"
        type="button">

    <i class="bi bi-list"></i>

</button>

<main class="admin-main">

    <div class="reports-content">

        <!-- PAGE HEADING -->

        <div class="page-heading">

            <div>

                <h2>Teacher Reports</h2>

                <p class="text-muted mb-0">
                    View and generate teacher employment reports.
                </p>

            </div>

            <div class="d-flex gap-2 flex-wrap no-print">

                <a href="index.php"
                   class="btn btn-outline-secondary">

                    <i class="bi bi-arrow-left"></i>
                    Reports Dashboard

                </a>

                <button type="button"
                        onclick="window.print()"
                        class="btn btn-outline-primary">

                    <i class="bi bi-printer"></i>
                    Print Report

                </button>

                <a href="<?= e(teacherReportUrl(['export' => 'csv'])) ?>"
                   class="btn btn-success">

                    <i class="bi bi-file-earmark-spreadsheet"></i>
                    Export CSV

                </a>

            </div>

        </div>

        <!-- PRINT HEADER -->

        <div class="print-header">

            <h2>CARVER MISSION ACADEMY</h2>

            <h4>TEACHER REPORT</h4>

            <p>Teacher Employment and Staff Records</p>

            <hr>

            <div class="print-details">

                <span>
                    <strong>Generated:</strong>
                    <?= e($generatedDate) ?> at <?= e($generatedTime) ?>
                </span>

                <span>
                    <strong>Total Teachers:</strong>
                    <?= e($totalTeachers) ?>
                </span>

            </div>

            <?php if ($search !== ''): ?>

                <div class="print-details">
                    <span>
                        <strong>Search:</strong>
                        <?= e($search) ?>
                    </span>
                </div>

            <?php endif; ?>

            <?php if ($gender !== ''): ?>

                <div class="print-details">
                    <span>
                        <strong>Gender Filter:</strong>
                        <?= e(ucfirst($gender)) ?>
                    </span>
                </div>

            <?php endif; ?>

            <?php if ($status !== ''): ?>

                <div class="print-details">
                    <span>
                        <strong>Employment Status:</strong>
                        <?= e(ucfirst($status)) ?>
                    </span>
                </div>

            <?php endif; ?>

        </div>

        <!-- SUMMARY CARDS -->

        <div class="row g-3 mb-4">

            <div class="col-xl-3 col-md-6">

                <div class="summary-card">

                    <div class="summary-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-people-fill"></i>
                    </div>

                    <div>
                        <h3><?= e($totalTeachers) ?></h3>
                        <p>Total Teachers</p>
                    </div>

                </div>

            </div>

            <div class="col-xl-3 col-md-6">

                <div class="summary-card">

                    <div class="summary-icon bg-info bg-opacity-10 text-info">
                        <i class="bi bi-gender-male"></i>
                    </div>

                    <div>
                        <h3><?= e($maleTeachers) ?></h3>
                        <p>Male Teachers</p>
                    </div>

                </div>

            </div>

            <div class="col-xl-3 col-md-6">

                <div class="summary-card">

                    <div class="summary-icon bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-gender-female"></i>
                    </div>

                    <div>
                        <h3><?= e($femaleTeachers) ?></h3>
                        <p>Female Teachers</p>
                    </div>

                </div>

            </div>

            <div class="col-xl-3 col-md-6">

                <div class="summary-card">

                    <div class="summary-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-person-check-fill"></i>
                    </div>

                    <div>
                        <h3><?= e($activeTeachers) ?></h3>
                        <p>Active Teachers</p>
                    </div>

                </div>

            </div>

        </div>

        <!-- FILTERS -->

        <div class="filter-card no-print">

            <h5 class="report-heading mb-3">

                <i class="bi bi-funnel me-2"></i>
                Filter Teacher Reports

            </h5>

            <form method="GET" action="teachers.php">

                <div class="row g-3">

                    <div class="col-lg-5 col-md-6">

                        <label>Search Teacher</label>

                        <input type="search"
                               name="search"
                               class="form-control"
                               placeholder="Name, employee number or phone"
                               value="<?= e($search) ?>">

                    </div>

                    <div class="col-lg-3 col-md-6">

                        <label>Gender</label>

                        <select name="gender" class="form-select">

                            <option value="">All Genders</option>

                            <option value="male"
                                <?= $gender === 'male' ? 'selected' : '' ?>>
                                Male
                            </option>

                            <option value="female"
                                <?= $gender === 'female' ? 'selected' : '' ?>>
                                Female
                            </option>

                        </select>

                    </div>

                    <div class="col-lg-3 col-md-6">

                        <label>Employment Status</label>

                        <select name="status" class="form-select">

                            <option value="">All Statuses</option>

                            <option value="active"
                                <?= $status === 'active' ? 'selected' : '' ?>>
                                Active
                            </option>

                            <option value="inactive"
                                <?= $status === 'inactive' ? 'selected' : '' ?>>
                                Inactive
                            </option>

                        </select>

                    </div>

                    <div class="col-lg-1 d-flex align-items-end">

                        <button type="submit"
                                class="btn btn-primary w-100"
                                title="Generate Report">

                            <i class="bi bi-search"></i>

                        </button>

                    </div>

                </div>

                <div class="mt-3">

                    <a href="teachers.php"
                       class="btn btn-outline-secondary btn-sm">

                        <i class="bi bi-arrow-counterclockwise"></i>
                        Reset Filters

                    </a>

                </div>

            </form>

        </div>

        <!-- TEACHER REPORT TABLE -->

        <div class="report-table-card">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

                <div>

                    <h5 class="report-heading mb-1">
                        Teacher Records
                    </h5>

                    <p class="text-muted small mb-0">
                        <?= e($totalTeachers) ?> teacher record(s) found.
                    </p>

                </div>

                <div class="small text-muted no-print">
                    Generated: <?= e($generatedDate) ?>, <?= e($generatedTime) ?>
                </div>

            </div>

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead>

                        <tr>

                            <th>#</th>
                            <th>Employee Number</th>
                            <th>Teacher Name</th>
                            <th>Gender</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Qualification</th>
                            <th>Employment Date</th>
                            <th>Status</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (!empty($teachers)): ?>

                        <?php foreach ($teachers as $index => $teacher): ?>

                            <tr>

                                <td><?= $index + 1 ?></td>

                                <td class="fw-semibold">
                                    <?= e($teacher['employee_number']) ?>
                                </td>

                                <td class="teacher-name">

                                    <?= e($teacher['first_name']) ?>

                                    <?= e($teacher['last_name']) ?>

                                </td>

                                <td>
                                    <?= e(ucfirst($teacher['gender'])) ?>
                                </td>

                                <td>
                                    <?= e($teacher['phone']) ?>
                                </td>

                                <td>
                                    <?= e($teacher['email']) ?>
                                </td>

                                <td>
                                    <?= e($teacher['qualification']) ?>
                                </td>

                                <td>
                                    <?= e($teacher['employment_date']) ?>
                                </td>

                                <td>

                                    <span class="badge bg-<?= e(statusBadge($teacher['teacher_status'])) ?>">

                                        <?= e(ucfirst($teacher['teacher_status'])) ?>

                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="9">

                                <div class="empty-state">

                                    <i class="bi bi-person-badge fs-1"></i>

                                    <h5 class="mt-3">
                                        No Teachers Found
                                    </h5>

                                    <p>
                                        No teacher records match your selected filters.
                                    </p>

                                </div>

                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <div class="text-muted small mt-3">

                Showing <?= e($totalTeachers) ?> matching teacher record(s).

            </div>

        </div>

        <!-- FOOTER -->

        <div class="text-center text-muted small mt-4">

            &copy; <?= date('Y') ?> Carver Mission Academy.
            Teacher Reports.

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
