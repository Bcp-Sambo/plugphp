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
  `modules/*/migrations/*.sql`, `public/index.php`.
- **Cannot:** views, `resources/`, `.env`, `config/modules.php`, any
  `.htaccess`, `vendor/`.

Two consequences worth keeping in mind when planning a release:

- **A patch cannot change a view.** If a fix needs a markup change, ship the
  logic change and document the view edit in the release notes as a manual
  step. Every site has customised its own views; overwriting them would delete
  the site's design work.
- **A patch cannot update a dependency.** A PHPMailer CVE needs a full manual
  redownload of the kit, announced in the release notes. This is the cost of
  keeping the updater's write access narrow.

---

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
