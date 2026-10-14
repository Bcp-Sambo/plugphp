# Module Manifest & Lifecycle — implementation plan

Working plan for `MODULE-MANIFEST-LIFECYCLE-SPEC.md`, checked against the code
as it stands at **1.4.0** and against the deferred **1.5.0 fingerprinting**
work. Read `core/Updater.php` and root `SKILL.md` before starting.

**Headline:** the spec is sound in intent, but it cannot ship in its current
order. Two of its deliverables are physically undeliverable to existing sites
until the 1.5.0 fingerprinting work lands, and one of its stated prerequisites
does not exist in this repository at all.

---

## 1. The spec's assumptions, checked against the code

### 1.1 Assumptions that do not hold

| Spec says | Reality |
|---|---|
| "Read `AUTO-UPDATE-SPEC.md` and `SECURITY-SETTINGS-SPEC.md` first" | Neither file is in this repository. The real contract is `core/Updater.php`, `docs/RELEASING.md`, and the `/admin/settings` implementation. |
| §2.3 "`SECURITY-SETTINGS-SPEC.md` §4 already defines `core/SecurityLog.php` and its `security_log` table" | **Neither exists.** No file, no table, no `event_type` vocabulary anywhere in the tree. §2.3 is net-new work, not an integration. |
| §4, §5 reference an `ecommerce` module | Does not exist. The Allied scenario needs a stand-in module to test against. |
| §4 lists modules as `blog, services, projects, contact-form, auth, admin-dashboard, ecommerce` | The actual eight are `home, about, services, projects, blog, contact-form, auth, admin-dashboard`. `home` and `about` are missing from the spec's list. |

### 1.2 Assumptions that are correct — verified

- **`config/modules.php` is genuinely protected.** It is in `REFERENCE_EXCLUDE`
  (`core/Updater.php:542`) and documented as untouched at `core/Updater.php:12`.
  A module shipping in a release does **not** auto-activate. §2's "confirmed
  safe starting point" is accurate.
- **Migration timing already works the way §2.1 wants.**
  `Updater::runPendingMigrations()` (`core/Updater.php:1242`) iterates
  `config/modules.php`, so a module that shipped but was never enabled has its
  migrations skipped automatically. Activation-time migration is not a new
  mechanism, only a new entry point into an existing one.
- **Double-running is already prevented.** `migrations_log` is keyed by
  repo-relative path (`core/Updater.php:1250`), so running one module's
  migrations at activation and the general runner later is a no-op the second
  time.
- **A disabled module leaves the sidebar for free.** Both `public/index.php:48`
  and `AdminDashboardModule::adminNav()` iterate only what
  `config/modules.php` lists, so removing a name drops its routes *and* its nav
  entry with no extra code. DoD item 4 is satisfied by construction.
- **A deleted module folder does not fatal.** `public/index.php:55` skips a
  missing folder with `continue`.

### 1.3 Hard blockers the spec does not account for

**(a) `manifest.json` cannot be delivered by an update — and would break every
update if shipped.**

`Updater::isSafePath()` (`core/Updater.php:878`) allowlists exactly six
patterns: `core/*.php`, `core/migrations/*.sql`, `modules/*/*Module.php`,
`modules/*/routes.php`, `modules/*/migrations/*.sql`, `public/index.php`.
A `.json` path matches none of them. Worse, a package containing **any**
non-matching path is **rejected whole** (`core/Updater.php:339-351`), so adding
manifests to a release would not partially fail — it would make every update
fail for every site. `tools/build-release.php:68-76` would not collect them
either.

`Updater::isReferenceFile()` does not rescue this: its final test only admits
`.php`, `.css` and `.htaccess` (`core/Updater.php:569`). **A `.json` file is
currently neither installable nor deliverable by any path.**

**(b) `/admin/modules` needs a new view, and updates never install views.**

Views reach a site only as staged *reference* copies, and only when
hand-added to `requiredViews()` — today an 8-entry list
(`core/Updater.php:730`). Shipping this feature as code alone means every
existing site gets working routes pointing at a view file that is not there.
This is exactly the silent-missing-feature failure the handoff notes record as
having happened four times.

**(c) §3's per-module versions would be silently discarded.**

`Updater::fetchManifest()` (`core/Updater.php:126`) builds its return value
from a fixed whitelist of five keys. A `modules` key in `version.json` is
parsed and then thrown away. It needs explicit extraction and validation.
Note also the feed fetch is capped at 16 KB (`httpGet(self::MANIFEST_URL,
16384)`) — ample for a dozen modules, but it is a real ceiling.

**(d) Nothing stops an admin disabling their own way back in.**

The spec's Disable action has no exclusion list. Disabling `auth` or
`admin-dashboard` removes the login and dashboard routes, leaving no UI path to
re-enable anything — recoverable only by hand-editing `config/modules.php`,
which is the precise situation this feature exists to abolish. `install.php`
already guards this with `$ALWAYS_ON = ['home', 'admin-dashboard', 'auth']`
(`public/install.php:27`); `/admin/modules` must honour the same set.

---

## 2. Relationship to the 1.5.0 fingerprinting plan

The fingerprinting work is **not optional groundwork for this spec — it is a
hard dependency.** Blockers (a) and (b) above are both "an update cannot
deliver this kind of file", which is the exact problem fingerprinting exists to
solve.

| Fingerprinting deliverable | What this spec needs from it |
|---|---|
| Per-release checksum manifest of every deliverable file | Lets manifests and new views be tracked rather than forgotten |
| Site stores the manifest it received | Basis for "you are missing the Module Management view" |
| Classify untouched-behind / customised-changed / current | Offers the new view for one-click apply |
| **Extend apply beyond views to every reference file** | **The blocker fix.** `/admin/modules`'s view and every `manifest.json` reach existing sites through this |
| `public/upgrade.php` one-time catch-up | Brings Perth Partner and Index Managers onto the track before they can use any of this |

**One change to the agreed fingerprinting scope:** `isReferenceFile()`'s
extension test must gain `.json`, and `REFERENCE_EXCLUDE` must not swallow
`modules/*/manifest.json`. Without that, manifests stay undeliverable even
after 1.5.0 ships.

**Ordering: 1.5.0 fingerprinting first, this spec as 1.6.0.** Attempting them
in the spec's own order produces a release that is rejected by every installed
site.

---

## 3. Phased implementation

### Phase A — unblock delivery (lands in 1.5.0)

1. Complete the fingerprinting work as already agreed (checksum manifest,
   stored received manifest, three-way classification, apply beyond views).
2. Widen `SAFE_PATTERNS` with `#^modules/[a-z0-9-]+/manifest\.json$#`.
   **Narrow by design** — this admits one exact filename per module, not
   `.json` generally, so the allowlist keeps its guarantee.
3. Add the same path to `tools/build-release.php`'s candidate glob.
4. Add `.json` to `isReferenceFile()`'s extension test and confirm
   `REFERENCE_EXCLUDE` does not match module manifests.
5. Regression test: build a release containing a manifest and confirm it is
   **accepted**, not rejected whole.

**Gate:** do not start Phase B until a package containing a `manifest.json`
applies cleanly to a test site.

### Phase B — `core/SecurityLog.php` (net-new, §2.3)

Built from scratch; nothing to integrate with.

6. `core/migrations/00X_create_security_log.sql` — `id`, `event_type`,
   `user_id`, `context` (JSON/TEXT), `created_at`. Additive only, per §3.1.
7. `core/SecurityLog.php` with `log(string $eventType, array $context = [])`,
   resolving the acting admin from `Auth::user()`. Both paths are deliverable
   under existing `SAFE_PATTERNS` — no widening needed.
8. Event vocabulary: `module_enabled`, `module_disabled`, `module_removed`.
9. A read path for the activity log. **Decision needed:** the spec says "the
   Security tab's activity log", which does not exist — `/admin/settings` has
   Branding, Email (SMTP) and Tracking tabs. Either add a fourth tab or put the
   log on `/admin/modules`. A fourth settings tab means another undeliverable
   view, so it must also ride Phase A.

### Phase C — manifests (§1)

10. Write `manifest.json` for all eight existing modules, matching what each
    already does. Schema per §1.
11. `install.php`'s picker (`public/install.php:518`) reads each manifest and
    renders declared capabilities beside the checkbox.
12. Copy discipline: never "sandboxed" or "isolated". Declared intent only, per
    §1's own framing.
13. Root `SKILL.md`: require `manifest.json` for every new module.

### Phase D — `/admin/modules` (§2.1)

14. Route `GET /admin/modules`, `Auth::requireRole(Auth::ROLE_ADMIN)`, matching
    the existing pattern at `modules/admin-dashboard/routes.php:66`.
15. View `modules/admin-dashboard/views/modules.php` — **must** be added to
    `requiredViews()` with a marker and a plain-English effect string.
16. Listing: `glob('modules/*', GLOB_ONLYDIR)` for everything physically
    present, cross-referenced against `config/modules.php` for active state.
17. `POST /admin/modules/enable` — CSRF, admin-only, then atomically: append to
    `config/modules.php`, run **that module's** migrations immediately, log
    `module_enabled`.
18. New public `Updater::runModuleMigrations(string $module): int`, factored out
    of the existing private `runPendingMigrations()` rather than duplicating it.
19. `POST /admin/modules/disable` — remove from `config/modules.php`, log
    `module_disabled`. Files and tables untouched.
20. **Refuse to disable `home`, `admin-dashboard`, `auth`** (blocker (d)).
    Reuse `install.php`'s `$ALWAYS_ON` as a single shared constant rather than a
    second copy of the list.
21. Validate every submitted module name against `^[a-z0-9-]+$` **and**
    membership of the globbed set. Never trust the POST value as a path.

### Phase E — `ignored_modules` and permanent removal (§2.2)

22. `site_settings` key `ignored_modules`, JSON array, via existing `Settings`.
23. `Updater`'s apply step skips any `modules/<name>/...` path whose name is in
    the list.
24. `POST /admin/modules/remove` — recursive folder delete, add to
    `ignored_modules`, log `module_removed`.
25. **The single most dangerous action in the dashboard.** Before deleting:
    validate the name pattern, `realpath()` the target, assert it is a direct
    child of `modules/`, refuse symlinks, and refuse any always-on module.
    Confirmation copy must state plainly that this differs from Disable.

### Phase F — per-module versions (§3)

26. `tools/build-release.php` emits a `modules` map into `version.json`.
27. Extend `fetchManifest()` to extract and validate `modules` — each entry
    version-shaped, changelog a string or null, unknown keys dropped. Keep the
    whitelist discipline; do not pass the decoded array through wholesale.
28. `/admin/updates` renders per-module lines. That view is already in
    `requiredViews()` (`modules/admin-dashboard/views/updates.php`), so this one
    is deliverable today.
29. Single unified release in v1 — visibility only, per §3.

### Phase G — documentation and the Allied test

30. Root and per-module `SKILL.md` updates (§4).
31. `docs/building-a-module.md`: manifests and the activation lifecycle.
32. The §5 scenario end to end: build a site, ship a module it has never seen,
    confirm it does **not** auto-activate, enable it via `/admin/modules`,
    confirm migrations run at that moment and not before. Needs a throwaway
    module standing in for `ecommerce`.

---

## 4. Decisions needed before starting

1. **Stand-in for `ecommerce`** — which module tests the Allied scenario?
2. **Where the activity log is read** — fourth `/admin/settings` tab, or on
   `/admin/modules`?
3. **Does `about` get a manifest?** It is static, owns no tables, has no admin
   panel. A manifest of all-empty declarations is arguably noise — but an
   exception weakens "every module ships one".
4. **Does Disable hide a module's public pages too?** A separate
   `{module}_visible` setting already exists (`Settings::isModuleVisible()`) and
   does something adjacent but different. Two overlapping concepts in one
   dashboard will confuse; decide whether Disable supersedes visibility, or
   whether the two are explicitly distinct and labelled as such.

## 5. Definition of Done

The spec's §5 checklist stands, plus:

- [ ] A release package containing `manifest.json` files is **accepted** by an
      existing site, not rejected whole.
- [ ] `/admin/modules`'s view reaches an existing site through the update
      mechanism — not only through a fresh install.
- [ ] `home`, `admin-dashboard` and `auth` cannot be disabled through the UI.
- [ ] "Remove permanently" refuses a crafted module name (traversal, symlink,
      always-on) before deleting anything.
