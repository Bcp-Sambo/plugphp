<?php

/**
 * Config
 * Loads key=value pairs from .env into a static in-memory store.
 * No Composer dependency required — deliberately dependency-free
 * so the kit runs on the cheapest shared hosting with zero setup.
 *
 * AI AGENTS: never hardcode secrets/config in module files.
 * Always read via Config::get('KEY'). Never edit this file to
 * add per-module config — add new keys to .env / .env.example instead.
 */
final class Config
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $envPath): void
    {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($envPath)) {
            throw new RuntimeException(
                ".env file not found at {$envPath}. Copy .env.example to .env and fill in real values."
            );
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            // strip optional surrounding quotes
            $value = trim($value, "\"'");
            self::$values[$key] = $value;
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    public static function isDebug(): bool
    {
        return self::get('APP_DEBUG', 'false') === 'true';
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'production') === 'production';
    }
}
