<?php

/**
 * test-throttle.php — core/Throttle.php against a development database.
 *
 *     php tools/test-throttle.php
 *
 * Runs the `002_create_request_throttle.sql` migration if it has not been
 * applied, then exercises the counter. Its own rows are deleted before and
 * after; nothing else in the database is touched.
 *
 * REMOTE_ADDR is set per case, because that is the bucket key and CLI has no
 * address of its own. That is also what makes the "different addresses do not
 * share a bucket" case testable at all.
 *
 * The case that matters most is the last one: with the table gone, isLimited()
 * must answer "not limited" and hit() must not throw. A site mid-upgrade has
 * no table yet, and a throttle that failed closed there would convert a
 * missing table into a site that refuses every checkout.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Config.php';
require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/SecurityLog.php';
require __DIR__ . '/../core/Throttle.php';

const TABLE = 'request_throttle';

Config::load(__DIR__ . '/../.env');

/*
 * Deliberately NOT UTC.
 *
 * Throttle stores and compares timestamps with gmdate(), so that a server
 * whose timezone changes — or one observing daylight saving — cannot shift an
 * hour of counters sideways and silently widen every window spanning the
 * change. On a machine already set to UTC that discipline is untestable:
 * gmdate() and date() return the same string, and a version using local time
 * passes every assertion.
 *
 * So the suite runs several hours off UTC. The backdated rows below are
 * written in UTC on purpose, matching what the class itself writes; if
 * Throttle ever reaches for date(), the window cases fail by hours.
 */
date_default_timezone_set('America/New_York');

echo "PlugPHP — request throttle\n";
echo 'process timezone: ' . date_default_timezone_get()
    . ' (deliberately not UTC — see the note in this file)' . "\n";
echo 'database: ' . Config::get('DB_NAME') . ' on ' . Config::get('DB_HOST') . "\n\n";

if (Config::get('APP_ENV', 'production') === 'production') {
    echo "REFUSING TO RUN: APP_ENV is \"production\". This test writes and drops a table.\n";
    exit(1);
}

Database::runMigrationFile(__DIR__ . '/../core/migrations/002_create_request_throttle.sql');

$failures = 0;

/* ---------------------------------------------------------------- *
 * Counting inside a window
 * ---------------------------------------------------------------- */

reset_rows();
as_address('203.0.113.10');

$failures += check(
    'a fresh address is not limited',
    Throttle::isLimited('checkout', 3, 3600) === false
);

Throttle::hit('checkout');
Throttle::hit('checkout');

$failures += check(
    'under the cap is still not limited',
    Throttle::isLimited('checkout', 3, 3600) === false,
    'two hits against a cap of three'
);

Throttle::hit('checkout');

$failures += check(
    'at the cap it is limited',
    Throttle::isLimited('checkout', 3, 3600) === true,
    'three hits against a cap of three'
);

$failures += check(
    'a different action has its own bucket',
    Throttle::isLimited('login', 3, 3600) === false,
    'checkout is at its cap; login must be unaffected'
);

as_address('198.51.100.7');
$failures += check(
    'a different address has its own bucket',
    Throttle::isLimited('checkout', 3, 3600) === false,
    'the first address is at its cap; a second must not inherit it'
);

/* ---------------------------------------------------------------- *
 * The window is rolling
 * ---------------------------------------------------------------- */

reset_rows();
as_address('203.0.113.20');

// Backdated directly, which is the only way to age a counter without waiting.
backdate_hit('checkout', '203.0.113.20', 7200);
backdate_hit('checkout', '203.0.113.20', 7200);
backdate_hit('checkout', '203.0.113.20', 7200);

$failures += check(
    'counters outside the window do not count',
    Throttle::isLimited('checkout', 3, 3600) === false,
    'three hits, all two hours old, against a one-hour window'
);

$failures += check(
    'the same counters inside a wider window do count',
    Throttle::isLimited('checkout', 3, 10800) === true,
    'the same three hits against a three-hour window'
);

/* ---------------------------------------------------------------- *
 * A cap of zero
 * ---------------------------------------------------------------- */

$failures += check(
    'a cap of zero means never allowed, with no query at all',
    Throttle::isLimited('checkout', 0, 3600) === true
);

/* ---------------------------------------------------------------- *
 * Pruning
 * ---------------------------------------------------------------- */

reset_rows();
backdate_hit('checkout', '203.0.113.30', 90000); // older than the day-long retention
backdate_hit('checkout', '203.0.113.30', 600);   // ten minutes old

$removed = Throttle::prune();

$failures += check(
    'prune removes only what is past the retention window',
    $removed === 1 && row_count() === 1,
    'removed=' . $removed . ', rows left=' . row_count()
);

/* ---------------------------------------------------------------- *
 * No usable address
 * ---------------------------------------------------------------- */

reset_rows();
unset($_SERVER['REMOTE_ADDR']);

$failures += check(
    'an unreadable address is served rather than refused',
    Throttle::isLimited('checkout', 1, 3600) === false,
    'matches Contact Form: not counting someone beats not serving them'
);

Throttle::hit('checkout');
$failures += check(
    'and nothing is recorded for it',
    row_count() === 0,
    'rows=' . row_count() . ' — a row with no address would be a shared bucket for everyone'
);

/* ---------------------------------------------------------------- *
 * THE IMPORTANT ONE: no table at all
 *
 * The three "Throttle ... failed" lines that follow are EXPECTED. Failing
 * open is not failing silently: every swallowed error reaches the log, which
 * is the only way a site owner finds out the limiter has stopped counting.
 * ---------------------------------------------------------------- */

as_address('203.0.113.40');
$renamed = false;

try {
    harness_pdo()->exec('RENAME TABLE ' . TABLE . ' TO ' . TABLE . '_hidden');
    $renamed = true;

    $limited = Throttle::isLimited('checkout', 1, 3600);

    $threw = false;
    try {
        Throttle::hit('checkout');
    } catch (Throwable $e) {
        $threw = true;
    }

    $failures += check(
        'with the table missing, isLimited() fails OPEN',
        $limited === false,
        'a site mid-upgrade has no table yet; failing closed would refuse every checkout'
    );

    $failures += check(
        'with the table missing, hit() does not throw',
        $threw === false,
        'the counter must never take down the action it is counting'
    );

    $failures += check(
        'and prune() reports zero rather than exploding',
        Throttle::prune() === 0
    );
} finally {
    if ($renamed) {
        harness_pdo()->exec('RENAME TABLE ' . TABLE . '_hidden TO ' . TABLE);
    }
}

reset_rows();

echo "\n" . str_repeat('=', 64) . "\n";

if ($failures === 0) {
    echo "PASS — counts correctly, buckets separately, and fails open.\n";
    exit(0);
}

echo "FAIL — {$failures} assertion(s) failed.\n";
exit(1);


/* ---------------------------------------------------------------- *
 * Helpers
 * ---------------------------------------------------------------- */

function check(string $name, bool $pass, string $detail = ''): int
{
    printf("  %s %s\n", $pass ? 'OK  ' : 'FAIL', $name);
    if (!$pass && $detail !== '') {
        echo '       ' . $detail . "\n";
    }

    return $pass ? 0 : 1;
}

/** Become this address for the cases that follow. */
function as_address(string $ip): void
{
    $_SERVER['REMOTE_ADDR'] = $ip;
}

/**
 * A counter written at a chosen age.
 *
 * UTC, the same clock Throttle writes on — a local-time value here would
 * make the window assertions pass or fail by however far the server's
 * timezone sits from UTC, which is exactly the bug the class avoids.
 */
function backdate_hit(string $action, string $ip, int $secondsAgo): void
{
    Database::insert(TABLE, [
        'action'     => $action,
        'ip'         => $ip,
        'created_at' => gmdate('Y-m-d H:i:s', time() - $secondsAgo),
    ]);
}

function row_count(): int
{
    $row = Database::fetchOne('SELECT COUNT(*) AS c FROM ' . TABLE);

    return (int) ($row['c'] ?? 0);
}

function reset_rows(): void
{
    harness_pdo()->exec('DELETE FROM ' . TABLE);
}

/**
 * Direct PDO, for the two things the Database API deliberately cannot do:
 * an unqualified DELETE, and the RENAME that hides the table.
 *
 * AI AGENTS: allowed here because this is a maintainer test harness. Never
 * copy it into core/ or a module — Database is the only way to reach the
 * database in application code.
 */
function harness_pdo(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . Config::get('DB_HOST', '127.0.0.1')
                . ';dbname=' . Config::get('DB_NAME') . ';charset=utf8mb4',
            Config::get('DB_USER'),
            Config::get('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    return $pdo;
}
