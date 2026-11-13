<?php
/**
 * EcoLink - App configuration & shared helpers
 */

session_start();

define('APP_NAME', 'EcoLink');
define('BASE_URL', '');
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/');
define('UPLOAD_URL', BASE_URL . '/assets/uploads/');

// Rough CO2-saved-per-kg estimates by category (used for the impact dashboard).
// These are simplified academic-project figures, not scientific measurements.
define('CO2_FACTORS', [
    'Food'        => 2.5,
    'Clothes'     => 6.0,
    'Books'       => 1.0,
    'Electronics' => 20.0,
    'Furniture'   => 8.0,
]);

// Average weight-per-unit estimates (kg), used only when the donor didn't
// specify an exact weight, to keep the impact dashboard populated.
define('WEIGHT_FACTORS', [
    'Food'        => 1.0,
    'Clothes'     => 0.4,
    'Books'       => 0.3,
    'Electronics' => 2.0,
    'Furniture'   => 15.0,
]);

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void {
    header("Location: " . BASE_URL . $path);
    exit;
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function currentUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function currentUserRole(): ?string {
    return $_SESSION['role'] ?? null;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect('/login.php');
    }
}

function requireRole(string $role): void {
    requireLogin();
    if (currentUserRole() !== $role) {
        http_response_code(403);
        die("Access denied: this page is for " . e($role) . " accounts only.");
    }
}

function flash(string $key, ?string $message = null) {
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return;
    }
    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}
