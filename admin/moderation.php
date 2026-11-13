<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'resolve') {
        $db->prepare("UPDATE moderation_reports SET status = 'resolved', resolved_at = NOW() WHERE report_id = ?")->execute([$reportId]);
        flash('success', 'Report resolved.');
    } elseif ($action === 'reject') {
        $db->prepare("UPDATE moderation_reports SET status = 'rejected', resolved_at = NOW() WHERE report_id = ?")->execute([$reportId]);
        flash('success', 'Report rejected.');
    } elseif ($action === 'reopen') {
        $db->prepare("UPDATE moderation_reports SET status = 'open', resolved_at = NULL WHERE report_id = ?")->execute([$reportId]);
        flash('success', 'Report reopened.');
    }
    redirect('/admin/moderation.php?report_id=' . $reportId);
}

$search   = trim($_GET['search'] ?? '');
$type     = $_GET['type'] ?? '';
$priority = $_GET['priority'] ?? '';
$status   = $_GET['status'] ?? 'open';

$where = ['1=1']; $params = [];
if ($search !== '')   { $where[] = "(title LIKE ? OR detail LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($type !== '')     { $where[] = "type = ?";     $params[] = $type; }
if ($priority !== '') { $where[] = "priority = ?"; $params[] = $priority; }
if ($status !== '')   { $where[] = "status = ?";   $params[] = $status; }
$whereSql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT * FROM moderation_reports WHERE $whereSql ORDER BY FIELD(priority,'high','medium','low'), created_at DESC LIMIT 30");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$selectedId = (int)($_GET['report_id'] ?? ($rows[0]['report_id'] ?? 0));
$selected = null;
if ($selectedId) {
    $sel = $db->prepare(
        "SELECT mr.*, d.title AS donation_title, d.quantity, d.unit, d.condition_grade,
                reporter.full_name AS reporter_name, reporter.organization_name AS reporter_org
         FROM moderation_reports mr
         LEFT JOIN donations d ON d.donation_id = mr.donation_id
         LEFT JOIN users reporter ON reporter.user_id = mr.reported_by
         WHERE mr.report_id = ?"
    );
    $sel->execute([$selectedId]);
    $selected = $sel->fetch() ?: null;
}

$stats = $db->query(
    "SELECT
        SUM(status = 'open') AS open_reports,
        SUM(status = 'open' AND type = 'user') AS user_issues,
        SUM(status = 'open' AND type = 'resource') AS resource_flags,
        SUM(status IN ('resolved','rejected') AND resolved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS resolved_month
     FROM moderation_reports"
)->fetch();

$pageTitle = 'Moderation Center';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Moderation Center</h1>
<p>Review reports and flagged activity to keep EcoLink trustworthy and accountable.</p>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Open Reports</span><strong><?= (int)$stats['open_reports'] ?></strong><span>needs review</span></div>
    <div class="mini-card"><span class="eyebrow">User Issues</span><strong><?= (int)$stats['user_issues'] ?></strong><span>verification / trust</span></div>
    <div class="mini-card"><span class="eyebrow">Resource Flags</span><strong><?= (int)$stats['resource_flags'] ?></strong><span>content review</span></div>
    <div class="mini-card"><span class="eyebrow">Resolved (30 days)</span><strong><?= (int)$stats['resolved_month'] ?></strong><span>cases closed</span></div>
</section>

<h2>Moderation Queue</h2>
<form method="get" class="filter-bar">
    <label>Search<input type="text" name="search" placeholder="Search report or account..." value="<?= e($search) ?>"></label>
    <label>Type
        <select name="type">
            <option value="">All types</option>
            <?php foreach (['resource','user','verification'] as $t): ?>
                <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Priority
        <select name="priority">
            <option value="">All priorities</option>
            <?php foreach (['high','medium','low'] as $p): ?>
                <option value="<?= $p ?>" <?= $priority === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Status
        <select name="status">
            <option value="">All</option>
            <?php foreach (['open','resolved','rejected'] as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn-accent" type="submit">Apply Filters</button>
</form>

<div class="mod-split">
    <div class="mini-card">
        <div class="mod-row" style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em; color:#8a988f; font-weight:700;">
            <span>Report / Issue</span><span>Type</span><span>Priority</span><span>Status</span><span>Time</span>
        </div>
        <?php foreach ($rows as $r): ?>
            <a class="mod-row" href="?<?= http_build_query(array_merge($_GET, ['report_id' => $r['report_id']])) ?>" style="color:inherit;">
                <span><strong><?= e($r['title']) ?></strong><small><?= e($r['detail'] ?? '') ?></small></span>
                <span><?= e(ucfirst($r['type'])) ?></span>
                <span><span class="badge badge-<?= e($r['priority']) ?>"><?= e(ucfirst($r['priority'])) ?></span></span>
                <span><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></span>
                <span style="color:var(--ink-soft);"><?= e(date('M d', strtotime($r['created_at']))) ?></span>
            </a>
        <?php endforeach; ?>
        <?php if (!$rows): ?><p style="color:var(--ink-soft); margin:14px 0 0;">No reports match these filters. A clean queue is a good sign.</p><?php endif; ?>

        <div class="alert" style="background:var(--mint); border:1px solid var(--mint-line); color:var(--forest-2); margin-top:18px;">
            <strong>Moderation principle</strong><br>
            Review reported content and account issues before they affect trust, matching, or redistribution.
        </div>
    </div>

    <div class="mini-card">
        <h3 style="margin-top:0;">Selected Report</h3>
        <?php if (!$selected): ?>
            <p style="color:var(--ink-soft);">Select a report from the queue to review its details.</p>
        <?php else: ?>
            <p style="color:var(--ink-soft);"><?= e($selected['title']) ?></p>
            <?php if ($selected['donation_title']): ?>
                <p><span class="eyebrow">Reported Item</span><br>
                   <strong><?= e($selected['donation_title']) ?></strong><br>
                   <small style="color:var(--ink-soft);"><?= (int)$selected['quantity'] ?> <?= e($selected['unit']) ?> · <?= e(ucfirst(str_replace('_',' ',$selected['condition_grade']))) ?> condition</small>
                </p>
            <?php endif; ?>
            <?php if ($selected['reporter_name']): ?>
                <p><span class="eyebrow">Reported By</span><br><strong><?= e($selected['reporter_org'] ?: $selected['reporter_name']) ?></strong></p>
            <?php endif; ?>
            <?php if ($selected['reason']): ?>
                <p><span class="eyebrow">Reason</span><br><?= e($selected['reason']) ?></p>
            <?php endif; ?>
            <p><span class="eyebrow">Status</span><br><span class="badge badge-<?= e($selected['status']) ?>"><?= e(ucfirst($selected['status'])) ?></span></p>

            <form method="post" style="display:flex; flex-direction:column; gap:8px; margin-top:16px;">
                <input type="hidden" name="report_id" value="<?= (int)$selected['report_id'] ?>">
                <?php if ($selected['status'] === 'open'): ?>
                    <button class="btn-accent" name="action" value="resolve" type="submit">Review &amp; Resolve</button>
                    <button class="btn-secondary" name="action" value="reject" type="submit">Reject Report</button>
                <?php else: ?>
                    <button class="btn-secondary" name="action" value="reopen" type="submit">Reopen Report</button>
                <?php endif; ?>
            </form>

            <p style="margin-top:16px;"><strong>Admin actions</strong></p>
            <ul style="color:var(--ink-soft); padding-left:18px; margin:6px 0 0;">
                <li>Review account / resource details</li>
                <li>Resolve or reject the report</li>
                <li>Preserve a moderation record</li>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="alert" style="background:var(--mint); border:1px solid var(--mint-line); color:var(--forest-2); margin-top:20px;">
    <strong>Trust &amp; accountability</strong><br>
    Moderation supports the proposal's goal of improving trust, transparency and accountability.
    Physical delivery and last-mile logistics remain outside the first-release system scope.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
