<?php

/**
 * test-safe-paths.php — the update allowlist, asserted path by path.
 *
 *     php tools/test-safe-paths.php
 *
 * Updater::SAFE_PATTERNS is the entire safety guarantee of auto-update: a
 * package containing any path outside it is rejected whole. Everything that
 * matters about it is a question of what it REFUSES, and a regex is the kind
 * of code that keeps working while quietly meaning something new.
 *
 * So every pattern family gets a case that must be accepted, and every near
 * miss a case that must be refused — with the views trap first, because views
 * are the one thing an update may never install and the one thing a careless
 * widening would let through.
 *
 * Pure functions only: no database, no network, no files written. Safe to run
 * anywhere, any time.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Updater.php';

/**
 * [path, accepted?, why it matters]
 *
 * "accepted" means an update package may contain this path and install it
 * directly over the site's own file.
 */
const CASES = [
    // ---- core ----
    ['core/Database.php',                      true,  'core classes are the point of the feature'],
    ['core/migrations/002_create_throttle.sql', true,  'core migrations ship with the code that reads them'],
    ['core/Http.php',                          true,  'a newly added core class needs no allowlist change'],
    ['core/nested/Thing.php',                  false, 'core is flat — no subdirectories beyond migrations'],
    ['core/migrations/evil.php',               false, 'the migrations directory is .sql only, never PHP'],

    // ---- public ----
    ['public/index.php',                       true,  'the bootstrap is the one public file an update owns'],
    ['public/install.php',                     false, 'install.php is replaced by hand, never by an update'],
    ['public/.htaccess',                       false, 'may carry the owner custom redirects; surfaced as a diff'],
    ['public/assets/css/app.css',              false, 'styling is the developer work an update must not touch'],

    // ---- module files at the module root ----
    ['modules/blog/BlogModule.php',            true,  'the module class itself'],
    ['modules/contact-form/routes.php',        true,  'routes are infrastructure, not UI'],
    ['modules/blog/migrations/001_posts.sql',  true,  'module migrations'],
    ['modules/blog/manifest.json',             true,  'exactly manifest.json, not .json generally'],
    ['modules/blog/module.json',               false, 'one exact filename — a near miss is still a miss'],
    ['modules/blog/Helper.php',                false, 'a second class at the module root is not admitted'],
    ['modules/blog/SKILL.md',                  false, 'documentation is not shipped over a site'],

    // ---- THE VIEWS TRAP: the reason the subdirectory rule is PascalCase ----
    ['modules/blog/views/index.php',           false, 'VIEWS ARE NEVER INSTALLED BY AN UPDATE'],
    ['modules/blog/views/admin_form.php',      false, 'an underscore filename is still a view'],
    ['modules/ecommerce/views/cart.php',       false, 'the exact case the PascalCase rule exists to refuse'],
    ['resources/layout.php',                   false, 'branding and layout belong to the site owner'],
    ['resources/404.php',                      false, 'restyled per site'],

    // ---- one PascalCase subdirectory of a module ----
    ['modules/ecommerce/Gateways/PaystackGateway.php', true,  'the case this pattern was added for'],
    ['modules/ecommerce/Gateways/PaymentGateway.php',  true,  'an interface beside its implementations'],
    ['modules/ecommerce/Services/Cart.php',            true,  'any PascalCase directory, not a fixed name'],
    ['modules/ecommerce/Gateways/P.php',               true,  'a single-letter class name is still PascalCase'],

    // ---- near misses on that pattern ----
    ['modules/ecommerce/gateways/PaystackGateway.php', false, 'lowercase directory is refused — this is what keeps views/ shut'],
    ['modules/ecommerce/Gateways/paystack.php',        false, 'lowercase filename is not a class file by convention'],
    ['modules/ecommerce/Gateways/_Private.php',        false, 'must start with a capital, not punctuation'],
    ['modules/ecommerce/Gateways/Pay_stack.php',       false, 'no underscores: PascalCase only'],
    ['modules/ecommerce/Gateways/Deep/Nested.php',     false, 'ONE level of subdirectory, never two'],
    ['modules/ecommerce/Gateways/Paystack.phtml',      false, 'only .php'],
    ['modules/ecommerce/Gateways/Paystack.php.bak',    false, 'anchored at the end — no trailing extension smuggling'],
    ['modules/Ecommerce/Gateways/Paystack.php',        false, 'module directories are lowercase'],

    // ---- traversal and hidden paths, refused before the allowlist runs ----
    ['../.env',                                false, 'traversal'],
    ['modules/blog/../../.env',                false, 'traversal mid-path'],
    ['/etc/passwd',                            false, 'absolute path'],
    ['.env',                                   false, 'the site secrets'],
    ['.github/workflows/ci.yml',               false, 'a dot segment anywhere'],
    ['modules/blog/.hidden/X.php',             false, 'a hidden directory inside an allowed module'],
    ['',                                       false, 'the empty string'],
    ['config/modules.php',                     false, 'the site own enabled-module list'],
    ['vendor/phpmailer/src/PHPMailer.php',     false, 'dependencies are a manual reupload'],
];

/**
 * A path the allowlist accepts must NOT also be offered as a reference copy —
 * it is installed directly. The two sets are disjoint by construction
 * (isReferenceFile() returns false for anything isSafePath() accepts), and
 * this asserts it stays that way after the widening.
 */
const REFERENCE_CASES = [
    ['modules/ecommerce/Gateways/PaystackGateway.php', false, 'installed directly, so never delivered as a reference'],
    ['core/Database.php',                              false, 'same — a code path is not a reference path'],
    ['modules/blog/views/index.php',                   true,  'a view IS delivered as a reference copy'],
    ['resources/layout.php',                           true,  'so is the layout'],
    ['docs/core-api.md',                               false, 'excluded: documentation is not delivered'],
    ['tools/test-safe-paths.php',                      false, 'excluded: maintainer tooling never ships'],
];

$failures = 0;

echo "PlugPHP — update allowlist\n";
echo str_repeat('-', 72) . "\n";
echo "isSafePath() — may an update package contain this path?\n\n";

foreach (CASES as [$path, $expected, $why]) {
    $actual = Updater::isSafePath($path);

    if ($actual === $expected) {
        printf("  OK   %-8s %s\n", $expected ? 'accept' : 'refuse', $path === '' ? '(empty string)' : $path);
        continue;
    }

    $failures++;
    printf(
        "  FAIL %-8s %s\n       expected %s, got %s — %s\n",
        $expected ? 'accept' : 'refuse',
        $path === '' ? '(empty string)' : $path,
        $expected ? 'accept' : 'refuse',
        $actual ? 'accept' : 'refuse',
        $why
    );
}

echo "\n" . str_repeat('-', 72) . "\n";
echo "isReferenceFile() — is this delivered as a reference copy instead?\n\n";

foreach (REFERENCE_CASES as [$path, $expected, $why]) {
    $actual = Updater::isReferenceFile($path);

    if ($actual === $expected) {
        printf("  OK   %-8s %s\n", $expected ? 'deliver' : 'no', $path);
        continue;
    }

    $failures++;
    printf(
        "  FAIL %-8s %s\n       expected %s, got %s — %s\n",
        $expected ? 'deliver' : 'no',
        $path,
        $expected ? 'deliver' : 'no',
        $actual ? 'deliver' : 'no',
        $why
    );
}

/* ------------------------------------------------------------------ *
 * The package-level rule.
 *
 * stagePackage() accepts an entry if isSafePath() OR isPackagedView() says
 * so. A view therefore has exactly one legitimate place in a package — under
 * the reference prefix — and must be refused at the package root even though
 * the very same file is a legitimate reference delivery.
 * ------------------------------------------------------------------ */
echo "\n" . str_repeat('-', 72) . "\n";
echo "package entries — a view may travel ONLY under the reference prefix\n\n";

// The prefix is written out rather than read from Updater::VIEW_PACKAGE_PREFIX,
// which is private. Hardcoding it is the right call anyway: installed sites
// look for this exact string, so changing it would break delivery to every
// site running an older release — and this test failing is how that gets
// noticed.
$packageCases = [
    ['modules/ecommerce/views/cart.php',                  false, 'a view at the package root is refused'],
    ['resources/layout.php',                              false, 'so is the layout at the package root'],
    ['reference-views/modules/ecommerce/views/cart.php',  true,  'the same view under the reference prefix is carried'],
    ['reference-views/resources/layout.php',              true,  'the layout travels the same way'],
    ['reference-views/.env',                              false, 'the prefix is not a way in for anything else'],
    ['reference-views/../.env',                           false, 'nor with traversal behind it'],
    ['reference-views/config/modules.php',                false, 'nor for the site enabled-module list'],
];

foreach ($packageCases as [$path, $expected, $why]) {
    $actual = Updater::isSafePath($path) || Updater::isPackagedView($path);

    if ($actual === $expected) {
        printf("  OK   %-8s %s\n", $expected ? 'carry' : 'refuse', $path);
        continue;
    }

    $failures++;
    printf(
        "  FAIL %-8s %s\n       expected %s, got %s — %s\n",
        $expected ? 'carry' : 'refuse',
        $path,
        $expected ? 'carry' : 'refuse',
        $actual ? 'carry' : 'refuse',
        $why
    );
}

echo "\n" . str_repeat('=', 72) . "\n";

if ($failures === 0) {
    printf("PASS — %d path(s) behave exactly as the allowlist intends.\n",
        count(CASES) + count(REFERENCE_CASES) + count($packageCases));
    exit(0);
}

printf("FAIL — %d case(s) did not match. The allowlist is the whole safety\n"
    . "guarantee of auto-update; do not cut a release until this passes.\n", $failures);
exit(1);
