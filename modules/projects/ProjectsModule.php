<?php

/**
 * ProjectsModule
 *
 * Portfolio content module: public grid + case-study detail, admin CRUD
 * with image uploads (featured image + a JSON gallery), and the per-module
 * public-visibility toggle. Auto-emits canonical / Open Graph / CreativeWork
 * JSON-LD from the row's own fields. Self-contained (own slug/SEO/404/upload
 * plumbing) so it stays independently removable.
 *
 * All image uploads go through core/Upload.php (whitelist + MIME sniff +
 * re-encode) — never handled directly here.
 */
final class ProjectsModule extends Module
{
    private const UPLOAD_SUBDIR = 'projects';

    public function name(): string
    {
        return 'projects';
    }

    public function label(): string
    {
        return 'Projects';
    }

    public function routes(Router $router): void
    {
        require __DIR__ . '/routes.php';
    }

    public function migrations(): array
    {
        return [__DIR__ . '/migrations/001_create_projects.sql'];
    }

    public function dashboardNavItem(): ?array
    {
        return ['label' => 'Projects', 'url' => '/admin/projects'];
    }

    // ---------- Public ----------

    public function publicIndex(): void
    {
        $projects = Database::fetchAll(
            'SELECT id, title, slug, summary, featured_image, client_name
             FROM projects ORDER BY completed_at DESC, id DESC'
        );

        View::render(__DIR__ . '/views/index.php', [
            'projects'        => $projects,
            'pageTitle'       => 'Projects',
            'metaDescription' => 'Selected projects and case studies.',
        ]);
    }

    public function publicShow(string $slug): void
    {
        $project = Database::fetchOne('SELECT * FROM projects WHERE slug = :slug', ['slug' => $slug]);
        if ($project === null) {
            $this->renderNotFound();
            return;
        }

        $gallery = $this->decodeGallery($project['gallery_images'] ?? null);
        $summary = (string) ($project['summary'] ?? '');

        $ld = [
            '@context'    => 'https://schema.org',
            '@type'       => 'CreativeWork',
            'name'        => $project['title'],
            'description' => $this->excerpt($summary !== '' ? $summary : (string) ($project['description'] ?? '')),
            'url'         => $this->canonical('/projects/' . $project['slug']),
        ];
        if (!empty($project['featured_image'])) {
            $ld['image'] = $this->absoluteUrl($project['featured_image']);
        }
        if (!empty($project['completed_at'])) {
            $ld['dateCreated'] = $project['completed_at'];
        }

        $seo = $this->buildSeo(
            '/projects/' . $project['slug'],
            ($project['meta_title'] ?? '') ?: $project['title'],
            ($project['meta_description'] ?? '') ?: $this->excerpt($summary),
            'article',
            $project['featured_image'] ?? null,
            $ld
        );

        View::render(__DIR__ . '/views/show.php', array_merge($seo, [
            'project' => $project,
            'gallery' => $gallery,
        ]));
    }

    // ---------- Admin ----------

    public function adminIndex(): void
    {
        Auth::requireLogin();

        $projects = Database::fetchAll(
            'SELECT id, title, slug, client_name, completed_at
             FROM projects ORDER BY completed_at DESC, id DESC'
        );

        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_index.php', [
            'projects' => $projects,
            'visible'  => Settings::isModuleVisible('projects'),
        ], 'Projects');
    }

    public function adminNew(): void
    {
        Auth::requireLogin();
        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php', [
            'project' => null,
            'gallery' => [],
            'errors'  => [],
        ], 'New project');
    }

    public function adminStore(): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        [$data, $errors] = $this->validate();

        $featured = null;
        $gallery = [];
        try {
            $featured = $this->uploadFeatured();
            $gallery  = $this->uploadGallery();
        } catch (\Throwable $e) {
            $errors['images'] = $e->getMessage();
        }

        if ($errors !== []) {
            $this->reRenderForm($_POST, $errors, 'New project');
            return;
        }

        $data['slug']           = $this->uniqueSlug($this->slugSource(), null);
        $data['featured_image'] = $featured;
        $data['gallery_images'] = $gallery !== [] ? json_encode($gallery) : null;

        Database::insert('projects', $data);

        header('Location: /admin/projects');
        exit;
    }

    public function adminEdit(string $id): void
    {
        Auth::requireLogin();

        $project = Database::fetchOne('SELECT * FROM projects WHERE id = :id', ['id' => $id]);
        if ($project === null) {
            $this->renderNotFound();
            return;
        }

        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php', [
            'project' => $project,
            'gallery' => $this->decodeGallery($project['gallery_images'] ?? null),
            'errors'  => [],
        ], 'Edit project');
    }

    public function adminUpdate(string $id): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $existing = Database::fetchOne('SELECT * FROM projects WHERE id = :id', ['id' => $id]);
        if ($existing === null) {
            $this->renderNotFound();
            return;
        }

        [$data, $errors] = $this->validate();

        $featured = null;
        $newGallery = [];
        try {
            $featured   = $this->uploadFeatured();
            $newGallery = $this->uploadGallery();
        } catch (\Throwable $e) {
            $errors['images'] = $e->getMessage();
        }

        if ($errors !== []) {
            AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php', [
                'project' => array_merge($existing, $_POST, ['id' => $id]),
                'gallery' => $this->decodeGallery($existing['gallery_images'] ?? null),
                'errors'  => $errors,
            ], 'Edit project');
            return;
        }

        $data['slug'] = $this->uniqueSlug($this->slugSource(), $id);

        // Only replace the featured image if a new one was uploaded.
        if ($featured !== null) {
            $data['featured_image'] = $featured;
        }
        // New gallery uploads are appended to what's already there.
        if ($newGallery !== []) {
            $merged = array_merge($this->decodeGallery($existing['gallery_images'] ?? null), $newGallery);
            $data['gallery_images'] = json_encode($merged);
        }

        Database::update('projects', $data, 'id', $id);

        header('Location: /admin/projects');
        exit;
    }

    public function adminDeleteConfirm(string $id): void
    {
        Auth::requireLogin();

        $project = Database::fetchOne('SELECT id, title FROM projects WHERE id = :id', ['id' => $id]);
        if ($project === null) {
            $this->renderNotFound();
            return;
        }

        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_delete.php', [
            'project' => $project,
        ], 'Delete project');
    }

    public function adminDelete(string $id): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        Database::delete('projects', 'id', $id);

        header('Location: /admin/projects');
        exit;
    }

    public function adminToggleVisibility(): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        Settings::set('projects_visible', isset($_POST['visible']) ? '1' : '0');

        header('Location: /admin/projects');
        exit;
    }

    // ---------- Validation ----------

    /** @return array{0: array<string,mixed>, 1: array<string,string>} */
    private function validate(): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }

        $completedAt = trim((string) ($_POST['completed_at'] ?? ''));
        if ($completedAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedAt)) {
            $errors['completed_at'] = 'Use the date format YYYY-MM-DD.';
        }

        $data = [
            'title'            => $title,
            'client_name'      => trim((string) ($_POST['client_name'] ?? '')),
            'summary'          => trim((string) ($_POST['summary'] ?? '')),
            'description'      => (string) ($_POST['description'] ?? ''),
            'project_url'      => trim((string) ($_POST['project_url'] ?? '')),
            'completed_at'     => $completedAt !== '' ? $completedAt : null,
            'meta_title'       => trim((string) ($_POST['meta_title'] ?? '')),
            'meta_description' => trim((string) ($_POST['meta_description'] ?? '')),
        ];

        return [$data, $errors];
    }

    private function reRenderForm(array $project, array $errors, string $title): void
    {
        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php', [
            'project' => $project,
            'gallery' => [],
            'errors'  => $errors,
        ], $title);
    }

    private function slugSource(): string
    {
        $slug = trim((string) ($_POST['slug'] ?? ''));
        return $this->slugify($slug !== '' ? $slug : (string) ($_POST['title'] ?? ''));
    }

    // ---------- Uploads (via core/Upload.php) ----------

    private function uploadFeatured(): ?string
    {
        require_once __DIR__ . '/../../core/Upload.php';

        $file = $_FILES['featured_image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return Upload::image($file, self::UPLOAD_SUBDIR);
    }

    /** @return array<int,string> stored web paths */
    private function uploadGallery(): array
    {
        require_once __DIR__ . '/../../core/Upload.php';

        $paths = [];
        foreach ($this->normalizeFiles($_FILES['gallery_images'] ?? null) as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $paths[] = Upload::image($file, self::UPLOAD_SUBDIR);
        }
        return $paths;
    }

    /**
     * Turn PHP's column-wise $_FILES['field'] (arrays of name/tmp_name/...)
     * into a list of per-file arrays.
     *
     * @return array<int, array<string,mixed>>
     */
    private function normalizeFiles(mixed $field): array
    {
        if (!is_array($field) || !isset($field['name']) || !is_array($field['name'])) {
            return [];
        }
        $out = [];
        foreach (array_keys($field['name']) as $i) {
            $out[] = [
                'name'     => $field['name'][$i] ?? '',
                'type'     => $field['type'][$i] ?? '',
                'tmp_name' => $field['tmp_name'][$i] ?? '',
                'error'    => $field['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $field['size'][$i] ?? 0,
            ];
        }
        return $out;
    }

    /** @return array<int,string> */
    private function decodeGallery(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    // ---------- Self-contained slug + SEO + 404 helpers ----------

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        return $text !== '' ? $text : 'project';
    }

    private function uniqueSlug(string $base, ?string $ignoreId): string
    {
        $slug = $base;
        $n = 1;
        while (true) {
            $sql = 'SELECT id FROM projects WHERE slug = :slug';
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

    private function excerpt(string $text, int $limit = 155): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    }

    private function absoluteUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return rtrim((string) Config::get('APP_URL', ''), '/') . '/' . ltrim($url, '/');
    }

    /**
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

    private function renderNotFound(): void
    {
        http_response_code(404);
        View::render(__DIR__ . '/../../resources/404.php');
    }
}
