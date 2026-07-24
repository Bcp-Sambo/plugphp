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
            header('Location: /admin');
            exit;
        }

        // Generic message only — never reveal whether the email exists.
        $this->showLogin('Invalid email or password.');
    }

    public function handleLogout(): void
    {
        Auth::requireCsrf($_POST['csrf_token'] ?? null);
        Auth::logout();
        header('Location: /login');
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
        header('Location: /admin');
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

        header('Location: /login');
        exit;
    }

    // ---------- Admin ----------

    public function adminUsers(): void
    {
        Auth::requireLogin(); // FIRST LINE.

        $users = Database::fetchAll(
            'SELECT id, name, email, created_at FROM users ORDER BY created_at DESC'
        );

        AdminDashboardModule::renderAdmin(
            __DIR__ . '/views/admin_users.php',
            ['users' => $users],
            'Users'
        );
    }
}
