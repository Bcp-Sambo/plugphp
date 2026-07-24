<?php

/**
 * ContactFormModule
 *
 * Public contact form: renders the form, handles the submission (CSRF +
 * validation + per-IP rate limit), stores it, and emails the site owner
 * via Mailer::send(). Also contributes a "Messages" admin screen.
 *
 * See modules/contact-form/SKILL.md — this is a common weak spot, so the
 * rules (CSRF first, rate-limit by IP, validate email, never inject raw
 * $_POST into mail headers) are enforced here explicitly.
 */
final class ContactFormModule extends Module
{
    /** Max submissions allowed from one IP within the rolling window. */
    private const RATE_LIMIT_MAX = 5;
    /** Rolling window length, in seconds (1 hour). */
    private const RATE_LIMIT_WINDOW = 3600;

    public function name(): string
    {
        return 'contact-form';
    }

    public function label(): string
    {
        return 'Contact Form';
    }

    public function routes(Router $router): void
    {
        require __DIR__ . '/routes.php';
    }

    public function migrations(): array
    {
        return [__DIR__ . '/migrations/001_create_contact_submissions.sql'];
    }

    public function dashboardNavItem(): ?array
    {
        return ['label' => 'Messages', 'url' => '/admin/messages'];
    }

    // ---------- Public form ----------

    /**
     * @param array<string,string> $errors field => message
     * @param array<string,string> $old    previously submitted values to refill
     */
    public function showForm(array $errors = [], array $old = []): void
    {
        View::render(__DIR__ . '/views/form.php', [
            'errors' => $errors,
            'old'    => $old,
            'sent'   => isset($_GET['sent']),
        ]);
    }

    public function handleSubmit(): void
    {
        Auth::requireCsrf($_POST['csrf_token'] ?? null); // FIRST LINE — always.

        $name    = trim((string) ($_POST['name'] ?? ''));
        $email   = trim((string) ($_POST['email'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Please enter your name.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }
        if ($message === '') {
            $errors['message'] = 'Please enter a message.';
        }

        if ($errors !== []) {
            $this->showForm($errors, ['name' => $name, 'email' => $email, 'message' => $message]);
            return;
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($this->isRateLimited($ip)) {
            $this->showForm(
                ['message' => 'You have sent too many messages recently. Please try again later.'],
                ['name' => $name, 'email' => $email, 'message' => $message]
            );
            return;
        }

        Database::insert('contact_submissions', [
            'name'       => $name,
            'email'      => $email,
            'message'    => $message,
            'ip_address' => $ip,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->notifyOwner($name, $email, $message);

        // Post/Redirect/Get: prevents duplicate submissions (and duplicate
        // emails) if the user refreshes the result page.
        header('Location: /contact?sent=1');
        exit;
    }

    /** True if this IP has hit the submission cap within the rolling window. */
    private function isRateLimited(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $row = Database::fetchOne(
            'SELECT COUNT(*) AS c FROM contact_submissions
             WHERE ip_address = :ip AND created_at > :cutoff',
            [
                'ip'     => $ip,
                'cutoff' => date('Y-m-d H:i:s', time() - self::RATE_LIMIT_WINDOW),
            ]
        );

        return (int) ($row['c'] ?? 0) >= self::RATE_LIMIT_MAX;
    }

    /**
     * Email the site owner. Submitted values go into the message BODY only,
     * escaped — never into headers (no header injection), and only via
     * Mailer::send() (never raw mail()).
     */
    private function notifyOwner(string $name, string $email, string $message): void
    {
        $to = Config::get('CONTACT_TO') ?: Config::get('SMTP_FROM_EMAIL');
        if (!$to) {
            return; // No destination configured; the submission is still stored.
        }

        $body = '<p><strong>Name:</strong> ' . e($name) . '</p>'
              . '<p><strong>Email:</strong> ' . e($email) . '</p>'
              . '<p><strong>Message:</strong></p>'
              . '<p>' . nl2br(e($message)) . '</p>';

        Mailer::send($to, 'New contact form submission', $body);
    }

    // ---------- Admin ----------

    public function adminMessages(): void
    {
        Auth::requireLogin(); // FIRST LINE — every /admin/* handler.

        $messages = Database::fetchAll(
            'SELECT id, name, email, message, ip_address, created_at
             FROM contact_submissions
             ORDER BY created_at DESC
             LIMIT 200'
        );

        AdminDashboardModule::renderAdmin(
            __DIR__ . '/views/admin_messages.php',
            ['messages' => $messages],
            'Messages'
        );
    }
}
