<?php
/**
 * EcoLink - Database Connection
 * Uses PDO with MySQL. Edit the constants below to match your XAMPP setup.
 */

// ---------------------------------------------------------------------
// Works both on your computer (XAMPP) and on InfinityFree - no editing needed
// when you move between the two.
//   * Opened as http://localhost/... -> uses the local XAMPP database below.
//   * Anywhere else (your live site)  -> uses the InfinityFree database.
// You can also override everything by creating config/db.local.php with
// define() lines for DB_HOST, DB_NAME, DB_USER, DB_PASS.
// NOTE: InfinityFree's database host (sql106...) only accepts connections from
// InfinityFree's own servers, so it can NOT be used from a local XAMPP.
// ---------------------------------------------------------------------
if (is_file(__DIR__ . '/db.local.php')) require __DIR__ . '/db.local.php';

$__host = strtolower($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
$__isLocal = (PHP_SAPI === 'cli') || (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $__host);

if (!defined('DB_HOST')) define('DB_HOST', $__isLocal ? 'localhost' : 'sql106.infinityfree.com');
if (!defined('DB_NAME')) define('DB_NAME', $__isLocal ? 'ecolink_db' : 'if0_43277897_ecolink');
if (!defined('DB_USER')) define('DB_USER', $__isLocal ? 'root' : 'if0_43277897');
if (!defined('DB_PASS')) define('DB_PASS', $__isLocal ? '' : 'Rajin4321');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die("<div style='font-family:sans-serif;max-width:640px;margin:40px auto;padding:20px;border:1px solid #e0b4b4;border-radius:12px;background:#fff6f6'>"
              . "<h2 style='margin-top:0'>Database connection failed</h2>"
              . "<p>" . htmlspecialchars($e->getMessage()) . "</p>"
              . "<p>Trying: host <b>" . htmlspecialchars(DB_HOST) . "</b>, database <b>" . htmlspecialchars(DB_NAME) . "</b>, user <b>" . htmlspecialchars(DB_USER) . "</b>.</p>"
              . "<ul><li>On XAMPP: start MySQL, create a database named <b>" . htmlspecialchars(DB_NAME) . "</b>, then open <a href='/setup_check.php'>/setup_check.php</a>.</li>"
              . "<li>On InfinityFree: check the MySQL host/user/password in <b>config/db.php</b> against your control panel.</li></ul></div>");
        }
    }
    return $pdo;
}
