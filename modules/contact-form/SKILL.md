# Contact Form module

Public-facing form that emails the site owner and (optionally) logs
submissions to the database for the admin dashboard.

## Schema
`contact_submissions`: id, name, email, message, ip_address, created_at
(001), plus status, admin_reply, replied_at (002).

`status` is one of `new`, `read`, `replied`. It advances new -> read when a
message is opened in the dashboard, and read -> replied only on a
**confirmed** send. Never set `replied` optimistically: an inbox that claims
a reply went out when it did not is worse than one that admits the failure.

## Routes this module owns
- `GET /contact` — renders the form
- `POST /contact` — handles submission
- `GET /admin/messages` — the inbox
- `GET /admin/messages/{id}` — one message, with a reply box
- `POST /admin/messages/{id}/reply` — sends the reply

The three admin routes are open to **both** roles. Answering enquiries is
content work, so `Auth::requireLogin()` is the right guard — do not add
`requireRole()` to them.

## Rules specific to this module — read carefully, this is a common weak spot
- `POST /contact` MUST call `Auth::requireCsrf($_POST['csrf_token'] ?? null)`
  as its first line, even though there's no login involved — CSRF applies
  to any state-changing POST, not just authenticated ones.
- **Rate-limit submissions** by IP (e.g. max 5 per hour) using the
  `contact_submissions` table itself — check count before inserting a new
  row, reject with a friendly message if exceeded. This is a spam vector
  if left open.
- **Keep the honeypot field.** Rate-limiting alone does not stop a bot that
  submits slowly and stays under the cap. The form carries a hidden field
  (`ContactFormModule::HONEYPOT_FIELD`); a submission that fills it is
  discarded without storing a row or sending mail, while still returning
  the normal success redirect so the bot learns nothing.

  Three things about it are deliberate and must not be "tidied up":

  1. It is named `website`, not anything containing "honeypot" — some bots
     look for that string and skip the field.
  2. It is hidden with **CSS**, not `type="hidden"`. Simple bots skip
     inputs typed hidden but still fill visually-hidden ones.
  3. It carries `tabindex="-1"`, `autocomplete="off"` and
     `aria-hidden="true"`. An off-screen input is still keyboard-reachable,
     still announced by screen readers, and still autofilled by a password
     manager that sees a field called "website". Drop these and the people
     most likely to be silently discarded are keyboard and screen-reader
     users — who would see a success message, so nobody would ever find out.
- Validate `email` with `filter_var($email, FILTER_VALIDATE_EMAIL)` before
  using it anywhere, including before calling `Mailer::send()`.
- Never build the notification email by concatenating raw `$_POST` values
  into headers — pass values as the message body text only, and always
  through `Mailer::send()`, never raw `mail()`.
- If this module handles file attachments in the future, route them through
  `core/Upload.php` (`Upload::image($file, 'contact')`). It only ever stores
  images; do not add a path that accepts arbitrary file types.

## Dashboard nav
Registers "Messages" in the admin sidebar — the inbox, not a raw log.
Not required for the module to function standalone.

## Replying
Replies go through `Mailer::send()`, never an ad hoc mail path. Both the
reply text and the quoted original are escaped with `e()` before going into
the HTML body — the reply is written by an admin and the original by a
member of the public, and neither is trustworthy markup.
