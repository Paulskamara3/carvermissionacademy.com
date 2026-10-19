<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?error=invalid_request');
    exit;
}

// Verify CSRF token
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    header('Location: index.php?error=invalid_token');
    exit;
}

// Validate examination ID
$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header('Location: index.php?error=invalid_id');
    exit;
}

try {
    // Check whether examination exists
    $stmt = $pdo->prepare("SELECT id FROM exams WHERE id = ?");
    $stmt->execute([$id]);

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        header('Location: index.php?error=not_found');
        exit;
    }

    // Check whether examination has recorded results
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM results
        WHERE exam_id = ?
    ");
    $stmt->execute([$id]);

    if ((int)$stmt->fetchColumn() > 0) {
        header('Location: index.php?error=has_results');
        exit;
    }

    // Delete examination
    $stmt = $pdo->prepare("DELETE FROM exams WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        header('Location: index.php?success=deleted');
        exit;
    }

    header('Location: index.php?error=delete_failed');
    exit;

} catch (PDOException $e) {
    header('Location: index.php?error=delete_failed');
    exit;
}
