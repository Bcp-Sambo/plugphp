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
- [ ] `FORCE_HSTS=false` until SSL is confirmed working on this exact domain,
      then `true`. Turning it on before the certificate is issued makes the
      site unreachable over HTTP with no visible error — the usual way a fresh
      subdomain bricks itself
- [ ] `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` — the host's database
- [ ] `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` / `SMTP_ENCRYPTION`
      / `SMTP_FROM_EMAIL` / `SMTP_FROM_NAME` — required for the contact form
      and password-reset emails (leave blank and those features silently no-op)
- [ ] `CONTACT_TO` — where contact-form notifications go (blank = `SMTP_FROM_EMAIL`)
- [ ] `APP_KEY` — generated by the installer. Encrypts secrets stored from the
      dashboard. Never copy one between sites, and never commit a real value.
      Changing it means re-entering the SMTP password in admin → Settings

> Note on `.env` values: `core/Config.php` uses a minimal parser. Avoid a
> `DB_PASS` (or any value) with **leading/trailing spaces or a leading/trailing
> quote character** — they are trimmed on read. Interior spaces, `#`, `=`,
> quotes and backslashes are fine.

---

## 4d. Deploying to a subdomain or a subfolder

A main-domain deploy and a subdomain deploy are **different mount locations**,
and cPanel treats them differently. This section exists because a real
subdomain deploy once cost two hours of debugging that all traced back to the
same root cause.

**Run `health.php` first.** Upload the project, then open
`https://your-domain/health.php` before anything else. It checks the nine
things that actually go wrong, in plain language, and tells you which one is
blocking you. Delete it once the site is live.

### Document root — the one that matters

On a main domain you drop the contents of `public/` into `public_html/` and it
works by habit. On a **subdomain**, cPanel creates a separate document root
(e.g. `public_html/index/`), and it is very easy to leave that pointing at the
project root instead of `plugphp/public/`.

When that happens the server tries to serve the project root, where there is
no `index.php`. You get a directory listing or a 404 — and, worse, `core/` and
`.env` become reachable over the web.

- **Preferred:** cPanel → *Domains* → set the subdomain's document root to the
  `public/` folder. `health.php`'s "Document root" check confirms it.
- **Fallback:** if your host will not let you repoint it, the project-root
  `.htaccess` shipped with PlugPHP forwards traffic into `public/` and returns
  403 for `core/`, `config/`, `storage/`, `modules/`, `resources/`, `vendor/`
  and `.env`. This is safe, but it is the second choice — verify each of those
  paths returns 403 before going live.

> If the document root was ever pointing at the project root on a live,
> internet-reachable domain, treat `.env` as compromised: rotate the database
> password and any SMTP credentials after fixing it.

### Subfolders work without code changes

PlugPHP detects the URL prefix it is served under and applies it to every
internal link, asset and redirect automatically. A site at
`example.com/mysite/` needs no code edits. `health.php`'s "Detected base path"
check shows what it detected.

Set `APP_URL` to the site's **full public root including the subfolder**
(`https://example.com/mysite`) — `APP_URL` is what canonical tags, Open Graph
tags, sitemap entries and password-reset links are built from.

### The quieter subdomain traps

- **PHP version.** cPanel MultiPHP can give a subdomain a different PHP version
  than the main domain. Code that runs fine on the main site can fail here for
  reasons unrelated to the deploy. `health.php` reports the version this
  domain actually runs.
- **SSL not issued yet.** A fresh subdomain often has no certificate for its
  first minutes or hours. Leave `FORCE_HSTS=false` until `https://` is
  confirmed working on that exact domain — sending HSTS before the certificate
  exists tells the browser to refuse plain HTTP, and the site becomes
  unreachable with no visible error.
- **`APP_URL` copied from the last deploy.** If `.env` still names the previous
  host, canonical tags, Open Graph tags, sitemap URLs and password-reset links
  all point at the wrong domain. `install.php` now pre-fills `APP_URL` from the
  address you open it on; `health.php` warns if it stops matching.

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
- [ ] **`health.php` reports no FAIL.** Open `https://your-domain/health.php`
      and resolve every FAIL before going live (WARNs on HTTPS and `APP_URL`
      are expected to clear once SSL is issued and `APP_URL` is correct).
- [ ] **Links work from the real mount point.** If this is a subdomain or a
      subfolder, click through the nav, open a content detail page, and log in
      — assets, links and post-login redirects must all stay under the right
      prefix. See §4d.
- [ ] **`FORCE_HSTS` turned on only after SSL is confirmed.** Set it to `true`
      in `.env` once `https://` is verified working on this exact domain, not
      before.
- [ ] `install.php` is deleted (`https://your-domain/install.php` → 404).
- [ ] `health.php` is deleted (`https://your-domain/health.php` → 404).
- [ ] Send a test message through `/contact` (once SMTP is set) and confirm the
      notification email arrives and the row appears under admin → Messages.

---

## 5a. Site settings (admin → Settings)

Three things the site owner can set without touching a file.

### Branding

Site name, description, logo, favicon and the default share (Open Graph)
image. The name and description feed the page title, the meta description and
the structured data; a page that sets its own description keeps it, and the
site-wide one is only the fallback.

Images go through the same validator as every other upload — real MIME sniff,
re-encode, random filename, no execute bit. Favicons are PNG only: GD cannot
re-encode an ICO, so accepting one would skip the step that makes uploads
safe, and every browser in current use supports PNG favicons.

### Email (SMTP)

Set here, these override `.env`. Leave them blank and `.env` keeps working, so
a fresh install sends mail before anyone opens this page.

Presets fill in the host, port and username for Resend, Gmail, SendGrid and
Mailgun — you still supply your own password or API key. All four are ordinary
SMTP relays, so nothing provider-specific runs.

The password is **encrypted before it is stored**, using the `APP_KEY` in
`.env`. It is never shown back to you; leave the field blank to keep the saved
one. If the site has no `APP_KEY` and `.env` is not writable, the password is
refused rather than stored in the clear, and the page shows the exact line to
add.

**Test Connection** sends a real email to your own admin address. If it fails,
the page names the likely causes and the exact SMTP error goes to
`storage/logs/`. The commonest causes are a wrong key, a port the host blocks
(many block 25), or a from-address the provider has not verified.

### Tracking

Paste a Google Analytics measurement ID or a Facebook Pixel ID; no template
edit needed. A master switch turns both off without losing the IDs.

IDs are validated before they are saved — a malformed one is rejected with an
explanation rather than stored. This matters more than it looks: tracking IDs
are written inside a `<script>` block, so an unchecked value would be a way to
run arbitrary JavaScript on every page of your site.

**While tracking is off, no third-party script loads and the site's content
security policy stays at its strictest.** Turning it on permits exactly the
Google or Facebook hosts needed, and nothing else — a script from any other
origin is still refused by the browser.

---
## 5b. Team access and the message inbox

### Two roles

**Administrator** — everything: settings, mail, tracking, updates, users, all
content. **Editor** — content modules and the message inbox only; no access to
settings, updates or user management.

Every account that existed before roles were introduced is an administrator, so
nothing changes on an existing site until you add someone.

Add people under **admin → Users**. You set a temporary password and pass it on;
ask them to change it via *Forgot password* once they have signed in.

**You cannot remove your last administrator.** Deleting one, or demoting one to
Editor, is refused when it would leave the site with none — that state is only
recoverable by editing the database directly. Promote a second administrator
first. The interface greys out both options and says why.

Changing someone's role takes effect on their next click, not their next login.
Deleting an account ends its session immediately.

### The message inbox

**admin → Messages** is an inbox rather than a log. Messages are `New` until
opened, then `Read`, then `Replied` once you answer from the dashboard. Both
roles can use it.

Replying sends through the site's configured mail settings and quotes the
original underneath. A message is only marked `Replied` when the send actually
succeeds — if mail is misconfigured it stays unanswered and tells you why, so
you can fix the settings and retry rather than losing track of it.

### Spam

The contact form carries a hidden field that real visitors never see. Anything
that fills it is automated: the submission is discarded silently, with no row
stored and no email sent. Combined with the existing per-IP rate limit, this
needs no CAPTCHA and no third-party service.

If you restyle the contact form, **keep that field and its attributes** — the
notes in `modules/contact-form/SKILL.md` explain why each one matters.

---
## 5c. Keeping the site updated

Once live, PlugPHP can update itself from **admin → Updates**. Nothing is ever
applied automatically: there is no cron entry point, and every update is an
explicit click.

**What an update changes.** PlugPHP's own code only — `core/`, each module's
logic and routes, new migrations, and `public/index.php`. It never touches your
page designs (`resources/` and each module's `views/`), your `.env`, your
enabled-module list, or your `.htaccess` files. A package containing anything
outside that set is rejected whole rather than partially applied.

**What happens when you click Update.**

1. Checks this host can take an update — writable files, the `zip` extension,
   a way to download, free disk space. If any check fails nothing is
   downloaded.
2. Downloads the package and verifies it against the SHA-256 the update server
   published. A mismatch aborts unconditionally and deletes the download; no
   retry bypasses it.
3. Confirms every path in the package is one an update may modify.
4. Backs up the current version of each file about to change.
5. Applies the files, then runs any new migrations.

If a file cannot be written partway through, the update stops and restores the
backup automatically.

**Rolling back.** For 7 days after an update a Roll back button restores the
previous code files. Database changes are *not* reversed — migrations only move
forward, and undoing them would destroy data. New columns simply go unused.

**Two things the updater cannot do for you**, both of which appear in a
release's notes when they apply:

- **Change a view or `resources/layout.php`.** Those are your design work, so
  an update will never overwrite them. If a release improves a shipped view,
  the notes describe the change for you to apply by hand.
- **Update `vendor/`.** Dependency fixes — a PHPMailer security release, say —
  need a full manual redownload of the kit.

**If the panel reports a version mismatch**, an update applied its files but
did not finish its database changes. The Finish the interrupted update button
runs only the outstanding migrations; it downloads nothing.

**Backups** live in `storage/backups/`, capped at the 3 most recent. They are
not a substitute for your host's backups — take a full backup before a major
update.

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
