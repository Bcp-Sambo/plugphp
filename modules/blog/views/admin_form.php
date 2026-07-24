<?php
/**
 * modules/blog/views/admin_form.php — create/edit form (multipart).
 * @var array|null           $post
 * @var array<string,string> $errors
 */
$post = $post ?? [];
$errors = $errors ?? [];
$isEdit = isset($post['id']) && $post['id'] !== '';
$action = $isEdit ? '/admin/blog/' . rawurlencode((string) $post['id']) : '/admin/blog';
$v = fn(string $k): string => e((string) ($post[$k] ?? ''));
$isPublished = !empty($post['published_at']);
$slotSvg = '<span class="img-slot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg></span>';
?>
<a class="backlink" href="/admin/blog">&larr; Blog</a>

<?php if (isset($errors['featured_image'])): ?>
    <div class="alert alert-error alert--inline" role="alert" style="max-width:720px;margin-top:16px"><?= e($errors['featured_image']) ?></div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form-card" style="margin-top:16px">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

    <div class="form-grid">
        <div class="field">
            <label for="b-title" class="field-label">Title</label>
            <input class="input" id="b-title" name="title" type="text" required value="<?= $v('title') ?>">
            <?php if (isset($errors['title'])): ?><p class="field-error"><?= e($errors['title']) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="b-slug" class="field-label">Slug</label>
            <input class="input input--mono" id="b-slug" name="slug" type="text" value="<?= $v('slug') ?>" placeholder="my-post">
        </div>
    </div>

    <div class="field">
        <label for="b-excerpt" class="field-label">Excerpt</label>
        <textarea class="input textarea" id="b-excerpt" name="excerpt" rows="2"><?= $v('excerpt') ?></textarea>
    </div>

    <div class="field">
        <label for="b-body" class="field-label">Body <span class="hint">— plain text, rendered escaped</span></label>
        <textarea class="input input--mono textarea" id="b-body" name="body" rows="12" style="line-height:1.6"><?= $v('body') ?></textarea>
    </div>

    <div class="field">
        <span class="field-label">Featured image <span class="hint">— JPEG/PNG/GIF/WebP, max 5 MB</span></span>
        <div class="upload">
            <div class="upload__thumb ratio ratio-16x9">
                <?php if (!empty($post['featured_image'])): ?>
                    <img src="<?= e((string) $post['featured_image']) ?>" alt="Current featured image" loading="lazy">
                <?php else: ?><?= $slotSvg ?><?php endif; ?>
            </div>
            <label class="upload__btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
                Upload image<input type="file" name="featured_image" accept="image/*">
            </label>
        </div>
    </div>

    <label class="checkbox">
        <input type="checkbox" name="published" value="1" <?= $isPublished ? 'checked' : '' ?>>
        Published <span class="hint">— unchecked saves as draft</span>
    </label>

    <div class="form-section">
        <div class="form-section__label">SEO</div>
        <div class="field">
            <label for="b-mt" class="field-label">Meta title <span class="hint">— defaults to title</span></label>
            <input class="input" id="b-mt" name="meta_title" type="text" value="<?= $v('meta_title') ?>">
        </div>
        <div class="field">
            <label for="b-md" class="field-label">Meta description</label>
            <textarea class="input textarea" id="b-md" name="meta_description" rows="2"><?= $v('meta_description') ?></textarea>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create post' ?></button>
        <a class="btn btn-secondary" href="/admin/blog">Cancel</a>
    </div>
</form>
