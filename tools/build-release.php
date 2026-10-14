<?php
/**
 * tools/build-release.php — maintainer-side release packager.
 *
 * Builds an update package containing ONLY the paths an update is allowed to
 * touch, computes its SHA-256, and writes the version.json the installed sites
 * poll. The same allowlist that Updater enforces on the installer side is
 * enforced here, so a mistake is caught while cutting the release rather than
 * by every site that tries to install it.
 *
 * Usage:
 *   php tools/build-release.php 1.1.0 [--security] [--out=dist]
 *
 * Output (in --out, default "dist/"):
 *   plugphp-update-<version>.zip
 *   version.json          <- publish this to the release-feed branch
 *
 * Publishing, in order:
 *   1. Attach the zip to the GitHub release for v<version>.
 *   2. THEN commit version.json to the release-feed branch.
 * Never the other way round: version.json announces the release to every
 * installed site, and a site that fetches it before the asset exists gets a
 * download failure.
 */

require_once __DIR__ . '/../core/Updater.php';

$argvv = $argv ?? [];
array_shift($argvv);

$version = null;
$security = false;
$wide = false;
$outDir = __DIR__ . '/../dist';

foreach ($argvv as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, 6);
    } elseif ($arg === '--security') {
        $security = true;
    } elseif ($arg === '--wide') {
        $wide = true;
    } elseif ($version === null) {
        $version = $arg;
    }
}

if ($version === null || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fwrite(STDERR, "Usage: php tools/build-release.php <x.y.z> [--security] [--out=dir]\n");
    exit(1);
}

$root = realpath(__DIR__ . '/..');

/* ------------------------------------------------------------------ *
 * The version in core/Updater.php must match the release being cut.
 * Sites compare the manifest against that constant, so a mismatch ships
 * a package that every site thinks it still needs.
 * ------------------------------------------------------------------ */
if (Updater::VERSION !== $version) {
    fwrite(STDERR, "REFUSING: core/Updater.php declares VERSION '" . Updater::VERSION
        . "' but you are building '{$version}'.\nBump the constant first.\n");
    exit(1);
}

/* ------------------------------------------------------------------ *
 * Collect candidate files, then filter through the SAME allowlist the
 * installer enforces.
 * ------------------------------------------------------------------ */
/* ------------------------------------------------------------------ *
 * Reference manifest.
 *
 * Checksums every file an update delivers as a reference copy, so an
 * installed site can tell three things apart that otherwise look identical:
 * a file it never touched that is simply behind, a file its owner customised,
 * and a file that is already current.
 *
 * Written as core/ReferenceManifest.php rather than a .json at the package
 * root because stagePackage() refuses a package containing any path outside
 * the updatable set, using the rules the RECEIVING site already runs. A .json
 * would be rejected by every site built before this feature existed. core/*.php
 * is long-standing, so this file installs everywhere and is simply ignored by
 * versions that do not know to read it.
 *
 * Generated on every build and left in the tree — commit it with the release.
 * ------------------------------------------------------------------ */
$refFiles = [];
foreach (Updater::referenceCandidates() as $rel) {
    $h = Updater::fingerprint("$root/$rel");
    if ($h !== null) {
        $refFiles[$rel] = $h;
    }
}
ksort($refFiles);

$manifestPhp = "<?php\n\n"
    . "/**\n"
    . " * GENERATED FILE — do not edit by hand.\n"
    . " *\n"
    . " * Written by tools/build-release.php. Lists the checksum of every file\n"
    . " * this release delivers as a reference copy. Updater::referenceStatus()\n"
    . " * reads it to tell a customised file from an out-of-date one.\n"
    . " */\n\n"
    . "return [\n"
    . "    'version' => " . var_export($version, true) . ",\n"
    . "    'files' => [\n";
foreach ($refFiles as $rel => $h) {
    $manifestPhp .= "        " . var_export($rel, true) . " => " . var_export($h, true) . ",\n";
}
$manifestPhp .= "    ],\n];\n";

if (file_put_contents("$root/core/ReferenceManifest.php", $manifestPhp) === false) {
    fwrite(STDERR, "Could not write core/ReferenceManifest.php\n");
    exit(1);
}
printf("Reference manifest: %d file(s) fingerprinted.\n", count($refFiles));

$candidates = [];
foreach ((array) glob("$root/core/*.php") as $f)            { $candidates[] = 'core/' . basename($f); }
foreach ((array) glob("$root/core/migrations/*.sql") as $f) { $candidates[] = 'core/migrations/' . basename($f); }
$candidates[] = 'public/index.php';

foreach ((array) glob("$root/modules/*", GLOB_ONLYDIR) as $dir) {
    $m = basename($dir);
    foreach ((array) glob("$dir/*Module.php") as $f)           { $candidates[] = "modules/$m/" . basename($f); }
    if (is_file("$dir/routes.php"))                            { $candidates[] = "modules/$m/routes.php"; }
    foreach ((array) glob("$dir/migrations/*.sql") as $f)      { $candidates[] = "modules/$m/migrations/" . basename($f); }
}

sort($candidates);

$include = [];
$refused = [];
foreach ($candidates as $rel) {
    if (!is_file("$root/$rel")) { continue; }
    if (Updater::isSafePath($rel)) { $include[] = $rel; } else { $refused[] = $rel; }
}

if ($refused) {
    fwrite(STDERR, "REFUSING: these paths are not in the updatable set:\n  " . implode("\n  ", $refused) . "\n");
    exit(1);
}
if (!$include) {
    fwrite(STDERR, "REFUSING: nothing to package.\n");
    exit(1);
}

/* ------------------------------------------------------------------ *
 * Build.
 * ------------------------------------------------------------------ */
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "Could not create output directory: $outDir\n");
    exit(1);
}
$outDir = realpath($outDir);
$zipPath = "$outDir/plugphp-update-$version.zip";

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create $zipPath\n");
    exit(1);
}
foreach ($include as $rel) {
    $zip->addFile("$root/$rel", $rel);
}

/* ------------------------------------------------------------------ *
 * Reference views.
 *
 * Carried under a separate prefix so the installer can deliver them to
 * storage/ without ever writing them into the project tree. The site owner
 * applies them from the dashboard, one at a time, or ignores them.
 *
 * This is what stops "copy these files by hand" from meaning "go and find
 * them yourself" — the earlier packages contained none of the views they told
 * people to copy.
 * ------------------------------------------------------------------ */
$stageList = $wide ? Updater::referenceCandidates() : array_keys(Updater::requiredViews());

$views = [];
foreach ($stageList as $rel) {
    if (!is_file("$root/$rel")) {
        continue;
    }
    if (!Updater::isPackagedView('reference-views/' . $rel)) {
        fwrite(STDERR, "REFUSING: $rel is not an allowed reference file.\n");
        exit(1);
    }
    $zip->addFile("$root/$rel", 'reference-views/' . $rel);
    $views[] = $rel;
}
if (!$zip->close()) {
    fwrite(STDERR, "Could not finalise the zip.\n");
    exit(1);
}

$sha = hash_file('sha256', $zipPath);
$repo = 'Bcp-Sambo/plugphp';

$manifest = [
    'version'           => $version,
    'security_advisory' => $security,
    'changelog_url'     => "https://github.com/$repo/releases/tag/v$version",
    'download_url'      => "https://github.com/$repo/releases/download/v$version/plugphp-update-$version.zip",
    'sha256'            => $sha,
];

file_put_contents("$outDir/version.json",
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf("Built %s\n", basename($zipPath));
printf("  files   %d code, %d reference file(s)\n", count($include), count($views));
if ($wide) {
    printf("  NOTE    --wide: this package stages the full reference set.\n");
    printf("          Sites older than 1.5.0 REJECT such a package whole.\n");
    printf("          Only safe once every installed site runs 1.5.0 or newer.\n");
}
printf("  size    %s\n", number_format(filesize($zipPath) / 1024, 1) . ' KB');
printf("  sha256  %s\n", $sha);
printf("  manifest %s\n", "$outDir/version.json");
if ($security) {
    printf("  SECURITY ADVISORY flag is SET — sites will show the urgent banner.\n");
}
printf("\nPublish in this order:\n");
printf("  1. gh release create v%s %s --title 'v%s'\n", $version, $zipPath, $version);
printf("  2. commit dist/version.json to the release-feed branch\n");
