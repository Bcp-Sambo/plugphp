<?php

/**
 * Auth
 *
 * Session, password, and CSRF handling for the whole project.
 * Modules must call these methods rather than touching $_SESSION
 * or password_hash()/password_verify() directly.
 *
 * AI AGENTS: never write your own login/session/token logic in a
 * module. Always call Auth::* below. Never store a raw password
 * reset token in the database — only its hash (see requestPasswordReset).
 */
final class Auth
{
    /** The two roles. Deliberately two — add a third only when one is actually needed. */
    public const ROLE_ADMIN  = 'admin';   // full access: Settings, Updates, users, all content
    public const ROLE_EDITOR = 'editor';  // content modules and Messages only
    public const ROLES = [self::ROLE_ADMIN => 'Administrator', self::ROLE_EDITOR => 'Editor'];

    private const SESSION_USER_KEY = 'auth_user_id';
    private const RESET_TOKEN_TTL_MINUTES = 30;
    private const RESET_RATE_LIMIT_MINUTES = 5;

    /** Per-request cache of the current user row. */
    private static ?array $cachedUser = null;

    public static function bootSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => Config::isProduction(),
            ]);
            session_start();
        }
    }

    // ---------- Registration / login ----------

    public static function register(string $email, string $password, array $extra = []): string
    {
        $existing = Database::fetchOne(
            'SELECT id FROM users WHERE email = :email',
            ['email' => $email]
        );
        if ($existing !== null) {
            throw new RuntimeException('An account with this email already exists.');
        }

        $data = array_merge($extra, [
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return Database::insert('users', $data);
    }

    public static function attemptLogin(string $email, string $password): bool
    {
        $user = Database::fetchOne(
            'SELECT id, password_hash FROM users WHERE email = :email',
            ['email' => $email]
        );

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            // Same generic failure path whether email is wrong or password is wrong —
            // never reveal which one, that's a user-enumeration leak.
            return false;
        }

        self::bootSession();
        session_regenerate_id(true); // prevent session fixation on privilege change
        $_SESSION[self::SESSION_USER_KEY] = $user['id'];

        return true;
    }

    public static function logout(): void
    {
        self::bootSession();
        $_SESSION = [];
        session_regenerate_id(true);
        self::$cachedUser = null;
    }

    public static function userId(): ?string
    {
        self::bootSession();
        return $_SESSION[self::SESSION_USER_KEY] ?? null;
    }

    public static function check(): bool
    {
        return self::userId() !== null;
    }

    /**
     * The logged-in user's row, or null. Memoised for the request.
     *
     * Reads the database rather than the session on purpose: a role change or
     * a deletion must take effect on the user's very next request, not
     * whenever they happen to log in again.
     */
    public static function user(): ?array
    {
        $id = self::userId();
        if ($id === null) {
            return null;
        }
        if (self::$cachedUser !== null && (string) self::$cachedUser['id'] === (string) $id) {
            return self::$cachedUser;
        }

        $row = Database::fetchOne(
            'SELECT id, name, email, role, created_at FROM users WHERE id = :id',
            ['id' => $id]
        );

        return self::$cachedUser = $row;
    }

    /** The current user's role, or null when not logged in / no longer exists. */
    public static function role(): ?string
    {
        $user = self::user();

        return $user === null ? null : (string) $user['role'];
    }

    public static function isAdmin(): bool
    {
        return self::role() === self::ROLE_ADMIN;
    }

    public static function requireLogin(): void
    {
        // A session id alone is not enough. If the account has since been
        // deleted, the session would otherwise keep working until it expired,
        // leaving a removed user with live access.
        if (!self::check() || self::user() === null) {
            if (self::check()) {
                self::logout();
            }
            http_response_code(302);
            header('Location: ' . Url::to('/login'));
            exit;
        }
    }

    /**
     * Require a specific role. Call AFTER requireLogin(), never instead of it.
     *
     * Same discipline as requireCsrf(): refuse with a status and a plain
     * message rather than redirecting somewhere that hides what happened.
     *
     * AI AGENTS: every Settings, Updates and user-management route must call
     * this. In particular, every user-management action needs
     * requireRole(Auth::ROLE_ADMIN) in the handler itself — without it an
     * Editor could POST to the role-change endpoint and make themselves an
     * administrator.
     */
    public static function requireRole(string $role): void
    {
        if (self::role() !== $role) {
            http_response_code(403);
            exit('You do not have permission to do that. Ask an administrator if you need access.');
        }
    }

    // ---------- CSRF ----------

    public static function csrfToken(): string
    {
        self::bootSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $submittedToken): bool
    {
        self::bootSession();
        return is_string($submittedToken)
            && isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $submittedToken);
    }

    /** Call at the top of every state-changing (POST/PUT/DELETE) handler. */
    public static function requireCsrf(?string $submittedToken): void
    {
        if (!self::verifyCsrf($submittedToken)) {
            http_response_code(419);
            exit('Invalid or expired form submission. Please refresh and try again.');
        }
    }

    // ---------- Password reset (hashed token, never store the raw token) ----------

    public static function requestPasswordReset(string $email): ?string
    {
        $user = Database::fetchOne('SELECT id FROM users WHERE email = :email', ['email' => $email]);
        if ($user === null) {
            // Return silently — do not reveal whether the email exists.
            return null;
        }

        $recent = Database::fetchOne(
            'SELECT id FROM password_resets WHERE user_id = :uid AND created_at > :cutoff',
            [
                'uid'    => $user['id'],
                'cutoff' => date('Y-m-d H:i:s', time() - self::RESET_RATE_LIMIT_MINUTES * 60),
            ]
        );
        if ($recent !== null) {
            // Already requested recently — rate limit, don't spam mail or DB.
            return null;
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        Database::insert('password_resets', [
            'user_id'    => $user['id'],
            'token_hash' => $tokenHash,
            'expires_at' => date('Y-m-d H:i:s', time() + self::RESET_TOKEN_TTL_MINUTES * 60),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $rawToken; // caller emails this; it is never persisted in raw form
    }

    public static function verifyResetToken(string $rawToken): ?string
    {
        $tokenHash = hash('sha256', $rawToken);

        $reset = Database::fetchOne(
            'SELECT id, user_id FROM password_resets
             WHERE token_hash = :hash AND expires_at > :now AND used_at IS NULL',
            ['hash' => $tokenHash, 'now' => date('Y-m-d H:i:s')]
        );

        return $reset['user_id'] ?? null;
    }

    public static function completePasswordReset(string $rawToken, string $newPassword): bool
    {
        $tokenHash = hash('sha256', $rawToken);

        $reset = Database::fetchOne(
            'SELECT id, user_id FROM password_resets
             WHERE token_hash = :hash AND expires_at > :now AND used_at IS NULL',
            ['hash' => $tokenHash, 'now' => date('Y-m-d H:i:s')]
        );

        if ($reset === null) {
            return false;
        }

        Database::update('users', [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ], 'id', $reset['user_id']);

        Database::update('password_resets', [
            'used_at' => date('Y-m-d H:i:s'),
        ], 'id', $reset['id']);

        return true;
    }
}
