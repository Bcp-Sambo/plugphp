# Core API Reference

The complete public surface of `core/`. These are the only building blocks a
module should use to reach the database, render output, authenticate, send mail,
or store files. **You never edit `core/`** — you call it.

Every class is `final` and uses static methods (no instances to wire up).

- [Config](#config) · [Database](#database) · [Auth](#auth) · [View & `e()`](#view--e)
- [Router](#router) · [Module](#module) · [Settings](#settings) · [Mailer](#mailer) · [Upload](#upload)

---

## Config

`core/Config.php` — reads `key=value` pairs from `.env` into a static store.
Dependency-free (no Composer). Loaded once by the front controller.

```php
Config::get('APP_NAME');                 // value or null
Config::get('SMTP_PORT', 587);           // value or default
Config::isDebug();                       // APP_DEBUG === 'true'
Config::isProduction();                  // APP_ENV === 'production'
```

`.env` parsing notes: lines starting with `#` and blank lines are skipped; the
value is split on the first `=`; surrounding quotes are stripped and
leading/trailing whitespace is trimmed. **Never** hardcode secrets in a module —
add a key to `.env` / `.env.example` and read it with `Config::get()`.

---

## Database

`core/Database.php` — **the only way to talk to the database.** It deliberately
exposes no raw-query method: every call is a parameterised PDO prepared
statement. Connection is lazy and reused (singleton PDO with
`ERRMODE_EXCEPTION`, `FETCH_ASSOC`, real prepares).

```php
// Read
$row  = Database::fetchOne('SELECT * FROM posts WHERE id = :id', ['id' => $id]); // ?array
$rows = Database::fetchAll('SELECT id, title FROM posts WHERE published_at <= :now',
                           ['now' => date('Y-m-d H:i:s')]);                       // array

// Write — table & column names are validated as safe identifiers
$id      = Database::insert('posts', ['title' => $t, 'slug' => $s]);   // returns last insert id (string)
$changed = Database::update('posts', ['title' => $t], 'id', $id);      // rows affected (int)
$deleted = Database::delete('posts', 'id', $id);                       // rows affected (int)

// Migrations (trusted files only — never user input)
Database::runMigrationFile(__DIR__ . '/migrations/001_create_posts.sql');
```

Rules and gotchas:

- **Bind every value.** Pass user/DB values as named params (`:id`), never
  string-concatenate them into SQL.
- `insert`/`update`/`delete` validate table and column names against
  `^[a-zA-Z_][a-zA-Z0-9_]*$` and throw `InvalidArgumentException` otherwise —
  table/column names must be literals in your code, never user input.
- `WHERE` in `update`/`delete` is a single `column = value` match. For anything
  more complex, write the `SELECT`/`INSERT` with `fetchAll`/`insert`, or compose
  logic in PHP — do **not** add a raw executor to `core/`.
- **`LIMIT`/`OFFSET`:** MySQL rejects bound placeholders for these under real
  (non-emulated) prepares. Cast to `int` and inline the integer, as the blog
  module does:
  ```php
  'SELECT ... ORDER BY published_at DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
  ```
  This is safe *only because the value is an `int` you control* — never inline a
  string.

---

## Auth

`core/Auth.php` — sessions, passwords, CSRF, and password-reset tokens for the
whole app. Modules must call these instead of touching `$_SESSION`,
`password_*`, or building tokens.

```php
// Session lifecycle
Auth::bootSession();                       // idempotent; called by the front controller
Auth::userId();                            // ?string — current user id or null
Auth::check();                             // bool — is someone logged in
Auth::requireLogin();                      // redirect to /login and exit if not

// Registration / login
$id = Auth::register($email, $password, ['name' => $name]); // throws if email exists
Auth::attemptLogin($email, $password);     // bool; regenerates session id on success
Auth::logout();

// CSRF — see Security guide
$token = Auth::csrfToken();                // per-session token (create-on-first-use)
Auth::verifyCsrf($submitted);              // bool
Auth::requireCsrf($_POST['csrf_token'] ?? null); // 419 + exit if invalid

// Password reset (raw token is emailed, only its hash is stored)
$rawToken = Auth::requestPasswordReset($email);        // ?string; rate-limited, silent on unknown email
$userId   = Auth::verifyResetToken($rawToken);         // ?string
Auth::completePasswordReset($rawToken, $newPassword);  // bool; single-use
```

Security properties baked in (do not undo them):

- Login failure is the **same generic path** whether the email is unknown or the
  password is wrong — no user enumeration.
- `attemptLogin` and `logout` call `session_regenerate_id(true)` to prevent
  fixation.
- Password reset **stores only the SHA-256 hash** of the token, enforces a TTL
  (30 min) and a per-user rate limit (5 min), and marks tokens single-use.
- `requestPasswordReset` returns `null` silently for unknown emails — the caller
  must not reveal whether the address exists.

⚠️ **Every admin handler calls `Auth::requireLogin()` as its first line.** The
shared admin renderer does *not* do it for you — that's deliberate, so the guard
is visible at each handler.

---

## View & `e()`

`core/View.php` — renders a view file inside the shared layout and applies
security headers.

```php
View::render(__DIR__ . '/views/index.php', [
    'posts'     => $posts,
    'pageTitle' => 'Blog',           // optional; used by layout.php <title>
]);
View::sendSecurityHeaders();         // called once by the front controller
```

`render()` extracts `$data` into local variables for the view, captures the view
into `$content` via output buffering, then includes
[`resources/layout.php`](../resources/layout.php), which echoes `$content` into
the page shell. Conventional data keys the layout understands: `pageTitle`,
`metaDescription`, `headExtra` (pre-escaped canonical/OG/JSON-LD), and
`bareLayout` (skip header/footer — used by auth screens).

The global escape helper:

```php
<h1><?= e($post['title']) ?></h1>     // htmlspecialchars, ENT_QUOTES, UTF-8
```

⚠️ **Never `echo` a dynamic value without `e()`.** There is no case in a view
where raw echo of user- or DB-sourced data is acceptable — that's how XSS gets
in.

---

## Url

Makes the app indifferent to where it is mounted — domain root, subdomain, or
subfolder. Every internal link, asset, redirect and canonical URL goes through
here. See hard rule 6 in the root `SKILL.md`.

```php
// In views — HTML-escaped, drop straight into an attribute.
<a href="<?= url('/blog') ?>">Blog</a>
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
<img src="<?= asset($post['featured_image']) ?>" alt="">

// In PHP — raw strings.
header('Location: ' . Url::to('/admin/blog'));
$canonical = Url::absolute('/blog/' . $post['slug']);
```

| Method | Returns | Use for |
|---|---|---|
| `Url::base()` | `''` or `/prefix` | The detected mount prefix. Rarely needed directly. |
| `Url::to($path)` | raw | Internal links, form actions, redirects. |
| `Url::asset($path)` | raw | Static assets and uploaded files. |
| `Url::absolute($path)` | raw | Canonical, Open Graph, sitemap, email links. |
| `url($path)` | escaped | View helper wrapping `Url::to()`. |
| `asset($path)` | escaped | View helper wrapping `Url::asset()`. |

`url()` and `asset()` already apply `e()`. Do not wrap them in `e()` again.

**Base-path detection.** The prefix is derived by comparing the front
controller's location (`SCRIPT_NAME`) against the path the browser actually
requested. A prefix only counts when the request genuinely carries it — which
is what makes the root-`.htaccess` fallback work. Under that fallback
`SCRIPT_NAME` is `/public/index.php` while the browser asked for `/blog`;
naively taking `dirname()` would emit `/public/blog` links, and that same
`.htaccess` refuses to rewrite them, so every link would 404.

**`Url::absolute()` and the Host header.** The host always comes from
`APP_URL`, never from `$_SERVER['HTTP_HOST']`. `HTTP_HOST` is
attacker-controllable: a password-reset link built from it lets an attacker
mail a victim a reset URL pointing at a host they control, with a valid token
attached. `APP_URL` must therefore be the site's full public root, including
the subfolder if there is one — the detected base is not appended on top.

---
## Router

`core/Router.php` — the route table. You don't instantiate it; the front
controller passes an instance to each module's `routes()`.

```php
$router->get('/blog', function (): void { $this->publicIndex(); });
$router->get('/blog/{slug}', function (array $p): void { $this->publicShow($p['slug']); });
$router->post('/admin/blog', function (): void { $this->adminStore(); });
```

- `{name}` path segments are passed to the handler as `['name' => '…']`.
- Register routes **only** inside a module's `routes()` — never in
  `public/index.php` or `core/`. That's what keeps modules independently
  removable.
- Unmatched paths render the styled 404 automatically.

---

## Module

`core/Module.php` — the abstract base every module class extends. It forces a
uniform shape so the bootstrap and installer can treat all modules the same.

```php
abstract public function name(): string;              // machine name == folder name
abstract public function label(): string;             // shown in the install picker
abstract public function routes(Router $router): void; // register routes
abstract public function migrations(): array;          // absolute paths to .sql (or [])
public function dashboardNavItem(): ?array;            // ['label'=>…, 'url'=>…] or null
```

Even a module with no tables must implement `migrations()` (return `[]`). See
[Building a Module](building-a-module.md).

---

## Settings

`core/Settings.php` — a shared key/value store (one `site_settings` table) for
site-level toggles, most importantly per-module public visibility.

```php
Settings::get('some_key', $default);
Settings::getBool('blog_visible', true);
Settings::set('blog_visible', '1');
Settings::isModuleVisible('blog');    // getBool("{module}_visible", true)
Settings::delete('some_key');       // remove the row entirely; not the same as set('')
```

Use this for any dashboard-controlled on/off setting. **Do not** create a
separate settings table per module — this one shared table is the pattern.

---

## Mailer

`core/Mailer.php` — a thin wrapper over PHPMailer so modules never touch SMTP.
Reads all SMTP settings from `.env`. Returns `false` (and logs) on failure
rather than throwing, so a mail problem never white-screens a page.

```php
Mailer::send($toEmail, 'Subject', '<p>HTML body</p>');   // bool
Mailer::sendPasswordReset($toEmail, $rawToken);          // builds the reset link from APP_URL
```

⚠️ **Never build raw headers or call `mail()`/PHPMailer directly in a module.**
Always go through `Mailer::send()`. If SMTP isn't configured in `.env`, send
calls simply fail-soft (log + `false`), so contact/reset features no-op cleanly.

---

## Updater

Powers the one-click update at **admin → Updates**. Triggered only from an
authenticated, CSRF-guarded POST — there is no cron entry point and nothing is
ever applied automatically.

```php
Updater::VERSION;              // version of the files on disk (a constant)
Updater::installedVersion();   // version recorded in site_settings
Updater::versionsAgree();      // false when an update stopped partway

$manifest = Updater::fetchManifest();       // null when unreachable
Updater::isNewer($manifest);
Updater::preflight();                       // PASS/WARN/FAIL readiness checks
Updater::update();                          // ['success'=>bool,'log'=>string[],'version'=>?string]
Updater::rollbackAvailable();
Updater::rollback();
Updater::finishInterrupted();               // run outstanding migrations only
```

**The boundary.** `Updater::isSafePath()` is the allowlist, and it is the whole
safety guarantee of the feature — see "Auto-update boundaries" in the root
`SKILL.md`. A package containing any path outside it is rejected whole rather
than partially applied. `tools/build-release.php` checks the same list when
packaging, so a mistake fails at release time, not on every site.

**The flow**, each step logged and shown to the admin: fetch the manifest →
preflight → download → verify SHA-256 → validate every path in the package →
back up the files about to change → apply → run new migrations → record the
version. A checksum mismatch aborts unconditionally and deletes the download.
A write failure partway through triggers an automatic restore.

**Why two version values.** `VERSION` is a compile-time constant shipped inside
each package, so it describes the files on disk. The `site_settings` row
describes what the *database* has been migrated to. Keeping them separate makes
a half-finished update detectable instead of silently wrong; `versionsAgree()`
is that check, and `finishInterrupted()` is the recovery.

**Applying does not reload the constant.** After `apply()` overwrites
`core/Updater.php`, `self::VERSION` in the running process is still the old
value — PHP compiled the class at include time. A per-request guard stops a
double-submit from applying twice and destroying the one good rollback point.
The next request reads the new constant normally.

**Rollback is code-only.** It restores files from the pre-update backup and
deletes files the update introduced, but never reverses a migration: schema
changes are forward-only, and undoing an `ALTER TABLE` would destroy data.
Write migrations so older code tolerates the newer schema.

Backups live in `storage/backups/`, capped at the 3 most recent, and the
rollback button is offered for 7 days.

---
## Upload

`core/Upload.php` — the shared, secure image-upload validator. Route **all**
image uploads through it; never move an uploaded file yourself.

```php
// $subdir is whitelisted (^[a-z0-9][a-z0-9-]*$); returns the public web path
$path = Upload::image($_FILES['featured_image'], 'blog');  // e.g. "/uploads/blog/ab12….jpg"
```

What it guarantees (see [Security](security.md) for the full rationale):

1. Whitelists by **real sniffed MIME type** (`finfo`), never the client
   `Content-Type` or filename extension.
2. **Re-encodes through GD**, so the stored file is fresh pixels — anything
   smuggled in the original bytes (PHP in EXIF, polyglots) does not survive.
3. Stores under a **random filename** with a server-chosen extension, `0644`
   (never executable), under `public/uploads/<subdir>/`.
4. Enforces a 5 MB limit and `image/jpeg|png|gif|webp` only; throws
   `RuntimeException` on any failure — catch it and show a friendly message.

Requires the `gd` extension. Callers typically wrap the call in `try/catch` and
put the error into their form's validation errors (see `BlogModule::uploadFeatured`).
