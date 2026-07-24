<?php
/**
 * modules/projects/views/index.php — public portfolio grid.
 * @var array $projects
 */
$projects = $projects ?? [];
?>
<div class="container section">
    <span class="eyebrow">Projects</span>
    <h1 class="h-page">Selected work</h1>
    <p class="lead" style="margin-top:16px;max-width:56ch">A portfolio of sites shipped on PlugPHP. Every one runs on shared hosting.</p>

    <?php if ($projects === []): ?>
        <p class="muted" style="margin-top:36px">No projects to show yet.</p>
    <?php else: ?>
        <div class="grid grid--projects" style="margin-top:36px">
            <?php foreach ($projects as $p): ?>
                <a href="/projects/<?= e($p['slug']) ?>" style="display:block">
                    <div class="ratio ratio-4x3">
                        <?php if (!empty($p['featured_image'])): ?>
                            <img src="<?= e($p['featured_image']) ?>" alt="<?= e($p['title']) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="img-slot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg><span>Featured · 4:3</span></span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-top:14px">
                        <h2 style="font-weight:700;font-size:19px;color:var(--ink)"><?= e($p['title']) ?></h2>
                        <?php if (!empty($p['client_name'])): ?><span style="font-family:var(--font-mono);font-size:12px;color:var(--muted);white-space:nowrap"><?= e($p['client_name']) ?></span><?php endif; ?>
                    </div>
                    <?php if (!empty($p['summary'])): ?><p style="font-size:14.5px;line-height:1.55;color:var(--muted-2);margin-top:6px"><?= e($p['summary']) ?></p><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
