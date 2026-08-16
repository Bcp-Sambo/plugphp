# Extending PlugPHP with an AI Agent

PlugPHP is built to be safely extended by AI coding agents. The mechanism is the
**`SKILL.md` system**: a master rulebook at the repo root plus a focused
`SKILL.md` in every module. These files encode the boundaries so an agent
produces code that fits the kit's security model instead of improvising around
it.

## How to point an agent at the codebase

Tell the agent, before it writes anything:

> Read `SKILL.md` in the project root, then the `SKILL.md` in the specific
> module you're changing. Treat `core/` as read-only. Follow the five hard rules.

That's it. The rules live in the repo, versioned with the code, so any
agent — Claude Code, an IDE assistant, a CI bot — reads the same contract.

## The `SKILL.md` layout

- [`SKILL.md`](../SKILL.md) (root) — the master rulebook: what's editable, the
  five hard rules, frontend/SEO expectations, the 404 and visibility patterns,
  and "if you're not sure, ask."
- `modules/<name>/SKILL.md` — per-module: its schema, the routes it owns, and
  rules specific to that module (e.g. "escape `body` on output", "uploads go
  through `Upload::image`").

## The five hard rules (from the root SKILL.md)

1. **Never write raw SQL in a view or route.** All DB access goes through
   `Database::fetchOne/fetchAll/insert/update/delete`. There is deliberately no
   raw-query method — if the shape you need doesn't exist, say so.
2. **Never `echo` a dynamic value without `e()`.** `<?= e($post['title']) ?>`.
3. **Never handle passwords, sessions, or CSRF yourself.** Use `Auth::*`; don't
   touch `$_SESSION` or `password_*` outside `core/Auth.php`.
4. **Never send email except through `Mailer::send()`.**
5. **Every state-changing route calls `Auth::requireCsrf(...)` as its first
   line** — no exceptions.

Full detail, including the editable-areas table and the "ask rather than
improvise" clause, is in the root [SKILL.md](../SKILL.md). The
[Security Model](security.md) explains *why* each rule exists.

## What agents may and may not edit

| Area | Editable? |
|---|---|
| `core/*` | ❌ Read-only infrastructure. Report gaps; don't fill them yourself. |
| `public/index.php` | ❌ Bootstrap, not feature code. |
| `modules/<name>/views/*` | ✅ UI / styling work. |
| `modules/<name>/routes.php` | ✅ Only for genuinely new pages in that module's concern. |
| `modules/<name>/migrations/*.sql` | ✅ Only to add a column/table that module owns. |
| `.env` | ✅ Fill in real values. Never commit them. |

## Patterns already solved — don't rebuild them

The root SKILL.md calls these out specifically, because agents tend to
re-implement them:

- **SEO tags** — content modules auto-emit `<title>`, description, canonical,
  Open Graph, and JSON-LD from their DB fields. Add a field + form input, don't
  hardcode tags in a view.
- **404** — `resources/404.php` renders through the normal View pipeline and
  inherits the site layout. Restyle its content; don't build a new error path.
- **Per-module visibility** — hide a module from the public site by not
  registering its public routes when `Settings::isModuleVisible()` is false
  (see [Building a Module](building-a-module.md)). Admin routes always register.
- **Attribution** — keep the "Built with PlugPHP" line in its three default
  places; don't move or duplicate it.

## A good agent task looks like

- *"Add a `read_time` column to the blog and show it on the post page."* →
  new migration in `modules/blog/migrations/`, add the field to the admin form,
  read it in the view with `e()`. No `core/` changes.
- *"Build a testimonials module: public list + admin CRUD."* → follow
  [Building a Module](building-a-module.md) exactly; ship a `SKILL.md`.
- *"Restyle the site to this brand."* → edit `app.css`, `resources/layout.php`,
  and views; swap the logo. Data flow untouched.

## A task an agent should refuse or escalate

- *"Add a helper to run a raw SQL query for flexibility."* → violates rule 1;
  say no, and add a specific bound-param method instead if truly needed.
- *"Render the blog body as raw HTML so formatting shows."* → the kit ships no
  sanitizer; flag the XSS risk rather than removing `e()`.
- Anything touching `core/`, auth, payments, or uploads where the correct
  behavior is unclear → **ask**, don't guess. The root SKILL.md's closing rule:
  a wrong guess here is a security bug on a live business site.
