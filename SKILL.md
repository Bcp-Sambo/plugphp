# SKILL.md — Read this before doing anything else

You are working inside a modular vanilla-PHP starter kit built for shared
hosting (cPanel-style). This file is the master rulebook. Each module folder
under `/modules/*` also has its own `SKILL.md` with module-specific detail —
read the relevant one before touching that module.

## What you are (and are not) allowed to touch

| Area | Can you edit it? |
|---|---|
| `/core/*` | **No.** Treat as read-only infrastructure. If something core seems to be missing a feature, say so — do not add it yourself. |
| `/modules/<name>/views/*` | Yes — this is where UI/styling work happens. |
| `/modules/<name>/routes.php` | Only to add routes for genuinely new pages within that module's concern. |
| `/modules/<name>/migrations/*.sql` | Only when adding a new column/table that module owns. |
| `/public/index.php` | **No.** Bootstrap file, not a place for feature code. |
| `.env` | Yes, to fill in real credentials. Never commit real values to git. |

## The six hard rules

1. **Never write raw SQL in a view or route file.** All database access goes
   through `Database::fetchOne()`, `fetchAll()`, `insert()`, `update()`,
   `delete()`. If the query shape you need doesn't exist, say so instead of
   reaching for `PDO`/`mysqli` directly — there is deliberately no raw-query
   method available.

2. **Never `echo` a dynamic value without wrapping it in `e()`.** Every view
   file has access to the global `e()` helper (`core/View.php`). This is the
   only approved way to print user- or database-sourced content into HTML.
   `<?= e($post['title']) ?>`, never `<?= $post['title'] ?>`.

3. **Never handle passwords, sessions, or CSRF tokens yourself.** Use
   `Auth::attemptLogin()`, `Auth::requireLogin()`, `Auth::csrfToken()`,
   `Auth::requireCsrf()`. Do not call `password_hash()`/`password_verify()`
   or touch `$_SESSION` directly outside of `core/Auth.php`.

4. **Never send email except through `Mailer::send()`.** Do not build raw
   SMTP calls, headers, or use `mail()` directly in a module.

5. **Every state-changing route (POST/PUT/DELETE) must call
   `Auth::requireCsrf($_POST['csrf_token'] ?? null)` as its first line.**
   No exceptions, including on forms that feel "low risk" like contact forms.

6. **Never write a leading-slash literal URL.** A PlugPHP site can be served
   from a main domain, a subdomain, OR a subfolder, and `href="/blog"`
   resolves against the domain root — so every link and asset 404s at once
   the moment the site is not at the root. Use the helpers in `core/Url.php`:

   | Context | Use | Escapes? |
   |---|---|---|
   | Link / form `action` in a view | `url('/blog')` | yes |
   | Asset (CSS/JS/image) in a view | `asset('/assets/css/app.css')` | yes |
   | Redirect, or any PHP logic | `Url::to('/admin')` | no (raw) |
   | Canonical, Open Graph, sitemap, email link | `Url::absolute('/blog/x')` | no (raw) |

   `url()`/`asset()` already apply `e()`, so do **not** wrap them in `e()`
   again. `Url::to()`/`Url::absolute()` return raw strings for use in
   `header('Location: ...')` and email bodies.

   This applies to values too, not just literals: a URL that arrives from the
   database or a data array still needs `url($item['url'])`, not
   `e($item['url'])`.

   **Host-header security rule:** `Url::absolute()` builds from `APP_URL` and
   never from `$_SERVER['HTTP_HOST']`. `HTTP_HOST` is attacker-controllable.
   A password-reset link built from it lets an attacker mail a victim a reset
   URL pointing at a host the attacker controls, with a valid token attached.
   Never introduce a code path that derives an emailed or canonical URL from
   the request host.
## Frontend/UI expectations

- Views ship as **minimal, unstyled semantic HTML placeholders** — this is
  intentional, not unfinished. Your job when asked to "build the UI" is to
  add styling/layout/branding on top of the existing markup structure, not
  to redesign the data flow.
- Keep headings in correct hierarchical order (one `<h1>` per page, no
  skipped levels) — this is required for both accessibility and the
  Agentic Browsing / SEO scoring the client cares about.
- Every `<img>` must have a real `alt` attribute — never leave it empty or
  omit it.
- Every form input must have an associated `<label for="...">`.
- Set explicit `width`/`height` (or aspect-ratio CSS) on images to avoid
  layout shift (CLS) — this is scored directly by Lighthouse.

## SEO defaults already built in — don't fight them

Content modules (blog, services, projects) already populate `<title>`,
meta description, canonical URL, Open Graph tags, and JSON-LD structured
data automatically from their database fields. Do not remove or duplicate
these tags manually in a view — if a page needs different SEO fields,
add the field to that module's migration and its edit form in the
admin dashboard, not as a one-off hardcoded tag.

## 404 handling — already solved, don't rebuild it

`resources/404.php` is rendered through the normal `View::render()`
pipeline (see `core/Router.php`), so it automatically inherits
`resources/layout.php` — the site's real nav, styling, and branding —
instead of showing a bare, unstyled error page. Restyle the *content* of
`resources/404.php` freely for a given site. Keep the small attribution
line at the bottom unless the client has explicitly asked to remove it.

Attribution ("Built with PlugPHP — by Kabiru Sambo / Bubble Bot
Solutions") appears in exactly three places by default: the admin
dashboard footer, the About page, and the 404 page. It is **not** placed
in the global `layout.php` footer — don't add it there, and don't remove
it from the three places above without being asked.

## Per-module public visibility toggle

Any content module (blog, services, projects, etc.) can be hidden from
the public site without being uninstalled — useful for "not ready to
publish yet" or a seasonal pause. This is a runtime toggle, distinct
from the install-time module picker (which decides whether the module
exists at all).

- Controlled via `Settings::isModuleVisible('blog')` (see
  `core/Settings.php`), backed by a shared `site_settings` table
  (`core/migrations/000_create_site_settings.sql`) — not a per-module
  settings table.
- **Enforce this inside the module's own `routes()` method**, by simply
  not registering the public GET routes when the toggle is off. Do not
  register the route and then check visibility inside the handler —
  if the route is registered, the router's own 404 fallback (above)
  naturally handles the "hidden" case for free, with no special branch
  needed.
- The module's **admin/dashboard routes always stay registered**
  regardless of this toggle — a hidden module should still be fully
  editable from the dashboard, just not visible publicly.
- Expose the toggle itself as a simple on/off control inside that
  module's own dashboard settings screen — not a separate global
  "site settings" module.
- **Unregistering the routes is only half the job.** The module's public
  links must disappear too, or the site keeps advertising a page that now
  404s. That is handled for you: contribute the link through
  `Module::publicNavItem()` and `Nav` drops it automatically when the
  toggle is off. See "Public navigation" below.

## Public navigation

The public nav is built from the enabled modules, not written into
`resources/layout.php`. A module contributes its own link:

```php
public function publicNavItem(): ?array
{
    return ['label' => 'Blog', 'url' => '/blog'];
    // add 'primary' => true for the highlighted call-to-action button
}
```

`Nav::publicItems()` collects them in the order modules are enabled and drops
any module the owner has hidden from the dashboard. A module does **not**
check its own visibility there — `Nav` does it.

**Never hardcode a module's link into a layout.** A hardcoded link survives
the module being disabled: its routes unregister, so the page returns 404,
but the link stays in the header, the mobile menu and the footer and sends
visitors straight to that 404. That was a real bug, fixed by this mechanism.

Return `null` from `publicNavItem()` for modules with no public page (auth,
admin-dashboard) — that is the default, so most admin-only modules need
nothing.
## Auto-update boundaries

A site owner can apply a PlugPHP update from `/admin/updates`. That update has
write access to `core/`, so what it may and may not touch is a hard boundary,
not a convention. `core/Updater.php` enforces it on the installer side and
`tools/build-release.php` enforces it again when a release is packaged — a
package containing anything outside the list is rejected whole.

**Safe to overwrite — infrastructure, never hand-edited by a site's developer:**

- `core/*.php`
- `core/migrations/*.sql`
- `modules/*/[Name]Module.php`
- `modules/*/routes.php`
- `modules/*/migrations/*.sql`
- `public/index.php`

**Never touched by an update, under any circumstance:**

- `modules/*/views/*` — the developer's UI work
- `resources/layout.php`, `resources/404.php` — branding and styling
- `.env` — site-specific secrets and configuration
- `config/modules.php` — the site's enabled-module list
- `public/.htaccess` and the root `.htaccess` — may carry custom redirects or
  headers. If a patch needs an `.htaccess` change it is surfaced as a manual
  diff for the owner to review, never applied automatically.
- `vendor/` — dependency updates are a manual reupload. Auto-update cannot
  ship a patched PHPMailer; that is a deliberate trade for a narrow blast
  radius, and it means a dependency CVE needs a full redownload of the kit.

**What this means when you build something:**

- Anything a developer is expected to restyle belongs in `views/` or
  `resources/`. Put it anywhere else and an update will overwrite their work.
- Anything that must survive an update — a site's own setting, a toggle, a
  piece of owner-entered content — belongs in the database via `Settings`, not
  in a file under `core/` or `modules/*/`.
- Do not widen the safe list. It is the entire safety guarantee of the feature.

`core/` is not a module, but additions to it (`Updater.php`, `Url.php`,
`Settings.php`) follow the same conventions as the rest of `core/`: final
classes, static methods, no raw SQL outside `Database.php`, and one choke
point per concern rather than a helper scattered across modules.

## Migrations

Every migration runs through `Database::runMigrationFile()`, which records it
in `migrations_log` and skips anything already applied. Migrations are keyed by
their path relative to the project root, so two modules may both ship an
`001_create_items.sql` without colliding.

Migrations are **forward-only**. A rollback restores code files but never
reverses schema changes — undoing an `ALTER TABLE` would destroy data. Write
migrations so that older code tolerates the newer schema: add columns as
nullable or with defaults, and never rename or drop a column that shipped
code still reads.
## If you're not sure

Say so, and ask, rather than improvising a workaround — especially for
anything touching `/core`, auth, payments, or file uploads. A wrong guess
here is a security bug on somebody's live business site, not a cosmetic issue.
