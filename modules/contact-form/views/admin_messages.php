<?php
/**
 * modules/contact-form/views/admin_messages.php — the inbox.
 *
 * Restyle freely.
 *
 * @var array $messages [['id','name','email','message','status','replied_at','created_at'], ...]
 * @var array $flash    ['errors' => string[], 'success' => string]
 * @var int   $unread
 */
$messages = $messages ?? [];
$flash = $flash ?? ['errors' => [], 'success' => ''];
?>

<?php if ($flash['success'] !== ''): ?>
    <div class="pp-note pp-note--ok"><?= e($flash['success']) ?></div>
<?php endif; ?>
<?php if ($flash['errors'] !== []): ?>
    <div class="pp-note pp-note--bad">
        <ul><?php foreach ($flash['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if ($messages === []): ?>
    <div class="table-wrap" style="padding:26px"><p class="muted">No messages yet.</p></div>
<?php else: ?>
    <?php if ($unread > 0): ?>
        <p class="pp-unread"><?= e((string) $unread) ?> unread</p>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="table" style="min-width:640px">
            <thead>
                <tr>
                    <th scope="col" style="width:120px">Status</th>
                    <th scope="col" style="width:150px">Received</th>
                    <th scope="col">From</th>
                    <th scope="col">Message</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($messages as $m):
                    $status = (string) ($m['status'] ?? 'new');
                    $snippet = trim(preg_replace('/\s+/', ' ', (string) $m['message']) ?? '');
                    if (mb_strlen($snippet) > 90) {
                        $snippet = rtrim(mb_substr($snippet, 0, 89)) . '…';
                    }
                ?>
                    <tr class="<?= $status === 'new' ? 'pp-row--new' : '' ?>">
                        <td>
                            <span class="pp-badge pp-badge--<?= e($status) ?>">
                                <?= e(ContactFormModule::STATUSES[$status] ?? $status) ?>
                            </span>
                        </td>
                        <td class="td-mono" style="font-size:12.5px">
                            <?= e($m['created_at'] ? date('Y-m-d H:i', strtotime((string) $m['created_at'])) : '') ?>
                        </td>
                        <td>
                            <a class="td-strong" href="<?= url('/admin/messages/' . (string) $m['id']) ?>"><?= e($m['name']) ?></a>
                            <div class="td-email" style="font-size:12.5px"><?= e($m['email']) ?></div>
                        </td>
                        <td>
                            <a class="pp-snippet" href="<?= url('/admin/messages/' . (string) $m['id']) ?>"><?= e($snippet) ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<style>
.pp-note { border-radius:10px; padding:14px 18px; margin-bottom:16px; border:1px solid; }
.pp-note--ok { background:#e9f7ee; border-color:#bfe3cc; color:#12331f; }
.pp-note--bad { background:#fdeeee; border-color:#f0c9c9; color:#4a1414; }
.pp-note ul { margin:0 0 0 1.1rem; padding:0; }
.pp-unread { font-size:13px; color:#5b6270; margin:0 0 10px; }
.pp-badge { display:inline-block; font-size:11px; font-weight:700; letter-spacing:.03em;
            padding:.2rem .5rem; border-radius:5px; }
.pp-badge--new     { background:#eaeff7; color:#2a4b7c; }
.pp-badge--read    { background:#eef0f3; color:#5b6270; }
.pp-badge--replied { background:#e6f6ec; color:#136c34; }
.pp-row--new .td-strong { font-weight:700; }
.pp-snippet { color:#3d434d; text-decoration:none; }
.pp-snippet:hover { text-decoration:underline; }
</style>
