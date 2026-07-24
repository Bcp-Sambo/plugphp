<?php
/**
 * modules/projects/views/admin_index.php — admin list + visibility toggle.
 * @var array $projects
 * @var bool  $visible
 */
$projects = $projects ?? [];
$visible = $visible ?? true;
?>
<div class="admin-toolbar">
    <form method="post" action="/admin/projects/visibility">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <?php if (!$visible): ?><input type="hidden" name="visible" value="1"><?php endif; ?>
        <button type="submit" class="toggle-field" aria-pressed="<?= $visible ? 'true' : 'false' ?>">
            <span class="toggle" aria-hidden="true"></span>
            Show projects on the public site
        </button>
    </form>
    <a class="btn btn-primary btn-sm" href="/admin/projects/new">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        Add new project
    </a>
</div>

<?php if ($projects === []): ?>
    <div class="table-wrap" style="padding:26px"><p class="muted">No projects yet.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Title</th>
                    <th scope="col">Client</th>
                    <th scope="col">Completed</th>
                    <th scope="col" class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $p): ?>
                    <tr>
                        <td class="td-strong"><?= e($p['title']) ?></td>
                        <td class="muted"><?= e($p['client_name'] ?? '') ?></td>
                        <td class="td-mono"><?= e($p['completed_at'] ?? '') ?></td>
                        <td class="td-actions">
                            <a href="/admin/projects/<?= e((string) $p['id']) ?>/edit">Edit</a>
                            <a class="danger" href="/admin/projects/<?= e((string) $p['id']) ?>/delete">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
