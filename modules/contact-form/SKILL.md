# Contact Form module

Public-facing form that emails the site owner and (optionally) logs
submissions to the database for the admin dashboard.

## Schema (migrations/001_create_contact_submissions.sql)
`contact_submissions`: id, name, email, message, ip_address, created_at.

## Routes this module owns
- `GET /contact` — renders the form
- `POST /contact` — handles submission

## Rules specific to this module — read carefully, this is a common weak spot
- `POST /contact` MUST call `Auth::requireCsrf($_POST['csrf_token'] ?? null)`
  as its first line, even though there's no login involved — CSRF applies
  to any state-changing POST, not just authenticated ones.
- **Rate-limit submissions** by IP (e.g. max 5 per hour) using the
  `contact_submissions` table itself — check count before inserting a new
  row, reject with a friendly message if exceeded. This is a spam vector
  if left open.
- Validate `email` with `filter_var($email, FILTER_VALIDATE_EMAIL)` before
  using it anywhere, including before calling `Mailer::send()`.
- Never build the notification email by concatenating raw `$_POST` values
  into headers — pass values as the message body text only, and always
  through `Mailer::send()`, never raw `mail()`.
- If this module handles file attachments in the future, route them
  through the same upload validator pattern described in the Projects
  module's SKILL.md (extension whitelist + MIME sniff + re-encode) —
  do not accept arbitrary file types.

## Dashboard nav
Optionally registers "Messages" in the admin sidebar, listing submissions
for review — not required for the module to function standalone.
