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

`ECOMMERCE-FASTFOLLOW.md` exists alongside the spec and has **not** been read
or incorporated here; this plan covers the core spec only.

### 1.3 Needs new core infrastructure the spec does not mention

- **An outbound HTTP client.** All three gateways POST to remote APIs with
  bearer auth. The only outbound code in the project is
  `Updater::httpGet()`, which is `private`, GET-only, and deliberately
  hardcoded to one host. Gateways need POST, headers, a timeout, and TLS
  verification — as a core class, not three copies inside the module.
- **A rate-limit mechanism that works before the row exists.** §4 says
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

## 3. Release sequencing — decide this before writing code

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
4. Widen `SAFE_PATTERNS` with `#^modules/[a-z0-9-]+/[A-Za-z0-9]+/[A-Za-z0-9_]+\.php$#`
   — one level of subdirectory, named files only. Add it to
   `tools/build-release.php`'s candidate glob in the same change.
5. Bulk apply on the Updates screen: "apply all missing files" for entries
   classified `missing`, one confirmation, each still backed up individually.
6. Rate limiting: a `request_throttle` table keyed by IP + action, or reuse
   `stock_holds` creation as the natural choke point. **Decide before §4.2 is
   written**, since checkout calls it.

### Phase B — schema and money (1.8.0)

7. Migrations for the seven tables in §2, additive only.
8. `email_templates` seeded by a migration INSERT, so activation seeds them —
   1.6.0 runs a module's migrations at the moment it is enabled.
9. `format_money(int $kobo, string $currency = '₦'): string` beside `e()` in
   `core/View.php`. Money is `int` kobo everywhere; **no float anywhere in this
   module**, including intermediate maths.
10. `site_settings` keys: `payment_mode`, twelve gateway key fields (secrets via
    `Crypto`), `shipping_free_above`.

### Phase C — catalogue and cart

11. Products, categories, shipping zones: admin CRUD + public views.
12. Images via `Upload::image()`; `gallery_images` as a JSON array of paths.
13. Session cart, `$_SESSION['cart'] = [id => qty]`. Display only — **never a
    price source**.

### Phase D — checkout (the part that must be right)

14. Implement §4.2 exactly as sequenced, inside `Database::transaction()`:
    re-fetch prices from `products`, `SELECT ... FOR UPDATE`, compute available
    stock live as `stock_quantity - SUM(unexpired holds)`, insert holds with a
    15-minute expiry, abort with "only N left" rather than overselling.
15. Shipping fee resolved server-side from `shipping_zone_id`; zeroed when
    `subtotal >= shipping_free_above`. Never read a client-submitted fee.
16. `order_number` sequential, `lookup_token = bin2hex(random_bytes(32))`.
    Public order page is `/order/{lookup_token}` only.
17. Prune expired holds lazily at checkout — there is no cron on this hosting.

### Phase E — gateways

18. `PaymentGateway` interface; `AbstractSecretKeyGateway` for Paystack and
    Flutterwave; `MonnifyGateway` separate (Basic-auth token exchange per
    request).
19. `payment_mode` switches all gateways together; **visible TEST MODE banner**
    on checkout whenever it is `test`.
20. On return: server-side `verify()`, and **compare the returned amount against
    the stored `total`**. A `success` status alone never marks an order paid.
    Any webhook is only a trigger to re-run `verify()`.
21. Idempotency by current status — already `paid` means do nothing.
22. "Re-verify payment" button for pending orders with a reference.
23. On confirmed payment: convert holds to real `stock_quantity` decrements,
    delete the holds, set `paid`, send the customer email.

### Phase F — orders, email, docs

24. `/admin/orders` list (filter by status) + detail (items, address, status
    control, re-verify). Admin only.
25. Status transitions validated — `delivered` must not fall back to `pending`.
26. `core/EmailTemplate.php` with `{{placeholder}}` substitution, plus the
    Email Templates dashboard screen. **Register every new view in
    `requiredViews()`** or they cannot be delivered.
27. `modules/ecommerce/SKILL.md` and `manifest.json`. The manifest must declare
    `network_access` honestly — this is the first module that makes outbound
    calls, and it is exactly what that field exists to disclose.

---

## 5. Decisions needed before Phase A

1. **Rate-limit mechanism** — shared `request_throttle` table, or
   checkout-specific? It must work before an order row exists.
2. **One subdirectory level or arbitrary depth** in `SAFE_PATTERNS`? One level
   keeps the allowlist meaningful; arbitrary depth is close to "any PHP file in
   a module".
3. **Demo products** — `products.is_demo` exists in the schema. Seeded on
   activation like the other modules' sample content, with a "remove demo data"
   action, or left for the owner to create?
4. **Currency** — NGN only, with `format_money()` taking a `₦` default. Does the
   symbol become a setting now, or stay hardcoded until a second currency is
   genuinely needed?

## 6. Definition of Done — additions to §10

- [ ] A **concurrency test** proves `Database::transaction()` + `FOR UPDATE`
      actually serialises: two simultaneous checkouts, one unit, exactly one
      winner. Without this the spec's §9 stock guarantee is unverified.
- [ ] A release containing `modules/*/Gateways/*.php` is **accepted** by a site
      running the previous release.
- [ ] Enabling ecommerce on an existing site yields working pages — every view
      either installed or applyable from the dashboard, with no 500s.
- [ ] No float appears anywhere in the module. Grep for `/ 100` outside
      `format_money()` and for `floatval`, `(float)`, `round(`.
- [ ] Gateway secret keys never appear in `storage/logs/`, in an error message,
      or in the rendered page.
