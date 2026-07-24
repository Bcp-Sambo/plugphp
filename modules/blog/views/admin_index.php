<?php
/**
 * modules/blog/views/admin_index.php — admin list (incl. drafts) + toggle.
 * @var array $posts
 * @var bool  $visible
 */
$posts = $posts ?? [];
$visible = $visible ?? true;
?>
<div class="admin-toolbar">
    <form method="post" action="/admin/blog/visibility">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <?php if (!$visible): ?><input type="hidden" name="visible" value="1"><?php endif; ?>
        <button type="submit" class="toggle-field" aria-pressed="<?= $visible ? 'true' : 'false' ?>">
            <span class="toggle" aria-hidden="true"></span>
            Show the blog on the public site
        </button>
    </form>
    <a class="btn btn-primary btn-sm" href="/admin/blog/new">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        Add new post
    </a>
</div>

<?php if ($posts === []): ?>
    <div class="table-wrap" style="padding:26px"><p class="muted">No posts yet.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Title</th>
                    <th scope="col" style="width:120px">Status</th>
                    <th scope="col" style="width:130px">Updated</th>
                    <th scope="col" class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($posts as $p): ?>
                    <?php $published = !empty($p['published_at']); ?>
                    <tr>
                        <td class="td-strong"><?= e($p['title']) ?></td>
                        <td><span class="badge <?= $published ? 'badge-published' : 'badge-draft' ?>"><?= $published ? 'Published' : 'Draft' ?></span></td>
                        <td class="td-mono"><?= e($p['updated_at'] ? date('Y-m-d', strtotime((string) $p['updated_at'])) : '') ?></td>
                        <td class="td-actions">
                            <a href="/admin/blog/<?= e((string) $p['id']) ?>/edit">Edit</a>
                            <a class="danger" href="/admin/blog/<?= e((string) $p['id']) ?>/delete">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
