# Releasing a PlugPHP update

For the maintainer. Installed sites poll a single feed and offer a one-click
update from `/admin/updates`; this is how that feed gets published.

---

## The two places a release lives

| What | Where | Why |
|---|---|---|
| The update package (`.zip`) | A GitHub **release asset** on `main`'s tag | Large binary, versioned, immutable |
| The feed (`version.json`) | The **`release-feed`** branch, at the repo root | What every installed site polls |

`release-feed` is a dedicated branch that contains nothing but `version.json`.

**Why not `main`.** `main` is where you work, and it gets pushed to constantly
— mid-feature, half-finished. If `version.json` lived there, every one of those
pushes would touch the file every live site polls, and bumping the version
before the release asset exists would have every site offering a download that
404s.

**Why it matters when a release goes wrong.** Withdrawing a bad release on
`release-feed` is a one-file edit on a branch that holds nothing else. On
`main` you would be committing a version rollback into a branch whose code is
still that version, leaving the repo contradicting itself — and you would be
doing it under pressure.

The feed URL is hardcoded in `core/Updater.php` and is deliberately not
configurable from `.env` or the database: that class can write to `core/`, so
the address it trusts must not be redirectable by anyone who compromises a
config file or a settings row. Changing it means shipping new code, and old
installs would keep polling the old address.

---

## Cutting a release

**1. Bump the version constant.**

```php
// core/Updater.php
public const VERSION = '1.1.0';
```

`tools/build-release.php` refuses to build if this does not match the version
you pass it. A mismatch would ship a package that every site still believes it
needs.

**2. Build.**

```bash
php tools/build-release.php 1.1.0
```

Add `--security` for a security release — it sets `security_advisory` in the
feed, which shows sites an urgent banner.

The tool packages **only** the paths an update is allowed to touch and
re-checks each one against the same allowlist `Updater` enforces on the
installer side, so a mistake is caught here rather than by every site that
tries to install it. It writes `dist/plugphp-update-1.1.0.zip` and
`dist/version.json`.

**3. Publish the asset FIRST.**

```bash
gh release create v1.1.0 dist/plugphp-update-1.1.0.zip --title "v1.1.0" --notes-file CHANGELOG-1.1.0.md
```

**4. Then publish the feed.**

```bash
git switch release-feed
cp dist/version.json version.json
git commit -am "Release 1.1.0"
git push origin release-feed
git switch main
```

Order matters. Step 4 announces the release to every installed site; a site
that fetches the feed before the asset exists gets a download failure.

`raw.githubusercontent.com` caches for roughly five minutes, so a release —
including a security advisory — is not instant.

---

## Creating the `release-feed` branch (once)

```bash
git switch --orphan release-feed
git rm -rf . >/dev/null 2>&1 || true
cp dist/version.json version.json
git add version.json
git commit -m "Initialise release feed"
git push -u origin release-feed
git switch main
```

Until this branch exists the update check simply reports that it could not
reach the update server. Nothing breaks — sites keep working, they just never
see an update offered.

---

## Withdrawing a bad release

Edit `version.json` on `release-feed` back to the previous version and push.
Sites stop being offered the bad release within the cache window. Sites that
already installed it can roll back from `/admin/updates` for seven days.

---

## What an update can and cannot change

The full boundary is in the root `SKILL.md` under "Auto-update boundaries".
The short version:

- **Can:** `core/*.php`, `core/migrations/*.sql`,
  `modules/*/[Name]Module.php`, `modules/*/routes.php`,
  `modules/*/migrations/*.sql`, `modules/*/[Pascal]/[Pascal].php`,
  `public/index.php`.
- **Cannot:** views, `resources/`, `.env`, `config/modules.php`, any
  `.htaccess`, `vendor/`.

`modules/*/[Pascal]/[Pascal].php` is the newest entry and the only one with a
shape requirement rather than a fixed name: one subdirectory level, PascalCase
on the directory and the filename. It admits
`modules/ecommerce/Gateways/PaystackGateway.php` while still refusing
`modules/ecommerce/views/cart.php`, which a generic one-level rule would have
let through.

Two consequences worth keeping in mind when planning a release:

- **A patch cannot change a view.** If a fix needs a markup change, ship the
  logic change and document the view edit in the release notes as a manual
  step. Every site has customised its own views; overwriting them would delete
  the site's design work.
- **A patch cannot update a dependency.** A PHPMailer CVE needs a full manual
  redownload of the kit, announced in the release notes. This is the cost of
  keeping the updater's write access narrow.

---

## File fingerprinting

Every build writes `core/ReferenceManifest.php` — the SHA-1 of each file an
update delivers as a reference copy rather than installing. **Commit it with
the release**; `tools/build-release.php` regenerates it each time it runs.

An installed site compares three things, not two:

| | |
|---|---|
| **your file** | what is on the server right now |
| **what you should have had** | `storage/reference-baseline.json`, written at install and before each update |
| **what is current** | the manifest shipped with the code now on disk |

That third input is the point. Without a baseline, a file that differs from the
release could equally be a customisation or a file that is simply old, and
those need opposite handling. With it:

- untouched and behind → offered for one-click apply
- customised **and** changed upstream → shown as a conflict; applying keeps
  the owner's version alongside as a `.replaced-<timestamp>` copy
- customised, upstream unchanged → nothing said; their version stands
- no baseline yet → reported honestly as "cannot tell", until the next update

### The one-release-behind rule — read this before widening a release

**A package is judged by the code the receiving site is already running, not
by the code in the package.** `Updater::stagePackage()` rejects a package
*whole* if it contains any path outside that site's updatable set.

So a change to what a release may contain has to ship one release **before**
the release that uses it:

| Release | Ships | Effect |
|---|---|---|
| 1.5.0 | the rules that accept a wider reference set | sites learn to accept it |
| 1.6.0 | the wider set itself (`--wide`) | now accepted, because every site runs ≥1.5.0 |

`tools/build-release.php --wide` stages every reference file instead of the
legacy eight. **It is not backward compatible** and the tool says so when you
use it. Only reach for it once no installed site is older than 1.5.0.

The same rule governs `SAFE_PATTERNS`. `modules/*/manifest.json` was added to
it in 1.5.0 specifically so that 1.6.0 can ship module manifests; shipping them
in the same release that added the pattern would have been refused by every
existing site.

## Migrations in a release

Ship new migrations as new files. Never edit a migration that has already been
released — `migrations_log` records it as applied, so an edited file is never
re-run, and sites would silently diverge.

Migrations are forward-only. A rollback restores code but not schema, so a new
migration must leave older code working: add columns nullable or with
defaults, and never rename or drop a column that shipped code still reads.

---

## Before you tag

- [ ] `core/Updater.php`'s `VERSION` matches the release.
- [ ] `php tools/build-release.php <version>` succeeds and its file list
      contains no view, no `resources/` path and no `.env`.
- [ ] Applied the package to a scratch install and confirmed a customised view
      and a customised `resources/layout.php` both survived.
- [ ] Rolled that scratch install back and confirmed the previous code returned.
- [ ] Release notes name any manual step the updater cannot perform for the
      owner (a view change, a dependency bump, an `.htaccess` edit).
- [ ] `php tools/test-stock-locking.php` passes against a development database
      if anything under `core/Database.php` or any stock-locking path changed.
      It must report PASS on all three sections — the unguarded control is
      expected to oversell, and a *clean* control means the race never
      collided and the guarded result proved nothing.
- [ ] `php tools/test-safe-paths.php` passes. Always — not only when
      `SAFE_PATTERNS` changed. It is the allowlist the whole feature rests on,
      it needs no database or network, and it takes a moment.
- [ ] `php tools/test-bulk-apply.php` passes if anything in the reference-file
      or apply path changed. It works on a copy of the tree in a temp
      directory and needs no database.
- [ ] `php tools/test-http.php` passes if `core/Http.php` or any gateway call
      changed. It generates its own certificate authority and needs no network
      access; the third section must FAIL to connect, which is what proves
      certificate verification is on.
