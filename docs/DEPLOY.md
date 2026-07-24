# Deploying PlugPHP to shared / cPanel hosting

An agent-readable checklist. Two flows are covered:

- **Flow A — Fresh install on the host.** Upload files, run `install.php` on
  the server, let it build the database. Use this for a brand-new site (and
  for a first deploy-and-test run).
- **Flow B — Move an existing database.** Export the DB you built locally and
  import it via phpMyAdmin. Use this when you already have content to carry
  over (e.g. the real WordPress-content migration).

Both flows share the same **pre-flight**, **web-root**, and **verification**
steps. Do them in order; each `[ ]` is a checkpoint.

---

## 0. Requirements on the host

- [ ] PHP **8.0 or newer** (cPanel → *MultiPHP Manager* / *Select PHP Version*).
- [ ] MySQL **5.7.8 or newer** — the `projects` module uses a native `JSON`
      column. If the host is older and you need projects, confirm the version
      first; otherwise skip the projects module in the installer.
- [ ] PHP extensions enabled: `pdo_mysql` (required), `gd` (image uploads),
      `mbstring`, `fileinfo` (cPanel → *Select PHP Version* → Extensions).
- [ ] `mod_rewrite` available (standard on cPanel/Apache).

---

## 1. Pre-flight (do this locally, before uploading)

- [ ] Install PHPMailer so `vendor/` exists:
      ```bash
      composer install
      ```
      `core/Mailer.php` requires `vendor/autoload.php`. If you can't run
      Composer on the host, you **must** upload the generated `vendor/` folder.
- [ ] Do **not** upload a local `.env`. The installer writes `.env` on the host
      (Flow A), or you create it by hand (Flow B). Only `.env.example` ships.
- [ ] Make sure hidden files upload too (the `.htaccess` files and, later,
      `.env`). In cPanel File Manager enable *Settings → Show Hidden Files*;
      in an FTP client enable "show hidden files".

---

## 2. Upload the files

- [ ] Upload the whole project (all of `core/`, `modules/`, `resources/`,
      `config/`, `storage/`, `public/`, `vendor/`, `composer.json`,
      `.env.example`, and every `.htaccess`) to the account, e.g. into
      `~/plugphp` — **above** the public web folder, not inside it.
- [ ] Confirm `storage/logs/` exists and is writable (0755). The production
      error log is written there.

---

## 3. Point the web root at `public/` — the #1 gotcha

PlugPHP's only web-exposed directory is `public/`. Everything else (`.env`,
`core/`, `config/`, `storage/`) must stay **outside** the document root.

Pick ONE:

- **Option 1 (recommended): set the document root to the `public/` folder.**
  - Subdomain / addon domain: cPanel → *Domains* → set *Document Root* to
    `~/plugphp/public`.
  - Main domain: some hosts let you edit the main domain's document root; if
    so, point it at `~/plugphp/public`.
- **Option 2 (fallback): make `public_html` be the contents of `public/`.**
  - Upload the app folders (`core/`, `modules/`, `config/`, `storage/`,
    `resources/`, `vendor/`) to `~/` (one level **above** `public_html`).
  - Upload the **contents** of `public/` (its `index.php`, `install.php`,
    `router.php`, `.htaccess`, `uploads/`) directly into `public_html/`.
  - This works because `public/index.php` references `__DIR__ . '/../core'`,
    which resolves to `~/core` — exactly one level up from `public_html`.

> ⚠️ Do **not** set the document root to the project root. If you do, `.env`,
> `config/`, and `storage/` become web-reachable. The bundled defense-in-depth
> `.htaccess` files block the worst of it, but the correct setup is docroot =
> `public/`.

---

## 4a. Flow A — Fresh install on the host

- [ ] Create an empty MySQL database + user in cPanel → *MySQL Databases*, and
      **add the user to the database with ALL PRIVILEGES**.
- [ ] Visit `https://your-domain/install.php`.
- [ ] Work through the installer:
      - Environment check — resolve any **FAIL** before continuing.
      - Module picker — `admin-dashboard` and `auth` are always on.
      - Site name + **Site URL = your real `https://` domain** (not localhost).
      - Database host (usually `localhost` on cPanel), name, user, password.
      - First admin name / email / password (min 8 chars).
- [ ] The installer tests the DB, runs migrations, creates the admin, and
      writes `.env` + `config/modules.php`.
- [ ] **Delete `public/install.php`** (File Manager → delete). Confirm
      `https://your-domain/install.php` now returns 404.
- [ ] Skip to **§5 Verify**.

---

## 4b. Flow B — Move an existing database

Use this to carry over data you built on another environment.

- [ ] Export the source database. From a machine with the MySQL client:
      ```bash
      mysqldump -u USER -p --single-transaction --default-character-set=utf8mb4 SOURCEDB > plugphp.sql
      ```
      (Or export it from the source phpMyAdmin as a UTF-8 SQL dump.)
- [ ] On the host, create an empty database + user (cPanel → *MySQL Databases*,
      grant ALL PRIVILEGES).
- [ ] Import via cPanel → *phpMyAdmin* → select the new DB → *Import* → upload
      `plugphp.sql`. For large files use the *Import* file field or split the dump.
- [ ] Create `.env` on the host by copying `.env.example` to `.env` and filling
      in **production values** (see §4c). Place `.env` in the project root
      (the parent of `public/`), never inside `public/`.
- [ ] Create `config/modules.php` listing the modules this site uses, e.g.:
      ```php
      <?php
      return ['about', 'services', 'projects', 'blog', 'contact-form', 'auth', 'admin-dashboard'];
      ```
- [ ] Continue to **§5 Verify**.

---

## 4c. Production `.env` values (both flows)

Whether written by the installer or by hand, confirm `.env` has:

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`  ← errors are logged to `storage/logs/`, never shown
- [ ] `APP_URL=https://your-domain`  ← drives canonical / Open Graph URLs; must
      be the real domain, or those tags leak the wrong host
- [ ] `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` — the host's database
- [ ] `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` / `SMTP_ENCRYPTION`
      / `SMTP_FROM_EMAIL` / `SMTP_FROM_NAME` — required for the contact form
      and password-reset emails (leave blank and those features silently no-op)
- [ ] `CONTACT_TO` — where contact-form notifications go (blank = `SMTP_FROM_EMAIL`)

> Note on `.env` values: `core/Config.php` uses a minimal parser. Avoid a
> `DB_PASS` (or any value) with **leading/trailing spaces or a leading/trailing
> quote character** — they are trimmed on read. Interior spaces, `#`, `=`,
> quotes and backslashes are fine.

---

## 5. Verify (do not skip)

- [ ] **HTTPS is active.** Enable cPanel *AutoSSL* first. Admin login will NOT
      persist over plain HTTP — the session cookie is `Secure`-only in
      production, so the browser won't send it back over HTTP. Test login only
      after `https://` works.
- [ ] Home page loads: `https://your-domain/`.
- [ ] Clean URLs work: `/login`, and any enabled content route (`/blog`,
      `/services`, `/projects`). If these 404, `mod_rewrite` / `public/.htaccess`
      isn't active or the document root is wrong (see §3).
- [ ] Log in at `/login`, reach `/admin`, confirm the sidebar shows the
      installed modules.
- [ ] **No localhost leaked into output.** View source on the home page and a
      content page; every `<link rel="canonical">` and `og:url` must use your
      real `https://` domain — never `localhost` or `127.0.0.1`. If they do,
      fix `APP_URL` in `.env`.
- [ ] **Error display is off.** Browsing the site shows no PHP warnings/notices.
      Confirm `APP_DEBUG=false`; errors should appear in
      `storage/logs/php-error.log`, not on screen.
- [ ] **Secrets are not web-reachable.** All of these must return 403/404, not
      contents:
      - `https://your-domain/.env`
      - `https://your-domain/storage/logs/php-error.log`
      - `https://your-domain/config/modules.php`
- [ ] `install.php` is deleted (`https://your-domain/install.php` → 404).
- [ ] Send a test message through `/contact` (once SMTP is set) and confirm the
      notification email arrives and the row appears under admin → Messages.

---

## 6. Performance (optional, from the PRD)

- [ ] Put the site behind Cloudflare's free tier (CDN + caching) once it's live.
- [ ] Run PageSpeed Insights and compare against the Perth Partner baseline
      (Performance ≥ 90 mobile, SEO/Best-Practices 100).

---

## Appendix A — Create a MySQL database in cPanel (step by step)

Most people don't run MySQL on their laptop. You don't need to: create the
database once in cPanel and point either the local installer (see the README's
Path B1) or the on-server installer (Flow A / B2) at it.

1. **Log in to cPanel** and open **Databases → MySQL® Databases** (on some hosts
   it's labelled *MySQL Database Wizard* — either works; the Wizard walks you
   through steps 2–4 in one flow).
2. **Create the database.** Under *Create New Database*, type a short name, e.g.
   `plugphp`, and click **Create Database**. cPanel prepends your account name,
   so the real database name becomes something like **`myacct_plugphp`**. Write
   the full name down.
3. **Create a database user.** Scroll to *MySQL Users → Add New User*. Pick a
   username (e.g. `plug`) → real name becomes **`myacct_plug`**. Use cPanel's
   **Password Generator** for a strong password and **save it** — you can't read
   it back later.
4. **Attach the user to the database with all privileges.** Under *Add User To
   Database*, choose your user and your database, click **Add**, then on the
   privileges screen tick **ALL PRIVILEGES** and **Make Changes**. Skipping this
   is the #1 reason the installer reports "access denied".
5. You now have the three values the installer needs:
   - **DB_NAME** = `myacct_plugphp`
   - **DB_USER** = `myacct_plug`
   - **DB_PASS** = the password from step 3

**Which `DB_HOST` do I use?**

- **Installing on the server** (Flow A / README Path B2): `DB_HOST` is almost
  always **`localhost`**.
- **Developing locally against the cPanel database** (README Path B1): `DB_HOST`
  is your server's hostname/IP, and you must first authorise your own IP:
  open cPanel → **Remote MySQL**, add the IP shown at <https://ifconfig.me> as an
  *Access Host*. (Home IPs can change — re-add it if the connection later fails.)
  Use the server hostname from cPanel → *Server Information*, or the server IP.

> Security note: create a **dedicated** user per site with only that database's
> privileges — never reuse your cPanel master account. Delete the Remote MySQL
> access host again once the site is installed on the server, if you no longer
> develop against it remotely.

---

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| 500 on every page | `.env` missing or bad DB creds; `vendor/` not uploaded; PHP < 8.0. Check `storage/logs/php-error.log`. |
| 404 on `/login`, `/blog`, etc. | `mod_rewrite` off, `public/.htaccess` not uploaded (hidden file), or docroot not `public/` (see §3). |
| Home page fine, admin login won't "stick" | Not on HTTPS — the session cookie is `Secure`-only in production. Enable SSL. |
| `projects` migration failed during install | MySQL older than 5.7.8 (no `JSON` column). Upgrade MySQL or don't install the projects module. |
| Contact form / password reset send nothing | SMTP not configured in `.env`. |
| Canonical / OG tags show localhost | `APP_URL` in `.env` still points at localhost — set the real domain. |
| Local install can't reach cPanel DB ("connection refused"/timeout) | Remote MySQL not authorised: add your current IP (<https://ifconfig.me>) under cPanel → *Remote MySQL*. Confirm `DB_HOST` is the server hostname/IP, not `localhost`. |
