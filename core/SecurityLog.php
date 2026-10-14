<?php

/**
 * SecurityLog — the audit trail for actions that change what a site can do.
 *
 * Narrower than a general activity log on purpose. This records the handful of
 * actions capable of turning a site's functionality on or off, or of changing
 * what data it handles — not page edits, not logins, not routine content work.
 * A log that records everything gets ignored, and an ignored log is not an
 * audit trail.
 *
 * AI AGENTS: every path that enables, disables or removes a module MUST call
 * log(). That is a hard rule, not a nicety — Module Management is the one
 * dashboard area that can silently change what a site does, and an unlogged
 * change there is indistinguishable from a compromise.
 */
final class SecurityLog
{
    /** Module Management. */
    public const MODULE_ENABLED  = 'module_enabled';
    public const MODULE_DISABLED = 'module_disabled';
    public const MODULE_REMOVED  = 'module_removed';

    /**
     * Event types this version knows how to describe. An unknown type is still
     * stored and still shown — a log that silently drops what it does not
     * recognise is worse than one showing a raw string.
     */
    private const LABELS = [
        self::MODULE_ENABLED  => 'Module enabled',
        self::MODULE_DISABLED => 'Module disabled',
        self::MODULE_REMOVED  => 'Module removed permanently',
    ];

    /**
     * Record one event.
     *
     * Never throws. An audit write that fails must not take down the action it
     * is describing — but it must not fail silently either, so a failure goes
     * to the PHP error log where it can be found.
     */
    public static function log(string $eventType, array $context = []): bool
    {
        try {
            $user = class_exists('Auth') ? Auth::user() : null;

            Database::insert('security_log', [
                'event_type' => substr($eventType, 0, 50),
                'user_id'    => $user['id'] ?? null,
                'context'    => $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES),
                'ip'         => self::clientIp(),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('SecurityLog write failed (' . $eventType . '): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Most recent entries, newest first, with the acting account's name
     * resolved where it still exists.
     *
     * @return array<int, array{id:int,event_type:string,label:string,context:array,user:string,ip:string,created_at:string}>
     */
    public static function recent(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        try {
            // Interpolated because LIMIT cannot be bound as a parameter on all
            // drivers; the value is clamped to an int range immediately above.
            $rows = Database::fetchAll(
                'SELECT l.id, l.event_type, l.context, l.ip, l.created_at,
                        u.name AS user_name, u.email AS user_email
                   FROM security_log l
              LEFT JOIN users u ON u.id = l.user_id
               ORDER BY l.id DESC
                  LIMIT ' . (int) $limit
            );
        } catch (Throwable $e) {
            error_log('SecurityLog read failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $ctx = json_decode((string) ($r['context'] ?? ''), true);
            $out[] = [
                'id'         => (int) $r['id'],
                'event_type' => (string) $r['event_type'],
                'label'      => self::LABELS[$r['event_type']] ?? (string) $r['event_type'],
                'context'    => is_array($ctx) ? $ctx : [],
                'user'       => (string) ($r['user_name'] ?? $r['user_email'] ?? 'a deleted account'),
                'ip'         => (string) ($r['ip'] ?? ''),
                'created_at' => (string) $r['created_at'],
            ];
        }

        return $out;
    }

    /**
     * The caller's address, as far as it can be trusted.
     *
     * Proxy headers are deliberately NOT consulted. On shared hosting anyone
     * can set X-Forwarded-For, so reading it would let the person being
     * audited choose what the audit records.
     *
     * Public because Throttle buckets its counters by the same value. "What
     * is the caller's address, and which sources are trustworthy" is a
     * security-relevant definition, and two copies of it is one copy too
     * many: a second reader that consulted X-Forwarded-For would let a
     * visitor pick their own rate-limit bucket while the audit log kept
     * recording the real address. One definition, one answer.
     */
    public static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
    }
}
