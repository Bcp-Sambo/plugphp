<?php
/**
 * modules/auth/views/reset-password.php
 * @var string      $token
 * @var bool        $valid
 * @var string|null $error
 */
$token = $token ?? '';
$valid = $valid ?? false;
$error = $error ?? null;
$pageTitle = 'Set a new password';
$bareLayout = true;
$brandName = Config::get('APP_NAME', 'PlugPHP');
$mark = '<img class="brand__mark" src="/assets/img/logo.png" alt="" width="34" height="34">';
?>
<div class="auth">
    <a class="auth__brand" href="/"><?= $mark ?><span><?= e($brandName) ?></span></a>
    <div class="auth-card">
        <?php if (!$valid): ?>
            <h1>Link expired</h1>
            <div class="alert alert-error" role="alert" style="margin-top:16px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-top:1px"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                This reset link is invalid or has expired.
            </div>
            <a class="btn btn-primary btn-block" style="margin-top:18px" href="/forgot-password">Request a new link</a>
        <?php else: ?>
            <h1>Set a new password</h1>
            <p class="auth-card__sub">Choose something you haven't used before.</p>

            <?php if ($error !== null): ?>
                <div class="alert alert-error alert--inline" role="alert" style="margin-top:16px">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="/reset-password">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="field">
                    <label for="reset-password">New password</label>
                    <input class="input" type="password" id="reset-password" name="password" required autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="reset-password-confirm">Confirm new password</label>
                    <input class="input" type="password" id="reset-password-confirm" name="password_confirm" required autocomplete="new-password">
                </div>
                <button class="btn btn-primary btn-block" type="submit">Update password</button>
            </form>
        <?php endif; ?>
    </div>
</div>
