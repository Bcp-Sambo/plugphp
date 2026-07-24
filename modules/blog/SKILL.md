# Blog module

Dashboard-editable content module. Admin can create/edit/delete posts;
public routes render the list and single-post pages.

## Schema (migrations/001_create_posts.sql)
`posts`: id, title, slug, excerpt, body, featured_image, meta_title,
meta_description, published_at, created_at, updated_at.

## Routes this module owns
- `GET /blog` — list published posts (paginated)
- `GET /blog/{slug}` — single post
- Admin CRUD routes live under `/admin/blog/*`, guarded by
  `Auth::requireLogin()` as the first line of every handler.

## Rules specific to this module
- Fetch posts only via `Database::fetchAll('SELECT ... FROM posts WHERE ...', [...])`
  with bound params — never interpolate a slug or search term into SQL.
- Every post view outputs meta title/description/canonical/Open Graph/JSON-LD
  Article schema automatically from the post's own fields — do not hardcode
  these tags per-post.
- `featured_image` uploads go through the shared upload validator (extension
  whitelist + MIME sniff + re-encode) — see `contact-form/SKILL.md` for the
  same pattern if you need to reuse the upload helper.
- Slugs must be unique — enforce with a unique index in the migration, not
  application-level checking alone.
- `body` content from the admin editor must still be escaped or passed
  through a safe HTML-sanitizing step before rendering — do not trust
  admin-entered HTML as automatically safe just because it came from a
  logged-in user.

## Public visibility toggle
This module supports being hidden from the public site without being
uninstalled (e.g. site owner isn't ready to publish a blog yet). In
`routes()`, only register `GET /blog` and `GET /blog/{slug}` when
`Settings::isModuleVisible('blog')` is true. Do not register the routes
and check visibility inside the handler — skipping registration lets
the router's default 404 (see root SKILL.md) handle it automatically.
Admin routes (`/admin/blog/*`) always register regardless of this
toggle. Expose the on/off switch in this module's own dashboard
settings screen.

## Dashboard nav
Registers "Blog" in the admin sidebar via `dashboardNavItem()`, linking to
`/admin/blog`.
