<?php

/**
 * Router
 *
 * Minimal route table. Modules register routes via their
 * Module::routes() method, which is called once at bootstrap
 * (see public/index.php).
 *
 * AI AGENTS: register new endpoints only inside a module's
 * routes() method. Never add routes directly in public/index.php
 * or core/ — that defeats the module boundary and breaks the
 * "modules are independently removable" guarantee.
 */
final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $path = rtrim($path, '/') ?: '/';

        $methodRoutes = $this->routes[$method] ?? [];

        // Fast path: an exact literal match (e.g. "/blog", "/admin").
        if (isset($methodRoutes[$path])) {
            $methodRoutes[$path]([]);
            return;
        }

        // Parameterised match: patterns containing {slug}, {id}, etc.
        // A simple regex conversion of the pattern — each {name} segment
        // becomes a named capture that is passed to the handler as an
        // associative array: function (array $params) { $params['slug'] }.
        foreach ($methodRoutes as $pattern => $handler) {
            if (!str_contains($pattern, '{')) {
                continue; // literal route, already tried above
            }

            $params = self::matchPattern($pattern, $path);
            if ($params !== null) {
                $handler($params);
                return;
            }
        }

        $this->renderNotFound();
    }

    /**
     * Convert a route pattern like "/blog/{slug}" into a regex and test it
     * against the request path. Returns the matched params on success
     * (e.g. ['slug' => 'my-post']) or null if it does not match.
     *
     * Segment values match anything except a slash, so "/blog/{slug}"
     * matches "/blog/hello" but not "/blog/hello/extra".
     */
    private static function matchPattern(string $pattern, string $path): ?array
    {
        $regex = preg_replace(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            '(?P<$1>[^/]+)',
            $pattern
        );
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        // Keep only the named captures, not the numeric duplicates.
        return array_filter(
            $matches,
            fn($key) => is_string($key),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function renderNotFound(): void
    {
        http_response_code(404);
        $notFoundView = __DIR__ . '/../resources/404.php';
        if (file_exists($notFoundView)) {
            // Rendered through the normal View pipeline so the 404
            // page automatically inherits the site's layout.php
            // (nav, footer, branding) instead of appearing bare.
            View::render($notFoundView);
        } else {
            echo '404 Not Found';
        }
    }
}
