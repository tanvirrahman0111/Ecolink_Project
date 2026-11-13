<?php
/**
 * EcoLink - One-time admin account creator.
 * Run this once from your browser after importing sql/schema.sql
 * (e.g. http://localhost/ecolink/setup_admin.php), then DELETE this
 * file for security.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';

$db = getDB();
$existingAdmin = $db->query("SELECT COUNT(*) c FROM users WHERE role = 'admin'")->fetch();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name && $email && $phone && strlen($password) >= 8) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare(
            "INSERT INTO users (full_name, email, phone, password_hash, role, is_verified)
             VALUES (?, ?, ?, ?, 'admin', 1)"
        );
        $stmt->execute([$name, $email, $phone, $hash]);
        $message = "Admin account created! Delete this file (setup_admin.php) now, then log in at login.php.";
    } else {
        $message = "Please fill all fields; password must be at least 8 characters.";
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>EcoLink Admin Setup</title>
<link rel="stylesheet" href="assets/css/style.css"></head>
<body><main class="container">
<section class="auth-card">
<h1>Create the first admin account</h1>
<?php if ($existingAdmin['c'] > 0): ?>
    <div class="alert alert-error">An admin account already exists. For security, delete this file.</div>
<?php endif; ?>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<form method="post" class="form-grid">
    <label>Full Name<input type="text" name="full_name" required></label>
    <label>Email<input type="email" name="email" required></label>
    <label>Phone<input type="text" name="phone" required></label>
    <label>Password (min 8 chars)<input type="password" name="password" minlength="8" required></label>
    <button class="btn-primary" type="submit">Create Admin</button>
</form>
</section>
</main></body></html>
