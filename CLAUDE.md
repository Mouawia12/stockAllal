# GescomStock

Central dashboard for the stock of several branches that each run **Gescomm** (a Delphi app on **InterBase 7.5**).
A PowerShell agent on each branch server reads the stock (read-only) and pushes it to this Laravel app; the dashboard
searches products across branches and can ask every branch for a live quantity.

## Deploying to a server

Production: **https://stockallal.souftech.com** on a **HestiaCP** server (`/home/user/web/stockallal.souftech.com/public_html`).
Follow **`DEPLOY.md`** step by step. Ask the user for the admin username and password; the repo is public, so never write them to a file.
HTTPS is mandatory (the agents refuse non-https API URLs). Do not edit application code on the server; report failures to the user.
PHP **8.4** is required (the lock file pulls Symfony 8, which needs PHP >= 8.4.1); on the server always call `/usr/bin/php8.4`.
For an already deployed server, use the "التحديثات لاحقاً" section at the end of `DEPLOY.md`.

## Layout

| Path | What |
|---|---|
| `app/Http/Controllers/Api/AgentController.php` | Agent API: `agents/register`, `agent/ping`, `agent/sync`, `agent/pending`, `agent/result` |
| `app/Http/Middleware/AuthenticateAgent.php` | Branch identified by `Authorization: Bearer <token>` (sha256 stored, never the token) |
| `app/Http/Controllers/StockController.php` | Search (one row per `code_key`), product modal JSON, "refresh now" |
| `app/Http/Controllers/BranchController.php` | Branches page, one-time install codes, rename / disable / delete, "sync now" (job type `sync`) |
| `app/Http/Controllers/AgentDownloadController.php` | Agent zip download (built on the fly, needs PHP `zip`) and the PDF guide |
| `agent/guide/` | `guide.html` = source of `agent/GescomAgent-Guide.pdf`; rebuild with `agent/guide/build-pdf.ps1` (headless Edge) after editing it |
| `resources/views/partials/theme*.blade.php` | Day / night / auto theme (CSS variables + toggle saved in localStorage) |
| `app/Http/Controllers/AccountController.php` | "حسابي": change display name, username (login is by username) and password |
| `routes/console.php` | `user:create`, `agent:install-code`, daily cleanup schedule |
| `resources/views/` | Blade, Arabic RTL, plain CSS + vanilla JS (no Node / Vite build) |
| `agent/` | Branch agent (PowerShell 5.1) + `install.ps1` / `uninstall.ps1` + `tools/mock_api.py`. Its `README.md` holds the API contract |

## Rules that matter

- **Grouping key:** `code_key = UPPER(TRIM(code))`, nothing more. `820-AC` and `820AC` are different products on purpose.
- **API contract:** `agent/README.md` and `AgentController` must stay in sync. The server only ever sends the agent a product code, never SQL.
- **Agent scripts:** must stay ASCII-only (PowerShell 5.1 reads BOM-less files as ANSI) and read-only on the Gescomm DB
  (every isql script starts with `SET TRANSACTION READ ONLY`).
- **Rate limiters:** use the named ones in `AppServiceProvider`. An anonymous `throttle:N,1` shares one counter per IP across routes,
  so a branch agent would lock out logins from the same office.
- **MySQL:** code columns use `utf8mb4_bin` (see the `branch_stock` migration), so codes that differ only by case or accent never collide.
- **Never commit** `.env` or `database/*.sqlite`.

## Commands

```bash
composer install
php artisan test                      # SQLite in memory
php artisan serve                     # http://127.0.0.1:8000
php artisan user:create <username> --name=Admin   # asks for the password
php artisan agent:install-code        # one-time code for install.ps1 (24 h)
```
