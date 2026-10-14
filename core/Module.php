<?php

/**
 * Module (abstract base class)
 *
 * Every folder under /modules/* must contain one class extending
 * this, named to match its folder (e.g. modules/blog/BlogModule.php).
 * This does not stop bad code inside a module, but it forces every
 * module to expose the same shape so the bootstrap, the installer,
 * and any future tooling can treat all modules uniformly.
 *
 * AI AGENTS: when adding a new module, extend this class. Do not
 * invent a different registration pattern. Do not skip migrate()
 * even if the module has no tables (return an empty array).
 */
abstract class Module
{
    /**
     * Read a module's manifest.json — its declared capabilities.
     *
     * HONEST FRAMING, and this matters in any copy built on top of it: this is
     * a DECLARATION, not a sandbox. PHP on shared hosting cannot stop a module
     * touching something it did not declare, and nothing here is enforced at
     * runtime. Its value is as an audit surface and a disclosure to the person
     * deciding whether to switch a module on. Never describe it to a user as
     * "sandboxed", "isolated" or "restricted".
     *
     * Read by install.php and the dashboard only. Never loaded during a normal
     * request, so it costs a live site nothing.
     *
     * `changelog` is optional and describes what changed in THIS module at its
     * current version — it is what /admin/updates shows beside the version bump,
     * instead of one undifferentiated "an update is available".
     *
     * @return array{module:string,version:string,changelog:?string,db_tables_owned:array<int,string>,handles_file_uploads:bool,network_access:array<int,string>,sends_email:bool,admin_routes:array<int,string>,public_routes:array<int,string>}|null
     */
    public static function manifestFor(string $name): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $name)) {
            return null;
        }

        $path = dirname(__DIR__) . '/modules/' . $name . '/manifest.json';
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }

        // A manifest that names a different module than the folder it sits in
        // is refused rather than trusted — the folder is the fact, the file is
        // the claim.
        if (($data['module'] ?? null) !== $name) {
            return null;
        }

        $strings = static function (mixed $v): array {
            if (!is_array($v)) {
                return [];
            }
            return array_values(array_filter($v, 'is_string'));
        };

        return [
            'module'               => $name,
            'version'              => is_string($data['version'] ?? null) ? $data['version'] : '',
            'changelog'            => is_string($data['changelog'] ?? null) ? $data['changelog'] : null,
            'db_tables_owned'      => $strings($data['db_tables_owned'] ?? []),
            'handles_file_uploads' => !empty($data['handles_file_uploads']),
            'network_access'       => $strings($data['network_access'] ?? []),
            'sends_email'          => !empty($data['sends_email']),
            'admin_routes'         => $strings($data['admin_routes'] ?? []),
            'public_routes'        => $strings($data['public_routes'] ?? []),
        ];
    }

    /**
     * One-line plain-English summary of what a module declares it does.
     * Returns '' when there is no manifest to summarise.
     */
    public static function capabilitySummary(string $name): string
    {
        $m = self::manifestFor($name);
        if ($m === null) {
            return '';
        }

        $parts = [];
        $t = count($m['db_tables_owned']);
        $parts[] = $t === 0
            ? 'stores nothing of its own'
            : 'owns ' . $t . ' database table' . ($t === 1 ? '' : 's')
              . ' (' . implode(', ', $m['db_tables_owned']) . ')';

        if ($m['sends_email'])          { $parts[] = 'sends email'; }
        if ($m['handles_file_uploads']) { $parts[] = 'accepts file uploads'; }
        $parts[] = $m['network_access'] === []
            ? 'makes no outside network calls'
            : 'contacts ' . implode(', ', $m['network_access']);

        return ucfirst(implode('; ', $parts)) . '.';
    }

    /** Machine-readable name, must match the folder name. */
    abstract public function name(): string;

    /** Human-readable label shown in the install GUI's module picker. */
    abstract public function label(): string;

    /** Register this module's routes on the shared Router instance. */
    abstract public function routes(Router $router): void;

    /**
     * Absolute paths to this module's *.sql migration files,
     * run once in order during install. Return [] if none needed.
     */
    abstract public function migrations(): array;

    /**
     * Optional: dashboard nav entry this module contributes.
     * Return null if this module has no admin-dashboard presence.
     */
    public function dashboardNavItem(): ?array
    {
        return null;
    }

    /**
     * Optional: public site nav entry this module contributes.
     *
     * Return ['label' => 'Blog', 'url' => '/blog'] — plus 'primary' => true
     * to render as the highlighted call-to-action button. Return null when a
     * module has no public-facing page (auth, admin-dashboard).
     *
     * Nav::publicItems() drops entries whose module has been hidden from the
     * dashboard, so a module does NOT need to check its own visibility here.
     *
     * AI AGENTS: a module's public links belong here, not hardcoded into
     * resources/layout.php. A hardcoded link survives the module being
     * disabled and points visitors at a 404.
     */
    public function publicNavItem(): ?array
    {
        return null;
    }
}
