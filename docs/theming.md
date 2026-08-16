# Theming & Branding

PlugPHP ships **minimal, semantic HTML** and one stylesheet. Restyling a site
means editing presentation files that live *outside* `core/` — the data flow
never changes. This page maps what to edit for a per-site look.

## What you can restyle (and what you can't)

| File / folder | Edit? | Purpose |
|---|---|---|
| `public/assets/css/app.css` | ✅ Yes | Site-wide styles. The main lever. |
| `resources/layout.php` | ✅ Yes | Public page shell: header, nav, footer, branding. |
| `resources/404.php` | ✅ Yes | 404 content (keep the attribution line unless asked). |
| `modules/<name>/views/*.php` | ✅ Yes | Per-page markup. Style freely; don't change the data flow. |
| `public/assets/img/logo.png` | ✅ Replace | The brand mark used across the site. |
| `modules/admin-dashboard/views/*` | ✅ Yes | The admin shell look. |
| `core/*` | ❌ No | Locked infrastructure. |

## Site name and logo

- **Name:** set `APP_NAME` in `.env`. The public header, footer, `<title>`
  fallback, and admin shell all read it via `Config::get('APP_NAME')`.
- **Logo:** replace `public/assets/img/logo.png` (keep the filename, or update
  the `<img src>` in `resources/layout.php` and the admin/auth views if you
  rename it). The public header and footer render it at 30px / 26px; provide a
  crisp square PNG.

## The public shell — `resources/layout.php`

Every page rendered by `View::render()` is wrapped in this file. It:

- Sets `<title>` from `$pageTitle` (falls back to `APP_NAME`), and
  `<meta name="description">` from `$metaDescription`.
- Injects `$headExtra` — pre-escaped canonical / Open Graph / JSON-LD that
  content modules build themselves. **Leave this as-is**; don't hand-add SEO
  tags per page (see [Security](security.md) and the root SKILL.md).
- Renders the header nav, `$content`, and footer.
- Honors `$bareLayout = true` — a view can set this to skip the header/footer
  (the auth screens do, to render a centered card).

The nav links are plain `<a>` tags you can edit directly. There's a CSS-only
mobile menu (a hidden checkbox `#nav-toggle` + burger label) — no JavaScript.

### Data a view can set for the layout

```php
View::render(__DIR__ . '/views/page.php', [
    'pageTitle'       => 'About us',
    'metaDescription' => 'Who we are.',
    'headExtra'       => $seoHeadHtml,   // optional, pre-escaped
    'bareLayout'      => false,          // optional
]);
```

## Styling approach

`app.css` is hand-written CSS with class names that match the markup
(`.site-header`, `.container`, `.brand`, `.btn`, `.nav`, `.site-footer`, …). The
CSP allows inline styles (`style-src 'self' 'unsafe-inline'`) but **only
same-origin scripts** — so no external CDNs for JS. Keep assets local under
`public/assets/`. Recommended workflow:

1. Set brand tokens (colors, fonts, radius) as CSS custom properties at the top
   of `app.css`.
2. Restyle the shared shell (`layout.php` classes) first — it frames every page.
3. Then per-module views as needed.

Follow the markup rules from the root [SKILL.md](../SKILL.md) — they're also what
the Lighthouse/SEO scoring keys on:

- One `<h1>` per page; no skipped heading levels.
- A real `alt` on every `<img>`; a `<label for>` on every input.
- Explicit `width`/`height` (or `aspect-ratio`) on images to avoid layout shift.

## The admin shell

Admin pages don't use `resources/layout.php`. They render through
`AdminDashboardModule::renderAdmin()`, which wraps the view in
`modules/admin-dashboard/views/layout.php` (sidebar + footer). The sidebar is
built automatically from each enabled module's `dashboardNavItem()` — never
hardcode a nav entry in a view. Restyle the admin shell in its own
`views/layout.php` and the shared admin CSS.

## Attribution

The line **"Built with PlugPHP — by Kabiru Sambo / Bubble Bot Solutions"**
appears by default in exactly three places: the admin dashboard footer, the
About page, and the 404 page. It is intentionally **not** in the global public
footer. Leave it unless a client explicitly asks you to remove it, and don't add
it elsewhere.
