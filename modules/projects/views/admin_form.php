<?php
/**
 * modules/projects/views/admin_form.php — create/edit form (multipart).
 * @var array|null           $project
 * @var array<int,string>    $gallery
 * @var array<string,string> $errors
 */
$project = $project ?? [];
$gallery = $gallery ?? [];
$errors = $errors ?? [];
$isEdit = isset($project['id']) && $project['id'] !== '';
$action = $isEdit ? '/admin/projects/' . rawurlencode((string) $project['id']) : '/admin/projects';
$v = fn(string $k): string => e((string) ($project[$k] ?? ''));
$slotSvg = '<span class="img-slot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg></span>';
?>
<a class="backlink" href="/admin/projects">&larr; Projects</a>

<?php if (isset($errors['images'])): ?>
    <div class="alert alert-error alert--inline" role="alert" style="max-width:720px;margin-top:16px"><?= e($errors['images']) ?></div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form-card" style="margin-top:16px">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

    <div class="form-grid">
        <div class="field">
            <label for="p-title" class="field-label">Title</label>
            <input class="input" id="p-title" name="title" type="text" required value="<?= $v('title') ?>">
            <?php if (isset($errors['title'])): ?><p class="field-error"><?= e($errors['title']) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="p-slug" class="field-label">Slug</label>
            <input class="input input--mono" id="p-slug" name="slug" type="text" value="<?= $v('slug') ?>" placeholder="northwind-rebrand">
        </div>
    </div>

    <div class="form-grid">
        <div class="field">
            <label for="p-client" class="field-label">Client name <span class="hint">— public-facing</span></label>
            <input class="input" id="p-client" name="client_name" type="text" value="<?= $v('client_name') ?>">
        </div>
        <div class="field">
            <label for="p-date" class="field-label">Completed date</label>
            <input class="input" id="p-date" name="completed_at" type="date" value="<?= $v('completed_at') ?>">
            <?php if (isset($errors['completed_at'])): ?><p class="field-error"><?= e($errors['completed_at']) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="field">
        <label for="p-url" class="field-label">Project URL</label>
        <input class="input" id="p-url" name="project_url" type="url" value="<?= $v('project_url') ?>" placeholder="https://">
    </div>

    <div class="field">
        <label for="p-sum" class="field-label">Summary</label>
        <input class="input" id="p-sum" name="summary" type="text" value="<?= $v('summary') ?>">
    </div>

    <div class="field">
        <label for="p-desc" class="field-label">Description</label>
        <textarea class="input textarea" id="p-desc" name="description" rows="5"><?= $v('description') ?></textarea>
    </div>

    <div class="field">
        <span class="field-label">Featured image <span class="hint">— JPEG/PNG/GIF/WebP, max 5 MB; replaces current</span></span>
        <div class="upload">
            <div class="upload__thumb ratio ratio-16x9">
                <?php if (!empty($project['featured_image'])): ?>
                    <img src="<?= e((string) $project['featured_image']) ?>" alt="Current featured image" loading="lazy">
                <?php else: ?><?= $slotSvg ?><?php endif; ?>
            </div>
            <label class="upload__btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
                Upload image<input type="file" name="featured_image" accept="image/*">
            </label>
        </div>
    </div>

    <div class="field">
        <span class="field-label">Gallery images <span class="hint">— added to any existing gallery</span></span>
        <div class="gallery-grid">
            <?php foreach ($gallery as $i => $img): ?>
                <div class="ratio ratio-1x1"><img src="<?= e($img) ?>" alt="Gallery image <?= e((string) ($i + 1)) ?>" loading="lazy"></div>
            <?php endforeach; ?>
            <label class="upload__btn" style="aspect-ratio:1/1;flex-direction:column;justify-content:center;text-align:center;color:var(--accent)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                Add<input type="file" name="gallery_images[]" accept="image/*" multiple>
            </label>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section__label">SEO</div>
        <div class="field">
            <label for="p-mt" class="field-label">Meta title <span class="hint">— defaults to title</span></label>
            <input class="input" id="p-mt" name="meta_title" type="text" value="<?= $v('meta_title') ?>">
        </div>
        <div class="field">
            <label for="p-md" class="field-label">Meta description</label>
            <textarea class="input textarea" id="p-md" name="meta_description" rows="2"><?= $v('meta_description') ?></textarea>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create project' ?></button>
        <a class="btn btn-secondary" href="/admin/projects">Cancel</a>
    </div>
</form>
