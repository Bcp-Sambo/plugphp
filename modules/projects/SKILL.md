# Projects module

Portfolio of past work. Same content-module pattern as Blog/Services.

## Schema (migrations/001_create_projects.sql)
`projects`: id, title, slug, client_name, summary, description,
featured_image, gallery_images (JSON array of paths), project_url,
completed_at, meta_title, meta_description, created_at, updated_at.

## Routes this module owns
- `GET /projects` — portfolio grid
- `GET /projects/{slug}` — single case-study page
- Admin CRUD under `/admin/projects/*`, guarded by `Auth::requireLogin()`.

## Rules specific to this module
- `gallery_images` stored as a JSON string column — decode with
  `json_decode()` on read, encode with `json_encode()` on write. Do not
  create a separate join table unless the client specifically needs
  per-image metadata (captions, ordering) later.
- All image uploads (featured + gallery) go through `core/Upload.php`:
  `Upload::image($file, 'projects')` — real MIME sniff, GD re-encode, random
  filename, stored under `/public/uploads/projects/` at 0644, never
  executable. Optional third and fourth arguments cap the size and narrow the
  accepted types: `Upload::image($file, 'projects', 2*1024*1024, ['png'])`.
- `client_name` is public-facing — confirm with the site owner before
  publishing a project that names a client, this is a business/legal
  question, not a coding one, but worth surfacing if it comes up.
- Structured data: `CreativeWork` or `Project`-style JSON-LD emitted
  automatically per project page from existing fields.

## Public visibility toggle
Same pattern as Blog/Services: only register `GET /projects` and
`GET /projects/{slug}` in `routes()` when
`Settings::isModuleVisible('projects')` is true. Admin routes always
stay registered. See root `SKILL.md` for the full pattern and rationale.

## Dashboard nav
Registers "Projects" in the admin sidebar via `dashboardNavItem()`.

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