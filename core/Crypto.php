<?php

/**
 * Crypto
 *
 * Encrypts the small number of genuinely secret values that have to live in
 * the database — currently only the dashboard-configured SMTP password.
 *
 * WHY THIS EXISTS: Settings stores plain values, which is right for a GA
 * measurement ID or a logo path. A mail password is different: a database
 * dump alone should not hand someone working credentials. The key lives in
 * .env and never in the database, so a leak of one is not a leak of both.
 *
 * AES-256-GCM, not CBC. GCM is authenticated: a value altered in the database
 * fails its tag check and decrypt() returns null, which degrades to "no SMTP
 * configured" instead of feeding Mailer silently corrupted bytes. Unauthenticated
 * CBC would let anyone who can write to site_settings tamper undetectably.
 *
 * AI AGENTS: this is the ONLY approved way to put a secret into the database.
 * Never store a password, token or API key through Settings::set() directly.
 * Everything that is not a secret stays a plain Settings value.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;   // GCM's standard nonce length
    private const TAG_BYTES = 16;
    private const KEY_BYTES = 32;  // AES-256

    /** Cached key for this request, so a freshly generated one is usable immediately. */
    private static ?string $key = null;
    private static bool $keyResolved = false;

    /** True when a usable APP_KEY is available. */
    public static function hasKey(): bool
    {
        return self::key() !== null;
    }

    /**
     * Encrypt a value for storage. Returns base64(iv . tag . ciphertext).
     *
     * @throws RuntimeException when no APP_KEY is available — callers must
     *         check hasKey() first and surface a clear message rather than
     *         silently storing a secret in the clear.
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        if ($key === null) {
            throw new RuntimeException('No APP_KEY is configured, so secrets cannot be encrypted.');
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Reverse encrypt(). Returns null — never throws — when the key is
     * missing, the value is malformed, or the authentication tag does not
     * verify, so a corrupted row degrades to "not configured".
     */
    public static function decrypt(string $stored): ?string
    {
        $key = self::key();
        if ($key === null || $stored === '') {
            return null;
        }

        $raw = base64_decode($stored, true);
        // Exactly IV+TAG with no ciphertext is legitimate: it is an encrypted
        // empty string. Only shorter than that is malformed.
        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }

        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::IV_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? null : $plaintext;
    }

    /** A fresh random key, base64-encoded, for writing into .env. */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_BYTES));
    }

    /** The exact line to paste into .env when it cannot be written automatically. */
    public static function suggestedKeyLine(): string
    {
        return 'APP_KEY=' . self::generateKey();
    }

    /**
     * Make sure an APP_KEY exists, generating and persisting one if not.
     *
     * Needed because .env is on the auto-updater's never-touch list: no release
     * can add APP_KEY to a site that already exists, so the first site to need
     * encryption has to create its own key.
     *
     * @return bool True when a key is now available. False means .env could
     *         not be written — the caller must show suggestedKeyLine() and
     *         refuse to store the secret rather than storing it unencrypted.
     */
    public static function ensureKey(?string $envPath = null): bool
    {
        if (self::hasKey()) {
            return true;
        }

        $envPath ??= __DIR__ . '/../.env';
        if (!is_file($envPath) || !is_writable($envPath)) {
            return false;
        }

        $generated = self::generateKey();
        $contents = file_get_contents($envPath);
        if ($contents === false) {
            return false;
        }

        // Replace an existing blank APP_KEY line rather than adding a second
        // one — Config reads the first match and a duplicate would be
        // confusing to anyone opening the file.
        if (preg_match('/^APP_KEY=.*$/m', $contents)) {
            $updated = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $generated, $contents, 1);
        } else {
            $updated = rtrim($contents, "\n") . "\n\n"
                . "# Encrypts secrets stored in the database (currently the SMTP password).\n"
                . "# Generated automatically. Changing it makes existing encrypted values\n"
                . "# unreadable, which means re-entering the SMTP password.\n"
                . 'APP_KEY=' . $generated . "\n";
        }

        if ($updated === null || @file_put_contents($envPath, $updated, LOCK_EX) === false) {
            return false;
        }

        @chmod($envPath, 0600);

        // Config has already loaded and caches its values, so make the new key
        // usable for the rest of this request without a reload.
        self::$key = base64_decode($generated, true) ?: null;
        self::$keyResolved = true;

        return self::$key !== null;
    }

    /** Reset the cached key. Test harnesses only. */
    public static function resetKeyCache(): void
    {
        self::$key = null;
        self::$keyResolved = false;
    }

    /** The raw 32-byte key, or null when APP_KEY is absent or unusable. */
    private static function key(): ?string
    {
        if (self::$keyResolved) {
            return self::$key;
        }
        self::$keyResolved = true;

        $configured = (string) Config::get('APP_KEY', '');
        if ($configured === '') {
            return self::$key = null;
        }

        // Accept a "base64:" prefix so a key copied from another framework's
        // .env still works.
        if (str_starts_with($configured, 'base64:')) {
            $configured = substr($configured, 7);
        }

        $raw = base64_decode($configured, true);
        if ($raw === false || strlen($raw) !== self::KEY_BYTES) {
            return self::$key = null;
        }

        return self::$key = $raw;
    }
}
