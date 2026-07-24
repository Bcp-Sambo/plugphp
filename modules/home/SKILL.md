# Home module

Owns the site root (`GET /`). Static landing page with OPTIONAL, defensive
teasers of other modules' content. Always-on (a site needs a homepage), like
`admin-dashboard` and `auth`.

## Routes this module owns
- `GET /` — the landing page.

## What you can do
- Edit `views/home.php` freely — hero copy, sections, layout, branding. This
  is pure UI/content work.

## Rules specific to this module
- The services and latest-posts teasers read the `services` / `posts` tables
  **defensively** (`safeTeaser()`): they respect each module's public
  visibility toggle and swallow a missing table, so Home never breaks if blog
  or services isn't installed. Keep that pattern if you add more teasers —
  never assume another module's table exists.
- No migration/table of its own (Option A static, same as `about`).
- SEO title/description/canonical/OG/Organization JSON-LD are emitted by the
  module from `.env` values — don't hardcode meta tags in the view.
- Attribution does **not** appear here — it lives only on the About page, the
  404 page, and the admin dashboard footer (see root `SKILL.md`).

## Files
```
modules/home/
├── HomeModule.php
├── routes.php
└── views/home.php
```
