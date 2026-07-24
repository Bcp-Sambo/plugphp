# Admin Dashboard module

This is **always on** — not a togglable module like Blog/Services/Projects.
Every enabled content module contributes a nav entry here via its
`dashboardNavItem()` method; this module renders the shell around them.

## What's editable vs. locked

| Part | Editable by devs/AI? |
|---|---|
| Layout, styling, branding, colors, dashboard theme | **Yes** — this is the main UI-first customization surface. |
| Sidebar nav rendering logic (loops over registered modules) | No — this is what keeps every module pluggable; don't hardcode a module's nav item directly into a view. |
| Permission checks / route guards (`Auth::requireLogin()` calls) | No — never remove or bypass these to "simplify" a page during development. |
| CRUD form generation per module | Module-specific — edit within that module's own admin views, not here. |

## Routes this module owns
- `GET /admin` — dashboard home (summary widgets, one per enabled module
  is fine — e.g. "12 blog posts", "3 new messages")
- `GET /admin/*` for module-specific admin pages, implemented inside each
  respective module, not duplicated here.

## Attribution
The dashboard footer includes "Built with PlugPHP — by Kabiru Sambo /
Bubble Bot Solutions" by default (soft convention, not license-mandatory
— see root SKILL.md). Keep it unless the client has explicitly asked
for it removed.

## Rules
- Every `/admin/*` route, in every module, must start with
  `Auth::requireLogin()`. This dashboard module cannot enforce that for
  other modules' routes — it's each module's own responsibility, called
  out again in every module's SKILL.md, because this is the single most
  damaging thing to get wrong (an unauthenticated admin page).
- Reskin freely — change CSS, layout, add a logo, restyle tables — but
  do not change what data is shown or remove confirmation steps on
  destructive actions (delete post, delete project, etc.).
- Destructive actions (delete anything) require a confirmation step
  (a confirm dialog or an "are you sure" intermediate page) — do not
  wire a delete button directly to a one-click irreversible action.
