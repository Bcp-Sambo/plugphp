# About module

Static module — no database table, no dashboard panel (Option A, by design).
Content lives directly in `views/about.php` as plain HTML/PHP, or in a small
config array at the top of `routes.php` if you want fields separated from markup.

## What you can do
- Edit `views/about.php` freely — this is a pure UI/content task.
- Add sections (team, mission, timeline) directly as markup.

## What you must not do
- Do not add a migration or database table for this module — if the client
  later wants this dashboard-editable, that is a deliberate upgrade to
  "Option B" (see root project notes), not something to add silently.
- Do not skip the SEO meta block at the top of the view — set page title
  and meta description as PHP variables before the layout renders, same
  pattern as other modules, even though this module has no DB-backed fields.

## Attribution
Include "Built with PlugPHP — by Kabiru Sambo / Bubble Bot Solutions"
somewhere on this page by default (soft convention, not license-
mandatory — see root SKILL.md). Keep it unless the client has
explicitly asked for it removed.

## Files
```
modules/about/
├── views/about.php
└── routes.php
```
