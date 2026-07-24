<?php
/**
 * modules/services/views/index.php — public services listing.
 * @var array $services
 */
$services = $services ?? [];
?>
<div class="container section">
    <span class="eyebrow">Features</span>
    <h1 class="h-page">Everything a real site needs, built in</h1>
    <p class="lead" style="margin-top:16px;max-width:56ch">PlugPHP ships with the plumbing already solved — secure, fast, and SEO-ready from the first request. Here's what comes in the box.</p>

    <?php if ($services === []): ?>
        <p class="muted" style="margin-top:36px">No services to show yet.</p>
    <?php else: ?>
        <div class="grid grid--services-lg" style="margin-top:36px">
            <?php foreach ($services as $s): ?>
                <a class="card card-link" href="/services/<?= e($s['slug']) ?>">
                    <span class="card__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.4"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/></svg></span>
                    <h2 style="font-weight:700;font-size:19px;margin-top:16px;color:var(--ink)"><?= e($s['title']) ?></h2>
                    <?php if (!empty($s['summary'])): ?><p><?= e($s['summary']) ?></p><?php endif; ?>
                    <span class="card__more">Learn more &rarr;</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
