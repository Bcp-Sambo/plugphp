<?php

/**
 * BlogModule
 *
 * Blog content module: public paginated list + single post, admin CRUD with
 * draft/publish, featured-image upload, and the per-module public-visibility
 * toggle. Auto-emits canonical / Open Graph / Article JSON-LD from the
 * post's own fields. Self-contained so it stays independently removable.
 *
 * Body content is rendered ESCAPED (see views/show.php) — admin-entered HTML
 * is never trusted as safe. Rich HTML would require a dedicated sanitizer
 * dependency, which this vanilla kit deliberately does not ship.
 */
final class BlogModule extends Module
{
    private const PER_PAGE = 10;
    private const UPLOAD_SUBDIR = 'blog';

    public function name(): string
    {
        return 'blog';
    }

    public function label(): string
    {
        return 'Blog';
    }

    public function routes(Router $router): void
    {
        require __DIR__ . '/routes.php';
    }

    public function migrations(): array
    {
        return [__DIR__ . '/migrations/001_create_posts.sql'];
    }

    public function dashboardNavItem(): ?array
    {
        return ['label' => 'Blog', 'url' => '/admin/blog'];
    }

    // ---------- Public ----------

    public function publicIndex(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $now = date('Y-m-d H:i:s');

        $total = (int) (Database::fetchOne(
            'SELECT COUNT(*) AS c FROM posts WHERE published_at IS NOT NULL AND published_at <= :now',
            ['now' => $now]
        )['c'] ?? 0);

        // LIMIT/OFFSET are integer-cast values we control (never user strings),
        // inlined because this project's PDO uses real prepared statements
        // where bound LIMIT placeholders are rejected by MySQL.
        $posts = Database::fetchAll(
            'SELECT id, title, slug, excerpt, featured_image, published_at
             FROM posts
             WHERE published_at IS NOT NULL AND published_at <= :now
             ORDER BY published_at DESC
             LIMIT ' . (int) self::PER_PAGE . ' OFFSET ' . (int) $offset,
            ['now' => $now]
        );

        View::render(__DIR__ . '/views/index.php', [
            'posts'           => $posts,
            'page'            => $page,
            'totalPages'      => max(1, (int) ceil($total / self::PER_PAGE)),
            'pageTitle'       => 'Blog',
            'metaDescription' => 'Latest articles and updates.',
        ]);
    }

    public function publicShow(string $slug): void
    {
        $post = Database::fetchOne(
            'SELECT * FROM posts
             WHERE slug = :slug AND published_at IS NOT NULL AND published_at <= :now',
            ['slug' => $slug, 'now' => date('Y-m-d H:i:s')]
        );
        if ($post === null) {
            $this->renderNotFound();
            return;
        }

        $desc = ($post['meta_description'] ?? '') ?: $this->excerpt(($post['excerpt'] ?? '') ?: (string) ($post['body'] ?? ''));

        $ld = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => $post['title'],
            'description'      => $desc,
            'mainEntityOfPage' => $this->canonical('/blog/' . $post['slug']),
            'author'           => ['@type' => 'Organization', 'name' => (string) Config::get('APP_NAME', '')],
            'publisher'        => ['@type' => 'Organization', 'name' => (string) Config::get('APP_NAME', '')],
        ];
        if (!empty($post['published_at'])) {
            $ld['datePublished'] = date('c', strtotime((string) $post['published_at']));
        }
        if (!empty($post['updated_at'])) {
            $ld['dateModified'] = date('c', strtotime((string) $post['updated_at']));
        }
        if (!empty($post['featured_image'])) {
            $ld['image'] = $this->absoluteUrl($post['featured_image']);
        }

        $seo = $this->buildSeo(
            '/blog/' . $post['slug'],
            ($post['meta_title'] ?? '') ?: $post['title'],
            $desc,
            'article',
            $post['featured_image'] ?? null,
            $ld
        );

        View::render(__DIR__ . '/views/show.php', array_merge($seo, ['post' => $post]));
    }

    // ---------- Admin ----------

    public function adminIndex(): void
    {
        Auth::requireLogin();

        $posts = Database::fetchAll(
            'SELECT id, title, slug, published_at, updated_at FROM posts ORDER BY updated_at DESC, id DESC'
        );

        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_index.php', [
            'posts'   => $posts,
            'visible' => Settings::isModuleVisible('blog'),
        ], 'Blog');
    }

    public function adminNew(): void
    {
        Auth::requireLogin();
        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php', [
            'post'   => null,
            'errors' => [],
        ], 'New post');
    }

    public function adminStore(): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        [$data, $errors] = $this->validate();

        $featured = null;
        try {
            $featured = $this->uploadFeatured();
        } catch (\Throwable $e) {
            $errors['featured_image'] = $e->getMessage();
        }

        if ($errors !== []) {
            AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php', [
                'post'   => $_POST,
                'errors' => $errors,
            ], 'New post');
            return;
        }

        $data['slug'] = $this->uniqueSlug($this->slugSource(), null);
        $data['featured_image'] = $featured;
        $data['published_at'] = $this->resolvePublishedAt(null);

        Database::insert('posts', $data);

        header('Location: /admin/blog');
        exit;
    }

    public function adminEdit(string $id): void
    {
        Auth::requireLogin();

        $post = Database::fetchOne('SELECT * FROM posts WHERE id = :id', ['id' => $id]);
        if ($post === null) {
            $this->renderNotFound();
            return;
        }

        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php', [
            'post'   => $post,
            'errors' => [],
        ], 'Edit post');
    }

    public function adminUpdate(string $id): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $existing = Database::fetchOne('SELECT * FROM posts WHERE id = :id', ['id' => $id]);
        if ($existing === null) {
            $this->renderNotFound();
            return;
        }

        [$data, $errors] = $this->validate();

        $featured = null;
        try {
            $featured = $this->uploadFeatured();
        } catch (\Throwable $e) {
            $errors['featured_image'] = $e->getMessage();
        }

        if ($errors !== []) {
            AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php', [
                'post'   => array_merge($existing, $_POST, ['id' => $id]),
                'errors' => $errors,
            ], 'Edit post');
            return;
        }

        $data['slug'] = $this->uniqueSlug($this->slugSource(), $id);
        if ($featured !== null) {
            $data['featured_image'] = $featured;
        }
        // Preserve the original publish date when a post stays published.
        $data['published_at'] = $this->resolvePublishedAt($existing['published_at'] ?? null);

        Database::update('posts', $data, 'id', $id);

        header('Location: /admin/blog');
        exit;
    }

    public function adminDeleteConfirm(string $id): void
    {
        Auth::requireLogin();

        $post = Database::fetchOne('SELECT id, title FROM posts WHERE id = :id', ['id' => $id]);
        if ($post === null) {
            $this->renderNotFound();
            return;
        }

        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_delete.php', [
            'post' => $post,
        ], 'Delete post');
    }

    public function adminDelete(string $id): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        Database::delete('posts', 'id', $id);

        header('Location: /admin/blog');
        exit;
    }

    public function adminToggleVisibility(): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        Settings::set('blog_visible', isset($_POST['visible']) ? '1' : '0');

        header('Location: /admin/blog');
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

        $data = [
            'title'            => $title,
            'excerpt'          => trim((string) ($_POST['excerpt'] ?? '')),
            'body'             => (string) ($_POST['body'] ?? ''),
            'meta_title'       => trim((string) ($_POST['meta_title'] ?? '')),
            'meta_description' => trim((string) ($_POST['meta_description'] ?? '')),
        ];

        return [$data, $errors];
    }

    /**
     * Decide published_at from the "publish" checkbox:
     * - checked + already had a date  => keep the original date
     * - checked + no prior date       => now
     * - unchecked                     => null (draft)
     */
    private function resolvePublishedAt(?string $existing): ?string
    {
        $wantsPublished = isset($_POST['published']);
        if (!$wantsPublished) {
            return null;
        }
        return $existing !== null && $existing !== '' ? $existing : date('Y-m-d H:i:s');
    }

    private function slugSource(): string
    {
        $slug = trim((string) ($_POST['slug'] ?? ''));
        return $this->slugify($slug !== '' ? $slug : (string) ($_POST['title'] ?? ''));
    }

    // ---------- Upload ----------

    private function uploadFeatured(): ?string
    {
        require_once __DIR__ . '/../../core/Upload.php';

        $file = $_FILES['featured_image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return Upload::image($file, self::UPLOAD_SUBDIR);
    }

    // ---------- Self-contained slug + SEO + 404 helpers ----------

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        return $text !== '' ? $text : 'post';
    }

    private function uniqueSlug(string $base, ?string $ignoreId): string
    {
        $slug = $base;
        $n = 1;
        while (true) {
            $sql = 'SELECT id FROM posts WHERE slug = :slug';
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
