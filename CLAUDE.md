# CLAUDE.md — AUSHVAN (Medical & General Store Management Software)

Permanent instructions for every Claude Code session in this repository.

Product name **AUSHVAN**, by TriMora Technologies (parent: TriMora Group); tagline "Every Store. Better Managed."
(decision D-049). The name and tagline are never translated. Internal names still say RMGSMS (folder, `rmgsms.*`
storage keys); do not rename those.

## What this project is

A billing, stock and udhar (credit) system for one small rural shop in Maharashtra, India, that is both a
medical store and a kirana/general store. Operators have limited computer experience. The product must be
simple, fast, colourful, dependable and cheap to run. It is **not** an ERP and **not** a demo CRUD app.

## Start of every session

1. Read this file.
2. Read `docs/IMPLEMENTATION_PLAN.md` (current phase, next task) and `docs/KNOWN_ISSUES.md`.
3. Read the documents relevant to the task (see the map below) **before** changing code.
4. If the code already exists for the area, read it before proposing changes. Do not rewrite working modules.

## Three roles every session (owner instruction, D-043)

Besides developing, run an independent **reviewer** (read-only code review of what changed and of anything
touching money, stock, permissions or exports) and a **tester** (writes tests that try to break the backend, in
its own test file) as background agents. Fix every confirmed finding and give it a test before reporting.

## End of every session

1. Update `docs/IMPLEMENTATION_PLAN.md`: tick what is done, note what is pending or blocked, and write the
   single next actionable task under "Next task".
2. Record any new architectural or business decision in `docs/DECISION_LOG.md` (with the reason).
3. Add newly found limitations to `docs/KNOWN_ISSUES.md`.
4. If a requirement, workflow, table or architectural choice changed, update the matching document in the
   same session. Documentation and code must not drift apart.

## Documentation map (source of truth)

| Document | Read it when |
|---|---|
| `docs/PROJECT_VISION.md` | Deciding whether something belongs in the product |
| `docs/PRODUCT_REQUIREMENTS.md` | Building or changing any feature |
| `docs/BUSINESS_WORKFLOWS.md` | Touching sales, purchases, returns, credit, day closing |
| `docs/MEDICAL_DOMAIN_GUIDE.md` | Anything involving medicines, batches, schedules, prescriptions |
| `docs/GENERAL_STORE_GUIDE.md` | Units, loose sale, kirana products |
| `docs/DATABASE_DESIGN.md` | Any migration or model change |
| `docs/TECHNICAL_ARCHITECTURE.md` | Structure, libraries, API conventions, money/quantity handling |
| `docs/UI_UX_GUIDELINES.md` | Any screen or component |
| `docs/OFFLINE_WINDOWS_STRATEGY.md` | Deployment, offline, desktop packaging, sync |
| `docs/SECURITY_AND_BACKUP.md` | Auth, permissions, uploads, backups |
| `docs/IMPLEMENTATION_PLAN.md` | Every session |
| `docs/DECISION_LOG.md` | Before changing an earlier decision |
| `docs/KNOWN_ISSUES.md` | Every session |

## Non-negotiable rules

### Process
- Never silently change an approved business rule or architectural decision. Propose it, get the owner's
  agreement, then log it in `DECISION_LOG.md`.
- Work phase by phase as laid out in `IMPLEMENTATION_PLAN.md`. Do not start a later phase's major module early.
- If a requirement is unclear, investigate, write the assumption down (in the relevant doc), then implement.
  Ask the owner only when the answer materially changes business behaviour or architecture.
- A feature is **done** only when: UI exists, backend exists, migrations are correct, validation exists,
  backend permission checks exist, business logic works, errors are handled, tests pass, docs are updated.
  Never report an untested feature as complete.
- No dummy buttons, no non-functional screens, no features added to look bigger.

### Stack boundaries
- Backend: PHP + Laravel (modular monolith), REST API. Frontend: React + TypeScript + Vite + Tailwind,
  compiled and served by Laravel. Node.js is a **build tool only** — no Node server, no Next.js, no
  microservices, no paid APIs by default.
- Database: MySQL in production; every migration and query must also run on SQLite (future Windows build).
  Follow the portability rules in `TECHNICAL_ARCHITECTURE.md`.
- Do not install Laravel Boost or other scaffolding that rewrites this file. (The Laravel skeleton ships its
  own `CLAUDE.md`/`AGENTS.md`; they were removed on purpose.)

### Money, quantity, stock
- Money amounts are **integer paise**. Stock quantities are **integers in the product's base unit**.
  Per-unit rates are decimals handled only through the Money/Rate helpers — never PHP floats, never summed in SQL.
- Every stock change is a row in `inventory_movements` with a source. Stock is never edited directly.
- Every financial or stock operation runs inside one database transaction.
- Finalised documents (sales, purchases, payments, ledger entries) are never edited or deleted. Corrections
  are made with cancellation, return, reversal or adjustment entries that reference the original.
- Sales store the actual prices, tax rates, batch and cost at the time of sale. Profit uses actual cost and
  actual selling price, never MRP.
- No negative stock unless the store setting explicitly allows it.
- Tax rates, drug schedules, alert periods and invoice formats are **data/configuration**, never hardcoded.

### Medicines
- Medicines are batch-tracked with expiry. Expired stock can never be sold; the backend enforces this.
- The software never substitutes a medicine, never recommends treatment, never makes a medical decision.
  "Same composition" lists are informational and only for pharmacist-verified compositions.
- Restricted-schedule sales must capture the records the schedule's configuration requires.
- Anything that needs confirmation from the Drug Inspector / FDA / a CA is marked as such in the docs — do
  not present it as settled law.

### Security
- Authorisation is enforced on the backend (policies/permissions) for every action. Hiding a button is not security.
- All input is validated server-side. Uploads are type- and size-checked and stored outside the web root.
- A backup is "successful" only after the produced file has been verified.

### UI
- The POS screen is the most important screen. An ordinary sale must complete on one screen.
- Compact and modern (owner decision D-038): 14 px text and 38 px controls on a PC, larger on phones; few fields,
  plain words (Sell, Purchase, Stock, Udhar, Day Closing). The sale screen keeps a large search box and Pay button.
- All user-facing text goes through the translation layer (English, Marathi, Hindi). No hardcoded strings.
- Barcode scanning is always optional; manual search must be equally practical.

### Honesty about capabilities
- Do not claim offline support, GST filing/e-invoice compliance, or regulatory compliance that has not
  actually been implemented and tested.

## Commands

Run from the project root.

| Task | Command |
|---|---|
| First install | `composer install` · `npm install` · copy `.env.example` to `.env` · `php artisan key:generate` · `php artisan migrate` |
| Run locally | `npm run build` then `php artisan serve` → http://localhost:8000 (first visit shows shop setup) |
| Frontend with live reload | `npm run dev` in a second terminal alongside `php artisan serve` |
| Backend tests | `php artisan test` (SQLite in memory) |
| Frontend tests | `npm test` |
| Type check | `npm run typecheck` |
| PHP code style | `vendor/bin/pint` |
| Load the demo shop | `php artisan migrate:fresh` then `php artisan db:seed --class=DemoDataSeeder` (logins `owner` / `pharmacist` / `manager` / `cashier`, password `demo-pass-123`) |
| Reset local database | `php artisan migrate:fresh` (destroys local data — never on a real shop database) |

All four checks (backend tests, frontend tests, type check, pint) must pass before a task is called done.

## Code map

- `app/Domain/Shared/` — Money, TaxCalculator, FinancialYear, NumberSequencer, Audit. Use these; do not re-implement.
- `app/Domain/Shared/Rate.php` (paise per base unit) and `ShopClock.php` (today in shop time).
- `app/Domain/Inventory/StockLedger.php` — the only code that may change stock. Never write `qty_sellable` / `qty_unsellable` any other way.
- `app/Domain/Sales/SalePricer.php` — prices a cart (batches, tax, round-off). Used by both the preview and `CompleteSale`; never duplicate its maths in the frontend.
- `app/Domain/Sales/ReturnPricer.php` — prices a return from the bill's own line amounts (never today's prices); `ReturnSale` and `CancelSale` in `app/Actions/` own the transactions. Sales, bill lines, returns and return lines refuse updates at the model level.
- `app/Domain/Parties/CustomerLedger.php` — the only code that may change a customer's `balance_paise`.
- `app/Domain/Reports/` — ReportRunner (every report as one table; add a report here and in `features/reports/api.ts`), ReportExporter (CSV / Excel / PDF), ReportLabels (export words from the locale JSON files), DashboardData.
- `app/Domain/Catalog/` — ProductQuery (stock subqueries that run on MySQL and SQLite), ProductPresenter, Barcode.
- `app/Exceptions/BusinessRuleException.php` — throw for a rule refusal; answered as HTTP 409 with a `code` the screens translate under `errors.<code>`.
- `app/Actions/` — one class per business operation, each owning its transaction (SetUpShop, SaveProduct, AddOpeningStock, AdjustStock, CompleteSale, ReceiveCustomerPayment).
- `app/Http/Controllers/Api/V1/`, `app/Http/Requests/`, `app/Http/Middleware/` (`idempotent`, `active`).
- `app/Enums/Permission.php` — every permission name; `RoleName.php` — default role permissions.
- `resources/js/` — `app.tsx` (routes), `features/<module>/`, `components/ui.tsx`, `lib/` (api, money, i18n, screen), `locales/{en,mr,hi}.json`.
- `resources/js/components/Mascot.tsx` — Sathi, the mascot (moods: hello, happy, empty, sad). `Spinner` in `ui.tsx` draws skeletons (`shape`), `EmptyState` shows the mascot. Motion classes live in `app.css`.
- `resources/js/components/CameraScanner.tsx` (`ScanButton`, `CameraScanner`) and `lib/barcodeReader.ts` — camera barcode reading. Put `ScanButton` beside a box a scanner would type into; return a `ScanResult` from `onCode` to keep scanning, nothing to close after one code.
- `build/windows/` — Windows desktop package: `make-desktop.ps1` (assembles PHP + app + launcher, zip or Inno installer), `launcher/Launcher.cs` (compile with the C# compiler in `C:\Windows\Microsoft.NET\Framework64\v4.0.30319`), `aushvan.iss`.
- `resources/js/features/dashboard/charts.tsx` — the only charts; follow the chart rules in `UI_UX_GUIDELINES.md`.
- `resources/css/app.css` — colour tokens. Use token classes (`bg-primary`, `text-ink-soft`, `border-line`), never raw Tailwind colours such as `bg-blue-600` or `slate-*`.
- Sidebar entries live in `resources/js/layout/AppLayout.tsx`; add an entry only when its screen works.
- Tests: `tests/Unit`, `tests/Feature`, and `*.test.ts(x)` beside frontend code. `Tests\TestCase::userWithRole()` creates a user with a role.

## Conventions learned so far

- `Model::shouldBeStrict()` is on outside production: after `Model::create()` call `->refresh()` before reading columns you did not set.
- Every new translation key goes into `en.json`, `mr.json` and `hi.json`; tests fail if they differ or a placeholder is lost.
- Roles are data: validate role names against the `roles` table, not the `RoleName` enum (custom roles exist). Only an owner may change roles.
- A new permission needs a label under `roles.permission.<name with _ for .>` in all three languages; a new report needs `reports.name`, `reports.about` and `reports.col` entries. Tests check both.
- Shell heredocs and inline `node -e` break on quotes and backslashes (PHP namespaces were mangled twice). Write a script file with the editor tool, then run it. Prefix Git Bash commands that pass URL paths with `MSYS_NO_PATHCONV=1`.
- Screenshots of the running app: `node <scratchpad>/shots.mjs` drives headless Chrome (see KNOWN_ISSUES P2). Look at the screen after changing layout.
- A report column the user may not see must be absent from the rows too (`ReportRunner::run` strips them); never hide data only in the headings or only on the screen.
- Counting failed attempts (PIN, login) must happen outside the database transaction that fails, or the count is rolled back with it (the cache is in the database).
- After signing out set the "nobody signed in" state before clearing cached data; clearing first detaches the screen.
- A dialog that must survive another dialog opening in front of it uses `Modal keepMounted`; unmounting it loses what was typed (the Pay dialog lost its amounts this way).
- Number boxes keep the typed text in their own state (`QtyBox`); `Number(x) || 1` in an `onChange` makes the box impossible to empty.
- After a request with no answer, never let the operator change the form and resend under the same request key: freeze it, or treat a mismatching answer as a warning.
- Search results that Enter acts on must be ranked for the operator (name match first, in stock first), not alphabetically.
- Unit words: `unit(code, count)` gives "strip" for 1 and "strips" otherwise; use `unit(code, 1)` after "per" or "/".
- A fixed bar that exists only on small screens is rendered through `useIsNarrow(1023)`, not hidden with CSS, so tests and screen readers do not meet the same button twice.
- Run the backend suite on MariaDB too (`DB_CONNECTION=mysql DB_PORT=3307 DB_DATABASE=rmgsms_test DB_USERNAME=root php artisan test`; WAMP's server on this PC). Its default engine is MyISAM — the connection forces InnoDB. MySQL does not reset ids between tests, and returns unordered rows in another order than SQLite: never assert on ids or on unsorted lists.
- Phones open the shop PC over plain http, which has no "secure context" APIs: never use `crypto.randomUUID`, `navigator.clipboard` or the live camera without a fallback; test on http://192.168.0.108:8000, not only localhost.
- Windowed programs started from the developer tool (the Windows launcher) are suspended by the tool's sandbox: resume them with `NtResumeProcess` to test, or have the owner double-click them. Never commit `build/windows/dist`, `stage` or `out`.
- Anything that adds to the bill from a stream of events (camera frames) needs a per-code memory, two readings running, and a "still open" check after every await; one remembered "last code" let two barcodes in view add the same item repeatedly.
- Query-string numbers arrive as strings: cast them before date or arithmetic use (a crash found by testing with demo data).
- Create and change files with the editor tools, not shell heredocs or inline scripts (quotes broke several), and never pipe long JSON through `cut`; use `head -c`.
- `products.test.tsx` fails if a text key used on a screen has no English text; the key-parity test fails if Marathi is missing one.
- The shared `Modal` reports only a user-requested close (Esc or the X). Do not wire the native dialog `close` event to state.
- In backend tests one user object is reused across requests: after changing the shop, call `$user->unsetRelation('store')`.
- ULIDs use Crockford base32 (no I, L, O, U); the server rejects anything else.
- API requests from tests need the `Origin` header (set in `Tests\TestCase`) so the session-cookie login applies.

## Environment notes

- Development machine: Windows 10, PowerShell. PHP 8.4, Composer 2, Node 26, git are installed.
  MariaDB 10.10 runs as the WAMP service `wampmariadb64` on port 3307 (root, no password); test database `rmgsms_test`.
