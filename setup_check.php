<?php
/**
 * EcoLink - Setup & repair page.
 * Open /setup_check.php once: it checks PHP, the database, the tables/columns and the upload folder,
 * and can create/repair everything with one click.  DELETE THIS FILE when your site is working.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/config/db.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---- parse sql/schema.sql into [table => [column => definition]] and statements ------------
$schemaFile = __DIR__ . '/sql/schema.sql';
$schemaSql = is_file($schemaFile) ? file_get_contents($schemaFile) : '';
function sqlStatements(string $sql): array {
    $lines = [];
    foreach (preg_split('/\R/', $sql) as $ln) { if (preg_match('/^\s*--/', $ln)) continue; $lines[] = $ln; }
    $out = [];
    foreach (preg_split('/;\s*\n/', implode("\n", $lines) . "\n") as $st) { $st = trim($st); if ($st !== '') $out[] = $st; }
    return $out;
}
function expectedColumns(string $sql): array {
    $tables = [];
    if (!preg_match_all('/CREATE TABLE IF NOT EXISTS\s+(\w+)\s*\((.*?)\)\s*ENGINE/s', $sql, $m, PREG_SET_ORDER)) return $tables;
    foreach ($m as $t) {
        foreach (preg_split('/\R/', $t[2]) as $ln) {
            if (!preg_match('/^\s{4}(\w+)\s+(.+?),?\s*(--.*)?$/', $ln, $c)) continue;
            if (in_array(strtoupper($c[1]), ['PRIMARY', 'UNIQUE', 'INDEX', 'KEY', 'CONSTRAINT', 'FOREIGN'], true)) continue;
            $def = preg_replace('/\s*--.*$/', '', $c[2]);
            $tables[$t[1]][$c[1]] = rtrim(trim($def), ',');
        }
    }
    return $tables;
}

$log = [];
$db = null; $dbErr = null;
try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) { $dbErr = $e->getMessage(); }

$action = $_POST['action'] ?? '';
if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'install' || $action === 'repair') {
        // 1) create missing tables (never touches existing ones)
        foreach (sqlStatements($schemaSql) as $st) {
            try { $db->exec($st); } catch (Throwable $e) { $log[] = '⚠️ ' . substr($st, 0, 60) . '… → ' . $e->getMessage(); }
        }
        // 2) add missing columns to tables that already existed
        foreach (expectedColumns($schemaSql) as $tbl => $cols) {
            $have = [];
            try { foreach ($db->query("SHOW COLUMNS FROM `$tbl`")->fetchAll() as $c) $have[strtolower($c['Field'])] = true; } catch (Throwable $e) { continue; }
            foreach ($cols as $col => $def) {
                if (isset($have[strtolower($col)])) continue;
                $def2 = preg_replace('/\bPRIMARY KEY\b/i', '', $def);
                try { $db->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def2"); $log[] = "✅ Added column $tbl.$col"; }
                catch (Throwable $e) { $log[] = "⚠️ Could not add $tbl.$col: " . $e->getMessage(); }
            }
        }
        // 3) hub role + manager column (older databases)
        try {
            require_once __DIR__ . '/includes/hub.php';
            ensureHubTables($db);
        } catch (Throwable $e) { $log[] = '⚠️ Hub setup: ' . $e->getMessage(); }
        // 4) upload folder
        $up = __DIR__ . '/assets/uploads';
        if (!is_dir($up)) @mkdir($up, 0777, true);
        @chmod($up, 0777);
        $log[] = '✅ Schema checked / repaired.';
    }
    if ($action === 'sample') {
        $n = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($n > 0) { $log[] = '⚠️ Demo data is only added to an EMPTY users table (you already have ' . $n . ' users).'; }
        else {
            $sf = __DIR__ . '/sql/sample_data.sql';
            foreach (sqlStatements(is_file($sf) ? file_get_contents($sf) : '') as $st) {
                try { $db->exec($st); } catch (Throwable $e) { $log[] = '⚠️ ' . $e->getMessage(); }
            }
            $log[] = '✅ Demo data added. Log in as admin@ecolink.test / Password123 (change it afterwards!).';
        }
    }
    if ($action === 'admin') {
        $name = trim($_POST['name'] ?? ''); $mail = strtolower(trim($_POST['email'] ?? '')); $pw = $_POST['password'] ?? '';
        $adminExists = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
        if ($adminExists) { $log[] = '⚠️ An admin already exists, so this page will not create another (security). Use Admin login.'; }
        elseif ($name === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL) || strlen($pw) < 8) { $log[] = '⚠️ Enter a name, valid email and a password of 8+ characters.'; }
        else {
            $has = $db->prepare("SELECT 1 FROM users WHERE email = ?"); $has->execute([$mail]);
            if ($has->fetchColumn()) { $log[] = '⚠️ That email already exists.'; }
            else {
                $db->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, is_verified, verification_status, email_verified) VALUES (?, ?, '', ?, 'admin', 1, 'verified', 1)")
                   ->execute([$name, $mail, password_hash($pw, PASSWORD_DEFAULT)]);
                $log[] = '✅ Admin account created. Log in with the Admin tab.';
            }
        }
    }
}

// ---- checks ----------------------------------------------------------------------------------
$checks = [];
$checks[] = [version_compare(PHP_VERSION, '8.0', '>='), 'PHP version ' . PHP_VERSION, 'EcoLink needs PHP 8.0 or newer.'];
foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'json'] as $ext) $checks[] = [extension_loaded($ext), "PHP extension $ext", 'Enable it in php.ini / hosting panel.'];
$up = __DIR__ . '/assets/uploads';
$checks[] = [is_dir($up) && is_writable($up), 'assets/uploads is writable', 'Create the folder and set permission 755/777 (needed for photo upload).'];
$checks[] = [$db !== null, 'Database connection (' . DB_HOST . ' / ' . DB_NAME . ')', (string)$dbErr];

$missingTables = []; $missingCols = []; $counts = [];
if ($db) {
    $exp = expectedColumns($schemaSql);
    $existing = array_map('strtolower', $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
    foreach ($exp as $tbl => $cols) {
        if (!in_array(strtolower($tbl), $existing, true)) { $missingTables[] = $tbl; continue; }
        $have = [];
        foreach ($db->query("SHOW COLUMNS FROM `$tbl`")->fetchAll() as $c) $have[strtolower($c['Field'])] = true;
        foreach ($cols as $col => $def) if (!isset($have[strtolower($col)])) $missingCols[] = "$tbl.$col";
        $counts[$tbl] = (int)$db->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
    }
    $checks[] = [!$missingTables, 'All tables exist', 'Missing: ' . implode(', ', $missingTables)];
    $checks[] = [!$missingCols, 'All columns exist', 'Missing: ' . implode(', ', $missingCols)];
    if (!$missingTables) {
        $roleCol = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        $checks[] = [$roleCol && stripos($roleCol['Type'], "'hub'") !== false, "users.role supports 'hub' (hub staff)", 'Press "Install / repair".'];
        $adminN = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        $checks[] = [$adminN > 0, 'An admin account exists', 'Create one below.'];
        $checks[] = [(int)$db->query("SELECT COUNT(*) FROM resource_categories")->fetchColumn() > 0, 'Resource categories exist', 'Press "Install / repair".'];
    }
}
$allOk = !in_array(false, array_column($checks, 0), true);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>EcoLink Setup Check</title>
<style>
body{font-family:system-ui,Segoe UI,sans-serif;background:#f3f6f2;margin:0;color:#16221c}
.wrap{max-width:760px;margin:30px auto;padding:0 16px}
.card{background:#fff;border:1px solid #dfe7dd;border-radius:14px;padding:18px 20px;margin-bottom:16px;box-shadow:0 4px 14px rgba(20,54,31,.06)}
h1{margin:0 0 4px}h2{margin:0 0 10px;font-size:1.05rem}
.row{display:flex;gap:10px;padding:6px 0;border-top:1px solid #eef2ed}.row:first-of-type{border-top:0}
.ok{color:#1f7a3d}.bad{color:#b3261e}.hint{color:#6b7a70;font-size:.85rem}
button{background:#1f7a3d;color:#fff;border:0;border-radius:10px;padding:10px 16px;font-weight:700;cursor:pointer;margin:4px 6px 4px 0}
button.alt{background:#fff;color:#1f7a3d;border:1px solid #1f7a3d}
input{padding:9px;border:1px solid #cfd9cd;border-radius:8px;margin:3px 6px 3px 0}
.log{background:#fffdf0;border:1px solid #f0e3a0}
</style></head><body><div class="wrap">
<h1>EcoLink Setup Check</h1>
<p class="hint">Fixes most "nothing works" problems in one place. Delete <code>setup_check.php</code> when everything is green.</p>

<?php if ($log): ?><div class="card log"><?php foreach ($log as $l) echo '<div>' . h($l) . '</div>'; ?></div><?php endif; ?>

<div class="card"><h2><?= $allOk ? '✅ Everything looks good' : '🔧 Problems found' ?></h2>
<?php foreach ($checks as $c): ?>
  <div class="row"><span class="<?= $c[0] ? 'ok' : 'bad' ?>"><?= $c[0] ? '✔' : '✘' ?></span>
  <div><?= h($c[1]) ?><?php if (!$c[0] && $c[2]): ?><div class="hint"><?= h($c[2]) ?></div><?php endif; ?></div></div>
<?php endforeach; ?></div>

<?php if ($db): ?>
<div class="card"><h2>Fix it</h2>
  <form method="post" style="display:inline"><input type="hidden" name="action" value="install"><button>Install / repair database</button></form>
  <form method="post" style="display:inline"><input type="hidden" name="action" value="sample"><button class="alt">Add demo data (empty DB only)</button></form>
  <?php if (empty($adminN) && !$missingTables): ?>
  <h2 style="margin-top:16px">Create an admin account</h2>
  <form method="post"><input type="hidden" name="action" value="admin">
    <input name="name" placeholder="Full name" required><input type="email" name="email" placeholder="Email" required>
    <input type="text" name="password" placeholder="Password (8+)" minlength="8" required><button>Create admin</button></form>
  <?php endif; ?>
</div>
<?php if ($counts): ?><div class="card"><h2>Table row counts</h2><?php foreach ($counts as $t => $n) echo '<div class="row"><span style="width:200px">' . h($t) . '</span><b>' . $n . '</b></div>'; ?></div><?php endif; ?>
<?php else: ?>
<div class="card"><h2>Can't reach the database</h2>
<p><?= h($dbErr) ?></p>
<ul><li><b>On XAMPP:</b> start MySQL in the XAMPP panel, open phpMyAdmin, create an empty database named <code><?= h(DB_NAME) ?></code>, then reload this page.</li>
<li><b>On InfinityFree:</b> copy the MySQL host, database name, user and password from your control panel → MySQL Databases into <code>config/db.php</code>.</li>
<li>InfinityFree's DB host does <b>not</b> work from your own computer – use the local database there.</li></ul></div>
<?php endif; ?>
</div></body></html>
