<?php

/**
 * test-stock-locking.php — proves Database::transaction() + FOR UPDATE
 * actually serialises two simultaneous buyers competing for one unit.
 *
 * Run it from the project root, against a DEVELOPMENT database:
 *
 *     php tools/test-stock-locking.php                  # contract + both races
 *     php tools/test-stock-locking.php --hold-ms=800     # a slower, wider race
 *     php tools/test-stock-locking.php --mode=contract   # just the wrapper's promises
 *     php tools/test-stock-locking.php --mode=race       # just the two races
 *
 * WHY THIS TEST EXISTS AT ALL
 *
 * The ecommerce spec's oversell protection is a `SELECT ... FOR UPDATE` read
 * followed by a stock check. Outside a transaction that lock is released the
 * moment the SELECT finishes, so both buyers read the same "1 left" and both
 * proceed. The code reads correctly, passes any single-request test, and
 * oversells only under concurrency. Nothing short of two processes colliding
 * on purpose can tell the working version from the broken one — so this runs
 * the race both ways:
 *
 *   guarded    the sequence inside Database::transaction()  → expect 1 winner
 *   unguarded  the same sequence without it                 → expect oversell
 *
 * The unguarded pass is not padding. It is the control: if it does NOT
 * oversell, the race never collided and the guarded pass proved nothing.
 *
 * Two processes, not two threads: the PDO handle in Database is a per-process
 * singleton, so a single process cannot hold two competing connections. The
 * parent spawns two children and hands them a common start timestamp to
 * collide on.
 *
 * AI AGENTS: the DDL below is the one place in this project that touches PDO
 * outside core/Database.php. It is allowed here because this is a maintainer
 * test harness that must create and drop its own scratch tables repeatedly —
 * runMigrationFile() records what it applies and would refuse to run twice.
 * The code being tested goes exclusively through the Database API. Never copy
 * this pattern into core/ or into a module.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Config.php';
require __DIR__ . '/../core/Database.php';

const PROBE_TABLE  = 'locking_probe';
const CLAIMS_TABLE = 'locking_probe_claims';

/** Long enough that the second worker is unambiguously blocked, not just slow. */
const DEFAULT_HOLD_MS = 400;

/** Head start given to both children so neither pays process boot inside the race. */
const BARRIER_LEAD_SECONDS = 0.45;

$args = parseArgs($argv);
Config::load(__DIR__ . '/../.env');

exit(isset($args['worker']) ? runWorker($args) : runParent($args));


/* ============================================================== *
 * The worker: one buyer, trying to take the last unit
 * ============================================================== */

/**
 * One simulated checkout. Returns a JSON line on stdout and nothing else, so
 * the parent can read it back without parsing prose.
 */
function runWorker(array $args): int
{
    $worker  = (int) $args['worker'];
    $holdMs  = (int) ($args['hold-ms'] ?? DEFAULT_HOLD_MS);
    $guarded = ($args['mode'] ?? 'guarded') === 'guarded';
    $startAt = (float) ($args['start-at'] ?? microtime(true));

    // Open the connection and warm the statement cache BEFORE the barrier, so
    // the race measures lock contention and not connection setup.
    Database::fetchOne('SELECT 1 AS warm');

    waitUntil($startAt);

    $attempt = static function () use ($worker, $holdMs): array {
        // The sequence under test, matching ECOMMERCE-CORE-SPEC §4.2: lock the
        // row, decide from what the lock returned, then write.
        $lockStarted = microtime(true);
        $row = Database::fetchOne(
            'SELECT stock FROM ' . PROBE_TABLE . ' WHERE id = :id FOR UPDATE',
            ['id' => 1]
        );
        $lockWaitMs = (microtime(true) - $lockStarted) * 1000;

        $stock = (int) ($row['stock'] ?? 0);

        // Stand-in for the real work §4.2 does while holding the lock
        // (re-pricing the cart, inserting holds). Without a pause here the
        // two workers can interleave by luck instead of by locking, which
        // would make both outcomes unreadable.
        usleep($holdMs * 1000);

        if ($stock < 1) {
            return ['outcome' => 'sold-out', 'saw_stock' => $stock, 'lock_wait_ms' => $lockWaitMs];
        }

        Database::insert(CLAIMS_TABLE, [
            'worker'     => $worker,
            'claimed_at' => date('Y-m-d H:i:s'),
        ]);
        Database::update(PROBE_TABLE, ['stock' => $stock - 1], 'id', 1);

        return ['outcome' => 'won', 'saw_stock' => $stock, 'lock_wait_ms' => $lockWaitMs];
    };

    $started = microtime(true);

    try {
        // The ONLY difference between the two modes. Everything inside
        // $attempt is identical, which is what makes the comparison mean
        // something.
        $result = $guarded ? Database::transaction($attempt) : $attempt();
    } catch (Throwable $e) {
        $result = ['outcome' => 'error', 'error' => $e->getMessage()];
    }

    $result['worker']     = $worker;
    $result['total_ms']   = (microtime(true) - $started) * 1000;
    $result['lock_wait_ms'] = round((float) ($result['lock_wait_ms'] ?? 0), 1);
    $result['total_ms']     = round($result['total_ms'], 1);

    echo json_encode($result), "\n";

    return 0;
}

/** Spin down to the shared start time. usleep for the bulk, busy-wait the last millisecond. */
function waitUntil(float $startAt): void
{
    $remaining = $startAt - microtime(true);
    if ($remaining > 0.002) {
        usleep((int) (($remaining - 0.001) * 1_000_000));
    }
    while (microtime(true) < $startAt) {
        // Final approach, to keep the two starts within a millisecond.
    }
}


/* ============================================================== *
 * The parent: sets the stage, runs both modes, judges the result
 * ============================================================== */

function runParent(array $args): int
{
    $holdMs = (int) ($args['hold-ms'] ?? DEFAULT_HOLD_MS);
    $modes  = match ($args['mode'] ?? 'all') {
        'guarded'   => ['guarded'],
        'unguarded' => ['unguarded'],
        'contract'  => ['contract'],
        'race'      => ['guarded', 'unguarded'],
        default     => ['contract', 'guarded', 'unguarded'],
    };

    line('PlugPHP — stock locking test');
    line('database: ' . Config::get('DB_NAME') . ' on ' . Config::get('DB_HOST')
        . '   hold: ' . $holdMs . 'ms');

    if (Config::get('APP_ENV', 'production') === 'production') {
        line('');
        line('REFUSING TO RUN: APP_ENV is "production". This test writes and drops');
        line('tables. Point .env at a development database first.');
        return 1;
    }

    createProbeTables();

    $failures = [];

    foreach ($modes as $mode) {
        if ($mode === 'contract') {
            line('');
            line(str_repeat('-', 62));
            line('CONTRACT — what Database::transaction() promises on its own');
            line(str_repeat('-', 62));

            $contract = checkContract();
            foreach ($contract['notes'] as $note) {
                line('  ' . $note);
            }
            if (!$contract['pass']) {
                $failures[] = 'contract';
            }
            continue;
        }

        resetProbe();

        line('');
        line(str_repeat('-', 62));
        line($mode === 'guarded'
            ? 'GUARDED — the sequence inside Database::transaction()'
            : 'UNGUARDED — the same sequence with no transaction (the control)');
        line(str_repeat('-', 62));

        $results = raceTwoWorkers($mode, $holdMs);
        $state   = probeState();

        foreach ($results as $r) {
            if (($r['outcome'] ?? '') === 'error') {
                line(sprintf('  worker %d: ERROR %s', $r['worker'] ?? 0, $r['error'] ?? ''));
                continue;
            }
            line(sprintf(
                '  worker %d: %-9s saw stock=%d   waited %6.1fms for the lock',
                $r['worker'] ?? 0,
                $r['outcome'] ?? '?',
                $r['saw_stock'] ?? 0,
                $r['lock_wait_ms'] ?? 0
            ));
        }

        line(sprintf('  final: stock=%d, claims=%d', $state['stock'], $state['claims']));
        line('');

        $verdict = $mode === 'guarded'
            ? judgeGuarded($results, $state, $holdMs)
            : judgeUnguarded($results, $state);

        foreach ($verdict['notes'] as $note) {
            line('  ' . $note);
        }
        if (!$verdict['pass']) {
            $failures[] = $mode;
        }
    }

    if (!isset($args['keep'])) {
        dropProbeTables();
    } else {
        line('');
        line('--keep: probe tables left in place (' . PROBE_TABLE . ', ' . CLAIMS_TABLE . ').');
    }

    line('');
    line(str_repeat('=', 62));
    if ($failures === []) {
        line(in_array('guarded', $modes, true) && in_array('unguarded', $modes, true)
            ? 'PASS — locking holds, and the control proves the race was real.'
            : 'PASS (' . $modes[0] . ' only) — run without --mode for the control that '
              . 'proves the race actually collided.');
        return 0;
    }
    line('FAIL — ' . implode(' and ', $failures) . '. Details above.');
    return 1;
}

/** Start both children on a shared clock and collect their JSON lines. */
function raceTwoWorkers(string $mode, int $holdMs): array
{
    $startAt = microtime(true) + BARRIER_LEAD_SECONDS;
    $procs   = [];

    foreach ([1, 2] as $worker) {
        $cmd = sprintf(
            '%s %s --worker=%d --mode=%s --hold-ms=%d --start-at=%.6f',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__FILE__),
            $worker,
            escapeshellarg($mode),
            $holdMs,
            $startAt
        );

        $pipes = [];
        $proc  = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            line('  could not spawn worker ' . $worker);
            continue;
        }
        $procs[$worker] = ['proc' => $proc, 'pipes' => $pipes];
    }

    $results = [];

    foreach ($procs as $worker => $p) {
        $stdout = stream_get_contents($p['pipes'][1]);
        $stderr = stream_get_contents($p['pipes'][2]);
        fclose($p['pipes'][1]);
        fclose($p['pipes'][2]);
        proc_close($p['proc']);

        $decoded = json_decode(trim((string) $stdout), true);
        if (!is_array($decoded)) {
            $results[] = [
                'worker'  => $worker,
                'outcome' => 'error',
                'error'   => trim($stderr . ' ' . $stdout) ?: 'no output',
            ];
            continue;
        }
        $results[] = $decoded;
    }

    usort($results, fn($a, $b) => ($a['worker'] ?? 0) <=> ($b['worker'] ?? 0));

    return $results;
}

/**
 * Guarded mode must produce exactly one winner, and the loser must show it
 * WAITED. The wait is the part that proves locking rather than luck: without
 * a held lock the second worker returns from its SELECT immediately.
 */
function judgeGuarded(array $results, array $state, int $holdMs): array
{
    $notes    = [];
    $pass     = true;
    $won      = array_filter($results, fn($r) => ($r['outcome'] ?? '') === 'won');
    $soldOut  = array_filter($results, fn($r) => ($r['outcome'] ?? '') === 'sold-out');
    $errors   = array_filter($results, fn($r) => ($r['outcome'] ?? '') === 'error');

    if ($errors !== []) {
        $notes[] = 'FAIL: a worker errored — see above.';
        return ['pass' => false, 'notes' => $notes];
    }

    if (count($won) === 1 && count($soldOut) === 1) {
        $notes[] = 'OK: exactly one buyer won, the other was told sold-out.';
    } else {
        $pass = false;
        $notes[] = sprintf(
            'FAIL: %d winner(s) and %d sold-out — expected one of each.',
            count($won),
            count($soldOut)
        );
    }

    if ($state['claims'] === 1 && $state['stock'] === 0) {
        $notes[] = 'OK: one claim row, stock landed on 0. No oversell.';
    } else {
        $pass = false;
        $notes[] = sprintf(
            'FAIL: %d claim row(s) and stock=%d — expected 1 and 0. THIS IS AN OVERSELL.',
            $state['claims'],
            $state['stock']
        );
    }

    $loser = array_values($soldOut)[0] ?? null;
    if ($loser !== null) {
        $waited    = (float) ($loser['lock_wait_ms'] ?? 0);
        $threshold = $holdMs * 0.5;
        if ($waited >= $threshold) {
            $notes[] = sprintf(
                'OK: the loser blocked %.1fms on FOR UPDATE (the winner held the lock %dms).',
                $waited,
                $holdMs
            );
            $notes[] = '    That wait is the proof — it is what an unprotected read never does.';
        } else {
            $pass = false;
            $notes[] = sprintf(
                'FAIL: the loser waited only %.1fms, under the %.0fms floor. One buyer won, but '
                . 'by timing rather than by locking — raise --hold-ms and look again.',
                $waited,
                $threshold
            );
        }
    }

    return ['pass' => $pass, 'notes' => $notes];
}

/**
 * The control. Unguarded is EXPECTED to oversell — that is what shows the
 * race is real and this harness can see a failure when one happens. A clean
 * unguarded run means the two workers never actually collided, which makes
 * the guarded pass meaningless rather than reassuring.
 */
function judgeUnguarded(array $results, array $state): array
{
    $won = array_filter($results, fn($r) => ($r['outcome'] ?? '') === 'won');

    if ($state['claims'] > 1 || $state['stock'] < 0) {
        return ['pass' => true, 'notes' => [
            sprintf(
                'OK (expected failure reproduced): %d buyers won the same unit, and the '
                . 'counter ended on stock=%d.',
                count($won),
                $state['stock']
            ),
            '    FOR UPDATE on autocommit released the lock at the end of the SELECT,',
            '    so both reads saw "1 left". This is the bug the spec would have shipped',
            '    had Database::transaction() been assumed to exist. The guarded run',
            '    differs by exactly one wrapper.',
            '',
            '    Note what the stock number does here: both workers wrote the ABSOLUTE',
            '    value they computed from a stale read (1 - 1 = 0), so the counter lands',
            '    on a perfectly plausible 0 while two claims exist. Nothing in the data',
            '    flags the oversell afterwards — only the duplicate claim rows do. A',
            '    relative "stock = stock - 1" would at least leave -1 as evidence.',
            '    Checkout should therefore not rely on the counter looking sane.',
        ]];
    }

    return ['pass' => false, 'notes' => [
        sprintf(
            'INCONCLUSIVE: no oversell without a transaction (claims=%d, stock=%d).',
            $state['claims'],
            $state['stock']
        ),
        '    The two workers did not collide, so the guarded result proves nothing.',
        '    Raise --hold-ms and run again before trusting either outcome.',
    ]];
}


/* ============================================================== *
 * Contract checks — single process, no race
 * ============================================================== */

/**
 * The race proves the lock holds. These prove the wrapper around it behaves:
 * a throw must undo the work and surface unchanged, a return value must come
 * back, nesting must be refused rather than silently joined, and the
 * connection must still be usable after a rollback.
 */
function checkContract(): array
{
    $notes = [];
    $pass  = true;

    $ok = static function (string $text) use (&$notes): void {
        $notes[] = 'OK: ' . $text;
    };
    $fail = static function (string $text) use (&$notes, &$pass): void {
        $notes[] = 'FAIL: ' . $text;
        $pass = false;
    };

    createProbeTables();
    resetProbe();

    // 1. The return value comes back out.
    $returned = Database::transaction(fn() => 'passed-through');
    $returned === 'passed-through'
        ? $ok("the callable's return value comes back to the caller.")
        : $fail('the return value was lost (got ' . var_export($returned, true) . ').');

    // 2. A commit persists. Stated because the rollback test below is
    //    meaningless if writes never land in the first place.
    Database::transaction(function () {
        Database::insert(CLAIMS_TABLE, ['worker' => 91, 'claimed_at' => date('Y-m-d H:i:s')]);
    });
    claimCount(91) === 1
        ? $ok('a committed write is still there afterwards.')
        : $fail('a committed write did not persist.');

    // 3. A throw rolls the work back AND propagates unchanged.
    $caught = null;
    try {
        Database::transaction(function () {
            Database::insert(CLAIMS_TABLE, ['worker' => 92, 'claimed_at' => date('Y-m-d H:i:s')]);
            throw new DomainException('deliberate failure inside the transaction');
        });
    } catch (Throwable $e) {
        $caught = $e;
    }

    if ($caught instanceof DomainException
        && $caught->getMessage() === 'deliberate failure inside the transaction') {
        $ok('the original exception propagates — not wrapped, not swallowed.');
    } else {
        $fail('expected the DomainException to propagate as thrown, got '
            . ($caught === null ? 'nothing' : get_class($caught) . ': ' . $caught->getMessage()));
    }

    claimCount(92) === 0
        ? $ok('the write made before the throw was rolled back.')
        : $fail('the write survived a rollback — the transaction did not undo it.');

    // 4. The connection is still usable after a rollback. A left-open
    //    transaction would poison every later query in the request.
    try {
        Database::transaction(function () {
            Database::insert(CLAIMS_TABLE, ['worker' => 93, 'claimed_at' => date('Y-m-d H:i:s')]);
        });
        claimCount(93) === 1
            ? $ok('the connection still works after a rolled-back transaction.')
            : $fail('a write after a rollback did not land.');
    } catch (Throwable $e) {
        $fail('the connection was left unusable after a rollback: ' . $e->getMessage());
    }

    // 5. Nesting is refused loudly rather than silently joining the outer
    //    transaction. Deliberate: see the docblock on transaction().
    $nested = null;
    try {
        Database::transaction(function () {
            Database::transaction(fn() => 'inner');
        });
    } catch (Throwable $e) {
        $nested = $e;
    }

    if ($nested instanceof RuntimeException && str_contains($nested->getMessage(), 'does not nest')) {
        $ok('a nested call is refused with a clear message.');
    } else {
        $fail('expected a RuntimeException about nesting, got '
            . ($nested === null ? 'nothing — it nested silently' : get_class($nested) . ': ' . $nested->getMessage()));
    }

    // 6. And the refusal did not leave the outer transaction dangling.
    try {
        Database::transaction(fn() => true);
        $ok('a transaction can still be opened after the nesting refusal.');
    } catch (Throwable $e) {
        $fail('the nesting refusal left a transaction open: ' . $e->getMessage());
    }

    harnessPdo()->exec('DELETE FROM ' . CLAIMS_TABLE);

    return ['pass' => $pass, 'notes' => $notes];
}

function claimCount(int $worker): int
{
    $row = Database::fetchOne(
        'SELECT COUNT(*) AS c FROM ' . CLAIMS_TABLE . ' WHERE worker = :w',
        ['w' => $worker]
    );

    return (int) ($row['c'] ?? 0);
}


/* ============================================================== *
 * Probe schema — harness-only, see the AI AGENTS note at the top
 * ============================================================== */

function harnessPdo(): PDO
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

function createProbeTables(): void
{
    // InnoDB explicitly: FOR UPDATE is a no-op on engines without row locks,
    // and a MyISAM default would make this whole test lie.
    harnessPdo()->exec(
        'CREATE TABLE IF NOT EXISTS ' . PROBE_TABLE . ' ('
        . ' id INT UNSIGNED NOT NULL PRIMARY KEY,'
        . ' stock INT NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    harnessPdo()->exec(
        'CREATE TABLE IF NOT EXISTS ' . CLAIMS_TABLE . ' ('
        . ' id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        . ' worker INT NOT NULL,'
        . ' claimed_at DATETIME NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/** One unit in stock, no claims. The whole scenario in two statements. */
function resetProbe(): void
{
    harnessPdo()->exec('DELETE FROM ' . CLAIMS_TABLE);
    harnessPdo()->exec(
        'INSERT INTO ' . PROBE_TABLE . ' (id, stock) VALUES (1, 1)'
        . ' ON DUPLICATE KEY UPDATE stock = 1'
    );
}

function probeState(): array
{
    $stock  = Database::fetchOne('SELECT stock FROM ' . PROBE_TABLE . ' WHERE id = 1');
    $claims = Database::fetchOne('SELECT COUNT(*) AS c FROM ' . CLAIMS_TABLE);

    return [
        'stock'  => (int) ($stock['stock'] ?? 0),
        'claims' => (int) ($claims['c'] ?? 0),
    ];
}

function dropProbeTables(): void
{
    harnessPdo()->exec('DROP TABLE IF EXISTS ' . CLAIMS_TABLE);
    harnessPdo()->exec('DROP TABLE IF EXISTS ' . PROBE_TABLE);
}


/* ============================================================== *
 * Small helpers
 * ============================================================== */

/** --key=value and --flag, with no dependency on getopt()'s quirks. */
function parseArgs(array $argv): array
{
    $out = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $out[$k] = $v;
            continue;
        }
        $out[$arg] = true;
    }

    return $out;
}

function line(string $text): void
{
    echo $text, "\n";
}
