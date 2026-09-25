<?php
/**
 * public/health.php — Deploy Doctor
 *
 * A standalone preflight page. Upload the project, open this once, fix
 * whatever it flags, then DELETE IT — same discipline as install.php.
 *
 * It deliberately runs BEFORE an admin account exists, so it cannot require
 * a login. That makes one rule absolute: NEVER PRINT A SECRET. No .env
 * contents, no database credentials, no raw exception messages or stack
 * traces. Only PASS / WARN / FAIL and plain-language remedial text. A
 * message that would help the owner debug is worth nothing if it also hands
 * a passer-by the database password.
 */

define('ROOT', dirname(__DIR__));

$checks = [];

/** Record one result. $fix is shown only when the check did not pass. */
function check(string $name, string $status, string $detail, string $fix = ''): void
{
    global $checks;
    $checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail, 'fix' => $fix];
}

/* ------------------------------------------------------------------ *
 * 1. PHP version on THIS host.
 *
 * cPanel MultiPHP can assign a subdomain a different PHP version than the
 * main domain, so code that runs fine on the main site can fail here for
 * reasons that have nothing to do with the deploy.
 * ------------------------------------------------------------------ */
$phpOk = version_compare(PHP_VERSION, '8.0', '>=');
check(
    'PHP version',
    $phpOk ? 'PASS' : 'WARN',
    'This host is running PHP ' . PHP_VERSION . '.',
    $phpOk ? '' : 'PlugPHP targets PHP 8.0+. In cPanel, open MultiPHP Manager and set '
        . 'this domain to 8.0 or newer. Note that each subdomain can carry its own '
        . 'PHP version, independent of the main domain.'
);

/* ------------------------------------------------------------------ *
 * 2. Required extensions.
 * ------------------------------------------------------------------ */
$required = ['pdo_mysql', 'mbstring', 'gd', 'openssl', 'fileinfo'];
$missing = array_values(array_filter($required, fn($x) => !extension_loaded($x)));
check(
    'Required PHP extensions',
    $missing ? 'FAIL' : 'PASS',
    $missing
        ? 'Missing: ' . implode(', ', $missing) . '.'
        : 'All present: ' . implode(', ', $required) . '.',
    $missing ? 'Enable the missing extension(s) in cPanel under "Select PHP Version" '
        . '-> Extensions, then reload this page.' : ''
);

/* ------------------------------------------------------------------ *
 * 3. Document root — the single most valuable check.
 *
 * This file lives in public/. If the docroot is the PARENT of public/, the
 * web server is serving the project root: core/ and .env become reachable
 * over HTTP. That is a security exposure, not just a broken deploy.
 * ------------------------------------------------------------------ */
$docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$publicDir = realpath(__DIR__);
$projectDir = realpath(ROOT);

if ($docRoot === false || $publicDir === false) {
    check('Document root', 'WARN',
        'Could not resolve the document root on this host.',
        'Verify by hand that the domain serves the public/ folder, not the project root.');
} elseif (rtrim($docRoot, '/') === rtrim($publicDir, '/')) {
    check('Document root', 'PASS', 'The document root correctly points at the public/ folder.');
} elseif ($projectDir !== false && rtrim($docRoot, '/') === rtrim($projectDir, '/')) {
    check('Document root', 'FAIL',
        'The document root points at the PROJECT ROOT, not public/. Without the '
        . 'fallback rules in the project-root .htaccess, your core/ source and your '
        . '.env file are reachable over the web.',
        'Preferred fix: in cPanel -> Domains, set this domain\'s document root to the '
        . 'public/ folder. If your host will not allow that, the project-root '
        . '.htaccess shipped with PlugPHP forwards traffic into public/ and blocks '
        . 'the sensitive paths — confirm the "Sensitive paths" check below passes.');
} else {
    check('Document root', 'WARN',
        'The document root is neither the public/ folder nor the project root.',
        'Confirm this domain serves PlugPHP\'s public/ folder. Anything else means '
        . 'requests are being resolved somewhere unexpected.');
}

/* ------------------------------------------------------------------ *
 * 4. Rewrite availability.
 * ------------------------------------------------------------------ */
if (function_exists('apache_get_modules')) {
    $hasRewrite = in_array('mod_rewrite', apache_get_modules(), true);
    check('URL rewriting', $hasRewrite ? 'PASS' : 'FAIL',
        $hasRewrite ? 'mod_rewrite is loaded.' : 'mod_rewrite is NOT loaded.',
        $hasRewrite ? '' : 'Without mod_rewrite every clean URL (/blog, /login) returns a '
            . 'server-level 404 before PHP runs. Ask your host to enable it.');
} else {
    check('URL rewriting', 'INFO',
        'Cannot detect loaded Apache modules from this SAPI (' . PHP_SAPI . ').',
        'Verify manually: if this page loads but /login returns a 404, rewriting is off.');
}

/* ------------------------------------------------------------------ *
 * 5. HTTPS / SSL.
 * ------------------------------------------------------------------ */
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
check('HTTPS', $isHttps ? 'PASS' : 'WARN',
    $isHttps ? 'This request arrived over HTTPS.' : 'This request arrived over plain HTTP.',
    $isHttps ? '' : 'On a new subdomain the certificate often takes minutes to hours to '
        . 'issue. PlugPHP deliberately holds the HSTS header back until you set '
        . 'FORCE_HSTS=true in .env — leave it false until https:// is confirmed working, '
        . 'or the browser will refuse to load the site over HTTP with no visible error.');

/* ------------------------------------------------------------------ *
 * Load core. Needed for checks 6, 8 and 9.
 * ------------------------------------------------------------------ */
$coreLoaded = false;
$envPresent = is_file(ROOT . '/.env');
if ($envPresent) {
    try {
        require_once ROOT . '/core/Config.php';
        require_once ROOT . '/core/Database.php';
        require_once ROOT . '/core/View.php';
        require_once ROOT . '/core/Url.php';
        Config::load(ROOT . '/.env');
        $coreLoaded = true;
    } catch (Throwable $e) {
        // Swallowed on purpose: the message can contain a filesystem path.
        $coreLoaded = false;
    }
}

/* ------------------------------------------------------------------ *
 * 6. Database reachability.
 * ------------------------------------------------------------------ */
if (!$envPresent) {
    check('Database connection', 'FAIL',
        'No .env file found in the project root.',
        'Run install.php first, or copy .env.example to .env and fill in real values.');
} elseif (!$coreLoaded) {
    check('Database connection', 'FAIL',
        'The .env file exists but could not be read.',
        'Check that .env is readable by the web server user and is valid KEY=value lines.');
} else {
    try {
        Database::fetchOne('SELECT 1 AS ok');
        check('Database connection', 'PASS', 'Connected to the database successfully.');
    } catch (Throwable $e) {
        // Only the driver's error CLASS is surfaced. The exception text can
        // contain the DSN, the username and the host — never print it.
        check('Database connection', 'FAIL',
            'Could not connect using the credentials in .env.',
            'Check DB_HOST, DB_NAME, DB_USER and DB_PASS. On cPanel the database and '
            . 'user names are usually prefixed with your account name, and the user '
            . 'must be added to the database with ALL PRIVILEGES.');
    }
}

/* ------------------------------------------------------------------ *
 * 7. Writable paths.
 * ------------------------------------------------------------------ */
foreach (['config/' => ROOT . '/config', 'storage/logs/' => ROOT . '/storage/logs'] as $label => $path) {
    $ok = is_dir($path) && is_writable($path);
    check('Writable: ' . $label, $ok ? 'PASS' : 'FAIL',
        $ok ? 'Writable by PHP.' : (is_dir($path) ? 'Not writable by PHP.' : 'Directory is missing.'),
        $ok ? '' : 'Set this directory to 0755 (or 0775) and make sure it is owned by the '
            . 'account PHP runs as. Files uploaded over FTP are sometimes owned by a '
            . 'different user than the PHP process.');
}

/* ------------------------------------------------------------------ *
 * 8. APP_URL vs the host this request actually arrived on.
 *
 * Informational: a reverse proxy or CDN can legitimately differ.
 * ------------------------------------------------------------------ */
if ($coreLoaded) {
    $appUrl = rtrim((string) Config::get('APP_URL', ''), '/');
    $actual = Url::detectedOrigin();

    if ($appUrl === '') {
        check('APP_URL', 'FAIL', 'APP_URL is not set in .env.',
            'Set APP_URL to this site\'s full public address (' . $actual . '). '
            . 'Canonical tags, Open Graph tags, sitemap entries and password-reset links '
            . 'are all built from it.');
    } else {
        $configuredOrigin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $appUrl);
        $matches = strcasecmp((string) $configuredOrigin, $actual) === 0;
        check('APP_URL', $matches ? 'PASS' : 'WARN',
            $matches
                ? 'APP_URL matches the host this request arrived on.'
                : 'APP_URL is set to a different host than this request arrived on.',
            $matches ? '' : 'Configured: ' . $appUrl . ' — this request: ' . $actual . '. '
                . 'If you copied .env from a previous deploy, update APP_URL to this '
                . 'site\'s real address, or password-reset links and canonical tags will '
                . 'point at the wrong domain. Safe to ignore behind a reverse proxy.');
    }

    /* -------------------------------------------------------------- *
     * 9. Detected base path.
     * -------------------------------------------------------------- */
    $base = Url::base();
    check('Detected base path', 'INFO',
        $base === ''
            ? 'The app is mounted at the domain root (base path is empty). This is the '
              . 'usual, correct case for a main domain or a subdomain.'
            : 'The app is mounted under the subfolder "' . $base . '". '
              . 'All internal links and assets are prefixed with it automatically.',
        $base === '' ? '' : 'If you did NOT intend a subfolder mount, APP_URL should still '
            . 'include it, since APP_URL is the site\'s full public root.');
}

/* ------------------------------------------------------------------ *
 * Sensitive paths — verify the web cannot reach what it must not.
 *
 * Not in the original nine, but it is the direct consequence of check 3
 * failing, and it is the difference between "misconfigured" and "leaking".
 * ------------------------------------------------------------------ */
$exposed = [];
if ($docRoot !== false && $projectDir !== false && rtrim($docRoot, '/') === rtrim($projectDir, '/')) {
    if (!is_file(ROOT . '/.htaccess')) {
        $exposed[] = 'the project-root .htaccess is missing';
    }
    if (!function_exists('apache_get_modules') || !in_array('mod_rewrite', apache_get_modules(), true)) {
        $exposed[] = 'mod_rewrite may be unavailable, so the blocking rules may not apply';
    }
}
check('Sensitive paths', $exposed ? 'FAIL' : 'PASS',
    $exposed
        ? 'The document root is the project root and ' . implode('; ', $exposed) . '.'
        : 'No exposure detected from this page\'s perspective.',
    $exposed ? 'Until this is fixed, treat .env as compromised: rotate the database '
        . 'password and any SMTP credentials after repointing the document root at public/.'
        : 'Confirm by hand: requesting /.env or /core/Config.php over the web should '
        . 'return 403 or 404, never file contents.');

/* ------------------------------------------------------------------ *
 * Render.
 * ------------------------------------------------------------------ */
$counts = array_count_values(array_column($checks, 'status'));
$hasFail = ($counts['FAIL'] ?? 0) > 0;

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>PlugPHP — Deploy Doctor</title>
<style>
  :root { color-scheme: light dark; }
  * { box-sizing: border-box; }
  body { margin:0; padding:2rem 1rem; background:#f6f7f9; color:#14161a;
         font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
  .wrap { max-width: 780px; margin: 0 auto; }
  h1 { font-size:1.5rem; margin:0 0 .25rem; }
  .sub { color:#5b6270; margin:0 0 1.5rem; }
  .row { background:#fff; border:1px solid #e3e6ea; border-radius:10px;
         padding:.85rem 1rem; margin-bottom:.6rem; display:flex; gap:.85rem; align-items:flex-start; }
  .tag { flex:none; font-size:11px; font-weight:700; letter-spacing:.04em; padding:.25rem .5rem;
         border-radius:5px; min-width:52px; text-align:center; }
  .PASS { background:#e6f6ec; color:#136c34; }
  .WARN { background:#fdf3e0; color:#8a5a08; }
  .FAIL { background:#fdeaea; color:#a11c1c; }
  .INFO { background:#eaeff7; color:#2a4b7c; }
  .name { font-weight:600; }
  .detail { color:#3d434d; margin-top:.15rem; }
  .fix { margin-top:.4rem; padding-left:.7rem; border-left:2px solid #d4d8de; color:#5b6270; font-size:14px; }
  .banner { border-radius:10px; padding:1rem 1.1rem; margin:1.5rem 0 0; font-weight:600; }
  .banner-del { background:#fdeaea; color:#a11c1c; border:1px solid #f3c4c4; }
  code { background:#eef0f3; padding:.1rem .3rem; border-radius:4px; font-size:13px; }
  @media (prefers-color-scheme: dark) {
    body { background:#15171b; color:#e6e8ec; }
    .row { background:#1d2025; border-color:#2b2f36; }
    .detail { color:#c2c7d0; } .fix { color:#9aa1ad; border-left-color:#3a3f47; }
    code { background:#2b2f36; }
    .PASS { background:#12321f; color:#6ddb95; } .WARN { background:#3a2c10; color:#f0be62; }
    .FAIL { background:#3b1717; color:#f08d8d; } .INFO { background:#1b2a42; color:#8fb4ee; }
    .banner-del { background:#3b1717; color:#f08d8d; border-color:#5a2222; }
  }
</style>
</head>
<body>
<div class="wrap">
  <h1>PlugPHP — Deploy Doctor</h1>
  <p class="sub">
    <?= (int) ($counts['PASS'] ?? 0) ?> passed &middot;
    <?= (int) ($counts['WARN'] ?? 0) ?> warnings &middot;
    <?= (int) ($counts['FAIL'] ?? 0) ?> failures
    <?php if ($hasFail): ?>&mdash; fix the failures before going live.<?php endif; ?>
  </p>

  <?php foreach ($checks as $c): ?>
    <div class="row">
      <span class="tag <?= htmlspecialchars($c['status'], ENT_QUOTES) ?>"><?= htmlspecialchars($c['status'], ENT_QUOTES) ?></span>
      <div>
        <div class="name"><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></div>
        <div class="detail"><?= htmlspecialchars($c['detail'], ENT_QUOTES) ?></div>
        <?php if ($c['status'] !== 'PASS' && $c['fix'] !== ''): ?>
          <div class="fix"><?= htmlspecialchars($c['fix'], ENT_QUOTES) ?></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="banner banner-del">
    Delete <code>health.php</code> before going live. It reports server details
    and requires no login, so it must not stay on a public site.
  </div>
</div>
</body>
</html>
