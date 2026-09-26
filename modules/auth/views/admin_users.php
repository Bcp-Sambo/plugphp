<?php
/**
 * modules/auth/views/admin_users.php — user management (administrator only).
 *
 * Restyle freely. Do not remove a csrf_token field, and do not turn the
 * role-change or delete POSTs into GETs.
 *
 * @var array  $users        [['id','name','email','role','created_at'], ...]
 * @var array  $flash        ['errors' => string[], 'success' => string]
 * @var string $currentId    the logged-in admin's own id
 * @var int    $adminCount   how many administrators exist
 * @var int    $minPassword  minimum password length
 */
$users = $users ?? [];
$csrf = Auth::csrfToken();
?>

<?php if ($flash['success'] !== ''): ?>
    <div class="pp-note pp-note--ok"><?= e($flash['success']) ?></div>
<?php endif; ?>
<?php if ($flash['errors'] !== []): ?>
    <div class="pp-note pp-note--bad">
        <ul><?php foreach ($flash['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Email</th>
                <th scope="col">Role</th>
                <th scope="col">Created</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
        <?php if ($users === []): ?>
            <tr><td colspan="5"><p class="muted">No users yet.</p></td></tr>
        <?php else: foreach ($users as $u):
            $isSelf = (string) $u['id'] === (string) $currentId;
            $isLastAdmin = $u['role'] === Auth::ROLE_ADMIN && $adminCount <= 1;
        ?>
            <tr>
                <td class="td-strong"><?= e($u['name'] ?? '') ?><?= $isSelf ? ' <span class="pp-you">you</span>' : '' ?></td>
                <td class="td-email"><?= e($u['email']) ?></td>
                <td>
                    <form method="post" action="<?= url('/admin/users/' . (string) $u['id'] . '/role') ?>" class="pp-inline">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <select name="role" data-autosubmit <?= $isLastAdmin ? 'disabled' : '' ?>
                                aria-label="Role for <?= e($u['email']) ?>">
                            <?php foreach (Auth::ROLES as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $u['role'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php /* Always rendered, not wrapped in <noscript>. The script below
                           hides it once auto-submit is wired up — so the form works
                           whether or not the script runs, instead of depending on it. */ ?>
                        <button class="btn btn-secondary btn-sm" type="submit" data-hide-when-scripted<?= $isLastAdmin ? ' disabled' : '' ?>>Set</button>
                    </form>
                    <?php if ($isLastAdmin): ?>
                        <div class="pp-hint">Only administrator — promote someone else first.</div>
                    <?php endif; ?>
                </td>
                <td class="td-mono" style="font-size:12.5px"><?= e($u['created_at'] ? date('Y-m-d', strtotime((string) $u['created_at'])) : '') ?></td>
                <td class="td-actions">
                    <?php if ($isLastAdmin): ?>
                        <span class="muted">&mdash;</span>
                    <?php else: ?>
                        <a class="textlink textlink--danger" href="<?= url('/admin/users/' . (string) $u['id'] . '/delete') ?>">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<form class="pp-panel" method="post" action="<?= url('/admin/users') ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <h2>Add a user</h2>
    <p class="pp-hint">
        You set the password and pass it on. Ask them to change it from
        <em>Forgot password</em> once they have signed in.
    </p>

    <div class="pp-row">
        <div class="field">
            <label for="nu_name">Name</label>
            <input type="text" id="nu_name" name="name" maxlength="120">
        </div>
        <div class="field">
            <label for="nu_email">Email</label>
            <input type="email" id="nu_email" name="email" required maxlength="191" autocomplete="off">
        </div>
    </div>

    <div class="pp-row">
        <div class="field">
            <label for="nu_password">Temporary password</label>
            <input type="text" id="nu_password" name="password" required
                   minlength="<?= e((string) $minPassword) ?>" autocomplete="new-password">
            <p class="pp-hint">At least <?= e((string) $minPassword) ?> characters. Shown as plain text so you can copy it.</p>
        </div>
        <div class="field">
            <label for="nu_role">Role</label>
            <select id="nu_role" name="role">
                <?php foreach (Auth::ROLES as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $value === Auth::ROLE_EDITOR ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="pp-hint">Editors can manage content and messages. Administrators can also change settings, users and updates.</p>
        </div>
    </div>

    <div class="form-actions"><button class="btn btn-primary" type="submit">Create user</button></div>
</form>

<style>
.pp-note { border-radius:10px; padding:14px 18px; margin-bottom:16px; border:1px solid; }
.pp-note--ok { background:#e9f7ee; border-color:#bfe3cc; color:#12331f; }
.pp-note--bad { background:#fdeeee; border-color:#f0c9c9; color:#4a1414; }
.pp-note ul { margin:0 0 0 1.1rem; padding:0; }
.pp-panel { background:#fff; border:1px solid #e3e6ea; border-radius:10px; padding:18px 20px; margin-top:16px; }
.pp-panel h2 { font-size:1.05rem; margin:0 0 .35rem; }
.pp-panel .field { margin-bottom:14px; }
.pp-panel label { display:block; font-size:13px; font-weight:600; margin-bottom:5px; }
.pp-panel input, .pp-panel select { width:100%; padding:8px 10px; border:1px solid #d4d8de; border-radius:7px; font:inherit; font-size:14px; }
.pp-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.pp-hint { font-size:12.5px; color:#5b6270; margin:5px 0 0; }
.pp-inline { display:inline; }
.pp-inline select { padding:5px 8px; border:1px solid #d4d8de; border-radius:6px; font:inherit; font-size:13px; }
.pp-you { font-size:11px; font-weight:600; background:#eaeff7; color:#2a4b7c; padding:1px 6px; border-radius:4px; margin-left:6px; }
.td-actions { text-align:right; }
.textlink--danger { color:#a11c1c; }
.sr-only { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); }
@media (max-width:640px){ .pp-row { grid-template-columns:1fr; } }
</style>

<script nonce="<?= e(View::nonce()) ?>">
/*
 * Submit the role form as soon as the dropdown changes, and hide the Set
 * button that exists for the no-script case.
 *
 * This was an onchange="this.form.submit()" attribute, which the content
 * security policy blocks (script-src-attr — a nonce covers <script> blocks,
 * not handler attributes). With the Set button hidden inside <noscript> at
 * the same time, changing a role did nothing at all in a browser.
 */
(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-hide-when-scripted]'), function (el) {
        el.hidden = true;
    });
    Array.prototype.forEach.call(document.querySelectorAll('select[data-autosubmit]'), function (select) {
        select.addEventListener('change', function () {
            if (select.form) { select.form.submit(); }
        });
    });
})();
</script>