<?php
/**
 * modules/auth/views/forgot-password.php
 * @var bool $sent
 */
$sent = $sent ?? false;
$pageTitle = 'Reset your password';
$bareLayout = true;
$brandName = Config::get('APP_NAME', 'PlugPHP');
$mark = '<img class="brand__mark" src="/assets/img/logo.png" alt="" width="34" height="34">';
?>
<div class="auth">
    <a class="auth__brand" href="/"><?= $mark ?><span><?= e($brandName) ?></span></a>
    <div class="auth-card">
        <h1>Forgot your password?</h1>
        <p class="auth-card__sub">Enter your email and we'll send a reset link.</p>

        <?php if ($sent): ?>
            <div class="alert alert-success alert--inline" role="status" style="margin-top:18px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                If that email exists, a reset link has been sent.
            </div>
        <?php else: ?>
            <form method="post" action="/forgot-password">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <div class="field">
                    <label for="forgot-email">Email</label>
                    <input class="input" type="email" id="forgot-email" name="email" required autocomplete="username">
                </div>
                <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
            </form>
        <?php endif; ?>

        <p class="auth-card__foot"><a class="textlink" href="/login">&larr; Back to log in</a></p>
    </div>
</div>
