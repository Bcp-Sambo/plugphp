# PRD — PlugPHP
### A modular, agent-ready PHP starter kit for shared hosting

**Author:** Kabiru Sambo (Bubble Bot Solutions)
**Status:** Pre-build / architecture locked, pilot not yet started
**Last updated:** July 17, 2026

---

## 1. Problem Statement

WordPress developers — especially UI-first, Elementor-trained developers —
are facing a squeeze: Elementor's pricing and feature direction is trending
against developers' interests, and the broader WordPress ecosystem is
increasingly absorbing AI *into* itself (Automattic's Claude Cowork plugin,
Elementor's Angie) rather than giving developers a real alternative outside
of it.

At the same time, AI coding agents ("vibe coding") make it technically
possible for these developers to build outside WordPress — but only in
theory. In practice:

- They have no framework knowledge (Laravel, Symfony, etc.) and no time to
  learn one.
- They lose WordPress's biggest structural advantage: a plug-and-play
  plugin ecosystem that gives them auth, media, forms, e-commerce, and an
  admin UI for free.
- If they do try to vibe-code from scratch, a large share of their AI
  credits/context gets burned re-building the same boilerplate (routing,
  auth, DB layer, mailer) every single time, before ever touching the part
  they actually care about: the UI.
- The author has direct access to a community of **9,989 WordPress
  developers in Africa** who share GPL plugins/themes today and have a
  strong Elementor affinity — a real distribution channel with a real,
  shared version of this exact pain.

## 2. Goal

Give this community (and the author's own business, Bubble Bot Solutions)
a **modular vanilla-PHP starter kit** that:

1. Runs on the cheapest shared/cPanel hosting, no CLI required to operate.
2. Replicates WordPress's plug-and-play *feel* (install GUI, module picker,
   admin dashboard) without needing the WordPress core or its plugin risk
   surface (nulled plugins, bloat, licensing pressure).
3. Is explicitly **agent-ready** — ships with SKILL.md instruction files so
   an AI coding agent can install and extend it correctly without
   improvising, burning far fewer credits than building from zero.
4. Comes secure and SEO/performance-hardened by default, so a non-expert
   dev doesn't have to know to ask for any of that.

## 3. Non-Goals (explicitly out of scope for v1)

- Not a general-purpose PHP framework competing with Laravel/Symfony.
- Not aiming to replace WordPress for every use case — targets specific
  site types (business sites first; e-commerce/funnels are later
  templates, not v1).
- Not a hosted SaaS product — this is a self-hosted, downloadable kit.
- Not attempting full WordPress plugin-API compatibility.
- Auto-applying updates to a live production site — updates are
  surfaced, never silently auto-installed (see §9).

## 4. Naming, Branding, Licensing

- **Product name: PlugPHP** (locked in).
- **Ownership:** ships under Kabiru's personal name/brand, not under
  Bubble Bot Solutions directly. Rationale: the 9,989-dev community
  trusts Kabiru personally (his existing "Open-Source King" reputation),
  not a company brand. No planned "future acquisition" by Bubble Bot —
  that framing was explicitly rejected: an acquisition requires two
  separate parties, and open-source code isn't a sellable asset in that
  sense in the first place. Bubble Bot Solutions is the commercial funnel
  this project feeds (see §10), not a future legal buyer of it.
- **License: GPL-3.0.** Chosen deliberately over MIT because the actual
  goal is helping a resource-poor community — GPL's copyleft prevents
  someone from forking PlugPHP, closing it up, and reselling it back to
  the same community it was built for.
- **Attribution:** not legally mandatory (GPL doesn't support enforcing a
  visible on-screen credit as a hard requirement), but included as a
  soft convention — a small "Built with PlugPHP" line in the default
  admin footer and About page, same pattern as WordPress's own
  "Proudly powered by WordPress." Removable, but the social norm is to
  keep it.
- If stronger brand protection is wanted later: trademark the name/logo
  separately from the code license (legally distinct from GPL terms).

## 5. Target Users & Primary Pilot

- **Primary audience:** the 9,989-member WP-dev community (Africa),
  Elementor-affinity, GPL-plugin-sharing culture, largely non-framework-
  literate, increasingly experimenting with AI coding agents.
- **v1 pilot:** Kabiru's own Bubble Bot Solutions website — a business
  site with Home, About, Services, Projects (portfolio), Blog, Contact,
  Admin, Auth. This is deliberately the full v1 feature set, not a toy
  demo, so the pilot proves both the install flow and a real content
  migration (from the existing WordPress site).
- **Rollout sequencing (not yet started):**
  1. Build + dogfood on Bubble Bot Solutions' own site.
  2. Pilot with 5–10 trusted devs from the community.
  3. Public release to the wider 9,989, led with the real migration
     story, not a cold platform pitch.

## 6. Reference Case: Perth Partner

An existing vanilla-PHP + cPanel site (built prior to this project) that
proves the underlying approach works. Reported PageSpeed Insights scores:

| | Performance | Accessibility | Best Practices | SEO | Agentic Browsing |
|---|---|---|---|---|---|
| Mobile | 90 | 95 | 100 | 100 | 2/2 |
| Desktop | 99 | 95 | 100 | 100 | 2/2 |

PlugPHP's SEO/performance/accessibility defaults (§8) are designed so any
site built on the kit hits comparable numbers *by construction*, not by a
developer remembering to optimize afterward.

> Note: an open discrepancy exists in project memory about whether Perth
> Partner itself was vanilla PHP/cPanel or WordPress+AI. Worth confirming
> which is accurate, since it's cited as the proof-of-concept reference.

## 7. Architecture

### 7.1 Directory structure

```
plugphp/
├── install.php                 → web installer entrypoint
├── public/
│   ├── index.php                → front controller, only web-exposed entrypoint
│   └── uploads/                  → execute-permission disabled
├── core/                         → NOT optional, not agent-editable
│   ├── Config.php                 → .env loader
│   ├── Database.php               → PDO wrapper, only parameterized access, no raw-query path
│   ├── Auth.php                    → sessions, hashing, CSRF, password reset
│   ├── Mailer.php                  → SMTP wrapper (PHPMailer via Composer)
│   ├── View.php                    → e() escape helper, security headers, layout render
│   ├── Router.php                  → GET/POST route table + dispatch
│   └── Module.php                  → abstract base class every module extends
├── modules/
│   ├── admin-dashboard/            → always-on, not togglable
│   ├── blog/
│   ├── services/
│   ├── projects/
│   ├── contact-form/
│   ├── auth/                        → UI layer only, delegates to core/Auth.php
│   └── about/                        → static, no DB table (Option A, decided)
├── config/
│   └── modules.php                   → list of enabled modules, written by installer
├── .env.example
├── SKILL.md                          → master agent instruction file
└── README.md
```

Each module folder contains: `<Name>Module.php` (extends `Module`),
`routes.php`, `views/`, `migrations/*.sql` (if any), and its own `SKILL.md`.

### 7.2 Composer usage — deliberately scoped

"Vanilla PHP" does **not** mean zero Composer. Composer is used only for:
- PSR-4 autoloading (closes the "no enforced structure" gap partially —
  misplaced files simply fail to autoload, surfacing mistakes immediately).
- A small number of vetted libraries (PHPMailer confirmed; others added
  only when a clear need arises).

No framework is adopted on top. This keeps the "no CLI required to run
the site" property intact — Composer runs once during setup/build, not
as an ongoing operational dependency for the site owner.

### 7.3 What is/isn't structurally enforced

| Concern | How it's enforced |
|---|---|
| No raw SQL / injection | `Database.php` exposes no raw-query method at all — not a convention, a missing code path. |
| XSS via unescaped output | `e()` helper exists; views are expected to always use it. **This one is social/documentary, not code-enforced** — nothing stops a raw `echo`. |
| Consistent module shape | `Module` abstract base class — every module must implement `name()`, `routes()`, `migrations()`. |
| Auth/session/CSRF correctness | Centralized in `core/Auth.php`; modules call it, never reimplement it. |
| Misplaced files/namespaces | Composer's PSR-4 autoloader fails silently-loud (class not found) if folder/namespace don't match. |
| Module SKILL.md rules being followed | **Not code-enforced** — instructional only. An agent could still deviate; this is a known, accepted limitation, not a solved problem. |

## 7.4 Themeable 404 handling (resolved)

Perth Partner required manually customizing the 404 page to fit the
site's theme — not handled by default. PlugPHP solves this structurally:

- `resources/404.php` is rendered through the normal `View::render()`
  pipeline (not a raw, bare include), so it automatically inherits
  `resources/layout.php` — the site's real nav, styling, and branding —
  the moment `core/Router.php` fails to match a route.
- `resources/` (not `core/`) is where both `layout.php` and `404.php`
  live, specifically because these are meant to be freely restyled per
  site — unlike `core/`, which stays locked.
- Attribution ("Built with PlugPHP — by Kabiru Sambo / Bubble Bot
  Solutions") appears in exactly three defaults: the admin dashboard
  footer, the About page, and the 404 page — not the global layout
  footer, to avoid the credit appearing on every single page.

## 7.5 Per-module public visibility toggle (resolved)

Distinct from the install-time module picker (§7.1, which decides
whether a module exists at all), this is a **runtime** toggle: a site
owner who installed Blog might still want to hide its public pages
temporarily (not ready to publish, seasonal pause) without losing the
module, its data, or its admin panel.

- Backed by a single shared `site_settings` key/value table
  (`core/migrations/000_create_site_settings.sql`), wrapped by a small
  `core/Settings.php` helper — not a separate settings table per module.
- Enforced structurally, not just in the UI: a content module checks
  `Settings::isModuleVisible('<name>')` inside its own `routes()` method
  and simply skips registering its public GET routes when false. Because
  the route is never registered, `core/Router.php`'s existing 404
  fallback (§7.4) handles the "hidden" case automatically — no bespoke
  branch needed, and no risk of the page being reachable by direct URL
  while only hidden from a nav menu.
- Admin/dashboard routes for that module always stay registered
  regardless of the toggle — hiding the public side never blocks editing.
- Applies to `blog`, `services`, `projects` in v1; documented per-module
  in each module's own `SKILL.md`.

## 8. Security Requirements (baked in, non-optional)

- Prepared statements only, enforced at the `Database.php` layer.
- `password_hash()`/`password_verify()`, session regeneration on
  login/logout, CSRF token required on every state-changing route.
- Password reset: random token generated, only its **SHA-256 hash**
  stored with an expiry; raw token never persisted; rate-limited per
  user; generic response regardless of whether the email exists
  (prevents user enumeration).
- File uploads: extension whitelist + real MIME sniffing + re-encode +
  stored with execute permission stripped.
- Baseline HTTP security headers sent on every response: CSP,
  X-Frame-Options, X-Content-Type-Options, Referrer-Policy, HSTS in
  production.
- Debug mode strictly `.env`-controlled; errors never displayed in
  production, logged to file instead.
- Contact form and any other public POST route: rate-limited to guard
  against spam/abuse.

**Open, unresolved risk:** the community's existing GPL/nulled-plugin
sharing culture is a real supply-chain risk vector if PlugPHP modules end
up circulated the same informal way (modified copies passed around a
Telegram/Facebook group with no central review). Mitigation direction:
a single canonical source (the GitHub repo) and explicit community norms
against forwarding modified copies — not yet solved, flagged for the
distribution plan.

## 9. Update Mechanism

- A static `version.json` (hosted on GitHub, no server required) contains
  the latest version number and a `security_advisory: true/false` flag.
- The dashboard checks this file and shows a banner ("Update available" /
  "Security patch available") — same pattern as WordPress core.
- **No site data is sent** in the check — no telemetry, consistent with
  the community's sovereignty-minded expectations.
- **Updates are never auto-applied.** Shared hosting/cPanel realities mean
  file updates stay a manual, deliberate action (redownload, re-upload
  changed files) — silently overwriting files on hosting you don't
  control is unacceptable.

## 10. SEO / Performance / Accessibility / Agentic Browsing

Built into `core/` and module templates by default, not left to
per-developer discretion:

- **Performance:** server-rendered PHP (no SPA/bundle weight by
  construction), lazy-loaded images by default, browser caching headers,
  minified CSS/JS, documented Cloudflare-free-tier CDN step in the
  deploy checklist.
- **Accessibility:** semantic HTML baked into module view templates,
  required `alt` on all media-module images, `label`/`for` pairing
  enforced on all form fields, correct heading hierarchy.
- **Best Practices:** HTTPS enforced, correct viewport meta, no
  deprecated APIs in shipped templates.
- **SEO:** every content module (blog/services/projects) auto-generates
  meta title/description, canonical tag, Open Graph tags, and JSON-LD
  structured data (Article/Service/CreativeWork schema respectively)
  from its own DB fields — not hand-authored per page. Core-level
  helpers also auto-generate `sitemap.xml` and a default `robots.txt`.
- **Agentic Browsing** (Lighthouse 13.3+, pass-ratio category, checks:
  clean accessibility tree, low Cumulative Layout Shift, valid
  `llms.txt`): addressed via (a) the accessibility defaults above,
  (b) explicit width/height or aspect-ratio on all images to prevent
  CLS, (c) a default `llms.txt` template shipped at the repo root.

## 11. Module Specification Summary (v1 / Business Template)

| Module | DB-backed? | Dashboard panel? | Notes |
|---|---|---|---|
| `about` | No | No | Static content, Option A explicitly chosen over dashboard-editable Option B. |
| `blog` | Yes (`posts`) | Yes | Full CRUD, slugs unique-indexed, Article schema. |
| `services` | Yes (`services`) | Yes | Ordered by `display_order`, Service schema. |
| `projects` | Yes (`projects`) | Yes | Portfolio/case studies, gallery images as JSON column, CreativeWork schema. |
| `contact-form` | Yes (`contact_submissions`) | Optional | Rate-limited, CSRF-required, no raw header injection. |
| `auth` | Yes (`users`, `password_resets`) | Partial (user list) | UI layer only; all logic delegates to `core/Auth.php`. |
| `admin-dashboard` | N/A | N/A (is the shell) | Always-on; aggregates other modules' `dashboardNavItem()` entries. |

## 12. AI-Agent Instruction Layer (SKILL.md system)

- One root `SKILL.md`: defines what's editable vs. locked (`core/` is
  off-limits), the five hard rules (no raw SQL, always `e()`, never
  reimplement auth/session/CSRF, `Mailer::send()` only, CSRF on every
  state-changing route), plus frontend/SEO expectations.
- One `SKILL.md` per module, with module-specific schema, routes, and
  flagged risk areas (e.g. contact-form's calls out rate-limiting
  specifically, since it's the module most likely to be built carelessly).
- Explicitly acknowledged limitation: SKILL.md compliance is instructional,
  not enforced by code — a genuine gap between "the agent is told the
  rule" and "the agent cannot violate the rule," distinct from the
  code-level guarantees in §7.3.

## 13. Positioning & Distribution Strategy

- **Core pitch:** "burn dramatically fewer AI credits to build a real
  site" — reframes the value from "easier" (vague) to "measurably
  cheaper in the currency (AI credits) this specific audience already
  feels scarcity around."
- **Not** positioned as "leave WordPress" or an anti-Elementor crusade —
  leads with the author's own real migration story (Bubble Bot Solutions
  site) as proof, not a platform pitch.
- Sequenced distribution (§5): dogfood → trusted-pilot → community-wide.
- Monetization is indirect: PlugPHP itself stays free/open-source; it
  functions as a lead-generation funnel into Bubble Bot Solutions
  Pillar 2 (custom ERP builds) and related paid services (migrations,
  custom modules, hosting setup, support) — same pattern already used
  successfully with the Aprix project.

## 14. Template Roadmap (post-v1, sequenced by risk, not enthusiasm)

1. **Business template** (this PRD's scope) — prove core + modules work.
2. **Lead-gen / landing page template** — lowest complexity, reuses the
   existing Forms/Contact module almost entirely.
3. **Sales funnel template** — multi-step forms, email sequences, builds
   on the Mailer/SMTP layer already in place.
4. **E-commerce template** — highest risk (payments, cart, inventory).
   Deliberately last, and only after the payments-specific security
   concerns (webhook signature verification, server-side amount
   validation, idempotency) are explicitly solved — not before.

## 15. Known Open Risks (carried forward, not yet resolved)

- **No auto-update mechanism for security patches** beyond the manual
  banner-and-redownload flow in §9 — accepted trade-off for shared-hosting
  compatibility, not a solved problem.
- **SKILL.md compliance is not code-enforced** (§12) — an agent can still
  deviate from instructions; only a subset of rules (no raw-SQL path,
  centralized Auth) are truly structurally guaranteed.
- **Nulled-plugin-style supply chain risk** if community distribution
  drifts into informal file-sharing instead of a single canonical repo
  source (§8) — mitigation not yet designed in detail.
- **PHP version drift on shared hosting** (some hosts still run old PHP
  versions) — installer needs to detect and warn, not assume a modern
  environment; not yet built.
- **Perth Partner stack discrepancy** (§6) — should be confirmed before
  it's cited publicly as the proof-of-concept.
- **This remains a fourth concurrent front** alongside Aprix, ShopReliQ,
  and Bubble Bot's existing dual-pillar work, none of which are fully
  shipped yet. Explicitly scoped as a bounded, killable side-bet (build
  the pilot, evaluate, stop if it doesn't validate) rather than an open-
  ended fifth commitment.

## 16. Definition of Done for v1 Pilot

- [x] `core/` fully implemented and reviewed (this document reflects the
      current state — see accompanying `starterkit-core-and-skills.zip`).
- [x] Themeable default 404 page (§7.4) and per-module public
      visibility toggle (§7.5) — both resolved and documented in
      relevant `SKILL.md` files.
- [ ] `install.php` web installer built (module picker, DB credential
      form, first-admin-user creation) — **not yet built**.
- [ ] Migration SQL files for all seven v1 modules — **not yet built**.
- [ ] `config/modules.php` generation wired into the installer —
      **not yet built**.
- [ ] Bubble Bot Solutions' existing WordPress content migrated in as
      real fixtures, not placeholder data.
- [ ] Local install → cPanel deployment checklist executed end-to-end at
      least once, per the migration checklist drafted earlier (export DB,
      upload files, swap `.env` to production values, import SQL, verify
      no local paths leaked).
- [ ] PageSpeed Insights run against the live migrated site, compared
      against the Perth Partner baseline (§6).
