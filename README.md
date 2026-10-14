# PlugPHP

**A modular, agent-ready vanilla-PHP starter kit for shared / cPanel hosting.**
Upload it, open the installer in your browser, tick the modules you want — and
you have a complete, secure, SEO-ready website with an admin dashboard, running
on hosting that costs a few pounds a month.

By Kabiru Sambo / Bubble Bot Solutions. Licensed GPL-3.0-or-later.

---

## What PlugPHP is

PlugPHP sits in the gap between the two things most small-business sites get
built with, and it is deliberately neither of them.

WordPress gives you the plug-and-play experience — an installer, a dashboard, a
plugin for everything — and charges you for it in attack surface, update
anxiety, and a codebase nobody can fully read. A modern PHP framework gives you
clean architecture, and charges you in Composer, npm, a build step, a CLI on the
server, and a hosting bill to match.

PlugPHP takes the *feel* of the first and the *discipline* of the second:

- **The WordPress part** — a real web installer, a module picker, a working
  admin dashboard, demo content seeded so the site is never empty.
- **The framework part** — a locked `core/`, one folder per feature, every
  security control structural rather than optional, and plain PHP you can read
  end to end in an afternoon.

No framework. No Composer step. No npm. No build. No CLI access needed on the
server. You download a ZIP and it runs.

---

## What you get out of the box

A finished, populated website — not a blank skeleton:

| Module | What it gives you | Editable from the dashboard? |
|---|---|---|
| **Home** | Site root, with defensive teasers pulling from whichever other modules are on | Static |
| **About** | Standard about page | Static |
| **Services** | A list of what the business offers | ✅ |
| **Projects** | Portfolio / past work, with images | ✅ |
| **Blog** | Posts with slugs, featured images, Open Graph tags | ✅ |
| **Contact form** | Emails the owner, logs to an inbox, spam honeypot | ✅ (inbox + reply) |
| **Auth** | Login, registration, password reset, user management | ✅ |
| **Admin dashboard** | The shell every other module plugs its panel into | Always on |

Every content module is **independently removable** — untick it in the
installer, or delete its folder later, and nothing else breaks.

And in the dashboard itself:

- **Branding** — logo, site name, colours, meta description, applied site-wide
- **SMTP settings** — configure mail from the UI, with `.env` as fallback
- **Tracking pixels** — Google Analytics, Meta, etc., validated before saving
  and served under a nonce-based CSP
- **Messages inbox** — read and reply to contact submissions without leaving
  the site
- **Users and roles** — `admin` and `editor`, enforced on routes, not just hidden
  in the nav
- **One-click updates** — the site checks a release feed, shows what changed,
  and applies or rolls back a release itself

---

## Why you'd choose it

**It runs where your clients already host.** Shared cPanel hosting, PHP 8,
MySQL. No SSH, no Node, no Docker, no deploy pipeline. Point the document root
at `public/` and you're live.

**Security is structural, not advisory.** You cannot accidentally write an
injectable query, because no raw-query method exists in `core/Database.php`.
Output escaping is one character (`e()`). CSRF is required on every
state-changing route. Security headers go out on every response. Uploads are
validated, re-encoded, and neutralized. Stored secrets are encrypted. `.env`,
`core/`, `config/` and `storage/` sit outside the web root by design. The full
model is in [docs/security.md](docs/security.md).

**SEO-hardened by default.** Clean URLs, per-page titles and meta descriptions,
canonical tags, Open Graph with per-post `og:image`, and JSON-LD hooks — wired
through `core/View.php` so a module gets them right without thinking about it.

**It updates itself.** Most starter kits are a snapshot you fork and then
maintain alone forever. PlugPHP ships an updater: installed sites see new
releases, read the notes, and apply them from the dashboard.

**It is built to be extended by an AI agent.** Every folder carries a
`SKILL.md` — a versioned contract stating what an agent may and may not touch,
and which rules are non-negotiable. Point any coding agent at the repo and it
reads the same rules you do, instead of guessing. See
[docs/agents.md](docs/agents.md).

**You can read all of it.** No magic, no service container, no 400-dependency
tree. `core/` is fifteen small classes.

---

## Who it's for

- **Freelancers and agencies** building small-business sites on cheap hosting,
  who want to stop re-solving auth, mail, uploads and the admin panel
- **Developers who are done with WordPress** but whose clients still need a
  dashboard they can log into
- **Anyone using AI agents to build**, who wants guardrails the agent actually
  reads

Not for: high-traffic applications, anything needing queues, websockets or
horizontal scaling. It is a starter kit for brochure-and-content sites, and it
is honest about that.

---

## Requirements

- PHP **8.0+**
- MySQL **5.7.8+** / MariaDB 10.2+ (the projects module uses a native `JSON` column)
- Apache with `mod_rewrite` (standard on cPanel), or PHP's built-in server for local dev
- PHP extensions: `pdo_mysql` (required), `gd` (image uploads), `mbstring`, `fileinfo`
- **A MySQL database.** You do **not** need MySQL installed on your own machine —
  see [Don't have a database locally?](#dont-have-a-database-locally-use-cpanel) below.

`vendor/` (PHPMailer) is committed, so a plain **Download ZIP** from GitHub runs
with **no Composer step**. Only re-run Composer if you change dependencies.

---

## Get the code

Either clone it or grab the ZIP from GitHub:

```bash
git clone https://github.com/Bcp-Sambo/plugphp.git
cd plugPHP
```

The whole app is ready to run — nothing to build.

---

## Install

The installer is a web page (`public/install.php`). It checks your environment,
lets you toggle modules, tests the database connection, runs migrations, seeds
demo content, creates your first admin user, and writes `.env` +
`config/modules.php`. You just need to point it at a database.

Pick the path that matches you:

### Path A — You have MySQL / MariaDB locally

1. Create an empty database and a user with access to it (via the `mysql` CLI,
   TablePlus, Sequel Ace, phpMyAdmin, MAMP/XAMPP, etc.).
2. Serve `public/` with PHP's built-in server — **bound to `127.0.0.1` only,
   never `0.0.0.0`** (don't expose your dev box):
   ```bash
   php -S 127.0.0.1:8000 -t public public/router.php
   ```
3. Open the installer: <http://127.0.0.1:8000/install.php>
4. Enter `DB_HOST=127.0.0.1`, your database name, user, and password.
5. Finish the wizard, then **delete `public/install.php`**.

### Path B — You don't have a database locally? Use cPanel

**This is the recommended path for most people** — no local database software to
install at all. You create the database once in your hosting cPanel, then either
run the app locally against it, or install straight on the server.

1. **Create the database in cPanel** — follow the step-by-step walkthrough in
   **[docs/DEPLOY.md → Appendix A](docs/DEPLOY.md#appendix-a--create-a-mysql-database-in-cpanel-step-by-step)**.
   You'll end up with three values: database name, database user, and password
   (all prefixed with your cPanel account name, e.g. `myacct_plugphp`).
2. Then choose **B1** or **B2**:

   **B1 · Develop locally, database on cPanel** (what this template was built with)
   - In cPanel, open **Remote MySQL** and add your home/office IP address as an
     allowed access host (find your IP at <https://ifconfig.me>). This lets your
     laptop connect to the cPanel database.
   - Run the local server as in Path A step 2, open
     <http://127.0.0.1:8000/install.php>, and for the database use:
     - `DB_HOST` = your cPanel server's hostname or IP (e.g. `server123.host.com`
       or the shared-IP shown in cPanel → *Server Information*)
     - `DB_NAME` / `DB_USER` / `DB_PASS` = the three values from step 1.
   - Finish the wizard. You now develop locally with zero local database setup.

   **B2 · Install directly on the server** (fastest route to a live site)
   - Upload the project to your hosting account and point the domain's document
     root at the `public/` folder (see [Deploy](#deploy-to-shared--cpanel-hosting)).
   - Visit `https://your-domain/install.php` and enter the step-1 database
     values (on cPanel `DB_HOST` is almost always `localhost`).
   - Finish the wizard, then **delete `public/install.php`** and confirm it 404s.

After install the public site is at `/`, and the admin dashboard is at
`/login` → `/admin`. The installer auto-seeds demo pages, projects, and blog
posts so the site isn't empty — edit or delete them from the admin.

> ⚠️ **Always delete `public/install.php` once installed.** Leaving it in place
> lets anyone re-run the installer against your site.

---

## Deploy to shared / cPanel hosting

See **[docs/DEPLOY.md](docs/DEPLOY.md)** for the full production checklist. The
single most important point: the domain's **document root must be the `public/`
folder**, which keeps `.env`, `core/`, `config/`, and `storage/` out of the web
root. DEPLOY.md also covers HTTPS, production `.env` values, the security
verification pass, and troubleshooting.

---

## Documentation

Full developer documentation lives in **[docs/](docs/)**:

- [Getting Started](docs/getting-started.md) — install paths, first run, the dev loop
- [Architecture](docs/architecture.md) — request lifecycle, `core/` vs `modules/`, routing
- [Core API Reference](docs/core-api.md) — every `core/` class and method
- [Building a Module](docs/building-a-module.md) — add a feature end to end
- [Theming & Branding](docs/theming.md) — layout, CSS, logo, per-site branding
- [Security Model](docs/security.md) — the structural controls and their rules
- [Extending with an AI Agent](docs/agents.md) — the `SKILL.md` system
- [Deployment](docs/DEPLOY.md) — production checklist + creating a DB in cPanel

## Project layout

```
core/       Locked infrastructure — do not edit (Config, Database, Auth, Mailer,
            View, Router, Module, Settings, Upload).
modules/    One folder per feature; each is independently removable.
resources/  Public layout.php + 404.php + sample-content.php seed data.
config/     modules.php (enabled list) + install lock. Not web-served.
storage/    Logs. Not web-served.
public/     The ONLY web-exposed directory (front controller + assets + uploads).
vendor/     PHPMailer (committed so Download-ZIP runs without Composer).
```

## Extending it with an AI agent

Read `SKILL.md` (root) and the per-module `SKILL.md` files first — they encode
the hard rules (no raw SQL outside `core/`, always `e()` output, never
reimplement auth/CSRF, `Mailer::send()` only, CSRF on every state-changing
route). `core/` is read-only infrastructure.

## License

GPL-3.0-or-later. Built with PlugPHP — by Kabiru Sambo / Bubble Bot Solutions.
