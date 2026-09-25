# Services module

Dashboard-editable list of services the business offers (e.g. "Web
Development", "ERP Consulting"). Same pattern as Blog, simpler schema.

## Schema (migrations/001_create_services.sql)
`services`: id, title, slug, summary, description, icon, display_order,
meta_title, meta_description, created_at, updated_at.

## Routes this module owns
- `GET /services` — grid/list of all services
- `GET /services/{slug}` — single service detail page
- Admin CRUD under `/admin/services/*`, guarded by `Auth::requireLogin()`.

## Rules specific to this module
- Sort by `display_order` for the public listing, not by id or created_at —
  the admin controls display order deliberately via the dashboard.
- Structured data: emit `Service` JSON-LD schema per service page
  automatically (title, description, business name from `.env`/config) —
  do not hand-write this per page.
- Icons: if using an icon set, reference by name/class string stored in
  the `icon` column — do not let the admin paste raw SVG/HTML into this
  field, that reopens the same XSS risk `e()` exists to prevent.

## Public visibility toggle
Same pattern as Blog: only register `GET /services` and
`GET /services/{slug}` in `routes()` when
`Settings::isModuleVisible('services')` is true. Admin routes always
stay registered. See root `SKILL.md` for the full pattern and rationale.

## Dashboard nav
Registers "Services" in the admin sidebar via `dashboardNavItem()`.

## URLs — deployment portability

This site may be served from a main domain, a subdomain, or a subfolder, so
no URL in this module may be a leading-slash literal.

- Public links and form actions in views: `url('/blog')`, never `href="/blog"`.
- Assets and uploaded images: `asset($row['featured_image'])`.
- Canonical, Open Graph and JSON-LD URLs: `Url::absolute($path)`. This module's
  private `canonical()` / `absoluteUrl()` helpers delegate to it — keep them
  delegating rather than rebuilding the string from `APP_URL` by hand.
- Redirects after a POST: `header('Location: ' . Url::to('/admin/...'))`.

`url()` and `asset()` escape their output; `Url::to()` and `Url::absolute()`
do not. Never wrap `url()`/`asset()` in `e()`.

`Url::absolute()` derives its host from `APP_URL`, never from the request's
Host header — see the root `SKILL.md` for why that matters.