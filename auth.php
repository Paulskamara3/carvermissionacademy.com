<?php

require_once __DIR__ . '/../config/session.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/cma_school_management');
}

// Check whether a user is logged in.
function isLoggedIn() {

    return isset($_SESSION['user_id']);

}

// Require login.
function requireLogin() {

    if (!isLoggedIn()) {

        header("Location: " . BASE_URL . "/login.php");
        exit;

    }

}

// Require a specific user role.
function requireRole($requiredRole) {

    requireLogin();

    if (
        !isset($_SESSION['role']) ||
        $_SESSION['role'] !== $requiredRole
    ) {

        http_response_code(403);

        exit("Access denied. You are not authorized to access this page.");

    }

}

// Generate CSRF token.
function generateCsrfToken() {

    if (empty($_SESSION['csrf_token'])) {

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    }

    return $_SESSION['csrf_token'];

}

// Verify CSRF token.
function verifyCsrfToken($token) {

    return isset($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);

}
