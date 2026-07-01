# PostgreSQL Migration + Chat + Sessions — Deploy Guide

This documents the cutover from JSON-file storage to PostgreSQL, plus the new
User Sessions (on `main`) and Team Chat (on `chat-v2`) features.

> **Public repo reminder:** `db.php` (DB credentials) and all `data/*.json`
> (users, admin/API keys, leads) are gitignored. Deploy `db.php` via **cPanel
> File Manager**, never via git. See CLAUDE.md.

---

## How storage selection works

`api.php` picks its backend automatically:

```php
define('LOCAL_MODE', is_dir(__DIR__ . '/local-data'));
require_once __DIR__ . (LOCAL_MODE ? '/db.local.php' : '/db.php');
```

- **Local dev:** create an empty `local-data/` folder → uses `db.local.php`
  (JSON flat files in `local-data/`). Nothing touches Postgres or the real `data/`.
- **Production:** ensure there is **no** `local-data/` folder → uses `db.php`
  (PostgreSQL).

---

## Phase 0 — Create the Macktiles Postgres database (cPanel)

1. cPanel → **PostgreSQL Databases**.
2. Create database: `macktilesdb` (cPanel may prefix it, e.g. `stagdctc_macktilesdb`).
3. Create user: `macktiles` (may become `stagdctc_macktiles`) with a strong password.
4. **Add the user to the database** with ALL privileges.
5. Note the final DB name + username (with any cPanel prefix).

## Phase 1 — Configure db.php on the server

`db.php` is NOT in git. Upload it via **cPanel File Manager** to the app root, then
edit the credential lines to match Phase 0 exactly:

```php
define('DB_NAME', getenv('DB_NAME') ?: 'macktilesdb');   // or stagdctc_macktilesdb
define('DB_USER', getenv('DB_USER') ?: 'macktiles');      // or stagdctc_macktiles
define('DB_PASS', getenv('DB_PASS') ?: 'CHANGE_ME_ON_SERVER');  // ← real password
```

The schema (all tables + indexes) is created automatically on first connection
by `dbBootstrap()` — no manual SQL needed.

## Phase 2 — Deploy the code (git)

On `main` (foundation + User Sessions):

```
git push        # then in cPanel: Update from Remote + Deploy HEAD Commit
```

`db.local.php` and `migrate.php` deploy via git (safe — no secrets). `db.php`
stays server-only.

## Phase 3 — Migrate existing JSON data → Postgres

Run once **on the server**, against the LIVE `data/` folder (real users.json +
user_*.json together):

```bash
php migrate.php
```

Or via browser: set `$browserSecret` in `migrate.php`, then visit
`.../migrate.php?secret=YOUR_SECRET`.

It is **UPSERT-based and safe to re-run**. It migrates:
- `users.json` → `users` table
- `admin.json` → `kv_store` (config/admin)
- each `user_<id>.json` → leads flattened into the `leads` table (tagged with
  owner) + settings into `kv_store`
- chat data (`channels.json`, `messages_*.json`, `chat_last_read_*.json`) if present

**Watch the output for `⚠ ORPHAN LEAD FILES`** — those are lead files whose owner
isn't in `users.json`. On the live server this list should be empty; if not,
investigate before trusting the run (a lead file may belong to a deleted user).

## Phase 4 — Flip to Postgres & verify

1. Ensure there is **no `local-data/` folder** on the server (delete/rename it if present).
2. Load the app, then verify:
   - Login works; leads list loads; My Context / settings persist.
   - Admin → **Sessions** page loads; activity accrues after a minute of use.
   - (After deploying `chat-v2`) **Team Chat**: send a message, react, pin, DM, upload.
3. Keep the `data/` folder as a backup until you're confident. Do NOT delete it yet.

## Phase 5 — Deploy Team Chat (`chat-v2` branch)

Chat lives on `chat-v2` (built on top of the same foundation). When ready:

```
git checkout chat-v2
git push -u origin chat-v2
# Merge chat-v2 → main when approved (or deploy the branch), then in cPanel deploy.
```

Chat tables are already created by `dbBootstrap()`. No extra migration needed
unless you have legacy chat JSON to import (migrate.php handles it if present).

---

## Rollback

The migration is non-destructive — the original `data/*.json` files are untouched.
To roll back: restore the previous `api.php` (JSON-based) from git history and
remove `db.php`'s influence by reverting the storage-layer commit. Because
`data/` is preserved, no lead/user data is lost.

## Local testing recipe

```bash
mkdir -p local-data            # switches api.php to JSON mode
php -S localhost:8000           # visit http://localhost:8000
# default seeded admin: admin@macktiles.com.au / password
# when done, delete local-data/ to return to Postgres mode
```
