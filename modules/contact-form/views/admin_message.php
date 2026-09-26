<?php
/**
 * modules/contact-form/views/admin_message.php — one message, with a reply box.
 *
 * Restyle freely. Do not remove the csrf_token field.
 *
 * @var array $message ['id','name','email','message','ip_address','status','admin_reply','replied_at','created_at']
 * @var array $flash   ['errors' => string[], 'success' => string]
 */
$flash = $flash ?? ['errors' => [], 'success' => ''];
$status = (string) ($message['status'] ?? 'new');
?>

<?php if ($flash['success'] !== ''): ?>
    <div class="pp-note pp-note--ok"><?= e($flash['success']) ?></div>
<?php endif; ?>
<?php if ($flash['errors'] !== []): ?>
    <div class="pp-note pp-note--bad">
        <ul><?php foreach ($flash['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<p><a class="textlink" href="<?= url('/admin/messages') ?>">&larr; All messages</a></p>

<div class="pp-panel">
    <div class="pp-msg__head">
        <div>
            <h2><?= e($message['name']) ?></h2>
            <p class="pp-meta">
                <a href="mailto:<?= e($message['email']) ?>"><?= e($message['email']) ?></a>
                &middot; <?= e($message['created_at'] ? date('j M Y \a\t H:i', strtotime((string) $message['created_at'])) : '') ?>
                <?php if (!empty($message['ip_address'])): ?>
                    &middot; from <?= e($message['ip_address']) ?>
                <?php endif; ?>
            </p>
        </div>
        <span class="pp-badge pp-badge--<?= e($status) ?>">
            <?= e(ContactFormModule::STATUSES[$status] ?? $status) ?>
        </span>
    </div>

    <div class="pp-msg__body"><?= nl2br(e($message['message'])) ?></div>
</div>

<?php if (!empty($message['admin_reply'])): ?>
<div class="pp-panel pp-panel--sent">
    <h2>Your reply</h2>
    <p class="pp-meta">
        Sent <?= e($message['replied_at'] ? date('j M Y \a\t H:i', strtotime((string) $message['replied_at'])) : '') ?>
    </p>
    <div class="pp-msg__body"><?= nl2br(e($message['admin_reply'])) ?></div>
</div>
<?php endif; ?>

<form class="pp-panel" method="post" action="<?= url('/admin/messages/' . (string) $message['id'] . '/reply') ?>">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <h2><?= !empty($message['admin_reply']) ? 'Send another reply' : 'Reply' ?></h2>
    <p class="pp-meta">
        Goes to <strong><?= e($message['email']) ?></strong> using the site's mail
        settings. Their original message is quoted underneath automatically.
    </p>
    <div class="field">
        <label class="sr-only" for="reply">Your reply</label>
        <textarea id="reply" name="reply" rows="7" required placeholder="Write your reply…"></textarea>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Send reply</button></div>
</form>

<style>
.pp-note { border-radius:10px; padding:14px 18px; margin-bottom:16px; border:1px solid; }
.pp-note--ok { background:#e9f7ee; border-color:#bfe3cc; color:#12331f; }
.pp-note--bad { background:#fdeeee; border-color:#f0c9c9; color:#4a1414; }
.pp-note ul { margin:0 0 0 1.1rem; padding:0; }
.pp-panel { background:#fff; border:1px solid #e3e6ea; border-radius:10px; padding:18px 20px; margin-bottom:16px; }
.pp-panel--sent { background:#f7fbf8; border-color:#cfe6d8; }
.pp-panel h2 { font-size:1.05rem; margin:0 0 .3rem; }
.pp-msg__head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; }
.pp-meta { font-size:12.5px; color:#5b6270; margin:0 0 .6rem; }
.pp-msg__body { font-size:14.5px; line-height:1.65; color:#23262b; white-space:normal; }
.pp-panel textarea { width:100%; padding:10px 12px; border:1px solid #d4d8de; border-radius:7px; font:inherit; font-size:14px; }
.pp-badge { display:inline-block; flex:none; font-size:11px; font-weight:700; letter-spacing:.03em; padding:.2rem .5rem; border-radius:5px; }
.pp-badge--new     { background:#eaeff7; color:#2a4b7c; }
.pp-badge--read    { background:#eef0f3; color:#5b6270; }
.pp-badge--replied { background:#e6f6ec; color:#136c34; }
.sr-only { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); }
</style>
