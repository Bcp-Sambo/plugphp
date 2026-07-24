<?php
/**
 * modules/services/views/admin_index.php — admin list + visibility toggle.
 * @var array $services
 * @var bool  $visible
 */
$services = $services ?? [];
$visible = $visible ?? true;
?>
<div class="admin-toolbar">
    <form method="post" action="/admin/services/visibility">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <?php if (!$visible): ?><input type="hidden" name="visible" value="1"><?php endif; ?>
        <button type="submit" class="toggle-field" aria-pressed="<?= $visible ? 'true' : 'false' ?>">
            <span class="toggle" aria-hidden="true"></span>
            Show services on the public site
        </button>
    </form>
    <a class="btn btn-primary btn-sm" href="/admin/services/new">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        Add new service
    </a>
</div>

<?php if ($services === []): ?>
    <div class="table-wrap" style="padding:26px"><p class="muted">No services yet.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col" style="width:80px">Order</th>
                    <th scope="col">Title</th>
                    <th scope="col">Slug</th>
                    <th scope="col" class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $s): ?>
                    <tr>
                        <td class="td-mono"><?= e((string) $s['display_order']) ?></td>
                        <td class="td-strong"><?= e($s['title']) ?></td>
                        <td class="td-slug"><?= e($s['slug']) ?></td>
                        <td class="td-actions">
                            <a href="/admin/services/<?= e((string) $s['id']) ?>/edit">Edit</a>
                            <a class="danger" href="/admin/services/<?= e((string) $s['id']) ?>/delete">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
