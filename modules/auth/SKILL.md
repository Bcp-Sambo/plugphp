# Auth module

This module is the **UI layer** on top of `core/Auth.php` — login form,
register form, password reset request/confirm forms, admin user list.
It must never reimplement anything `core/Auth.php` already does.

## Routes this module owns
- `GET /login`, `POST /login`
- `GET /register`, `POST /register` (if public registration is enabled)
- `GET /forgot-password`, `POST /forgot-password`
- `GET /reset-password`, `POST /reset-password`
- `POST /logout`

## Schema
Two tables, both required by `core/Auth.php`:
- `users`: id, email (unique), password_hash, created_at, and whatever
  extra profile columns this project needs.
- `password_resets`: id, user_id, token_hash, expires_at, used_at, created_at.

Both migrations live in `migrations/001_create_users.sql` and
`migrations/002_create_password_resets.sql`.

## Rules specific to this module
- Every handler here calls into `Auth::*` — this module contains **zero**
  password hashing, session manipulation, or token generation of its own.
  If you find yourself writing `password_hash(` or touching `$_SESSION`
  inside this module, stop — that logic belongs in `core/Auth.php`, which
  you cannot edit. Flag it instead of working around it.
- `POST /login`, `POST /register`, `POST /forgot-password`, and
  `POST /reset-password` all require `Auth::requireCsrf(...)` as their
  first line.
- Login/register error messages must be generic ("Invalid email or
  password") — never reveal whether the email exists in the system.
  `Auth::attemptLogin()` already returns a single boolean for exactly
  this reason; don't add your own more-specific error branching on top.
- The forgot-password handler always shows the same success message
  ("If that email exists, a reset link has been sent") regardless of
  whether `Auth::requestPasswordReset()` actually found a user — this
  prevents email enumeration.
- Reset-password form must call `Auth::verifyResetToken()` before showing
  the "set new password" form, so an expired/used link fails gracefully
  instead of showing a broken form.

## Dashboard nav
Registers "Users" (admin user list, if applicable) via `dashboardNavItem()`.
