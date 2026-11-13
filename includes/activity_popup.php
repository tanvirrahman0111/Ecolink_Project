<?php
/** Live activity pop-ups (new users, donations, received items, hub updates). Included from footer.php. */
$__apV = '20261107';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/activity-popup.css?v=<?= $__apV ?>">
<div id="eco-pop" class="eco-pop" data-endpoint="<?= BASE_URL ?>/api/activity.php" aria-live="polite" aria-label="Live EcoLink activity">
    <div class="eco-pop-stack"></div>
    <button type="button" class="eco-pop-toggle" aria-pressed="false" title="Mute / unmute live updates">
        <span class="eco-pop-dot"></span><span class="eco-pop-toggle-label">Live updates</span>
    </button>
</div>
<script src="<?= BASE_URL ?>/assets/js/activity-popup.js?v=<?= $__apV ?>" defer></script>
