<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'weights';

    if ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash('error', 'Category name cannot be empty.');
        } else {
            $exists = $db->prepare("SELECT category_id FROM resource_categories WHERE name = ?");
            $exists->execute([$name]);
            if ($exists->fetch()) {
                flash('error', 'That category already exists.');
            } else {
                $db->prepare("INSERT INTO resource_categories (name) VALUES (?)")->execute([$name]);
                flash('success', 'Category added with default matching weights.');
            }
        }
        redirect('/admin/manage_categories.php');
    }

    $id = (int)($_POST['category_id'] ?? 0);
    $wu = (float)($_POST['weight_urgency'] ?? 0);
    $wd = (float)($_POST['weight_distance'] ?? 0);
    $wm = (float)($_POST['weight_demand'] ?? 0);
    $wc = (float)($_POST['weight_capacity'] ?? 0);
    $sum = $wu + $wd + $wm + $wc;

    if ($sum <= 0 || $sum > 1.2) {
        flash('error', 'Weights should roughly sum to 1.0. Please adjust and try again.');
    } else {
        $db->prepare(
            "UPDATE resource_categories SET weight_urgency = ?, weight_distance = ?, weight_demand = ?, weight_capacity = ? WHERE category_id = ?"
        )->execute([$wu, $wd, $wm, $wc, $id]);
        flash('success', 'Matching weights updated for this category.');
    }
    redirect('/admin/manage_categories.php');
}

$categories = $db->query(
    "SELECT c.*, (SELECT COUNT(*) FROM donations d WHERE d.category_id = c.category_id) AS resource_count
     FROM resource_categories c ORDER BY c.name"
)->fetchAll();

$search = trim($_GET['search'] ?? '');
$statusF = $_GET['status'] ?? '';
$where = ['1=1']; $params = [];
if ($search !== '') { $where[] = "(d.title LIKE ? OR u.full_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($statusF !== '') { $where[] = "d.status = ?"; $params[] = $statusF; }
$whereSql = implode(' AND ', $where);

$resources = $db->prepare(
    "SELECT d.*, c.name AS category_name, u.full_name AS donor_name
     FROM donations d JOIN resource_categories c ON c.category_id = d.category_id
     JOIN users u ON u.user_id = d.donor_id
     WHERE $whereSql ORDER BY d.created_at DESC LIMIT 20"
);
$resources->execute($params);
$resources = $resources->fetchAll();

$stats = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM resource_categories) AS category_count,
        (SELECT COUNT(*) FROM donations WHERE status = 'available') AS active_resources,
        (SELECT COUNT(*) FROM donations WHERE status IN ('matched','accepted')) AS pending_review,
        (SELECT COUNT(*) FROM donations) AS total_records"
)->fetch();

$pageTitle = 'Resources & Categories';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Resource &amp; Category Management</h1>
<p>Manage EcoLink resource categories and review resources submitted by donors.</p>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Resource Categories</span><strong><?= (int)$stats['category_count'] ?></strong><span>supported categories</span></div>
    <div class="mini-card"><span class="eyebrow">Active Resources</span><strong><?= (int)$stats['active_resources'] ?></strong><span>available now</span></div>
    <div class="mini-card"><span class="eyebrow">In Matching</span><strong><?= (int)$stats['pending_review'] ?></strong><span>matched or accepted</span></div>
    <div class="mini-card"><span class="eyebrow">Total Resource Records</span><strong><?= (int)$stats['total_records'] ?></strong><span>submitted resources</span></div>
</section>

<div class="two-col" style="align-items:start;">
    <div class="mini-card">
        <h3 style="margin-top:0;">Resource Categories</h3>
        <p style="color:var(--ink-soft);">Categories supported in the project scope, with their matching weights.</p>
        <?php foreach ($categories as $c): ?>
            <details style="border:1px solid var(--line); border-radius:10px; padding:12px 14px; margin-bottom:10px;">
                <summary style="cursor:pointer; display:flex; justify-content:space-between; gap:10px;">
                    <strong><?= e($c['name']) ?></strong>
                    <span style="color:var(--ink-soft);"><?= (int)$c['resource_count'] ?> resources</span>
                </summary>
                <form method="post" style="margin-top:12px;">
                    <input type="hidden" name="action" value="weights">
                    <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                    <div class="two-col">
                        <label class="field"><span class="field-label">Urgency</span>
                            <input type="number" step="0.01" min="0" max="1" name="weight_urgency" value="<?= e($c['weight_urgency']) ?>"></label>
                        <label class="field"><span class="field-label">Distance</span>
                            <input type="number" step="0.01" min="0" max="1" name="weight_distance" value="<?= e($c['weight_distance']) ?>"></label>
                    </div>
                    <div class="two-col">
                        <label class="field"><span class="field-label">Demand</span>
                            <input type="number" step="0.01" min="0" max="1" name="weight_demand" value="<?= e($c['weight_demand']) ?>"></label>
                        <label class="field"><span class="field-label">Capacity</span>
                            <input type="number" step="0.01" min="0" max="1" name="weight_capacity" value="<?= e($c['weight_capacity']) ?>"></label>
                    </div>
                    <button class="btn-small btn-accent" type="submit">Save Weights</button>
                </form>
            </details>
        <?php endforeach; ?>

        <hr style="border:none; border-top:1px solid var(--line); margin:18px 0;">
        <h4 style="margin:0 0 8px;">Add a Category</h4>
        <form method="post" style="display:flex; gap:8px;">
            <input type="hidden" name="action" value="add_category">
            <input type="text" name="name" placeholder="New category name" required style="flex:1; padding:9px 12px; border:1px solid var(--line); border-radius:8px; font-family:inherit;">
            <button class="btn-accent btn-small" type="submit">Add Category</button>
        </form>
        <p style="color:var(--ink-soft); font-size:0.82rem; margin-top:12px;">Scope defined in the proposal: Food, Clothes, Books, Electronics, Furniture.</p>
    </div>

    <div class="mini-card">
        <h3 style="margin-top:0;">Resource Records</h3>
        <p style="color:var(--ink-soft);">Review submitted surplus resources.</p>
        <form method="get" style="display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
            <input type="text" name="search" placeholder="Resource title or donor..." value="<?= e($search) ?>" style="flex:1; min-width:150px; padding:9px 12px; border:1px solid var(--line); border-radius:8px; font-family:inherit;">
            <select name="status" style="padding:9px 12px; border:1px solid var(--line); border-radius:8px; font-family:inherit;">
                <option value="">All statuses</option>
                <?php foreach (['available','matched','accepted','pickup','delivered','completed'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusF === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn-accent btn-small" type="submit">Filter</button>
        </form>
        <table class="data-table" style="box-shadow:none; border:none;">
            <thead><tr><th>Resource</th><th>Category</th><th>Status</th><th>Donor</th></tr></thead>
            <tbody>
            <?php foreach ($resources as $r): ?>
                <tr>
                    <td><strong><?= e($r['title']) ?></strong><br><small><?= (int)$r['quantity'] ?> <?= e($r['unit']) ?> · <?= e(ucfirst(str_replace('_',' ',$r['condition_grade']))) ?></small></td>
                    <td><?= e($r['category_name']) ?></td>
                    <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                    <td><?= e($r['donor_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$resources): ?><tr><td colspan="4">No resource records found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="alert" style="background:var(--mint); border:1px solid var(--mint-line); color:var(--forest-2);">
    <strong>Admin responsibility</strong><br>
    Manage users and resource categories, and review resource records as part of platform administration.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
