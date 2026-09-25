<?php
/**
 * modules/admin-dashboard/views/updates.php — /admin/updates
 *
 * A developer may restyle this freely, like any view. Do not remove the CSRF
 * token from either form, and do not turn either POST into a GET.
 *
 * @var string      $currentVersion
 * @var string      $recordedVersion
 * @var bool        $versionsAgree
 * @var array|null  $manifest
 * @var bool        $checkFailed
 * @var bool        $updateAvailable
 * @var array       $preflight        [['name'=>, 'status'=>, 'detail'=>], ...]
 * @var bool        $rollbackOffered
 * @var int|null    $lastUpdatedAt
 * @var int         $rollbackWindow
 * @var array|null  $result           outcome of an apply/rollback just run
 */
$csrf = Auth::csrfToken();
$blocked = false;
foreach ($preflight as $c) {
    if ($c['status'] === 'FAIL') { $blocked = true; break; }
}
?>

<?php if ($result !== null): ?>
    <div class="pp-note <?= $result['success'] ? 'pp-note--ok' : 'pp-note--bad' ?>">
        <h2><?= $result['success'] ? 'Finished' : 'Did not complete' ?></h2>
        <ol class="pp-log">
            <?php foreach ($result['log'] as $line): ?>
                <li><?= e($line) ?></li>
            <?php endforeach; ?>
        </ol>
    </div>
<?php endif; ?>

<?php if ($manifest !== null && !empty($manifest['security_advisory']) && $updateAvailable): ?>
    <div class="pp-note pp-note--urgent">
        <h2>Security update available</h2>
        <p>
            Version <?= e($manifest['version']) ?> is a security release. Apply it as
            soon as you can — sites left on <?= e($currentVersion) ?> stay exposed to
            whatever it fixes.
        </p>
    </div>
<?php endif; ?>

<div class="pp-panel">
    <h2>Version</h2>
    <p>
        This site is running <strong>PlugPHP <?= e($currentVersion) ?></strong>.
    </p>

    <?php if (!$versionsAgree): ?>
        <p class="pp-warn">
            The database records <?= e($recordedVersion) ?>, which does not match the
            files on disk. An update applied its files but did not finish its database
            changes. Finishing it runs only the outstanding migrations — it downloads
            nothing and replaces no file.
        </p>
        <form method="post" action="<?= url('/admin/updates/finish') ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <button class="btn btn-primary btn-sm" type="submit">Finish the interrupted update</button>
        </form>
    <?php endif; ?>

    <?php if ($checkFailed): ?>
        <p class="pp-warn">
            Could not reach the update server just now. That does not affect your site —
            it only means the latest version is unknown. Try again in a few minutes.
        </p>
    <?php elseif ($manifest === null): ?>
        <p class="muted">Click below to see whether a newer version has been released.</p>
    <?php elseif ($updateAvailable): ?>
        <p>
            <strong>Version <?= e($manifest['version']) ?> is available.</strong>
            <?php if ($manifest['changelog_url'] !== ''): ?>
                <a href="<?= url($manifest['changelog_url']) ?>" target="_blank" rel="noopener noreferrer">
                    Read what changed
                </a> before you update.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <p>You are on the latest version.</p>
    <?php endif; ?>

    <form method="post" action="<?= url('/admin/updates/check') ?>" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="btn btn-secondary btn-sm" type="submit">Check for updates</button>
    </form>
</div>

<div class="pp-panel">
    <h2>Before updating</h2>
    <p class="muted">
        These are checked again automatically when you click Update, and the update
        stops before downloading anything if any of them fail.
    </p>
    <ul class="pp-checks">
        <?php foreach ($preflight as $c): ?>
            <li>
                <span class="pp-tag pp-tag--<?= e(strtolower($c['status'])) ?>"><?= e($c['status']) ?></span>
                <span class="pp-check__name"><?= e($c['name']) ?></span>
                <?php if ($c['status'] !== 'PASS'): ?>
                    <div class="pp-check__detail"><?= e($c['detail']) ?></div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="pp-panel">
    <h2>Update</h2>
    <p class="muted">
        An update replaces PlugPHP's own code only. Your page designs
        (<code>resources/</code> and every module's <code>views/</code>), your
        <code>.env</code>, your enabled-module list and your <code>.htaccess</code>
        files are never touched. The current version of every file being replaced is
        backed up first, and can be restored from here for
        <?= e((string) $rollbackWindow) ?> days.
    </p>

    <?php if ($blocked): ?>
        <p class="pp-warn">
            Updating is blocked until the failing checks above are resolved.
        </p>
    <?php elseif (!$updateAvailable): ?>
        <p class="muted">
            Nothing to install right now. Run a check first if you want to be sure.
        </p>
    <?php else: ?>
        <form method="post" action="<?= url('/admin/updates/apply') ?>"
              onsubmit="return confirm('Apply update <?= e($manifest['version']) ?> now? Your designs and settings are not affected.');">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <button class="btn btn-primary" type="submit">
                Update now to <?= e($manifest['version']) ?>
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if ($rollbackOffered): ?>
<div class="pp-panel">
    <h2>Roll back the last update</h2>
    <p class="muted">
        <?php if ($lastUpdatedAt !== null): ?>
            Last updated <?= e(date('j M Y \a\t H:i', $lastUpdatedAt)) ?>.
        <?php endif; ?>
        Restores the code files from the backup taken just before that update.
        Database changes are not reversed — migrations only ever move forward, and
        undoing them would destroy data. New columns simply go unused.
    </p>
    <form method="post" action="<?= url('/admin/updates/rollback') ?>"
          onsubmit="return confirm('Restore the previous version of PlugPHP\'s code files?');">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="btn btn-secondary" type="submit">Roll back</button>
    </form>
</div>
<?php endif; ?>

<style>
/* Scoped to this panel so restyling the admin theme does not have to fight it. */
.pp-panel { background:#fff; border:1px solid #e3e6ea; border-radius:10px; padding:18px 20px; margin-bottom:16px; }
.pp-panel h2 { font-size:1.05rem; margin:0 0 .5rem; }
.pp-note { border-radius:10px; padding:16px 20px; margin-bottom:16px; border:1px solid; }
.pp-note h2 { font-size:1.05rem; margin:0 0 .5rem; }
.pp-note--ok { background:#e9f7ee; border-color:#bfe3cc; color:#12331f; }
.pp-note--bad { background:#fdeeee; border-color:#f0c9c9; color:#4a1414; }
.pp-note--urgent { background:#fdeeee; border-color:#e28b8b; color:#4a1414; }
.pp-log { margin:.4rem 0 0 1.1rem; padding:0; font-size:14px; line-height:1.6; }
.pp-warn { color:#8a5a08; }
.pp-checks { list-style:none; margin:.6rem 0 0; padding:0; font-size:14px; }
.pp-checks li { padding:.35rem 0; border-top:1px solid #eef0f3; }
.pp-checks li:first-child { border-top:0; }
.pp-tag { display:inline-block; min-width:44px; text-align:center; font-size:11px; font-weight:700;
          padding:.15rem .4rem; border-radius:4px; margin-right:.5rem; }
.pp-tag--pass { background:#e6f6ec; color:#136c34; }
.pp-tag--warn { background:#fdf3e0; color:#8a5a08; }
.pp-tag--fail { background:#fdeaea; color:#a11c1c; }
.pp-tag--info { background:#eaeff7; color:#2a4b7c; }
.pp-check__detail { margin:.2rem 0 .1rem 3.2rem; color:#5b6270; }
</style>
