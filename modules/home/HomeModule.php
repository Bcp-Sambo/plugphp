<?php

/**
 * HomeModule
 *
 * Owns the site root ("/"). Renders a landing page: a static hero plus
 * OPTIONAL teasers of services and the latest blog posts. The teasers read
 * other modules' tables defensively — if a module isn't installed or its
 * public side is hidden, that section simply doesn't render, so Home never
 * breaks and stays independently removable.
 *
 * Emits canonical / Open Graph / Organization JSON-LD for the root URL.
 */
final class HomeModule extends Module
{
    public function name(): string
    {
        return 'home';
    }

    public function label(): string
    {
        return 'Home Page';
    }

    public function routes(Router $router): void
    {
        require __DIR__ . '/routes.php';
    }

    /** Static module — no tables of its own. */
    public function migrations(): array
    {
        return [];
    }

    // No dashboardNavItem(): Home is static content (like About), no admin panel.

    public function show(): void
    {
        $services = $this->safeTeaser(
            'services',
            'SELECT title, slug, summary FROM services ORDER BY display_order ASC, id ASC LIMIT 3'
        );
        $posts = $this->safeTeaser(
            'blog',
            'SELECT title, slug, excerpt, published_at FROM posts
             WHERE published_at IS NOT NULL AND published_at <= :now
             ORDER BY published_at DESC LIMIT 3',
            ['now' => date('Y-m-d H:i:s')]
        );

        $appName = (string) Config::get('APP_NAME', 'Home');

        View::render(__DIR__ . '/views/home.php', array_merge($this->seoHead(), [
            'services' => $services,
            'posts'    => $posts,
            'appName'  => $appName,
        ]));
    }

    /**
     * Fetch a teaser list for another module, but only when that module's
     * public side is visible, and never let a missing table (module not
     * installed) break the homepage.
     *
     * @return array<int,array<string,mixed>>
     */
    private function safeTeaser(string $moduleName, string $sql, array $params = array()): array
    {
        try {
            if (!Settings::isModuleVisible($moduleName)) {
                return array();
            }
            return Database::fetchAll($sql, $params);
        } catch (\Throwable $e) {
            return array();
        }
    }

    /** @return array{pageTitle:string, metaDescription:string, headExtra:string} */
    private function seoHead(): array
    {
        $appName = (string) Config::get('APP_NAME', 'Home');
        $appUrl = rtrim((string) Config::get('APP_URL', ''), '/');
        $desc = 'Welcome to ' . $appName . '.';

        $ld = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $appName,
            'url'      => $appUrl !== '' ? $appUrl . '/' : '',
        );

        $head  = '<link rel="canonical" href="' . e($appUrl . '/') . '">' . "\n";
        $head .= '<meta property="og:type" content="website">' . "\n";
        $head .= '<meta property="og:title" content="' . e($appName) . '">' . "\n";
        $head .= '<meta property="og:description" content="' . e($desc) . '">' . "\n";
        $head .= '<meta property="og:url" content="' . e($appUrl . '/') . '">' . "\n";
        $head .= '<meta property="og:site_name" content="' . e($appName) . '">' . "\n";
        $head .= '<script type="application/ld+json">'
               . json_encode($ld, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
               . '</script>' . "\n";

        return array(
            'pageTitle'       => $appName,
            'metaDescription' => $desc,
            'headExtra'       => $head,
        );
    }
}
