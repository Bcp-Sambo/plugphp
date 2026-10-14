# Building a Module

A module is a self-contained feature that plugs into PlugPHP: a class, its
routes, its database migration(s), its views, and a `SKILL.md`. This guide
builds a small **"notes"** module (a public list + admin CRUD) end to end. Every
pattern here is copied from the shipped `blog` module, so read
[`modules/blog/`](../modules/blog) alongside it.

> Golden rule: work only inside `modules/` and `resources/`. Never edit `core/`
> or `public/index.php`. Every method you call is documented in
> [Core API](core-api.md).

## 1. Folder & naming

Create the folder and the standard files:

```
modules/notes/
├─ NotesModule.php          # class name = PascalCase(folder) + "Module"
├─ routes.php
├─ migrations/
│  └─ 001_create_notes.sql
├─ views/
│  ├─ index.php             # public list
│  ├─ admin_index.php       # admin list
│  └─ admin_form.php        # admin create/edit form
└─ SKILL.md
```

The folder name is **kebab-case** (`notes`, `contact-form`). The bootstrap maps
it to a PascalCase class + `Module` suffix (`notes` → `NotesModule`,
`contact-form` → `ContactFormModule`). The class name must match, or the loader
skips the module.

## 2. The migration

Migrations are trusted `.sql` files run once at install, in filename order.
Model them on [`blog/migrations/001_create_posts.sql`](../modules/blog/migrations/001_create_posts.sql):

```sql
-- modules/notes/migrations/001_create_notes.sql
CREATE TABLE IF NOT EXISTS notes (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title      VARCHAR(255) NOT NULL,
    slug       VARCHAR(191) NOT NULL,           -- 191 keeps a utf8mb4 unique index legal on old MySQL
    body       LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_notes_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Enforce real invariants (like unique slugs) with a DB index, not just PHP.

## 3. The module class

Extend `Module` and implement the required methods. Handlers live here too.

```php
<?php
// modules/notes/NotesModule.php

final class NotesModule extends Module
{
    public function name(): string  { return 'notes'; }
    public function label(): string { return 'Notes'; }

    public function routes(Router $router): void
    {
        require __DIR__ . '/routes.php';       // $router and $this are in scope there
    }

    public function migrations(): array
    {
        return [__DIR__ . '/migrations/001_create_notes.sql'];
    }

    public function dashboardNavItem(): ?array
    {
        return ['label' => 'Notes', 'url' => '/admin/notes'];   // adds a sidebar link
    }

    // ---------- Public ----------

    public function publicIndex(): void
    {
        $notes = Database::fetchAll('SELECT id, title, slug FROM notes ORDER BY created_at DESC');
        View::render(__DIR__ . '/views/index.php', [
            'notes'     => $notes,
            'pageTitle' => 'Notes',
        ]);
    }

    // ---------- Admin ----------

    public function adminIndex(): void
    {
        Auth::requireLogin();                                   // ⚠️ first line of EVERY admin handler
        $notes = Database::fetchAll('SELECT id, title, slug FROM notes ORDER BY id DESC');
        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_index.php',
            ['notes' => $notes], 'Notes');
    }

    public function adminNew(): void
    {
        Auth::requireLogin();
        AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php',
            ['note' => null, 'errors' => []], 'New note');
    }

    public function adminStore(): void
    {
        Auth::requireLogin();
        Auth::requireCsrf($_POST['csrf_token'] ?? null);        // ⚠️ CSRF on every state change

        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            AdminDashboardModule::renderAdmin(__DIR__ . '/views/admin_form.php',
                ['note' => $_POST, 'errors' => ['title' => 'Title is required.']], 'New note');
            return;
        }

        Database::insert('notes', [
            'title' => $title,
            'slug'  => $this->slugify($title),
            'body'  => (string) ($_POST['body'] ?? ''),
        ]);

        header('Location: /admin/notes');
        exit;
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-');
        return $text !== '' ? $text : 'note';
    }
}
```

## 4. The routes

`routes.php` is `require`d from `routes()`, so `$router` and `$this` are already
in scope. Public routes can be gated behind the visibility toggle; admin routes
always register.

```php
<?php
// modules/notes/routes.php   (@var Router $router)

if (Settings::isModuleVisible('notes')) {
    $router->get('/notes', function (): void { $this->publicIndex(); });
}

// Admin — always registered, each handler guards itself with Auth::requireLogin()
$router->get('/admin/notes',      function (): void { $this->adminIndex(); });
$router->get('/admin/notes/new',  function (): void { $this->adminNew(); });
$router->post('/admin/notes',     function (): void { $this->adminStore(); });
```

Registering the public route only when visible means the router's own 404
handles the "hidden" case for free — no `if` inside the handler.

## 5. The views

Public views render through the site layout; admin views render through the
admin shell (via `renderAdmin`). Both escape **every** dynamic value with `e()`.

```php
<?php // modules/notes/views/index.php ?>
<section class="container">
  <h1>Notes</h1>
  <?php if (!$notes): ?>
    <p>No notes yet.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($notes as $note): ?>
        <li><a href="/notes/<?= e($note['slug']) ?>"><?= e($note['title']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
```

Any admin form that POSTs **must** include the CSRF token:

```php
<form method="post" action="/admin/notes">
  <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
  <label for="title">Title</label>
  <input id="title" name="title" value="<?= e($note['title'] ?? '') ?>">
  <button type="submit">Save</button>
</form>
```

Follow the markup rules in the root [SKILL.md](../SKILL.md): one `<h1>` per page,
real `alt` on every image, a `<label for>` per input, explicit image dimensions.

## 6. The SKILL.md

Every module ships a `SKILL.md` describing its schema, the routes it owns, and
any module-specific rules — for humans and AI agents. Keep it short and factual;
model it on [`blog/SKILL.md`](../modules/blog/SKILL.md). Minimum contents:

- **Schema** — the table(s) and key columns.
- **Routes this module owns** — public and admin.
- **Rules specific to this module** — e.g. "fetch only via bound params",
  "escape body on output", "uploads go through `Upload::image`".
- **Visibility toggle / dashboard nav**, if the module has them.

## 7. The manifest.json

Every module ships one, next to its `SKILL.md`:

```json
{
  "module": "notes",
  "version": "1.0.0",
  "changelog": null,
  "db_tables_owned": ["notes"],
  "handles_file_uploads": false,
  "network_access": [],
  "sends_email": false,
  "admin_routes": ["/admin/notes"],
  "public_routes": ["/notes"]
}
```

`module` must match the folder name — a manifest claiming to be a different
module is refused rather than trusted. The installer's picker and
`/admin/modules` both show this, so someone deciding whether to switch your
module on can see what it touches.

Keep it current. It is the reference an audit checks your code against, and a
manifest that has drifted from reality is worse than none because it is
believed. Bump `version` when the module changes and put a one-line
`changelog` beside it — that is what `/admin/updates` shows as
"Notes 1.0 → 1.1 — adds tagging".

**It is a declaration, not a sandbox.** Nothing enforces it at runtime, and
nothing stops a module doing more than it says. Do not describe it as isolation.

## 8. Enable it

Modules are listed in `config/modules.php`, which no update ever touches.

- **Fresh install:** your module appears in the installer's picker
  automatically (by its `label()`), with its declared capabilities beside it.
  Its migrations run when selected.
- **Existing site:** go to **`/admin/modules`**, find it in the list, and press
  **Enable**. It is added to `config/modules.php` and **its migrations run at
  that moment** — nothing to run by hand.

Do not tell anyone to hand-edit `config/modules.php`. That was the only option
before `/admin/modules` existed, and it is exactly what that screen replaced.

A module that ships in a release does **not** activate itself. It sits in
`/admin/modules` as "Disabled" until an administrator turns it on, which is why
a new module arriving in core never changes an existing site's behaviour.

`home`, `admin-dashboard` and `auth` can never be disabled — without them there
is no login and no dashboard, so there would be no way back in.

## Checklist

- [ ] Folder is kebab-case; class is `PascalCaseModule` and matches the folder.
- [ ] `name()` returns the folder name; `migrations()` returns paths (or `[]`).
- [ ] All DB access via `Database::*` with bound params — no raw SQL, no PDO.
- [ ] Every admin handler starts with `Auth::requireLogin()`.
- [ ] Every POST handler starts with `Auth::requireCsrf(...)`; every form posts the token.
- [ ] Every dynamic value in a view is wrapped in `e()`.
- [ ] Uploads (if any) go through `Upload::image()`.
- [ ] `SKILL.md` documents schema, routes, and rules.
- [ ] `manifest.json` exists, `module` matches the folder, and what it declares
      is what the code actually does.
- [ ] The module is listed at `/admin/modules` and enabling it creates its tables.
