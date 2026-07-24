<?php
/**
 * modules/blog/views/index.php — public paginated post list.
 * @var array $posts
 * @var int   $page
 * @var int   $totalPages
 */
$posts = $posts ?? [];
$page = $page ?? 1;
$totalPages = $totalPages ?? 1;
?>
<div class="container container--narrow section">
    <span class="eyebrow">Blog</span>
    <h1 class="h-page" style="margin-bottom:24px">Notes &amp; field reports</h1>

    <?php if ($posts === []): ?>
        <p class="muted">No posts published yet.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:28px">
            <?php foreach ($posts as $p): ?>
                <a class="blog-list__item" href="/blog/<?= e($p['slug']) ?>">
                    <div class="ratio ratio-16x9">
                        <?php if (!empty($p['featured_image'])): ?>
                            <img src="<?= e($p['featured_image']) ?>" alt="<?= e($p['title']) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="img-slot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg><span>16:9</span></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if (!empty($p['published_at'])): ?>
                            <time datetime="<?= e(date('c', strtotime((string) $p['published_at']))) ?>" style="font-family:var(--font-mono);font-size:12px;color:var(--muted)"><?= e(date('M j, Y', strtotime((string) $p['published_at']))) ?></time>
                        <?php endif; ?>
                        <h2 style="font-weight:700;font-size:20px;line-height:1.3;margin-top:6px;color:var(--ink)"><?= e($p['title']) ?></h2>
                        <?php if (!empty($p['excerpt'])): ?><p style="font-size:14.5px;line-height:1.55;color:var(--muted-2);margin-top:8px"><?= e($p['excerpt']) ?></p><?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pager" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a class="textlink" href="/blog?page=<?= e((string) ($page - 1)) ?>" rel="prev">&larr; Newer</a>
                <?php else: ?>
                    <span class="pager__disabled">&larr; Newer</span>
                <?php endif; ?>
                <span class="pager__page">Page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="textlink" href="/blog?page=<?= e((string) ($page + 1)) ?>" rel="next">Older &rarr;</a>
                <?php else: ?>
                    <span class="pager__disabled">Older &rarr;</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
