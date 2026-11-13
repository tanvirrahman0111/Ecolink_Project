<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/media.php';
require_once __DIR__ . '/../includes/hub.php';
requireRole('donor');

$db = getDB();
$categories = $db->query("SELECT * FROM resource_categories ORDER BY name")->fetchAll();
ensureHubTables($db);
$hubs = $db->query("SELECT * FROM delivery_hubs WHERE is_active = 1 ORDER BY area, name")->fetchAll();
$me = $db->prepare("SELECT COALESCE(NULLIF(pickup_area,''), address) FROM users WHERE user_id = ?");
$me->execute([currentUserId()]);
$myArea = (string)$me->fetchColumn();
usort($hubs, fn($a, $b) => (int)sameArea($b['area'], $myArea) <=> (int)sameArea($a['area'], $myArea));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categoryId  = (int)($_POST['category_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quantity    = max(1, (int)($_POST['quantity'] ?? 1));
    $unit        = trim($_POST['unit'] ?? 'pcs');
    $condition   = $_POST['condition_grade'] ?? 'good';
    $address     = trim($_POST['pickup_address'] ?? '');
    $lat         = $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
    $lng         = $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
    $expiry      = trim($_POST['expiry_datetime'] ?? '');

    if ($categoryId <= 0 || $title === '' || $address === '') {
        flash('error', 'Please fill in category, title, and pickup address.');
        redirect('/donor/upload_resource.php');
    }
    if (!in_array($condition, ['new','like_new','good','fair'], true)) $condition = 'good';

    // The donor decides which delivery hub the item will be dropped at.
    $hubId = (int)($_POST['hub_id'] ?? 0);
    $hubRow = null;
    foreach ($hubs as $h) if ((int)$h['hub_id'] === $hubId) $hubRow = $h;
    if ($hubs && !$hubRow) {
        flash('error', 'Please choose the delivery hub where you will drop this item.');
        redirect('/donor/upload_resource.php');
    }

    // New donations always start as 'pending_approval'. An admin must approve
    // them before any receiver can see them.
    $stmt = $db->prepare(
        "INSERT INTO donations
            (donor_id, category_id, title, description, quantity, unit, condition_grade,
             pickup_address, latitude, longitude, expiry_datetime, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval')"
    );
    $stmt->execute([
        currentUserId(), $categoryId, $title, $description ?: null, $quantity, $unit ?: 'pcs',
        $condition, $address, $lat, $lng, $expiry ?: null,
    ]);
    $donationId = (int)$db->lastInsertId();
    [$mediaSaved, $mediaProblems] = saveDonationMedia($db, $donationId, 'media');
    if ($mediaProblems) flash('error', implode(' ', $mediaProblems));

    if ($hubRow) {
        $db->prepare("INSERT INTO hub_items (hub_id, donation_id, donor_id) VALUES (?, ?, ?)")->execute([$hubRow['hub_id'], $donationId, currentUserId()]);
        hubNotifyStaff($db, (int)$hubRow['hub_id'], 'Incoming donation', 'A donor will bring "' . mb_substr($title, 0, 80) . '" (donation #' . $donationId . ') to your hub once an admin approves it.');
    }

    $log = $db->prepare("INSERT INTO lifecycle_log (donation_id, status, note, changed_by) VALUES (?, 'pending_approval', 'Submitted by donor, waiting for admin approval', ?)");
    $log->execute([$donationId, currentUserId()]);

    // Tell every admin there is something to review.
    $admins = $db->query("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    $notify = $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'system', ?, ?)");
    foreach ($admins as $adminId) {
        $notify->execute([$adminId, 'New donation awaiting approval', 'Donation "' . mb_substr($title, 0, 100) . '" needs your review.']);
    }

    redirect('/donor/resource_posted.php?donation_id=' . $donationId);
}

$pageTitle = 'Add a Resource';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Add a Resource</h1>
<p>Share a surplus resource and help it reach someone who needs it. An admin will review it before receivers can see it.</p>
<form method="post" enctype="multipart/form-data" class="form-grid">
    <label>Category
        <select name="category_id" required>
            <option value="">Select category</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['category_id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Title
        <input type="text" name="title" placeholder="e.g. 20 cooked meal packs" required>
    </label>
    <label>Description
        <textarea name="description" rows="3" placeholder="Any details receivers should know"></textarea>
    </label>
    <label>Quantity
        <input type="number" name="quantity" value="1" min="1" required>
    </label>
    <label>Unit
        <input type="text" name="unit" value="pcs" placeholder="pcs, kg, boxes...">
    </label>
    <label>Condition
        <select name="condition_grade">
            <option value="new">New</option>
            <option value="like_new">Like New</option>
            <option value="good" selected>Good</option>
            <option value="fair">Fair</option>
        </select>
    </label>
    <label>Photos &amp; videos <small>(up to 6 photos + 2 videos; receivers will see these)</small>
        <input type="file" name="media[]" accept="image/*,video/*" multiple>
    </label>
    <label>Delivery Hub <small>(where you will drop this item; the receiver collects it from there with an ID)</small>
        <select name="hub_id" <?= $hubs ? 'required' : '' ?>>
            <?php if ($hubs): ?>
                <option value="">Select a delivery hub</option>
                <?php foreach ($hubs as $h): ?>
                    <option value="<?= (int)$h['hub_id'] ?>"><?= e($h['name']) ?> — <?= e($h['area']) ?> (<?= e($h['address']) ?>)<?= sameArea($h['area'], $myArea) ? ' ⭐ your area' : '' ?></option>
                <?php endforeach; ?>
            <?php else: ?>
                <option value="">No hub is open yet (ask admin) — you can add one later</option>
            <?php endif; ?>
        </select>
    </label>
    <label>Pickup Address
        <input type="text" name="pickup_address" placeholder="e.g. Road 5, Dhanmondi, Dhaka" required>
    </label>
    <div class="two-col">
        <label>Latitude (optional)
            <input type="text" name="latitude" placeholder="23.7461">
        </label>
        <label>Longitude (optional)
            <input type="text" name="longitude" placeholder="90.3742">
        </label>
    </div>
    <label>Expiry Date/Time <small>(food items — leave blank if not perishable)</small>
        <input type="datetime-local" name="expiry_datetime">
    </label>
    <button type="submit" class="btn-primary">Submit for Approval</button>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
