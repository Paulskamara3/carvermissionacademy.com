<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$errors = [];

/*
|--------------------------------------------------------------------------
| Load Academic Years
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT id, year_name, start_date, end_date, status
    FROM academic_years
    ORDER BY start_date DESC
");

$academicYears = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Find the active academic year.
$activeYear = null;

foreach ($academicYears as $year) {
    if ($year['status'] === 'active') {
        $activeYear = $year;
        break;
    }
}

// Determine selected academic year.
$selectedAcademicYearId =
    $_POST['academic_year_id']
    ?? $_GET['academic_year_id']
    ?? ($activeYear['id'] ?? '');

$selectedAcademicYear = null;

foreach ($academicYears as $year) {
    if ((string)$year['id'] === (string)$selectedAcademicYearId) {
        $selectedAcademicYear = $year;
        break;
    }
}

// If invalid, default to active year.
if (!$selectedAcademicYear) {
    $selectedAcademicYear = $activeYear;
    $selectedAcademicYearId = $activeYear['id'] ?? '';
}

/*
|--------------------------------------------------------------------------
| Selected Date, Class and Section
|--------------------------------------------------------------------------
*/

$selectedDate = $_POST['attendance_date']
    ?? $_GET['date']
    ?? date('Y-m-d');

$dateObject = DateTime::createFromFormat('Y-m-d', $selectedDate);

if (!$dateObject || $dateObject->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d');
}

$selectedClass = $_POST['class_id'] ?? $_GET['class_id'] ?? '';
$selectedSection = $_POST['section_id'] ?? $_GET['section_id'] ?? '';

$selectedClass = filter_var($selectedClass, FILTER_VALIDATE_INT);

$selectedSection = $selectedSection === ''
    ? ''
    : filter_var($selectedSection, FILTER_VALIDATE_INT);

if (!$selectedClass || $selectedClass < 1) {
    $selectedClass = '';
}

if ($selectedSection !== '' && (!$selectedSection || $selectedSection < 1)) {
    $selectedSection = '';
}

/*
|--------------------------------------------------------------------------
| Load Classes
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT id, class_name
    FROM classes
    ORDER BY class_name ASC
");

$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Load Sections
|--------------------------------------------------------------------------
*/

$sections = [];

if ($selectedClass !== '') {
    $stmt = $pdo->prepare("
        SELECT id, section_name
        FROM sections
        WHERE class_id = :class_id
        ORDER BY section_name ASC
    ");

    $stmt->execute([
        'class_id' => $selectedClass
    ]);

    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Validate that selected section belongs to selected class.
if ($selectedClass !== '' && $selectedSection !== '') {

    $validSection = false;

    foreach ($sections as $section) {
        if ((int)$section['id'] === (int)$selectedSection) {
            $validSection = true;
            break;
        }
    }

    if (!$validSection) {
        $selectedSection = '';
        $errors[] = 'The selected section does not belong to this class.';
    }
}

/*
|--------------------------------------------------------------------------
| Load Enrolled Students
|--------------------------------------------------------------------------
*/

function loadEnrolledStudents($pdo, $classId, $sectionId, $academicYearId) {

    $sql = "
        SELECT
            s.id,
            s.admission_number,
            s.first_name,
            s.middle_name,
            s.last_name,
            e.class_id,
            e.section_id
        FROM enrollments e
        INNER JOIN students s ON s.id = e.student_id
        WHERE e.class_id = :class_id
          AND e.academic_year_id = :academic_year_id
          AND e.enrollment_status = 'active'
          AND s.student_status = 'active'
    ";

    $params = [
        'class_id' => $classId,
        'academic_year_id' => $academicYearId
    ];

    if ($sectionId === '') {
        $sql .= " AND e.section_id IS NULL";
    } else {
        $sql .= " AND e.section_id = :section_id";
        $params['section_id'] = $sectionId;
    }

    $sql .= " ORDER BY s.first_name ASC, s.last_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Save Attendance
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    }

    if (!$selectedAcademicYear) {
        $errors[] = 'Please select a valid academic year.';
    }

    if ($selectedClass === '') {
        $errors[] = 'Please select a class.';
    }

    // Verify selected section.
    if ($selectedClass !== '' && $selectedSection !== '') {

        $validSection = false;

        foreach ($sections as $section) {
            if ((int)$section['id'] === (int)$selectedSection) {
                $validSection = true;
                break;
            }
        }

        if (!$validSection) {
            $errors[] = 'Invalid section selected.';
        }
    }

    if (!$errors) {

        $students = loadEnrolledStudents(
            $pdo,
            $selectedClass,
            $selectedSection,
            $selectedAcademicYear['id']
        );

        if (!$students) {

            $errors[] = 'No active enrolled students were found for the selected class and section.';

        } else {

            $statuses = $_POST['status'] ?? [];
            $remarks = $_POST['remarks'] ?? [];

            $allowedStatuses = [
                'present',
                'absent',
                'late',
                'excused'
            ];

            // Validate attendance status for every student.
            foreach ($students as $student) {

                $studentId = (int)$student['id'];
                $status = $statuses[$studentId] ?? '';

                if (!in_array($status, $allowedStatuses, true)) {
                    $errors[] = 'Please select a valid attendance status for every student.';
                    break;
                }
            }

            if (!$errors) {

                $markedBy = $_SESSION['user_id']
                    ?? $_SESSION['id']
                    ?? null;

                if (!is_numeric($markedBy) || (int)$markedBy < 1) {
                    $markedBy = null;
                } else {
                    $markedBy = (int)$markedBy;
                }

                try {

                    $pdo->beginTransaction();

                    // Find existing attendance for this class, section and date.
                    $findAttendance = $pdo->prepare("
                        SELECT id
                        FROM attendance
                        WHERE student_id = :student_id
                          AND class_id = :class_id
                          AND attendance_date = :attendance_date
                          AND (
                              section_id = :section_id
                              OR (
                                  section_id IS NULL
                                  AND :section_null IS NULL
                              )
                          )
                        ORDER BY id DESC
                        LIMIT 1
                    ");

                    $insertAttendance = $pdo->prepare("
                        INSERT INTO attendance (
                            student_id,
                            class_id,
                            section_id,
                            attendance_date,
                            attendance_status,
                            marked_by,
                            remarks
                        )
                        VALUES (
                            :student_id,
                            :class_id,
                            :section_id,
                            :attendance_date,
                            :attendance_status,
                            :marked_by,
                            :remarks
                        )
                    ");

                    $updateAttendance = $pdo->prepare("
                        UPDATE attendance
                        SET
                            attendance_status = :attendance_status,
                            marked_by = :marked_by,
                            remarks = :remarks
                        WHERE id = :id
                    ");

                    $sectionValue = $selectedSection === ''
                        ? null
                        : $selectedSection;

                    foreach ($students as $student) {

                        $studentId = (int)$student['id'];

                        $status = $statuses[$studentId];

                        $remark = trim(
                            (string)($remarks[$studentId] ?? '')
                        );

                        $findAttendance->execute([
                            'student_id' => $studentId,
                            'class_id' => $selectedClass,
                            'attendance_date' => $selectedDate,
                            'section_id' => $sectionValue,
                            'section_null' => $sectionValue
                        ]);

                        $existingRecord = $findAttendance->fetch(
                            PDO::FETCH_ASSOC
                        );

                        $remarkValue = $remark !== ''
                            ? $remark
                            : null;

                        if ($existingRecord) {

                            $updateAttendance->execute([
                                'attendance_status' => $status,
                                'marked_by' => $markedBy,
                                'remarks' => $remarkValue,
                                'id' => $existingRecord['id']
                            ]);

                        } else {

                            $insertAttendance->execute([
                                'student_id' => $studentId,
                                'class_id' => $selectedClass,
                                'section_id' => $sectionValue,
                                'attendance_date' => $selectedDate,
                                'attendance_status' => $status,
                                'marked_by' => $markedBy,
                                'remarks' => $remarkValue
                            ]);
                        }
                    }

                    $pdo->commit();

                    header(
                        'Location: mark.php?academic_year_id=' .
                        (int)$selectedAcademicYear['id'] .
                        '&class_id=' . (int)$selectedClass .
                        '&section_id=' . urlencode((string)$selectedSection) .
                        '&date=' . urlencode($selectedDate) .
                        '&message=saved'
                    );

                    exit;

                } catch (Throwable $ex) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log(
                        'Attendance saving error: ' . $ex->getMessage()
                    );

                    $errors[] = 'Unable to save attendance. Please try again.';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Students for Display
|--------------------------------------------------------------------------
*/

$students = [];

if (
    $selectedAcademicYear &&
    $selectedClass !== '' &&
    ($selectedSection !== '' || empty($sections))
) {

    $students = loadEnrolledStudents(
        $pdo,
        $selectedClass,
        $selectedSection,
        $selectedAcademicYear['id']
    );
}

/*
|--------------------------------------------------------------------------
| Load Existing Attendance
|--------------------------------------------------------------------------
*/

$existingAttendance = [];

if ($students) {

    $sectionValue = $selectedSection === ''
        ? null
        : $selectedSection;

    $stmt = $pdo->prepare("
        SELECT
            student_id,
            attendance_status,
            remarks
        FROM attendance
        WHERE attendance_date = :attendance_date
          AND class_id = :class_id
          AND (
              section_id = :section_id
              OR (
                  section_id IS NULL
                  AND :section_null IS NULL
              )
          )
    ");

    $stmt->execute([
        'attendance_date' => $selectedDate,
        'class_id' => $selectedClass,
        'section_id' => $sectionValue,
        'section_null' => $sectionValue
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $existingAttendance[$record['student_id']] = $record;
    }
}

$csrfToken = generateCsrfToken();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Mark Attendance | CMA School Management System</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>

        .mark-attendance-container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .mark-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .mark-card {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
        }

        .mark-card h5 {
            font-weight: 700;
            margin-bottom: 20px;
        }

        .form-label {
            font-weight: 600;
        }

        .form-control,
        .form-select {
            padding: 10px 12px;
        }

        .attendance-table th {
            white-space: nowrap;
        }

        .student-name {
            font-weight: 600;
        }

        .attendance-table select {
            min-width: 125px;
        }

        .empty-students {
            text-align: center;
            padding: 35px 15px;
            color: #6c757d;
        }

        .empty-students i {
            display: block;
            font-size: 40px;
            margin-bottom: 12px;
        }

        @media (max-width: 576px) {

            .mark-attendance-container {
                padding: 0 12px;
                margin-top: 20px;
            }

            .mark-card {
                padding: 17px;
            }

            .mark-header .btn {
                width: 100%;
            }
        }

    </style>

</head>

<body>

<?php
$activePage = 'attendance';
require_once __DIR__ . '/../../includes/admin_sidebar.php';
?>

<button class="sidebar-toggle" id="sidebarToggle" type="button">
    <i class="bi bi-list"></i>
</button>

<main class="admin-main">

    <div class="mark-attendance-container">

        <div class="mark-header">

            <div>
                <h2 class="fw-bold">Mark Attendance</h2>

                <p class="text-muted mb-0">
                    Record attendance for enrolled students.
                </p>
            </div>

            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i>
                Back to Attendance
            </a>

        </div>

        <?php if (!$selectedAcademicYear): ?>

            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                No academic year is available. Please create or activate an academic year first.
            </div>

        <?php else: ?>

            <div class="alert alert-info">

                <i class="bi bi-calendar-check"></i>

                Selected Academic Year:

                <strong>
                    <?= e($selectedAcademicYear['year_name']) ?>
                </strong>

                <?php if ($selectedAcademicYear['status'] === 'active'): ?>
                    <span class="badge bg-success ms-2">Active</span>
                <?php endif; ?>

            </div>

        <?php endif; ?>

        <?php if (isset($_GET['message']) && $_GET['message'] === 'saved'): ?>

            <div class="alert alert-success alert-dismissible fade show">

                <i class="bi bi-check-circle"></i>
                Attendance saved successfully.

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <?php if ($errors): ?>

            <div class="alert alert-danger">

                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <!-- Selection Form -->

        <div class="mark-card">

            <h5>
                <i class="bi bi-funnel text-primary"></i>
                Select Academic Year, Class and Section
            </h5>

            <form method="GET" action="mark.php">

                <div class="row g-3 align-items-end">

                    <!-- Academic Year -->

                    <div class="col-md-3">

                        <label class="form-label" for="academic_year_id">
                            Academic Year
                        </label>

                        <select
                            class="form-select"
                            name="academic_year_id"
                            id="academic_year_id"
                            required
                        >

                            <option value="">
                                Select Academic Year
                            </option>

                            <?php foreach ($academicYears as $year): ?>

                                <option
                                    value="<?= (int)$year['id'] ?>"
                                    <?= (string)$selectedAcademicYearId === (string)$year['id'] ? 'selected' : '' ?>
                                >

                                    <?= e($year['year_name']) ?>

                                    <?= $year['status'] === 'active' ? '(Active)' : '' ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Class -->

                    <div class="col-md-3">

                        <label class="form-label" for="class_id">
                            Class
                        </label>

                        <select
                            class="form-select"
                            name="class_id"
                            id="class_id"
                            required
                        >

                            <option value="">Select Class</option>

                            <?php foreach ($classes as $class): ?>

                                <option
                                    value="<?= (int)$class['id'] ?>"
                                    <?= (string)$selectedClass === (string)$class['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($class['class_name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Section -->

                    <div class="col-md-3">

                        <label class="form-label" for="section_id">
                            Section
                        </label>

                        <select
                            class="form-select"
                            name="section_id"
                            id="section_id"
                            <?= !empty($sections) ? 'required' : '' ?>
                        >

                            <?php if (!empty($sections)): ?>

                                <option value="">Select Section</option>

                                <?php foreach ($sections as $section): ?>

                                    <option
                                        value="<?= (int)$section['id'] ?>"
                                        <?= (string)$selectedSection === (string)$section['id'] ? 'selected' : '' ?>
                                    >
                                        <?= e($section['section_name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <option value="" selected>
                                    No Section
                                </option>

                            <?php endif; ?>

                        </select>

                    </div>

                    <!-- Attendance Date -->

                    <div class="col-md-3">

                        <label class="form-label" for="attendance_date">
                            Attendance Date
                        </label>

                        <input
                            type="date"
                            class="form-control"
                            name="date"
                            id="attendance_date"
                            value="<?= e($selectedDate) ?>"
                            required
                        >

                    </div>

                    <div class="col-12">

                        <button type="submit" class="btn btn-primary">

                            <i class="bi bi-people"></i>
                            Load Students

                        </button>

                    </div>

                </div>

            </form>

        </div>

        <!-- Attendance Table -->

        <?php if (
            $selectedAcademicYear &&
            $selectedClass !== '' &&
            ($selectedSection !== '' || empty($sections))
        ): ?>

            <div class="mark-card">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

                    <div>

                        <h5 class="mb-1">
                            Student Attendance List
                        </h5>

                        <p class="text-muted mb-0">
                            <?= count($students) ?> enrolled student(s)
                        </p>

                    </div>

                    <?php if ($students): ?>

                        <span class="badge bg-primary">
                            <?= e($selectedDate) ?>
                        </span>

                    <?php endif; ?>

                </div>

                <?php if ($students): ?>

                    <form method="POST" action="mark.php">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="academic_year_id"
                            value="<?= (int)$selectedAcademicYear['id'] ?>"
                        >

                        <input
                            type="hidden"
                            name="class_id"
                            value="<?= (int)$selectedClass ?>"
                        >

                        <input
                            type="hidden"
                            name="section_id"
                            value="<?= e($selectedSection) ?>"
                        >

                        <input
                            type="hidden"
                            name="attendance_date"
                            value="<?= e($selectedDate) ?>"
                        >

                        <div class="table-responsive">

                            <table class="table table-hover align-middle attendance-table">

                                <thead class="table-light">

                                    <tr>
                                        <th>#</th>
                                        <th>Admission Number</th>
                                        <th>Student Name</th>
                                        <th>Attendance Status</th>
                                        <th>Remarks</th>
                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($students as $index => $student): ?>

                                        <?php

                                        $studentId = (int)$student['id'];

                                        $studentName = trim(
                                            $student['first_name'] . ' ' .
                                            ($student['middle_name'] ?? '') . ' ' .
                                            $student['last_name']
                                        );

                                        $currentStatus =
                                            $existingAttendance[$studentId]['attendance_status']
                                            ?? 'present';

                                        $currentRemark =
                                            $existingAttendance[$studentId]['remarks']
                                            ?? '';

                                        ?>

                                        <tr>

                                            <td>
                                                <?= $index + 1 ?>
                                            </td>

                                            <td>
                                                <?= e($student['admission_number']) ?>
                                            </td>

                                            <td class="student-name">
                                                <?= e($studentName) ?>
                                            </td>

                                            <td>

                                                <select
                                                    name="status[<?= $studentId ?>]"
                                                    class="form-select attendance-status"
                                                    required
                                                >

                                                    <option
                                                        value="present"
                                                        <?= $currentStatus === 'present' ? 'selected' : '' ?>
                                                    >
                                                        Present
                                                    </option>

                                                    <option
                                                        value="absent"
                                                        <?= $currentStatus === 'absent' ? 'selected' : '' ?>
                                                    >
                                                        Absent
                                                    </option>

                                                    <option
                                                        value="late"
                                                        <?= $currentStatus === 'late' ? 'selected' : '' ?>
                                                    >
                                                        Late
                                                    </option>

                                                    <option
                                                        value="excused"
                                                        <?= $currentStatus === 'excused' ? 'selected' : '' ?>
                                                    >
                                                        Excused
                                                    </option>

                                                </select>

                                            </td>

                                            <td>

                                                <input
                                                    type="text"
                                                    name="remarks[<?= $studentId ?>]"
                                                    class="form-control"
                                                    placeholder="Optional remarks"
                                                    value="<?= e($currentRemark) ?>"
                                                >

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-4">

                            <p class="text-muted mb-0">
                                Review each student's status before saving.
                            </p>

                            <button type="submit" class="btn btn-success">

                                <i class="bi bi-save"></i>
                                Save Attendance

                            </button>

                        </div>

                    </form>

                <?php else: ?>

                    <div class="empty-students">

                        <i class="bi bi-people"></i>

                        <h5>No Enrolled Students Found</h5>

                        <p>
                            There are no active students enrolled in this class and section for the selected academic year.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

</main>

<script>

    const sidebarToggle = document.getElementById('sidebarToggle');
    const adminSidebar = document.getElementById('adminSidebar');

    if (sidebarToggle && adminSidebar) {

        sidebarToggle.addEventListener('click', function () {
            adminSidebar.classList.toggle('show');
        });

    }

    // Reload the page when the class changes to load its sections.
const classSelect = document.getElementById('class_id');

if (classSelect) {

    classSelect.addEventListener('change', function () {

        const academicYear = document.getElementById('academic_year_id').value;
        const attendanceDate = document.getElementById('attendance_date').value;
        const selectedClass = this.value;

        const params = new URLSearchParams();

        if (academicYear) {
            params.set('academic_year_id', academicYear);
        }

        if (selectedClass) {
            params.set('class_id', selectedClass);
        }

        if (attendanceDate) {
            params.set('date', attendanceDate);
        }

        window.location.href = 'mark.php?' + params.toString();

    });

}

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>