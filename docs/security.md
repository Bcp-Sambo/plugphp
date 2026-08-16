# Security Model

PlugPHP's security is **structural**, not advisory. The design goal: make the
unsafe thing impossible or obvious, so that neither a rushed developer nor an AI
agent under prompting pressure can casually introduce the classic PHP
vulnerabilities. This page explains each control and the rule that keeps it
intact.

## The threat model

A small business site on shared hosting, edited over time by developers and AI
agents of varying care. The realistic risks are SQL injection, XSS, CSRF, weak
auth/session handling, malicious file uploads, and leaking secrets or errors.
Each has a corresponding control in `core/`.

## SQL injection → no raw-query method exists

`core/Database.php` exposes only parameterised helpers (`fetchOne`, `fetchAll`,
`insert`, `update`, `delete`), each using **real** PDO prepared statements
(`ATTR_EMULATE_PREPARES => false`). There is deliberately **no** method that
runs a concatenated SQL string.

- Table/column names in `insert`/`update`/`delete` are validated against
  `^[a-zA-Z_][a-zA-Z0-9_]*$` and must be literals in your code — never user input.
- ⚠️ **Rule:** never reach for `PDO`/`mysqli` in a module, and never add a
  raw-SQL executor to `core/`. If you need a new query shape, write it as a
  specific `SELECT` with bound params. The one allowed inline value is an
  `int`-cast `LIMIT`/`OFFSET` (MySQL rejects bound placeholders there) — safe
  only because it's an integer you control.

## XSS → one-character escaping, no template trust

`core/View.php` defines the global `e()` helper
(`htmlspecialchars(ENT_QUOTES, UTF-8)`). Views are plain PHP; there's no
auto-escaping template engine, so escaping is explicit and visible.

- ⚠️ **Rule:** never `echo`/`<?=` a dynamic value without `e()`.
  `<?= e($post['title']) ?>`, never `<?= $post['title'] ?>`.
- Admin-entered HTML (e.g. a blog `body`) is **rendered escaped** — the kit does
  not ship an HTML sanitizer, so it never treats logged-in-user HTML as safe.

## Response headers → sent on every request

`View::sendSecurityHeaders()` runs once in the front controller and emits:

- `Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'`
  — **scripts are same-origin only** (no inline JS, no CDNs).
- `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: strict-origin-when-cross-origin`.
- `Strict-Transport-Security` — in production only.

Keep assets local under `public/assets/` to stay within the CSP.

## CSRF → required on every state change

`core/Auth.php` issues a per-session token and verifies it.

- ⚠️ **Rule:** every `POST`/`PUT`/`DELETE` handler calls
  `Auth::requireCsrf($_POST['csrf_token'] ?? null)` **as its first line** — no
  exceptions, including "low-risk" forms like contact. An invalid token returns
  `419` and exits.
- Every form that POSTs includes
  `<input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">`.

## Authentication & sessions

- Passwords: `password_hash(PASSWORD_DEFAULT)` / `password_verify` — never rolled
  by hand in a module.
- Sessions: `HttpOnly` + `SameSite=Lax`, and `Secure` in production
  (so admin login won't persist over plain HTTP — enable HTTPS first). Session id
  is regenerated on login and logout to prevent fixation.
- Login failure is a single generic path whether the email or the password is
  wrong — **no user enumeration**.
- ⚠️ **Rule:** never touch `$_SESSION` or `password_*` outside `core/Auth.php`;
  call `Auth::attemptLogin`, `Auth::requireLogin`, `Auth::check`, etc.

### Password reset

`requestPasswordReset` stores **only the SHA-256 hash** of the token (the raw
token is emailed, never persisted), enforces a 30-minute TTL and a 5-minute
per-user rate limit, and marks tokens single-use. Unknown emails return `null`
silently — the caller must not reveal whether an address exists.

## File uploads → validate, re-encode, neutralize

`core/Upload.php` is the only sanctioned path for image uploads:

1. Whitelists by **real sniffed MIME** (`finfo`), never client `Content-Type` or
   extension.
2. **Re-encodes through GD** — the stored file is fresh pixels, so PHP-in-EXIF or
   image/script polyglots don't survive.
3. Random server-chosen filename + extension; stored `0644` (never executable)
   under `public/uploads/<subdir>/` (subdir whitelisted against traversal).
4. 5 MB cap; `image/jpeg|png|gif|webp` only. A hardening `.htaccess` in
   `public/uploads/` additionally blocks script execution on Apache/cPanel.

- ⚠️ **Rule:** route all uploads through `Upload::image()`; never move an
  uploaded file yourself and never trust `$_FILES[...]['type']` or the filename.

## Secrets & error handling

- Secrets live in `.env`, read via `Config::get()`. `.env` is **gitignored** —
  never commit real values.
- `APP_DEBUG=false` in production: errors are **logged** to
  `storage/logs/php-error.log`, never shown. `APP_DEBUG=true` shows them (local
  only).

## Deployment-level: the document root

The single most important production setting: the web server's **document root
must be `public/`**, so `.env`, `core/`, `config/`, and `storage/` are never
web-reachable. Bundled `.htaccess` files are defense-in-depth, but the correct
setup is docroot = `public/`. Full checklist and the "secrets not web-reachable"
verification step are in [DEPLOY.md](DEPLOY.md).

## Quick rule sheet

| Concern | Do this | Never do this |
|---|---|---|
| Database | `Database::*` with bound params | Raw SQL, `PDO`, `mysqli` |
| Output | `e($value)` in views | `echo $value` of dynamic data |
| State change | `Auth::requireCsrf(...)` first line | Skip CSRF on "safe" forms |
| Auth | `Auth::*` | Touch `$_SESSION` / `password_*` directly |
| Email | `Mailer::send()` | `mail()` / raw SMTP headers |
| Uploads | `Upload::image()` | Move `$_FILES` yourself |
| Config/secrets | `.env` + `Config::get()` | Hardcode secrets; commit `.env` |
| Errors in prod | `APP_DEBUG=false` (logged) | Display errors publicly |

When unsure — especially around `core/`, auth, payments, or uploads — stop and
ask rather than improvise. A wrong guess here is a security bug on a live
business site, not a cosmetic one.
