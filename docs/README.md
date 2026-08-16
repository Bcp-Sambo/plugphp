# PlugPHP Developer Documentation

Everything you need to build on, extend, and deploy PlugPHP — a modular,
agent-ready vanilla-PHP starter kit for shared / cPanel hosting.

These docs are written for **developers** (and AI agents) building sites on top
of the kit. If you just want to get a site running, start with the root
[README](../README.md).

## Contents

| Guide | What it covers |
|---|---|
| [Getting Started](getting-started.md) | Install paths, first run, project layout, the dev loop |
| [Architecture](architecture.md) | Request lifecycle, `core/` vs `modules/`, routing, how modules load |
| [Core API Reference](core-api.md) | Every `core/` class: Config, Database, Auth, View/`e()`, Router, Module, Settings, Mailer, Upload |
| [Building a Module](building-a-module.md) | Step-by-step: scaffold → routes → migration → views → admin → `SKILL.md` |
| [Theming & Branding](theming.md) | `layout.php`, `app.css`, the logo, per-site branding, the admin shell |
| [Security Model](security.md) | The structural controls: SQL, escaping, CSRF, auth, uploads, `.env`, docroot |
| [Extending with an AI Agent](agents.md) | The `SKILL.md` system and the five hard rules agents must follow |
| [Deployment](DEPLOY.md) | Full production checklist for shared / cPanel hosting, incl. creating a DB in cPanel |

## The one-paragraph mental model

PlugPHP is a tiny front controller (`public/index.php`) that loads a handful of
locked infrastructure classes from `core/`, then loops over the modules enabled
at install time (`config/modules.php`) and lets each one register its routes.
`core/` is **read-only** — it exists to make the unsafe thing impossible (no raw
SQL method exists, output escaping is a one-character helper, auth/CSRF live in
one place). All your feature work happens in **`modules/`** and the restyleable
**`resources/`** layout. That boundary is the whole design.

## Conventions in these docs

- Paths are relative to the project root unless noted.
- Code is real, copy-pasteable, and matches the shipped `core/` API.
- ⚠️ marks a security-relevant rule you should not work around.
