<?php
/**
 * modules/auth/views/login.php
 * @var string|null $error
 */
$error = $error ?? null;
$pageTitle = 'Log in';
$bareLayout = true;
$brandName = Config::get('APP_NAME', 'PlugPHP');
$mark = '<img class="brand__mark" src="/assets/img/logo.png" alt="" width="34" height="34">';
?>
<div class="auth">
    <a class="auth__brand" href="/"><?= $mark ?><span><?= e($brandName) ?></span></a>
    <div class="auth-card">
        <h1>Log in</h1>
        <p class="auth-card__sub">Access your dashboard.</p>

        <?php if ($error !== null): ?>
            <div class="alert alert-error alert--inline" role="alert" style="margin-top:18px">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/login">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <div class="field">
                <label for="login-email">Email</label>
                <input class="input" type="email" id="login-email" name="email" required autocomplete="username">
            </div>
            <div class="field">
                <div class="label-row">
                    <label for="login-password">Password</label>
                    <a class="textlink" style="font-size:12.5px" href="/forgot-password">Forgot?</a>
                </div>
                <input class="input" type="password" id="login-password" name="password" required autocomplete="current-password">
            </div>
            <button class="btn btn-primary btn-block" type="submit">Log in</button>
        </form>

        <?php if (AuthModule::publicRegistrationEnabled()): ?>
            <p class="auth-card__foot">No account? <a class="textlink" href="/register">Register</a></p>
        <?php endif; ?>
    </div>
</div>
