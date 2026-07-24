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

## The five hard rules

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

## If you're not sure

Say so, and ask, rather than improvising a workaround — especially for
anything touching `/core`, auth, payments, or file uploads. A wrong guess
here is a security bug on somebody's live business site, not a cosmetic issue.
