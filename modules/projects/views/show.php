<?php
/**
 * modules/projects/views/show.php — single case study.
 * SEO injected by the module via $headExtra.
 * @var array             $project
 * @var array<int,string> $gallery
 */
$gallery = $gallery ?? [];
?>
<article>
    <div class="container" style="max-width:900px;padding-top:clamp(40px,5vw,64px)">
        <a class="backlink" href="/projects">&larr; All projects</a>
        <div style="display:flex;align-items:baseline;gap:14px;flex-wrap:wrap;margin-top:22px">
            <h1 style="font-weight:800;font-size:clamp(30px,4.4vw,48px);letter-spacing:-.03em"><?= e($project['title']) ?></h1>
            <?php if (!empty($project['client_name'])): ?><span style="font-family:var(--font-mono);font-size:13px;color:var(--accent)">Client · <?= e($project['client_name']) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($project['summary'])): ?>
            <p style="font-size:clamp(17px,2.2vw,20px);line-height:1.5;color:var(--body-2);margin-top:16px;max-width:60ch"><?= e($project['summary']) ?></p>
        <?php endif; ?>
    </div>

    <div class="container" style="margin-top:28px">
        <div class="ratio ratio-16x9">
            <?php if (!empty($project['featured_image'])): ?>
                <img src="<?= e($project['featured_image']) ?>" alt="<?= e($project['title']) ?>" loading="lazy">
            <?php else: ?>
                <span class="img-slot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg><span>Featured · 16:9</span></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="container container--prose" style="padding-top:36px">
        <?php if (!empty($project['description'])): ?>
            <div class="prose"><?= nl2br(e($project['description'])) ?></div>
        <?php endif; ?>
        <?php if (!empty($project['project_url'])): ?>
            <a class="btn btn-secondary" style="margin-top:26px" href="<?= e($project['project_url']) ?>" rel="noopener noreferrer" target="_blank">Visit project
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M8 7h9v9"/></svg>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($gallery !== []): ?>
        <div class="container" style="padding-top:40px;padding-bottom:clamp(40px,6vw,72px)">
            <span class="eyebrow">Gallery</span>
            <div class="grid grid--gallery" style="margin-top:16px">
                <?php foreach ($gallery as $i => $img): ?>
                    <div class="ratio ratio-1x1">
                        <img src="<?= e($img) ?>" alt="<?= e($project['title']) ?> — image <?= e((string) ($i + 1)) ?>" loading="lazy">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</article>
