<?php
/**
 * modules/home/views/home.php — landing page.
 * Styled per the PlugPHP design system. SEO is injected by the module.
 *
 * @var string $appName
 * @var array  $services  up to 3 service teasers (may be empty)
 * @var array  $posts     up to 3 latest post teasers (may be empty)
 */
$appName = $appName ?? 'Home';
$services = $services ?? [];
$posts = $posts ?? [];
?>
<section class="container section section--hero">
    <span class="eyebrow">00 / Starter kit</span>
    <div class="hero-grid">
        <div>
            <h1 class="h-display" style="margin-top:18px">A modular, agent-ready PHP starter kit.</h1>
            <p class="lead" style="margin-top:20px">Build real, secure, SEO-ready websites on the cheapest shared hosting — with a web installer, an admin dashboard, and no framework to learn. This whole site is the default PlugPHP template.</p>
            <div class="btn-row">
                <a class="btn btn-cta" href="/contact">Get started
                    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
                <a class="btn btn-secondary" href="/projects">See what you can build</a>
            </div>
        </div>
        <div class="ratio ratio-16x9">
            <span class="img-slot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg><span>Hero · 16:9</span></span>
        </div>
    </div>
</section>

<?php if ($services !== []): ?>
<section class="section section--divider">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="eyebrow">01 / Features</span>
                <h2 class="h-section">What you get</h2>
            </div>
            <a class="textlink" href="/services">All features &rarr;</a>
        </div>
        <div class="grid grid--services">
            <?php foreach ($services as $i => $s): ?>
                <a class="card card-link" href="/services/<?= e($s['slug']) ?>">
                    <span class="card__icon card__icon--num"><?= e(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                    <h3><?= e($s['title']) ?></h3>
                    <?php if (!empty($s['summary'])): ?><p><?= e($s['summary']) ?></p><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($posts !== []): ?>
<section class="section section--tint">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="eyebrow">02 / Journal</span>
                <h2 class="h-section">Latest from the blog</h2>
            </div>
            <a class="textlink" href="/blog">All posts &rarr;</a>
        </div>
        <div class="grid grid--posts">
            <?php foreach ($posts as $p): ?>
                <a href="/blog/<?= e($p['slug']) ?>" style="display:block">
                    <div class="ratio ratio-16x9">
                        <span class="img-slot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg><span>16:9</span></span>
                    </div>
                    <?php if (!empty($p['published_at'])): ?>
                        <time datetime="<?= e(date('c', strtotime((string) $p['published_at']))) ?>" style="display:block;font-family:var(--font-mono);font-size:12px;color:var(--muted);margin-top:14px"><?= e(date('M j, Y', strtotime((string) $p['published_at']))) ?></time>
                    <?php endif; ?>
                    <h3 style="font-weight:700;font-size:18px;line-height:1.3;margin-top:6px;color:var(--ink)"><?= e($p['title']) ?></h3>
                    <?php if (!empty($p['excerpt'])): ?><p style="font-size:14.5px;line-height:1.55;color:var(--muted-2);margin-top:7px"><?= e($p['excerpt']) ?></p><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
