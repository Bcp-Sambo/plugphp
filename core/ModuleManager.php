<?php

/**
 * ModuleManager — turning modules on and off after install time.
 *
 * Until this existed, module selection happened exactly once, in install.php's
 * picker. A module shipped in a later release could not be switched on at all
 * without hand-editing config/modules.php, which is precisely the kind of
 * thing this project exists to avoid asking anyone to do.
 *
 * config/modules.php is on the updater's untouched list, and stays there. A
 * module arriving in a release still never activates itself; this only adds a
 * deliberate, logged, administrator-driven way to activate one.
 *
 * TWO SWITCHES, DELIBERATELY DIFFERENT:
 *   Disable (here)                 the module is not loaded at all — no routes,
 *                                  no admin panel, its pages 404.
 *   Hide (Settings::isModuleVisible) the module runs normally; only its link is
 *                                  taken out of the public menu.
 * They are different granularities, not duplicates. Do not collapse them.
 *
 * AI AGENTS: every path through here that changes config/modules.php or deletes
 * a folder MUST write a SecurityLog entry. No exceptions — this is the one part
 * of the dashboard that can silently change what a site does.
 */
final class ModuleManager
{
    /**
     * Modules without which the site cannot be administered.
     *
     * Disabling auth or admin-dashboard would remove the login and the
     * dashboard, leaving no way back in short of editing config/modules.php by
     * hand — the exact situation this class removes the need for. install.php
     * guards the same set; this is the one definition, so the two cannot drift.
     */
    public const ALWAYS_ON = ['home', 'admin-dashboard', 'auth'];

    /** Settings key holding modules deleted on purpose, so updates leave them deleted. */
    public const KEY_IGNORED = 'ignored_modules';

    /* ----------------------------------------------------------------- *
     * Reading state
     * ----------------------------------------------------------------- */

    /** Module folder names physically present on disk. */
    public static function installed(): array
    {
        $out = [];
        foreach ((array) glob(self::root() . '/modules/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            if (self::isValidName($name)) {
                $out[] = $name;
            }
        }
        sort($out);
        return $out;
    }

    /** The enabled list, from the same file the bootstrap reads. */
    public static function enabled(): array
    {
        $path = self::root() . '/config/modules.php';
        if (!is_file($path)) {
            return [];
        }
        $list = @include $path;
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_filter($list, static fn($m) => is_string($m) && self::isValidName($m)));
    }

    /** Modules deliberately deleted, which an update must not reinstate. */
    public static function ignored(): array
    {
        if (!class_exists('Settings')) {
            return [];
        }
        $raw = Settings::get(self::KEY_IGNORED);
        $list = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_filter($list, static fn($m) => is_string($m) && self::isValidName($m)));
    }

    public static function isEnabled(string $name): bool
    {
        return in_array($name, self::enabled(), true);
    }

    public static function isAlwaysOn(string $name): bool
    {
        return in_array($name, self::ALWAYS_ON, true);
    }

    public static function isIgnored(string $name): bool
    {
        return in_array($name, self::ignored(), true);
    }

    /**
     * Everything the Module Management screen needs, in one pass.
     *
     * This is the one list where a disabled module always remains visible —
     * it is how it gets switched back on.
     *
     * @return array<int, array{name:string,enabled:bool,always_on:bool,manifest:?array,summary:string}>
     */
    public static function overview(): array
    {
        $enabled = self::enabled();
        $out = [];

        foreach (self::installed() as $name) {
            $out[] = [
                'name'      => $name,
                'enabled'   => in_array($name, $enabled, true),
                'always_on' => self::isAlwaysOn($name),
                'manifest'  => class_exists('Module') ? Module::manifestFor($name) : null,
                'summary'   => class_exists('Module') ? Module::capabilitySummary($name) : '',
            ];
        }

        return $out;
    }

    /* ----------------------------------------------------------------- *
     * Changing state
     * ----------------------------------------------------------------- */

    /**
     * Switch a module on: add it to the enabled list, create its schema now,
     * and record who did it.
     *
     * Migrations run AT ACTIVATION, not at update time. A module enabled six
     * months after it shipped needs its tables created at that moment —
     * Updater's own runner only ever walks the enabled list, so until now its
     * migrations were correctly skipped and had nowhere to be run from.
     *
     * @return array{success:bool, message:string}
     */
    public static function enable(string $name): array
    {
        if (!self::isValidName($name)) {
            return self::no('That is not a valid module name.');
        }
        if (!in_array($name, self::installed(), true)) {
            return self::no('There is no module by that name on this site.');
        }
        if (self::isEnabled($name)) {
            return self::no(self::label($name) . ' is already enabled.');
        }

        $list = self::enabled();
        $list[] = $name;

        if (!self::writeEnabled($list)) {
            return self::no('Could not write config/modules.php. Check that it is writable.');
        }

        // Un-ignore: switching a module on is a clearer statement of intent
        // than whatever caused it to be ignored before.
        self::setIgnored(array_values(array_diff(self::ignored(), [$name])));

        $ran = 0;
        try {
            // Safe to run the whole pending set: migrations_log makes every
            // already-applied file a no-op, so in practice this runs exactly
            // the newly-enabled module's migrations.
            $ran = class_exists('Updater') ? Updater::runMigrations() : 0;
        } catch (Throwable $e) {
            error_log('ModuleManager::enable migrations failed for ' . $name . ': ' . $e->getMessage());
            return self::no(
                self::label($name) . ' was enabled, but its database setup did not finish. '
                . 'Check storage/logs/, then disable and re-enable it to try again.'
            );
        }

        self::audit(SecurityLog::MODULE_ENABLED, $name, ['migrations_run' => $ran]);

        return self::yes(
            self::label($name) . ' is now enabled.'
            . ($ran > 0 ? ' ' . $ran . ' database migration(s) ran.' : ' Its database setup was already in place.')
        );
    }

    /**
     * Switch a module off. Files stay, tables stay, data stays — re-enabling
     * puts it back exactly as it was.
     *
     * @return array{success:bool, message:string}
     */
    public static function disable(string $name): array
    {
        if (!self::isValidName($name)) {
            return self::no('That is not a valid module name.');
        }
        if (self::isAlwaysOn($name)) {
            return self::no(
                self::label($name) . ' cannot be disabled — the site needs it to be '
                . 'administered at all. Disabling it would remove your way back in.'
            );
        }
        if (!self::isEnabled($name)) {
            return self::no(self::label($name) . ' is already disabled.');
        }

        if (!self::writeEnabled(array_values(array_diff(self::enabled(), [$name])))) {
            return self::no('Could not write config/modules.php. Check that it is writable.');
        }

        self::audit(SecurityLog::MODULE_DISABLED, $name);

        return self::yes(
            self::label($name) . ' is now disabled. Its files and data are untouched, '
            . 'so enabling it again restores it exactly as it was.'
        );
    }

    /**
     * Delete a module's folder and remember that the deletion was deliberate.
     *
     * THE MOST DESTRUCTIVE ACTION IN THE DASHBOARD. It takes a name from a form
     * post and recursively deletes a directory, so the name is checked against
     * a pattern, against the set actually installed, and the resolved path is
     * required to be a direct child of modules/ that is not a symlink. A
     * symlinked module folder would otherwise make this delete whatever it
     * points at.
     *
     * @return array{success:bool, message:string}
     */
    public static function removePermanently(string $name): array
    {
        if (!self::isValidName($name)) {
            return self::no('That is not a valid module name.');
        }
        if (self::isAlwaysOn($name)) {
            return self::no(self::label($name) . ' cannot be removed — the site needs it.');
        }
        if (!in_array($name, self::installed(), true)) {
            return self::no('There is no module by that name on this site.');
        }

        $modules = realpath(self::root() . '/modules');
        $target  = self::root() . '/modules/' . $name;

        if (is_link($target)) {
            return self::no(
                self::label($name) . ' is a symbolic link, not a real module folder. '
                . 'It was not deleted — remove it by hand if that is what you intend.'
            );
        }

        $real = realpath($target);
        if ($modules === false || $real === false || dirname($real) !== $modules) {
            return self::no('That module folder is not where it should be, so it was not deleted.');
        }

        // Off first: a half-deleted module that is still in the enabled list
        // would have the bootstrap trying to load files that no longer exist.
        if (self::isEnabled($name) && !self::writeEnabled(array_values(array_diff(self::enabled(), [$name])))) {
            return self::no('Could not write config/modules.php, so nothing was deleted.');
        }

        if (!self::rrmdir($real)) {
            return self::no(
                'Could not fully delete ' . self::label($name) . '. It has been disabled, '
                . 'and whatever remains can be deleted by hand.'
            );
        }

        self::setIgnored(array_values(array_unique(array_merge(self::ignored(), [$name]))));
        self::audit(SecurityLog::MODULE_REMOVED, $name);

        return self::yes(
            self::label($name) . ' has been deleted and added to the ignored list, so '
            . 'future updates will not put it back. Restoring it means uploading its '
            . 'folder by hand and removing it from that list.'
        );
    }

    /* ----------------------------------------------------------------- *
     * Internals
     * ----------------------------------------------------------------- */

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,48}[a-z0-9]$/', $name);
    }

    /** Human label for a folder name: contact-form -> Contact form. */
    public static function label(string $name): string
    {
        return ucfirst(str_replace('-', ' ', $name));
    }

    /** Write the enabled list in the same shape install.php produces. */
    private static function writeEnabled(array $names): bool
    {
        $names = array_values(array_unique(array_filter($names, [self::class, 'isValidName'])));

        // Always-on modules are added back regardless of what was passed, so no
        // code path can produce a list that locks the site out of itself.
        foreach (self::ALWAYS_ON as $m) {
            if (!in_array($m, $names, true) && in_array($m, self::installed(), true)) {
                $names[] = $m;
            }
        }

        $php = "<?php\n\n// The enabled module list. Written by install.php and by\n"
             . "// /admin/modules. Never modified by an update.\n\nreturn [\n";
        foreach ($names as $m) {
            $php .= "    '" . $m . "',\n";
        }
        $php .= "];\n";

        $path = self::root() . '/config/modules.php';
        $tmp  = $path . '.tmp';

        // Written via a temporary file and renamed, so a request arriving mid-write
        // never reads a half-written list and loads no modules at all.
        if (@file_put_contents($tmp, $php) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        @chmod($path, 0644);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        return true;
    }

    private static function setIgnored(array $names): void
    {
        if (class_exists('Settings')) {
            Settings::set(self::KEY_IGNORED, json_encode(array_values($names), JSON_UNESCAPED_SLASHES));
        }
    }

    private static function audit(string $event, string $module, array $extra = []): void
    {
        if (class_exists('SecurityLog')) {
            SecurityLog::log($event, ['module' => $module] + $extra);
        }
    }

    /** Recursive delete, bounded to a path the caller has already resolved. */
    private static function rrmdir(string $dir): bool
    {
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            // Never follow a link out of the tree being deleted.
            if (is_link($path) || is_file($path)) {
                if (!@unlink($path)) {
                    return false;
                }
            } elseif (is_dir($path)) {
                if (!self::rrmdir($path)) {
                    return false;
                }
            }
        }

        return @rmdir($dir);
    }

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    private static function yes(string $m): array
    {
        return ['success' => true, 'message' => $m];
    }

    private static function no(string $m): array
    {
        return ['success' => false, 'message' => $m];
    }
}
