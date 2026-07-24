<?php
/**
 * modules/contact-form/views/admin_messages.php — read-only submissions.
 * @var array $messages
 */
$messages = $messages ?? [];
?>
<?php if ($messages === []): ?>
    <div class="table-wrap" style="padding:26px"><p class="muted">No messages yet.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table" style="min-width:640px">
            <thead>
                <tr>
                    <th scope="col" style="width:150px">Received</th>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Message</th>
                    <th scope="col">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($messages as $m): ?>
                    <tr>
                        <td class="td-mono" style="font-size:12.5px"><?= e(date('M j · H:i', strtotime((string) $m['created_at']))) ?></td>
                        <td class="td-strong"><?= e($m['name']) ?></td>
                        <td class="td-email"><?= e($m['email']) ?></td>
                        <td class="muted" style="max-width:280px"><?= nl2br(e($m['message'])) ?></td>
                        <td class="td-mono" style="font-size:12.5px"><?= e($m['ip_address']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
