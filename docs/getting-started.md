# Getting Started

This guide gets a PlugPHP site running locally and explains the day-to-day dev
loop. For production, see [DEPLOY.md](DEPLOY.md).

## Requirements

- **PHP 8.0+** with extensions `pdo_mysql` (required), `gd` (image uploads),
  `mbstring`, `fileinfo`.
- **MySQL 5.7.8+ / MariaDB 10.2+** — the `projects` module uses a native `JSON`
  column, which is the only hard version constraint.
- **A MySQL database.** You do *not* need MySQL installed on your own machine —
  see [Path B](#path-b--no-local-database-use-cpanel) below.
- `vendor/` (PHPMailer) is committed, so **no Composer step is required** to run
  the kit. Only re-run `composer install` if you change dependencies.

## Get the code

```bash
git clone https://github.com/<your-username>/plugphp.git
cd plugphp
```

Nothing to build — the app is ready to serve.

## Install

The installer is a web page, `public/install.php`. It checks your environment,
lets you toggle modules, tests the database connection, runs the migrations,
seeds demo content, creates your first admin user, and writes `.env` +
`config/modules.php`. You only need to give it a database.

### Path A — you have MySQL / MariaDB locally

1. Create an empty database and a user that can access it.
2. Serve `public/` with PHP's built-in server, **bound to `127.0.0.1` only**:
   ```bash
   php -S 127.0.0.1:8000 -t public public/router.php
   ```
3. Open <http://127.0.0.1:8000/install.php>.
4. Use `DB_HOST=127.0.0.1` plus your database name / user / password.
5. Finish the wizard, then **delete `public/install.php`**.

### Path B — no local database? Use cPanel

Create the database once in your hosting cPanel and point the installer at it —
no local database software needed. Full step-by-step:
[DEPLOY.md → Appendix A](DEPLOY.md#appendix-a--create-a-mysql-database-in-cpanel-step-by-step).
In short:

- **Develop locally against the cPanel DB:** add your IP under cPanel →
  *Remote MySQL*, then run the local server (Path A step 2) and point the
  installer's `DB_HOST` at the cPanel server hostname/IP.
- **Or install straight on the server:** upload the project, set the domain's
  docroot to `public/`, and open `https://your-domain/install.php`.

> ⚠️ **Always delete `public/install.php` once installed.** Left in place, it
> lets anyone re-run the installer against your site.

## After install

- Public site: `/`
- Admin: `/login` → `/admin`
- The installer seeds demo pages, projects, and posts so the site isn't empty —
  edit or delete them from the admin dashboard.

## The dev loop

Restart nothing — the built-in server picks up PHP changes on the next request.
Typical work:

- **Change how a page looks** → edit that module's `views/*.php` and the shared
  [`resources/layout.php`](theming.md).
- **Change site-wide styling** → edit `public/assets/css/app.css`.
- **Add a page or feature** → work inside a module (see
  [Building a Module](building-a-module.md)). Never edit `core/` or
  `public/index.php`.
- **Add config** → add a key to `.env` and `.env.example`, read it with
  `Config::get('KEY')`.

## Project layout

```
core/       Locked infrastructure — do NOT edit. Config, Database, Auth,
            Mailer, View, Router, Module, Settings, Upload (+ core migration).
modules/    One folder per feature; each is independently removable.
            <name>/<Name>Module.php, routes.php, migrations/*.sql, views/*, SKILL.md
resources/  Public layout.php + 404.php + sample-content.php (seed data). Restyle freely.
config/     modules.php (enabled list) + installed.lock. NOT web-served.
storage/    Logs. NOT web-served.
public/     The ONLY web-exposed directory: index.php (front controller),
            router.php (dev-server shim), install.php, assets/, uploads/.
vendor/     PHPMailer, committed so Download-ZIP runs without Composer.
```

## Where to go next

- Understand how a request flows → [Architecture](architecture.md)
- Learn the toolbox you're allowed to call → [Core API Reference](core-api.md)
- Add your own feature → [Building a Module](building-a-module.md)
