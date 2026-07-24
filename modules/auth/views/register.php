<?php
/**
 * modules/auth/views/register.php — only reachable when public registration
 * is enabled (see AuthModule).
 * @var string|null          $error
 * @var array<string,string> $old
 */
$error = $error ?? null;
$old = $old ?? [];
$pageTitle = 'Create an account';
$bareLayout = true;
$brandName = Config::get('APP_NAME', 'PlugPHP');
$mark = '<img class="brand__mark" src="/assets/img/logo.png" alt="" width="34" height="34">';
?>
<div class="auth">
    <a class="auth__brand" href="/"><?= $mark ?><span><?= e($brandName) ?></span></a>
    <div class="auth-card">
        <div style="display:flex;align-items:center;gap:8px">
            <h1>Create account</h1>
            <span class="pill">Optional</span>
        </div>
        <p class="auth-card__sub">Self-registration is off by default.</p>

        <?php if ($error !== null): ?>
            <div class="alert alert-error alert--inline" role="alert" style="margin-top:16px">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/register">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <div class="field">
                <label for="register-name">Name</label>
                <input class="input" type="text" id="register-name" name="name" value="<?= e($old['name'] ?? '') ?>" autocomplete="name">
            </div>
            <div class="field">
                <label for="register-email">Email</label>
                <input class="input" type="email" id="register-email" name="email" required value="<?= e($old['email'] ?? '') ?>" autocomplete="username">
            </div>
            <div class="field">
                <label for="register-password">Password</label>
                <input class="input" type="password" id="register-password" name="password" required autocomplete="new-password">
            </div>
            <button class="btn btn-primary btn-block" type="submit">Create account</button>
        </form>

        <p class="auth-card__foot">Have an account? <a class="textlink" href="/login">Log in</a></p>
    </div>
</div>
