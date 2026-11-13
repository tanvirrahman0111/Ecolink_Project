<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';

$db = getDB();
$stats = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM donations WHERE status = 'completed') AS completed_donations,
        (SELECT COALESCE(SUM(quantity),0) FROM donations WHERE status = 'completed') AS resources_reused,
        (SELECT COUNT(DISTINCT matched_receiver_id) FROM donations WHERE status = 'completed') AS beneficiaries,
        (SELECT COALESCE(SUM(estimated_weight_kg),0) FROM impact_log) AS waste_saved,
        (SELECT COALESCE(SUM(estimated_co2_saved_kg),0) FROM impact_log) AS co2_saved
    "
)->fetch();

$pageTitle = 'Home';
require_once __DIR__ . '/includes/header.php';
?>
<section class="hero-split">
    <div>
        <span class="tag">+ SMART REDISTRIBUTION</span>
        <h1>Give Surplus a<br><span class="accent">Second Life.</span></h1>
        <p>Connect surplus resources with the people who need them through smarter, faster redistribution — powered by a priority-based matching algorithm.</p>
        <div class="hero-actions">
            <a class="btn-primary" href="<?= BASE_URL ?>/register.php?role=donor">Donate Resources</a>
            <a class="btn-secondary" href="<?= BASE_URL ?>/register.php?role=receiver">Find Resources</a>
            <a class="btn-secondary" href="<?= BASE_URL ?>/register.php?role=hub">Join as Hub Staff</a>
        </div>
    </div>
    <div class="hero-visual">
        <div class="smart-match-chip">
            <div class="label">Smart Match</div>
            <div class="pct">94% <small style="font-weight:500;color:#8a988f;">match score</small></div>
            <div class="bar"><span style="width:94%"></span></div>
        </div>
        <div class="hero-node-grid">
            <div class="hero-node"><div class="circle">🍲</div><span>Food</span></div>
            <div class="hero-node"><div class="circle">👕</div><span>Clothes</span></div>
            <div class="hero-node"><div class="circle">📚</div><span>Books</span></div>
            <div class="hero-node"><div class="circle">💻</div><span>Electronics</span></div>
        </div>
        <div class="center-node" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);">E</div>
    </div>
</section>

<section class="stats-strip">
    <div class="stat"><strong><?= (int)$stats['beneficiaries'] ?></strong><span>Beneficiaries</span></div>
    <div class="stat"><strong><?= (int)$stats['resources_reused'] ?></strong><span>Resources Reused</span></div>
    <div class="stat"><strong><?= number_format((float)$stats['waste_saved'],0) ?> kg</strong><span>Waste Saved</span></div>
    <div class="stat"><strong><?= number_format((float)$stats['co2_saved']/1000,2) ?>T</strong><span>CO₂ Saved</span></div>
</section>

<section class="section" id="categories">
    <div class="container">
        <h2>One Platform. Five Resource Categories.</h2>
        <p class="lead">Give useful surplus a new purpose instead of letting it go to waste.</p>
        <div class="cat-grid">
            <div class="cat-card"><div class="circle">🍲</div><h3>Food</h3><small>Surplus meals &amp; food</small></div>
            <div class="cat-card"><div class="circle">👕</div><h3>Clothes</h3><small>Wearable essentials</small></div>
            <div class="cat-card"><div class="circle">📚</div><h3>Books</h3><small>Books &amp; textbooks</small></div>
            <div class="cat-card"><div class="circle">💻</div><h3>Electronics</h3><small>Working devices</small></div>
            <div class="cat-card"><div class="circle">🛋️</div><h3>Furniture</h3><small>Reusable furniture</small></div>
        </div>
    </div>
</section>

<section class="section" id="how-it-works">
    <div class="container">
        <h2>How EcoLink Works</h2>
        <p class="lead">A simple journey from surplus resource to meaningful impact.</p>
        <div class="steps-flow">
            <div class="flow-step"><div class="num">01</div><h4>Donate</h4><small>Upload your surplus</small></div>
            <div class="flow-step"><div class="num">02</div><h4>Smart Match</h4><small>Find a suitable receiver</small></div>
            <div class="flow-step"><div class="num">03</div><h4>Accept</h4><small>Receiver accepts</small></div>
            <div class="flow-step"><div class="num">04</div><h4>Complete</h4><small>Track to completion</small></div>
        </div>
        <div class="lifecycle-strip">
            <span class="eyebrow">Donation Lifecycle</span><br>
            <strong>Available → Matched → Accepted → Pickup → Delivered → Completed</strong>
        </div>
    </div>
</section>

<section class="section split-section">
    <div class="container split-grid">
        <div>
            <h2>Smarter Matches.<br><span class="accent">Better Redistribution.</span></h2>
            <p style="color:var(--ink-soft); max-width:420px;">EcoLink recommends suitable donor–receiver pairs using category-weighted urgency, distance, demand, and capacity scoring.</p>
            <a class="btn-primary" href="<?= BASE_URL ?>/get_started.php">Explore Smart Matching</a>
        </div>
        <div class="match-result-card">
            <span class="eyebrow">Smart Match Results</span>
            <h3 style="margin:6px 0 2px;">Food Donation</h3>
            <small style="color:var(--ink-soft);">15 meals · Looking for suitable receivers</small>
            <div class="match-row">
                <div class="match-row-main">
                    <strong>Hope Foundation</strong><br><small style="color:var(--ink-soft);">2.4 km · High demand</small>
                    <div class="bar"><span style="width:94%"></span></div>
                </div>
                <div class="pct">94%</div>
            </div>
            <div class="match-row">
                <div class="match-row-main">
                    <strong>Community Center</strong><br><small style="color:var(--ink-soft);">4.1 km · Medium demand</small>
                    <div class="bar"><span style="width:86%"></span></div>
                </div>
                <div class="pct">86%</div>
            </div>
        </div>
    </div>
</section>

<section class="section" id="impact">
    <div class="container">
        <div class="stats-strip dark" style="flex-direction:column; text-align:center; padding:40px 20px;">
            <h2 style="color:#fff; margin-top:0;">Every Redistribution Creates an Impact.</h2>
            <p style="color:#cfe6d4; margin-bottom:28px;">Measure the social and environmental value created by every completed donation.</p>
            <div style="display:flex; justify-content:space-around; flex-wrap:wrap; gap:20px; width:100%;">
                <div class="stat"><strong><?= (int)$stats['resources_reused'] ?></strong><span>Resources Reused</span></div>
                <div class="stat"><strong><?= (int)$stats['beneficiaries'] ?></strong><span>People Benefited</span></div>
                <div class="stat"><strong><?= number_format((float)$stats['waste_saved'],0) ?> kg</strong><span>Waste Prevented</span></div>
                <div class="stat"><strong><?= number_format((float)$stats['co2_saved']/1000,2) ?>T</strong><span>CO₂ Saved</span></div>
            </div>
        </div>
    </div>
</section>

<section class="footer-cta">
    <h2>Have something worth giving?</h2>
    <p style="color:var(--ink-soft);">Don't let useful resources go to waste.</p>
    <a class="btn-primary" href="<?= BASE_URL ?>/get_started.php">Donate a Resource</a>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
