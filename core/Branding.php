<?php

/**
 * Branding
 *
 * Site identity the owner sets from the dashboard: name, description, logo,
 * favicon and the default Open Graph share image.
 *
 * Every accessor is safe to call from resources/layout.php, which renders on
 * every page including the 404 — so a database that is down must degrade to
 * the .env/shipped defaults rather than throw out of the layout.
 *
 * AI AGENTS: read branding through this class, not Settings::get() directly,
 * so the fallbacks and the database-down handling stay in one place. Image
 * paths returned here are stored relative (e.g. "/uploads/branding/x.png") —
 * render them with asset(), never with a leading-slash literal.
 */
final class Branding
{
    /** Shipped default, used until the owner uploads their own. */
    public const DEFAULT_LOGO = '/assets/img/logo.png';

    /** Site name: dashboard first, then APP_NAME from .env. */
    public static function siteName(): string
    {
        return self::setting('site_name') ?? (string) Config::get('APP_NAME', 'Site');
    }

    /** Default meta description. Empty string when unset. */
    public static function description(): string
    {
        return self::setting('site_description') ?? '';
    }

    /** Logo path, falling back to the shipped asset. */
    public static function logo(): string
    {
        return self::setting('site_logo') ?? self::DEFAULT_LOGO;
    }

    /** Favicon path, or null when the owner has not uploaded one. */
    public static function favicon(): ?string
    {
        return self::setting('site_favicon');
    }

    /** Default Open Graph image, or null. */
    public static function ogImage(): ?string
    {
        return self::setting('og_image');
    }

    /**
     * Absolute URL for the default OG image, since Open Graph requires one.
     * Null when no image is set.
     */
    public static function ogImageAbsolute(): ?string
    {
        $path = self::ogImage();

        return $path === null ? null : Url::absolute($path);
    }

    private static function setting(string $key): ?string
    {
        try {
            $value = Settings::get($key);
        } catch (Throwable $e) {
            return null;
        }

        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
