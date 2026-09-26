<?php
/**
 * modules/auth/views/admin_user_delete.php — delete confirmation step.
 * @var array $user   ['id','name','email','role']
 * @var bool  $isSelf
 */
?>
<div class="confirm">
    <div class="confirm__head">
        <span class="confirm__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1L5 6"/></svg></span>
        <h1>Delete this user?</h1>
    </div>
    <p>
        You're about to permanently delete
        &ldquo;<strong><?= e($user['name'] ?: $user['email']) ?></strong>&rdquo;
        (<?= e($user['email']) ?>, <?= e(Auth::ROLES[$user['role']] ?? $user['role']) ?>).
        This can't be undone.
    </p>
    <?php if ($isSelf): ?>
        <p><strong>This is your own account.</strong> Deleting it signs you out immediately
        and you will not be able to sign back in with it.</p>
    <?php endif; ?>
    <form method="post" action="<?= url('/admin/users/' . (string) $user['id'] . '/delete') ?>" class="confirm__actions">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <a class="btn btn-secondary" href="<?= url('/admin/users') ?>">Cancel</a>
        <button class="btn btn-danger" type="submit">Yes, delete</button>
    </form>
</div>
