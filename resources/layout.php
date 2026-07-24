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
$pageTitle = $pageTitle ?? Config::get('APP_NAME', 'Site');
$metaDescription = $metaDescription ?? '';
$headExtra = $headExtra ?? '';
$brandName = Config::get('APP_NAME', 'PlugPHP');
// A view may set $bareLayout = true to render without the public header/footer
// (used for the centered auth screens). The view then owns the full chrome.
$bareLayout = $bareLayout ?? false;
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
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <a class="skip-link" href="#pp-main">Skip to content</a>

    <?php if ($bareLayout): ?>
        <main id="pp-main"><?= $content ?></main>
    <?php else: ?>

    <header class="site-header">
        <input type="checkbox" id="nav-toggle" class="nav-toggle" aria-hidden="true" tabindex="-1">
        <div class="container site-header__bar">
            <a class="brand" href="/">
                <img class="brand__mark" src="/assets/img/logo.png" alt="" width="30" height="30">
                <span><?= e($brandName) ?></span>
            </a>
            <nav class="nav" aria-label="Primary">
                <a href="/">Home</a>
                <a href="/about">About</a>
                <a href="/services">Services</a>
                <a href="/projects">Projects</a>
                <a href="/blog">Blog</a>
                <a class="btn btn-primary" href="/contact">Contact</a>
            </nav>
            <label for="nav-toggle" class="nav-burger" aria-label="Toggle menu">
                <span></span><span></span><span></span>
            </label>
        </div>
        <nav class="nav--mobile" aria-label="Mobile">
            <a href="/">Home</a>
            <a href="/about">About</a>
            <a href="/services">Services</a>
            <a href="/projects">Projects</a>
            <a href="/blog">Blog</a>
            <a class="btn btn-primary btn-block" href="/contact">Contact</a>
        </nav>
    </header>

    <main id="pp-main">
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <div class="container site-footer__grid">
            <div>
                <div class="site-footer__brand">
                    <img class="brand__mark" src="/assets/img/logo.png" alt="" width="26" height="26">
                    <span><?= e($brandName) ?></span>
                </div>
                <p>A modular, agent-ready PHP starter kit for shared hosting — secure and SEO-ready by default.</p>
            </div>
            <div class="site-footer__col">
                <h2>Site</h2>
                <a href="/about">About</a>
                <a href="/services">Services</a>
                <a href="/projects">Projects</a>
                <a href="/blog">Blog</a>
            </div>
            <div class="site-footer__col">
                <h2>Get in touch</h2>
                <a href="/contact">Contact</a>
                <a class="site-footer__admin" href="/login">Admin log in &rarr;</a>
            </div>
        </div>
        <div class="site-footer__bar">
            <div class="container">&copy; <?= e(date('Y')) ?> <?= e($brandName) ?>. All rights reserved.</div>
        </div>
    </footer>
    <?php endif; ?>
</body>
</html>
