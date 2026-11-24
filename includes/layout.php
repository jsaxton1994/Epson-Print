<?php
/**
 * Page chrome: public site header/footer, dashboard shell, icons, flashes.
 */

declare(strict_types=1);

function icon(string $name, string $class = ''): string
{
    $paths = [
        'printer'    => '<path d="M6 9V4.5h12V9"/><path d="M6 18H4.5A2.5 2.5 0 0 1 2 15.5v-4A2.5 2.5 0 0 1 4.5 9h15A2.5 2.5 0 0 1 22 11.5v4A2.5 2.5 0 0 1 19.5 18H18"/><rect x="6" y="14" width="12" height="7" rx="1.6"/><path d="M6 14h12"/>',
        'upload'     => '<path d="M12 16V4"/><path d="m7.5 8.5 4.5-4.5 4.5 4.5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'download'   => '<path d="M12 4v12"/><path d="m7.5 11.5 4.5 4.5 4.5-4.5"/><path d="M4 19h16"/>',
        'check'      => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/>',
        'alert'      => '<path d="M12 8.5v5"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/>',
        'close'      => '<path d="m6 6 12 12"/><path d="m18 6-12 12"/>',
        'users'      => '<circle cx="9" cy="8" r="3.4"/><path d="M2.5 19.5v-1.2A4.3 4.3 0 0 1 6.8 14h4.4a4.3 4.3 0 0 1 4.3 4.3v1.2"/><path d="M16.5 4.6a3.4 3.4 0 0 1 0 6.6"/><path d="M18 14h.4a3 3 0 0 1 3 3v1.5"/>',
        'layers'     => '<path d="m12 3.5 8.5 4.6-8.5 4.6-8.5-4.6L12 3.5Z"/><path d="m3.5 12.4 8.5 4.6 8.5-4.6"/><path d="m3.5 16.6 8.5 4.6 8.5-4.6"/>',
        'gear'       => '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.03 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.36a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.64 15a1.7 1.7 0 0 0-1.56-1.03H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.64 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.64h.09A1.7 1.7 0 0 0 10.12 3.08V3a2 2 0 1 1 4 0v.09A1.7 1.7 0 0 0 15.15 4.64a1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.36 9v.09A1.7 1.7 0 0 0 20.92 10.12H21a2 2 0 1 1 0 4h-.09A1.7 1.7 0 0 0 19.4 15Z"/>',
        'logout'     => '<path d="M9.5 21H5.5A2.5 2.5 0 0 1 3 18.5v-13A2.5 2.5 0 0 1 5.5 3h4"/><path d="m16 16.5 4.5-4.5L16 7.5"/><path d="M20.5 12h-11"/>',
        'menu'       => '<path d="M3.5 7h17"/><path d="M3.5 12h17"/><path d="M3.5 17h17"/>',
        'search'     => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'file'       => '<path d="M14 3.5H7A2 2 0 0 0 5 5.5v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5l-5-5Z"/><path d="M14 3.5v5h5"/>',
        'image'      => '<rect x="3" y="4.5" width="18" height="15" rx="2.6"/><circle cx="9" cy="10" r="1.7"/><path d="m4 17.5 4.5-4.5L12 16l3.2-3.2L20 17.5"/>',
        'arrow-right'=> '<path d="M4.5 12h15"/><path d="m13.5 6 6 6-6 6"/>',
        'arrow-left' => '<path d="M19.5 12h-15"/><path d="m10.5 6-6 6 6 6"/>',
        'chevron-down' => '<path d="m6 9.5 6 6 6-6"/>',
        'plus'       => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'sparkles'   => '<path d="m12 3 1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3Z"/><path d="M18.5 15.5 19.4 18l2.5.9-2.5.9-.9 2.5-.9-2.5-2.5-.9 2.5-.9.9-2.5Z"/>',
        'shield'     => '<path d="M12 3 5 6v6c0 4.4 3 7.9 7 9 4-1.1 7-4.6 7-9V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
        'bolt'       => '<path d="M13 2 5 13.5h5.5L10 22l8-11.5h-5.5L13 2Z"/>',
        'mail'       => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.8 7.5 8.2 5.7 8.2-5.7"/>',
        'phone'      => '<path d="M6.5 3.5h3l1.5 4-2 1.5a11 11 0 0 0 5 5l1.5-2 4 1.5v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.5 5.7 2 2 0 0 1 6.5 3.5Z"/>',
        'eye'        => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'    => '<path d="M3 3l18 18"/><path d="M10.6 6.1A9.7 9.7 0 0 1 12 6c6 0 9.5 6 9.5 6a17 17 0 0 1-2.4 3.1"/><path d="M6.2 7.6A16.6 16.6 0 0 0 2.5 12S6 18 12 18a9.6 9.6 0 0 0 3.9-.8"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'refresh'    => '<path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><path d="M20.5 4v5h-5"/>',
        'trash'      => '<path d="M4 7h16"/><path d="M9.5 7V4.5h5V7"/><path d="M6.5 7l.8 12.2A2 2 0 0 0 9.3 21h5.4a2 2 0 0 0 2-1.8L17.5 7"/><path d="M10.5 11v6"/><path d="M13.5 11v6"/>',
        'home'       => '<path d="m3.5 10.5 8.5-7 8.5 7"/><path d="M5.5 9v10.5h13V9"/><path d="M10 19.5v-5h4v5"/>',
        'wifi'       => '<path d="M2.5 8.5a14 14 0 0 1 19 0"/><path d="M5.8 12a9.5 9.5 0 0 1 12.4 0"/><path d="M9 15.4a5 5 0 0 1 6 0"/><path d="M12 19h.01"/>',
        'leaf'       => '<path d="M20 4c-9 0-15 4.5-15 11a4.5 4.5 0 0 0 4.5 4.5C16 19.5 20 13.5 20 4Z"/><path d="M4 21c1-6 5-9.5 10-11.5"/>',
        'copy'       => '<rect x="8.5" y="8.5" width="11.5" height="11.5" rx="2"/><path d="M15.5 5.5v-1a2 2 0 0 0-2-2h-8a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h1"/>',
        'tiro'       => '<circle cx="12" cy="12" r="9"/><path d="M12 8v.01"/><path d="M12 11v5"/>',
    ];

    $path = $paths[$name] ?? $paths['file'];

    return '<svg class="icon ' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

function render_flashes(): string
{
    $html = '';

    foreach (consume_flashes() as $flash) {
        $type = in_array($flash['type'], ['success', 'error', 'info'], true) ? $flash['type'] : 'info';
        $iconName = match ($type) {
            'success' => 'check',
            'error'   => 'alert',
            default   => 'alert',
        };

        $html .= '<div class="flash flash-' . $type . '" role="status" data-flash>'
            . icon($iconName, 'flash-icon')
            . '<span>' . e($flash['message']) . '</span>'
            . '<button type="button" class="flash-close" data-flash-close aria-label="Dismiss">' . icon('close') . '</button>'
            . '</div>';
    }

    return $html === '' ? '' : '<div class="flash-stack">' . $html . '</div>';
}

/* -------------------------------------------------------------------------
 |  Public site chrome
 * ---------------------------------------------------------------------- */

function site_head(string $title, array $options = []): void
{
    $user   = current_user();
    $active = $options['active'] ?? '';
    $body   = $options['body_class'] ?? '';
    $full   = $title === '' ? site_name() : $title . ' — ' . site_name();
    $home   = url('public/index.php');
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($full) ?></title>
    <meta name="description" content="<?= e($options['description'] ?? 'Print your photos for free on the PrintFree Epson ET-2926. Upload a JPG or PNG, choose 4 × 6 and we print it on photo paper.') ?>">
    <meta name="theme-color" content="#f6f8fc">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
</head>
<body class="site-body <?= e($body) ?>">
<?= render_flashes() ?>
<header class="site-header" data-site-header>
    <div class="container header-inner">
        <a class="brand" href="<?= e($home) ?>">
            <span class="brand-mark"><?= icon('printer') ?></span>
            <span class="brand-name"><?= e(site_name()) ?><span class="brand-dot">.</span></span>
        </a>

        <nav class="site-nav" id="site-nav" aria-label="Main">
            <a class="<?= $active === 'home' ? 'is-active' : '' ?>" href="<?= e($home) ?>">Home</a>
            <a href="<?= e($home) ?>#how-it-works">How it works</a>
            <a href="<?= e($home) ?>#printer">The printer</a>
            <a href="<?= e($home) ?>#faq">FAQ</a>
            <div class="nav-actions">
                <?php if ($user !== null): ?>
                    <a class="btn btn-primary btn-block" href="<?= e($user['role'] === 'admin' ? url('admin/index.php') : url('customer/index.php')) ?>">Open dashboard</a>
                <?php else: ?>
                    <a class="btn btn-ghost btn-block" href="<?= e(url('public/login.php')) ?>">Sign in</a>
                    <a class="btn btn-primary btn-block" href="<?= e(url('public/register.php')) ?>">Start printing</a>
                <?php endif; ?>
            </div>
        </nav>

        <div class="header-actions">
            <?php if ($user !== null): ?>
                <a class="btn btn-ghost" href="<?= e($user['role'] === 'admin' ? url('admin/index.php') : url('customer/index.php')) ?>">
                    <?= icon('layers') ?> Dashboard
                </a>
                <a class="avatar avatar-sm" href="<?= e(url('customer/profile.php')) ?>" title="<?= e($user['name']) ?>"><?= e(initials($user['name'])) ?></a>
            <?php else: ?>
                <a class="btn btn-ghost" href="<?= e(url('public/login.php')) ?>">Sign in</a>
                <a class="btn btn-primary" href="<?= e(url('public/register.php')) ?>"><?= icon('upload') ?> Start printing</a>
            <?php endif; ?>
        </div>

        <button class="nav-toggle" type="button" data-nav-toggle aria-controls="site-nav" aria-expanded="false" aria-label="Toggle menu">
            <?= icon('menu') ?>
        </button>
    </div>
</header>
<main class="site-main">
    <?php
}

function site_footer(): void
{
    $home = url('public/index.php');
    ?>
</main>
<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-brand">
            <a class="brand" href="<?= e($home) ?>">
                <span class="brand-mark"><?= icon('printer') ?></span>
                <span class="brand-name"><?= e(site_name()) ?><span class="brand-dot">.</span></span>
            </a>
            <p>Free photo printing on a real Epson ET-2926, powered by the Epson Connect API. No accounts to pay for, no credits, no catch.</p>
            <span class="footer-chip"><?= icon('image') ?> 4 × 6 prints · <?= e(MAX_UPLOAD_MB) ?> MB per photo</span>
        </div>

        <div class="footer-col">
            <h4>Print</h4>
            <a href="<?= e(url('public/register.php')) ?>">Create an account</a>
            <a href="<?= e(url('public/login.php')) ?>">Sign in</a>
            <a href="<?= e($home) ?>#how-it-works">How it works</a>
        </div>

        <div class="footer-col">
            <h4>Service</h4>
            <a href="<?= e($home) ?>#printer">The printer</a>
            <a href="<?= e($home) ?>#faq">FAQ</a>
            <a href="<?= e($home) ?>#promise">Our promise</a>
        </div>

        <div class="footer-col">
            <h4>Contact</h4>
            <a href="mailto:<?= e(setting('support_email', 'hello@printfree.test')) ?>"><?= e(setting('support_email', 'hello@printfree.test')) ?></a>
            <span class="footer-note">Epson ET-2926 · EcoTank</span>
            <span class="footer-note">Epson Connect API v2</span>
        </div>
    </div>
    <div class="container footer-bottom">
        <span>&copy; <?= date('Y') ?> <?= e(site_name()) ?>. Free for everyone, always.</span>
        <span class="footer-status"><span class="status-dot"></span> Printer online</span>
    </div>
</footer>
<script src="<?= e(asset('js/main.js')) ?>" defer></script>
<script src="<?= e(asset('js/forms.js')) ?>" defer></script>
</body>
</html>
    <?php
}

/* -------------------------------------------------------------------------
 |  Dashboard chrome (customer + admin)
 * ---------------------------------------------------------------------- */

function app_start(string $role, string $active, string $title, string $subtitle = ''): void
{
    $user = current_user();
    if ($user === null) {
        redirect('public/login.php');
    }

    $isAdmin = $role === 'admin';
    $items   = $isAdmin
        ? [
            'dashboard' => ['label' => 'Dashboard', 'href' => 'admin/index.php', 'icon' => 'home'],
            'orders'    => ['label' => 'Orders', 'href' => 'admin/orders.php', 'icon' => 'layers'],
            'customers' => ['label' => 'Customers', 'href' => 'admin/customers.php', 'icon' => 'users'],
            'settings'  => ['label' => 'Settings', 'href' => 'admin/settings.php', 'icon' => 'gear'],
        ]
        : [
            'dashboard' => ['label' => 'Dashboard', 'href' => 'customer/index.php', 'icon' => 'home'],
            'new'       => ['label' => 'New order', 'href' => 'customer/new-order.php', 'icon' => 'upload'],
            'orders'    => ['label' => 'My orders', 'href' => 'customer/orders.php', 'icon' => 'layers'],
            'profile'   => ['label' => 'Profile', 'href' => 'customer/profile.php', 'icon' => 'users'],
        ];

    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — <?= e(site_name()) ?></title>
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#f6f8fc">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/dashboard.css')) ?>">
</head>
<body class="app-body">
<?= render_flashes() ?>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand sidebar-brand" href="<?= e(url($isAdmin ? 'admin/index.php' : 'customer/index.php')) ?>">
            <span class="brand-mark"><?= icon('printer') ?></span>
            <span class="brand-name"><?= e(site_name()) ?><span class="brand-dot">.</span></span>
        </a>

        <div class="sidebar-role"><?= $isAdmin ? 'Administrator' : 'Customer area' ?></div>

        <nav class="side-nav" aria-label="Dashboard">
            <?php foreach ($items as $key => $item): ?>
                <a class="side-link <?= $active === $key ? 'is-active' : '' ?>" href="<?= e(url($item['href'])) ?>">
                    <?= icon($item['icon']) ?><span><?= e($item['label']) ?></span>
                    <?php if ($active === $key): ?><span class="side-dot"></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <a class="user-card" href="<?= e(url($isAdmin ? 'admin/settings.php' : 'customer/profile.php')) ?>">
                <span class="avatar"><?= e(initials((string) $user['name'])) ?></span>
                <span class="user-card-text">
                    <strong><?= e($user['name']) ?></strong>
                    <span><?= e($user['email']) ?></span>
                </span>
            </a>
            <form method="post" action="<?= e(url('public/logout.php')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-block btn-sm"><?= icon('logout') ?> Sign out</button>
            </form>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <button type="button" class="icon-btn sidebar-toggle" data-sidebar-toggle aria-label="Toggle menu"><?= icon('menu') ?></button>
            <div class="topbar-title">
                <span class="eyebrow"><?= e($isAdmin ? 'Admin' : 'My printing') ?></span>
                <h1><?= e($title) ?></h1>
            </div>
            <div class="topbar-actions">
                <a class="btn btn-ghost btn-sm topbar-site" href="<?= e(url('public/index.php')) ?>"><?= icon('arrow-left') ?> Back to site</a>
                <a class="avatar avatar-sm" href="<?= e(url($isAdmin ? 'admin/settings.php' : 'customer/profile.php')) ?>"><?= e(initials((string) $user['name'])) ?></a>
            </div>
        </header>

        <main class="app-content">
            <?php if ($subtitle !== ''): ?>
                <p class="page-subtitle"><?= e($subtitle) ?></p>
            <?php endif; ?>
    <?php
}

function app_end(): void
{
    ?>
        </main>
        <footer class="app-footer">
            <span><?= e(site_name()) ?> · <?= e(EPSON_PRINTER_MODEL) ?> · Free printing for everyone</span>
        </footer>
    </div>
</div>
<div class="sidebar-overlay" data-sidebar-overlay></div>
<script src="<?= e(asset('js/main.js')) ?>" defer></script>
<script src="<?= e(asset('js/forms.js')) ?>" defer></script>
<script src="<?= e(asset('js/dashboard.js')) ?>" defer></script>
</body>
</html>
    <?php
}

/* -------------------------------------------------------------------------
 |  Small content components
 * ---------------------------------------------------------------------- */

function page_heading(string $title, string $subtitle = '', string $actions = ''): void
{
    ?>
    <div class="page-head">
        <div>
            <h2><?= e($title) ?></h2>
            <?php if ($subtitle !== ''): ?><p><?= e($subtitle) ?></p><?php endif; ?>
        </div>
        <?php if ($actions !== ''): ?><div class="page-head-actions"><?= $actions ?></div><?php endif; ?>
    </div>
    <?php
}

function stat_card(string $label, string $value, string $iconName, string $tone = 'brand', string $hint = ''): void
{
    ?>
    <div class="stat-card tone-<?= e($tone) ?>">
        <div class="stat-icon"><?= icon($iconName) ?></div>
        <div class="stat-body">
            <span class="stat-label"><?= e($label) ?></span>
            <strong class="stat-value"><?= e($value) ?></strong>
            <?php if ($hint !== ''): ?><span class="stat-hint"><?= e($hint) ?></span><?php endif; ?>
        </div>
    </div>
    <?php
}

function empty_state(string $iconName, string $title, string $text, string $action = ''): void
{
    ?>
    <div class="empty-state">
        <span class="empty-icon"><?= icon($iconName) ?></span>
        <h3><?= e($title) ?></h3>
        <p><?= e($text) ?></p>
        <?= $action ?>
    </div>
    <?php
}
