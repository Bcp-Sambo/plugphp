<?php
/**
 * resources/404.php — default not-found view, rendered through View::render()
 * so it inherits the site layout. Restyle freely; keep the attribution line.
 */
$pageTitle = 'Page Not Found';
$metaDescription = 'The page you were looking for could not be found.';
?>
<div class="notfound">
    <div class="notfound__code">404</div>
    <h1 style="font-weight:800;font-size:clamp(24px,3.4vw,32px);letter-spacing:-.02em;margin-top:8px">This page unplugged itself</h1>
    <p style="font-size:16px;line-height:1.6;color:var(--muted-2);margin:14px auto 0;max-width:40ch">We couldn't find what you were looking for. It may have moved or never existed.</p>
    <a class="btn btn-primary" style="margin-top:26px" href="/">&larr; Back home</a>
    <p style="margin-top:48px;font-size:13px;color:var(--muted)">Built with PlugPHP — by Kabiru Sambo / Bubble Bot Solutions</p>
</div>
