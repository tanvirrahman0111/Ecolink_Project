<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

$db = getDB();
$uid = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all'])) {
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$uid]);
    flash('success', 'All notifications marked as read.');
    redirect('/notifications.php');
}

$typeFilter   = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$where = ['user_id = ?'];
$params = [$uid];
if ($typeFilter !== '')   { $where[] = 'type = ?';    $params[] = $typeFilter; }
if ($statusFilter === 'unread') { $where[] = 'is_read = 0'; }
if ($statusFilter === 'read')   { $where[] = 'is_read = 1'; }
$whereSql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT * FROM notifications WHERE $whereSql ORDER BY created_at DESC LIMIT 50");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = $db->prepare(
    "SELECT
        SUM(is_read = 0) AS unread,
        SUM(type = 'matching' AND is_read = 0) AS matching,
        SUM(type = 'lifecycle' AND is_read = 0) AS lifecycle,
        SUM(type = 'reminder' AND is_read = 0) AS reminders
     FROM notifications WHERE user_id = ?"
);
$counts->execute([$uid]);
$counts = $counts->fetch();

$typeIcons = ['matching' => '✦', 'lifecycle' => '✓', 'reminder' => '⏰', 'request' => '✓', 'system' => '●'];

/** Human-friendly relative time, e.g. "8 min ago". */
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    if ($diff < 172800) return 'Yesterday';
    return date('M d, Y', strtotime($datetime));
}

$pageTitle = 'Notifications';
require_once __DIR__ . '/includes/header.php';
?>
<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
    <div>
        <h1>Notifications</h1>
        <p>Stay updated about matching, donation status, requests and pickup reminders.</p>
    </div>
    <form method="post"><button class="btn-secondary" name="mark_all" value="1" type="submit">Mark all as read</button></form>
</div>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Unread</span><strong><?= (int)$counts['unread'] ?></strong><span>notifications</span></div>
    <div class="mini-card"><span class="eyebrow">Matching</span><strong><?= (int)$counts['matching'] ?></strong><span>new updates</span></div>
    <div class="mini-card"><span class="eyebrow">Lifecycle</span><strong><?= (int)$counts['lifecycle'] ?></strong><span>status updates</span></div>
    <div class="mini-card"><span class="eyebrow">Reminders</span><strong><?= (int)$counts['reminders'] ?></strong><span>pickup reminders</span></div>
</section>

<h2>All Notifications</h2>
<form method="get" class="filter-bar">
    <label>Type
        <select name="type">
            <option value="">All notifications</option>
            <?php foreach (['matching','lifecycle','reminder','request','system'] as $t): ?>
                <option value="<?= $t ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Status
        <select name="status">
            <option value="">All</option>
            <option value="unread" <?= $statusFilter === 'unread' ? 'selected' : '' ?>>Unread</option>
            <option value="read" <?= $statusFilter === 'read' ? 'selected' : '' ?>>Read</option>
        </select>
    </label>
    <button class="btn-accent" type="submit">Apply Filters</button>
</form>

<div class="mini-card">
    <?php foreach ($rows as $n): ?>
        <div class="notif-row">
            <span class="n-icon"><?= $typeIcons[$n['type']] ?? '●' ?></span>
            <div class="n-body">
                <span class="tag-pill tag-<?= e($n['type']) ?>"><?= e($n['type']) ?></span>
                <strong><?= e($n['title']) ?></strong>
                <p><?= e($n['message']) ?></p>
            </div>
            <span class="n-time"><?= e(timeAgo($n['created_at'])) ?></span>
            <?php if (!$n['is_read']): ?><span class="n-dot"></span><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <p style="color:var(--ink-soft); margin:0;">No notifications match these filters.</p>
    <?php else: ?>
        <p style="color:var(--ink-soft); font-size:0.85rem; margin:14px 0 0;">Showing latest <?= count($rows) ?> notification(s)</p>
    <?php endif; ?>
</div>

<div class="alert" style="background:var(--mint); border:1px solid var(--mint-line); color:var(--forest-2);">
    <strong>Notification purpose</strong><br>
    EcoLink notifications keep donors and receivers informed about matching, lifecycle updates, and pickup reminders.
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
