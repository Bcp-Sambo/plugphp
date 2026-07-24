<?php
/**
 * public/install.php — PlugPHP web installer (WordPress-style 5-minute setup).
 *
 * Single file, web-accessible. Steps: environment check -> module picker +
 * DB credentials + first admin -> test DB -> run migrations -> create admin
 * -> write config/modules.php and .env -> lock.
 *
 * DELETE THIS FILE after a successful install (it can create an admin user
 * and rewrite configuration).
 *
 * Written in PHP 7.x-safe syntax on purpose: on an old-PHP shared host it
 * must still render and warn "PHP 8.0+ required" instead of parse-erroring.
 * Core (which uses PHP 8.0 syntax) is only required after that gate passes.
 *
 * SECURITY / RUN NOTE: serve locally bound to 127.0.0.1 only, never 0.0.0.0
 *   php -S 127.0.0.1:8000 -t public
 * then open http://127.0.0.1:8000/install.php
 */

define('ROOT', dirname(__DIR__));
define('ENV_FILE', ROOT . '/.env');
define('MODULES_FILE', ROOT . '/config/modules.php');
define('LOCK_FILE', ROOT . '/config/installed.lock');

/** Always installed; not shown as togglable in the picker. */
$ALWAYS_ON = array('home', 'admin-dashboard', 'auth');
/** Optional modules the site owner can pick. */
$OPTIONAL = array('about', 'contact-form', 'services', 'projects', 'blog');
/** Canonical order written into config/modules.php (drives dashboard nav order). */
$CANONICAL = array('home', 'about', 'services', 'projects', 'blog', 'contact-form', 'auth', 'admin-dashboard');

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function module_class($name)
{
    return str_replace(' ', '', ucwords(str_replace('-', ' ', $name))) . 'Module';
}

/* ------------------------------------------------------------------ *
 * Already installed? Refuse and point at the lock file.
 * ------------------------------------------------------------------ */
if (file_exists(LOCK_FILE)) {
    render_locked();
    exit;
}

/* ------------------------------------------------------------------ *
 * Environment checks (all PHP 7.x-safe).
 * ------------------------------------------------------------------ */
$phpOk    = version_compare(PHP_VERSION, '8.0.0', '>=');
$pdoMysql = extension_loaded('pdo_mysql');
$rootW    = is_writable(ROOT);
$configW  = is_writable(ROOT . '/config');
$storageW = is_dir(ROOT . '/storage/logs') && is_writable(ROOT . '/storage/logs');

$checks = array(
    array('label' => 'PHP version (' . PHP_VERSION . ')', 'status' => $phpOk ? 'pass' : 'fail',
          'note' => 'PlugPHP requires PHP 8.0 or newer.'),
    array('label' => 'PDO MySQL driver', 'status' => $pdoMysql ? 'pass' : 'fail',
          'note' => 'Required to connect to the database.'),
    array('label' => 'mbstring extension', 'status' => extension_loaded('mbstring') ? 'pass' : 'warn',
          'note' => 'Recommended for correct multibyte text handling.'),
    array('label' => 'GD extension', 'status' => extension_loaded('gd') ? 'pass' : 'warn',
          'note' => 'Required for image uploads (blog / projects featured images).'),
    array('label' => 'Project root writable', 'status' => $rootW ? 'pass' : 'fail',
          'note' => 'Needed to write the .env file (' . ROOT . ').'),
    array('label' => 'config/ writable', 'status' => $configW ? 'pass' : 'fail',
          'note' => 'Needed to write config/modules.php and the install lock.'),
    array('label' => 'storage/logs writable', 'status' => $storageW ? 'pass' : 'warn',
          'note' => 'Needed for the production error log.'),
);

/** Hard blockers — install cannot proceed until these pass. */
$canInstall = $phpOk && $pdoMysql && $rootW && $configW;

/* ------------------------------------------------------------------ *
 * Handle the install submission.
 * ------------------------------------------------------------------ */
$errors = array();
$done = false;
$old = array(
    'app_name' => 'PlugPHP',
    'app_url'  => 'http://127.0.0.1:8000',
    'db_host'  => '127.0.0.1',
    'db_name'  => '',
    'db_user'  => '',
    'admin_name'  => '',
    'admin_email' => '',
    'modules' => $OPTIONAL, // default: all optional modules pre-checked
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install') {
    // Preserve entered values for re-render (never passwords).
    $old['app_name']    = trim((string) (isset($_POST['app_name']) ? $_POST['app_name'] : ''));
    $old['app_url']     = trim((string) (isset($_POST['app_url']) ? $_POST['app_url'] : ''));
    $old['db_host']     = trim((string) (isset($_POST['db_host']) ? $_POST['db_host'] : ''));
    $old['db_name']     = trim((string) (isset($_POST['db_name']) ? $_POST['db_name'] : ''));
    $old['db_user']     = trim((string) (isset($_POST['db_user']) ? $_POST['db_user'] : ''));
    $old['admin_name']  = trim((string) (isset($_POST['admin_name']) ? $_POST['admin_name'] : ''));
    $old['admin_email'] = trim((string) (isset($_POST['admin_email']) ? $_POST['admin_email'] : ''));
    $postedModules      = isset($_POST['modules']) && is_array($_POST['modules']) ? $_POST['modules'] : array();
    $old['modules']     = array_values(array_intersect($postedModules, $OPTIONAL));

    $dbPass    = (string) (isset($_POST['db_pass']) ? $_POST['db_pass'] : '');
    $adminPass = (string) (isset($_POST['admin_pass']) ? $_POST['admin_pass'] : '');
    $adminPass2 = (string) (isset($_POST['admin_pass_confirm']) ? $_POST['admin_pass_confirm'] : '');

    // ---- Validate ----
    if (!$canInstall) {
        $errors['env'] = 'The environment checks above must pass before installing.';
    }
    if ($old['app_url'] === '') {
        $errors['app_url'] = 'Site URL is required.';
    }
    if ($old['db_name'] === '') {
        $errors['db_name'] = 'Database name is required.';
    }
    if ($old['db_user'] === '') {
        $errors['db_user'] = 'Database user is required.';
    }
    if (!filter_var($old['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['admin_email'] = 'A valid admin email is required.';
    }
    if (strlen($adminPass) < 8) {
        $errors['admin_pass'] = 'Admin password must be at least 8 characters.';
    } elseif ($adminPass !== $adminPass2) {
        $errors['admin_pass'] = 'The two admin passwords do not match.';
    }

    // ---- Test the DB connection before doing anything irreversible ----
    if (empty($errors)) {
        try {
            $dsn = 'mysql:host=' . $old['db_host'] . ';dbname=' . $old['db_name'] . ';charset=utf8mb4';
            new PDO($dsn, $old['db_user'], $dbPass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        } catch (Throwable $e) {
            $errors['db'] = 'Could not connect to the database: ' . $e->getMessage();
        }
    }

    // ---- Do the install ----
    if (empty($errors)) {
        try {
            $modules = final_module_list($CANONICAL, $ALWAYS_ON, $old['modules']);

            // 1) Write .env (Config/Database read from it).
            $isLocal = (strpos($old['app_url'], 'localhost') !== false)
                     || (strpos($old['app_url'], '127.0.0.1') !== false);
            write_env(ENV_FILE, array(
                'APP_NAME'  => $old['app_name'] !== '' ? $old['app_name'] : 'PlugPHP',
                'APP_ENV'   => $isLocal ? 'local' : 'production',
                'APP_DEBUG' => $isLocal ? 'true' : 'false',
                'APP_URL'   => rtrim($old['app_url'], '/'),
                'DB_HOST'   => $old['db_host'] !== '' ? $old['db_host'] : '127.0.0.1',
                'DB_NAME'   => $old['db_name'],
                'DB_USER'   => $old['db_user'],
                'DB_PASS'   => $dbPass,
            ));

            // 2) Load core against the fresh .env, then run migrations.
            require_once ROOT . '/core/Config.php';
            require_once ROOT . '/core/Database.php';
            require_once ROOT . '/core/Auth.php';
            require_once ROOT . '/core/Mailer.php';
            require_once ROOT . '/core/View.php';
            require_once ROOT . '/core/Router.php';
            require_once ROOT . '/core/Module.php';
            require_once ROOT . '/core/Settings.php';

            Config::load(ENV_FILE);

            // Core migration first, then each selected module's migrations in order.
            Database::runMigrationFile(ROOT . '/core/migrations/000_create_site_settings.sql');
            foreach ($modules as $moduleName) {
                $class = module_class($moduleName);
                $file = ROOT . '/modules/' . $moduleName . '/' . $class . '.php';
                if (!file_exists($file)) {
                    continue;
                }
                require_once $file;
                $instance = new $class();
                foreach ($instance->migrations() as $migration) {
                    Database::runMigrationFile($migration);
                }
            }

            // 3) First admin user — delegated to core/Auth.php (idempotent on retry).
            $existing = Database::fetchOne('SELECT id FROM users WHERE email = :email', array('email' => $old['admin_email']));
            if ($existing === null) {
                Auth::register($old['admin_email'], $adminPass, array('name' => $old['admin_name']));
            }

            // 4) Seed the PlugPHP demo content into empty tables (WordPress-
            //    style), so a fresh download is populated and informative.
            seed_sample_content($modules);

            // 5) Persist the enabled module list.
            write_modules(MODULES_FILE, $modules);

            // 6) Lock — marks the install complete; delete it to re-run.
            file_put_contents(LOCK_FILE, 'Installed ' . gmdate('c') . "\n");

            $done = true;
        } catch (Throwable $e) {
            $errors['install'] = 'Install failed: ' . $e->getMessage();
        }
    }
}

/* ------------------------------------------------------------------ *
 * Helpers
 * ------------------------------------------------------------------ */
function final_module_list($canonical, $alwaysOn, $selectedOptional)
{
    $out = array();
    foreach ($canonical as $m) {
        if (in_array($m, $alwaysOn, true) || in_array($m, $selectedOptional, true)) {
            $out[] = $m;
        }
    }
    return $out;
}

function write_env($path, $values)
{
    // SMTP + contact settings are left blank for the owner to fill in later.
    $all = $values + array(
        'SMTP_HOST'       => '',
        'SMTP_PORT'       => '587',
        'SMTP_USER'       => '',
        'SMTP_PASS'       => '',
        'SMTP_ENCRYPTION' => 'tls',
        'SMTP_FROM_EMAIL' => '',
        'SMTP_FROM_NAME'  => isset($values['APP_NAME']) ? $values['APP_NAME'] : 'Website',
        'CONTACT_TO'      => '',
    );

    $lines = array();
    foreach ($all as $key => $value) {
        // core/Config.php uses a minimal parser: it splits each line on the
        // first '=' and strips only SURROUNDING quotes (no unescaping). So we
        // write RAW, UNQUOTED values — that round-trips spaces, '#', '=', and
        // quotes/backslashes appearing INSIDE the value. Newlines are removed
        // because the format is strictly one KEY=value per line.
        //
        // Known parser limitation (documented, not fixable from here): a value
        // with LEADING/TRAILING whitespace or a leading/trailing quote char
        // will be trimmed on read. Avoid those in DB_PASS, or edit .env by hand.
        $clean = str_replace(array("\r", "\n"), '', (string) $value);
        $lines[] = $key . '=' . $clean;
    }

    if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
        throw new RuntimeException('Could not write .env at ' . $path);
    }
    @chmod($path, 0600);
}

function write_modules($path, $modules)
{
    $php = "<?php\n\n// Generated by install.php. The enabled module list.\n\nreturn [\n";
    foreach ($modules as $m) {
        $php .= "    '" . addslashes($m) . "',\n";
    }
    $php .= "];\n";
    if (file_put_contents($path, $php) === false) {
        throw new RuntimeException('Could not write config/modules.php at ' . $path);
    }
}

/**
 * Seed the shipped demo content into empty tables for the modules that were
 * installed. Only inserts when a table is empty, so re-running is safe.
 */
function seed_sample_content($modules)
{
    $file = ROOT . '/resources/sample-content.php';
    if (!is_file($file)) {
        return;
    }
    $sample = require $file;
    if (!is_array($sample)) {
        return;
    }

    // module name => [table, sample-content key]
    $map = array(
        'services' => array('services', 'services'),
        'projects' => array('projects', 'projects'),
        'blog'     => array('posts', 'posts'),
    );

    foreach ($map as $moduleName => $pair) {
        list($table, $key) = $pair;
        if (!in_array($moduleName, $modules, true) || empty($sample[$key])) {
            continue;
        }
        // Fixed, hardcoded table name (never user input).
        $row = Database::fetchOne('SELECT COUNT(*) AS c FROM ' . $table);
        if ((int) ($row['c'] ?? 0) > 0) {
            continue; // already has content — don't duplicate
        }
        foreach ($sample[$key] as $record) {
            Database::insert($table, $record);
        }
    }
}

function render_locked()
{
    page_top('Already installed', 'Locked');
    echo '<h1>Already installed</h1>';
    echo '<p class="intro">An install lock file exists, so the installer will not run again.</p>';
    echo '<div class="alert err" style="display:flex;gap:10px;align-items:flex-start;margin-top:18px">'
       . '<strong>For security, delete <code>public/install.php</code> now.</strong></div>';
    echo '<div class="sec"><p class="sec__label">Re-run the installer</p>'
       . '<p style="font-size:14px;color:#4B5563;margin:0 0 10px">Deleting the lock file below lets the installer '
       . 'recreate configuration:</p>'
       . '<pre>' . h(LOCK_FILE) . '</pre></div>';
    page_bottom();
}

function install_css()
{
    return <<<'CSS'
*{box-sizing:border-box}
body{margin:0;background:#F3F4F6;color:#1F2937;line-height:1.5;-webkit-font-smoothing:antialiased;
  font-family:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.mono,.step,.hint,.sec__label,.badge,code,pre{font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace}
a{color:#617CBE}
.wrap{min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:clamp(24px,5vw,56px) 16px}
.card{width:min(600px,100%);background:#fff;border:1px solid rgba(31,41,55,.1);border-radius:12px;overflow:hidden;
  box-shadow:0 24px 60px -24px rgba(31,41,55,.3)}
.card__head{display:flex;align-items:center;gap:11px;padding:16px 22px;border-bottom:1px solid rgba(31,41,55,.08)}
.card__head .mark{width:26px;height:26px;flex:0 0 auto;object-fit:contain}
.card__head b{font-size:16px;font-weight:700}
.card__head .step{margin-left:auto;font-size:12px;color:#9CA3AF}
.bar{height:4px;background:linear-gradient(90deg,#617CBE,#10B981)}
.body{padding:24px 22px 26px}
.body h1{font-size:22px;font-weight:800;letter-spacing:-.02em;margin:0}
p.intro{font-size:14.5px;color:#6B7280;margin:8px 0 0}
.sec{margin-top:24px}
.sec__label{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#9CA3AF;margin:0 0 12px}
.checks{list-style:none;padding:0;margin:0;border:1px solid rgba(31,41,55,.1);border-radius:8px;overflow:hidden}
.checks li{display:flex;align-items:center;gap:10px;padding:10px 14px;border-top:1px solid rgba(31,41,55,.07);font-size:14px}
.checks li:first-child{border-top:none}
.badge{font-size:10px;font-weight:700;letter-spacing:.05em;padding:3px 7px;border-radius:5px;flex:0 0 auto}
.badge.pass{background:rgba(16,185,129,.14);color:#0e7a58}
.badge.warn{background:rgba(176,96,0,.12);color:#b06000}
.badge.fail{background:rgba(220,38,38,.1);color:#b91c1c}
.check-note{display:block;font-size:12.5px;color:#9CA3AF;margin-top:2px}
.always{font-size:13px;color:#9CA3AF;margin:0 0 12px}
.mods{display:flex;flex-direction:column;gap:8px}
.mod{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid rgba(31,41,55,.12);border-radius:8px;cursor:pointer}
.mod__cb{position:absolute;opacity:0;width:1px;height:1px}
.mod__name{font-weight:600;font-size:14.5px;flex:1 1 auto}
.mod__sw{width:40px;height:23px;border-radius:999px;background:#d1d5db;position:relative;flex:0 0 auto;transition:background .16s}
.mod__sw::after{content:"";position:absolute;top:2px;left:2px;width:19px;height:19px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .16s}
.mod__cb:checked ~ .mod__sw{background:#10B981}
.mod__cb:checked ~ .mod__sw::after{left:19px}
.mod__cb:focus-visible ~ .mod__sw{outline:2px solid #617CBE;outline-offset:2px}
.field{margin-top:14px}
.field label{display:block;font-size:13px;font-weight:600;color:#374151;margin:0 0 5px}
.field input{width:100%;font:inherit;font-size:15px;padding:10px 12px;border:1px solid rgba(31,41,55,.2);
  border-radius:6px;background:#fff;outline:none}
.field input:focus{border-color:#617CBE;box-shadow:0 0 0 3px rgba(97,124,190,.25)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:480px){.grid2{grid-template-columns:1fr}}
.field-err{display:block;font-size:12.5px;color:#DC2626;margin-top:5px}
.alert{border-radius:8px;padding:11px 13px;font-size:13.5px;margin-top:14px}
.alert.err{background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.3);color:#b91c1c}
.cta-row{display:flex;align-items:center;gap:14px;margin-top:24px;border-top:1px solid rgba(31,41,55,.08);padding-top:20px}
.btn{background:#10B981;color:#fff;font:inherit;font-weight:600;font-size:15px;padding:13px 22px;border:none;border-radius:6px;cursor:pointer}
.btn:hover{background:#0ea875}
.btn:disabled{background:#d1d5db;cursor:not-allowed}
.hint{font-size:11.5px;color:#9CA3AF}
.card__foot{padding:14px 22px;border-top:1px solid rgba(31,41,55,.08);font-size:12px;color:#9CA3AF}
code{font-size:.9em;background:#F3F4F6;padding:.1em .35em;border-radius:4px}
pre{font-size:12.5px;background:#F3F4F6;padding:12px;border-radius:6px;overflow:auto}
.success-icon{width:44px;height:44px;border-radius:50%;background:rgba(16,185,129,.12);color:#10B981;
  display:inline-flex;align-items:center;justify-content:center}
ul.next{margin:12px 0 0;padding-left:20px;font-size:14.5px}
ul.next li{margin:6px 0}
CSS;
}

function page_top($title, $step = '')
{
    $mark = '<img class="mark" src="/assets/img/logo.png" alt="" width="26" height="26">';
    echo "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"UTF-8\">";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<meta name="color-scheme" content="light">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . h($title) . ' — PlugPHP installer</title>';
    echo '<style>' . install_css() . '</style></head><body>';
    echo '<div class="wrap"><div class="card">';
    echo '<div class="card__head">' . $mark . '<b>PlugPHP Setup</b>';
    if ($step !== '') {
        echo '<span class="step">' . h($step) . '</span>';
    }
    echo '</div><div class="bar"></div><div class="body">';
}

function page_bottom()
{
    echo '</div>'; // .body
    echo '<div class="card__foot">Built with PlugPHP — by Kabiru Sambo / Bubble Bot Solutions</div>';
    echo '</div></div></body></html>'; // .card .wrap
}

/* ------------------------------------------------------------------ *
 * Success screen
 * ------------------------------------------------------------------ */
if ($done) {
    page_top('Install complete', 'Done');
    echo '<div style="display:flex;align-items:center;gap:14px">'
       . '<span class="success-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>'
       . '<h1>Installation complete</h1></div>';
    echo '<p class="intro">Your database is set up, the admin user is created, and configuration has been written.</p>';
    echo '<div class="alert err" style="margin-top:18px"><strong>Delete <code>public/install.php</code> now</strong> — it can create an admin user and rewrite configuration, so leaving it in place is a security risk.</div>';
    echo '<div class="sec"><p class="sec__label">Next</p><ul class="next">'
       . '<li>Log in to the admin dashboard: <a href="/login">/login</a></li>'
       . '<li>View your site: <a href="/">/</a></li>'
       . '</ul></div>';
    echo '<p class="hint" style="margin-top:18px">SMTP settings were left blank in <code>.env</code> — fill them in to enable contact-form notifications and password-reset emails.</p>';
    page_bottom();
    exit;
}

/* ------------------------------------------------------------------ *
 * Installer form
 * ------------------------------------------------------------------ */
page_top('Install PlugPHP', '~5 min · no CLI');
?>
<h1>Install PlugPHP</h1>
<p class="intro">This sets up your database, creates your first admin user, and writes your configuration.</p>

<div class="sec">
    <p class="sec__label">1 · Environment</p>
    <ul class="checks">
        <?php foreach ($checks as $c): ?>
            <li>
                <span class="badge <?= h($c['status']) ?>"><?= h(strtoupper($c['status'])) ?></span>
                <span>
                    <?= h($c['label']) ?>
                    <?php if ($c['status'] !== 'pass'): ?><span class="check-note"><?= h($c['note']) ?></span><?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (!$canInstall): ?><div class="alert err">Resolve the FAIL items above, then reload this page before installing.</div><?php endif; ?>
</div>

<?php if (isset($errors['install'])): ?><div class="alert err"><?= h($errors['install']) ?></div><?php endif; ?>
<?php if (isset($errors['db'])): ?><div class="alert err"><?= h($errors['db']) ?></div><?php endif; ?>
<?php if (isset($errors['env'])): ?><div class="alert err"><?= h($errors['env']) ?></div><?php endif; ?>

<form method="post" action="install.php">
    <input type="hidden" name="action" value="install">

    <div class="sec">
        <p class="sec__label">2 · Modules</p>
        <p class="always"><code>home</code>, <code>admin-dashboard</code> and <code>auth</code> are always installed.</p>
        <div class="mods">
            <?php foreach ($OPTIONAL as $m): ?>
                <label class="mod">
                    <input type="checkbox" class="mod__cb" name="modules[]" value="<?= h($m) ?>" <?= in_array($m, $old['modules'], true) ? 'checked' : '' ?>>
                    <span class="mod__name"><?= h($m) ?></span>
                    <span class="mod__sw" aria-hidden="true"></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="sec">
        <p class="sec__label">3 · Site</p>
        <div class="field"><label for="app_name">Site name</label><input type="text" id="app_name" name="app_name" value="<?= h($old['app_name']) ?>"></div>
        <div class="field"><label for="app_url">Site URL</label><input type="url" id="app_url" name="app_url" value="<?= h($old['app_url']) ?>"><?php if (isset($errors['app_url'])): ?><span class="field-err"><?= h($errors['app_url']) ?></span><?php endif; ?></div>
    </div>

    <div class="sec">
        <p class="sec__label">4 · Database</p>
        <div class="grid2">
            <div class="field"><label for="db_host">Host</label><input type="text" id="db_host" name="db_host" value="<?= h($old['db_host']) ?>"></div>
            <div class="field"><label for="db_name">Name</label><input type="text" id="db_name" name="db_name" value="<?= h($old['db_name']) ?>"><?php if (isset($errors['db_name'])): ?><span class="field-err"><?= h($errors['db_name']) ?></span><?php endif; ?></div>
        </div>
        <div class="grid2">
            <div class="field"><label for="db_user">User</label><input type="text" id="db_user" name="db_user" value="<?= h($old['db_user']) ?>"><?php if (isset($errors['db_user'])): ?><span class="field-err"><?= h($errors['db_user']) ?></span><?php endif; ?></div>
            <div class="field"><label for="db_pass">Password</label><input type="password" id="db_pass" name="db_pass" autocomplete="new-password"></div>
        </div>
    </div>

    <div class="sec">
        <p class="sec__label">5 · Admin user</p>
        <div class="field"><label for="admin_name">Name</label><input type="text" id="admin_name" name="admin_name" value="<?= h($old['admin_name']) ?>"></div>
        <div class="field"><label for="admin_email">Email</label><input type="email" id="admin_email" name="admin_email" value="<?= h($old['admin_email']) ?>"><?php if (isset($errors['admin_email'])): ?><span class="field-err"><?= h($errors['admin_email']) ?></span><?php endif; ?></div>
        <div class="grid2">
            <div class="field"><label for="admin_pass">Password <span class="hint">(min 8)</span></label><input type="password" id="admin_pass" name="admin_pass" autocomplete="new-password"><?php if (isset($errors['admin_pass'])): ?><span class="field-err"><?= h($errors['admin_pass']) ?></span><?php endif; ?></div>
            <div class="field"><label for="admin_pass_confirm">Confirm password</label><input type="password" id="admin_pass_confirm" name="admin_pass_confirm" autocomplete="new-password"></div>
        </div>
    </div>

    <div class="cta-row">
        <button type="submit" class="btn" <?= $canInstall ? '' : 'disabled' ?>>Install PlugPHP</button>
        <span class="hint">~5 min · no CLI</span>
    </div>
</form>
<?php
page_bottom();
