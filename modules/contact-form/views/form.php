<?php
/**
 * modules/contact-form/views/form.php
 * @var array<string,string> $errors
 * @var array<string,string> $old
 * @var bool                 $sent
 */
$errors = $errors ?? [];
$old = $old ?? [];
$sent = $sent ?? false;

$pageTitle = 'Contact Us';
$metaDescription = 'Get in touch — send us a message and we will get back to you.';

$errIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>';
?>
<div class="container section">
    <span class="eyebrow">Contact</span>
    <h1 class="h-page">Start a conversation</h1>

    <div class="contact-grid">
        <div>
            <?php if ($sent): ?>
                <div class="alert alert-success" role="status">
                    <span style="flex:0 0 auto;width:30px;height:30px;border-radius:50%;background:var(--green);display:inline-flex;align-items:center;justify-content:center"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                    <div>
                        <span class="alert__title alert__title--block">Thanks — your message has been sent.</span>
                        <p style="font-size:14.5px;color:var(--body);margin-top:6px">We usually reply within one business day.</p>
                        <a class="textlink" href="/contact" style="display:inline-block;margin-top:12px">Send another &rarr;</a>
                    </div>
                </div>
            <?php else: ?>
                <form method="post" action="/contact" novalidate style="display:flex;flex-direction:column;gap:18px">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <div class="field">
                        <label for="contact-name">Name</label>
                        <input class="input" type="text" id="contact-name" name="name" required value="<?= e($old['name'] ?? '') ?>">
                        <?php if (isset($errors['name'])): ?><p class="field-error"><?= $errIcon ?><?= e($errors['name']) ?></p><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="contact-email">Email</label>
                        <input class="input <?= isset($errors['email']) ? 'input--error' : '' ?>" type="email" id="contact-email" name="email" required value="<?= e($old['email'] ?? '') ?>">
                        <?php if (isset($errors['email'])): ?><p class="field-error"><?= $errIcon ?><?= e($errors['email']) ?></p><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="contact-message">Message</label>
                        <textarea class="input textarea" id="contact-message" name="message" rows="5" required><?= e($old['message'] ?? '') ?></textarea>
                        <?php if (isset($errors['message'])): ?><p class="field-error"><?= $errIcon ?><?= e($errors['message']) ?></p><?php endif; ?>
                    </div>
                    <div><button class="btn btn-cta" type="submit">Send message</button></div>
                </form>
            <?php endif; ?>
        </div>

        <aside class="card">
            <h2 style="font-weight:700;font-size:16px;color:var(--ink)">Get in touch</h2>
            <p style="font-size:14.5px;line-height:1.6;color:var(--muted-2);margin-top:10px">Questions about PlugPHP, a build, or migrating an existing site? Send a note and we'll get back to you.</p>
            <p style="font-size:14.5px;line-height:1.6;color:var(--muted-2);margin-top:14px">This is the built-in contact module — CSRF-protected and rate-limited out of the box.</p>
        </aside>
    </div>
</div>
