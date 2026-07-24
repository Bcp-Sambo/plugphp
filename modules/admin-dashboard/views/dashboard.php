<?php
/**
 * modules/admin-dashboard/views/dashboard.php — dashboard home.
 * @var array $stats           [['label'=>, 'count'=>, 'url'=>], ...]
 * @var array $recentMessages  recent contact_submissions (may be empty)
 */
$stats = $stats ?? [];
$recentMessages = $recentMessages ?? [];
?>
<?php if ($stats === []): ?>
    <p class="muted">No module data to show yet.</p>
<?php else: ?>
    <div class="grid grid--stats">
        <?php foreach ($stats as $s): ?>
            <?php if (!empty($s['url'])): ?>
                <a class="stat-card" href="<?= e($s['url']) ?>">
                    <div class="stat-card__label"><?= e($s['label']) ?></div>
                    <div class="stat-card__count"><?= e((string) $s['count']) ?></div>
                </a>
            <?php else: ?>
                <div class="stat-card">
                    <div class="stat-card__label"><?= e($s['label']) ?></div>
                    <div class="stat-card__count"><?= e((string) $s['count']) ?></div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($recentMessages !== []): ?>
    <div class="panel">
        <h2>Recent messages</h2>
        <div style="margin-top:14px;display:flex;flex-direction:column">
            <?php foreach ($recentMessages as $m): ?>
                <div style="display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid var(--line-2)">
                    <span style="font-weight:600;font-size:14px;flex:0 0 130px"><?= e($m['name']) ?></span>
                    <span style="font-size:13.5px;color:var(--muted-2);flex:1 1 auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($m['message']) ?></span>
                    <span style="font-family:var(--font-mono);font-size:12px;color:var(--muted);flex:0 0 auto"><?= e(date('M j', strtotime((string) $m['created_at']))) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
