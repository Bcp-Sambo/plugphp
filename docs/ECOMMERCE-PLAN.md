# Ecommerce Core — implementation plan

Working plan for `ECOMMERCE-CORE-SPEC.md`, checked against the code as it
stands at **1.6.0**. Read root `SKILL.md` and `core/Updater.php` first.

**Headline:** the spec is buildable and well-specified, but it names one core
method that does not exist, and the absence of that method would make its
oversell protection *appear* to work while doing nothing. Separately, the
module layout it describes cannot be delivered by an update at all without a
release that precedes it.

---

## 1. Spec assumptions, checked

### 1.1 Correct

| Spec relies on | State |
|---|---|
| `Crypto::encrypt()` / `decrypt()` for gateway secrets | ✅ `core/Crypto.php` |
| `Upload::image()` for product images | ✅ `core/Upload.php:54` |
| `Mailer::send($to, $subject, $htmlBody)` | ✅ `core/Mailer.php:125` |
| `Settings` for `payment_mode` and gateway keys | ✅ shared `site_settings` |
| `Auth::requireRole()`, no new roles | ✅ use `Auth::ROLE_ADMIN`, not the string `'admin'` |
| Arbitrary SQL with bound params for `FOR UPDATE` | ✅ `Database::fetchOne/fetchAll` take SQL |
| Module activation runs migrations at that moment | ✅ shipped in 1.6.0 |

### 1.2 Does not exist

**`Database::transaction()` — the spec's §1 says "reuse of existing
`Database::transaction()`". There is no such method.** `core/Database.php`
exposes `fetchOne`, `fetchAll`, `insert`, `update`, `delete`,
`runMigrationFile`, `migrationWasApplied`. Nothing else.

This is the most dangerous gap in the spec, because of how it fails. `FOR
UPDATE` outside a transaction acquires a lock that is released immediately on
autocommit. Written as specified but without a real transaction, §4.2's
checkout would **pass a casual test and oversell under concurrency** — the
failure only appears with two simultaneous buyers, which is exactly when it
costs money and credibility.

`ECOMMERCE-FASTFOLLOW.md` has since been read and reconciled against this
plan — see §7. Three items come forward; the rest stay deferred.

### 1.3 Needs new core infrastructure the spec does not mention

- **An outbound HTTP client.** All three gateways POST to remote APIs with
  bearer auth. The only outbound code in the project is
  `Updater::httpGet()`, which is `private`, GET-only, and deliberately
  hardcoded to one host. Gateways need POST, headers, a timeout, and TLS
  verification — as a core class, not three copies inside the module.
- **A rate-limit mechanism that works before the row exists** (settled in
  §5.1 as `core/Throttle.php`). §4 says
  "IP rate-limited, same discipline as Contact Form", but that discipline does
  not transfer. `ContactFormModule::isRateLimited()` counts rows in
  `contact_submissions` by IP — it works because the submission *is* the row.
  Checkout must be limited *before* an order exists, or rate limiting creates
  the very junk orders it exists to prevent.

---

## 2. The blocker: module layout cannot be delivered

`Updater::isSafePath()` admits exactly four things inside a module:
`<Name>Module.php`, `routes.php`, `migrations/*.sql`, `manifest.json`.
Verified against 1.6.0:

| Path | Update can install it? |
|---|---|
| `modules/ecommerce/EcommerceModule.php` | yes |
| `modules/ecommerce/Gateways/PaystackGateway.php` | **no — rejects the whole package** |
| `modules/ecommerce/PaystackGateway.php` | **no — rejects the whole package** |
| `modules/ecommerce/lib/Cart.php` | **no — rejects the whole package** |

A second PHP file anywhere in a module is refused, subdirectory or not. The
spec's `modules/ecommerce/Gateways/PaymentGateway.php` is five-plus classes.

And a package containing *any* unacceptable path is rejected **whole**, judged
by the rules the receiving site already runs. So widening the allowlist must
ship in a release **before** the release that uses it — the same rule that
governed manifests in 1.5.0 → 1.6.0.

**Views are a second, softer version of this.** Updates never install views;
they arrive as reference copies to be applied from the dashboard. For a brand
new module every view is *missing*, so enabling ecommerce on an existing site
means applying roughly a dozen files one click at a time. That works — it is
what 1.5.0's fingerprinting was built for — but it needs a bulk "apply all
missing files" action or it is a genuinely bad first experience. Staging them
also requires building the release with `--wide`, which every site must be on
**1.5.0 or newer** to accept.

---

## 3. Release sequencing — settled

```
1.7.0   plumbing only, no ecommerce
        - Database::transaction()
        - core/Http.php outbound client
        - SAFE_PATTERNS admits modules/<name>/<Sub>/*.php
        - bulk "apply all missing reference files"
        - rate-limit mechanism

1.8.0   ecommerce ships
        - accepted by every site running 1.7.0+
        - built with --wide so its views are staged
```

Shipping both at once produces a release every installed site refuses.

**Allied is on 1.4.0 and must take 1.5.0 before anything else.** It cannot
accept 1.6.0, let alone a later one.

---

## 4. Phases

### Phase A — core plumbing (1.7.0, no ecommerce code)

1. `Database::transaction(callable $fn): mixed` — begin/commit/rollback around
   a closure, rethrowing after rollback. A specific method, not a raw executor;
   `SKILL.md`'s rule forbids generalising into a raw SQL runner, not adding a
   named primitive.
2. Prove it: a test that runs two concurrent checkouts against one unit of
   stock and asserts exactly one wins. **Write this before the checkout code** —
   it is the only way to know `FOR UPDATE` is really locking.
3. `core/Http.php` — `postJson($url, $payload, $headers, $timeout)` and
   `getJson(...)`. TLS verification on, no redirects followed by default, a
   byte cap, and no secret ever written to a log.
4. Widen `SAFE_PATTERNS` with
   `#^modules/[a-z0-9-]+/[A-Z][A-Za-z0-9]*/[A-Z][A-Za-z0-9]*\.php$#` — one
   level of subdirectory, PascalCase on both halves so it can never match
   `views/`, `migrations/` or any other lowercase convention directory
   (§5.2). Add it to `tools/build-release.php`'s candidate glob in the same
   change.
5. Bulk apply on the Updates screen: "apply all missing files" for entries
   classified `missing`, one confirmation, each still backed up individually.
6. Rate limiting: `core/Throttle.php` over a shared `request_throttle` table
   keyed by action + IP, with `SecurityLog::clientIp()` promoted to public as
   the single definition of the caller's address (§5.1). Checkout runs at 10
   attempts per IP per hour, counted before any row is written.

### Phase B — schema and money (1.8.0)

7. Migrations for the seven tables in §2, additive only — plus `is_demo` on
   `categories` and `shipping_zones`, which §2 puts on `products` alone
   (§5.3).
8. `email_templates` seeded by a migration INSERT, so activation seeds them —
   1.6.0 runs a module's migrations at the moment it is enabled. **Three
   templates, not the spec's two:** `order_confirmation_customer`,
   `new_order_admin`, and `order_shipped_customer` brought forward from the
   fast-follow (§7), whose trigger — the admin status control in item 26 —
   already exists in v1.
9. `format_money(int $kobo, ?string $symbol = null): string` beside `e()` in
   `core/View.php`, resolving the symbol from settings when omitted and
   formatting with `intdiv()`/`%` rather than the spec's `$kobo / 100`
   (§5.4). Money is `int` kobo everywhere; **no float anywhere in this
   module**, including intermediate maths and including this function.
10. `site_settings` keys: `payment_mode`, twelve gateway key fields (secrets via
    `Crypto`), `shipping_free_above`, and `currency_symbol` + `currency_code`
    (§5.4 — the code is what item 21 compares a gateway's returned currency
    against).
11. `modules/ecommerce/migrations/002_seed_demo_data.sql` — three demo
    products, two categories, two shipping zones, all `is_demo = 1`, every
    column populated except `featured_image`, which no update package can
    ever carry (§5.3). Build the missing-image placeholder in the same phase.

### Phase C — catalogue and cart

12. Products, categories, shipping zones: admin CRUD + public views.
13. Images via `Upload::image()`; `gallery_images` as a JSON array of paths.
14. Session cart, `$_SESSION['cart'] = [id => qty]`. Display only — **never a
    price source**.

### Phase D — checkout (the part that must be right)

15. Implement §4.2 exactly as sequenced, inside `Database::transaction()`:
    re-fetch prices from `products`, `SELECT ... FOR UPDATE`, compute available
    stock live as `stock_quantity - SUM(unexpired holds)`, insert holds with a
    15-minute expiry, abort with "only N left" rather than overselling.
16. Shipping fee resolved server-side from `shipping_zone_id`; zeroed when
    `subtotal >= shipping_free_above`. Never read a client-submitted fee.
17. `order_number` sequential, `lookup_token = bin2hex(random_bytes(32))`.
    Public order page is `/order/{lookup_token}` only.
18. Prune expired holds lazily at checkout — there is no cron on this hosting.

### Phase E — gateways

19. `PaymentGateway` interface; `AbstractSecretKeyGateway` for Paystack and
    Flutterwave; `MonnifyGateway` separate (Basic-auth token exchange per
    request).
20. `payment_mode` switches all gateways together; **visible TEST MODE banner**
    on checkout whenever it is `test`.
21. On return: server-side `verify()`, and **compare the returned amount against
    the stored `total`**. A `success` status alone never marks an order paid.
    Any webhook is only a trigger to re-run `verify()`.
22. Idempotency by current status — already `paid` means do nothing.
23. "Re-verify payment" button for pending orders with a reference.
24. On confirmed payment: convert holds to real `stock_quantity` decrements,
    delete the holds, set `paid`, send the customer email.

### Phase F — orders, email, docs

25. `/admin/orders` list (filter by status) + detail (items, address, status
    control, re-verify). Admin only.
26. Status transitions validated — `delivered` must not fall back to `pending`.
    The transition into `shipped` sends `order_shipped_customer` (§7); no
    other transition emails the customer in v1.
27. `core/EmailTemplate.php` with `{{placeholder}}` substitution, plus the
    Email Templates dashboard screen. **Register every new view in
    `requiredViews()`** or they cannot be delivered.
28. `modules/ecommerce/SKILL.md` and `manifest.json`. The manifest must declare
    `network_access` honestly — this is the first module that makes outbound
    calls, and it is exactly what that field exists to disclose.

---

## 5. Decisions — settled

Settled **2026-10-14**. These four were the gate on Phase A; none is open.

### 5.1 Rate limiting: a shared `request_throttle` table in core

*What was being decided:* checkout needs a cap on how many times one visitor
can hammer it per hour. Every existing cap in this project counts rows in the
table the action writes to — Contact Form counts `contact_submissions`
(`ContactFormModule.php:142`), password reset counts `password_resets`
(`Auth.php:212`). Checkout cannot work that way: the thing being limited
happens *before* an order exists, so counting orders means creating the junk
orders the limit exists to prevent.

**Decision: a generic counter table in core, keyed by action + IP.**

```
core/migrations/002_create_request_throttle.sql

request_throttle(
    id          BIGINT UNSIGNED AUTO_INCREMENT,
    action      VARCHAR(50)  NOT NULL,
    ip          VARCHAR(45)  NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_throttle_lookup (action, ip, created_at)
)

core/Throttle.php
    Throttle::hit(string $action): void
    Throttle::isLimited(string $action, int $max, int $windowSeconds): bool
    Throttle::prune(int $olderThanSeconds): int
```

Why this, and not the alternatives that were on the table:

- **Not `security_log`.** That table is the obvious shortcut — it already has
  `event_type`, `ip`, `created_at` and an index on each. It is the wrong
  answer: `SecurityLog`'s own contract is that it records *only* actions
  capable of turning a site's functionality on or off, on the stated grounds
  that "a log that records everything gets ignored, and an ignored log is not
  an audit trail". Checkout attempts are routine and high-volume. Writing them
  there would bury the module-lifecycle trail 1.6.0 just shipped under noise —
  causing precisely the failure that class was written to prevent.
- **Not `stock_holds` creation as the choke point.** A hold is a *successful*
  outcome. Limiting on hold rows means a visitor has to succeed before being
  told to stop, and a legitimately abandoned cart leaves holds behind — so
  honest buyers would throttle themselves while a bot that fails validation
  early would never be counted at all.
- **Not checkout-specific.** The same shape is wanted in at least two other
  places already (login attempts, and the public `/order/{lookup_token}`
  page, which is guessable-token-adjacent and should not be free to poll).
  One table and one class beats three near-copies of the counting query.
- It lives in `core/migrations/`, which `SAFE_PATTERNS` **already admits** —
  so it ships in 1.7.0 with no allowlist work of its own.

Settings for checkout: **10 attempts per IP per hour**, counted on entry to
the POST handler, before any row is written. Pruning rides the same lazy
sweep as expired holds (§4 item 18) — one cleanup pass per checkout, not two,
since there is no cron on this hosting.

`SecurityLog::clientIp()` is promoted from `private` to `public` and
`Throttle` calls it rather than reading `REMOTE_ADDR` itself. "The caller's
address, as far as it can be trusted" is a security-relevant definition —
specifically that proxy headers are never consulted, because on shared
hosting anyone can set `X-Forwarded-For` and would otherwise choose their own
throttle bucket. Two copies of that rule is one copy too many. An unreadable
IP is **not** limited, matching Contact Form: refusing service to a visitor
whose address we cannot read is the worse failure.

### 5.2 `SAFE_PATTERNS`: one subdirectory level, and it must be PascalCase

**Decision: exactly one level, with both the directory and the filename
constrained to PascalCase.**

```php
'#^modules/[a-z0-9-]+/[A-Z][A-Za-z0-9]*/[A-Z][A-Za-z0-9]*\.php$#',
```

That is tighter than the placeholder this plan carried in §4 item 4
(`[A-Za-z0-9]+/[A-Za-z0-9_]+\.php`), deliberately.

**Why one level rather than arbitrary depth**

- The allowlist's entire value is that a reader can take it in at a glance and
  know the blast radius. `(?:/[A-Za-z0-9]+)*` reads as "any PHP file anywhere
  beneath a module" — that is not an allowlist, it is a file-extension filter
  wearing an allowlist's clothes.
- Nothing in the spec needs depth. `Gateways/PaystackGateway.php` is one
  level, and all five-plus gateway classes sit in that one directory.
- The costs are asymmetric. Widening later is a one-line release. Narrowing
  later is a release that begins **rejecting** packages built against the
  wider rule — and since a package is judged by the rules the *receiving*
  site runs, that misfires on exactly the sites that are slowest to update.
  Start tight.

**Why PascalCase — this is the half doing the real work**

- **It cannot match `views/`.** That is the point. Views are the one thing
  this feature is forbidden to install, and `core/Updater.php`'s own header
  says not to widen for them. A generic one-level rule would admit
  `modules/ecommerce/views/cart.php` and dissolve that boundary silently: the
  allowlist would still *look* narrow while having given away the exact thing
  it exists to protect.
- By the same construction it cannot match `migrations/` (which has its own
  `.sql`-only rule and must never become a PHP drop zone), nor `assets/`,
  `templates/`, `lang/`, `config/`. Every lowercase convention directory
  stays shut **by shape**, with no exclusion list to maintain as conventions
  get added.
- PascalCase directory + PascalCase file is the PSR-4 shape, so the pattern
  reads as "class files in a namespace directory" — which is precisely and
  only what it is for.

Traversal is *not* the argument: `isSafePath()` (`core/Updater.php:1309`)
already rejects `..`, absolute paths and dot-segments before the allowlist is
consulted. The argument is entirely about what the list means to whoever reads
it next.

**One honest limit on the views claim.** The rule shuts the view set as it
actually exists — every view in the tree is `modules/<name>/views/` with a
lowercase filename (`admin_index.php`, `show.php`), and both halves of the
pattern require an initial capital, so not one of them can match. What it
does *not* structurally prevent is a future module choosing to render from a
PascalCase `Views/Cart.php`: `View::render()` takes an absolute path the
module supplies, so nothing stops that path existing, and it would then be
auto-updatable. That gap is closed by convention, not by regex — the module
SKILL rule stays "view files live in lowercase `views/`", and §6 keeps an
explicit check that a package carrying `views/cart.php` is still rejected.
Worth stating rather than claiming the pattern is airtight, because the
pattern is what someone will read when deciding where to put a file.

Unchanged, and still the binding constraint: **this ships in 1.7.0, a release
before the one that uses it.**

Two follow-ons belong in the same commit:

- `tools/build-release.php`'s candidate glob must pick the new paths up, or
  the gateway files silently never enter the zip and the release looks fine.
- `Updater::isReferenceFile()` returns false for anything `isSafePath()`
  accepts (`core/Updater.php:680`), so these class files stop being treated as
  reference/view deliverables the moment the pattern lands. Nothing to change
  — worth an assertion in the test so it stays that way.

### 5.3 Demo products: seeded on activation, every column populated

**Decision: seed on activation, each row filling every column**, so the admin
screens, the public templates and the JSON-decode path are all exercised by
real-looking data instead of NULLs.

One correction to the framing this plan used when it asked the question: it
said "like the other modules' sample content". **There is no such precedent.**
`grep -rl "sample\|seed"` across `modules/` returns nothing — Blog, Services
and Projects each ship an empty table. Ecommerce is the first module here to
seed anything, so this sets the convention rather than following it.

- `modules/ecommerce/migrations/002_seed_demo_data.sql` — plain `INSERT`s,
  no application code. 1.6.0 runs a module's migrations at the moment it is
  enabled, and `migrations_log` makes it exactly-once: an owner who deletes
  the demo rows never has them come back.
- Every seeded row carries `is_demo = 1`: three products across two
  categories, plus two shipping zones, so the zone selector on checkout has
  something to select and the free-shipping threshold is reachable.
- Every column filled — `sku`, `stock_quantity`, `meta_title`,
  `meta_description`, `description`, `category_id`, `display_order`,
  `is_active` — and `gallery_images` as `'[]'` rather than NULL, which is
  what exercises the JSON decode instead of skipping it.
- **The one column that cannot be filled is `featured_image`, and that is
  structural, not an oversight.** No image can ever reach a site through an
  update package: `isSafePath()` admits `.php`, `.sql` and `manifest.json`
  and nothing else, by design. A seeded photo is impossible by construction,
  and writing a path to a file that will never arrive ships a broken `<img>`
  on every fresh store — worse than no image. So `featured_image` stays NULL,
  and the missing-image placeholder (inline SVG in the product partial, the
  way the other modules already draw icons) is built in the same phase.
  Real products without photos need it anyway, so it is not demo-only work.
- **`is_demo` has to exist on three tables, not one.** The spec puts it on
  `products` only, but the seed also creates categories and shipping zones,
  and removal cannot tell those apart from the owner's own. So §2's schema
  gains `is_demo BOOLEAN NOT NULL DEFAULT FALSE` on `categories` and
  `shipping_zones` as well — additive, and cheaper now than a second
  migration later. Without it, "remove demo data" either orphans a category
  or deletes one the owner created.
- A **"Remove demo data"** action on the ecommerce admin screen deletes
  `WHERE is_demo = 1` across products, categories and zones, behind the same
  confirmation step as any other destructive action. It stays available
  permanently — not a one-time dismissal — because an owner who seeds a
  staging copy months later wants it then too.

Demo rows are never special-cased anywhere else. `is_demo` drives the removal
query and nothing in the storefront: a demo product is purchasable, which is
the only way the seed actually proves the checkout works.

### 5.4 Currency: a setting now — two keys, not one

**Decision: `currency_symbol` (`₦`) and `currency_code` (`NGN`) both become
`site_settings` keys in Phase B.**

The question was asked as a display concern, but the deciding factor turned
out to be verification, not formatting. §4 item 21 requires that a gateway's
`verify()` response be checked against the stored order before it is marked
paid, and that response carries **both** an amount and a currency
(`ECOMMERCE-CORE-SPEC.md:177`). With the symbol hardcoded there is no stored
currency to compare the returned one against — so a response in the wrong
currency with a numerically matching amount would pass. The code field is
load-bearing; the symbol riding along with it is the cheap half.

- `format_money(int $kobo, ?string $symbol = null)` resolves from the setting
  when the argument is omitted, caching the lookup in a static for the
  request rather than hitting `site_settings` per line item.
- While that function is being written: **drop the float.** The spec's draft
  is `number_format($kobo / 100, 2)`, which divides to a float in the one
  function that exists to keep money exact. Integer-only instead —
  `number_format(intdiv($kobo, 100)) . '.' . str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT)`
  — so §6's "no float anywhere in this module" is literally true rather than
  true-with-an-exception. Negative input takes a sign prefix and `abs()`
  before the modulo; v1 has no refunds (the fast-follow defers them), but a
  money formatter that renders `-1.-50` the first time one arrives is not
  worth the three characters saved.
- **This is explicitly not the first step of multi-currency.** One store, one
  currency, and `ECOMMERCE-FASTFOLLOW.md` pins multi-currency plus PayPal
  behind a conversion mechanism that has not been designed. The two keys
  describe what the store already charges in; nothing may read them as an
  invitation to convert between currencies, and the admin field for them is a
  single store-wide pair, not a per-product one.

---

## 6. Definition of Done — additions to §10

- [ ] A **concurrency test** proves `Database::transaction()` + `FOR UPDATE`
      actually serialises: two simultaneous checkouts, one unit, exactly one
      winner. Without this the spec's §9 stock guarantee is unverified.
- [ ] A release containing `modules/*/Gateways/*.php` is **accepted** by a site
      running the previous release.
- [ ] Enabling ecommerce on an existing site yields working pages — every view
      either installed or applyable from the dashboard, with no 500s.
- [ ] No float appears anywhere in the module — `format_money()` included
      (§5.4). Grep for `/ 100`, `floatval`, `(float)` and `round(`; all four
      should return nothing.
- [ ] Gateway secret keys never appear in `storage/logs/`, in an error message,
      or in the rendered page.
- [ ] **Every customer-supplied order field is escaped with `e()` wherever it
      is displayed** — admin order list, admin order detail, and every email
      template. Carried forward from the fast-follow's checkout-field security
      note (§7): name, phone and address are arbitrary customer input rendered
      into an authenticated admin session, so the stored-XSS risk is the same
      whether or not the field set is configurable. `{{placeholder}}`
      substitution escapes by default and ships no raw-output variant.
- [ ] A fresh activation yields a **browsable store with no broken images**:
      demo products visible, placeholder rendered where `featured_image` is
      NULL, a demo product purchasable end to end in test mode.
- [ ] "Remove demo data" leaves no orphans — products, categories and zones
      with `is_demo = 1` all gone, and nothing referencing them.
- [ ] Checkout refuses the 11th attempt from one IP within the hour, and a
      visitor whose IP cannot be read is still served.
- [ ] A package containing `modules/ecommerce/views/cart.php` is still
      **rejected** after `SAFE_PATTERNS` is widened. The PascalCase rule is
      only worth having if this stays true.

---

## 7. `ECOMMERCE-FASTFOLLOW.md` — read, and what comes forward

Read 2026-10-14. It is a clean deferral list: nine items, each already
designed, each with a stated "build when" trigger. Nothing in it changes the
core plan's shape or its blockers. **Three things come forward; six stay
deferred, and one is confirmed as permanently out of scope.**

### Comes forward — the stored-XSS requirement (not the feature)

The *Dynamic checkout field builder* entry is correctly deferred, but it
carries a security note that does **not** only apply to custom fields:

> every custom field value must be escaped with `e()` wherever displayed
> (admin order view, email templates) — stored-XSS risk into an
> authenticated admin session otherwise

v1's checkout collects name, email, phone and address. Those are *also*
arbitrary customer input, also stored, and also rendered into an
authenticated admin screen and into emails. The risk is identical; the fixed
field set does not make it go away. It needs stating now, while the order
views are being written, not when a merchant eventually asks for a gift-note
field.

The email-template path is the sharp edge. `{{placeholder}}` substitution
into an HTML body builds markup *outside* the view layer, where the habit of
reaching for `e()` is weakest — and the resulting mail lands in the owner's
inbox. Substitution escapes by default, with no raw-output variant in v1.
Added to §6 as a Definition-of-Done item.

### Comes forward — `order_shipped_customer`

Of the two deferred email templates, this one's **trigger already exists in
v1**: §4 item 25 gives the admin a status control, so an order can be marked shipped
on day one. Cost is one seeded row plus one send call on a transition the
module is already handling, and `EmailTemplate` ships in core in Phase F
regardless. Deferring it would mean shipping a status change that silently
tells the customer nothing.

`payment_failed_customer` **stays deferred**, and not merely on cost: its
trigger is the gateway failure path, where a single abandoned checkout can
produce several failed attempts. Mailing on each is how a new store teaches
customers that its email is noise. It wants a de-dup rule per order, which
is design work, not a template.

### Comes forward — the currency boundary

The *Multi-currency + PayPal* entry pins both behind a conversion mechanism
that has not been designed. That is now recorded as an explicit constraint on
§5.4 rather than an unwritten assumption: the two new currency settings
describe the single currency the store charges in, and are not a seam for
conversion. Without that written down, `currency_code` existing in
`site_settings` is exactly the kind of thing a later change reads as
permission.

### Stays deferred, unchanged

Roles (Shop Manager / Attendant / customer accounts), product variations,
coupons, automated refunds, the dynamic checkout field builder itself, and
digital products + the shipping toggle. Each is additive, none blocks
anything in Phases A–F, and all six have real triggers worth waiting for.

One judgement call inside that list: **`products.requires_shipping` is not
being added now as a bare column.** Additive migrations cost nothing later,
and a column no code reads is a claim the schema cannot back — the
fast-follow is right that the delivery mechanism must be designed alongside
it, not before it.

### Confirmed permanently out of scope

Zone-based shipping beyond flat merchant-named fees — no address parsing, no
live courier rates, no tracking numbers. The fast-follow marks this as
indefinitely out, not deferred, matching the original "we are not building a
logistics solution" decision. So Phase C item 12 is **complete as specified**:
there is no v2 to leave room for, and shipping zones can stay as simple as
they look.
