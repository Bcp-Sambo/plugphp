<?php
/**
 * modules/admin-dashboard/views/modules.php — /admin/modules
 *
 * Restyle freely. Do not remove a csrf_token field, do not turn any of these
 * POSTs into a GET, and do not filter the module list by enabled state — this
 * screen is the only place a disabled module stays visible, and it is how one
 * gets switched back on.
 *
 * @var array $modules   [['name','enabled','always_on','manifest','summary'], ...]
 * @var array $ignored   module names deleted on purpose
 * @var array $activity  SecurityLog::recent()
 * @var array $flash     ['errors' => string[], 'success' => string]
 */
$csrf = Auth::csrfToken();
?>

<?php if ($flash['success'] !== ''): ?>
    <div class="pp-note pp-note--ok"><?= e($flash['success']) ?></div>
<?php endif; ?>

<?php if ($flash['errors'] !== []): ?>
    <div class="pp-note pp-note--bad">
        <strong>Not done:</strong>
        <ul><?php foreach ($flash['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="pp-panel">
    <h2>Modules</h2>
    <p>Every module present on this site, whether it is switched on or not — including
       any that arrived with an update but were never activated. Nothing here activates
       itself: a module shipping in a release stays off until you turn it on.</p>
    <p class="mm-note">Each module states what it touches. Those are declarations made by
       the module so you can see what you are switching on. They are not enforced
       restrictions, and nothing stops a module doing more than it says.</p>

    <div class="mm-list">
    <?php foreach ($modules as $m): ?>
        <div class="mm-item<?= $m['enabled'] ? '' : ' mm-item--off' ?>">
            <div class="mm-item__head">
                <span class="mm-item__name"><?= e(ucfirst(str_replace('-', ' ', $m['name']))) ?></span>
                <code class="mm-item__slug"><?= e($m['name']) ?></code>
                <?php if ($m['always_on']): ?>
                    <span class="mm-tag mm-tag--core">Required</span>
                <?php elseif ($m['enabled']): ?>
                    <span class="mm-tag mm-tag--on">Enabled</span>
                <?php else: ?>
                    <span class="mm-tag mm-tag--off">Disabled</span>
                <?php endif; ?>
            </div>

            <?php if ($m['summary'] !== ''): ?>
                <p class="mm-item__cap"><?= e($m['summary']) ?></p>
            <?php else: ?>
                <p class="mm-item__cap mm-item__cap--none">No manifest.json — this module does not
                   declare what it touches.</p>
            <?php endif; ?>

            <?php if ($m['manifest'] !== null && $m['manifest']['public_routes'] !== []): ?>
                <p class="mm-item__routes">Public:
                    <?php foreach ($m['manifest']['public_routes'] as $r): ?><code><?= e($r) ?></code> <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <div class="mm-item__acts">
            <?php if ($m['always_on']): ?>
                <span class="mm-item__locked">The site cannot be administered without this,
                    so it cannot be switched off.</span>
            <?php elseif ($m['enabled']): ?>
                <form method="post" action="<?= url('/admin/modules/disable') ?>"
                      data-confirm="Disable <?= e($m['name']) ?>? Its pages stop working, but its files and data are untouched and you can switch it back on here.">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="module" value="<?= e($m['name']) ?>">
                    <button class="btn btn-secondary" type="submit">Disable</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= url('/admin/modules/enable') ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="module" value="<?= e($m['name']) ?>">
                    <button class="btn btn-primary" type="submit">Enable</button>
                </form>
                <form method="post" action="<?= url('/admin/modules/remove') ?>"
                      data-confirm="Delete <?= e($m['name']) ?> permanently? This is NOT the same as disabling. Its folder is deleted and future updates will not put it back. Getting it back means uploading the folder by hand.">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="module" value="<?= e($m['name']) ?>">
                    <button class="btn btn-danger" type="submit">Remove permanently</button>
                </form>
            <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<?php if ($ignored !== []): ?>
<div class="pp-panel">
    <h2>Deleted on purpose</h2>
    <p>These were removed permanently, so updates will not reinstate them. To bring one
       back, upload its folder into <code>modules/</code> again — it will reappear above
       once its files are present.</p>
    <p><?php foreach ($ignored as $i): ?><code><?= e($i) ?></code> <?php endforeach; ?></p>
</div>
<?php endif; ?>

<div class="pp-panel">
    <h2>Recent activity</h2>
    <p>Enabling, disabling and removing a module are recorded here, because they change
       what this site can do.</p>
    <?php if ($activity === []): ?>
        <p class="mm-note">Nothing recorded yet.</p>
    <?php else: ?>
        <table class="mm-log">
            <tbody>
            <?php foreach ($activity as $a): ?>
                <tr>
                    <td class="mm-log__when"><?= e($a['created_at']) ?></td>
                    <td class="mm-log__what"><?= e($a['label']) ?>
                        <?php if (isset($a['context']['module'])): ?>
                            — <code><?= e((string) $a['context']['module']) ?></code>
                        <?php endif; ?>
                    </td>
                    <td class="mm-log__who"><?= e($a['user']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<style>
.mm-note{color:#6B7280;font-size:13.5px}
.mm-list{display:grid;gap:12px;margin-top:14px}
.mm-item{border:1px solid #E5E7EB;border-radius:10px;padding:14px 16px;background:#fff}
.mm-item--off{background:#FAFAFA;border-style:dashed}
.mm-item__head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.mm-item__name{font-weight:600;font-size:15px}
.mm-item__slug{font-size:12px;color:#6B7280;background:#F3F4F6;padding:.1rem .35rem;border-radius:4px}
.mm-tag{font-size:11.5px;font-weight:600;letter-spacing:.03em;text-transform:uppercase;padding:.15rem .45rem;border-radius:999px}
.mm-tag--on{background:#ECFDF5;color:#065F46}
.mm-tag--off{background:#F3F4F6;color:#6B7280}
.mm-tag--core{background:#EEF2FF;color:#3730A3}
.mm-item__cap{margin:.5rem 0 0;font-size:13.5px;color:#374151}
.mm-item__cap--none{color:#9CA3AF;font-style:italic}
.mm-item__routes{margin:.35rem 0 0;font-size:12.5px;color:#6B7280}
.mm-item__routes code{background:#F3F4F6;padding:.05rem .3rem;border-radius:4px}
.mm-item__acts{display:flex;gap:8px;margin-top:.7rem;flex-wrap:wrap}
.mm-item__locked{font-size:12.5px;color:#9CA3AF;font-style:italic}
.btn-danger{background:#B91C1C;color:#fff;border:0}
.btn-danger:hover{background:#991B1B}
.mm-log{width:100%;border-collapse:collapse;font-size:13.5px;margin-top:10px}
.mm-log td{padding:.45rem .3rem;border-bottom:1px solid #F3F4F6;vertical-align:top}
.mm-log__when{color:#9CA3AF;white-space:nowrap;width:1%}
.mm-log__who{color:#6B7280;text-align:right;white-space:nowrap}
</style>

<script nonce="<?= e(View::nonce()) ?>">
/**
 * Confirmations for the destructive actions on this screen.
 *
 * Attached with addEventListener from a nonced block, never as an inline
 * onsubmit= attribute: the CSP permits <script> elements carrying the nonce
 * and blocks handler attributes outright, so an inline one fails silently and
 * the action proceeds unconfirmed.
 */
(function () {
    var forms = document.querySelectorAll('form[data-confirm]');
    Array.prototype.forEach.call(forms, function (form) {
        form.addEventListener('submit', function (event) {
            var question = form.getAttribute('data-confirm');
            if (question && !window.confirm(question)) {
                event.preventDefault();
                return;
            }
            var button = form.querySelector('button[type="submit"], button:not([type])');
            if (button) {
                if (button.disabled) { event.preventDefault(); return; }
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
            }
        });
    });
})();
</script>
