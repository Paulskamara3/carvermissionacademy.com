<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$schoolName = 'Carver Mission Academy';
$schoolEmail = 'info@cma.edu';
$schoolPhone = 'To be updated';
$schoolAddress = 'Robertsfield Highway, Mills Center, Paynesville, Liberia';

try {
    $stmt = $pdo->query("
        SELECT setting_key, setting_value
        FROM school_settings
        WHERE setting_key IN (
            'school_name',
            'school_email',
            'school_phone',
            'school_address'
        )
    ");

    while ($setting = $stmt->fetch(PDO::FETCH_ASSOC)) {
        switch ($setting['setting_key']) {
            case 'school_name':
                $schoolName = $setting['setting_value'] ?: $schoolName;
                break;
            case 'school_email':
                $schoolEmail = $setting['setting_value'] ?: $schoolEmail;
                break;
            case 'school_phone':
                $schoolPhone = $setting['setting_value'] ?: $schoolPhone;
                break;
            case 'school_address':
                $schoolAddress = $setting['setting_value'] ?: $schoolAddress;
                break;
        }
    }
} catch (PDOException $e) {
    error_log('Contact page settings error: ' . $e->getMessage());
}

$pageTitle = 'Contact Us';
$pageDescription = 'Contact Carver Mission Academy for enquiries and assistance.';

$successMessage = '';
$errorMessage = '';

$old = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'subject' => '',
    'message' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $key => $value) {
        $old[$key] = trim($_POST[$key] ?? '');
    }

    try {
        verifyCsrfToken($_POST['csrf_token'] ?? '');

        if (
            $old['full_name'] === '' ||
            $old['email'] === '' ||
            $old['subject'] === '' ||
            $old['message'] === ''
        ) {
            throw new RuntimeException('Please complete all required fields.');
        }

        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        if (
            strlen($old['full_name']) > 150 ||
            strlen($old['email']) > 150 ||
            strlen($old['subject']) > 200 ||
            strlen($old['phone']) > 30
        ) {
            throw new RuntimeException('One or more fields exceed the allowed length.');
        }

        if (strlen($old['message']) > 10000) {
            throw new RuntimeException('Your message is too long. Please shorten it.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO contact_messages
                (full_name, email, phone, subject, message)
            VALUES
                (:full_name, :email, :phone, :subject, :message)
        ");

        $stmt->execute([
            ':full_name' => $old['full_name'],
            ':email' => $old['email'],
            ':phone' => $old['phone'] !== '' ? $old['phone'] : null,
            ':subject' => $old['subject'],
            ':message' => $old['message']
        ]);

        $successMessage = 'Thank you for contacting us. Your message has been submitted successfully.';

        foreach ($old as $key => $value) {
            $old[$key] = '';
        }

    } catch (RuntimeException $e) {
        $errorMessage = $e->getMessage();

    } catch (Throwable $e) {
        error_log('Contact form error: ' . $e->getMessage());
        $errorMessage = 'Sorry, we could not submit your message. Please try again.';
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Contact Hero -->
<section class="contact-hero">
    <div class="container text-center">
        <span class="contact-label">GET IN TOUCH</span>
        <h1>Contact Us</h1>
        <p>
            We would love to hear from you.
            Contact Carver Mission Academy for enquiries,
            assistance, and general information.
        </p>
    </div>
</section>

<!-- Contact Information and Form -->
<section class="contact-section section-padding">
    <div class="container">
        <div class="row g-5">

            <!-- Contact Information -->
            <div class="col-lg-5">
                <div class="contact-information">
                    <h2>Get In Touch</h2>

                    <p class="contact-intro">
                        Have a question about admissions, academics,
                        or school activities? Send us a message or
                        reach us using the contact details below.
                    </p>

                    <div class="contact-info-item">
                        <div class="contact-icon">
                            <i class="bi bi-geo-alt-fill"></i>
                        </div>
                        <div>
                            <h5>Our Location</h5>
                            <p><?= htmlspecialchars($schoolAddress) ?></p>
                        </div>
                    </div>

                    <div class="contact-info-item">
                        <div class="contact-icon">
                            <i class="bi bi-envelope-fill"></i>
                        </div>
                        <div>
                            <h5>Email Address</h5>
                            <p>
                                <a href="mailto:<?= htmlspecialchars($schoolEmail) ?>">
                                    <?= htmlspecialchars($schoolEmail) ?>
                                </a>
                            </p>
                        </div>
                    </div>

                    <div class="contact-info-item">
                        <div class="contact-icon">
                            <i class="bi bi-telephone-fill"></i>
                        </div>
                        <div>
                            <h5>Phone Number</h5>
                            <p><?= htmlspecialchars($schoolPhone) ?></p>
                        </div>
                    </div>

                    <div class="contact-note">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>
                            Our team will review your enquiry and
                            respond using the email address you provide.
                        </span>
                    </div>
                </div>
            </div>

            <!-- Contact Form -->
            <div class="col-lg-7">
                <div class="contact-form-card">
                    <h2>Send Us a Message</h2>
                    <p class="form-description">
                        Complete the form below and submit your enquiry.
                    </p>

                    <?php if ($successMessage): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?= htmlspecialchars($successMessage) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-circle-fill me-2"></i>
                            <?= htmlspecialchars($errorMessage) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="contact.php">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(generateCsrfToken()) ?>"
                        >

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label for="full_name" class="form-label">
                                    Full Name *
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="full_name"
                                    name="full_name"
                                    maxlength="150"
                                    value="<?= htmlspecialchars($old['full_name']) ?>"
                                    placeholder="Enter your full name"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label for="email" class="form-label">
                                    Email Address *
                                </label>
                                <input
                                    type="email"
                                    class="form-control"
                                    id="email"
                                    name="email"
                                    maxlength="150"
                                    value="<?= htmlspecialchars($old['email']) ?>"
                                    placeholder="Enter your email"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label for="phone" class="form-label">
                                    Phone Number
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="phone"
                                    name="phone"
                                    maxlength="30"
                                    value="<?= htmlspecialchars($old['phone']) ?>"
                                    placeholder="Enter your phone number"
                                >
                            </div>

                            <div class="col-md-6">
                                <label for="subject" class="form-label">
                                    Subject *
                                </label>
                                <select
                                    class="form-select"
                                    id="subject"
                                    name="subject"
                                    required
                                >
                                    <option value="">Select a subject</option>
                                    <?php
                                    $subjects = [
                                        'General Enquiry',
                                        'Admissions',
                                        'Academic Information',
                                        'School Fees',
                                        'Student Affairs',
                                        'Other'
                                    ];
                                    foreach ($subjects as $subject):
                                    ?>
                                        <option
                                            value="<?= htmlspecialchars($subject) ?>"
                                            <?= $old['subject'] === $subject ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($subject) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label for="message" class="form-label">
                                    Your Message *
                                </label>
                                <textarea
                                    class="form-control"
                                    id="message"
                                    name="message"
                                    rows="6"
                                    maxlength="10000"
                                    placeholder="Write your message here..."
                                    required
                                ><?= htmlspecialchars($old['message']) ?></textarea>
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn contact-submit-btn">
                                    Send Message
                                    <i class="bi bi-send-fill ms-2"></i>
                                </button>
                            </div>

                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
