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
    public static function sendSecurityHeaders(): void
    {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'");
        if (Config::isProduction()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
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
