<?php
/**
 * modules/admin-dashboard/views/settings.php — /admin/settings
 *
 * Restyle freely. Do not remove a csrf_token field, and do not turn any of
 * these POSTs into a GET.
 *
 * @var string      $tab
 * @var array       $flash            ['errors' => string[], 'success' => string]
 * @var string      $siteName
 * @var string      $siteDescription
 * @var string      $siteLogo
 * @var string|null $favicon
 * @var string|null $ogImage
 * @var array       $smtp             Mailer::config()
 * @var string      $smtpPreset
 * @var bool        $smtpPassSet
 * @var array       $presets          Mailer::PROVIDER_PRESETS
 * @var bool        $hasAppKey
 * @var string      $suggestedKey
 * @var bool        $trackingOn
 * @var string      $gaId
 * @var string      $fbPixelId
 * @var string      $adminEmail
 */
$csrf = Auth::csrfToken();
$tabs = ['branding' => 'Branding', 'smtp' => 'Email (SMTP)', 'tracking' => 'Tracking'];
?>

<?php if ($flash['success'] !== ''): ?>
    <div class="pp-note pp-note--ok"><?= e($flash['success']) ?></div>
<?php endif; ?>

<?php if ($flash['errors'] !== []): ?>
    <div class="pp-note pp-note--bad">
        <strong>Not saved:</strong>
        <ul><?php foreach ($flash['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<nav class="pp-tabs" aria-label="Settings sections">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= url('/admin/settings?tab=' . $key) ?>"
           class="pp-tab<?= $tab === $key ? ' pp-tab--on' : '' ?>"
           <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php /* ---------------------------------------------------------- BRANDING */ ?>
<?php if ($tab === 'branding'): ?>
<form class="pp-panel" method="post" action="<?= url('/admin/settings/branding') ?>" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <h2>Branding</h2>

    <div class="field">
        <label for="site_name">Site name</label>
        <input type="text" id="site_name" name="site_name" value="<?= e($siteName) ?>" maxlength="120">
        <p class="hint">Shown in the header, the footer and the browser tab.</p>
    </div>

    <div class="field">
        <label for="site_description">Site description</label>
        <textarea id="site_description" name="site_description" rows="3" maxlength="300"><?= e($siteDescription) ?></textarea>
        <p class="hint">Used as the meta description on pages that do not set their own — so search results and link previews have something to show.</p>
    </div>

    <?php
    $imageFields = [
        ['site_logo',    'Logo',              $siteLogo, 'PNG, JPG or WebP, up to 2 MB.'],
        ['site_favicon', 'Favicon',           $favicon,  'PNG only, up to 2 MB. A square image around 32×32 or 64×64 works best.'],
        ['og_image',     'Share image (Open Graph)', $ogImage, 'PNG, JPG or WebP, up to 2 MB. Shown when someone links to your site. 1200×630 is the usual size.'],
    ];
    foreach ($imageFields as [$field, $label, $current, $hint]):
    ?>
    <div class="field">
        <label for="<?= e($field) ?>"><?= e($label) ?></label>
        <?php if ($current): ?>
            <div class="pp-thumb"><img src="<?= asset($current) ?>" alt="Current <?= e(strtolower($label)) ?>"></div>
        <?php else: ?>
            <p class="hint">Nothing uploaded yet.</p>
        <?php endif; ?>
        <input type="file" id="<?= e($field) ?>" name="<?= e($field) ?>" accept="image/png,image/jpeg,image/webp">
        <p class="hint"><?= e($hint) ?> Leave empty to keep the current image.</p>
    </div>
    <?php endforeach; ?>

    <div class="form-actions"><button class="btn btn-primary" type="submit">Save branding</button></div>
</form>
<?php endif; ?>

<?php /* ------------------------------------------------------------- SMTP */ ?>
<?php if ($tab === 'smtp'): ?>
<?php if (!$hasAppKey): ?>
    <div class="pp-note pp-note--bad">
        <strong>This site cannot store a mail password yet.</strong>
        <p>Passwords are encrypted before they are saved, and that needs an
        <code>APP_KEY</code>. This site has none, and <code>.env</code> could not be
        written automatically. Add this line to your <code>.env</code> file, then reload:</p>
        <pre class="pp-key"><?= e($suggestedKey) ?></pre>
        <p>Everything else on this page saves normally without it.</p>
    </div>
<?php endif; ?>

<form class="pp-panel" method="post" action="<?= url('/admin/settings/smtp') ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <h2>Email (SMTP)</h2>
    <p class="hint">Used for contact-form notifications and password-reset emails. Set here, these override the values in <code>.env</code>.</p>

    <div class="field">
        <label for="smtp_provider_preset">Provider</label>
        <select id="smtp_provider_preset" name="smtp_provider_preset">
            <?php foreach ($presets as $key => $preset): ?>
                <option value="<?= e($key) ?>"<?= $smtpPreset === $key ? ' selected' : '' ?>
                    data-host="<?= e($preset['host'] ?? '') ?>"
                    data-port="<?= e((string) ($preset['port'] ?? '')) ?>"
                    data-user="<?= e($preset['user'] ?? '') ?>"
                    data-encryption="<?= e($preset['encryption'] ?? '') ?>">
                    <?= e($preset['label'] ?? 'Custom') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="hint">Picking one fills in the host, port and username below. You still supply your own password or API key.</p>
    </div>

    <div class="field">
        <label for="smtp_host">Host</label>
        <input type="text" id="smtp_host" name="smtp_host" value="<?= e($smtp['host']) ?>" autocomplete="off">
    </div>

    <div class="pp-row">
        <div class="field">
            <label for="smtp_port">Port</label>
            <input type="number" id="smtp_port" name="smtp_port" value="<?= e((string) $smtp['port']) ?>" min="1" max="65535">
        </div>
        <div class="field">
            <label for="smtp_encryption">Encryption</label>
            <select id="smtp_encryption" name="smtp_encryption">
                <?php foreach (['tls' => 'TLS (usual)', 'ssl' => 'SSL', 'none' => 'None'] as $v => $l): ?>
                    <option value="<?= e($v) ?>"<?= ($smtp['encryption'] ?: 'none') === $v ? ' selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="field">
        <label for="smtp_user">Username</label>
        <input type="text" id="smtp_user" name="smtp_user" value="<?= e($smtp['user']) ?>" autocomplete="off">
    </div>

    <div class="field">
        <label for="smtp_pass">Password or API key</label>
        <input type="password" id="smtp_pass" name="smtp_pass" value="" autocomplete="new-password"
               placeholder="<?= $smtpPassSet ? 'Saved — leave blank to keep it' : 'Not set' ?>">
        <p class="hint">
            Stored encrypted.
            <?= $smtpPassSet ? 'A password is already saved; leave this blank to keep it.' : '' ?>
            It is never shown back to you, here or anywhere else.
        </p>
    </div>

    <div class="pp-row">
        <div class="field">
            <label for="smtp_from_email">From address</label>
            <input type="email" id="smtp_from_email" name="smtp_from_email" value="<?= e($smtp['from_email']) ?>">
        </div>
        <div class="field">
            <label for="smtp_from_name">From name</label>
            <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?= e($smtp['from_name']) ?>">
        </div>
    </div>

    <div class="form-actions"><button class="btn btn-primary" type="submit">Save mail settings</button></div>
</form>

<form class="pp-panel" method="post" action="<?= url('/admin/settings/test-email') ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <h2>Test it</h2>
    <p class="hint">
        Sends a real email to <strong><?= e($adminEmail) ?></strong>, the address on your
        admin account. Save your settings first.
    </p>
    <div class="form-actions"><button class="btn btn-secondary" type="submit">Send test email</button></div>
</form>
<?php endif; ?>

<?php /* --------------------------------------------------------- TRACKING */ ?>
<?php if ($tab === 'tracking'): ?>
<form class="pp-panel" method="post" action="<?= url('/admin/settings/tracking') ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <h2>Tracking</h2>
    <p class="hint">
        Add Google Analytics or a Facebook Pixel without editing any template.
        While tracking is off, no third-party script is loaded and the site's
        content security policy stays at its strictest.
    </p>

    <div class="field pp-check">
        <label>
            <input type="checkbox" name="tracking_enabled" value="1"<?= $trackingOn ? ' checked' : '' ?>>
            Enable tracking on the public site
        </label>
        <p class="hint">The master switch. With this off, the IDs below are kept but nothing is loaded.</p>
    </div>

    <div class="field">
        <label for="ga_measurement_id">Google Analytics measurement ID</label>
        <input type="text" id="ga_measurement_id" name="ga_measurement_id"
               value="<?= e($gaId) ?>" placeholder="G-XXXXXXXXXX"
               pattern="[Gg]-[A-Za-z0-9]{4,24}" maxlength="26" autocomplete="off">
        <p class="hint">Find it in Google Analytics under Admin &rarr; Data streams. Leave blank to remove.</p>
    </div>

    <div class="field">
        <label for="fb_pixel_id">Facebook Pixel ID</label>
        <input type="text" id="fb_pixel_id" name="fb_pixel_id"
               value="<?= e($fbPixelId) ?>" placeholder="123456789012345"
               pattern="[0-9]{5,24}" inputmode="numeric" maxlength="24" autocomplete="off">
        <p class="hint">Digits only. Find it in Meta Events Manager. Leave blank to remove.</p>
    </div>

    <div class="form-actions"><button class="btn btn-primary" type="submit">Save tracking</button></div>
</form>
<?php endif; ?>

<style>
.pp-tabs { display:flex; gap:4px; margin-bottom:16px; border-bottom:1px solid #e3e6ea; }
.pp-tab { padding:9px 14px; text-decoration:none; color:#5b6270; font-size:14px; font-weight:600;
          border-bottom:2px solid transparent; margin-bottom:-1px; }
.pp-tab--on { color:#14161a; border-bottom-color:#14161a; }
.pp-panel { background:#fff; border:1px solid #e3e6ea; border-radius:10px; padding:18px 20px; margin-bottom:16px; }
.pp-panel h2 { font-size:1.05rem; margin:0 0 .5rem; }
.pp-panel .field { margin-bottom:16px; }
.pp-panel label { display:block; font-size:13px; font-weight:600; margin-bottom:5px; }
.pp-panel input[type=text], .pp-panel input[type=email], .pp-panel input[type=password],
.pp-panel input[type=number], .pp-panel select, .pp-panel textarea {
    width:100%; padding:8px 10px; border:1px solid #d4d8de; border-radius:7px; font:inherit; font-size:14px; }
.pp-panel .pp-check label { font-weight:400; font-size:14px; display:flex; gap:8px; align-items:center; }
.pp-panel .pp-check input { width:auto; }
.hint { font-size:12.5px; color:#5b6270; margin:5px 0 0; }
.pp-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.pp-thumb { margin-bottom:8px; }
.pp-thumb img { max-height:64px; max-width:220px; border:1px solid #e3e6ea; border-radius:6px; background:#f6f7f9; padding:4px; }
.pp-key { background:#14161a; color:#e6e8ec; padding:10px 12px; border-radius:7px; font-size:12.5px; overflow-x:auto; }
.pp-note { border-radius:10px; padding:14px 18px; margin-bottom:16px; border:1px solid; }
.pp-note--ok { background:#e9f7ee; border-color:#bfe3cc; color:#12331f; }
.pp-note--bad { background:#fdeeee; border-color:#f0c9c9; color:#4a1414; }
.pp-note ul { margin:.4rem 0 0 1.1rem; padding:0; }
@media (max-width: 640px) { .pp-row { grid-template-columns:1fr; } }
</style>

<script nonce="<?= e(View::nonce()) ?>">
// Picking a provider fills in its known host/port/username. Progressive
// enhancement only — every field stays editable and the form works without JS.
(function () {
    var select = document.getElementById('smtp_provider_preset');
    if (!select) { return; }
    select.addEventListener('change', function () {
        var opt = select.options[select.selectedIndex];
        var map = { smtp_host: 'host', smtp_port: 'port', smtp_encryption: 'encryption' };
        for (var id in map) {
            var v = opt.getAttribute('data-' + map[id]);
            if (v) { document.getElementById(id).value = v; }
        }
        var user = opt.getAttribute('data-user');
        if (user) { document.getElementById('smtp_user').value = user; }
    });
})();
</script>
