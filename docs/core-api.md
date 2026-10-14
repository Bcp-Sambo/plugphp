# Core API Reference

The complete public surface of `core/`. These are the only building blocks a
module should use to reach the database, render output, authenticate, send mail,
or store files. **You never edit `core/`** — you call it.

Every class is `final` and uses static methods (no instances to wire up).

- [Config](#config) · [Database](#database) · [Auth](#auth) · [View & `e()`](#view--e)
- [Router](#router) · [Module](#module) · [Settings](#settings) · [Mailer](#mailer) · [Upload](#upload)
- [Http](#http) · [Throttle](#throttle) · [Crypto](#crypto) · [Updater](#updater) · [SecurityLog](#securitylog)

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

// Delete rows older than a cutoff — the one range delete, for pruning
// expiring data (rate-limit counters, stock holds). There is no cron here.
$pruned = Database::deleteOlderThan('request_throttle', 'created_at', $cutoff);

// Migrations (trusted files only — never user input)
Database::runMigrationFile(__DIR__ . '/migrations/001_create_posts.sql');

// One transaction around several statements. Commits on return, rolls back
// and rethrows on any exception, and passes the return value through.
$orderId = Database::transaction(function () use ($productId) {
    $row = Database::fetchOne(
        'SELECT stock_quantity FROM products WHERE id = :id FOR UPDATE',
        ['id' => $productId]
    );
    // ... decide from $row, then write
    return Database::insert('orders', [/* ... */]);
});
```

Rules and gotchas:

- **`SELECT ... FOR UPDATE` only means something inside
  `Database::transaction()`.** On autocommit the row lock is released the
  instant the SELECT finishes, so two requests both read the same "1 left"
  and both proceed. The code looks right and oversells only under concurrent
  load. `tools/test-stock-locking.php` demonstrates both outcomes against a
  real database — run it if you are changing anything in this area.
- **`transaction()` does not nest.** A second call while one is open throws
  rather than quietly joining the outer transaction. Restructure so the
  outermost caller owns the transaction; add `SAVEPOINT` support (with its
  own test) only if a real call site ever needs it.
- **No DDL inside `transaction()`.** MySQL commits implicitly on `CREATE`,
  `ALTER` and `DROP`, which ends the transaction mid-closure while the method
  still believes it is open. Never call `runMigrationFile()` from inside one.
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

### Roles

```php
Auth::ROLE_ADMIN;   Auth::ROLE_EDITOR;   Auth::ROLES;   // value => label

Auth::user();                          // the current user row, or null
Auth::role();                          // 'admin' | 'editor' | null
Auth::isAdmin();
Auth::requireRole(Auth::ROLE_ADMIN);   // 403 + plain message on mismatch
```

`requireRole()` goes **after** `requireLogin()`, never instead of it. See the
"Roles" section in the root `SKILL.md` for the rule about user management.

The role is read from the database per request (memoised within the request),
not from the session, so changing someone's role takes effect on their very
next request rather than at their next login.

`requireLogin()` also confirms the user row still exists and ends the session
if it does not — without that, a deleted account would keep working access
until its session happened to expire.

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
## Crypto

The only approved way to store a secret in the database. See "Secrets in the
database" in the root `SKILL.md`.

```php
Crypto::hasKey();                 // is an APP_KEY available?
Crypto::encrypt($plaintext);      // base64(iv . tag . ciphertext)
Crypto::decrypt($stored);         // null on missing key / tampering / garbage
Crypto::ensureKey();              // generate and write APP_KEY to .env
Crypto::suggestedKeyLine();       // the line to paste when .env is read-only
```

AES-256-GCM. GCM rather than CBC because it is authenticated: a value altered
in the database fails its tag check and `decrypt()` returns `null`, so a
tampered row degrades to "not configured" instead of feeding a caller
corrupted bytes. Unauthenticated CBC would let anyone who can write to
`site_settings` change ciphertext undetectably.

`encrypt()` throws when there is no key, rather than letting a caller silently
store a secret in the clear. `decrypt()` never throws.

`APP_KEY` lives in `.env`, never in the database — a leak of one must not be a
leak of both. It is generated per install. Because `.env` is on the updater's
never-touch list, no release can add it to an existing site, so `ensureKey()`
creates one on first need; when `.env` is not writable it returns `false` and
the caller must refuse to store the secret.

Changing `APP_KEY` makes existing encrypted values unreadable.

---

## Tracking

GA4 and Facebook Pixel snippets, driven by dashboard settings.

```php
Tracking::isValidGaId($id);        // ^G-[A-Z0-9]{4,24}$
Tracking::isValidFbPixelId($id);   // ^[0-9]{5,24}$
Tracking::normaliseGaId($input);   // trim + uppercase, then validate
Tracking::headSnippet();           // GA4 gtag.js, for <head>
Tracking::bodySnippet();           // FB Pixel, for just after <body>
Tracking::isActive();              // enabled AND at least one valid ID
Tracking::cspScriptSources();      // vendor hosts to allow right now
Tracking::cspConnectSources();
```

The IDs land inside inline `<script>` blocks, so they are validated before
saving and again before rendering — see "Tracking pixel IDs" in the root
`SKILL.md`. Both snippets return an empty string unless tracking is enabled
and the relevant ID is valid.

The CSP host lists live here, next to the snippets, so a snippet that gains a
dependency and the policy that permits it cannot drift apart.

---

## Branding

Site identity from the dashboard, with fallbacks.

```php
Branding::siteName();          // falls back to APP_NAME
Branding::description();       // '' when unset
Branding::logo();              // falls back to the shipped asset
Branding::favicon();           // null when unset
Branding::ogImage();
Branding::ogImageAbsolute();   // via Url::absolute(), for Open Graph
```

Every accessor is safe while the database is unreachable, because the layout
calls them on every page including the 404. Render image paths with `asset()`.

---
## Nav

Builds the public site navigation from the enabled modules, dropping any the
owner has hidden from the dashboard.

```php
Nav::publicItems();      // [['label'=>, 'url'=>, 'primary'=>bool], ...]
Nav::secondaryItems();   // the same, excluding the primary call-to-action
Nav::primaryItem();      // the call-to-action item, or null
```

A module contributes its link through `Module::publicNavItem()`; `Nav` applies
the visibility check, so modules never test their own. Results are memoised
per request.

The nav used to be hardcoded in `resources/layout.php`. Hiding Services
correctly unregistered its routes, so `/services` returned 404 — but the three
hardcoded links stayed in the header, mobile menu and footer, pointing
visitors at that 404. Building it from the module list is what makes the
dashboard toggle actually mean something.

`Nav` falls back to showing an item when the visibility lookup throws, so the
layout still renders (including the 404 page) when the database is down.

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

Reading a module's declared capabilities (never loaded during a normal request):

```php
Module::manifestFor('blog');        // validated array, or null
Module::capabilitySummary('blog');  // one-line plain-English summary
```

A manifest naming a different module than its folder is refused, not trusted.
It is a declaration, not a sandbox — nothing enforces it at runtime.

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

**Reference files — the ones an update may never install.** Views,
`resources/`, the `.htaccess` files: a release carries them under a separate
prefix, the updater drops them in `storage/pending-views/`, and the admin
applies them from the dashboard. `referenceStatus()` classifies each one
against three inputs — what the site has, what it had before its last update,
and what this release ships — so a file the owner customised is never confused
with one that is merely old:

```php
Updater::referenceStatus();        // [['file','status','staged','effect'], …]
Updater::applyPendingView($rel);   // one file, backing up the current version
Updater::applyAllMissing();        // every MISSING file, in one action
```

`applyAllMissing()` is scoped to `missing` and nothing else. A missing file has
no local version to lose, so one confirmation for the batch is an honest
trade — enabling a new module leaves a dozen views missing at once, and
applying them one click at a time is a bad first experience for no safety
benefit. `behind`, `conflict` and `unknown` stay one file at a time on purpose:
each of those can overwrite something the owner wrote, and the per-file
decision *is* the protection. It derives its own list rather than trusting the
page, so a file that reappeared in the meantime is never in the batch, and
each file still goes through `applyPendingView()` so there is one write path,
not a faster looser one.

`tools/test-bulk-apply.php` exercises this against a throwaway copy of the
tree — it is the only path that writes into a live site's own directories, so
it is not tested against a working checkout.

---
## ModuleManager

`core/ModuleManager.php` — turning modules on and off after install time.
Backs `/admin/modules`.

```php
ModuleManager::installed();                  // folder names present on disk
ModuleManager::enabled();                    // the config/modules.php list
ModuleManager::overview();                   // everything the screen needs, one pass
ModuleManager::isEnabled('blog');
ModuleManager::isAlwaysOn('auth');           // home, admin-dashboard, auth
ModuleManager::ignored();                    // deleted on purpose; updates skip these

ModuleManager::enable('ecommerce');          // + runs ITS migrations, now
ModuleManager::disable('blog');              // files and tables untouched
ModuleManager::removePermanently('blog');    // deletes the folder, remembers that
```

Each state-changing call returns `['success' => bool, 'message' => string]` and
writes a `SecurityLog` entry.

**Disable is not Hide.** Disable removes the module from `config/modules.php`,
so it is not loaded at all — no routes, no admin panel, its pages 404. Hide
(`Settings::isModuleVisible()`) leaves it running and only takes its link out of
the public menu. Both exist because they are different granularities.

`ModuleManager::ALWAYS_ON` is the single definition of the modules that cannot
be switched off; `install.php` reads the same constant. Do not write a second
copy of that list.

`config/modules.php` is written to a temp file and renamed, so a request
arriving mid-write never reads a half-written list.

---

## SecurityLog

`core/SecurityLog.php` — the audit trail for actions that change what a site
can do.

```php
SecurityLog::log(SecurityLog::MODULE_ENABLED, ['module' => 'ecommerce']);
SecurityLog::recent(25);   // newest first, acting account resolved
```

Deliberately narrow: module enable/disable/remove, not page edits or logins. A
log that records everything gets ignored, and an ignored log is not an audit
trail.

`log()` never throws — an audit write must not take down the action it
describes — but a failure is written to the PHP error log rather than swallowed.
Proxy headers are **not** consulted when recording the caller's address: on
shared hosting anyone can set `X-Forwarded-For`, so trusting it would let the
person being audited choose what the audit says.

**Every path that changes `config/modules.php` or deletes a module folder must
call `log()`.** That is a hard rule.

---

## Upload

> Signature note: `Upload::image($file, $subdir, ?int $maxBytes = null, ?array $onlyTypes = null)`.
> The last two are optional — `Upload::image($f, 'branding', 2*1024*1024, ['png'])` caps the size
> and accepts PNG only. The type list says what the caller will accept; the decision is still made
> from the file's sniffed bytes, and the stored extension is always chosen by the server.

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

---

## Http

`core/Http.php` — the only approved way to make an outbound request. Two
methods, both JSON, because the only thing this project calls out to is a
payment gateway's REST API.

```php
$res = Http::postJson($url, $payload, ['Authorization' => 'Bearer ' . $key]);
$res = Http::getJson($url, ['Authorization' => 'Bearer ' . $key], 10); // timeout in seconds

// [ 'ok' => bool, 'status' => int, 'data' => array, 'error' => ?string ]
```

**`ok => false` means "we do not know what happened", not "the operation
failed."** For a payment verification those are opposite outcomes: a timeout
must leave the order `pending` and re-verifiable, never mark it failed. `data`
is `[]` on every failure, so a caller that checks `$res['data']['status']`
without checking `ok` still falls through to "not paid".

A 4xx or 5xx carrying a readable JSON body gives `ok => false` **with `data`
populated** — gateways put their reason codes there and the caller needs them.
A 2xx with an empty body (a 204) is `ok => true` with empty `data`.

Transport problems are returned, not thrown: a gateway timing out is an
expected condition on shared hosting, and the payment return page still has to
render something for the customer.

What it guarantees, none of it configurable:

1. **HTTPS only** — an `http://` URL is refused, not upgraded, and a URL
   carrying `user:pass@` credentials is refused too.
2. **Certificate verification always on.** There is no parameter to disable
   it, so no amount of "just make it work" produces a request that accepts any
   certificate.
3. **Redirects are never followed.** A 3xx would re-send the `Authorization`
   header to a host chosen by the response — a secret-leak primitive, and no
   gateway needs it.
4. **Responses are capped** at 1 MB, enforced as bytes arrive.
5. **Nothing that can carry a secret is logged** — no headers, no request
   body, and no query string. Transport messages are scrubbed of URLs before
   they are returned or logged, because PHP's own stream warnings quote the
   full URL.
6. Header names and values are rejected if they contain CR or LF (a key pasted
   with a trailing newline is a real occurrence); the error names the header
   but never repeats the value.

Timeouts default to 15s and are clamped to 30s, because shared hosting often
caps `max_execution_time` at 30 and a request that outlives it is killed
mid-flight.

Works with curl, and falls back to the stream wrapper on hosts built without
it. `tools/test-http.php` runs the full assertion set against a real TLS
server on **both** transports, plus a run with the certificate authority
deliberately untrusted to prove verification is really on.

`Updater::httpGet()` stays separate and private: it fetches the file that can
overwrite `core/`, so its host allowlist, redirect policy and byte cap are
decided in the class that acts on them rather than inherited from a
general-purpose helper something else might widen.

---

## Throttle

`core/Throttle.php` — "has this address done this too many times lately?",
over a shared `request_throttle` table keyed by an action name and the
caller's IP.

```php
if (Throttle::isLimited('checkout', 10, 3600)) {
    // show "too many attempts, try again later" and stop
}
Throttle::hit('checkout');          // count this attempt, then do the work

Throttle::prune();                  // delete counters past the retention window
```

Check first, then record — recording before the check costs the caller their
own first attempt. `$action` is an application-chosen label (`'checkout'`,
`'login'`), **never user input**.

**Why a table of its own.** Every other limit in this project counts the rows
the action already wrote: Contact Form counts `contact_submissions`, password
reset counts `password_resets`. That works because the submission *is* the
row. Checkout has to be limited before an order exists, or the limit creates
the junk orders it was added to prevent. `security_log` was the obvious
shortcut and is the wrong answer — it records only actions that can turn a
site's functionality on or off, precisely so it stays readable.

**It is not a security control, and it fails open.** A failed write loses one
count; a failed read returns "not limited" and the request proceeds. If this
table is unreachable the database is unreachable and the action cannot
complete anyway, so failing closed would convert a database problem into a
site-wide refusal to serve anyone while protecting nothing — and a missing
table is the normal state of a site mid-upgrade. Every swallowed error reaches
the error log, so failing open is not failing silently. Authentication, CSRF
and server-side revalidation are what actually guard an action.

**Details worth knowing:**

- A **rolling** window (rows newer than a cutoff), not a fixed bucket, so it
  cannot be gamed by waiting for a period boundary.
- An unreadable address is **not** limited, matching Contact Form: refusing to
  serve a visitor whose address cannot be read is the worse failure. Nothing
  is recorded for them either — a row with no address would be one shared
  bucket for everybody.
- The address comes from `SecurityLog::clientIp()`, the single definition of
  what is trustworthy. Proxy headers are never consulted: a second reader that
  honoured `X-Forwarded-For` would let a visitor pick their own bucket.
- Timestamps are UTC (`gmdate`), stored and compared on one clock, so a
  timezone change or DST cannot shift an hour of counters sideways.
- `hit()` sweeps the table roughly one time in fifty, because there is no cron
  on this hosting and relying on each caller to remember a sweep means the
  first one that forgets grows the table forever. `prune()` stays public for
  callers with a natural sweep point — the checkout prunes expired stock holds
  on the same pass.

`tools/test-throttle.php` covers it against a development database, and runs
in a deliberately non-UTC timezone: on a UTC machine a version using local
time passes every assertion.