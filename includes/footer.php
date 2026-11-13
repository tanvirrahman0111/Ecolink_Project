<?php if (isLoggedIn()): ?>
        </main>
    </div>
<?php else: ?>
    </main>
    <footer class="site-footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> EcoLink — Smart Surplus Resource Optimization &amp; Redistribution Platform. CSE 416, Department of CSE, UITS.</p>
        </div>
    </footer>
<?php endif; ?>
<?php require_once __DIR__ . '/activity_popup.php'; ?>
<script src="<?= BASE_URL ?>/assets/js/script.js?v=20261102"></script>
</body>
</html>
