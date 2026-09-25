<?php

/**
 * Url
 *
 * Makes the app indifferent to where it is mounted: domain root, subdomain
 * root, or a subfolder. Every internal link, form action, asset reference
 * and redirect goes through here instead of hardcoding a leading slash.
 *
 * TWO APIS, DELIBERATELY DIFFERENT:
 *
 *   Url::to() / Url::asset() / Url::absolute()
 *       Return RAW urls. Use in PHP logic — header('Location: ...'),
 *       emails, sitemap generation, JSON-LD values.
 *
 *   url() / asset()  (global helpers, bottom of this file)
 *       Return HTML-ESCAPED urls, exactly like e(). Use in views, where
 *       the value lands inside an href="" or src="" attribute.
 *
 * AI AGENTS: never write a leading-slash literal URL in a view
 * (href="/blog"). It resolves against the domain root and 404s the moment
 * the site is served from a subfolder. Use url('/blog') in views and
 * Url::to('/blog') in PHP. For canonical/Open Graph/sitemap/email links use
 * Url::absolute(), which is built from APP_URL and never from the request
 * host — see the Host-header note on absolute() below.
 */
final class Url
{
    private static ?string $base = null;

    /**
     * The URL prefix this app is served under: '' at a domain/subdomain
     * root, '/something' in a subfolder. Never has a trailing slash.
     *
     * Detection compares the front controller's location against the path
     * the browser actually requested. The naive approach — dirname() of
     * SCRIPT_NAME alone — is wrong whenever an internal rewrite has moved
     * the request: with the root-.htaccess fallback (docroot at the project
     * root, traffic forwarded into public/), SCRIPT_NAME is
     * "/public/index.php" while the browser asked for "/blog". Taking
     * dirname() there would emit "/public/blog" links, which that same
     * .htaccess explicitly refuses to rewrite — every link would 404.
     *
     * So a prefix only counts if the REQUEST URI genuinely carries it.
     */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        $scriptDir = ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '\\')
            ? ''
            : rtrim($scriptDir, '/');

        if ($scriptDir === '' || !self::isSafeBase($scriptDir)) {
            return self::$base = '';
        }

        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        // The browser must actually be asking under this prefix. If it is
        // not, the prefix came from an internal rewrite and is invisible
        // to the client — the app is effectively mounted at the root.
        $carriesPrefix = $requestPath === $scriptDir
            || str_starts_with($requestPath, $scriptDir . '/');

        return self::$base = $carriesPrefix ? $scriptDir : '';
    }

    /**
     * Internal link or form action. Url::to('/blog') is '/blog' at a root
     * mount and '/site/blog' under a subfolder.
     *
     * Absolute and scheme-relative inputs pass through untouched so a view
     * can hand an external link to the same helper. '//evil.com' is NOT
     * treated as passthrough — it would be a protocol-relative jump to
     * another host, so it is normalised into a local path instead.
     */
    public static function to(string $path): string
    {
        $path = self::stripControlChars($path);

        if (self::isExternal($path)) {
            return $path;
        }

        if ($path === '' || $path === '/') {
            return self::base() . '/';
        }

        // Collapse any leading slashes so '//x' can never survive as a
        // protocol-relative URL pointing off-site.
        $path = '/' . ltrim($path, '/');

        return self::base() . $path;
    }

    /** Static asset (CSS/JS/images). Same base handling as to(). */
    public static function asset(string $path): string
    {
        return self::to($path);
    }

    /**
     * Full scheme://host/base/path, for canonical tags, Open Graph, sitemap
     * entries and links inside emails.
     *
     * SECURITY: the host ALWAYS comes from APP_URL, never from
     * $_SERVER['HTTP_HOST']. HTTP_HOST is attacker-controllable (Host-header
     * injection); deriving a password-reset link from it lets an attacker
     * mail a victim a reset URL pointing at a host the attacker controls.
     *
     * APP_URL is the site's full public root and MUST already include the
     * subfolder when the site is mounted in one (http://example.com/site).
     * The detected base is therefore NOT appended here — doing so would
     * double it. health.php cross-checks the two and warns on a mismatch.
     */
    public static function absolute(string $path = '/'): string
    {
        $appUrl = rtrim(self::stripControlChars((string) Config::get('APP_URL', '')), '/');
        $path   = self::stripControlChars($path);

        if (self::isExternal($path)) {
            return $path;
        }

        $path = ($path === '' || $path === '/') ? '/' : '/' . ltrim($path, '/');

        // With APP_URL unset there is no trustworthy host to build from.
        // Degrade to a root-relative URL rather than inventing one from the
        // request — a wrong-but-relative link beats a poisoned absolute one.
        if ($appUrl === '') {
            return self::to($path);
        }

        return $appUrl . $path;
    }

    /**
     * Scheme + host the current request actually arrived on.
     *
     * DIAGNOSTICS ONLY — health.php uses it to flag an APP_URL that was
     * copied from a previous deploy. Never build a link from this.
     */
    public static function detectedOrigin(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $host = self::stripControlChars($host);

        return ($https ? 'https://' : 'http://') . $host;
    }

    /** Reset memoised detection. Test harnesses only. */
    public static function resetDetection(): void
    {
        self::$base = null;
    }

    /**
     * A base path is a plain sequence of URL path segments and nothing
     * else. Anything with a quote, angle bracket, backslash, traversal or
     * whitespace in it is rejected outright rather than sanitised — it
     * cannot be a legitimate mount point, so treating it as a root mount
     * is both safer and more likely correct.
     */
    private static function isSafeBase(string $base): bool
    {
        if (str_contains($base, '..')) {
            return false;
        }

        return (bool) preg_match('#^(/[A-Za-z0-9._~%@+-]+)+$#', $base);
    }

    /** Links that already name their own scheme are left alone. */
    private static function isExternal(string $path): bool
    {
        return (bool) preg_match('#^(https?://|mailto:|tel:|\#)#i', $path);
    }

    /**
     * Strip CR/LF and NUL. Url values reach header('Location: ...'), where
     * an embedded newline is a response-splitting vector.
     */
    private static function stripControlChars(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
    }
}

/**
 * View helper for internal links and form actions. HTML-escaped, so it
 * drops straight into an attribute:
 *   <a href="<?= url('/blog') ?>">Blog</a>
 */
function url(string $path = '/'): string
{
    return e(Url::to($path));
}

/**
 * View helper for static assets. HTML-escaped, same as url():
 *   <link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
 */
function asset(string $path): string
{
    return e(Url::asset($path));
}
