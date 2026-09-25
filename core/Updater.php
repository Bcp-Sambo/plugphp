<?php

/**
 * Updater
 *
 * One-click in-dashboard updates, triggered only from an authenticated
 * /admin/updates action. Never from cron: shared-hosting cron is unreliable,
 * and silently auto-applying code to a live site is the opposite of what this
 * kit promises.
 *
 * THE BOUNDARY THAT MATTERS: an update may overwrite infrastructure code and
 * nothing else. `resources/`, `modules/*\/views/`, `.env`, `config/modules.php`
 * and every `.htaccess` are the developer's own work or the site's own
 * configuration, and are never touched. A package containing any path outside
 * SAFE_PATTERNS is rejected whole — maintainer discipline is not relied on.
 *
 * AI AGENTS: do not widen SAFE_PATTERNS to cover views, resources, config or
 * .htaccess. That list is the entire safety guarantee of this feature. If a
 * patch genuinely needs a change in one of those files, it is surfaced to the
 * admin as a manual diff to review, never applied automatically.
 */
final class Updater
{
    /**
     * The version of the files currently on disk. Ships inside each update
     * package (it lives in core/, which is overwritable), so it updates itself
     * when a package is applied.
     */
    public const VERSION = '1.1.0';

    /**
     * Where the update feed lives. HARDCODED ON PURPOSE — never read from
     * .env or the database. This class can write to core/, so the address it
     * trusts must not be redirectable by anyone who compromises a config file
     * or a settings row.
     *
     * Served from a dedicated branch rather than the default branch so that
     * routine development pushes cannot announce a release, and so a bad
     * release can be withdrawn without committing a version rollback onto the
     * branch whose code is still that version.
     */
    private const MANIFEST_URL =
        'https://raw.githubusercontent.com/Bcp-Sambo/plugphp/release-feed/version.json';

    /**
     * Hosts an update package may be downloaded from. Defence in depth: even
     * if the manifest itself were tampered with, the zip cannot be pulled from
     * an arbitrary server.
     */
    private const ALLOWED_DOWNLOAD_HOSTS = [
        'github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
        'raw.githubusercontent.com',
    ];

    /**
     * Every path an update package is allowed to contain, as anchored regexes
     * against the package-relative path. Anything else rejects the package.
     */
    private const SAFE_PATTERNS = [
        '#^core/[A-Za-z0-9_]+\.php$#',
        '#^core/migrations/[A-Za-z0-9_.-]+\.sql$#',
        '#^modules/[a-z0-9-]+/[A-Za-z0-9]+Module\.php$#',
        '#^modules/[a-z0-9-]+/routes\.php$#',
        '#^modules/[a-z0-9-]+/migrations/[A-Za-z0-9_.-]+\.sql$#',
        '#^public/index\.php$#',
    ];

    /** Settings keys. */
    private const KEY_VERSION   = 'plugphp_installed_version';
    private const KEY_UPDATED   = 'plugphp_last_update_at';
    private const KEY_ROLLBACK  = 'plugphp_rollback_backup';

    /** Rollback stays offered for this long after an update. */
    public const ROLLBACK_WINDOW_DAYS = 7;

    /** How many pre-update backups to keep. Shared hosting quotas are small. */
    private const KEEP_BACKUPS = 3;

    private const DOWNLOAD_TIMEOUT = 60;
    private const MAX_PACKAGE_BYTES = 52428800; // 50 MB

    /** @var string[] Human-readable log of the run in progress. */
    private static array $log = [];

    /**
     * Set once an update has been applied in THIS process.
     *
     * self::VERSION is a compile-time constant: after apply() replaces
     * core/Updater.php on disk, the value in memory is still the OLD version,
     * so a second update() call in the same request would believe it is still
     * behind and apply the package again — taking a fresh backup of the
     * already-updated tree and destroying the one good rollback point.
     * A normal click is its own request and reloads the constant; this guards
     * the double-submit and replay cases.
     */
    private static bool $appliedThisRequest = false;

    /* ============================================================== *
     * Public API
     * ============================================================== */

    /** The version recorded in the database, which may lag the files on disk. */
    public static function installedVersion(): string
    {
        return (string) Settings::get(self::KEY_VERSION, self::VERSION);
    }

    /**
     * Files and schema should agree. They disagree when a package was applied
     * but its migrations did not finish, or when files were restored from a
     * backup without the database following.
     */
    public static function versionsAgree(): bool
    {
        return self::installedVersion() === self::VERSION;
    }

    /**
     * Fetch the update feed. Returns null when it cannot be reached — an
     * update check must never take the dashboard down.
     *
     * @return array{version:string,security_advisory:bool,changelog_url:string,download_url:string,sha256:string}|null
     */
    public static function fetchManifest(): ?array
    {
        $raw = self::httpGet(self::MANIFEST_URL, 16384);
        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        foreach (['version', 'download_url', 'sha256'] as $required) {
            if (!isset($data[$required]) || !is_string($data[$required]) || $data[$required] === '') {
                return null;
            }
        }

        if (!preg_match('/^\d+\.\d+\.\d+$/', $data['version'])) {
            return null;
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $data['sha256'])) {
            return null;
        }

        return [
            'version'           => $data['version'],
            'security_advisory' => !empty($data['security_advisory']),
            'changelog_url'     => is_string($data['changelog_url'] ?? null) ? $data['changelog_url'] : '',
            'download_url'      => $data['download_url'],
            'sha256'            => strtolower($data['sha256']),
        ];
    }

    /** True when $manifest names a version newer than the files on disk. */
    public static function isNewer(array $manifest): bool
    {
        return version_compare($manifest['version'], self::VERSION, '>');
    }

    /**
     * Readiness checks, run before anything is downloaded. Shared by the
     * dashboard panel and by health.php.
     *
     * @return array<int, array{name:string,status:string,detail:string}>
     */
    public static function preflight(): array
    {
        $out = [];
        $root = self::root();

        // Writability of everything an update may touch. A file uploaded over
        // FTP can be owned by a different user than the one PHP runs as, so
        // this genuinely varies by host and must be checked before, not during.
        $unwritable = [];
        foreach (self::updatableProjectPaths() as $rel) {
            $abs = $root . '/' . $rel;
            if (file_exists($abs) && !is_writable($abs)) {
                $unwritable[] = $rel;
            }
        }
        foreach (['core', 'core/migrations', 'public'] as $dir) {
            if (is_dir($root . '/' . $dir) && !is_writable($root . '/' . $dir)) {
                $unwritable[] = $dir . '/';
            }
        }

        $out[] = [
            'name'   => 'Update target files are writable',
            'status' => $unwritable ? 'FAIL' : 'PASS',
            'detail' => $unwritable
                ? 'Not writable by PHP: ' . implode(', ', array_slice($unwritable, 0, 6))
                  . (count($unwritable) > 6 ? ' and ' . (count($unwritable) - 6) . ' more.' : '.')
                  . ' Set these to 0644 (files) / 0755 (directories) owned by the account PHP runs as.'
                : 'PHP can write to core/, public/index.php and every module class.',
        ];

        // ZipArchive is a hard dependency: packaging the backup, reading the
        // package and restoring a rollback all need it.
        $hasZip = class_exists('ZipArchive');
        $out[] = [
            'name'   => 'ZipArchive extension',
            'status' => $hasZip ? 'PASS' : 'FAIL',
            'detail' => $hasZip
                ? 'Available.'
                : 'The zip extension is not installed. Updates cannot be downloaded, '
                  . 'backed up or rolled back without it. Enable "zip" in cPanel under '
                  . 'Select PHP Version -> Extensions.',
        ];

        $canFetch = function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL);
        $out[] = [
            'name'   => 'Can download over HTTPS',
            'status' => $canFetch ? 'PASS' : 'FAIL',
            'detail' => $canFetch
                ? (function_exists('curl_init') ? 'The curl extension is available.' : 'allow_url_fopen is enabled.')
                : 'Neither the curl extension nor allow_url_fopen is available, so the '
                  . 'update package cannot be fetched. Ask your host to enable curl.',
        ];

        $free = @disk_free_space($root);
        if ($free === false) {
            $out[] = ['name' => 'Free disk space', 'status' => 'INFO',
                      'detail' => 'Could not determine free disk space on this host.'];
        } else {
            $mb = (int) round($free / 1048576);
            $ok = $free > 104857600;      // 100 MB
            $out[] = [
                'name'   => 'Free disk space',
                'status' => $ok ? 'PASS' : 'WARN',
                'detail' => $mb . ' MB free.' . ($ok ? '' :
                    ' An update needs room for the download, a staging copy and a backup. '
                    . 'Shared hosting quotas are often small — free some space first.'),
            ];
        }

        $storage = $root . '/storage';
        $storageOk = is_dir($storage) && is_writable($storage);
        $out[] = [
            'name'   => 'storage/ is writable',
            'status' => $storageOk ? 'PASS' : 'FAIL',
            'detail' => $storageOk
                ? 'Downloads and backups can be written.'
                : 'storage/ is not writable, so the update package and the pre-update '
                  . 'backup cannot be saved. Set it to 0755 owned by the PHP user.',
        ];

        return $out;
    }

    /** True when no preflight check failed. */
    public static function preflightPasses(?array $checks = null): bool
    {
        foreach ($checks ?? self::preflight() as $c) {
            if ($c['status'] === 'FAIL') {
                return false;
            }
        }
        return true;
    }

    /**
     * Run the full update. Returns the step log either way; check
     * $result['success'].
     *
     * @return array{success:bool, log:string[], version:?string}
     */
    public static function update(): array
    {
        self::$log = [];
        $staging = null;
        $zipPath = null;

        if (self::$appliedThisRequest) {
            return self::fail('An update has already been applied in this request. '
                . 'Reload the page to see the current version.');
        }

        try {
            // 1. Manifest.
            $manifest = self::fetchManifest();
            if ($manifest === null) {
                return self::fail('Could not reach the update server, or it returned something unreadable. Nothing was changed.');
            }
            self::note('Update feed reports version ' . $manifest['version'] . '; this site is on ' . self::VERSION . '.');

            if (!self::isNewer($manifest)) {
                return self::fail('This site is already up to date. Nothing was changed.');
            }

            // 2. Preflight — stop before downloading anything.
            $checks = self::preflight();
            if (!self::preflightPasses($checks)) {
                foreach ($checks as $c) {
                    if ($c['status'] === 'FAIL') {
                        self::note('Blocked: ' . $c['name'] . ' — ' . $c['detail']);
                    }
                }
                return self::fail('Preflight checks failed, so nothing was downloaded and no file was changed.');
            }
            self::note('Preflight checks passed.');

            // 3. Download.
            $zipPath = self::download($manifest['download_url'], $manifest['version']);
            if ($zipPath === null) {
                return self::fail('The update package could not be downloaded. No file was changed.');
            }
            self::note('Downloaded update package (' . self::humanBytes((int) filesize($zipPath)) . ').');

            // 4. Verify integrity. A mismatch aborts unconditionally.
            $actual = hash_file('sha256', $zipPath);
            if (!hash_equals($manifest['sha256'], (string) $actual)) {
                @unlink($zipPath);
                self::note('Expected checksum ' . substr($manifest['sha256'], 0, 16) . '…, got ' . substr((string) $actual, 0, 16) . '…');
                return self::fail(
                    'Update package failed verification and was NOT applied. The downloaded '
                    . 'file does not match the checksum the update server published, which '
                    . 'means it was corrupted in transit or tampered with. The file has been '
                    . 'deleted. No project file was touched. Retrying will not bypass this check.'
                );
            }
            self::note('Checksum verified against the published SHA-256.');

            // 5. Validate contents, into a staging directory outside the project tree.
            $staging = self::stagePackage($zipPath);
            if ($staging === null) {
                return self::fail('The update package could not be read. No file was changed.');
            }
            $files = self::collectStagedFiles($staging);
            if ($files === []) {
                self::cleanup($staging, $zipPath);
                return self::fail('The update package is empty. No file was changed.');
            }
            $rejected = array_values(array_filter($files, fn($rel) => !self::isSafePath($rel)));
            if ($rejected) {
                self::cleanup($staging, $zipPath);
                foreach (array_slice($rejected, 0, 5) as $r) {
                    self::note('Rejected path: ' . $r);
                }
                return self::fail(
                    'The update package contains ' . count($rejected) . ' file(s) outside the '
                    . 'paths an update is allowed to modify — it was rejected whole and NOT '
                    . 'applied. Updates may never overwrite your views, your layout, your '
                    . '.env, your module configuration or your .htaccess files.'
                );
            }
            self::note('Package contents validated: ' . count($files) . ' file(s), all within the updatable set.');

            // 6. Backup the current version of every file about to change.
            $backup = self::backup($files, $manifest['version']);
            if ($backup === null) {
                self::cleanup($staging, $zipPath);
                return self::fail('Could not write a pre-update backup, so the update was not applied.');
            }
            self::note('Backed up ' . count($files) . ' file(s) to ' . basename($backup) . '.');

            // 7. Apply.
            $applied = self::apply($staging, $files);
            if ($applied !== true) {
                self::note('Copy failed on: ' . $applied);
                self::note('Rolling back automatically…');
                $restored = self::restoreFrom($backup);
                self::cleanup($staging, $zipPath);
                return self::fail(
                    'A file could not be written partway through the update, so the update '
                    . 'was stopped and ' . ($restored
                        ? 'the previous files were restored from the backup. The site is as it was.'
                        : 'the automatic rollback ALSO failed. Restore from '
                          . basename($backup) . ' manually before using the site.')
                );
            }
            self::$appliedThisRequest = true;
            self::note('Applied ' . count($files) . ' file(s).');

            // 8. Migrations. Already-applied files are skipped by migrations_log.
            $ran = self::runPendingMigrations();
            self::note($ran === 0 ? 'No new migrations to run.' : 'Ran ' . $ran . ' new migration(s).');

            // 9. Record the new version and arm the rollback window.
            Settings::set(self::KEY_VERSION, $manifest['version']);
            Settings::set(self::KEY_UPDATED, (string) time());
            Settings::set(self::KEY_ROLLBACK, basename($backup));

            self::cleanup($staging, $zipPath);
            self::note('Updated to version ' . $manifest['version'] . '.');
            self::writeLogFile();

            return ['success' => true, 'log' => self::$log, 'version' => $manifest['version']];
        } catch (Throwable $e) {
            // The message can contain absolute filesystem paths; log it, do not
            // print it. The admin gets the step log, which is written for them.
            error_log('Updater error: ' . $e->getMessage());
            if ($staging !== null || $zipPath !== null) {
                self::cleanup($staging, $zipPath);
            }
            return self::fail('The update stopped on an unexpected error. It has been written to storage/logs/. No further changes were made.');
        }
    }

    /**
     * Finish an update whose files landed but whose migrations did not.
     *
     * Without this the admin is stuck: the version check compares against the
     * constant, which already reads the NEW version, so "Update now" correctly
     * reports nothing to do while the schema is still behind.
     *
     * @return array{success:bool, log:string[]}
     */
    public static function finishInterrupted(): array
    {
        self::$log = [];

        if (self::versionsAgree()) {
            return ['success' => false, 'log' => ['Nothing to finish — the files and the database already agree.']];
        }

        try {
            $ran = self::runPendingMigrations();
            Settings::set(self::KEY_VERSION, self::VERSION);
            self::note($ran === 0
                ? 'No migrations were outstanding; the recorded version has been corrected to ' . self::VERSION . '.'
                : 'Ran ' . $ran . ' outstanding migration(s); now recorded as ' . self::VERSION . '.');
            self::writeLogFile();
            return ['success' => true, 'log' => self::$log];
        } catch (Throwable $e) {
            error_log('Updater finishInterrupted error: ' . $e->getMessage());
            self::note('Could not finish the interrupted update. Details were written to storage/logs/.');
            self::writeLogFile();
            return ['success' => false, 'log' => self::$log];
        }
    }
    /** True while the rollback button should still be offered. */
    public static function rollbackAvailable(): bool
    {
        return self::rollbackBackupPath() !== null;
    }

    /** Timestamp of the last successful update, or null. */
    public static function lastUpdatedAt(): ?int
    {
        $t = Settings::get(self::KEY_UPDATED);
        return $t === null ? null : (int) $t;
    }

    /**
     * Restore the most recent pre-update backup.
     *
     * @return array{success:bool, log:string[]}
     */
    public static function rollback(): array
    {
        self::$log = [];

        $backup = self::rollbackBackupPath();
        if ($backup === null) {
            return ['success' => false, 'log' => ['There is no backup available to roll back to.']];
        }

        self::note('Restoring from ' . basename($backup) . '.');
        if (!self::restoreFrom($backup)) {
            self::writeLogFile();
            return ['success' => false, 'log' => array_merge(self::$log,
                ['The restore failed. The site may be in a mixed state — restore '
                 . basename($backup) . ' by hand before using it.'])];
        }

        // The files are back at the previous version, so the recorded version
        // must follow them. It cannot be self::VERSION: this class was loaded
        // from the NEW core/Updater.php at the top of this request, so the
        // constant in memory still holds the version we just undid. The
        // backup's own manifest is the only reliable source for what the files
        // have reverted to.
        $previous = self::backupFromVersion($backup);
        if ($previous !== null) {
            Settings::set(self::KEY_VERSION, $previous);
        }

        // Clear the rollback marker: the backup has been consumed.
        Settings::delete(self::KEY_ROLLBACK);

        // The database schema is NOT rolled back. Migrations are forward-only,
        // and dropping columns to match older code would destroy data.

        self::note('Files restored. Database changes were left in place — migrations are '
                 . 'forward-only, and reversing them would destroy data. Unused new columns are harmless.');
        self::writeLogFile();

        return ['success' => true, 'log' => self::$log];
    }

    /* ============================================================== *
     * Internals
     * ============================================================== */

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /** Project-relative paths that currently exist and an update may replace. */
    private static function updatableProjectPaths(): array
    {
        $root = self::root();
        $paths = ['public/index.php'];

        foreach ((array) glob($root . '/core/*.php') as $f) {
            $paths[] = 'core/' . basename($f);
        }
        foreach ((array) glob($root . '/modules/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            foreach ((array) glob($dir . '/*Module.php') as $f) {
                $paths[] = "modules/{$name}/" . basename($f);
            }
            if (is_file($dir . '/routes.php')) {
                $paths[] = "modules/{$name}/routes.php";
            }
        }

        return $paths;
    }

    /** Is this package-relative path inside the updatable set? */
    public static function isSafePath(string $rel): bool
    {
        $rel = str_replace('\\', '/', $rel);

        // Reject traversal, absolute paths and hidden segments outright,
        // before the allowlist gets a chance to be clever about them.
        if ($rel === '' || str_starts_with($rel, '/') || str_contains($rel, '..')) {
            return false;
        }
        if (preg_match('#(^|/)\.#', $rel)) {
            return false;
        }

        foreach (self::SAFE_PATTERNS as $pattern) {
            if (preg_match($pattern, $rel)) {
                return true;
            }
        }
        return false;
    }

    /** GET a URL over HTTPS, via curl when present, else the stream wrapper. */
    private static function httpGet(string $url, int $maxBytes): ?string
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => self::DOWNLOAD_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS_STR  => 'https',
                CURLOPT_REDIR_PROTOCOLS_STR => 'https',
                CURLOPT_USERAGENT      => 'PlugPHP-Updater/' . self::VERSION,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            // No curl_close(): it is a no-op from PHP 8.0 (the handle is freed
            // when $ch goes out of scope) and emits a deprecation notice on 8.5.
            unset($ch);

            if (!is_string($body) || $code !== 200 || strlen($body) > $maxBytes) {
                return null;
            }
            return $body;
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => ['timeout' => self::DOWNLOAD_TIMEOUT, 'follow_location' => 1, 'max_redirects' => 5,
                       'user_agent' => 'PlugPHP-Updater/' . self::VERSION],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);

        return (is_string($body) && strlen($body) <= $maxBytes) ? $body : null;
    }

    /** Download the package into storage/update-tmp/. */
    private static function download(string $url, string $version): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!str_starts_with(strtolower($url), 'https://') || !in_array($host, self::ALLOWED_DOWNLOAD_HOSTS, true)) {
            self::note('Refused to download from an unexpected host: ' . ($host ?: 'unknown') . '.');
            return null;
        }

        $body = self::httpGet($url, self::MAX_PACKAGE_BYTES);
        if ($body === null) {
            return null;
        }

        $dir = self::root() . '/storage/update-tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return null;
        }
        self::protectDirectory($dir);

        $path = $dir . '/plugphp-update-' . preg_replace('/[^0-9.]/', '', $version) . '.zip';
        return @file_put_contents($path, $body) === false ? null : $path;
    }

    /** Extract the package to a staging directory, guarding against zip-slip. */
    private static function stagePackage(string $zipPath): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $staging = self::root() . '/storage/update-tmp/staging-' . bin2hex(random_bytes(6));
        if (!@mkdir($staging, 0755, true)) {
            $zip->close();
            return null;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/')) {
                continue;
            }
            // Validate BEFORE extracting. extractTo() on an unvalidated archive
            // is how a "../../.env" entry escapes the staging directory.
            if (!self::isSafePath($name)) {
                $zip->close();
                self::rrmdir($staging);
                self::note('Package contains a disallowed path: ' . substr($name, 0, 120));
                return null;
            }
            $target = $staging . '/' . $name;
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
                $zip->close();
                self::rrmdir($staging);
                return null;
            }
            $stream = $zip->getStream($name);
            if ($stream === false || @file_put_contents($target, $stream) === false) {
                if (is_resource($stream)) { fclose($stream); }
                $zip->close();
                self::rrmdir($staging);
                return null;
            }
            fclose($stream);
        }

        $zip->close();
        return $staging;
    }

    /** Package-relative paths present in the staging directory. */
    private static function collectStagedFiles(string $staging): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if ($f->isFile()) {
                $files[] = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($staging))), '/');
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Zip the current version of each file the update will replace. Only the
     * affected files, not the whole project — shared-hosting storage is tight.
     */
    private static function backup(array $files, string $version): ?string
    {
        $dir = self::root() . '/storage/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return null;
        }
        self::protectDirectory($dir);

        $path = $dir . '/pre-update-' . preg_replace('/[^0-9.]/', '', $version)
              . '-' . date('Ymd-His') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        // A manifest of which files were NEW (absent before the update) so a
        // rollback can delete them rather than leaving orphans behind.
        $created = [];
        foreach ($files as $rel) {
            $abs = self::root() . '/' . $rel;
            if (is_file($abs)) {
                $zip->addFile($abs, $rel);
            } else {
                $created[] = $rel;
            }
        }
        $zip->addFromString('.plugphp-backup.json', (string) json_encode([
            'from_version' => self::VERSION,
            'to_version'   => $version,
            'created_at'   => date('c'),
            'new_files'    => $created,
        ], JSON_PRETTY_PRINT));

        if (!$zip->close()) {
            return null;
        }

        self::pruneBackups($dir);
        return $path;
    }

    private static function pruneBackups(string $dir): void
    {
        $backups = (array) glob($dir . '/pre-update-*.zip');
        if (count($backups) <= self::KEEP_BACKUPS) {
            return;
        }
        usort($backups, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($backups, self::KEEP_BACKUPS) as $old) {
            @unlink($old);
        }
    }

    /**
     * Copy staged files over the project. Returns true, or the relative path
     * of the file that failed.
     *
     * Each file is written to a temp name and renamed into place, so a failed
     * write cannot leave a half-written PHP file that would fatal on the next
     * request. core/Updater.php is copied last — this class is executing, and
     * replacing it before the run finishes serves no purpose.
     *
     * @return true|string
     */
    private static function apply(string $staging, array $files)
    {
        usort($files, fn($a, $b) => (int) ($a === 'core/Updater.php') <=> (int) ($b === 'core/Updater.php'));

        foreach ($files as $rel) {
            $src = $staging . '/' . $rel;
            $dst = self::root() . '/' . $rel;
            $dir = dirname($dst);

            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return $rel;
            }

            $tmp = $dst . '.plugphp-new';
            if (@copy($src, $tmp) === false) {
                @unlink($tmp);
                return $rel;
            }
            @chmod($tmp, 0644);
            if (@rename($tmp, $dst) === false) {
                @unlink($tmp);
                return $rel;
            }
        }

        return true;
    }

    /** Restore every file held in a backup zip, and remove files it records as new. */
    private static function restoreFrom(string $backupPath): bool
    {
        if (!is_file($backupPath) || !class_exists('ZipArchive')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($backupPath) !== true) {
            return false;
        }

        $meta = $zip->getFromName('.plugphp-backup.json');
        $newFiles = [];
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            if (is_array($decoded) && isset($decoded['new_files']) && is_array($decoded['new_files'])) {
                $newFiles = $decoded['new_files'];
            }
        }

        $ok = true;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '.plugphp-backup.json' || str_ends_with($name, '/')) {
                continue;
            }
            if (!self::isSafePath($name)) {
                continue; // a backup should never contain one, but never trust it
            }
            $dst = self::root() . '/' . $name;
            $body = $zip->getFromIndex($i);
            if ($body === false) { $ok = false; continue; }
            if (!is_dir(dirname($dst))) { @mkdir(dirname($dst), 0755, true); }

            $tmp = $dst . '.plugphp-restore';
            if (@file_put_contents($tmp, $body) === false || @rename($tmp, $dst) === false) {
                @unlink($tmp);
                $ok = false;
            }
        }
        $zip->close();

        // Files the update introduced did not exist before, so restoring means
        // removing them.
        foreach ($newFiles as $rel) {
            if (is_string($rel) && self::isSafePath($rel)) {
                @unlink(self::root() . '/' . $rel);
            }
        }

        return $ok;
    }

    /** Most recent backup, if still inside the rollback window. */
    private static function rollbackBackupPath(): ?string
    {
        $name = Settings::get(self::KEY_ROLLBACK);
        $at   = self::lastUpdatedAt();

        if (!is_string($name) || $name === '' || $at === null) {
            return null;
        }
        if (time() - $at > self::ROLLBACK_WINDOW_DAYS * 86400) {
            return null;
        }

        $path = self::root() . '/storage/backups/' . basename($name);
        return is_file($path) ? $path : null;
    }

    /** The version a backup was taken FROM, read from its own manifest. */
    private static function backupFromVersion(string $backupPath): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($backupPath) !== true) {
            return null;
        }
        $meta = $zip->getFromName('.plugphp-backup.json');
        $zip->close();

        if (!is_string($meta)) {
            return null;
        }
        $decoded = json_decode($meta, true);
        $from = is_array($decoded) ? ($decoded['from_version'] ?? null) : null;

        return (is_string($from) && preg_match('/^\d+\.\d+\.\d+$/', $from)) ? $from : null;
    }
    /** Run every core and module migration not yet in migrations_log. */
    private static function runPendingMigrations(): int
    {
        $root = self::root();
        $ran = 0;

        $files = (array) glob($root . '/core/migrations/*.sql');
        foreach ((array) require $root . '/config/modules.php' as $moduleName) {
            foreach ((array) glob($root . '/modules/' . $moduleName . '/migrations/*.sql') as $f) {
                $files[] = $f;
            }
        }
        sort($files);

        foreach ($files as $f) {
            $key = ltrim(str_replace([$root, '\\'], ['', '/'], $f), '/');
            if (Database::migrationWasApplied($key)) {
                continue;
            }
            Database::runMigrationFile($f);
            self::note('Migration applied: ' . basename($f));
            $ran++;
        }

        return $ran;
    }

    private static function cleanup(?string $staging, ?string $zipPath): void
    {
        if ($staging !== null && is_dir($staging)) {
            self::rrmdir($staging);
        }
        if ($zipPath !== null && is_file($zipPath)) {
            @unlink($zipPath);
        }
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /** Drop a deny-all .htaccess into a storage directory, in case of a bad docroot. */
    private static function protectDirectory(string $dir): void
    {
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
    }

    private static function note(string $line): void
    {
        self::$log[] = $line;
    }

    /** @return array{success:false, log:string[], version:null} */
    private static function fail(string $why): array
    {
        self::note($why);
        self::writeLogFile();
        return ['success' => false, 'log' => self::$log, 'version' => null];
    }

    private static function writeLogFile(): void
    {
        $dir = self::root() . '/storage/logs';
        if (!is_dir($dir)) {
            return;
        }
        @file_put_contents(
            $dir . '/updates.log',
            '[' . date('c') . "]\n  " . implode("\n  ", self::$log) . "\n\n",
            FILE_APPEND
        );
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
