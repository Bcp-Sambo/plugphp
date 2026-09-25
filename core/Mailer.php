<?php

/**
 * Mailer
 *
 * Thin wrapper so modules never touch SMTP details directly.
 * Uses PHPMailer under the hood (added via Composer — see /composer.json).
 * If Composer isn't available on a host, this class can be swapped
 * to PHP's built-in mail() as a fallback — modules never need to know.
 *
 * AI AGENTS: never construct raw email headers or call mail()/PHPMailer
 * directly in a module. Always call Mailer::send().
 */
final class Mailer
{
    /**
     * Known SMTP providers, used only to pre-fill the dashboard form.
     *
     * These change nothing about how mail is sent: every one of them is a
     * plain SMTP relay, so the existing PHPMailer-over-SMTP path handles all
     * of them with no provider-specific code. A preset is a convenience for
     * the person filling in the form, not a code path.
     *
     * 'user' is the fixed username a provider requires, or null when the
     * account's own address or key name goes there.
     */
    public const PROVIDER_PRESETS = [
        'custom'   => null,
        'resend'   => ['label' => 'Resend',   'host' => 'smtp.resend.com',   'port' => 587, 'encryption' => 'tls', 'user' => 'resend'],
        'gmail'    => ['label' => 'Gmail',    'host' => 'smtp.gmail.com',    'port' => 587, 'encryption' => 'tls', 'user' => null],
        'sendgrid' => ['label' => 'SendGrid', 'host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'tls', 'user' => 'apikey'],
        'mailgun'  => ['label' => 'Mailgun',  'host' => 'smtp.mailgun.org',  'port' => 587, 'encryption' => 'tls', 'user' => null],
    ];

    /**
     * The effective SMTP configuration: dashboard settings take priority,
     * .env is the fallback.
     *
     * The fallback matters — a fresh install must be able to send mail before
     * anyone has opened the settings page, and a site that never uses the
     * dashboard keeps working exactly as it did.
     *
     * @return array{host:string,port:int,user:string,pass:string,encryption:string,from_email:string,from_name:string}
     */
    public static function config(): array
    {
        return [
            'host'       => self::setting('smtp_host')       ?? (string) Config::get('SMTP_HOST', ''),
            'port'       => (int) (self::setting('smtp_port') ?? Config::get('SMTP_PORT', 587)),
            'user'       => self::setting('smtp_user')       ?? (string) Config::get('SMTP_USER', ''),
            'pass'       => self::password(),
            'encryption' => self::setting('smtp_encryption') ?? (string) Config::get('SMTP_ENCRYPTION', 'tls'),
            'from_email' => self::setting('smtp_from_email') ?? (string) Config::get('SMTP_FROM_EMAIL', ''),
            'from_name'  => self::setting('smtp_from_name')  ?? (string) Config::get('SMTP_FROM_NAME', 'Website'),
        ];
    }

    /** True when there is enough configuration to attempt a send. */
    public static function isConfigured(): bool
    {
        $c = self::config();

        return $c['host'] !== '' && $c['from_email'] !== '';
    }

    /**
     * A dashboard setting, or null when unset/blank/unavailable.
     *
     * Wrapped because this now reaches the database on a path that previously
     * only read .env. A mail attempt while the database is unreachable should
     * fall back to .env rather than throw out of Mailer::send().
     */
    private static function setting(string $key): ?string
    {
        try {
            $value = Settings::get($key);
        } catch (Throwable $e) {
            return null;
        }

        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * The SMTP password. The dashboard stores it encrypted; .env stores it in
     * plain text, which is why the dashboard is the better place for it.
     */
    private static function password(): string
    {
        $stored = self::setting('smtp_pass_encrypted');
        if ($stored !== null) {
            $plain = Crypto::decrypt($stored);
            // A null here means the key changed or the row was tampered with.
            // Falling through to .env is right: better a working fallback than
            // authenticating with garbage.
            if ($plain !== null) {
                return $plain;
            }
        }

        return (string) Config::get('SMTP_PASS', '');
    }
    public static function send(string $toEmail, string $subject, string $htmlBody): bool
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $cfg = self::config();

            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->Port       = $cfg['port'];
            // Some relays accept unauthenticated localhost submission; only
            // offer credentials when there are credentials to offer.
            $mail->SMTPAuth   = $cfg['user'] !== '' || $cfg['pass'] !== '';
            $mail->Username   = $cfg['user'];
            $mail->Password   = $cfg['pass'];
            $mail->SMTPSecure = $cfg['encryption'];

            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->addAddress($toEmail);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('Mailer error: ' . $e->getMessage());
            return false;
        }
    }

    public static function sendPasswordReset(string $toEmail, string $rawToken): bool
    {
        // SECURITY: the host comes from APP_URL via Url::absolute(), never from
        // $_SERVER['HTTP_HOST']. The Host header is attacker-controllable, and a
        // reset link built from one would point the victim at a host the attacker
        // controls, with a valid token attached.
        $link = Url::absolute('/reset-password?token=' . urlencode($rawToken));

        $body = "<p>Click the link below to reset your password. This link expires in 30 minutes.</p>"
              . "<p><a href=\"" . htmlspecialchars($link, ENT_QUOTES) . "\">Reset your password</a></p>"
              . "<p>If you didn't request this, you can safely ignore this email.</p>";

        return self::send($toEmail, 'Reset your password', $body);
    }
}
