<?php

/**
 * ServicesModule
 *
 * Content module: public services list + detail, full admin CRUD, and a
 * per-module public-visibility toggle. Public routes are registered ONLY
 * when the module is visible (see routes.php) so a hidden module 404s
 * naturally; admin routes always register.
 *
 * Auto-emits meta title/description, canonical, Open Graph and Service
 * JSON-LD from the row's own fields — never hand-authored per page. This
 * module is self-contained (its own slug/SEO/404 helpers) so it stays
 * independently removable.
 */
final class ServicesModule extends Module
{
    public function name(): string
    {
        return 'services';
    }

    public function label(): string
    {
        return 'Services';
    }

    public function routes(Router $router): void
    {
        require __DIR__ . '/routes.php';
    }

    public function migrations(): array
    {
        return [__DIR__ . '/migrations/001_create_services.sql'];
    }

    public function dashboardNavItem(): ?array
    {
        return ['label' => 'Services', 'url' => '/admin/services'];
    }

    // ---------- Public ----------

    public function publicIndex(): void
    {
        $services = Database::fetchAll(
            'SELECT id, title, slug, summary, icon FROM services ORDER BY display_order ASC, id ASC'
        );

        View::render(__DIR__ . '/views/index.php', [
            'services'        => $services,
            'pageTitle'       => 'Services',
            'metaDescription' => 'The services we offer.',
        ]);
    }

    public function publicShow(string $slug): void
    {
        $service = Database::fetchOne('SELECT * FROM services WHERE slug = :slug', ['slug' => $slug]);
        if ($service === null) {
            $this->renderNotFound();
            return;
        }

        $summary = (string) ($service['summary'] ?? '');
        $seo = $this->buildSeo(
            '/services/' . $service['slug'],
            ($service['meta_title'] ?? '') ?: $service['title'],
            ($service['meta_description'] ?? '') ?: $this->excerpt($summary),
            'website',
            null,
            [
                '@context'    => 'https://schema.org',
                '@type'       => 'Service',
                'name'        => $service['title'],
                'description' => $this->excerpt($summary !== '' ? $summary : (string) ($service['description'] ?? '')),
                'url'         => $this->canonical('/services/' . $service['slug']),
                'provider'    => [
                    '@type' => 'Organization',
                    'name'  => (string) Config::get('APP_NAME', ''),
                ],
            ]
        );

        View::render(__DIR__ . '/views/show.php', array_merge($seo, ['service' => $service]));
    }

    // ---------- Admin ----------

    public function adminIndex(): void
    {
        Auth::requireLogin();

        $services = Database::fetchAll(
            'SELECT id, title, slug, display_order FROM services ORDER BY display_order ASC, id ASC'
        );

        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_index.php', [
            'services' => $services,
            'visible'  => Settings::isModuleVisible('services'),
        ], 'Services');
    }

    public function adminNew(): void
    {
        Auth::requireLogin();
        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php', [
            'service' => null,
            'errors'  => [],
        ], 'New service');
    }

    public function adminStore(): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        [$data, $errors] = $this->validate();
        if ($errors !== []) {
            AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php', [
                'service' => $_POST,
                'errors'  => $errors,
            ], 'New service');
            return;
        }

        $data['slug'] = $this->uniqueSlug($this->slugSource(), null);
        Database::insert('services', $data);

        header('Location: /admin/services');
        exit;
    }

    public function adminEdit(string $id): void
    {
        Auth::requireLogin();

        $service = Database::fetchOne('SELECT * FROM services WHERE id = :id', ['id' => $id]);
        if ($service === null) {
            $this->renderNotFound();
            return;
        }

        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php', [
            'service' => $service,
            'errors'  => [],
        ], 'Edit service');
    }

    public function adminUpdate(string $id): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $existing = Database::fetchOne('SELECT id FROM services WHERE id = :id', ['id' => $id]);
        if ($existing === null) {
            $this->renderNotFound();
            return;
        }

        [$data, $errors] = $this->validate();
        if ($errors !== []) {
            AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php', [
                'service' => array_merge($_POST, ['id' => $id]),
                'errors'  => $errors,
            ], 'Edit service');
            return;
        }

        $data['slug'] = $this->uniqueSlug($this->slugSource(), $id);
        Database::update('services', $data, 'id', $id);

        header('Location: /admin/services');
        exit;
    }

    public function adminDeleteConfirm(string $id): void
    {
        Auth::requireLogin();

        $service = Database::fetchOne('SELECT id, title FROM services WHERE id = :id', ['id' => $id]);
        if ($service === null) {
            $this->renderNotFound();
            return;
        }

        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_delete.php', [
            'service' => $service,
        ], 'Delete service');
    }

    public function adminDelete(string $id): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        Database::delete('services', 'id', $id);

        header('Location: /admin/services');
        exit;
    }

    public function adminToggleVisibility(): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        Settings::set('services_visible', isset($_POST['visible']) ? '1' : '0');

        header('Location: /admin/services');
        exit;
    }

    // ---------- Validation ----------

    /** @return array{0: array<string,mixed>, 1: array<string,string>} [data, errors] */
    private function validate(): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }

        $data = [
            'title'            => $title,
            'summary'          => trim((string) ($_POST['summary'] ?? '')),
            'description'      => (string) ($_POST['description'] ?? ''),
            'icon'             => trim((string) ($_POST['icon'] ?? '')),
            'display_order'    => (int) ($_POST['display_order'] ?? 0),
            'meta_title'       => trim((string) ($_POST['meta_title'] ?? '')),
            'meta_description' => trim((string) ($_POST['meta_description'] ?? '')),
        ];

        return [$data, $errors];
    }

    private function slugSource(): string
    {
        $slug = trim((string) ($_POST['slug'] ?? ''));
        return $this->slugify($slug !== '' ? $slug : (string) ($_POST['title'] ?? ''));
    }

    // ---------- Self-contained slug + SEO + 404 helpers ----------

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        return $text !== '' ? $text : 'service';
    }

    private function uniqueSlug(string $base, ?string $ignoreId): string
    {
        $slug = $base;
        $n = 1;
        while (true) {
            $sql = 'SELECT id FROM services WHERE slug = :slug';
            $params = ['slug' => $slug];
            if ($ignoreId !== null) {
                $sql .= ' AND id != :id';
                $params['id'] = $ignoreId;
            }
            if (Database::fetchOne($sql, $params) === null) {
                return $slug;
            }
            $n++;
            $slug = $base . '-' . $n;
        }
    }

    private function canonical(string $path): string
    {
        return rtrim((string) Config::get('APP_URL', ''), '/') . $path;
    }

    /** Plain-text meta description from possibly-multiline text. */
    private function excerpt(string $text, int $limit = 155): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    }

    /**
     * Build canonical + Open Graph + JSON-LD for a detail page. Returns the
     * pageTitle / metaDescription / headExtra the layout consumes. All values
     * are escaped here (attributes via e(); JSON-LD via json_encode with
     * JSON_HEX_TAG so a "</script>" in the data cannot break out).
     *
     * @return array{pageTitle:string, metaDescription:string, headExtra:string}
     */
    private function buildSeo(
        string $canonicalPath,
        string $title,
        string $description,
        string $ogType,
        ?string $imageUrl,
        array $jsonLd
    ): array {
        $canonical = $this->canonical($canonicalPath);
        $siteName = (string) Config::get('APP_NAME', '');

        $head  = '<link rel="canonical" href="' . e($canonical) . '">' . "\n";
        $head .= '<meta property="og:type" content="' . e($ogType) . '">' . "\n";
        $head .= '<meta property="og:title" content="' . e($title) . '">' . "\n";
        if ($description !== '') {
            $head .= '<meta property="og:description" content="' . e($description) . '">' . "\n";
        }
        $head .= '<meta property="og:url" content="' . e($canonical) . '">' . "\n";
        if ($siteName !== '') {
            $head .= '<meta property="og:site_name" content="' . e($siteName) . '">' . "\n";
        }
        if ($imageUrl !== null && $imageUrl !== '') {
            $head .= '<meta property="og:image" content="' . e($this->absoluteUrl($imageUrl)) . '">' . "\n";
        }
        $head .= '<script type="application/ld+json">'
               . json_encode($jsonLd, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
               . '</script>' . "\n";

        return [
            'pageTitle'       => $title,
            'metaDescription' => $description,
            'headExtra'       => $head,
        ];
    }

    private function absoluteUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return rtrim((string) Config::get('APP_URL', ''), '/') . '/' . ltrim($url, '/');
    }

    private function renderNotFound(): void
    {
        http_response_code(404);
        View::render(__DIR__ . '/../../resources/404.php');
    }
}
