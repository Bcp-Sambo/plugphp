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

        return $items;
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
