<?php
/**
 * modules/blog/views/show.php — single published post.
 * SEO injected by the module via $headExtra.
 * Body rendered ESCAPED (nl2br(e())) — admin HTML is not trusted.
 * @var array $post
 */
?>
<article>
    <div class="container" style="max-width:680px;padding-top:clamp(40px,5vw,64px)">
        <a class="backlink" href="/blog">&larr; All posts</a>
        <?php if (!empty($post['published_at'])): ?>
            <time datetime="<?= e(date('c', strtotime((string) $post['published_at']))) ?>" style="display:block;font-family:var(--font-mono);font-size:13px;color:var(--accent);margin-top:24px"><?= e(date('F j, Y', strtotime((string) $post['published_at']))) ?></time>
        <?php endif; ?>
        <h1 style="font-weight:800;font-size:clamp(30px,4.4vw,46px);line-height:1.1;letter-spacing:-.03em;margin-top:10px;text-wrap:balance"><?= e($post['title']) ?></h1>
    </div>

    <div class="container" style="max-width:900px;margin-top:28px">
        <div class="ratio ratio-16x9">
            <?php if (!empty($post['featured_image'])): ?>
                <img src="<?= e($post['featured_image']) ?>" alt="<?= e($post['title']) ?>" loading="lazy">
            <?php else: ?>
                <span class="img-slot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg><span>Featured · 16:9</span></span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($post['body'])): ?>
        <div class="prose prose--lg" style="max-width:65ch;margin:0 auto;padding:36px 24px clamp(48px,7vw,80px)"><?= nl2br(e($post['body'])) ?></div>
    <?php endif; ?>
</article>
