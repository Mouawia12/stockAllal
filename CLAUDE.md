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
| `config/apps.php` + `app/Http/Controllers/HomeController.php` | Odoo-style apps screen at `/` after login. Only المخزون (`/stock`) is built; the others show "قريباً" until their `route` is set. Icons: `resources/views/partials/app-icon.blade.php`. The top bar shows the current app (picked by route name in `layouts/app.blade.php`) |
| `app/Http/Controllers/StockController.php` | Search (one row per `code_key` or per merged group; every word must match the code or the designation), product modal JSON, "refresh now", and the "دمج الأصناف" page (same view, `$merge`). `?partial=1` returns only `stock/_results.blade.php`: the page searches while typing (debounce, abort, out-of-order guard) |
| `app/Http/Controllers/ProductGroupController.php` + `app/Models/ProductGroup.php` | Admin merges of codes that are the same product (`820AC` + `820-AC`). `ProductGroup::codesOf()` / `expand()` give the codes a key stands for |
| `app/Http/Controllers/BranchController.php` | Branches page, one-time install codes, rename / disable / delete, "sync now" (job type `sync`), main branch (`is_main`, price reference shown in search) |
| `app/Http/Controllers/AgentDownloadController.php` | Agent zip download (built on the fly, needs PHP `zip`) and the PDF guide |
| `agent/guide/` | `guide.html` = source of `agent/GescomAgent-Guide.pdf`; rebuild with `agent/guide/build-pdf.ps1` (headless Edge) after editing it |
| `resources/views/partials/theme*.blade.php` | Day / night / auto theme (CSS variables + toggle saved in localStorage) |
| `app/Http/Controllers/AccountController.php` | "حسابي": change display name, username (login is by username) and password |
| `app/Http/Controllers/UserController.php` | Accounts page (admins only): create workers, edit, tick "مدير" to promote, delete. `users.is_admin` |
| `app/Http/Middleware/EnsureAdmin.php` | `admin` middleware: branches, merge page, agent download/guide, accounts. Workers (`is_admin = false`) only get search, product modal, "تحديث الآن" and their own account |
| `routes/console.php` | `user:create`, `agent:install-code`, daily cleanup schedule |
| `resources/views/` | Blade, Arabic RTL, plain CSS + vanilla JS (no Node / Vite build) |
| `agent/` | Branch agent (PowerShell 5.1) + `install.ps1` / `uninstall.ps1` + `tools/mock_api.py`. Its `README.md` holds the API contract |

## Rules that matter

- **Grouping key:** `code_key = UPPER(TRIM(code))`, nothing more. `820-AC` and `820AC` are different products on purpose,
  until an admin merges them on "دمج الأصناف". Never merge codes automatically; search, modal and refresh go through `ProductGroup`.
- **API contract:** `agent/README.md` and `AgentController` must stay in sync. The server only ever sends the agent a product code, never SQL.
- **Agent scripts:** must stay ASCII-only (PowerShell 5.1 reads BOM-less files as ANSI) and read-only on the Gescomm DB
  (every isql script starts with `SET TRANSACTION READ ONLY`).
- **Every agent change** (the user asked for this): bump `$AgentVersion` in `agent/agent.ps1`, update `agent/README.md`,
  update `agent/guide/guide.html` (version line, zip name, what it reads) and rebuild `agent/GescomAgent-Guide.pdf`
  with `agent/guide/build-pdf.ps1` (needs Windows + Edge), all in the same commit. The dashboard's download zip ships these files.
- **Prices** = the columns of Gescomm's article list (`GC003`): `PUGROS` "Pu Detail" -> `price` (retail, التجزئة),
  `PRIXVNFACT` "Prix Vente Gros" -> `price_wholesale` (الجملة), `PRIX` "Pu Achat" -> `price_buy` (الشراء).
  Column names do not match their meaning; trust the captions. `price_buy` is admins only: `StockController` never puts it
  in a worker's page or JSON. Price columns are optional in the agent (a branch with an older Gescomm still syncs).
- **Rate limiters:** use the named ones in `AppServiceProvider`. An anonymous `throttle:N,1` shares one counter per IP across routes,
  so a branch agent would lock out logins from the same office.
- **MySQL:** code columns use `utf8mb4_bin` (see the `branch_stock` migration), so codes that differ only by case or accent never collide.
- **Sync writes only what changed** (`AgentController::sync`): unchanged rows keep their `updated_at`; their freshness is the branch's
  `last_sync_at`. Keep it that way: rewriting ~4k rows per branch every 5 min is load the users feel.
- **Flaky lines** (about half of new connections can drop, see the end of `DEPLOY.md`): browser calls go through `fetchRetry`;
  a POST that creates something carries a `newId()` key (`batch`) so a replayed request does not create it twice.
  Page loads go through `public/sw.js`: it retries GET navigations and falls back to `public/offline.html`. It must never cache
  app pages. To switch it off, ship a `sw.js` that calls `self.registration.unregister()`; never just delete the file.
- **Never commit** `.env` or `database/*.sqlite`.

## Commands

```bash
composer install
php artisan test                      # SQLite in memory
php artisan serve                     # http://127.0.0.1:8000
php artisan user:create <username> --name=Admin --admin   # asks for the password; without --admin = worker (stock only)
php artisan agent:install-code        # one-time code for install.ps1 (24 h)
```
