<?php

/**
 * AuthModule
 *
 * The UI layer on top of core/Auth.php — login, logout, password-reset
 * request/confirm forms, and an admin user list. It contains ZERO password
 * hashing, session manipulation, or token generation of its own; every
 * handler delegates to Auth::* (see modules/auth/SKILL.md).
 */
final class AuthModule extends Module
{
    /**
     * Public self-registration is OFF by default: on a business site the
     * first admin is created by the installer and further users are managed
     * from the admin. Flip to true to expose GET/POST /register.
     */
    private const ALLOW_PUBLIC_REGISTRATION = false;

    /** Minimum length enforced on new passwords (core does not impose one). */
    private const MIN_PASSWORD_LENGTH = 8;

    public function name(): string
    {
        return 'auth';
    }

    public function label(): string
    {
        return 'Authentication';
    }

    public function routes(Router $router): void
    {
        require __DIR__ . '/routes.php';
    }

    public function migrations(): array
    {
        return [
            __DIR__ . '/migrations/001_create_users.sql',
            __DIR__ . '/migrations/002_create_password_resets.sql',
            __DIR__ . '/migrations/003_add_user_role.sql',
        ];
    }

    public function dashboardNavItem(): ?array
    {
        return ['label' => 'Users', 'url' => '/admin/users'];
    }

    public static function publicRegistrationEnabled(): bool
    {
        return self::ALLOW_PUBLIC_REGISTRATION;
    }

    // ---------- Login / logout ----------

    public function showLogin(?string $error = null): void
    {
        View::render(__DIR__ . '/views/login.php', ['error' => $error]);
    }

    public function handleLogin(): void
    {
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (Auth::attemptLogin($email, $password)) {
            header('Location: ' . Url::to('/admin'));
            exit;
        }

        // Generic message only — never reveal whether the email exists.
        $this->showLogin('Invalid email or password.');
    }

    public function handleLogout(): void
    {
        Auth::requireCsrf($_POST['csrf_token'] ?? null);
        Auth::logout();
        header('Location: ' . Url::to('/login'));
        exit;
    }

    // ---------- Registration (only wired up when enabled) ----------

    public function showRegister(?string $error = null, array $old = []): void
    {
        View::render(__DIR__ . '/views/register.php', ['error' => $error, 'old' => $old]);
    }

    public function handleRegister(): void
    {
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $email     = trim((string) ($_POST['email'] ?? ''));
        $password  = (string) ($_POST['password'] ?? '');
        $name      = trim((string) ($_POST['name'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->showRegister('Please enter a valid email address.', ['name' => $name, 'email' => $email]);
            return;
        }
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->showRegister(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.',
                ['name' => $name, 'email' => $email]
            );
            return;
        }

        try {
            Auth::register($email, $password, ['name' => $name]);
        } catch (\RuntimeException $e) {
            // Auth::register throws if the email already exists.
            $this->showRegister('Unable to register with those details.', ['name' => $name, 'email' => $email]);
            return;
        }

        Auth::attemptLogin($email, $password);
        header('Location: ' . Url::to('/admin'));
        exit;
    }

    // ---------- Password reset ----------

    public function showForgotPassword(bool $sent = false): void
    {
        View::render(__DIR__ . '/views/forgot-password.php', ['sent' => $sent]);
    }

    public function handleForgotPassword(): void
    {
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $email = trim((string) ($_POST['email'] ?? ''));

        // requestPasswordReset returns the raw token only if the email maps
        // to a user AND isn't rate-limited; null otherwise. We email on a
        // token and ALWAYS show the same generic message either way, so the
        // response never reveals whether the email exists.
        $token = $email !== '' ? Auth::requestPasswordReset($email) : null;
        if ($token !== null) {
            Mailer::sendPasswordReset($email, $token);
        }

        $this->showForgotPassword(true);
    }

    public function showResetPassword(?string $error = null): void
    {
        $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

        // Verify BEFORE showing the "set new password" form so an
        // expired/used/bogus link fails gracefully instead of showing a
        // form that can't work.
        $valid = $token !== '' && Auth::verifyResetToken($token) !== null;

        View::render(__DIR__ . '/views/reset-password.php', [
            'token' => $token,
            'valid' => $valid,
            'error' => $error,
        ]);
    }

    public function handleResetPassword(): void
    {
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $token           = (string) ($_POST['token'] ?? '');
        $password        = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->showResetPassword('Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
            return;
        }
        if ($password !== $passwordConfirm) {
            $this->showResetPassword('The two passwords do not match.');
            return;
        }

        if (!Auth::completePasswordReset($token, $password)) {
            // Token expired/used/invalid between viewing the form and submitting.
            $this->showResetPassword('This reset link is invalid or has expired. Please request a new one.');
            return;
        }

        header('Location: ' . Url::to('/login'));
        exit;
    }

    // ---------- Admin ----------

    /**
     * Every handler below begins with requireLogin() then
     * requireRole(ROLE_ADMIN), in the handler itself.
     *
     * AI AGENTS: do not move that check to a wrapper, a middleware-ish helper,
     * or the route file alone. Creating a user and changing a role are the two
     * actions that can grant administrator access — an Editor who reaches any
     * of them once can promote themselves permanently. The guard belongs where
     * it cannot be skipped by adding a new route that forgets it.
     */
    public function adminUsers(): void
    {
        self::guardAdmin();

        $users = Database::fetchAll(
            'SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC'
        );

        AdminDashboardModule::renderAdmin(
            __DIR__ . '/views/admin_users.php',
            [
                'users'      => $users,
                'flash'      => self::takeFlash(),
                'currentId'  => (string) Auth::userId(),
                'adminCount' => self::adminCount(),
                'minPassword'=> self::MIN_PASSWORD_LENGTH,
            ],
            'Users'
        );
    }

    /** POST /admin/users — create a user. */
    public function createUser(): void
    {
        self::guardAdmin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $name     = trim((string) ($_POST['name'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role     = (string) ($_POST['role'] ?? Auth::ROLE_EDITOR);

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
        }
        if (!array_key_exists($role, Auth::ROLES)) {
            $errors[] = 'Unknown role.';
        }

        if ($errors === []) {
            try {
                Auth::register($email, $password, ['name' => $name, 'role' => $role]);
            } catch (Throwable $e) {
                // register() throws a clean message for a duplicate address;
                // anything else is logged rather than shown.
                $errors[] = str_contains($e->getMessage(), 'already exists')
                    ? 'An account with that email already exists.'
                    : 'The account could not be created.';
                if (!str_contains($e->getMessage(), 'already exists')) {
                    error_log('createUser error: ' . $e->getMessage());
                }
            }
        }

        self::finishUsers($errors, 'User created: ' . $email);
    }

    /** POST /admin/users/{id}/role — change a user's role. */
    public function changeUserRole(string $id): void
    {
        self::guardAdmin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $role = (string) ($_POST['role'] ?? '');
        $user = self::findUser($id);

        if ($user === null) {
            self::finishUsers(['That user no longer exists.'], '');
        }
        if (!array_key_exists($role, Auth::ROLES)) {
            self::finishUsers(['Unknown role.'], '');
        }
        // Demoting the last administrator locks the site out of its own
        // Settings, Updates and this very page, with no way back except
        // editing the database by hand.
        if ($role !== Auth::ROLE_ADMIN && self::isLastAdmin($user)) {
            self::finishUsers([
                'That is the only administrator left. Promote someone else to '
                . 'administrator first, then change this account.',
            ], '');
        }

        Database::update('users', ['role' => $role], 'id', $user['id']);

        self::finishUsers([], $user['email'] . ' is now ' . Auth::ROLES[$role] . '.');
    }

    /** GET /admin/users/{id}/delete — confirmation step. */
    public function confirmDeleteUser(string $id): void
    {
        self::guardAdmin();

        $user = self::findUser($id);
        if ($user === null) {
            self::finishUsers(['That user no longer exists.'], '');
        }

        AdminDashboardModule::renderAdmin(
            __DIR__ . '/views/admin_user_delete.php',
            ['user' => $user, 'isSelf' => (string) $user['id'] === (string) Auth::userId()],
            'Delete user'
        );
    }

    /** POST /admin/users/{id}/delete */
    public function deleteUser(string $id): void
    {
        self::guardAdmin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $user = self::findUser($id);
        if ($user === null) {
            self::finishUsers(['That user no longer exists.'], '');
        }
        if (self::isLastAdmin($user)) {
            self::finishUsers([
                'That is the only administrator left. Deleting it would lock this '
                . 'site out of its own dashboard. Create another administrator first.',
            ], '');
        }

        $wasSelf = (string) $user['id'] === (string) Auth::userId();
        Database::delete('users', 'id', $user['id']);

        if ($wasSelf) {
            // An admin may delete their own account as long as another admin
            // remains — but the session must not outlive the row.
            Auth::logout();
            header('Location: ' . Url::to('/login'));
            exit;
        }

        self::finishUsers([], 'Deleted ' . $user['email'] . '.');
    }

    /* -------------------------------------------------------------- *
     * Helpers
     * -------------------------------------------------------------- */

    private static function guardAdmin(): void
    {
        Auth::requireLogin();          // FIRST LINE.
        Auth::requireRole(Auth::ROLE_ADMIN);
    }

    private static function findUser(string $id): ?array
    {
        if (!ctype_digit($id)) {
            return null;
        }

        return Database::fetchOne(
            'SELECT id, name, email, role, created_at FROM users WHERE id = :id',
            ['id' => $id]
        );
    }

    private static function adminCount(): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS c FROM users WHERE role = :role',
            ['role' => Auth::ROLE_ADMIN]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Is this the last remaining administrator?
     *
     * One check for both destructive paths. Deleting the last admin and
     * demoting the last admin produce exactly the same locked-out site, so
     * they share a rule rather than having two that can drift apart. It is
     * deliberately not "is this me" — an admin deleting the *other* last
     * admin locks the site out just as effectively.
     */
    private static function isLastAdmin(array $user): bool
    {
        return (string) $user['role'] === Auth::ROLE_ADMIN && self::adminCount() <= 1;
    }

    private static function finishUsers(array $errors, string $success): void
    {
        $_SESSION['pp_users_flash'] = [
            'errors'  => $errors,
            'success' => $errors === [] ? $success : '',
        ];

        http_response_code(302);
        header('Location: ' . Url::to('/admin/users'));
        exit;
    }

    private static function takeFlash(): array
    {
        $flash = $_SESSION['pp_users_flash'] ?? ['errors' => [], 'success' => ''];
        unset($_SESSION['pp_users_flash']);

        return [
            'errors'  => is_array($flash['errors'] ?? null) ? $flash['errors'] : [],
            'success' => (string) ($flash['success'] ?? ''),
        ];
    }
}
