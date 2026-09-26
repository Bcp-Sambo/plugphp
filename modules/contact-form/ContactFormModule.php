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
        return [
            __DIR__ . '/migrations/001_create_contact_submissions.sql',
            __DIR__ . '/migrations/002_add_message_status.sql',
        ];
    }

    /**
     * Public nav entry. Nav::publicItems() drops this automatically when
     * the module is hidden from the dashboard, so no visibility check here.
     */
    public function publicNavItem(): ?array
    {
        return [
            'label' => 'Contact',
            'url'   => '/contact',
            'primary' => true,   // rendered as the call-to-action button
        ];
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
            'errors'        => $errors,
            'old'           => $old,
            'sent'          => isset($_GET['sent']),
            'honeypotField' => self::HONEYPOT_FIELD,
        ]);
    }

    public function handleSubmit(): void
    {
        Auth::requireCsrf($_POST['csrf_token'] ?? null); // FIRST LINE — always.

        // Bot check, before anything is validated or stored.
        //
        // The rate limit alone does not stop a bot that submits slowly and
        // stays under the cap. This field is invisible to a human, so anything
        // that fills it is automated.
        //
        // The response is a normal-looking success: a bot that is told it was
        // rejected learns to adapt, while one that thinks it succeeded keeps
        // wasting its time. Nothing is written and nobody is emailed.
        if (trim((string) ($_POST[self::HONEYPOT_FIELD] ?? '')) !== '') {
            header('Location: ' . Url::to('/contact?sent=1'));
            exit;
        }

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
        header('Location: ' . Url::to('/contact?sent=1'));
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
        // CONTACT_TO is an explicit "send enquiries here" and wins when set.
        // Otherwise fall back to the site's own from-address — via
        // Mailer::config(), NOT Config::get(), so that an owner who changes
        // their address in the dashboard starts receiving notifications there.
        // Reading .env directly meant notifications kept going to the previous
        // address indefinitely, with nothing to indicate it.
        $to = Config::get('CONTACT_TO') ?: Mailer::config()['from_email'];
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

    /**
     * Name of the honeypot field.
     *
     * Deliberately plausible rather than descriptive. Some bots look for the
     * literal string "honeypot" and skip anything named that, so naming it
     * honestly would defeat it.
     */
    private const HONEYPOT_FIELD = 'website';

    /** Inbox statuses, in the order a message moves through them. */
    public const STATUSES = ['new' => 'New', 'read' => 'Read', 'replied' => 'Replied'];

    /**
     * /admin/messages — the inbox.
     *
     * Available to both roles: answering enquiries is content work, not a
     * settings-level action, so requireLogin() alone is the right guard here.
     */
    public function adminMessages(): void
    {
        Auth::requireLogin(); // FIRST LINE — every /admin/* handler.

        $messages = Database::fetchAll(
            'SELECT id, name, email, message, status, replied_at, created_at
             FROM contact_submissions
             ORDER BY created_at DESC
             LIMIT 200'
        );

        AdminDashboardModule::renderAdmin(
            __DIR__ . '/views/admin_messages.php',
            [
                'messages' => $messages,
                'flash'    => self::takeFlash(),
                'unread'   => self::countByStatus('new'),
            ],
            'Messages'
        );
    }

    /** /admin/messages/{id} — one message, with a reply box. */
    public function adminMessage(string $id): void
    {
        Auth::requireLogin();

        $message = self::findMessage($id);
        if ($message === null) {
            self::finishMessages(['That message no longer exists.'], '');
        }

        // Opening a message marks it read. Only from 'new' — a reply must not
        // be demoted back to 'read' just because someone reopened it.
        if ($message['status'] === 'new') {
            Database::update('contact_submissions', ['status' => 'read'], 'id', $message['id']);
            $message['status'] = 'read';
        }

        AdminDashboardModule::renderAdmin(
            __DIR__ . '/views/admin_message.php',
            ['message' => $message, 'flash' => self::takeFlash()],
            'Message'
        );
    }

    /** POST /admin/messages/{id}/reply */
    public function replyToMessage(string $id): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);

        $message = self::findMessage($id);
        if ($message === null) {
            self::finishMessages(['That message no longer exists.'], '');
        }

        $reply = trim((string) ($_POST['reply'] ?? ''));
        if ($reply === '') {
            self::finishMessage($id, ['Write a reply before sending.'], '');
        }
        // The address came from a public form. It was validated on the way in,
        // but re-check rather than trusting a stored value to still be sane.
        if (!filter_var((string) $message['email'], FILTER_VALIDATE_EMAIL)) {
            self::finishMessage($id, ['That message has no valid reply-to address.'], '');
        }
        if (!Mailer::isConfigured()) {
            self::finishMessage($id, [
                'No mail settings are configured, so the reply was not sent. '
                . 'An administrator can set them up under Settings.',
            ], '');
        }

        $siteName = Branding::siteName();
        $sent = Mailer::send(
            (string) $message['email'],
            'Re: your message to ' . $siteName,
            // The admin types plain text; escape it so an ampersand or an
            // angle bracket cannot break the HTML body or inject markup.
            '<p>' . nl2br(e($reply)) . '</p>'
            . '<hr><p style="color:#666;font-size:13px">You wrote:</p>'
            . '<blockquote style="color:#666;font-size:13px;border-left:3px solid #ddd;padding-left:12px">'
            . nl2br(e((string) $message['message']))
            . '</blockquote>'
        );

        if (!$sent) {
            // Status deliberately unchanged: the sender did not receive this,
            // so the inbox must not claim otherwise. The admin can retry.
            self::finishMessage($id, [
                'The reply could not be sent, so this message is still marked '
                . 'as unanswered. Check the mail settings under Settings and try '
                . 'again — the exact error was written to storage/logs/.',
            ], '');
        }

        Database::update('contact_submissions', [
            'admin_reply' => $reply,
            'replied_at'  => date('Y-m-d H:i:s'),
            'status'      => 'replied',
        ], 'id', $message['id']);

        self::finishMessage($id, [], 'Reply sent to ' . $message['email'] . '.');
    }

    /* -------------------------------------------------------------- *
     * Helpers
     * -------------------------------------------------------------- */

    private static function findMessage(string $id): ?array
    {
        if (!ctype_digit($id)) {
            return null;
        }

        return Database::fetchOne(
            'SELECT id, name, email, message, ip_address, status, admin_reply, replied_at, created_at
             FROM contact_submissions WHERE id = :id',
            ['id' => $id]
        );
    }

    private static function countByStatus(string $status): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS c FROM contact_submissions WHERE status = :s',
            ['s' => $status]
        );

        return (int) ($row['c'] ?? 0);
    }

    private static function finishMessages(array $errors, string $success): void
    {
        self::setFlash($errors, $success);
        http_response_code(302);
        header('Location: ' . Url::to('/admin/messages'));
        exit;
    }

    private static function finishMessage(string $id, array $errors, string $success): void
    {
        self::setFlash($errors, $success);
        http_response_code(302);
        header('Location: ' . Url::to('/admin/messages/' . $id));
        exit;
    }

    private static function setFlash(array $errors, string $success): void
    {
        $_SESSION['pp_messages_flash'] = [
            'errors'  => $errors,
            'success' => $errors === [] ? $success : '',
        ];
    }

    private static function takeFlash(): array
    {
        $flash = $_SESSION['pp_messages_flash'] ?? ['errors' => [], 'success' => ''];
        unset($_SESSION['pp_messages_flash']);

        return [
            'errors'  => is_array($flash['errors'] ?? null) ? $flash['errors'] : [],
            'success' => (string) ($flash['success'] ?? ''),
        ];
    }
}
