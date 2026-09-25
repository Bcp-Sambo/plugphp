<?php

/**
 * Nav
 *
 * Builds the public site navigation from the enabled modules, dropping any
 * module the owner has hidden from the dashboard.
 *
 * This exists because the nav used to be hardcoded in resources/layout.php.
 * Hiding Services correctly unregistered its routes — so /services returned
 * 404 — but the three hardcoded "Services" links stayed in the header, the
 * mobile menu and the footer, pointing visitors straight at that 404.
 *
 * AI AGENTS: a module contributes its public link via Module::publicNavItem().
 * Never hardcode a module's link into a layout; a hardcoded link outlives the
 * module being disabled.
 */
final class Nav
{
    /** @var array<int, array{label:string,url:string,primary:bool}>|null */
    private static ?array $items = null;

    /**
     * Visible public nav items, in the order modules are enabled.
     *
     * @return array<int, array{label:string,url:string,primary:bool}>
     */
    public static function publicItems(): array
    {
        if (self::$items !== null) {
            return self::$items;
        }

        $items = [];
        foreach (self::enabledModules() as $moduleName) {
            $module = self::loadModule($moduleName);
            if ($module === null) {
                continue;
            }

            $item = $module->publicNavItem();
            if (!is_array($item) || !isset($item['label'], $item['url'])) {
                continue;
            }

            if (!self::isVisible($moduleName)) {
                continue;
            }

            $items[] = [
                'label'   => (string) $item['label'],
                'url'     => (string) $item['url'],
                'primary' => !empty($item['primary']),
            ];
        }

        return self::$items = $items;
    }

    /** Nav items excluding the highlighted call-to-action, for footer columns. */
    public static function secondaryItems(): array
    {
        return array_values(array_filter(self::publicItems(), fn($i) => !$i['primary']));
    }

    /** The highlighted call-to-action item, if a module contributes one. */
    public static function primaryItem(): ?array
    {
        foreach (self::publicItems() as $item) {
            if ($item['primary']) {
                return $item;
            }
        }
        return null;
    }

    /** Reset memoisation. Test harnesses only. */
    public static function reset(): void
    {
        self::$items = null;
    }

    /**
     * Visibility is a database lookup, and the nav renders on every page —
     * including the 404 page, which must still render when the database is
     * unreachable. A failed lookup falls back to showing the item, matching
     * Settings::isModuleVisible()'s own default.
     */
    private static function isVisible(string $moduleName): bool
    {
        try {
            return Settings::isModuleVisible($moduleName);
        } catch (Throwable $e) {
            return true;
        }
    }

    private static function enabledModules(): array
    {
        $configPath = __DIR__ . '/../config/modules.php';
        if (!is_file($configPath)) {
            return [];
        }
        $modules = require $configPath;

        return is_array($modules) ? $modules : [];
    }

    private static function loadModule(string $moduleName): ?Module
    {
        // Folder names are kebab-case; class names cannot contain hyphens.
        $className = str_replace(' ', '', ucwords(str_replace('-', ' ', $moduleName))) . 'Module';
        $path = __DIR__ . "/../modules/{$moduleName}/{$className}.php";

        if (!is_file($path)) {
            return null;
        }
        require_once $path;

        if (!class_exists($className)) {
            return null;
        }

        $module = new $className();

        return $module instanceof Module ? $module : null;
    }
}
