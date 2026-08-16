# Architecture

PlugPHP is intentionally small. There is no framework, no service container, no
autoloader magic — just a front controller, a fixed set of `core/` classes, and
a folder of modules. This page traces exactly what happens on a request.

## The two-layer boundary

```
┌──────────────────────────────────────────────────────────┐
│  core/  — LOCKED infrastructure (read-only)                │
│  Config · Database · Auth · View/e() · Router · Module ·   │
│  Settings · Mailer · Upload                                │
└──────────────────────────────────────────────────────────┘
              ▲ modules may only CALL core, never edit it
┌──────────────────────────────────────────────────────────┐
│  modules/<name>/  — YOUR feature code                      │
│  <Name>Module.php · routes.php · migrations/ · views/ ·    │
│  SKILL.md                                                  │
└──────────────────────────────────────────────────────────┘
```

`core/` is the security surface. It's designed so the unsafe thing simply
doesn't exist — there is no raw-SQL method to reach for, escaping is a
one-character call, and auth/CSRF live in exactly one place. Modules are where
all features live, and **each module is independently removable**: turning it
off in the installer means its class is never loaded and its routes never
register.

## Request lifecycle

Every public request is routed by the web server to `public/index.php` (the
front controller). Here is the whole boot, in order
([`public/index.php`](../public/index.php)):

1. **Load core.** `require_once` each `core/*.php` class. No autoloader — the
   set is small and fixed.
2. **Load config.** `Config::load(__DIR__ . '/../.env')` parses `.env` into a
   static store. Missing `.env` throws immediately with a clear message.
3. **Set error visibility from `.env`.** `APP_DEBUG=true` shows errors;
   otherwise display is off and errors are logged to
   `storage/logs/php-error.log`. Production never shows a stack trace.
4. **Boot the session.** `Auth::bootSession()` starts a session with
   `HttpOnly` + `SameSite=Lax`, and `Secure` when `APP_ENV=production`.
5. **Send security headers.** `View::sendSecurityHeaders()` emits
   `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, a
   `Content-Security-Policy`, and HSTS in production.
6. **Build the router** and **load enabled modules.** The enabled list comes
   from `config/modules.php` (written by the installer). For each name:
   - The kebab-case folder name is converted to a PascalCase class name
     (`contact-form` → `ContactFormModule`).
   - The module file is `require_once`d, instantiated, and asked to register its
     routes via `$module->routes($router)`.
7. **Dispatch.** `$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'])`
   matches the path and calls the handler, or renders the styled 404.

```
Browser → public/index.php
            ├─ load core/*  →  Config::load(.env)  →  error mode
            ├─ Auth::bootSession()  →  View::sendSecurityHeaders()
            ├─ for each module in config/modules.php:
            │       require class  →  new  →  routes($router)
            └─ Router::dispatch(method, uri)
                    ├─ exact match   → handler([])
                    ├─ {param} match → handler(['slug' => …])
                    └─ no match      → View::render(resources/404.php)
```

## Routing

`core/Router.php` is a flat method+path table with two match modes:

- **Literal:** `$router->get('/blog', $handler)` — matched first, by hash lookup.
- **Parameterised:** `$router->get('/blog/{slug}', $handler)` — `{name}`
  segments become named captures and are passed to the handler as an assoc
  array: `function (array $params) { $params['slug']; }`. A segment matches
  anything except `/`, so `/blog/{slug}` matches `/blog/hello` but not
  `/blog/hello/extra`.

Only `GET` and `POST` are registered by convention. State changes are POSTs
guarded by CSRF (see [Security](security.md)). Unmatched routes render
`resources/404.php` *through the normal View pipeline*, so the 404 inherits the
site's real layout and branding instead of appearing bare.

## How a module is structured

A module folder is self-contained:

| File | Role |
|---|---|
| `<Name>Module.php` | Class extending `Module`. Declares `name()`, `label()`, `routes()`, `migrations()`, optional `dashboardNavItem()`. Holds the handler methods. |
| `routes.php` | `require`d from `routes()`; `$router` and `$this` are in scope. Maps URLs to handler methods. |
| `migrations/*.sql` | Trusted schema files run once at install, in filename order. |
| `views/*.php` | Presentational templates rendered via `View::render()` or the admin shell. |
| `SKILL.md` | The module's contract/rules — for humans and AI agents. |

See [Building a Module](building-a-module.md) for the full walkthrough, and
[Core API](core-api.md) for the methods handlers call.

## The two layouts

There are **two** page shells, by design:

- **Public shell** — [`resources/layout.php`](../resources/layout.php). Wraps
  everything rendered by `View::render()`. Header, nav, footer, branding. Fully
  restyleable; lives outside `core/`.
- **Admin shell** — `modules/admin-dashboard/views/layout.php`, applied by
  `AdminDashboardModule::renderAdmin()`. Every `/admin/*` page in every module
  renders through it, so the sidebar nav is aggregated in one place from each
  module's `dashboardNavItem()`.

## Migrations & install

There is no runtime migration runner. Migrations are executed **once, by the
installer**: the shared `core/migrations/000_create_site_settings.sql` plus each
enabled module's `migrations()` files, in order, via
`Database::runMigrationFile()`. The `auth` module owns `users` and
`password_resets`; each content module owns its own table. After a successful
install, `config/installed.lock` exists and `config/modules.php` lists the
enabled modules.

To add a column later, add a new numbered `.sql` file to that module's
`migrations/` folder and apply it to existing databases yourself (e.g. via
phpMyAdmin) — the installer only runs on first install.

## What is deliberately NOT here

- No ORM — `Database` exposes typed helpers over PDO prepared statements, nothing more.
- No template engine — views are plain PHP with the `e()` escape helper.
- No autoloader / Composer requirement at runtime — PHPMailer is the only vendored dependency.
- No raw-SQL escape hatch — this is a security feature, not an omission.

That minimalism is the point: it runs on the cheapest shared host, and the whole
system is small enough to hold in your head.
