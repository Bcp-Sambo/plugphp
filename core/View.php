<?php

/**
 * View
 *
 * Renders a view file inside the shared layout, and applies
 * baseline security headers on every response.
 *
 * AI AGENTS: in any .php view file, never `echo $variable` directly.
 * Always use e($variable) (defined below) to escape output.
 * Unescaped output is how XSS gets in — there is no case where
 * raw echo of dynamic data is acceptable in a view.
 */
final class View
{
    /** Per-request CSP nonce, generated on first use. */
    private static ?string $nonce = null;

    /**
     * The nonce for this request.
     *
     * Any inline <script> the app emits must carry it, or the CSP blocks the
     * script. Generated lazily so a request that renders no script still pays
     * nothing for it.
     */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /** ` nonce="…"`, ready to drop into a <script> tag. Already escaped. */
    public static function nonceAttribute(): string
    {
        return ' nonce="' . htmlspecialchars(self::nonce(), ENT_QUOTES, 'UTF-8') . '"';
    }

    public static function sendSecurityHeaders(): void
    {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Content-Security-Policy: ' . self::contentSecurityPolicy());
        // HSTS is gated on an explicit opt-in flag, NOT on APP_ENV. A freshly
        // created subdomain often has no certificate for its first minutes or
        // hours; sending HSTS before the cert exists tells the browser to refuse
        // plain HTTP to that host, and the site becomes unreachable with no
        // visible error. Turn FORCE_HSTS on only once https:// is confirmed
        // working on this exact domain.
        if (Config::get('FORCE_HSTS', 'false') === 'true') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Build the Content-Security-Policy.
     *
     * With tracking off this is byte-identical to the policy the kit has
     * always sent: no inline script, no third-party script, nothing but
     * 'self'. Turning tracking on is the ONLY thing that widens it, and it
     * widens it by exactly what the enabled trackers need.
     *
     * The inline snippets are allowed by a per-request nonce rather than
     * 'unsafe-inline'. 'unsafe-inline' would permit ANY inline script on
     * every page — including one injected through some future XSS — which
     * would give away the main thing this header is for. A nonce only permits
     * the exact blocks this application emitted for this one request.
     *
     * The vendor hosts are still listed alongside the nonce: gtag.js and
     * fbevents.js load further scripts of their own, and those inherit no
     * nonce. 'strict-dynamic' would cover them but makes the host list be
     * ignored in CSP3 browsers, so the explicit hosts are the more
     * predictable choice here.
     */
    private static function contentSecurityPolicy(): string
    {
        $script  = ["'self'"];
        $connect = ["'self'"];

        // class_exists guards the standalone entry points (install.php,
        // health.php) that load only part of core.
        if (class_exists('Tracking') && Tracking::isActive()) {
            $script[] = "'nonce-" . self::nonce() . "'";
            $script   = array_merge($script, Tracking::cspScriptSources());
            $connect  = array_merge($connect, Tracking::cspConnectSources());
        }

        return implode('; ', [
            "default-src 'self'",
            "img-src 'self' data: https:",
            "style-src 'self' 'unsafe-inline'",
            'script-src ' . implode(' ', array_unique($script)),
            'connect-src ' . implode(' ', array_unique($connect)),
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
    /**
     * @param string $viewPath Absolute path to the module's view file
     * @param array $data Variables made available to the view as local vars
     */
    public static function render(string $viewPath, array $data = []): void
    {
        if (!file_exists($viewPath)) {
            throw new RuntimeException("View not found: {$viewPath}");
        }

        extract($data, EXTR_SKIP);

        $layout = __DIR__ . '/../resources/layout.php';

        ob_start();
        include $viewPath;
        $content = ob_get_clean();

        if (file_exists($layout)) {
            include $layout; // layout.php echoes $content wherever the page shell needs it
        } else {
            echo $content;
        }
    }
}

/**
 * Global escape helper. Short name deliberately, since it is
 * meant to wrap every dynamic value printed in a view:
 *   <h1><?= e($post['title']) ?></h1>
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
