# PlugPHP release feed

This branch is the update feed. It contains `version.json` and nothing else.

Installed PlugPHP sites poll this file to learn whether a newer version is
available. The URL is hardcoded in `core/Updater.php` and cannot be changed
from `.env` or the database — that class can write to `core/`, so the address
it trusts must not be redirectable by anyone who compromises a config file.

**Do not merge this branch into `main`, and do not merge `main` into it.**

It is kept separate from `main` on purpose. `main` is worked on constantly;
if the feed lived there, every development push would touch the file every
live site polls, and bumping the version before the release asset exists
would have every site offering a download that 404s.

To cut a release, see `docs/RELEASING.md` on `main`.
