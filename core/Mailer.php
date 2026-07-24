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
    public static function send(string $toEmail, string $subject, string $htmlBody): bool
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = Config::get('SMTP_HOST');
            $mail->Port       = (int) Config::get('SMTP_PORT', 587);
            $mail->SMTPAuth   = true;
            $mail->Username   = Config::get('SMTP_USER');
            $mail->Password   = Config::get('SMTP_PASS');
            $mail->SMTPSecure = Config::get('SMTP_ENCRYPTION', 'tls');

            $mail->setFrom(
                Config::get('SMTP_FROM_EMAIL'),
                Config::get('SMTP_FROM_NAME', 'Website')
            );
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
        $baseUrl = Config::get('APP_URL');
        $link = "{$baseUrl}/reset-password?token=" . urlencode($rawToken);

        $body = "<p>Click the link below to reset your password. This link expires in 30 minutes.</p>"
              . "<p><a href=\"" . htmlspecialchars($link, ENT_QUOTES) . "\">Reset your password</a></p>"
              . "<p>If you didn't request this, you can safely ignore this email.</p>";

        return self::send($toEmail, 'Reset your password', $body);
    }
}
