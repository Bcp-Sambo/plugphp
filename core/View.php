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
        // The nonce is always present. It costs nothing when no inline script
        // is emitted, and it means the app can use an inline <script> anywhere
        // — the admin settings page does — without ever needing
        // 'unsafe-inline'. A per-request random value is no use to an attacker
        // who cannot read the response that carried it.
        $script  = ["'self'", "'nonce-" . self::nonce() . "'"];
        $connect = ["'self'"];

        // Third-party hosts are the part that stays conditional: they are only
        // allowed while tracking is actually on and configured.
        // class_exists guards the standalone entry points (install.php,
        // health.php) that load only part of core.
        if (class_exists('Tracking') && Tracking::isActive()) {
            $script  = array_merge($script, Tracking::cspScriptSources());
            $connect = array_merge($connect, Tracking::cspConnectSources());
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

        ob_start();
        if (file_exists($layout)) {
            include $layout; // layout.php echoes $content wherever the page shell needs it
        } else {
            echo $content;
        }

        echo self::injectSiteWideTags((string) ob_get_clean());
    }

    /**
     * Add the site-wide <head>/<body> tags that a layout should not have to
     * remember: tracking snippets, favicon, default Open Graph image.
     *
     * WHY THIS IS DONE HERE AND NOT IN THE LAYOUT
     *
     * An update can never overwrite resources/layout.php — it is the site's
     * design work. So anything the layout has to *call* is unreachable by an
     * update: a site on an older layout keeps running current code that
     * nothing invokes, and the feature is silently absent while the page looks
     * completely normal. That is exactly how tracking and branding went
     * missing on a site that had updated correctly.
     *
     * Injecting into the finished HTML instead means these work on ANY
     * layout — original, restyled, or years out of date — with nothing for the
     * site owner to do.
     *
     * Each tag is skipped when the page already provides its own, so a layout
     * that does call these, or a content module that emits a per-page
     * og:image, still wins.
     *
     * AI AGENTS: when adding something site-wide to <head>, add it here rather
     * than to layout.php. A layout is not a delivery mechanism.
     */
    private static function injectSiteWideTags(string $html): string
    {
        // Only a complete document; fragments and non-HTML responses are left alone.
        if (stripos($html, '</head>') === false) {
            return $html;
        }

        $head = '';

        if (class_exists('Branding')) {
            $favicon = Branding::favicon();
            if ($favicon !== null && !preg_match('/<link[^>]+rel=["\'][^"\']*icon/i', $html)) {
                $head .= '<link rel="icon" href="' . e(Url::asset($favicon)) . '">' . "\n";
            }

            // A content module's own og:image (a post's featured image) wins.
            $ogImage = Branding::ogImageAbsolute();
            if ($ogImage !== null && stripos($html, 'og:image') === false) {
                $head .= '<meta property="og:image" content="' . e($ogImage) . '">' . "\n";
            }
        }

        $bodyTag = '';
        if (class_exists('Tracking')) {
            $gaSnippet = Tracking::headSnippet();
            // A layout that still calls headSnippet() itself has already put the
            // measurement id on the page; do not emit it twice.
            $gaId = Tracking::gaId();
            if ($gaSnippet !== '' && ($gaId === null || substr_count($html, $gaId) === 0)) {
                $head .= $gaSnippet;
            }

            $fbSnippet = Tracking::bodySnippet();
            if ($fbSnippet !== '' && stripos($html, "fbq('init'") === false) {
                $bodyTag = $fbSnippet;
            }
        }

        if ($head !== '') {
            $html = preg_replace('#</head>#i', $head . '</head>', $html, 1) ?? $html;
        }
        if ($bodyTag !== '') {
            // Facebook's snippet expects to sit immediately after <body>.
            $html = preg_replace('#(<body\b[^>]*>)#i', '$1' . "\n" . $bodyTag, $html, 1) ?? $html;
        }

        return $html;
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
