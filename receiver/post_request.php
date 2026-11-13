<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('receiver');

$db = getDB();
$uid = currentUserId();
$categories = $db->query("SELECT * FROM resource_categories ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $title      = trim($_POST['title'] ?? '');
    $qtyMin     = max(1, (int)($_POST['quantity_min'] ?? 1));
    $qtyMax     = max($qtyMin, (int)($_POST['quantity_max'] ?? $qtyMin));
    $condition  = $_POST['condition_preference'] ?? 'any';
    $priority   = $_POST['priority'] ?? 'normal';
    $neededBy   = trim($_POST['needed_by'] ?? '');
    $location   = trim($_POST['pickup_location'] ?? '');
    $lat        = $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
    $lng        = $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
    $reason     = trim($_POST['reason'] ?? '');
    $notes      = trim($_POST['additional_notes'] ?? '');

    if ($categoryId <= 0 || $title === '' || $location === '') {
        flash('error', 'Please fill in the resource name, category, and pickup location.');
        redirect('/receiver/post_request.php');
    }
    if (!in_array($condition, ['any','new','like_new','good','fair'], true)) $condition = 'any';
    if (!in_array($priority, ['normal','high','urgent'], true)) $priority = 'normal';

    $stmt = $db->prepare(
        "INSERT INTO requests
            (receiver_id, category_id, title, quantity_min, quantity_max, condition_preference, priority,
             needed_by, pickup_location, latitude, longitude, reason, additional_notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $uid, $categoryId, $title, $qtyMin, $qtyMax, $condition, $priority,
        $neededBy ?: null, $location, $lat, $lng, $reason ?: null, $notes ?: null,
    ]);
    $requestId = (int)$db->lastInsertId();

    flash('success', 'Request published! EcoLink is now finding suitable donor resources for you.');
    redirect('/receiver/find_resources.php?request_id=' . $requestId);
}

$pageTitle = 'Post a Request';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Post a Resource Request</h1>
<p>Tell EcoLink what your organization needs so suitable donors can be matched.</p>

<div class="step-bar">
    <div class="step-item"><span class="step-num">1</span><div class="step-text"><strong>Request Details</strong><small>What do you need?</small></div></div>
    <div class="step-item"><span class="step-num">2</span><div class="step-text"><strong>Quantity &amp; Requirements</strong><small>Specify your needs</small></div></div>
    <div class="step-item"><span class="step-num">3</span><div class="step-text"><strong>Review &amp; Publish</strong><small>Confirm your request</small></div></div>
</div>

<div class="two-col" style="align-items:start;">
    <form method="post" class="form-grid" style="background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:24px;">
        <h3 style="margin-top:0;">Request Details</h3>
        <label>Resource Needed *
            <input type="text" name="title" placeholder="e.g. Office Chairs, Children Books, Laptop Computers" required>
        </label>
        <div class="two-col">
            <label>Category *
                <select name="category_id" required>
                    <option value="">Select category</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['category_id'] ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Needed By
                <input type="date" name="needed_by">
            </label>
        </div>
        <h3>Quantity &amp; Requirements</h3>
        <div class="two-col">
            <label>Minimum Quantity *
                <input type="number" name="quantity_min" min="1" value="1" required>
            </label>
            <label>Maximum Quantity
                <input type="number" name="quantity_max" min="1" placeholder="Same as minimum if left blank">
            </label>
        </div>
        <label>Priority
            <select name="priority">
                <option value="normal">Normal</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
            </select>
        </label>
        <label>Preferred Condition
            <select name="condition_preference">
                <option value="any">Any usable condition</option>
                <option value="good">Good or better</option>
                <option value="like_new">Like New or better</option>
                <option value="new">New only</option>
            </select>
        </label>
        <label>Location / Preferred Pickup Area *
            <input type="text" name="pickup_location" placeholder="e.g. Uttara, Dhaka" required>
        </label>
        <div class="two-col">
            <label>Latitude (optional)
                <input type="text" name="latitude" placeholder="23.8759">
            </label>
            <label>Longitude (optional)
                <input type="text" name="longitude" placeholder="90.3795">
            </label>
        </div>
        <label>Why do you need this resource?
            <textarea name="reason" rows="3" placeholder='Example: "We need chairs for our community learning room used by local students."'></textarea>
        </label>
        <label>Additional Requirements
            <input type="text" name="additional_notes" placeholder="Optional notes for donors...">
        </label>
        <button type="submit" class="btn-primary">Save &amp; Continue →</button>
    </form>

    <div>
        <div class="mini-card" style="margin-bottom:16px;">
            <span class="eyebrow">Request Tips</span>
            <p style="margin:8px 0;"><strong>Be specific.</strong> Clear descriptions improve matching.</p>
            <p style="margin:8px 0;"><strong>Set a realistic quantity.</strong> Tell donors how many units you need.</p>
            <p style="margin:8px 0;"><strong>Mention your use case.</strong> This helps donors understand the impact.</p>
            <p style="margin:8px 0;"><strong>Keep location updated.</strong> Nearby matching depends on it.</p>
        </div>
        <div class="mini-card" style="background:var(--mint); border-color:var(--mint-line);">
            <span class="eyebrow">What happens next?</span>
            <p style="margin:8px 0;">1. EcoLink publishes your request.<br>2. Smart Matching finds suitable surplus.<br>3. You review available donor matches.<br>4. You accept a suitable donation.<br>5. Pickup and delivery are tracked.</p>
        </div>
    </div>
</div>

<p style="margin-top:20px;color:var(--ink-soft);">Prefer an always-on, category-level need instead of a one-off request? Update your <a href="<?= BASE_URL ?>/receiver/demand_profile.php">standing demand profile</a>.</p>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
