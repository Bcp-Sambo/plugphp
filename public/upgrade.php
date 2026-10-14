<?php

/**
 * public/upgrade.php — one-time catch-up for a site installed before the
 * Updates panel existed.
 *
 * Perth Partner and Index Managers both predate 1.0.0. They have no
 * core/Updater.php, so they cannot be reached by the normal update flow at
 * all — the feature that would fetch a release is itself part of the release
 * they are missing. The files therefore arrive the way they always did for
 * those sites, by upload; this script does the part a file copy cannot:
 * bring the schema forward, record the version, and write the fingerprint
 * baseline so the site joins the automatic track from here on.
 *
 * HOW IT IS USED
 *   1. Upload the current PlugPHP tree over the site, keeping .env,
 *      config/modules.php, public/uploads/ and any customised views.
 *   2. Log in to the site as an administrator.
 *   3. Visit /upgrade.php and press the button.
 *   4. The script deletes itself. If it cannot, delete it by hand — it says so.
 *
 * It deliberately does NOT download anything. A script that fetches and
 * executes remote code is a far larger thing to leave sitting on a shared
 * host than one that only touches the database and storage/.
 *
 * AI AGENTS: this file is meant to be ABSENT from a healthy site. Do not
 * reference it from the dashboard, and do not restore it in an update —
 * Updater::REFERENCE_EXCLUDE lists it for that reason.
 */

declare(strict_types=1);

define('ROOT', dirname(__DIR__));

$bootError = null;

// .env is the evidence that this is an installed site, NOT config/installed.lock.
// The lock is written by install.php and is routinely absent on exactly the old
// sites this script exists for — the 1.0.0-era test site has none, and neither
// does a tree set up by hand. Gating on it would refuse the intended audience.
if (!is_file(ROOT . '/.env')) {
    $bootError = 'No .env file was found, so this is not an installed PlugPHP site. '
        . 'Run public/install.php instead.';
}

$loggedIn = false;
$isAdmin  = false;
$ran      = false;
$selfDeleted = false;
$errors   = [];
$report   = [];

if ($bootError === null) {
    foreach ([
        'Config', 'Database', 'Auth', 'Mailer', 'View', 'Url',
        'Router', 'Module', 'Settings', 'Crypto', 'Updater',
    ] as $class) {
        $file = ROOT . '/core/' . $class . '.php';
        if (!is_file($file)) {
            $bootError = 'core/' . $class . '.php is missing. Upload the complete '
                . 'PlugPHP tree over this site before running the upgrade.';
            break;
        }
        require_once $file;
    }
}

if ($bootError === null) {
    try {
        Config::load(ROOT . '/.env');
        Auth::bootSession();
        $loggedIn = Auth::user() !== null;
        $isAdmin  = $loggedIn && Auth::isAdmin();
    } catch (Throwable $e) {
        // Never surface the raw message: it can carry credentials and paths.
        error_log('upgrade.php boot: ' . $e->getMessage());
        $bootError = 'Could not start up against this site\'s configuration. '
            . 'The details were written to storage/logs/.';
    }
}

/* ------------------------------------------------------------------ *
 * The upgrade itself. Admin session + CSRF, same as any other action
 * that changes the site.
 * ------------------------------------------------------------------ */
if ($bootError === null && $isAdmin && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $n = Updater::runMigrations();
        $report[] = $n === 0
            ? 'Schema already current — no migrations needed.'
            : $n . ' migration(s) applied.';

        if (Updater::seedBaseline()) {
            $report[] = 'File fingerprints recorded. Differences from a release are now reportable.';
        } else {
            $report[] = 'A fingerprint baseline was already present; left as it was.';
        }

        Settings::set('plugphp_installed_version', Updater::VERSION);
        $report[] = 'Recorded version ' . Updater::VERSION . '.';

        $ran = true;

        // Self-delete. Verified rather than assumed: on plenty of shared hosts
        // PHP can read a file it may not unlink, and silently leaving a working
        // upgrade endpoint on a live site is the one outcome worth shouting about.
        if (@unlink(__FILE__) && !file_exists(__FILE__)) {
            $selfDeleted = true;
        }
    } catch (Throwable $e) {
        error_log('upgrade.php run: ' . $e->getMessage());
        $errors[] = 'The upgrade did not complete. The details were written to '
            . 'storage/logs/. Nothing was left half-applied — migrations that '
            . 'did run are recorded and will not repeat.';
    }
}

function u_e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$csrf = ($bootError === null && $isAdmin) ? Auth::csrfToken() : '';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Upgrade — PlugPHP</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#F7F8FA;color:#111827;font:15px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:640px;margin:48px auto;padding:0 20px}
.card{background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:28px}
h1{margin:0 0 6px;font-size:22px}
.sub{color:#6B7280;margin:0 0 22px;font-size:14px}
.msg{border-radius:8px;padding:12px 14px;margin:0 0 16px;font-size:14px}
.err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B}
.ok{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46}
.warn{background:#FFFBEB;border:1px solid #FDE68A;color:#92400E}
ul{margin:.4rem 0 .4rem 1.1rem;padding:0}
li{margin:.25rem 0}
code{background:#F3F4F6;padding:.1rem .35rem;border-radius:4px;font-size:13px}
button{background:#111827;color:#fff;border:0;border-radius:8px;padding:11px 18px;font-size:15px;font-weight:600;cursor:pointer}
button:hover{background:#1F2937}
.steps{font-size:14px;color:#374151}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>PlugPHP upgrade</h1>
    <p class="sub">One-time catch-up for a site installed before the Updates panel existed.</p>

<?php if ($bootError !== null): ?>
    <div class="msg err"><?= u_e($bootError) ?></div>

<?php elseif (!$loggedIn): ?>
    <div class="msg warn">
      You need to be logged in as an administrator to run this.
      <a href="login">Log in</a>, then come back to this page.
    </div>

<?php elseif (!$isAdmin): ?>
    <div class="msg err">
      This account is not an administrator. Only an administrator can upgrade the site.
    </div>

<?php elseif ($ran && $errors === []): ?>
    <div class="msg ok"><strong>Upgrade complete.</strong></div>
    <ul>
      <?php foreach ($report as $line): ?><li><?= u_e($line) ?></li><?php endforeach; ?>
    </ul>
    <?php if ($selfDeleted): ?>
      <div class="msg ok">This script has deleted itself. Nothing further to do.</div>
    <?php else: ?>
      <div class="msg err">
        <strong>This script could not delete itself — delete it now, by hand.</strong>
        Leaving <code>public/upgrade.php</code> on a live site leaves an endpoint
        that re-runs migrations for anyone who reaches it with an admin session.
      </div>
    <?php endif; ?>
    <p class="steps">The site now appears in the Updates panel at
      <code>/admin/updates</code> and will be offered future releases there.</p>

<?php else: ?>
    <?php foreach ($errors as $err): ?>
      <div class="msg err"><?= u_e($err) ?></div>
    <?php endforeach; ?>

    <p class="steps">Before pressing this, make sure you have uploaded the current
      PlugPHP tree over this site, keeping <code>.env</code>,
      <code>config/modules.php</code>, <code>public/uploads/</code> and any views
      you have customised.</p>
    <p class="steps">This will:</p>
    <ul class="steps">
      <li>run any database migrations this site has not had yet;</li>
      <li>record the checksums of the files it shipped with, so later releases can
          tell your customisations from files that are merely out of date;</li>
      <li>record the installed version, which is what puts the site on the
          update track;</li>
      <li>delete this script.</li>
    </ul>
    <p class="steps">It does not download anything, and it does not overwrite any
      of your files.</p>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= u_e($csrf) ?>">
      <button type="submit">Upgrade this site</button>
    </form>
<?php endif; ?>
  </div>
</div>
</body>
</html>
