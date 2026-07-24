<?php
/**
 * modules/services/views/show.php — single service detail.
 * SEO injected by the module via $headExtra — no meta tags here.
 * @var array $service
 */
?>
<div class="container container--prose section">
    <a class="backlink" href="/services">&larr; All services</a>

    <span class="card__icon" style="margin-top:24px"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/></svg></span>

    <h1 style="font-weight:800;font-size:clamp(30px,4.2vw,42px);letter-spacing:-.03em;margin-top:18px"><?= e($service['title']) ?></h1>

    <?php if (!empty($service['summary'])): ?>
        <p style="font-size:clamp(17px,2.2vw,20px);line-height:1.55;color:var(--body-2);margin-top:16px"><?= e($service['summary']) ?></p>
    <?php endif; ?>

    <?php if (!empty($service['description'])): ?>
        <div class="prose" style="margin-top:28px"><?= nl2br(e($service['description'])) ?></div>
    <?php endif; ?>

    <a class="btn btn-cta" style="margin-top:32px" href="/contact">Enquire about this service
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
    </a>
</div>
