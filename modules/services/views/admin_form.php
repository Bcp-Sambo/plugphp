<?php
/**
 * modules/services/views/admin_form.php — create/edit form.
 * @var array|null           $service
 * @var array<string,string> $errors
 */
$service = $service ?? [];
$errors = $errors ?? [];
$isEdit = isset($service['id']) && $service['id'] !== '';
$action = $isEdit ? '/admin/services/' . rawurlencode((string) $service['id']) : '/admin/services';
$v = fn(string $k): string => e((string) ($service[$k] ?? ''));
?>
<a class="backlink" href="/admin/services">&larr; Services</a>

<form method="post" action="<?= e($action) ?>" class="form-card" style="margin-top:16px">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

    <div class="form-grid">
        <div class="field">
            <label for="s-title" class="field-label">Title</label>
            <input class="input" id="s-title" name="title" type="text" required value="<?= $v('title') ?>">
            <?php if (isset($errors['title'])): ?><p class="field-error"><?= e($errors['title']) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="s-slug" class="field-label">Slug <span class="hint">— generated from title if blank</span></label>
            <input class="input input--mono" id="s-slug" name="slug" type="text" value="<?= $v('slug') ?>" placeholder="web-development">
        </div>
    </div>

    <div class="field">
        <label for="s-sum" class="field-label">Summary</label>
        <input class="input" id="s-sum" name="summary" type="text" value="<?= $v('summary') ?>">
    </div>

    <div class="field">
        <label for="s-desc" class="field-label">Description</label>
        <textarea class="input textarea" id="s-desc" name="description" rows="6"><?= $v('description') ?></textarea>
    </div>

    <div class="form-grid">
        <div class="field">
            <label for="s-icon" class="field-label">Icon <span class="hint">— icon-set name/class</span></label>
            <input class="input input--mono" id="s-icon" name="icon" type="text" value="<?= $v('icon') ?>" placeholder="bi-gear">
        </div>
        <div class="field">
            <label for="s-order" class="field-label">Display order</label>
            <input class="input" id="s-order" name="display_order" type="number" value="<?= e((string) ($service['display_order'] ?? 0)) ?>">
        </div>
    </div>

    <div class="form-section">
        <div class="form-section__label">SEO</div>
        <div class="field">
            <label for="s-mt" class="field-label">Meta title <span class="hint">— defaults to title</span></label>
            <input class="input" id="s-mt" name="meta_title" type="text" value="<?= $v('meta_title') ?>">
        </div>
        <div class="field">
            <label for="s-md" class="field-label">Meta description</label>
            <textarea class="input textarea" id="s-md" name="meta_description" rows="2"><?= $v('meta_description') ?></textarea>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create service' ?></button>
        <a class="btn btn-secondary" href="/admin/services">Cancel</a>
    </div>
</form>
