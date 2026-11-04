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
| `config/apps.php` + `app/Http/Controllers/HomeController.php` | Odoo-style apps screen at `/` after login. Only المخزون (`/stock`) is built; the others show "قريباً" until their `route` is set. Names: `apps` in `lang/*/common.php`. Icons: `resources/views/partials/app-icon.blade.php`. The top bar shows the current app (picked by route name in `layouts/app.blade.php`) |
| `app/Http/Controllers/StockController.php` | Search (one row per `code_key` or per merged group; every word must match the code or the designation), product modal JSON, "refresh now", and the "دمج الأصناف" page (same view, `$merge`). `?partial=1` returns only `stock/_results.blade.php`: the page searches while typing (debounce, abort, out-of-order guard). Product page `stock.item` (`stock/item` + `stock/_shelf`: the shelf tag, then one bar per branch; `?partial=1&batch=` = the shelf only, polled during "تحديث الآن"), and `palette` (Ctrl+K JSON, never prices). Prices come from `priceBranch()`: the main branch when shown, else the one branch shown or the account's branch |
| `app/Support/BranchScope.php` + `partials/branch-switch.blade.php` | Odoo-style branch switcher in the top bar ("كل فروعي" or one branch, session `branch_scope`, POST `branch-scope`). Stock pages use `BranchScope::ids()`, never more than `User::stockBranchIds()`; shown only when the account sees more than one branch |
| `partials/palette.blade.php` | Ctrl+K palette on every page (also `?` and the top-bar button; `e.code`, so it works with an Arabic keyboard): products, the pages the account may open, the shortcuts |
| `app/Http/Controllers/ProductGroupController.php` + `app/Models/ProductGroup.php` | Admin merges of codes that are the same product (`820AC` + `820-AC`). `ProductGroup::codesOf()` / `expand()` give the codes a key stands for |
| `app/Http/Controllers/BranchController.php` | Branches page (only the branches the account sees), one-time install codes (new branch, or reinstall for an existing one: `install_codes.for_branch_id`, same branch, new token), rename / disable / delete, "sync now" (job type `sync` for agent 1.0.4+, see `Branch::AGENT_FEATURES`; older agents get a `waiting` job their next automatic sync answers), main branch (`is_main`, price reference shown in search), "من يرى مخزون من" matrix (`branch_access`, `App\Models\BranchAccess`) for developer / owner |
| `app/Http/Controllers/AgentDownloadController.php` | Agent zip download (built on the fly, needs PHP `zip`) and the PDF guide |
| `agent/guide/` | `guide.html` = source of `agent/GescomAgent-Guide.pdf`; rebuild with `agent/guide/build-pdf.ps1` (headless Edge) after editing it |
| `resources/views/partials/theme*.blade.php` + `partials/fonts.blade.php` | Day / night / auto theme (CSS variables + toggle saved in localStorage). Palette "galvanised steel" (zinc grey, steel ink, teal accent); `--tag` (shelf-tag amber) is only for the product page label, the one bold element. `--zero` / `--negative` / `--low` / `--over` = Gescomm's stock colours (`App\Support\StockState`). Fonts: IBM Plex Sans Arabic (UI) + Barlow Condensed (`.code`, the tag's numbers), loaded without blocking the page. `public/offline.html` copies the palette (bump `sw.js` CACHE when it changes) |
| `app/Http/Controllers/AccountController.php` | "حسابي": change display name, username (login is by username) and password |
| `app/Http/Controllers/UserController.php` + `app/Support/Roles.php` | Accounts by role: developer > owner (مدير الشركة, his branches in `branch_user`) > branch_manager > user (`users.role`, `users.branch_id`). The page is a tree by branch; the form gives role, branch (or the owner's branches), permissions, `active`. Nobody grants a role, branch or permission they lack (`User::canManage`, `assignableRoles`, `grantablePermissions`; a permission the author lacks stays as it is on the account), and nobody edits their own account here ("حسابي"). Accounts from before roles have no branch and see every branch until assigned. Changes go to `activity_log` (`App\Support\Activity`) |
| `app/Support/Permissions.php` | The permissions ticked for a user (`GROUPS`), the defaults of a new user, `normalize()` (sync / manage / agent imply `branches.view`). One Gate each (`AppServiceProvider`), used as `can:` on routes and `@can` in views. Texts: `perms` in `lang/*/users.php`. The developer has them all |
| `AppServiceProvider` gates `manage-users`, `manage-branch-access` | Role-based, never a checkbox: the accounts pages (developer, owner, branch manager) and who sees whose stock (developer, owner). Everyone gets search, the product modal and their own account ("الإعدادات" on the home screen = accounts for those who manage some, "حسابي" for others). `LogoutInactive` signs a disabled account out at its next click |
| `routes/console.php` | `user:create`, `agent:install-code`, daily cleanup schedule |
| `resources/views/` | Blade, plain CSS + vanilla JS (no Node / Vite build). Arabic (RTL, default), French and English (LTR) |
| `lang/{ar,fr,en}/` + `app/Http/Middleware/SetLocale.php` + `LocaleController` | Interface texts. Language = `locale` cookie (plain, not httpOnly: `public/offline.html` reads it), set by the عربي / FR / EN buttons (`partials/lang-switch.blade.php`). `config('app.locales')` lists the languages and their `dir` |
| `agent/` | Branch agent (PowerShell 5.1) + `install.ps1` / `uninstall.ps1` + `tools/mock_api.py`. Its `README.md` holds the API contract |
| `agent/setup/` + `agent/GescomAgent-Setup.exe` | GUI installer (C# 5 / .NET 4 WinForms, ar/fr/en): finds Gescomm's DB (`LABASEN.IB` next to `Gescomm.exe`, via the Uninstall registry key) and isql, then installs / updates / uninstalls / diagnoses. `Installer.cs` = logic, `SetupForm.cs` = window. It does every step itself and **never starts PowerShell**: AVG quarantines scripts launched by an unknown exe (`IDP.HELU.PSD11`). It copies the `agent.ps1` next to it, so agent changes need no rebuild |
| `agent/tests/` | Windows tests: `run-tests.ps1` (no admin, changes nothing: scripts, build, detection, read-only DB read, the window via UI Automation), `e2e-install.ps1` (admin: full install through the window against a local Laravel + temp SQLite, then uninstall; own task/folder `GescomAgentE2E`) |
| `docs/roadmap.md` | **Development plan** (Arabic, still under discussion): synced vs native branches (a native branch leaves Gescomm and works in the web app), the target schema both modes share, Odoo + Gescomm-shortcut UI, phases. Read it before building a new module |
| `docs/gescomm/` | **Gescomm database reference**: `schema.md` (every table, column, type, Gescomm screen caption, keys, indexes, triggers and what they touch), `schema.json`, `README.md` (Arabic overview + what the key tables are). Generated by `agent/tools/dump-schema.ps1` (read-only, Windows + InterBase only) |

## Rules that matter

- **Branch scope** (the user asked for this): every page or JSON that shows branch data goes through `Branch::visibleTo($user)` /
  `User::stockBranchIds()` (developer all, owner his branches, others their branch + what `branch_access` opens for it).
  A branch the account does not see does not exist for it (404). Each new module gets its own `branch_access.module` (stock today;
  sales will be "own branch only" by default) and a test "a user of A never sees B" (`BranchScopeTest`).
- **Stock colours mean what they mean in Gescomm** (the users know them): red = 0, yellow = negative, green = at or below
  the alert level, orange = above the max (`StockState`, `.qty.zero` ...). Never reuse yellow or red for something else in
  stock views: the search highlight is teal on purpose.
- **Grouping key:** `code_key = UPPER(TRIM(code))`, nothing more. `820-AC` and `820AC` are different products on purpose,
  until an admin merges them on "دمج الأصناف". Never merge codes automatically; search, modal and refresh go through `ProductGroup`.
- **API contract:** `agent/README.md` and `AgentController` must stay in sync. The server only ever sends the agent a product code, never SQL.
- **Old agents keep working** (the user asked for this): branches update their agent by hand, when the user decides (download,
  `GescomAgent-Setup.exe` → «تحديث الوكيل»). A new agent version is for new installs; the ones in the field stay as they are, so every
  server deploy must leave every released agent version syncing and answering «تحديث الآن», and must never ask a branch to reinstall.
  - The API only grows: never rename, remove or make mandatory a field, endpoint, value or job type an older agent relies on.
    New fields are optional (`null` = unknown, like the prices before 1.0.5). Reshaping the server (e.g. moving tokens to an `agents`
    table) migrates the existing data so every agent keeps its token.
  - A job that needs a newer agent goes through `Branch::AGENT_FEATURES` / `agentSupports()`: the server never sends it to an older agent
    (agents up to 1.0.6 read any unknown job as a product code). The next agent change should answer an unknown `type` with `error: "unsupported"`.
  - **Never an error just because an agent is old:** the button does what that version can and reports success or failure from there.
    Example: «مزامنة الآن» for an agent before 1.0.4 creates a `waiting` job (`RefreshJob`) that its next automatic sync (every 5 min)
    answers: "done" when it lands, "no answer" after `WAIT_SYNC_SECONDS`. The page says it is waiting for an older agent.
  - A feature that needs data only a newer agent sends (e.g. sales) shows "update this branch's agent" for older branches; their stock stays.
  - `tests/Feature/AgentCompatibilityTest.php` replays the requests of every released agent version: add each new version there,
    never remove an old one.
- **The agent stays light and orderly** (the user asked for this: it must never weigh on the branch PC, stall or mix things up).
  Today's agent: one loop, live jobs every 10 s, full stock every 5 min, 60 s hard limit per request, retries with backoff, one instance per config.
  Every new task (sales, customers...) must keep that:
  - **Its own step:** its own schedule, size limit and time limit. A slow or failing task logs, retries later and never blocks
    the others, above all «تحديث الآن» (answered within ~10 s).
  - **Big uploads in pages** (~1000 rows per request). The agent goes back to live jobs between pages. A page that is resent changes nothing
    (same key, same result), and a dropped line resumes from the last page the server confirmed instead of starting over.
  - **Light on Gescomm:** read-only, read only the window that can have changed, use indexed columns (e.g. `GC011.DATES`),
    keep the full history for the night. Never scan a large table every few minutes.
  - **Only what changed:** the agent sends totals or changes, not raw history again and again. The server writes only what changed.
  - **Visible state:** the server keeps the last success / last error of each task per branch, so the branches page shows where each branch stands.
- **Agent scripts:** must stay ASCII-only (PowerShell 5.1 reads BOM-less files as ANSI) and read-only on the Gescomm DB
  (every isql script starts with `SET TRANSACTION READ ONLY`).
- **Every agent change** (the user asked for this): bump `$AgentVersion` in `agent/agent.ps1`, update `agent/README.md`,
  update `agent/guide/guide.html` (version line, zip name, what it reads) and rebuild `agent/GescomAgent-Guide.pdf`
  with `agent/guide/build-pdf.ps1` (needs Windows + Edge), all in the same commit. The dashboard's download zip ships these files.
- **Installer exe** (the user asked never to leave it stale): after any change in `agent/setup/`, run `agent\setup\build.ps1` on Windows
  and commit `agent/GescomAgent-Setup.exe` + `agent/setup/build.sha256` with it. The server (Linux) cannot build it; it ships what Git has.
  `tests/Unit/AgentSetupTest.php` fails when the sources changed without a rebuild, and when `Installer.RequiredFields` /
  `DefaultApiUrl` drift from `agent.ps1` / `install.ps1`. Keep `Installer.cs` doing what `install.ps1` does (same steps, same task).
  From a Mac you can edit `agent/setup/` but not build it: say so, and rebuild on the Windows PC before deploying.
  C# 5 only (the built-in `csc.exe`): no `$"..."`, `?.`, `nameof`, `=>` members. Window texts live in `Texts` (`GescomAgentSetup.cs`), in ar/fr/en.
- **Gescomm fields:** before reading a new field, look it up in `docs/gescomm/schema.md` and trust its "Screen caption"
  column over the name. No foreign keys exist (links are by column name, Gescomm keeps them with triggers). From a Mac you can
  write the change, but testing the agent needs the Windows PC with Gescomm. Never write to the Gescomm DB.
  After a Gescomm update, rerun `agent/tools/dump-schema.ps1` there and commit the new `docs/gescomm/`.
- **Prices** = the columns of Gescomm's article list (`GC003`): `PUGROS` "Pu Detail" -> `price` (retail, التجزئة),
  `PRIXVNFACT` "Prix Vente Gros" -> `price_wholesale` (الجملة), `PRIX` "Pu Achat" -> `price_buy` (الشراء).
  Column names do not match their meaning; trust the captions. `price_buy` needs the `prices.buy` permission and retail / wholesale need
  `prices.sale`: `StockController` never puts a price the user may not see in the page or the JSON. Price columns are optional in the agent (a branch with an older Gescomm still syncs).
- **Rate limiters:** use the named ones in `AppServiceProvider`. An anonymous `throttle:N,1` shares one counter per IP across routes,
  so a branch agent would lock out logins from the same office.
- **MySQL:** code columns use `utf8mb4_bin` (see the `branch_stock` migration), so codes that differ only by case or accent never collide.
- **Sync writes only what changed** (`AgentController::sync`): unchanged rows keep their `updated_at`; their freshness is the branch's
  `last_sync_at`. Keep it that way: rewriting ~4k rows per branch every 5 min is load the users feel.
- **Flaky lines** (about half of new connections can drop, see the end of `DEPLOY.md`): browser calls go through `fetchRetry`;
  a POST that creates something carries a `newId()` key (`batch`) so a replayed request does not create it twice.
  Page loads go through `public/sw.js`: it retries GET navigations and falls back to `public/offline.html`. It must never cache
  app pages. To switch it off, ship a `sw.js` that calls `self.registration.unregister()`; never just delete the file.
- **Interface texts:** never hardcode them. Add the key to `lang/ar`, `lang/fr` and `lang/en` with the same `:placeholders`
  (`LocaleTest` fails otherwise). JavaScript gets them through `@json(...)` (build arrays in `@php` first: `@json` splits its argument on commas).
  CSS must work both ways: `text-align: start`, `inset-inline-*`, `margin-inline-*`, never `left` / `right`.
  The language buttons are a POST on purpose: `sw.js` replays GET navigations with a `/sw.js` Referer, so `back()` would land there.
- **A login lasts a workday:** `SESSION_LIFETIME` minutes (480 = 8 h), counted from the last click (Laravel) *and* from
  the login (`ExpireLogin`), so the shop PC is signed out around the end of the shift. "تذكّرني" opts out on purpose.
  `fetchRetry` reloads the page on 401 / 419 / a redirect to the login page, so the login page never lands inside the results.
- **Never commit** `.env` or `database/*.sqlite`.

## Commands

```bash
composer install
php artisan test                      # SQLite in memory
php artisan serve                     # http://127.0.0.1:8000
php artisan user:create <username> --name=Admin --admin   # = --role=developer; asks for the password
php artisan user:create <username> --role=branch_manager --branch=Oran   # roles: developer, owner, branch_manager, user (default)
php artisan agent:install-code        # one-time code for the installer (24 h)
```

On Windows (PowerShell):
```
powershell -ExecutionPolicy Bypass -File .\agent\setup\build.ps1          # rebuild GescomAgent-Setup.exe (+ build.sha256)
powershell -ExecutionPolicy Bypass -File .\agent\tests\run-tests.ps1      # agent + installer tests, no admin
powershell -ExecutionPolicy Bypass -File .\agent\tests\e2e-install.ps1    # full install test, as administrator
```
