<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// School information
$schoolName = 'Carver Mission Academy';

try {
    $stmt = $pdo->prepare("
        SELECT setting_value
        FROM school_settings
        WHERE setting_key = 'school_name'
        LIMIT 1
    ");
    $stmt->execute();

    $savedName = $stmt->fetchColumn();

    if ($savedName) {
        $schoolName = $savedName;
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
}

$pageTitle = 'Admissions | ' . $schoolName;
$pageDescription = 'Apply for admission to Carver Mission Academy through our online application form.';

$successMessage = '';
$errorMessage = '';

$applicationNumber = '';
$studentUsername = '';
$temporaryPassword = '';

$old = [
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'gender' => '',
    'date_of_birth' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'previous_school' => '',
    'class_applying_for' => '',
    'academic_year' => '',
    'parent_name' => '',
    'parent_phone' => '',
    'parent_email' => ''
];

// Process application
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($old as $key => $value) {
        $old[$key] = trim($_POST[$key] ?? '');
    }

    try {

        // Verify CSRF token
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException(
                'Your session has expired. Please refresh the page and try again.'
            );
        }

        // Required fields
        $requiredFields = [
            'first_name',
            'last_name',
            'gender',
            'phone',
            'address',
            'class_applying_for',
            'academic_year',
            'parent_name',
            'parent_phone'
        ];

        foreach ($requiredFields as $field) {
            if ($old[$field] === '') {
                throw new RuntimeException(
                    'Please complete all required fields.'
                );
            }
        }

        // Validate gender
        if (!in_array($old['gender'], ['Male', 'Female'], true)) {
            throw new RuntimeException(
                'Please select a valid gender.'
            );
        }

        // Validate student email
        if (
            $old['email'] !== '' &&
            !filter_var($old['email'], FILTER_VALIDATE_EMAIL)
        ) {
            throw new RuntimeException(
                'Please enter a valid student email address.'
            );
        }

        // Validate parent email
        if (
            $old['parent_email'] !== '' &&
            !filter_var($old['parent_email'], FILTER_VALIDATE_EMAIL)
        ) {
            throw new RuntimeException(
                'Please enter a valid parent email address.'
            );
        }

        // Validate date of birth
        if ($old['date_of_birth'] !== '') {

            $date = DateTime::createFromFormat(
                '!Y-m-d',
                $old['date_of_birth']
            );

            if (
                !$date ||
                $date->format('Y-m-d') !== $old['date_of_birth']
            ) {
                throw new RuntimeException(
                    'Please enter a valid date of birth.'
                );
            }

            if ($date > new DateTime('today')) {
                throw new RuntimeException(
                    'Date of birth cannot be in the future.'
                );
            }
        }

        // Generate unique application number
        do {

            $applicationNumber = 'CMA-' .
                date('Ymd') . '-' .
                strtoupper(bin2hex(random_bytes(3)));

            $check = $pdo->prepare("
                SELECT id
                FROM admissions
                WHERE application_number = ?
                LIMIT 1
            ");

            $check->execute([$applicationNumber]);

        } while ($check->fetch());

        // Student username = application number
        $studentUsername = $applicationNumber;

        // Generate temporary password
        $temporaryPassword = bin2hex(random_bytes(5));

        // Get student role
        $roleStmt = $pdo->prepare("
            SELECT id
            FROM roles
            WHERE role_name = 'student'
            LIMIT 1
        ");

        $roleStmt->execute();

        $studentRoleId = $roleStmt->fetchColumn();

        if (!$studentRoleId) {
            throw new RuntimeException(
                'Student role was not found. Please contact the school administrator.'
            );
        }

        // Hash temporary password
        $passwordHash = password_hash(
            $temporaryPassword,
            PASSWORD_DEFAULT
        );

        if (!$passwordHash) {
            throw new RuntimeException(
                'Unable to generate student login credentials.'
            );
        }

        // Start transaction
        $pdo->beginTransaction();

        // Save admission application
        $stmt = $pdo->prepare("
            INSERT INTO admissions (
                application_number,
                first_name,
                middle_name,
                last_name,
                gender,
                date_of_birth,
                email,
                phone,
                address,
                previous_school,
                class_applying_for,
                academic_year,
                parent_name,
                parent_phone,
                parent_email,
                application_status
            ) VALUES (
                :application_number,
                :first_name,
                :middle_name,
                :last_name,
                :gender,
                :date_of_birth,
                :email,
                :phone,
                :address,
                :previous_school,
                :class_applying_for,
                :academic_year,
                :parent_name,
                :parent_phone,
                :parent_email,
                'pending'
            )
        ");

        $stmt->execute([
            ':application_number' => $applicationNumber,
            ':first_name' => $old['first_name'],
            ':middle_name' => $old['middle_name'] ?: null,
            ':last_name' => $old['last_name'],
            ':gender' => $old['gender'],
            ':date_of_birth' => $old['date_of_birth'] ?: null,
            ':email' => $old['email'] ?: null,
            ':phone' => $old['phone'],
            ':address' => $old['address'],
            ':previous_school' => $old['previous_school'] ?: null,
            ':class_applying_for' => $old['class_applying_for'],
            ':academic_year' => $old['academic_year'],
            ':parent_name' => $old['parent_name'],
            ':parent_phone' => $old['parent_phone'],
            ':parent_email' => $old['parent_email'] ?: null
        ]);

        // Create inactive student login account
        $userStmt = $pdo->prepare("
            INSERT INTO users (
                role_id,
                username,
                email,
                password_hash,
                account_status,
                must_change_password
            ) VALUES (
                :role_id,
                :username,
                NULL,
                :password_hash,
                'inactive',
                1
            )
        ");

        $userStmt->execute([
            ':role_id' => $studentRoleId,
            ':username' => $studentUsername,
            ':password_hash' => $passwordHash
        ]);

        // Commit both records
        $pdo->commit();

        $successMessage =
            'Your application has been submitted successfully.';

        // Clear form after successful submission
        foreach ($old as $key => $value) {
            $old[$key] = '';
        }

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Admissions submission error: ' . $e->getMessage()
        );

        if ($e instanceof RuntimeException) {
            $errorMessage = $e->getMessage();
        } else {
            $errorMessage =
                'Unable to submit your application right now. Please try again.';
        }

        // Do not display credentials if submission failed
        $applicationNumber = '';
        $studentUsername = '';
        $temporaryPassword = '';
    }
}

include __DIR__ . '/includes/header.php';
?>

<!-- ADMISSIONS HERO -->
<section class="admissions-hero">
    <div class="container text-center">

        <span>JOIN OUR SCHOOL COMMUNITY</span>

        <h1>Admissions at <?= htmlspecialchars($schoolName) ?></h1>

        <p>
            Take the first step towards a brighter future.
            Apply to become part of our learning community.
        </p>

        <div class="admission-breadcrumb">
            <a href="index.php">Home</a>
            <i class="bi bi-chevron-right"></i>
            <span>Admissions</span>
        </div>

    </div>
</section>


<!-- ADMISSION INTRODUCTION -->
<section class="section-padding">
    <div class="container">

        <div class="section-heading text-center">
            <span>START YOUR JOURNEY</span>

            <h2>Welcome to Our Admissions Office</h2>

            <p>
                We welcome applications from families seeking
                quality education, academic development,
                discipline and good character for their children.
            </p>
        </div>

        <div class="row g-4 mt-4">

            <div class="col-md-4">
                <div class="admission-info-card">
                    <i class="bi bi-file-earmark-text"></i>
                    <h4>Complete Application</h4>
                    <p>
                        Provide accurate student and parent
                        information using our online application form.
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="admission-info-card">
                    <i class="bi bi-clipboard-check"></i>
                    <h4>Application Review</h4>
                    <p>
                        Our school administration will review
                        submitted applications.
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="admission-info-card">
                    <i class="bi bi-mortarboard"></i>
                    <h4>Join CMA</h4>
                    <p>
                        Successful applicants will receive
                        further instructions from the school.
                    </p>
                </div>
            </div>

        </div>
    </div>
</section>


<!-- ADMISSION REQUIREMENTS -->
<section class="admission-requirements section-padding">
    <div class="container">

        <div class="row align-items-center g-5">

            <div class="col-lg-6">
                <span class="section-label">BEFORE YOU APPLY</span>

                <h2>Admission Information</h2>

                <p>
                    Applicants and parents should prepare
                    the relevant student and parent information
                    before completing the application.
                </p>

                <div class="requirement-item">
                    <i class="bi bi-check-circle-fill"></i>
                    <span>Student's personal information.</span>
                </div>

                <div class="requirement-item">
                    <i class="bi bi-check-circle-fill"></i>
                    <span>Student's date of birth and gender.</span>
                </div>

                <div class="requirement-item">
                    <i class="bi bi-check-circle-fill"></i>
                    <span>Previous school information, if applicable.</span>
                </div>

                <div class="requirement-item">
                    <i class="bi bi-check-circle-fill"></i>
                    <span>Parent or guardian contact information.</span>
                </div>

                <div class="requirement-item">
                    <i class="bi bi-check-circle-fill"></i>
                    <span>Class and academic year being applied for.</span>
                </div>

                <p class="small text-muted mt-3">
                    The school will communicate any additional
                    document requirements during the review process.
                </p>
            </div>

            <div class="col-lg-6">
                <div class="admission-process-card">

                    <h3>Application Process</h3>

                    <div class="process-step">
                        <span>01</span>
                        <div>
                            <h5>Submit Application</h5>
                            <p>Complete and submit the online form.</p>
                        </div>
                    </div>

                    <div class="process-step">
                        <span>02</span>
                        <div>
                            <h5>Application Review</h5>
                            <p>The administration reviews your application.</p>
                        </div>
                    </div>

                    <div class="process-step">
                        <span>03</span>
                        <div>
                            <h5>Admission Decision</h5>
                            <p>The school communicates its decision.</p>
                        </div>
                    </div>

                    <div class="process-step">
                        <span>04</span>
                        <div>
                            <h5>Student Registration</h5>
                            <p>Approved applicants proceed to registration.</p>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</section>


<!-- ONLINE APPLICATION FORM -->
<section class="online-application-section section-padding" id="application-form">
    <div class="container">

        <div class="section-heading text-center">
            <span>ONLINE APPLICATION</span>
            <h2>Apply for Admission</h2>
            <p>
                Complete the form below. Fields marked with
                an asterisk (*) are required.
            </p>
        </div>

        <div class="application-form-card">

            <!-- SUCCESS MESSAGE AND LOGIN CREDENTIALS -->
            <?php if ($successMessage): ?>

                <div class="alert alert-success">

                    <h4>
                        <i class="bi bi-check-circle-fill"></i>
                        Application Submitted Successfully!
                    </h4>

                    <p>
                        <?= htmlspecialchars($successMessage) ?>
                    </p>

                    <hr>

                    <h5>
                        <i class="bi bi-person-lock"></i>
                        Your Student Login Credentials
                    </h5>

                    <div class="mb-3">
                        <label class="fw-bold">Application Number</label>
                        <div class="form-control bg-light">
                            <?= htmlspecialchars($applicationNumber) ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="fw-bold">Username</label>
                        <div class="form-control bg-light">
                            <?= htmlspecialchars($studentUsername) ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="fw-bold">Temporary Password</label>
                        <div class="form-control bg-light fw-bold">
                            <?= htmlspecialchars($temporaryPassword) ?>
                        </div>
                    </div>

                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle-fill"></i>

                        <strong>Important Instructions</strong>

                        <ul class="mb-0 mt-2">
                            <li>Keep your username and temporary password safe.</li>
                            <li>Your application is awaiting school approval.</li>
                            <li>Your student account will become active after approval.</li>
                            <li>You must change your temporary password on your first login.</li>
                        </ul>
                    </div>

                </div>

            <?php endif; ?>

            <!-- ERROR MESSAGE -->
            <?php if ($errorMessage): ?>

                <div class="alert alert-danger">
                    <?= htmlspecialchars($errorMessage) ?>
                </div>

            <?php endif; ?>

            <!-- APPLICATION FORM -->
            <form method="POST" action="admissions.php#application-form">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= htmlspecialchars(generateCsrfToken()) ?>">

                <h4 class="form-section-title">
                    <i class="bi bi-person"></i>
                    Student Information
                </h4>

                <div class="row g-3">

                    <div class="col-md-4">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name"
                               class="form-control" required maxlength="100"
                               value="<?= htmlspecialchars($old['first_name']) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name"
                               class="form-control" maxlength="100"
                               value="<?= htmlspecialchars($old['middle_name']) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name"
                               class="form-control" required maxlength="100"
                               value="<?= htmlspecialchars($old['last_name']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Gender *</label>
                        <select name="gender" class="form-select" required>
                            <option value="">Select Gender</option>
                            <option value="Male" <?= $old['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= $old['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="date_of_birth"
                               class="form-control"
                               max="<?= date('Y-m-d') ?>"
                               value="<?= htmlspecialchars($old['date_of_birth']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Student Email</label>
                        <input type="email" name="email"
                               class="form-control" maxlength="150"
                               value="<?= htmlspecialchars($old['email']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Student Phone *</label>
                        <input type="tel" name="phone"
                               class="form-control" required maxlength="30"
                               value="<?= htmlspecialchars($old['phone']) ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Residential Address *</label>
                        <textarea name="address" class="form-control"
                                  rows="3" required><?= htmlspecialchars($old['address']) ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Previous School</label>
                        <input type="text" name="previous_school"
                               class="form-control" maxlength="200"
                               value="<?= htmlspecialchars($old['previous_school']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Class Applying For *</label>
                        <input type="text" name="class_applying_for"
                               class="form-control" required maxlength="100"
                               placeholder="e.g. Grade 7"
                               value="<?= htmlspecialchars($old['class_applying_for']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Academic Year *</label>
                        <input type="text" name="academic_year"
                               class="form-control" required maxlength="20"
                               placeholder="e.g. 2026/2027"
                               value="<?= htmlspecialchars($old['academic_year']) ?>">
                    </div>

                </div>

                <hr class="my-4">

                <h4 class="form-section-title">
                    <i class="bi bi-people"></i>
                    Parent or Guardian Information
                </h4>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Parent/Guardian Name *</label>
                        <input type="text" name="parent_name"
                               class="form-control" required maxlength="200"
                               value="<?= htmlspecialchars($old['parent_name']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Parent/Guardian Phone *</label>
                        <input type="tel" name="parent_phone"
                               class="form-control" required maxlength="30"
                               value="<?= htmlspecialchars($old['parent_phone']) ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Parent/Guardian Email</label>
                        <input type="email" name="parent_email"
                               class="form-control" maxlength="150"
                               value="<?= htmlspecialchars($old['parent_email']) ?>">
                    </div>

                </div>

                <div class="application-notice mt-4">
                    <i class="bi bi-info-circle-fill"></i>
                    Submitting this form does not guarantee admission.
                    Your application will be reviewed by the school administration.
                </div>

                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary btn-lg px-5">
                        Submit Application
                        <i class="bi bi-send ms-2"></i>
                    </button>
                </div>

            </form>

        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
