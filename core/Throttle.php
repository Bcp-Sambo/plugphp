<?php

/**
 * Throttle — "has this address done this too many times lately?"
 *
 * One shared counter table, keyed by an action name and the caller's address.
 * Exists because the pattern every other limit in this project uses does not
 * generalise: Contact Form counts rows in `contact_submissions`, password
 * reset counts rows in `password_resets`, and both work because the thing
 * being limited IS the row. Checkout has to be limited before an order
 * exists, or the limit creates the junk orders it was added to prevent.
 *
 *     if (Throttle::isLimited('checkout', 10, 3600)) {
 *         // show "too many attempts, try again later" and stop
 *     }
 *     Throttle::hit('checkout');   // count this attempt, then do the work
 *
 * Check first, then record. Recording before the check costs the caller
 * their own first attempt.
 *
 * THIS IS NOT A SECURITY CONTROL. It reduces nuisance and accidental
 * hammering; it is not what stops a determined attacker, and nothing may be
 * left unguarded on the strength of it. Authentication, CSRF and the
 * server-side revalidation of every submitted value do that work. The
 * distinction is load-bearing, because it is why this class fails OPEN:
 *
 *   - A failed write loses one count. The limit gets slightly more lenient.
 *   - A failed read returns "not limited" and the request proceeds.
 *
 * Failing closed would be worse in every realistic case. If this table is
 * unreachable the database is unreachable, and checkout cannot complete
 * anyway — so a throttle that answered "blocked" would convert a database
 * problem into a site-wide refusal to serve anyone, while protecting nothing.
 * A missing table is the normal state of a site mid-upgrade, before core
 * migrations have run.
 *
 * AI AGENTS: never pass user input as $action. It is an application-chosen
 * label ('checkout', 'login'), stored to identify which limit a row belongs
 * to. And never add a parameter that reads an address from a request header
 * — see clientIp() below.
 */
final class Throttle
{
    private const TABLE = 'request_throttle';

    /** Matches the column; a longer label is truncated rather than rejected. */
    private const MAX_ACTION_LENGTH = 50;

    /**
     * Rows older than this are pruned by the opportunistic sweep. Longer than
     * any window a caller is likely to ask for, so a sweep never deletes a
     * row some other limit is still counting.
     */
    private const RETENTION_SECONDS = 86400;

    /** Roughly one in this many hits runs the sweep. See maybePrune(). */
    private const PRUNE_ODDS = 50;

    /**
     * True when this address has already hit $action $max times inside the
     * last $windowSeconds.
     *
     * A rolling window, not a fixed bucket: counting rows newer than a cutoff
     * cannot be gamed by waiting for a period boundary, which a
     * "N per clock hour" counter can.
     */
    public static function isLimited(string $action, int $max, int $windowSeconds): bool
    {
        if ($max < 1) {
            return true; // a cap of zero means "never allowed"; say so plainly
        }

        $ip = self::clientIp();
        if ($ip === null) {
            // No usable address, so no bucket to count. Matches Contact Form:
            // refusing to serve a visitor whose address cannot be read is a
            // worse failure than not counting them.
            return false;
        }

        try {
            $row = Database::fetchOne(
                'SELECT COUNT(*) AS c FROM ' . self::TABLE
                . ' WHERE action = :action AND ip = :ip AND created_at > :cutoff',
                [
                    'action' => self::normaliseAction($action),
                    'ip'     => $ip,
                    'cutoff' => self::timestamp(time() - max(1, $windowSeconds)),
                ]
            );
        } catch (Throwable $e) {
            // Fails open, loudly in the log. See the class note.
            error_log('Throttle read failed for ' . $action . ': ' . $e->getMessage());
            return false;
        }

        return (int) ($row['c'] ?? 0) >= $max;
    }

    /**
     * Record one attempt. Never throws.
     *
     * Deliberately separate from isLimited(): some callers count only the
     * attempts that got as far as being worth counting (a submission that
     * passed validation), and folding the two together would take that
     * choice away.
     */
    public static function hit(string $action): void
    {
        $ip = self::clientIp();
        if ($ip === null) {
            return;
        }

        try {
            Database::insert(self::TABLE, [
                'action'     => self::normaliseAction($action),
                'ip'         => $ip,
                'created_at' => self::timestamp(time()),
            ]);
        } catch (Throwable $e) {
            error_log('Throttle write failed for ' . $action . ': ' . $e->getMessage());
            return;
        }

        self::maybePrune();
    }

    /**
     * Delete counters older than $olderThanSeconds. Returns rows removed.
     *
     * Public so a caller with a natural sweep point can run it there — the
     * checkout prunes expired stock holds on the same pass, which is one
     * cleanup query per checkout instead of two.
     */
    public static function prune(int $olderThanSeconds = self::RETENTION_SECONDS): int
    {
        try {
            return Database::deleteOlderThan(
                self::TABLE,
                'created_at',
                self::timestamp(time() - max(60, $olderThanSeconds))
            );
        } catch (Throwable $e) {
            error_log('Throttle prune failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Sweep occasionally, at random, from inside hit().
     *
     * There is no cron on this hosting, so the table has to maintain itself.
     * Pruning on every hit would add a DELETE to every counted request;
     * pruning never would grow the table forever, and relying on each caller
     * to remember a sweep means the first one that forgets does exactly that.
     * One in fifty keeps it bounded no matter who is calling, and a caller
     * with a better sweep point still has prune().
     *
     * random_int() rather than rand(): not for unpredictability here, just
     * the one correct generator to reach for by habit.
     */
    private static function maybePrune(): void
    {
        try {
            if (random_int(1, self::PRUNE_ODDS) === 1) {
                self::prune();
            }
        } catch (Throwable $e) {
            // A failed sweep must never affect the request that triggered it.
            error_log('Throttle sweep failed: ' . $e->getMessage());
        }
    }

    /**
     * The address this counter is bucketed by.
     *
     * Delegates to SecurityLog, which is the single definition of "the
     * caller's address, as far as it can be trusted" — specifically that
     * proxy headers are never consulted, because on shared hosting anyone
     * can set X-Forwarded-For. A second reader that did consult it would let
     * a visitor choose their own rate-limit bucket by sending a header, while
     * the audit log went on recording their real address.
     */
    private static function clientIp(): ?string
    {
        if (!class_exists('SecurityLog')) {
            return null;
        }

        return SecurityLog::clientIp();
    }

    /** An application label, clamped to what the column holds. */
    private static function normaliseAction(string $action): string
    {
        return substr(trim($action), 0, self::MAX_ACTION_LENGTH);
    }

    /**
     * UTC, always.
     *
     * Windows are computed by comparing stored strings, so every value in the
     * table has to be on one clock. A server whose timezone is changed — or
     * one observing daylight saving — would otherwise shift an hour of
     * counters sideways, silently widening or collapsing every window that
     * spans the change.
     */
    private static function timestamp(int $unixTime): string
    {
        return gmdate('Y-m-d H:i:s', $unixTime);
    }
}
