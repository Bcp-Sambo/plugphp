# PlugPHP — Design brief / prompt for Claude (design)

Copy everything in the block below into Claude and attach or reference the live
style reference: https://plugphp.bubblebotsolutions.com/

---

You are designing the complete UI for **PlugPHP**, a modular PHP starter kit's
default **business-website template** plus its **admin dashboard**. Produce a
beautiful, cohesive, high-fidelity design system and screen designs.

## 0. Visual reference — match this aesthetic
Match the design language of **https://plugphp.bubblebotsolutions.com/**:
- Stark, high-contrast, **developer-minimal**. Light theme on white/off-white.
- Near-black charcoal text on white; generous whitespace; typography-driven.
- Geometric/modern **sans-serif**; clear type hierarchy; occasional **numbered
  labels** (e.g. "01 / Services") as a motif.
- **Hairline (1px) borders**, minimal-to-no shadows, **no gradients**, no
  rounded-heavy "SaaS" look. Buttons are crisp — solid dark primary + ghost/
  outline secondary. Radius small (0–6px), consistent.
- Introduce **one restrained accent color** for links, focus states, and
  primary actions only (keep ~90% of the UI monochrome). Pick something
  tech-forward (e.g. an electric blue or violet); use it sparingly.
- Mood: professional, precise, confident, uncluttered, fast.

Deliver a small **design system first** (color tokens with hex, type scale,
spacing scale, button/input/card/table specs, focus style), then every screen.

## 1. Product context
This is the theme a site owner gets out of the box. Two surfaces:
- **Public site** (a business/agency site: Home, About, Services, Projects,
  Blog, Contact). Branding areas use **placeholder logo/wordmark** ("Your
  Brand") — this is a template, not a specific client.
- **Admin dashboard** — its own shell (sidebar), visually related to the public
  site but denser and utilitarian. Marked noindex.

A small attribution line — **"Built with PlugPHP — by Kabiru Sambo / Bubble Bot
Solutions"** — appears in exactly three places: the About page, the 404 page,
and the admin dashboard footer. Nowhere else.

## 2. Hard technical constraints (the design MUST honor these)
- **Server-rendered vanilla PHP**, no SPA. Design must work as plain HTML/CSS.
  No client-side framework. Avoid designs that depend on JavaScript to
  function — any JS is progressive enhancement only.
- **Strict CSP**: no external CDNs, no external fonts, no inline `<script>`.
  Use a **system font stack** (system-ui / a geometric system sans) or a
  **self-hosted** font. Do NOT use Google Fonts via CDN. Inline CSS is allowed.
- **Accessibility (scored by Lighthouse, required):** exactly **one `<h1>` per
  page**, no skipped heading levels; every form control has a visible
  `<label for>`; every image has real `alt` text; visible keyboard focus
  states; sufficient contrast (WCAG AA).
- **Images:** use **placeholder blocks only** (neutral gray with a small icon +
  "Image" label), never real photos. Every image slot must show an **explicit
  aspect ratio / width+height** so there's no layout shift (CLS). Note the ratio
  on each (e.g. 16:9, 1:1).
- **Responsive, mobile-first.** Design desktop + mobile for every page; the
  public template should score well on mobile PageSpeed.
- Lazy-load below-the-fold images.

## 3. Global components
- **Public header / nav:** placeholder wordmark on the left; nav links: Home,
  About, Services, Projects, Blog, Contact; a subtle primary CTA (e.g.
  "Contact"). Mobile: hamburger → accessible menu (CSS/`<details>`-based, no JS
  dependency).
- **Public footer:** columns (nav, brief blurb, maybe contact). No PlugPHP
  attribution here.
- **Admin shell:** left **sidebar** with nav items (Dashboard, Services,
  Projects, Blog, Messages, Users) — the active item is highlighted; a **Log
  out** button; a top bar with page title; main content area; footer with the
  "Built with PlugPHP…" attribution. Show a "skip to content" link.

## 4. Public pages (design each, desktop + mobile)

**Home (`/`)** — hero: site name + one-line tagline + primary CTA to Contact
(optional hero image placeholder, 16:9). Then a **"What we do"** section teasing
up to 3 services (each: title + one-line summary, linking to the service). Then
**"Latest from the blog"** teasing up to 3 posts (title + excerpt). Clean
section rhythm with the numbered-label motif.

**About (`/about`)** — one `<h1>` "About Us"; a mission section; a team section
(placeholder avatar blocks, 1:1, with names/roles); the PlugPHP attribution line
at the bottom.

**Services list (`/services`)** — heading + a grid/list of service cards. Each
card: an **icon** (small, from an icon set — show a placeholder glyph), title,
short summary, link to detail.

**Service single (`/services/{slug}`)** — `<h1>` title, summary lead paragraph,
long description body, optional icon. Clean long-form reading layout. (SEO tags
are auto-generated — don't design meta UI.)

**Projects list (`/projects`)** — portfolio grid of project cards. Each: a
**featured image** placeholder (4:3 or 16:9), title, one-line summary. Link to
detail.

**Project single / case study (`/projects/{slug}`)** — `<h1>` title; client
name; large featured image (16:9); summary; long description; an optional
"Visit project" outbound button; a **gallery** of image placeholders (grid of
1:1 or 4:3 thumbnails). This is the richest public page — make it shine.

**Blog list (`/blog`)** — list of posts, each: featured image (16:9), title,
published date (`<time>`), excerpt. **Pagination** control at the bottom (Newer /
Page X of Y / Older).

**Blog single (`/blog/{slug}`)** — `<h1>` title; published date; featured image
(16:9); long-form article body with comfortable reading measure (~65ch),
styled headings, lists, blockquotes.

**Contact (`/contact`)** — a form with three fields: **Name** (text), **Email**
(email), **Message** (textarea); a "Send message" button. Design the **success
state** ("Thanks — your message has been sent.") and inline **field-error**
states. Optional supporting column (address/hours placeholder).

**404** — themed not-found page consistent with the site; a short message, a
link home, and the PlugPHP attribution line.

## 5. Auth pages (centered, minimal, no public nav clutter)

**Login (`/login`)** — Email, Password; "Log in" button; "Forgot your password?"
link; a generic **error** state ("Invalid email or password.").

**Forgot password (`/forgot-password`)** — Email field; "Send reset link"
button; the **generic success** state ("If that email exists, a reset link has
been sent."); "Back to log in" link.

**Reset password (`/reset-password`)** — New password + Confirm new password;
"Update password" button. Also design the **invalid/expired-link** state ("This
reset link is invalid or has expired." + a link to request a new one).

*(Public self-registration is off by default; you may include an optional
Register screen — Name, Email, Password — styled to match, marked "optional".)*

## 6. Admin dashboard (its own shell; denser, utilitarian, still on-brand)

**Dashboard home (`/admin`)** — a row of **stat cards**: Users, Messages, Blog
posts, Services, Projects (each a count). Keep it calm and scannable.

**Services admin** — (a) **list**: table with Display order, Title, Slug,
Edit/Delete actions; an "Add new service" button; a **public-visibility toggle**
("Show services on the public site"). (b) **create/edit form**: Title, Slug
(optional — "generated from title if blank"), Summary, Description (large),
Icon (icon-set name/class), Display order (number), Meta title, Meta description.
(c) **delete confirmation** step (never one-click).

**Projects admin** — (a) **list**: Title, Client, Completed date, actions; add
button; visibility toggle. (b) **form**: Title, Slug, Client name, Summary,
Description (large), Project URL, Completed date (date picker), **Featured image
upload** (show current thumbnail + "replaces current"), **Gallery images**
(multiple upload; show existing thumbnails), Meta title, Meta description.
(c) delete confirmation.

**Blog admin** — (a) **list**: Title, Status (Draft/Published badge), Updated,
actions; add button; visibility toggle. (b) **form**: Title, Slug, Excerpt,
Body (large editor-style textarea — plain text, not rich HTML), **Featured image
upload**, Meta title, Meta description, **Published** checkbox (unchecked =
draft). (c) delete confirmation.

**Messages (`/admin/messages`)** — read-only table: Received, Name, Email,
Message, IP.

**Users (`/admin/users`)** — read-only table: Name, Email, Created.

Design shared admin patterns once: table style, form layout, buttons
(primary/secondary/danger), toggle switch, badge (Draft/Published), the
delete-confirmation pattern, empty states ("No posts yet"), and inline
validation errors.

## 7. Deliverables
1. **Design system**: color tokens (hex, incl. the single accent + states),
   type scale, spacing scale, radius/border rules, and specs for button, input,
   textarea, checkbox/toggle, card, table, badge, nav, focus ring.
2. **Every screen above**, high fidelity, **desktop + mobile**, light theme
   matching the reference, with **placeholder images** carrying explicit aspect
   ratios.
3. Prefer **self-contained responsive HTML/CSS** (system/self-hosted fonts,
   inline or `<style>` CSS, no external requests) so it's directly
   implementable on top of the existing semantic PHP templates — keep the
   markup semantic and accessible (one `<h1>`, `<label for>`, `alt`, `<time>`,
   `<nav>`, `<main>`, `<article>`, `<table>` with `<th scope>`).

Keep it beautiful but restrained — this should look like a natural, polished
extension of https://plugphp.bubblebotsolutions.com/.
