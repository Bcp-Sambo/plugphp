<?php

/**
 * test-bulk-apply.php — Updater::applyAllMissing(), against a throwaway copy
 * of this project.
 *
 *     php tools/test-bulk-apply.php
 *     php tools/test-bulk-apply.php --keep
 *
 * WHY A COPY OF THE TREE
 *
 * This is the one code path in the project that writes files into a live
 * site's own directories. Testing it means deleting views, staging
 * replacements and watching what lands — which is not something to do to a
 * working checkout. So the whole tree is copied to a temporary directory and
 * the copy is what gets mangled; `Updater::root()` resolves relative to its
 * own file, so the copy's updater operates entirely on the copy.
 *
 * No database and no network: referenceStatus() and applyAllMissing() read
 * the manifest, config/modules.php and the filesystem, nothing else.
 *
 * What is being pinned down is narrow and specific: the action restores files
 * that are ABSENT, and does not touch anything else — not a customised file,
 * not an out-of-date one, not a file that reappeared after the page was
 * rendered. Those are exactly the cases where a bulk button would quietly
 * overwrite somebody's work.
 *
 * NOT covered, deliberately: the is_file() recheck inside applyAllMissing()'s
 * loop. It guards the window between that method deriving its own list and
 * writing the files — microseconds, inside one request, and not reachable
 * from out here. Removing it fails nothing in this suite. It stays in the
 * code as defence in depth for two admins pressing the button at once; it is
 * not what keeps the guarantee, and this file should not pretend to test it.
 */

declare(strict_types=1);

const STAGING_DIR = 'storage/pending-views';

$keep = in_array('--keep', $argv, true);
$root = realpath(__DIR__ . '/..');

echo "PlugPHP — bulk apply of missing files\n";

$sandbox = makeSandbox($root);
echo "sandbox: {$sandbox}\n\n";

/*
 * The scenario, built out of real files from the reference manifest.
 *
 * Three missing files so the bulk case is genuinely plural, plus one of each
 * kind the action must leave alone.
 */
$missing = [
    'modules/blog/views/index.php',
    'modules/blog/views/show.php',
    'modules/services/views/index.php',
];
$customised = 'modules/about/views/about.php';   // exists, owner edited it
$reappeared = 'modules/projects/views/index.php'; // staged as missing, then put back

$failures = 0;

// Every file in play gets a staged copy waiting in storage/, as a real update
// would have delivered.
foreach (array_merge($missing, [$customised, $reappeared]) as $rel) {
    stage($sandbox, $rel);
}

// The three missing ones: delete the live copy.
foreach ($missing as $rel) {
    unlink($sandbox . '/' . $rel);
}

// The customised one: leave it in place with content of its own, so a
// wrongly-scoped bulk apply would be visible as lost work.
$ownWork = "<?php /* the owner's own version — must survive */ ?>\n";
file_put_contents($sandbox . '/' . $customised, $ownWork);

// The reappeared one: staged, and present with different content — a file
// that WAS missing when the admin loaded the page, and was put back by
// another tab or a manual upload before the button was pressed.
//
// It is protected, but not by the skip counter inside the apply loop:
// applyAllMissing() re-derives the missing list itself, so by the time it
// runs this file is no longer classified missing and never enters the batch
// at all. That makes the skip counter defence in depth for the much smaller
// window inside a single request, and this case asserts the protection that
// actually does the work — the list is never carried over from a stale page.
$raceContent = "<?php /* arrived after the page was rendered */ ?>\n";
file_put_contents($sandbox . '/' . $reappeared, $raceContent);

$before = snapshot($sandbox, array_merge($missing, [$customised, $reappeared]));

$result = runInSandbox($sandbox);

if ($result === null) {
    echo "FAIL: the sandbox run produced no parseable result.\n";
    exit(1);
}

echo "applyAllMissing() reported:\n";
printf("  applied=%d  skipped=%d  failed=%d  success=%s\n",
    (int) $result['applied'], (int) $result['skipped'],
    count((array) $result['failed']), $result['success'] ? 'true' : 'false');
echo '  message: ' . trim((string) $result['message']) . "\n\n";

$after = snapshot($sandbox, array_merge($missing, [$customised, $reappeared]));

/* ---------------------------------------------------------------- *
 * Assertions
 * ---------------------------------------------------------------- */

foreach ($missing as $rel) {
    $failures += check(
        'restored: ' . $rel,
        $after[$rel] !== null && $after[$rel] === staged($sandbox, $rel),
        $after[$rel] === null ? 'still absent' : 'content does not match the staged copy'
    );
}

$failures += check(
    'reported applying exactly ' . count($missing) . ' file(s)',
    (int) $result['applied'] === count($missing),
    'got ' . (int) $result['applied']
);

$failures += check(
    "left the owner's customised file alone",
    $after[$customised] === $ownWork,
    'the customised file was modified — THIS WOULD BE LOST WORK'
);

$failures += check(
    'did not touch the file that reappeared after the page was rendered',
    $after[$reappeared] === $raceContent,
    'content was OVERWRITTEN — the missing list was trusted from a stale page'
);

$failures += check(
    'and left it out of the batch entirely rather than applying it',
    (int) $result['applied'] === count($missing),
    'applied=' . (int) $result['applied'] . ', expected ' . count($missing)
);

// THE ASSERTION THAT PINS THE SCOPE.
//
// With the batch correctly limited to `missing`, the existing files — the
// customised one and the reappeared one — are never considered, so nothing
// needs skipping and skipped is 0. Widen the scope to every staged file and
// they DO get considered, the loop's is_file() check catches them, and
// skipped becomes 2. That makes this the assertion that fails when the
// scoping goes, instead of the effects quietly surviving on the strength of
// a secondary check.
$failures += check(
    'considered only missing files, so nothing needed skipping',
    (int) $result['skipped'] === 0,
    'skipped=' . (int) $result['skipped'] . ' — files that already exist are entering the '
        . 'batch and being caught by the in-loop recheck instead of being out of scope'
);

$failures += check(
    'wrote no .replaced- backups (a missing file has nothing to back up)',
    glob($sandbox . '/modules/*/views/*.replaced-*') === [],
    'found: ' . implode(', ', array_map('basename', (array) glob($sandbox . '/modules/*/views/*.replaced-*')))
);

$failures += check(
    'touched nothing outside the files in play',
    unchangedElsewhere($sandbox, $root, array_merge($missing, [$customised, $reappeared])),
    'a file outside the scenario changed'
);

// A second run must find nothing left to do, and must say so rather than
// reporting a cheerful zero.
$second = runInSandbox($sandbox);
$failures += check(
    'a second run finds nothing to apply and reports that plainly',
    $second !== null
        && (int) $second['applied'] === 0
        && $second['success'] === false
        && str_contains((string) $second['message'], 'Nothing to apply'),
    'got: ' . trim((string) ($second['message'] ?? 'no result'))
);

if (!$keep) {
    exec('rm -rf ' . escapeshellarg($sandbox));
} else {
    echo "\n--keep: sandbox left at {$sandbox}\n";
}

echo "\n" . str_repeat('=', 64) . "\n";

if ($failures === 0) {
    echo "PASS — missing files restored, everything else untouched.\n";
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

/**
 * A copy of the tree with everything the updater reads and nothing it does
 * not. vendor/ and .git/ are skipped because they are large and irrelevant
 * here; storage/ is skipped so the sandbox starts with no staged files of its
 * own.
 */
function makeSandbox(string $root): string
{
    $dir = sys_get_temp_dir() . '/plugphp-bulk-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    foreach (['core', 'modules', 'resources', 'public', 'config'] as $sub) {
        exec(sprintf('cp -a %s %s', escapeshellarg($root . '/' . $sub), escapeshellarg($dir . '/')));
    }
    mkdir($dir . '/storage', 0755, true);

    return $dir;
}

/** Put a copy of the live file into the staging directory, as an update would. */
function stage(string $sandbox, string $rel): void
{
    $target = $sandbox . '/' . STAGING_DIR . '/' . $rel;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0755, true);
    }

    // Marked, so a restored file can be told apart from one that was simply
    // never deleted.
    $source = $sandbox . '/' . $rel;
    $body   = is_file($source) ? (string) file_get_contents($source) : "<?php // delivered\n";
    file_put_contents($target, "<?php /* staged copy */ ?>\n" . $body);
}

function staged(string $sandbox, string $rel): ?string
{
    $path = $sandbox . '/' . STAGING_DIR . '/' . $rel;

    return is_file($path) ? (string) file_get_contents($path) : null;
}

/** @return array<string, ?string> */
function snapshot(string $sandbox, array $files): array
{
    $out = [];
    foreach ($files as $rel) {
        $path = $sandbox . '/' . $rel;
        $out[$rel] = is_file($path) ? (string) file_get_contents($path) : null;
    }

    return $out;
}

/**
 * Run applyAllMissing() inside the sandbox, in its own process.
 *
 * The sandbox's own core/Updater.php is loaded, so root() resolves to the
 * sandbox and nothing it writes can reach this checkout.
 */
function runInSandbox(string $sandbox): ?array
{
    $script = $sandbox . '/run-apply.php';
    file_put_contents($script, <<<'PHP'
<?php
require __DIR__ . '/core/Updater.php';
echo json_encode(Updater::applyAllMissing());
PHP);

    $output = [];
    exec(sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($script)), $output);
    unlink($script);

    $decoded = json_decode(trim(implode('', $output)), true);

    if (!is_array($decoded)) {
        echo "  sandbox said: " . trim(implode("\n", $output)) . "\n";
        return null;
    }

    return $decoded;
}

/**
 * Compare every reference file in the sandbox against this checkout, ignoring
 * the ones the scenario deliberately changed. Catches a bulk apply that
 * reaches further than it claims to.
 */
function unchangedElsewhere(string $sandbox, string $root, array $expectedChanges): bool
{
    $manifest = require $root . '/core/ReferenceManifest.php';
    $ok = true;

    foreach (array_keys((array) $manifest['files']) as $rel) {
        if (in_array($rel, $expectedChanges, true)) {
            continue;
        }

        $a = $sandbox . '/' . $rel;
        $b = $root . '/' . $rel;

        // Files the sandbox never received (it copies only some top-level
        // directories) are not interesting either way.
        if (!is_file($b) || !is_file($a)) {
            continue;
        }

        if (sha1_file($a) !== sha1_file($b)) {
            echo '       changed unexpectedly: ' . $rel . "\n";
            $ok = false;
        }
    }

    return $ok;
}
