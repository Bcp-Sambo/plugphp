<?php

/**
 * Tracking
 *
 * Google Analytics 4 and Facebook Pixel snippets, driven by dashboard
 * settings.
 *
 * THE SECURITY POINT OF THIS CLASS: a tracking ID is interpolated INSIDE an
 * inline <script> block. e() does not help there — it escapes for HTML text,
 * and JavaScript is a different context. An unvalidated ID would let anyone
 * with dashboard access run arbitrary JavaScript on every page of the site,
 * for every visitor.
 *
 * So the IDs are validated against a strict format BEFORE they are ever
 * saved, and validated again here before they are ever rendered. Saving is
 * where the real check belongs — a value that never enters the database
 * cannot be rendered by some future code path that forgets to check.
 *
 * AI AGENTS: never render an admin-supplied value inside a <script> block
 * without a format whitelist of this kind. Never relax these patterns to
 * "be more permissive"; there is no legitimate GA or Pixel ID they reject.
 */
final class Tracking
{
    /** GA4 measurement IDs look like G-XXXXXXXXXX. */
    private const GA_PATTERN = '/^G-[A-Z0-9]{4,24}$/';

    /** Facebook pixel IDs are numeric only. */
    private const FB_PATTERN = '/^[0-9]{5,24}$/';

    /**
     * Hosts the snippets need. Kept here rather than in View so the CSP and
     * the snippets can never drift apart: if a snippet gains a dependency,
     * it is added in the same file.
     */
    private const GA_SCRIPT_HOSTS  = ['https://www.googletagmanager.com'];
    private const GA_CONNECT_HOSTS = [
        'https://www.google-analytics.com',
        'https://*.google-analytics.com',
        'https://*.analytics.google.com',
        'https://www.googletagmanager.com',
    ];
    private const FB_SCRIPT_HOSTS  = ['https://connect.facebook.net'];
    private const FB_CONNECT_HOSTS = ['https://www.facebook.com', 'https://connect.facebook.net'];

    /* ============================================================== *
     * Validation — used by the dashboard BEFORE saving
     * ============================================================== */

    public static function isValidGaId(string $id): bool
    {
        return (bool) preg_match(self::GA_PATTERN, $id);
    }

    public static function isValidFbPixelId(string $id): bool
    {
        return (bool) preg_match(self::FB_PATTERN, $id);
    }

    /**
     * Tidy user input before validating it: trim, and uppercase the GA ID so
     * someone typing "g-abc123" is helped rather than rejected. Normalising
     * is not the same as being permissive — the strict pattern still decides.
     */
    public static function normaliseGaId(string $id): string
    {
        return strtoupper(trim($id));
    }

    public static function normaliseFbPixelId(string $id): string
    {
        return trim($id);
    }

    /* ============================================================== *
     * Stored state
     * ============================================================== */

    public static function isEnabled(): bool
    {
        return self::setting('tracking_enabled') === '1';
    }

    /** The stored GA ID, but only if it still passes validation. */
    public static function gaId(): ?string
    {
        $id = self::setting('ga_measurement_id');

        return ($id !== null && self::isValidGaId($id)) ? $id : null;
    }

    /** The stored Pixel ID, but only if it still passes validation. */
    public static function fbPixelId(): ?string
    {
        $id = self::setting('fb_pixel_id');

        return ($id !== null && self::isValidFbPixelId($id)) ? $id : null;
    }

    /** True when at least one valid tracker is configured and enabled. */
    public static function isActive(): bool
    {
        return self::isEnabled() && (self::gaId() !== null || self::fbPixelId() !== null);
    }

    /* ============================================================== *
     * CSP contributions
     * ============================================================== */

    /** Extra script-src entries needed right now, or [] when inactive. */
    public static function cspScriptSources(): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        $hosts = [];
        if (self::gaId() !== null) {
            $hosts = array_merge($hosts, self::GA_SCRIPT_HOSTS);
        }
        if (self::fbPixelId() !== null) {
            $hosts = array_merge($hosts, self::FB_SCRIPT_HOSTS);
        }

        return array_values(array_unique($hosts));
    }

    /** Extra connect-src entries, for the beacons the trackers actually send. */
    public static function cspConnectSources(): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        $hosts = [];
        if (self::gaId() !== null) {
            $hosts = array_merge($hosts, self::GA_CONNECT_HOSTS);
        }
        if (self::fbPixelId() !== null) {
            $hosts = array_merge($hosts, self::FB_CONNECT_HOSTS);
        }

        return array_values(array_unique($hosts));
    }

    /* ============================================================== *
     * Snippets
     * ============================================================== */

    /** GA4 gtag.js, for <head>. Empty string when GA is off or unset. */
    public static function headSnippet(): string
    {
        $id = self::isEnabled() ? self::gaId() : null;
        if ($id === null) {
            return '';
        }

        // $id has passed GA_PATTERN, so it is [A-Z0-9-] only and cannot
        // terminate the script block or the string literal it sits in.
        $nonce = View::nonceAttribute();

        return <<<HTML
<script async{$nonce} src="https://www.googletagmanager.com/gtag/js?id={$id}"></script>
<script{$nonce}>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{$id}');
</script>

HTML;
    }

    /**
     * Facebook Pixel, for immediately after <body>.
     *
     * Facebook's own snippet expects to sit there, and the <noscript> image
     * fallback records visits from browsers with JavaScript disabled.
     */
    public static function bodySnippet(): string
    {
        $id = self::isEnabled() ? self::fbPixelId() : null;
        if ($id === null) {
            return '';
        }

        // $id has passed FB_PATTERN, so it is digits only.
        $nonce = View::nonceAttribute();

        return <<<HTML
<script{$nonce}>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window,document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '{$id}');
  fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none" alt=""
  src="https://www.facebook.com/tr?id={$id}&ev=PageView&noscript=1"></noscript>

HTML;
    }

    /**
     * A setting, or null when unset/blank/unreachable.
     *
     * Wrapped: the CSP header is built on every request, including the 404
     * page, and must not throw when the database is down.
     */
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
