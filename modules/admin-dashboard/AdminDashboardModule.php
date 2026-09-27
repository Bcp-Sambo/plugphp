<?php

/**
 * AdminDashboardModule
 *
 * The always-on admin shell. It does two jobs:
 *   1. Owns GET /admin (the dashboard home with per-module summary widgets).
 *   2. Provides renderAdmin() — the shared admin chrome (sidebar + footer)
 *      that EVERY other module's /admin/* page renders through, so the
 *      sidebar nav is aggregated in one place from each module's
 *      dashboardNavItem().
 *
 * The admin shell is rendered as its own complete HTML document (see
 * views/layout.php), NOT through the public resources/layout.php — the
 * public layout is for the public site and will grow its own nav/footer.
 * Baseline security headers are already sent by public/index.php.
 *
 * AI AGENTS: the sidebar nav is built by looping over registered modules
 * (collectNavItems). Never hardcode a module's nav entry into a view —
 * that breaks the "modules are independently removable" guarantee.
 */
final class AdminDashboardModule extends Module
{
    /** Maps an enabled module to a (table, label) for the dashboard summary widgets. */
    private const STAT_SOURCES = [
        'auth'         => ['users', 'Users'],
        'contact-form' => ['contact_submissions', 'Messages'],
        'blog'         => ['posts', 'Blog posts'],
        'services'     => ['services', 'Services'],
        'projects'     => ['projects', 'Projects'],
    ];

    public function name(): string
    {
        return 'admin-dashboard';
    }

    public function label(): string
    {
        return 'Admin Dashboard';
    }

    public function routes(Router $router): void
    {
        require __DIR__ . '/routes.php';
    }

    public function migrations(): array
    {
        return [];
    }

    // This module IS the shell, so it has no sidebar entry of its own; the
    // "Dashboard" home link is added directly in collectNavItems().

    /**
     * Render the dashboard home page: a summary widget per enabled module
     * whose table exists. Called from the route handler AFTER
     * Auth::requireLogin().
     */
    public static function dashboardHome(): void
    {
        $enabled = self::enabledModules();
        $urls = [
            'auth'         => '/admin/users',
            'contact-form' => '/admin/messages',
            'blog'         => '/admin/blog',
            'services'     => '/admin/services',
            'projects'     => '/admin/projects',
        ];

        $stats = [];
        foreach ($enabled as $moduleName) {
            if (!isset(self::STAT_SOURCES[$moduleName])) {
                continue;
            }
            // The Users card links to an administrator-only page; showing an
            // Editor a card that leads to a 403 is the same defect as showing
            // them the nav link.
            if ($moduleName === 'auth' && !Auth::isAdmin()) {
                continue;
            }
            [$table, $label] = self::STAT_SOURCES[$moduleName];
            $count = self::safeCount($table);
            if ($count !== null) {
                $stats[] = ['label' => $label, 'count' => $count, 'url' => $urls[$moduleName] ?? null];
            }
        }

        // Recent messages panel — only if contact-form is installed; degrade
        // gracefully if the table is missing.
        $recentMessages = [];
        if (in_array('contact-form', $enabled, true)) {
            try {
                $recentMessages = Database::fetchAll(
                    'SELECT name, email, message, created_at
                     FROM contact_submissions ORDER BY created_at DESC LIMIT 5'
                );
            } catch (\Throwable $e) {
                $recentMessages = [];
            }
        }

        self::renderAdmin(__DIR__ . '/views/dashboard.php', [
            'stats'          => $stats,
            'recentMessages' => $recentMessages,
        ], 'Dashboard');
    }

    /**
     * Shared admin renderer. Captures a module's admin view into $content,
     * then wraps it in the admin shell (sidebar + footer). Every /admin/*
     * page in every module renders through this.
     *
     * NOTE: this does NOT call Auth::requireLogin() — each admin handler is
     * required to call it as its own first line (see every module SKILL.md).
     * Keeping the guard visible at the handler, not hidden here, is
     * deliberate: it is the single most damaging thing to get wrong.
     */
    /**
     * The /admin/updates panel.
     *
     * @param array{success:bool,log:string[],version?:?string}|null $result
     *        Outcome of an apply or rollback that just ran, if any.
     * @param bool $forceCheck Contact the update server even though a page
     *        view alone should not (see below).
     */
    public static function updatesPage(?array $result = null, bool $forceCheck = false): void
    {
        // A plain page view does NOT phone home. Opening the dashboard should
        // not depend on a remote host being up, and should not emit a network
        // request the owner did not ask for. The check runs on an explicit
        // click, or implicitly right after an update so the page can confirm
        // the new version took.
        $manifest = ($forceCheck || $result !== null) ? Updater::fetchManifest() : null;
        $checkFailed = ($forceCheck || $result !== null) && $manifest === null;

        // An update that just succeeded in THIS request is a special case.
        // Updater::VERSION is a compile-time constant, so the value in memory
        // is still the old one even though apply() has already replaced the
        // file on disk. Reading it here would report the freshly updated site
        // as "files and database disagree" and offer to finish an interrupted
        // update — a false alarm at the single worst moment, right after the
        // owner watched the update report success. The next request reads the
        // new constant and is correct; this just stops the one page in between
        // from contradicting the log directly above it.
        $justUpdatedTo = ($result !== null && !empty($result['success']) && !empty($result['version']))
            ? (string) $result['version']
            : null;

        self::renderAdmin(__DIR__ . '/views/updates.php', [
            'currentVersion'   => $justUpdatedTo ?? Updater::VERSION,
            'recordedVersion'  => Updater::installedVersion(),
            'versionsAgree'    => $justUpdatedTo !== null || Updater::versionsAgree(),
            'manifest'         => $manifest,
            'checkFailed'      => $checkFailed,
            'updateAvailable'  => $justUpdatedTo === null && $manifest !== null && Updater::isNewer($manifest),
            'preflight'        => Updater::preflight(),
            'rollbackOffered'  => Updater::rollbackAvailable(),
            'lastUpdatedAt'    => Updater::lastUpdatedAt(),
            'rollbackWindow'   => Updater::ROLLBACK_WINDOW_DAYS,
            'result'           => $result,
        ], 'Updates');
    }
    /** Branding uploads are small by design — shared-hosting quotas are tight. */
    private const BRANDING_MAX_BYTES = 2 * 1024 * 1024;
    private const BRANDING_SUBDIR = 'branding';

    /** The three tabs of /admin/settings. */
    private const SETTINGS_TABS = ['branding', 'smtp', 'tracking'];

    /**
     * /admin/settings — site name, branding images, SMTP, tracking pixels.
     *
     * A fixed part of the dashboard shell, like the nav loop itself. A future
     * module should not build a second settings area.
     */
    public static function settingsPage(): void
    {
        $tab = (string) ($_GET['tab'] ?? 'branding');
        if (!in_array($tab, self::SETTINGS_TABS, true)) {
            $tab = 'branding';
        }

        self::renderAdmin(__DIR__ . '/views/settings.php', [
            'tab'            => $tab,
            'flash'          => self::takeFlash(),
            'siteName'       => Branding::siteName(),
            'siteDescription'=> Branding::description(),
            'siteLogo'       => Branding::logo(),
            'favicon'        => Branding::favicon(),
            'ogImage'        => Branding::ogImage(),
            'smtp'           => Mailer::config(),
            'smtpPreset'     => (string) (Settings::get('smtp_provider_preset', 'custom') ?: 'custom'),
            'smtpPassSet'    => Settings::get('smtp_pass_encrypted') !== null,
            'presets'        => Mailer::PROVIDER_PRESETS,
            'hasAppKey'      => Crypto::hasKey(),
            'suggestedKey'   => Crypto::hasKey() ? '' : Crypto::suggestedKeyLine(),
            'trackingOn'     => Settings::getBool('tracking_enabled', false),
            'gaId'           => (string) (Settings::get('ga_measurement_id', '') ?: ''),
            'fbPixelId'      => (string) (Settings::get('fb_pixel_id', '') ?: ''),
            'adminEmail'     => self::currentUserEmail() ?? '',
        ], 'Settings');
    }

    /** POST /admin/settings/branding */
    public static function saveBranding(): void
    {
        $errors = [];

        Settings::set('site_name', trim((string) ($_POST['site_name'] ?? '')));
        Settings::set('site_description', trim((string) ($_POST['site_description'] ?? '')));

        // field name => [settings key, allowed types]
        $images = [
            'site_logo'   => ['site_logo',   ['png', 'jpg', 'webp']],
            'site_favicon'=> ['site_favicon', ['png']],
            'og_image'    => ['og_image',    ['png', 'jpg', 'webp']],
        ];

        foreach ($images as $field => [$key, $types]) {
            $file = $_FILES[$field] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue; // nothing uploaded for this field, keep what is stored
            }
            try {
                Settings::set($key, Upload::image($file, self::BRANDING_SUBDIR, self::BRANDING_MAX_BYTES, $types));
            } catch (Throwable $e) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $e->getMessage();
            }
        }

        self::finishSettings('branding', $errors, 'Branding saved.');
    }

    /** POST /admin/settings/smtp */
    public static function saveSmtp(): void
    {
        $errors = [];

        $preset = (string) ($_POST['smtp_provider_preset'] ?? 'custom');
        if (!array_key_exists($preset, Mailer::PROVIDER_PRESETS)) {
            $preset = 'custom';
        }
        Settings::set('smtp_provider_preset', $preset);

        $port = (int) ($_POST['smtp_port'] ?? 587);
        if ($port < 1 || $port > 65535) {
            $errors[] = 'Port must be between 1 and 65535.';
            $port = 587;
        }

        $encryption = (string) ($_POST['smtp_encryption'] ?? 'tls');
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }

        $fromEmail = trim((string) ($_POST['smtp_from_email'] ?? ''));
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'From address is not a valid email address.';
            $fromEmail = '';
        }

        Settings::set('smtp_host', trim((string) ($_POST['smtp_host'] ?? '')));
        Settings::set('smtp_port', (string) $port);
        Settings::set('smtp_user', trim((string) ($_POST['smtp_user'] ?? '')));
        // Stored literally, including 'none'. Writing '' would read back as
        // "not configured" and silently fall through to the .env value.
        Settings::set('smtp_encryption', $encryption);
        Settings::set('smtp_from_name', trim((string) ($_POST['smtp_from_name'] ?? '')));
        if ($fromEmail !== '') {
            Settings::set('smtp_from_email', $fromEmail);
        }

        // Blank password means "keep the one already saved" — the stored value
        // is never rendered back into the form, so blank cannot mean "clear".
        $password = (string) ($_POST['smtp_pass'] ?? '');
        if ($password !== '') {
            if (!Crypto::ensureKey()) {
                $errors[] = 'The password was NOT saved: this site has no APP_KEY and the '
                    . '.env file is not writable, so the password could not be encrypted. '
                    . 'Add the line shown on this page to .env, then save again.';
            } else {
                Settings::set('smtp_pass_encrypted', Crypto::encrypt($password));
            }
        }

        self::finishSettings('smtp', $errors, 'Mail settings saved.');
    }

    /** POST /admin/settings/tracking */
    public static function saveTracking(): void
    {
        $errors = [];

        // Validate BEFORE saving. A tracking ID ends up inside an inline
        // <script>, so an invalid value must never reach the database — a
        // later render path that forgets to re-check would then execute it.
        $ga = Tracking::normaliseGaId((string) ($_POST['ga_measurement_id'] ?? ''));
        if ($ga === '') {
            Settings::delete('ga_measurement_id');
        } elseif (Tracking::isValidGaId($ga)) {
            Settings::set('ga_measurement_id', $ga);
        } else {
            $errors[] = 'Google Analytics ID rejected. It must look like G-XXXXXXXXXX '
                . '(the letter G, a hyphen, then letters and digits only). Nothing was saved for it.';
        }

        $fb = Tracking::normaliseFbPixelId((string) ($_POST['fb_pixel_id'] ?? ''));
        if ($fb === '') {
            Settings::delete('fb_pixel_id');
        } elseif (Tracking::isValidFbPixelId($fb)) {
            Settings::set('fb_pixel_id', $fb);
        } else {
            $errors[] = 'Facebook Pixel ID rejected. It must be digits only. Nothing was saved for it.';
        }

        Settings::set('tracking_enabled', isset($_POST['tracking_enabled']) ? '1' : '0');

        self::finishSettings('tracking', $errors, 'Tracking settings saved.');
    }

    /** POST /admin/settings/test-email */
    public static function sendTestEmail(): void
    {
        $to = self::currentUserEmail();

        if ($to === null) {
            self::finishSettings('smtp', ['Could not determine your email address.'], '');
            return;
        }
        if (!Mailer::isConfigured()) {
            self::finishSettings('smtp', ['Set a mail host and a from-address first, then save.'], '');
            return;
        }

        $sent = Mailer::send(
            $to,
            'PlugPHP test email',
            '<p>This is a test from your site\'s mail settings.</p>'
            . '<p>If you are reading it, sending works.</p>'
        );

        if ($sent) {
            self::finishSettings('smtp', [], 'Test email sent to ' . $to . '. Check your inbox, and your spam folder.');
        } else {
            self::finishSettings('smtp', [
                'The test email could not be sent. The most common causes are a wrong '
                . 'password or API key, a host or port your server blocks (many hosts '
                . 'block port 25), or a from-address the provider has not verified. '
                . 'The exact error was written to storage/logs/.',
            ], '');
        }
    }

    /** The logged-in admin's email address, or null. */
    private static function currentUserEmail(): ?string
    {
        $id = Auth::userId();
        if ($id === null) {
            return null;
        }
        $row = Database::fetchOne('SELECT email FROM users WHERE id = :id', ['id' => $id]);

        return $row['email'] ?? null;
    }

    /**
     * Store the outcome and redirect back to the tab.
     *
     * Post/redirect/get: without it a browser refresh would re-submit the
     * form, and on the branding tab that means re-uploading the images.
     */
    private static function finishSettings(string $tab, array $errors, string $success): void
    {
        $_SESSION['pp_settings_flash'] = [
            'errors'  => $errors,
            'success' => $errors === [] ? $success : '',
        ];

        http_response_code(302);
        header('Location: ' . Url::to('/admin/settings?tab=' . $tab));
        exit;
    }

    /** Read and clear the flash. */
    private static function takeFlash(): array
    {
        $flash = $_SESSION['pp_settings_flash'] ?? ['errors' => [], 'success' => ''];
        unset($_SESSION['pp_settings_flash']);

        return [
            'errors'  => is_array($flash['errors'] ?? null) ? $flash['errors'] : [],
            'success' => (string) ($flash['success'] ?? ''),
        ];
    }
    public static function renderAdmin(string $viewPath, array $data = [], string $pageTitle = 'Admin'): void
    {
        if (!file_exists($viewPath)) {
            throw new RuntimeException("Admin view not found: {$viewPath}");
        }

        extract($data, EXTR_SKIP);
        $navItems = self::collectNavItems();

        ob_start();
        include $viewPath;
        $content = ob_get_clean();

        // Prepended here rather than added to a view on purpose: this file
        // ships with an update, the views do not. A site whose views are stale
        // is exactly the site that will never receive a warning written into a
        // view, so the warning has to come from code that can reach it.
        $content = self::staleViewNotice() . $content;

        include __DIR__ . '/views/layout.php';
    }

    /**
     * Build the sidebar nav by asking every enabled module for its
     * dashboardNavItem(). The "Dashboard" home link is always first.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public static function collectNavItems(): array
    {
        $items = [['label' => 'Dashboard', 'url' => '/admin']];

        foreach (self::enabledModules() as $moduleName) {
            $className = self::classNameFor($moduleName);
            $modulePath = __DIR__ . "/../{$moduleName}/{$className}.php";

            if (!file_exists($modulePath)) {
                continue;
            }
            require_once $modulePath;
            if (!class_exists($className)) {
                continue;
            }

            $module = new $className();
            $item = $module->dashboardNavItem();
            if (is_array($item) && isset($item['label'], $item['url'])) {
                $items[] = $item;
            }
        }

        // Site-wide config and maintenance last, after the content sections.
        $items[] = ['label' => 'Settings', 'url' => '/admin/settings', 'admin_only' => true];
        $items[] = ['label' => 'Updates', 'url' => '/admin/updates', 'admin_only' => true];

        return self::filterNavByRole($items);
    }

    /**
     * Drop nav entries the current user cannot reach.
     *
     * Routes are guarded by Auth::requireRole() regardless — this is about not
     * showing someone a link that will refuse them. A visible link to a 403 is
     * the same defect as the public nav advertising a hidden module's 404.
     *
     * Admin-only destinations are declared two ways: an 'admin_only' flag on
     * the item, or a URL under one of the admin-only prefixes, so a module
     * that contributes a Settings or Users link is covered without having to
     * know about the flag.
     */
    private static function filterNavByRole(array $items): array
    {
        if (Auth::isAdmin()) {
            return $items;
        }

        $adminOnlyPrefixes = ['/admin/settings', '/admin/updates', '/admin/users'];

        return array_values(array_filter($items, static function (array $item) use ($adminOnlyPrefixes): bool {
            if (!empty($item['admin_only'])) {
                return false;
            }
            foreach ($adminOnlyPrefixes as $prefix) {
                if (str_starts_with((string) $item['url'], $prefix)) {
                    return false;
                }
            }
            return true;
        }));
    }
    /**
     * Banner shown on every admin page when view files are older than the code.
     *
     * Updates never overwrite views, so a release that moves behaviour into one
     * cannot deliver it — and the site keeps looking healthy while the feature
     * is quietly absent. This turns that silence into something the owner can
     * see and act on.
     */
    private static function staleViewNotice(): string
    {
        if (!class_exists('Updater')) {
            return '';
        }

        try {
            $stale = Updater::staleViews();
            $pending = Updater::pendingViews();
        } catch (Throwable $e) {
            return '';
        }
        if ($stale === []) {
            return '';
        }

        // Which of the stale files do we actually have a copy of on disk?
        $staged = [];
        foreach ($pending as $p) {
            $staged[$p['file']] = true;
        }

        $csrf = Auth::csrfToken();
        $canApply = Auth::isAdmin();
        $items = '';
        $haveAny = false;

        foreach ($stale as $row) {
            $file = (string) $row['file'];
            $items .= '<li><code>' . e($file) . '</code><br>' . e((string) $row['effect']);

            if (isset($staged[$file])) {
                $haveAny = true;
                if ($canApply) {
                    $items .= '<form method="post" action="' . url('/admin/views/apply') . '" class="pp-stale__act">'
                        . '<input type="hidden" name="csrf_token" value="' . e($csrf) . '">'
                        . '<input type="hidden" name="file" value="' . e($file) . '">'
                        . '<button class="btn btn-secondary btn-sm" type="submit">Apply this file</button>'
                        . '</form>';
                } else {
                    $items .= '<div class="pp-stale__act"><em>An administrator can apply this.</em></div>';
                }
            }
            $items .= '</li>';
        }

        $intro = '<p>Updates never overwrite your view files, because they are yours to '
            . 'restyle. These carry behaviour this version expects, so until they are '
            . 'updated the features below are inactive — the site will otherwise look '
            . 'completely normal.</p>';

        $howto = $haveAny
            ? '<p>The files are already on your server, delivered with the update. '
              . 'Applying one keeps a copy of your current version alongside it, so a '
              . 'customisation you did not mean to lose can be recovered.</p>'
            : '<p>This release did not deliver replacement copies — that arrived in a '
              . 'later version. Run an update first, then this notice will offer to '
              . 'apply them. To do it by hand instead, take the files from the '
              . '<strong>Source code (zip)</strong> on the release page, not the update '
              . 'package, which deliberately contains no views.</p>';

        return '<div class="pp-stale" role="alert">'
            . '<strong>' . e((string) count($stale)) . ' file(s) are older than PlugPHP '
            . e(Updater::VERSION) . '.</strong>'
            . $intro . '<ul>' . $items . '</ul>' . $howto
            . '</div>'
            . '<style>'
            . '.pp-stale{background:#fdf3e0;border:1px solid #e8cf9a;color:#5c4108;'
            . 'border-radius:10px;padding:14px 18px;margin-bottom:16px;font-size:14px}'
            . '.pp-stale ul{margin:.5rem 0 .5rem 1.1rem;padding:0}'
            . '.pp-stale li{margin-bottom:.7rem}'
            . '.pp-stale code{background:#f4e6c6;padding:.1rem .3rem;border-radius:4px;font-size:12.5px}'
            . '.pp-stale p{margin:.4rem 0}'
            . '.pp-stale__act{margin-top:.35rem}'
            . '</style>';
    }

    /**
     * POST /admin/views/apply — copy one delivered view into place.
     *
     * Administrator only, one file per request, with the current version kept
     * alongside. This is the only place a view is ever written by the
     * application, and it happens because someone asked for it by name — the
     * update itself still never touches one.
     */
    public static function applyView(): void
    {
        Auth::requireLogin();
        Auth::requireRole(Auth::ROLE_ADMIN);
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $result = Updater::applyPendingView((string) ($_POST['file'] ?? ''));

        $_SESSION['pp_settings_flash'] = [
            'errors'  => $result['success'] ? [] : [$result['message']],
            'success' => $result['success'] ? $result['message'] : '',
        ];

        $back = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        // Only follow a same-site referer; otherwise land somewhere known.
        $target = (str_starts_with($back, '/') && !str_starts_with($back, '//'))
            ? $back
            : Url::to('/admin/updates');

        http_response_code(302);
        header('Location: ' . $target);
        exit;
    }
    /** The enabled-module list, same source of truth the bootstrap uses. */
    private static function enabledModules(): array
    {
        $configPath = __DIR__ . '/../../config/modules.php';
        if (!file_exists($configPath)) {
            return [];
        }
        $modules = require $configPath;
        return is_array($modules) ? $modules : [];
    }

    /** kebab-case folder name -> PascalCase module class (same rule as the bootstrap). */
    private static function classNameFor(string $moduleName): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $moduleName))) . 'Module';
    }

    /**
     * COUNT(*) on a FIXED, hardcoded table name (never user input). Returns
     * null if the table does not exist yet (module installed but not
     * migrated), so the dashboard degrades gracefully instead of erroring.
     */
    private static function safeCount(string $table): ?int
    {
        try {
            $row = Database::fetchOne("SELECT COUNT(*) AS c FROM {$table}");
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
