<?php
/**
 * modules/auth/views/admin_users.php — read-only user list.
 * @var array $users
 */
$users = $users ?? [];
?>
<?php if ($users === []): ?>
    <div class="table-wrap" style="padding:26px"><p class="muted">No users yet.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="td-strong"><?= e($u['name'] ?? '') ?></td>
                        <td class="td-email"><?= e($u['email']) ?></td>
                        <td class="td-mono" style="font-size:12.5px"><?= e($u['created_at'] ? date('Y-m-d', strtotime((string) $u['created_at'])) : '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
