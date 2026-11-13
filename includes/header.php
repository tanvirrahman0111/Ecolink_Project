<?php
require_once __DIR__ . '/../config/app.php';

/**
 * Sidebar nav configuration per role, matching the Figma design:
 * icon (simple inline SVG path id), label, href, page-key for active state.
 */
function sidebarMenu(string $role): array {
    if ($role === 'donor') {
        return [
            'label' => 'MAIN MENU',
            'items' => [
                ['key' => 'dashboard',   'label' => 'Dashboard',         'href' => '/donor/dashboard.php',        'icon' => 'grid'],
                ['key' => 'donations',   'label' => 'My Donations',      'href' => '/donor/my_donations.php',     'icon' => 'circle'],
                ['key' => 'add',         'label' => 'Add Resource',      'href' => '/donor/upload_resource.php',  'icon' => 'plus'],
                ['key' => 'incoming',    'label' => 'Receiver Requests', 'href' => '/donor/donation_requests.php',    'icon' => 'arrow'],
                ['key' => 'hub',         'label' => 'Delivery Hub',      'href' => '/donor/hub_dropoff.php',      'icon' => 'circle'],
                ['key' => 'matching',    'label' => 'Smart Matching',    'href' => '/donor/smart_matching.php',   'icon' => 'spark'],
                ['key' => 'history',     'label' => 'Donation History',  'href' => '/donor/donation_history.php', 'icon' => 'search'],
                ['key' => 'impact',      'label' => 'My Impact',         'href' => '/donor/my_impact.php',        'icon' => 'arrow'],
            ],
        ];
    }
    if ($role === 'receiver') {
        return [
            'label' => 'RECEIVER MENU',
            'items' => [
                ['key' => 'dashboard',  'label' => 'Dashboard',         'href' => '/receiver/dashboard.php',        'icon' => 'grid'],
                ['key' => 'requests',   'label' => 'My Requests',       'href' => '/receiver/browse_matches.php',   'icon' => 'circle'],
                ['key' => 'post',       'label' => 'Post a Request',    'href' => '/receiver/post_request.php',     'icon' => 'plus'],
                ['key' => 'browse',     'label' => 'Browse Donations',  'href' => '/receiver/browse_donations.php', 'icon' => 'search'],
                ['key' => 'myreqs',     'label' => 'My Donation Requests','href' => '/receiver/my_donation_requests.php','icon' => 'refresh'],
                ['key' => 'hubpick',    'label' => 'Hub Pickup',        'href' => '/receiver/hub_pickup.php',       'icon' => 'circle'],
                ['key' => 'find',       'label' => 'Find Resources',    'href' => '/receiver/find_resources.php',   'icon' => 'search'],
                ['key' => 'history',    'label' => 'Request History',   'href' => '/receiver/request_history.php', 'icon' => 'arrow'],
                ['key' => 'impact',     'label' => 'My Impact',         'href' => '/receiver/my_impact.php',        'icon' => 'spark'],
            ],
        ];
    }
    if ($role === 'hub') {
        return [
            'label' => 'HUB MENU',
            'items' => [
                ['key' => 'hubdesk', 'label' => 'Hub Desk', 'href' => '/hub/desk.php', 'icon' => 'check'],
            ],
        ];
    }
    // admin
    return [
        'label' => 'ADMIN MENU',
        'items' => [
            ['key' => 'dashboard',  'label' => 'Dashboard',            'href' => '/admin/dashboard.php',           'icon' => 'grid'],
            ['key' => 'approvals',  'label' => 'Donation Approvals',   'href' => '/admin/approve_donations.php',   'icon' => 'check'],
            ['key' => 'hubs',       'label' => 'Delivery Hubs',        'href' => '/admin/hubs.php',                'icon' => 'circle'],
            ['key' => 'hubdesk',    'label' => 'Hub Desk',             'href' => '/admin/hub_desk.php',            'icon' => 'check'],
            ['key' => 'users',      'label' => 'Users & Organizations','href' => '/admin/users.php',               'icon' => 'circle'],
            ['key' => 'verify',     'label' => 'User Verification',    'href' => '/admin/verify_users.php',        'icon' => 'check'],
            ['key' => 'resources',  'label' => 'Resources & Categories','href' => '/admin/manage_categories.php',  'icon' => 'plus'],
            ['key' => 'requests',   'label' => 'Requests',             'href' => '/admin/requests.php',            'icon' => 'search'],
            ['key' => 'matches',    'label' => 'Match Monitoring',     'href' => '/admin/matches.php',             'icon' => 'spark'],
            ['key' => 'surveys',    'label' => 'Surveys',              'href' => '/admin/surveys.php',             'icon' => 'star'],
            ['key' => 'donations',  'label' => 'Donation Lifecycle',   'href' => '/admin/donations.php',           'icon' => 'refresh'],
            ['key' => 'analytics',  'label' => 'Matching Analytics',   'href' => '/admin/matching_analytics.php',  'icon' => 'arrow'],
            ['key' => 'impact',     'label' => 'Impact Dashboard',     'href' => '/admin/impact_dashboard.php',    'icon' => 'star'],
            ['key' => 'reports',    'label' => 'Reports & Analytics',  'href' => '/admin/reports.php',             'icon' => 'chart'],
            ['key' => 'moderation', 'label' => 'Moderation Center',    'href' => '/admin/moderation.php',          'icon' => 'flag'],
        ],
    ];
}

function navIcon(string $name): string {
    $icons = [
        'grid'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'circle' => '<circle cx="12" cy="12" r="8"/>',
        'plus'   => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'spark'  => '<path d="M12 2 L14 9 L21 12 L14 15 L12 22 L10 15 L3 12 L10 9 Z"/>',
        'arrow'  => '<line x1="17" y1="7" x2="7" y2="17"/><polyline points="8 7 17 7 17 16"/>',
        'bell'   => '<path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/>',
        'gear'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06A1.65 1.65 0 004.6 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 009 4.6a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09A1.65 1.65 0 0015 4.6a1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06A1.65 1.65 0 0019.4 9c.36.19.7.44 1 .74"/>',
        'check'  => '<polyline points="20 6 9 17 4 12"/>',
        'refresh'=> '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>',
        'star'   => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'chart'  => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'flag'   => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
        'user'   => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    ];
    $path = $icons[$name] ?? $icons['circle'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}

$__currentFile = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' – ' . APP_NAME : APP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=20261102">
</head>
<body class="<?= isLoggedIn() ? 'has-sidebar' : '' ?>">

<?php if (isLoggedIn()): ?>
    <?php $menu = sidebarMenu(currentUserRole()); ?>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="<?= BASE_URL ?>/index.php">
                <span class="brand-dot"></span> EcoLink
            </a>
            <nav class="side-nav">
                <p class="side-label"><?= e($menu['label']) ?></p>
                <?php foreach ($menu['items'] as $item): ?>
                    <a class="side-link <?= $__currentFile === basename($item['href']) ? 'active' : '' ?>"
                       href="<?= BASE_URL . $item['href'] ?>">
                        <span class="side-icon"><?= navIcon($item['icon']) ?></span>
                        <?= e($item['label']) ?>
                    </a>
                <?php endforeach; ?>
                <p class="side-label" style="margin-top:22px;">ACCOUNT</p>
                <a class="side-link <?= $__currentFile === 'notifications.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/notifications.php">
                    <span class="side-icon"><?= navIcon('bell') ?></span> Notifications
                </a>
                <a class="side-link <?= $__currentFile === 'settings.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/settings.php">
                    <span class="side-icon"><?= navIcon('gear') ?></span> Profile &amp; Settings
                </a>
                <a class="side-link" href="<?= BASE_URL ?>/logout.php">
                    <span class="side-icon"><?= navIcon('arrow') ?></span> Log out
                </a>
            </nav>
            <div class="side-user">
                <span class="side-avatar"><?= e(strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1))) ?></span>
                <div>
                    <strong><?= e($_SESSION['full_name'] ?? '') ?></strong>
                    <small><?= e(ucfirst(currentUserRole())) ?> Account</small>
                </div>
            </div>
        </aside>
        <div class="sidebar-backdrop" data-sidebar-close></div>
        <main class="app-main">
            <div class="app-topbar">
                <button type="button" class="menu-toggle" aria-label="Open menu" aria-expanded="false" data-sidebar-toggle>
                    <span></span><span></span><span></span>
                </button>
                <div class="topbar-icons">
                    <a class="icon-btn" href="#" title="Search"><?= navIcon('search') ?></a>
                    <a class="icon-btn" href="<?= BASE_URL ?>/notifications.php" title="Notifications"><?= navIcon('bell') ?></a>
                </div>
            </div>
<?php else: ?>
    <header class="site-header">
        <div class="container header-inner">
            <a class="brand" href="<?= BASE_URL ?>/index.php"><span class="brand-dot"></span> EcoLink</a>
            <nav class="nav">
                <a href="<?= BASE_URL ?>/index.php">Home</a>
                <a href="<?= BASE_URL ?>/index.php#how-it-works">How It Works</a>
                <a href="<?= BASE_URL ?>/index.php#categories">Resources</a>
                <a href="<?= BASE_URL ?>/index.php#impact">Impact</a>
                <a href="<?= BASE_URL ?>/login.php">Login</a>
                <a class="btn-cta" href="<?= BASE_URL ?>/get_started.php">Get Started</a>
            </nav>
        </div>
    </header>
    <main class="container">
<?php endif; ?>
<?php
$err = flash('error');
$ok  = flash('success');
if ($err): ?>
    <div class="alert alert-error"><?= e($err) ?></div>
<?php endif;
if ($ok): ?>
    <div class="alert alert-success"><?= e($ok) ?></div>
<?php endif; ?>
