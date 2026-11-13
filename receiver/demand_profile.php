<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('receiver');

$db = getDB();
$uid = currentUserId();
$categories = $db->query("SELECT * FROM resource_categories ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cats = isset($_POST['categories']) && is_array($_POST['categories'])
        ? implode(',', array_map('strval', $_POST['categories'])) : null;
    $qty       = trim($_POST['typical_quantity'] ?? '');
    $cond      = $_POST['preferred_condition'] ?? 'any';
    $area      = trim($_POST['pickup_area'] ?? '');
    $recurring = trim($_POST['recurring_needs'] ?? '');
    $desc      = trim($_POST['need_description'] ?? '');
    if (!in_array($cond, ['any','new','like_new','good','fair'], true)) $cond = 'any';

    $db->prepare(
        "UPDATE users SET preferred_categories = ?, typical_quantity = ?, preferred_condition = ?,
                pickup_area = ?, recurring_needs = ?, need_description = ? WHERE user_id = ?"
    )->execute([$cats, $qty ?: null, $cond, $area ?: null, $recurring ?: null, $desc ?: null, $uid]);

    // Mirror the selected categories into demand_profiles so the automatic
    // matching engine (PriorityMatcher) keeps working off the same choices.
    $selected = $cats ? explode(',', $cats) : [];
    $db->prepare("UPDATE demand_profiles SET is_active = 0 WHERE receiver_id = ?")->execute([$uid]);
    foreach ($categories as $cat) {
        if (in_array($cat['name'], $selected, true)) {
            $db->prepare(
                "INSERT INTO demand_profiles (receiver_id, category_id, needed_quantity, priority_level, is_active)
                 VALUES (?, ?, 1, 'medium', 1)
                 ON DUPLICATE KEY UPDATE is_active = 1"
            )->execute([$uid, $cat['category_id']]);
        }
    }

    flash('success', 'Demand profile saved. EcoLink will use this to improve your matches.');
    redirect('/receiver/demand_profile.php');
}

$user = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$user->execute([$uid]);
$user = $user->fetch();
$selectedCats = $user['preferred_categories'] ? explode(',', $user['preferred_categories']) : [];

// Top needs summary comes from the receiver's active individual requests.
$topNeeds = $db->prepare(
    "SELECT r.title, r.quantity_min, r.quantity_max, c.name AS category_name
     FROM requests r JOIN resource_categories c ON c.category_id = r.category_id
     WHERE r.receiver_id = ? AND r.status = 'searching'
     ORDER BY r.created_at DESC LIMIT 3"
);
$topNeeds->execute([$uid]);
$topNeeds = $topNeeds->fetchAll();

$pageTitle = 'Demand Profile';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Demand Profile</h1>
<p>Maintain your organization's needs so EcoLink can improve donor–receiver matching.</p>

<div class="profile-banner">
    <div>
        <div class="eyebrow">Why this matters</div>
        <h2 style="font-size:1.15rem;">A detailed demand profile helps EcoLink recommend resources that better fit your needs.</h2>
        <p>Keep quantities, conditions, locations and priority needs up to date.</p>
    </div>
</div>

<div class="two-col" style="align-items:start;">
    <form method="post" class="mini-card">
        <h3 style="margin-top:0;">Organization Needs</h3>
        <p style="color:var(--ink-soft);">These preferences are used by the matching engine when suitable donor resources are available.</p>

        <span class="field-label">Preferred Categories</span>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px;">
            <?php foreach ($categories as $c): ?>
                <label class="checkbox-label" style="background:var(--bg); border:1px solid var(--line); padding:8px 14px; border-radius:999px; font-size:0.88rem;">
                    <input type="checkbox" name="categories[]" value="<?= e($c['name']) ?>" <?= in_array($c['name'], $selectedCats, true) ? 'checked' : '' ?>>
                    <?= e($c['name']) ?>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="two-col">
            <label class="field"><span class="field-label">Typical Quantity Needed</span>
                <input type="text" name="typical_quantity" value="<?= e($user['typical_quantity'] ?? '') ?>" placeholder="e.g. 10–15 units">
            </label>
            <label class="field"><span class="field-label">Preferred Condition</span>
                <select name="preferred_condition">
                    <?php foreach (['any'=>'Any usable condition','good'=>'Good / usable','like_new'=>'Like New or better','new'=>'New only'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $user['preferred_condition'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <label class="field"><span class="field-label">Preferred Location / Pickup Area</span>
            <input type="text" name="pickup_area" value="<?= e($user['pickup_area'] ?? '') ?>" placeholder="e.g. Uttara, Dhaka">
        </label>
        <label class="field"><span class="field-label">Recurring Needs</span>
            <input type="text" name="recurring_needs" value="<?= e($user['recurring_needs'] ?? '') ?>" placeholder="e.g. Learning materials and furniture">
        </label>
        <label class="field"><span class="field-label">Need Description</span>
            <textarea name="need_description" rows="4" placeholder="Describe the types of resources your organization commonly needs and how they are used by your beneficiaries."><?= e($user['need_description'] ?? '') ?></textarea>
        </label>

        <button class="btn-accent" type="submit">Save Demand Profile</button>
    </form>

    <div>
        <div class="mini-card" style="margin-bottom:16px;">
            <h3 style="margin-top:0;">Matching Preferences</h3>
            <p style="color:var(--ink-soft);">Information used to improve suitable recommendations.</p>
            <ol style="padding-left:18px; color:var(--ink-soft);">
                <li><strong style="color:var(--ink);">Category fit</strong><br>Focuses on the resources you actually need.</li>
                <li><strong style="color:var(--ink);">Quantity fit</strong><br>Helps compare donor quantity with demand.</li>
                <li><strong style="color:var(--ink);">Condition fit</strong><br>Avoids recommendations outside your needs.</li>
                <li><strong style="color:var(--ink);">Location fit</strong><br>Supports nearby resource recommendations.</li>
            </ol>
        </div>

        <div class="mini-card" style="background:var(--mint); border-color:var(--mint-line);">
            <h3 style="margin-top:0;">Current Demand Summary</h3>
            <span class="eyebrow">Top Needs</span>
            <?php foreach ($topNeeds as $n): ?>
                <div style="background:#fff; border-radius:10px; padding:12px 14px; margin-top:10px; display:flex; justify-content:space-between; gap:10px;">
                    <strong><?= e($n['title']) ?></strong>
                    <span style="color:var(--ink-soft); white-space:nowrap;"><?= (int)$n['quantity_min'] ?>–<?= (int)$n['quantity_max'] ?> units</span>
                </div>
            <?php endforeach; ?>
            <?php if (!$topNeeds): ?>
                <p style="margin:10px 0 0; color:var(--ink-soft);">No active requests yet. <a href="<?= BASE_URL ?>/receiver/post_request.php">Post a request</a> to see it here.</p>
            <?php endif; ?>
            <p style="color:var(--ink-soft); font-size:0.82rem; margin:14px 0 0;">Keep this profile updated to improve future matches.</p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
