<?php
/**
 * resources/layout.php
 *
 * The public page shell wrapped around every view rendered via
 * View::render(). Lives OUTSIDE core/ deliberately — restyle freely.
 *
 * $content is provided by View::render(). $pageTitle / $metaDescription /
 * $headExtra are optional and set by the view (or the module) beforehand.
 */
$brandName = Branding::siteName();
$pageTitle = $pageTitle ?? $brandName;
// A view that set its own description wins; the site-wide one from the
// dashboard is the fallback for pages that did not set one.
$metaDescription = $metaDescription ?? Branding::description();
$headExtra = $headExtra ?? '';
$siteLogo = Branding::logo();
$favicon = Branding::favicon();
// Modules emit their own og:image for a post's featured image. Only fall
// back to the site default when the page did not provide one.
$fallbackOgImage = str_contains($headExtra, 'og:image') ? null : Branding::ogImageAbsolute();
// A view may set $bareLayout = true to render without the public header/footer
// (used for the centered auth screens). The view then owns the full chrome.
$bareLayout = $bareLayout ?? false;

// Nav is built from the enabled modules, so hiding a module from the
// dashboard removes its links here too. Never hardcode a module's link
// below — a hardcoded link outlives the module being disabled and sends
// visitors to a 404.
$navItems    = Nav::publicItems();
$footerItems = array_values(array_filter(Nav::secondaryItems(), fn($i) => $i['url'] !== '/'));
$primaryItem = Nav::primaryItem();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <?php if ($metaDescription): ?>
    <meta name="description" content="<?= e($metaDescription) ?>">
    <?php endif; ?>
    <?= $headExtra /* canonical / Open Graph / JSON-LD, pre-escaped by the module */ ?>
    <?php if ($fallbackOgImage !== null): ?>
    <meta property="og:image" content="<?= e($fallbackOgImage) ?>">
    <?php endif; ?>
    <?php if ($favicon !== null): ?>
    <link rel="icon" href="<?= asset($favicon) ?>">
    <?php endif; ?>
    <?= Tracking::headSnippet() /* GA4; empty unless enabled + a valid ID is saved */ ?>
    <link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body>
<?= Tracking::bodySnippet() /* Facebook Pixel; empty unless enabled + a valid ID is saved */ ?>
    <a class="skip-link" href="#pp-main">Skip to content</a>

    <?php if ($bareLayout): ?>
        <main id="pp-main"><?= $content ?></main>
    <?php else: ?>

    <header class="site-header">
        <input type="checkbox" id="nav-toggle" class="nav-toggle" aria-hidden="true" tabindex="-1">
        <div class="container site-header__bar">
            <a class="brand" href="<?= url('/') ?>">
                <img class="brand__mark" src="<?= asset($siteLogo) ?>" alt="" width="30" height="30">
                <span><?= e($brandName) ?></span>
            </a>
            <nav class="nav" aria-label="Primary">
                <?php foreach ($navItems as $item): ?>
                    <a<?= $item['primary'] ? ' class="btn btn-primary"' : '' ?> href="<?= url($item['url']) ?>"><?= e($item['label']) ?></a>
                <?php endforeach; ?>
            </nav>
            <label for="nav-toggle" class="nav-burger" aria-label="Toggle menu">
                <span></span><span></span><span></span>
            </label>
        </div>
        <nav class="nav--mobile" aria-label="Mobile">
            <?php foreach ($navItems as $item): ?>
                <a<?= $item['primary'] ? ' class="btn btn-primary btn-block"' : '' ?> href="<?= url($item['url']) ?>"><?= e($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>
    </header>

    <main id="pp-main">
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <div class="container site-footer__grid">
            <div>
                <div class="site-footer__brand">
                    <img class="brand__mark" src="<?= asset($siteLogo) ?>" alt="" width="26" height="26">
                    <span><?= e($brandName) ?></span>
                </div>
                <p>A modular, agent-ready PHP starter kit for shared hosting — secure and SEO-ready by default.</p>
            </div>
            <div class="site-footer__col">
                <h2>Site</h2>
                <?php foreach ($footerItems as $item): ?>
                    <a href="<?= url($item['url']) ?>"><?= e($item['label']) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="site-footer__col">
                <h2>Get in touch</h2>
                <?php if ($primaryItem !== null): ?>
                    <a href="<?= url($primaryItem['url']) ?>"><?= e($primaryItem['label']) ?></a>
                <?php endif; ?>
                <a class="site-footer__admin" href="<?= url('/login') ?>">Admin log in &rarr;</a>
            </div>
        </div>
        <div class="site-footer__bar">
            <div class="container">&copy; <?= e(date('Y')) ?> <?= e($brandName) ?>. All rights reserved.</div>
        </div>
    </footer>
    <?php endif; ?>
</body>
</html>
