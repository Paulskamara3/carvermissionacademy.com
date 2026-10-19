<?php
require_once __DIR__ . '/config/database.php';

$pageTitle = 'School Media Gallery';

$stmt = $pdo->prepare("
    SELECT *
    FROM school_media
    WHERE status = 'published'
    ORDER BY uploaded_at DESC
");

$stmt->execute();
$mediaItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

function mediaEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

include __DIR__ . '/includes/header.php';
?>

<!-- MEDIA HERO -->
<section class="media-hero">
    <div class="container text-center">
        <span class="media-label">CARVER MISSION ACADEMY</span>
        <h1>Our School Media Gallery</h1>
        <p>
            Discover memorable moments, classroom activities,
            celebrations and achievements at CMA.
        </p>
    </div>
</section>

<!-- MEDIA GALLERY -->
<section class="public-media-section py-5">
    <div class="container">

        <div class="section-heading text-center mb-5">
            <span>EXPLORE OUR SCHOOL</span>
            <h2>Memories and Moments at CMA</h2>
            <p>
                Take a look at life, learning and activities
                within our school community.
            </p>
        </div>

        <div class="row g-4">

            <?php if (!empty($mediaItems)): ?>

                <?php foreach ($mediaItems as $media): ?>

                    <?php
                    $fileName = basename($media['file_path']);
                    $fileUrl = 'uploads/media/' . rawurlencode($fileName);
                    ?>

                    <div class="col-md-6 col-lg-4">

                        <div class="public-media-card">

                            <?php if ($media['media_type'] === 'image'): ?>

                                <a href="<?= mediaEscape($fileUrl) ?>"
                                   target="_blank"
                                   rel="noopener">

                                    <img
                                        src="<?= mediaEscape($fileUrl) ?>"
                                        alt="<?= mediaEscape($media['title']) ?>"
                                        class="public-media-image"
                                        loading="lazy"
                                    >

                                </a>

                            <?php else: ?>

                                <video
                                    class="public-media-video"
                                    controls
                                    preload="metadata"
                                >
                                    <source src="<?= mediaEscape($fileUrl) ?>">
                                    Your browser does not support video playback.
                                </video>

                            <?php endif; ?>

                            <div class="public-media-details">

                                <span class="public-media-category">
                                    <?= mediaEscape($media['category']) ?>
                                </span>

                                <h4>
                                    <?= mediaEscape($media['title']) ?>
                                </h4>

                                <?php if (!empty($media['description'])): ?>
                                    <p>
                                        <?= nl2br(mediaEscape($media['description'])) ?>
                                    </p>
                                <?php endif; ?>

                                <small>
                                    <i class="bi bi-calendar3"></i>
                                    <?= date('F j, Y', strtotime($media['uploaded_at'])) ?>
                                </small>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="col-12 text-center py-5">

                    <i class="bi bi-images display-1 text-muted"></i>

                    <h4 class="mt-3">No Media Available Yet</h4>

                    <p class="text-muted">
                        Our school photographs and videos will be
                        displayed here as they become available.
                    </p>

                </div>

            <?php endif; ?>

        </div>

    </div>
</section>

<!-- CALL TO ACTION -->
<section class="media-cta py-5 text-center">
    <div class="container">

        <h2>Be Part of the CMA Community</h2>

        <p>
            Join Carver Mission Academy and become part
            of our learning community.
        </p>

        <a href="admissions.php" class="btn btn-light btn-lg">
            Apply for Admission
        </a>

    </div>
</section>

<style>
.media-hero {
    background: linear-gradient(135deg, #102b50, #174f83);
    color: white;
    padding: 85px 20px;
}

.media-label {
    color: #f5c542;
    font-weight: 600;
    letter-spacing: 2px;
    font-size: 13px;
}

.media-hero h1 {
    font-size: clamp(32px, 5vw, 48px);
    font-weight: 700;
    margin: 15px 0;
}

.media-hero p {
    max-width: 650px;
    margin: auto;
    line-height: 1.8;
}

.public-media-section {
    background: #f8fafc;
}

.public-media-card {
    height: 100%;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 5px 20px rgba(0,0,0,.07);
    transition: transform .3s ease;
}

.public-media-card:hover {
    transform: translateY(-5px);
}

.public-media-image,
.public-media-video {
    width: 100%;
    height: 250px;
    object-fit: cover;
    display: block;
    background: #e5e7eb;
}

.public-media-details {
    padding: 20px;
}

.public-media-category {
    display: inline-block;
    background: #e8f0fe;
    color: #174f83;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    margin-bottom: 12px;
}

.public-media-details h4 {
    font-size: 18px;
    font-weight: 600;
    color: #17324f;
}

.public-media-details p {
    color: #64748b;
    font-size: 14px;
    line-height: 1.7;
}

.public-media-details small {
    color: #64748b;
}

.media-cta {
    background: #174f83;
    color: white;
}

.media-cta h2 {
    font-weight: 700;
}

.media-cta p {
    margin: 15px 0 25px;
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
