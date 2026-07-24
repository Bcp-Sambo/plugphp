<?php
/**
 * modules/services/views/admin_delete.php — delete confirmation step.
 * @var array $service  ['id' => ..., 'title' => ...]
 */
?>
<div class="confirm">
    <div class="confirm__head">
        <span class="confirm__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1L5 6"/></svg></span>
        <h1>Delete this service?</h1>
    </div>
    <p>You're about to permanently delete “<strong><?= e($service['title']) ?></strong>”. This can't be undone.</p>
    <form method="post" action="/admin/services/<?= e((string) $service['id']) ?>/delete" class="confirm__actions">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <a class="btn btn-secondary" href="/admin/services">Cancel</a>
        <button class="btn btn-danger" type="submit">Yes, delete</button>
    </form>
</div>
