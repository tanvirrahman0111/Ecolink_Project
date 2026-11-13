<?php
/**
 * EcoLink - Donation details: photos/videos, info, tracking timeline, survey.
 * Shared by donor (owner), receiver and admin. Opened by tapping any donation.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/hub.php';
requireLogin();

$db = getDB();
ensureHubTables($db);
syncHubAssignments($db);
$uid = currentUserId();
$role = currentUserRole();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$st = $db->prepare(
    "SELECT d.*, c.name AS category_name,
            du.full_name AS donor_name, du.phone AS donor_phone, du.organization_name AS donor_org,
            ru.full_name AS receiver_name, ru.phone AS receiver_phone, ru.organization_name AS receiver_org, ru.address AS receiver_address
     FROM donations d
     JOIN resource_categories c ON c.category_id = d.category_id
     JOIN users du ON du.user_id = d.donor_id
     LEFT JOIN users ru ON ru.user_id = d.matched_receiver_id
     WHERE d.donation_id = ?"
);
$st->execute([$id]);
$d = $st->fetch();

// ---- access rules ----
$isOwner    = $d && $role === 'donor' && (int)$d['donor_id'] === $uid;
$isMatched  = $d && $role === 'receiver' && (int)$d['matched_receiver_id'] === $uid;
$isAdmin    = $role === 'admin';
$canView = $d && ($isAdmin || $isOwner || $isMatched || ($role === 'receiver' && $d['status'] === 'available'));
if (!$canView) {
    flash('error', 'That donation could not be found or you do not have access to it.');
    redirect('/' . ($role ?: 'index') . '/dashboard.php');
}
$showContacts = $isAdmin || $isOwner || $isMatched; // contact details only for the two parties (and admin)

// ---- survey submit ----
$surveyAllowed = $d['status'] === 'completed' && ($isOwner || $isMatched);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'survey') {
    $rating = (int)($_POST['rating'] ?? 0);
    if (!$surveyAllowed) {
        flash('error', 'The survey opens once the donation is completed.');
    } elseif ($rating < 1 || $rating > 5) {
        flash('error', 'Please choose an overall rating from 1 to 5.');
    } else {
        $comm = (int)($_POST['communication'] ?? 0);
        $asDesc = $_POST['item_as_described'] ?? '';
        $onTime = $_POST['on_time'] ?? '';
        $db->prepare(
            "INSERT INTO donation_surveys (donation_id, user_id, role, rating, item_as_described, on_time, communication, comment)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), item_as_described = VALUES(item_as_described),
                 on_time = VALUES(on_time), communication = VALUES(communication), comment = VALUES(comment), created_at = NOW()"
        )->execute([
            $id, $uid, $role, $rating,
            $asDesc === '' ? null : (int)($asDesc === '1'),
            $onTime === '' ? null : (int)($onTime === '1'),
            ($comm >= 1 && $comm <= 5) ? $comm : null,
            mb_substr(trim($_POST['comment'] ?? ''), 0, 500) ?: null,
        ]);
        flash('success', 'Thank you! Your survey was saved.');
    }
    redirect('/donation_view.php?id=' . $id . '#survey');
}

$media = donationMedia($db, $id, $d['image_path']);
$photos = array_values(array_filter($media, fn($m) => $m['media_type'] === 'image'));
$videos = array_values(array_filter($media, fn($m) => $m['media_type'] === 'video'));

$log = $db->prepare("SELECT l.*, u.full_name, u.role FROM lifecycle_log l LEFT JOIN users u ON u.user_id = l.changed_by WHERE l.donation_id = ? ORDER BY l.changed_at, l.log_id");
$log->execute([$id]);
$log = $log->fetchAll();
$reached = [];
foreach ($log as $row) $reached[$row['status']] = $row['changed_at'];

$mySurvey = $db->prepare("SELECT * FROM donation_surveys WHERE donation_id = ? AND user_id = ?");
$mySurvey->execute([$id, $uid]);
$mySurvey = $mySurvey->fetch() ?: null;

$surveys = [];
if ($isAdmin || $d['status'] === 'completed') {
    $q = $db->prepare("SELECT s.*, u.full_name FROM donation_surveys s JOIN users u ON u.user_id = s.user_id WHERE s.donation_id = ? ORDER BY s.created_at");
    $q->execute([$id]);
    $surveys = $q->fetchAll();
    if (!$isAdmin) $surveys = array_values(array_filter($surveys, fn($s) => (int)$s['user_id'] === $uid));
}

// tracking steps
$steps = [
    'pending_approval' => 'Submitted',
    'available'        => 'Approved',
    'accepted'         => 'Accepted',
    'pickup'           => 'Pickup',
    'delivered'        => 'Delivered',
    'completed'        => 'Completed',
];
$order = array_keys($steps);
$currentIdx = array_search($d['status'], $order, true);
if ($d['status'] === 'matched') $currentIdx = 1;
$terminalBad = in_array($d['status'], ['rejected', 'cancelled'], true);

$backHref = $isAdmin ? '/admin/donations.php' : ($isOwner ? '/donor/my_donations.php' : '/receiver/browse_donations.php');
$icons = ['Food' => '🍲', 'Clothes' => '👕', 'Books' => '📚', 'Electronics' => '💻', 'Furniture' => '🛋️'];

$pageTitle = 'Donation Details';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.gallery-main { width:100%; max-height:420px; border-radius:14px; background:#0e1f17; display:flex; align-items:center; justify-content:center; overflow:hidden; }
.gallery-main img, .gallery-main video { width:100%; max-height:420px; object-fit:contain; display:block; }
.gallery-thumbs { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
.gallery-thumbs button { border:2px solid transparent; padding:0; border-radius:10px; overflow:hidden; cursor:pointer; background:#0e1f17; width:78px; height:78px; position:relative; }
.gallery-thumbs button.active { border-color:var(--forest-2); }
.gallery-thumbs img, .gallery-thumbs video { width:100%; height:100%; object-fit:cover; display:block; pointer-events:none; }
.gallery-thumbs .play { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.4rem; background:rgba(0,0,0,.35); }
.no-media { padding:60px 20px; text-align:center; color:var(--ink-soft); background:var(--mint); border-radius:14px; font-size:2.4rem; }
.no-media small { display:block; font-size:.9rem; margin-top:6px; }
.timeline { list-style:none; margin:0; padding:0; }
.timeline li { position:relative; padding:0 0 16px 26px; border-left:2px solid var(--line); margin-left:6px; }
.timeline li::before { content:''; position:absolute; left:-7px; top:2px; width:12px; height:12px; border-radius:50%; background:var(--forest-2); }
.timeline li:last-child { border-left-color:transparent; padding-bottom:0; }
.timeline small { color:var(--ink-soft); display:block; }
.stars label { font-size:1.6rem; cursor:pointer; color:#c9d3cc; }
.stars { display:flex; flex-direction:row-reverse; justify-content:flex-end; gap:4px; }
.stars input { display:none; }
.stars input:checked ~ label, .stars label:hover, .stars label:hover ~ label { color:#f5a623; }
.star-show { color:#f5a623; letter-spacing:2px; }
</style>

<p><a href="<?= BASE_URL . $backHref ?>">← Back</a></p>
<h1><?= $icons[$d['category_name']] ?? '📦' ?> <?= e($d['title']) ?>
    <span class="badge badge-<?= e($d['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $d['status']))) ?></span></h1>
<p style="color:var(--ink-soft);">ECO-<?= str_pad((string)$d['donation_id'], 5, '0', STR_PAD_LEFT) ?> · <?= e($d['category_name']) ?> · Posted <?= e(date('M d, Y', strtotime($d['created_at']))) ?></p>

<div class="two-col" style="align-items:start;">
    <!-- ============ photos & videos ============ -->
    <div>
        <?php if ($media): $first = $media[0]; ?>
            <div class="gallery-main" id="galleryMain">
                <?php if ($first['media_type'] === 'video'): ?>
                    <video src="<?= UPLOAD_URL . e($first['file_path']) ?>" controls playsinline></video>
                <?php else: ?>
                    <img src="<?= UPLOAD_URL . e($first['file_path']) ?>" alt="">
                <?php endif; ?>
            </div>
            <?php if (count($media) > 1): ?>
            <div class="gallery-thumbs">
                <?php foreach ($media as $i => $m): ?>
                    <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-type="<?= e($m['media_type']) ?>" data-src="<?= UPLOAD_URL . e($m['file_path']) ?>">
                        <?php if ($m['media_type'] === 'video'): ?><video src="<?= UPLOAD_URL . e($m['file_path']) ?>#t=0.5" preload="metadata" muted></video><span class="play">▶</span>
                        <?php else: ?><img src="<?= UPLOAD_URL . e($m['file_path']) ?>" alt=""><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <p style="color:var(--ink-soft); font-size:.85rem; margin-top:8px;"><?= count($photos) ?> photo(s) · <?= count($videos) ?> video(s)</p>
        <?php else: ?>
            <div class="no-media"><?= $icons[$d['category_name']] ?? '📦' ?><small>The donor did not add photos or videos.</small></div>
        <?php endif; ?>
    </div>

    <!-- ============ info ============ -->
    <div class="mini-card">
        <span class="eyebrow">Donation Information</span>
        <p style="color:var(--ink-soft);"><?= nl2br(e($d['description'] ?: 'No description provided.')) ?></p>
        <table class="data-table">
            <tr><td>Quantity</td><td><strong><?= (int)$d['quantity'] ?> <?= e($d['unit']) ?></strong></td></tr>
            <tr><td>Condition</td><td><?= e(ucfirst(str_replace('_', ' ', $d['condition_grade']))) ?></td></tr>
            <tr><td>Pickup address</td><td><?= e($d['pickup_address']) ?></td></tr>
            <?php if ($d['expiry_datetime']): ?><tr><td>Expires</td><td><?= e(date('M d, Y H:i', strtotime($d['expiry_datetime']))) ?></td></tr><?php endif; ?>
            <tr><td>Donor</td><td><?= e($d['donor_org'] ?: $d['donor_name']) ?><?= $showContacts ? '<br><small>📞 ' . e($d['donor_phone']) . '</small>' : '' ?></td></tr>
            <?php if ($d['matched_receiver_id'] && ($isAdmin || $isOwner || $isMatched)): ?>
                <tr><td>Receiver</td><td><?= e($d['receiver_org'] ?: $d['receiver_name']) ?>
                    <?php if ($showContacts && in_array($d['status'], ['accepted','pickup','delivered','completed'], true)): ?><br><small>📞 <?= e($d['receiver_phone']) ?><?= $d['receiver_address'] ? '<br>📍 ' . e($d['receiver_address']) : '' ?></small><?php endif; ?></td></tr>
            <?php endif; ?>
            <?php if ($d['status'] === 'rejected' && $d['rejection_reason']): ?><tr><td>Rejection reason</td><td><?= e($d['rejection_reason']) ?></td></tr><?php endif; ?>
        </table>
        <?php if ($role === 'receiver' && $d['status'] === 'available'): ?>
            <p style="margin-top:12px;"><a class="btn-primary" href="<?= BASE_URL ?>/receiver/browse_donations.php#d<?= (int)$d['donation_id'] ?>">Request this Donation →</a></p>
        <?php endif; ?>
    </div>
</div>

<!-- ============ tracking ============ -->
<h2 id="tracking">Tracking</h2>
<?php if ($isMatched || $isOwner || $isAdmin) echo renderHubTrackingCard(hubItemForDonation($db, $id), $isMatched ? 'receiver' : ($isOwner ? 'donor' : 'admin')); ?>
<div class="mini-card">
    <?php if ($terminalBad): ?>
        <p><span class="badge badge-<?= e($d['status']) ?>"><?= e(ucfirst($d['status'])) ?></span> This donation will not move forward.</p>
    <?php else: ?>
    <div class="stage-flow">
        <?php foreach ($order as $i => $key):
            $cls = $currentIdx !== false && $i < $currentIdx ? 'done' : ($i === $currentIdx ? 'current' : '');
            if ($d['status'] === 'completed') $cls = 'done';
        ?>
            <div class="stage <?= $cls ?>">
                <div class="bar"></div>
                <div class="num"><?= $cls === 'done' ? '✓' : $i + 1 ?></div>
                <strong><?= e($steps[$key]) ?></strong>
                <small><?= isset($reached[$key]) ? e(date('M d, H:i', strtotime($reached[$key]))) : '—' ?></small>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($log && ($showContacts)): ?>
        <h3 style="margin:18px 0 10px;">History</h3>
        <ul class="timeline">
            <?php foreach ($log as $row): ?>
                <li><strong><?= e(ucfirst(str_replace('_', ' ', $row['status']))) ?></strong>
                    <small><?= e(date('M d, Y H:i', strtotime($row['changed_at']))) ?><?= $row['full_name'] ? ' · by ' . e($row['full_name']) . ' (' . e($row['role']) . ')' : '' ?></small>
                    <?php if ($row['note']): ?><small><?= e($row['note']) ?></small><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<!-- ============ survey ============ -->
<h2 id="survey">Survey</h2>
<div class="mini-card">
<?php if ($surveyAllowed): ?>
    <p style="color:var(--ink-soft); margin-top:0;">How did this donation go? Your answers help EcoLink improve matching and trust.<?= $mySurvey ? ' You already answered — you can update it below.' : '' ?></p>
    <form method="post" class="form-grid">
        <input type="hidden" name="action" value="survey"><input type="hidden" name="id" value="<?= $id ?>">
        <div>Overall rating
            <div class="stars">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <input type="radio" id="r<?= $i ?>" name="rating" value="<?= $i ?>" <?= $mySurvey && (int)$mySurvey['rating'] === $i ? 'checked' : '' ?>><label for="r<?= $i ?>" title="<?= $i ?> star">★</label>
                <?php endfor; ?>
            </div>
        </div>
        <label><?= $isOwner ? 'Was the receiver ready for pickup / handover?' : 'Was the item as described?' ?>
            <select name="item_as_described">
                <option value="">—</option>
                <option value="1" <?= $mySurvey && (string)$mySurvey['item_as_described'] === '1' ? 'selected' : '' ?>>Yes</option>
                <option value="0" <?= $mySurvey && (string)$mySurvey['item_as_described'] === '0' ? 'selected' : '' ?>>No</option>
            </select></label>
        <label>Was the handover on time?
            <select name="on_time">
                <option value="">—</option>
                <option value="1" <?= $mySurvey && (string)$mySurvey['on_time'] === '1' ? 'selected' : '' ?>>Yes</option>
                <option value="0" <?= $mySurvey && (string)$mySurvey['on_time'] === '0' ? 'selected' : '' ?>>No</option>
            </select></label>
        <label>Communication with the other party
            <select name="communication">
                <option value="">—</option>
                <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= $mySurvey && (int)$mySurvey['communication'] === $i ? 'selected' : '' ?>><?= $i ?> / 5</option><?php endfor; ?>
            </select></label>
        <label>Comments (optional)
            <textarea name="comment" rows="3" maxlength="500"><?= e($mySurvey['comment'] ?? '') ?></textarea></label>
        <button class="btn-primary" type="submit"><?= $mySurvey ? 'Update Survey' : 'Submit Survey' ?></button>
    </form>
<?php elseif ($d['status'] !== 'completed'): ?>
    <p style="color:var(--ink-soft); margin:0;">The survey opens for the donor and receiver once this donation is <strong>completed</strong>.</p>
<?php endif; ?>

<?php if ($surveys && ($isAdmin || !$surveyAllowed)): ?>
    <?php foreach ($surveys as $s): ?>
        <div style="border-top:1px solid var(--line); padding:10px 0;">
            <strong><?= e($s['full_name']) ?></strong> <small>(<?= e($s['role']) ?>)</small>
            <span class="star-show"><?= str_repeat('★', (int)$s['rating']) . str_repeat('☆', 5 - (int)$s['rating']) ?></span>
            <small style="display:block; color:var(--ink-soft);">
                <?php if ($s['item_as_described'] !== null): ?><?= $s['item_as_described'] ? 'As described / ready ✓' : 'Not as described / not ready ✗' ?> · <?php endif; ?>
                <?php if ($s['on_time'] !== null): ?><?= $s['on_time'] ? 'On time ✓' : 'Late ✗' ?> · <?php endif; ?>
                <?php if ($s['communication']): ?>Communication <?= (int)$s['communication'] ?>/5<?php endif; ?>
            </small>
            <?php if ($s['comment']): ?><p style="margin:4px 0 0;"><?= e($s['comment']) ?></p><?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php elseif ($isAdmin && $d['status'] === 'completed'): ?>
    <p style="color:var(--ink-soft);">No survey answers yet.</p>
<?php endif; ?>
</div>

<script>
(function () {
    var main = document.getElementById('galleryMain');
    if (!main) return;
    document.querySelectorAll('.gallery-thumbs button').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.gallery-thumbs button').forEach(function (x) { x.classList.remove('active'); });
            b.classList.add('active');
            var src = b.getAttribute('data-src');
            main.innerHTML = '';
            var el;
            if (b.getAttribute('data-type') === 'video') { el = document.createElement('video'); el.controls = true; el.playsInline = true; el.autoplay = true; }
            else { el = document.createElement('img'); el.alt = ''; }
            el.src = src;
            main.appendChild(el);
        });
    });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
