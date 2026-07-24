<?php

/**
 * Settings
 *
 * Key/value store for site-level toggles — currently used for
 * per-module public visibility ("hide Blog from the public site
 * without uninstalling it"). Built on top of Database's existing
 * methods, not a new raw-query path.
 *
 * AI AGENTS: use this for any "on/off" style setting a site owner
 * should control from the dashboard. Do not invent a separate
 * settings table per module — this one shared table is the pattern.
 */
final class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = Database::fetchOne(
            'SELECT setting_value FROM site_settings WHERE setting_key = :key',
            ['key' => $key]
        );

        if ($row === null) {
            return $default;
        }

        return $row['setting_value'];
    }

    public static function getBool(string $key, bool $default = true): bool
    {
        $value = self::get($key, $default ? '1' : '0');
        return $value === '1' || $value === true || $value === 1;
    }

    public static function set(string $key, mixed $value): void
    {
        $stringValue = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        $existing = Database::fetchOne(
            'SELECT setting_key FROM site_settings WHERE setting_key = :key',
            ['key' => $key]
        );

        if ($existing === null) {
            Database::insert('site_settings', [
                'setting_key'   => $key,
                'setting_value' => $stringValue,
            ]);
        } else {
            Database::update('site_settings', [
                'setting_value' => $stringValue,
            ], 'setting_key', $key);
        }
    }

    /** Convenience helper for the common "is this module's public side visible" check. */
    public static function isModuleVisible(string $moduleName): bool
    {
        return self::getBool("{$moduleName}_visible", true);
    }
}
