<?php
/**
 * modules/admin-dashboard/views/layout.php
 *
 * The admin shell — a complete HTML document wrapped around every /admin/*
 * page via AdminDashboardModule::renderAdmin(). Separate from the public
 * layout. Reskin freely; do NOT hardcode a module's nav entry — the sidebar
 * loops over $navItems so modules stay pluggable.
 *
 * @var string $content
 * @var array  $navItems  [['label'=>..,'url'=>..], ...]
 * @var string $pageTitle
 */
$pageTitle = $pageTitle ?? 'Admin';
$navItems = $navItems ?? [];
$brandName = Config::get('APP_NAME', 'PlugPHP');
$currentPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
$isCurrent = static function (string $url) use ($currentPath): bool {
    $u = rtrim($url, '/') ?: '/';
    if ($currentPath === $u) {
        return true;
    }
    // Prefix-match only section roots deeper than /admin, so the Dashboard
    // ("/admin") doesn't light up on every /admin/* page.
    return $u !== '/admin' && str_starts_with($currentPath, $u . '/');
};
$mark = '<img class="brand__mark" src="/assets/img/logo.png" alt="" width="26" height="26">';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> — Admin</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <a class="skip-link" href="#admin-main">Skip to content</a>
    <div class="admin">
        <aside class="admin-sidebar">
            <div class="admin-sidebar__brand"><?= $mark ?><span style="color:var(--bg)"><?= e($brandName) ?></span><span class="admin-sidebar__tag">Admin</span></div>
            <nav class="admin-nav" aria-label="Admin">
                <?php foreach ($navItems as $item): ?>
                    <a href="<?= e($item['url']) ?>"<?= $isCurrent($item['url']) ? ' aria-current="page"' : '' ?>><span class="dot"></span><?= e($item['label']) ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="admin-sidebar__foot">
                <form method="post" action="/logout">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <button class="admin-logout" type="submit">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                        Log out
                    </button>
                </form>
            </div>
        </aside>

        <div class="admin-body">
            <header class="admin-topbar">
                <input type="checkbox" id="admin-nav-toggle" class="nav-toggle" aria-hidden="true" tabindex="-1">
                <div class="admin-topbar__inner">
                    <label for="admin-nav-toggle" class="admin-burger" aria-label="Toggle menu"><span></span><span></span><span></span></label>
                    <h1><?= e($pageTitle) ?></h1>
                    <a class="admin-topbar__view" href="/">View site &nearr;</a>
                </div>
                <nav class="admin-nav--mobile" aria-label="Admin">
                    <?php foreach ($navItems as $item): ?>
                        <a href="<?= e($item['url']) ?>"<?= $isCurrent($item['url']) ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
                    <?php endforeach; ?>
                </nav>
            </header>

            <main id="admin-main" class="admin-main">
                <?= $content ?>
            </main>

            <footer class="admin-footer">Built with PlugPHP — by Kabiru Sambo / Bubble Bot Solutions</footer>
        </div>
    </div>
</body>
</html>
