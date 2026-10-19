<?php
require_once __DIR__ . '/config/database.php';

// Default school information
$school = [
    'school_name' => 'Carver Mission Academy',
    'school_motto' => 'Knowledge, Discipline and Excellence',
    'school_email' => 'info@cma.edu',
    'school_phone' => '',
    'school_address' => 'Robertsfield Highway, Mills Center, Paynesville, Liberia.'
];

// Load school settings
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM school_settings");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (array_key_exists($row['setting_key'], $school)) {
            $school[$row['setting_key']] = $row['setting_value'] ?? '';
        }
    }
} catch (PDOException $e) {
    error_log("About page settings error: " . $e->getMessage());
}

$pageTitle = 'About Us | ' . $school['school_name'];
$pageDescription = 'Learn about ' . $school['school_name'] . ', our mission, vision, values and commitment to quality education.';

include __DIR__ . '/includes/header.php';
?>

<!-- ABOUT HERO -->
<section class="about-hero">
    <div class="container">
        <span class="about-hero-label">GET TO KNOW US</span>

        <h1>About Carver Mission Academy</h1>

        <p>
            Discover our commitment to quality education,
            discipline, character development and academic excellence.
        </p>

        <div class="about-breadcrumb">
            <a href="index.php">Home</a>
            <i class="bi bi-chevron-right"></i>
            <span>About Us</span>
        </div>
    </div>
</section>


<!-- SCHOOL INTRODUCTION -->
<section class="about-intro section-padding">
    <div class="container">
        <div class="row align-items-center g-5">

            <div class="col-lg-6">
                <div class="about-main-visual">
                    <div class="about-visual-content">
                        <i class="bi bi-mortarboard-fill"></i>
                        <h3><?= htmlspecialchars($school['school_name']) ?></h3>
                        <p><?= htmlspecialchars($school['school_motto']) ?></p>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <span class="section-label">WHO WE ARE</span>

                <h2>A School Committed to Learning and Excellence</h2>

                <p>
                    <?= htmlspecialchars($school['school_name']) ?>
                    is an educational institution located in
                    Paynesville City, Montserrado County, Liberia.
                    The academy is committed to providing students
                    with educational opportunities that support
                    academic growth, character development and
                    preparation for the future.
                </p>

                <p>
                    We believe education is essential to personal
                    success and national development. Through
                    teaching, learning and guidance, we seek to help
                    students develop the knowledge, skills, discipline
                    and confidence needed to become responsible
                    members of society.
                </p>

                <p>
                    Our goal is to create a supportive learning
                    environment where students are encouraged
                    to discover their abilities, develop their talents
                    and prepare for future opportunities.
                </p>
            </div>

        </div>
    </div>
</section>


<!-- MISSION AND VISION -->
<section class="mission-vision-section section-padding">
    <div class="container">

        <div class="section-heading text-center">
            <span>OUR PURPOSE</span>
            <h2>Our Mission and Vision</h2>
            <p>
                Our educational direction is guided by our commitment
                to learning, personal development and responsible citizenship.
            </p>
        </div>

        <div class="row g-4 mt-4">

            <div class="col-md-6">
                <div class="purpose-card">
                    <div class="purpose-icon">
                        <i class="bi bi-bullseye"></i>
                    </div>

                    <h3>Our Mission</h3>

                    <p>
                        To provide quality educational opportunities
                        in a supportive environment that promotes
                        academic achievement, discipline, good character
                        and the development of responsible individuals.
                    </p>
                </div>
            </div>

            <div class="col-md-6">
                <div class="purpose-card">
                    <div class="purpose-icon">
                        <i class="bi bi-eye"></i>
                    </div>

                    <h3>Our Vision</h3>

                    <p>
                        To nurture knowledgeable, confident and
                        responsible individuals who are prepared
                        to contribute positively to their communities
                        and the development of society.
                    </p>
                </div>
            </div>

        </div>
    </div>
</section>


<!-- CORE VALUES -->
<section class="core-values-section section-padding">
    <div class="container">

        <div class="section-heading text-center">
            <span>WHAT WE BELIEVE IN</span>
            <h2>Our Core Values</h2>

            <p>
                The principles that guide our learning environment
                and shape the character of our students.
            </p>
        </div>

        <div class="row g-4 mt-4">

            <div class="col-md-4">
                <div class="value-card">
                    <i class="bi bi-book-half"></i>
                    <h4>Knowledge</h4>
                    <p>
                        Encouraging continuous learning,
                        intellectual growth and academic achievement.
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="value-card">
                    <i class="bi bi-shield-check"></i>
                    <h4>Discipline</h4>
                    <p>
                        Promoting responsibility, self-control,
                        respect and positive behaviour.
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="value-card">
                    <i class="bi bi-award"></i>
                    <h4>Excellence</h4>
                    <p>
                        Encouraging students and staff to pursue
                        high standards in their work and conduct.
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="value-card">
                    <i class="bi bi-heart"></i>
                    <h4>Respect</h4>
                    <p>
                        Building an environment where everyone
                        is treated with dignity and fairness.
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="value-card">
                    <i class="bi bi-people"></i>
                    <h4>Teamwork</h4>
                    <p>
                        Promoting cooperation, collaboration
                        and positive relationships.
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="value-card">
                    <i class="bi bi-lightbulb"></i>
                    <h4>Creativity</h4>
                    <p>
                        Inspiring students to think critically,
                        explore ideas and develop solutions.
                    </p>
                </div>
            </div>

        </div>
    </div>
</section>


<!-- EDUCATIONAL COMMITMENT -->
<section class="educational-commitment section-padding">
    <div class="container">

        <div class="row align-items-center g-5">

            <div class="col-lg-6">
                <span class="section-label">OUR COMMITMENT</span>

                <h2>Preparing Students for a Brighter Future</h2>

                <p>
                    At Carver Mission Academy, we recognize that
                    education goes beyond classroom instruction.
                    We encourage students to develop confidence,
                    communication skills, critical thinking,
                    teamwork and a sense of responsibility.
                </p>

                <p>
                    We aim to provide an environment where students
                    can grow academically, socially and personally
                    while preparing for future educational and
                    professional opportunities.
                </p>

                <a href="admissions.php" class="btn btn-primary mt-3">
                    Join Our School
                    <i class="bi bi-arrow-right"></i>
                </a>
            </div>

            <div class="col-lg-6">
                <div class="commitment-card">

                    <div class="commitment-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>Quality teaching and learning</span>
                    </div>

                    <div class="commitment-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>Academic growth and achievement</span>
                    </div>

                    <div class="commitment-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>Good character and discipline</span>
                    </div>

                    <div class="commitment-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>Student confidence and development</span>
                    </div>

                    <div class="commitment-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>Responsible citizenship and leadership</span>
                    </div>

                </div>
            </div>

        </div>
    </div>
</section>


<!-- CONTACT CTA -->
<section class="about-cta">
    <div class="container text-center">

        <span>YOUR JOURNEY STARTS HERE</span>

        <h2>Become Part of the CMA Community</h2>

        <p>
            We welcome families and students who value education,
            discipline and personal development.
        </p>

        <a href="admissions.php" class="btn btn-light btn-lg">
            Apply for Admission
            <i class="bi bi-arrow-right"></i>
        </a>

    </div>
</section>


<?php include __DIR__ . '/includes/footer.php'; ?>
