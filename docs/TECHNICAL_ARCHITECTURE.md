# Technical Architecture

Status: **accepted 2026-11-11** (owner said to continue; no changes requested). Versions verified on 11 Nov 2026.

## Stack

| Layer | Choice | Notes |
|---|---|---|
| Language / framework | PHP 8.4, Laravel 13 | Laravel 13 requires PHP ≥ 8.3. |
| Database | MySQL 8 / MariaDB (production), SQLite (tests, desktop) | One set of migrations. |
| Auth | Laravel Sanctum, cookie-based SPA sessions | Same origin; no tokens in browser storage. |
| Permissions | `spatie/laravel-permission` | Roles and permissions as data. |
| Exact arithmetic | `brick/math` | Rates and tax maths without floats. |
| Frontend | React 19, TypeScript, Vite 8, Tailwind CSS 4 | Built to static assets served by Laravel. |
| Routing / data | React Router, TanStack Query | Caching, retries, request state. |
| Forms / validation | React Hook Form + Zod | Mirrors server validation; server is authoritative. |
| UI primitives | Radix UI + own Tailwind components, Lucide icons | Accessible, light, no heavy kit. |
| Charts | Recharts (lazy-loaded) | Dashboard and reports only. |
| i18n | i18next / react-i18next | `en`, `mr`, `hi` JSON files. |
| Barcodes | JsBarcode (labels) | Generated in the browser. |
| Camera scanning | Browser `BarcodeDetector` where it exists (Chrome on Android); otherwise `barcode-detector` (ZXing compiled to WebAssembly) | `lib/barcodeReader.ts`. The reader and its 0.9 MB engine are separate files fetched on first use from the application itself (the library's default CDN address is overridden). EAN-13, EAN-8, UPC-A and Code 128 only (all have a check digit); `barcode-detector` and `zxing-wasm` are pinned to exact versions because the two must match. No server part: the code goes through the same `/pos/search` lookup as a plug-in scanner. |
| Import / export | CSV built-in; `openspout/openspout` for XLSX | Streams, low memory. |
| Tests | Pest/PHPUnit (backend), Vitest + Testing Library (frontend), Playwright smoke tests for the 15 scenarios | |

No Node.js at runtime. No Redis, no websockets, no queue workers required. Scheduler tasks (expiry check,
backup, integrity check) run from one cron entry and are also triggerable from the UI.

## Shape

One Laravel application, modular monolith:

```
app/
  Domain/                     business logic, no HTTP
    Catalog/                  products, units, barcodes, categories, tax rates, medicine details
    Inventory/                batches, movements, adjustments, stock takes, expiry
    Sales/                    cart pricing, sale completion, returns, cancellation, registers
    Purchasing/               purchases, purchase returns
    Parties/                  customers, suppliers, ledgers
    Cash/                     payments, expenses, cash sessions
    Reporting/                read-only queries
    Shared/                   Money, Rate, Qty, TaxCalculator, NumberSequence, Idempotency, Audit
  Http/
    Controllers/Api/V1/       thin: validate -> authorise -> call action -> resource
    Requests/  Resources/  Middleware/
  Models/  Policies/  Enums/
resources/js/                 React SPA
  app/ (routes, providers)  features/<module>/  components/ui/  lib/ (api, money, i18n)  locales/
```

Each module exposes **action classes** (`CompleteSale`, `ConfirmPurchase`, `ReceiveCustomerPayment`,
`ReturnSaleItems`, `CloseDay`…). An action owns its database transaction and is the only place that writes
stock movements, payments and ledger entries. Controllers and future clients (import, sync) call the same actions.

## API conventions

- Base path `/api/v1`. JSON. Resource-style routes plus explicit action routes
  (`POST /sales/{ulid}/complete`, `POST /sales/{ulid}/cancel`, `POST /purchases/{ulid}/confirm`).
- Money in JSON is integer paise; quantities are integers with a unit; the frontend formats.
- Errors: 422 with field errors; 409 for business conflicts (insufficient stock, expired lot, stale
  version, day closed) with a machine code and a translated message key; 403 for permission.
- **Idempotency:** every state-changing POST carries an `Idempotency-Key` (the document's client ULID).
  The server stores the key with the result; a repeat returns the stored result.
- Lists are paginated; search endpoints are limited and debounced.
- POS product search is served from a compact product index cached in the browser (refreshed in the
  background and after changes) so typing feels instant; stock and lots are confirmed by the server on add
  and again on completion.

## Money, quantity and tax rules

1. **Amounts** are integer paise end to end. **Quantities** are integer base units.
2. **Line amount** = qty × unit price, where unit price is paise per sale unit. For derived units:
   `round(qty_base × pricing_unit_price ÷ pricing_unit_factor)` — round half up to the paise.
3. **Retail prices include tax** (MRP is inclusive by law). For a line with rate r%:
   `taxable = round(line_after_discount × 100 ÷ (100 + r))`, `tax = line_after_discount − taxable`,
   CGST = floor(tax ÷ 2), SGST = tax − CGST. Computed per line; bill totals are sums of lines.
4. **Bill round-off** to the nearest rupee is a separate `round_off_paise` line (setting; default on).
5. **GST mode** of the shop decides the document:

   | Mode | Document | Tax shown |
   |---|---|---|
   | Unregistered | Cash memo, no GSTIN | None. Prices are final. |
   | Composition | Bill of Supply with the statutory line "composition taxable person, not eligible to collect tax on supplies" | None |
   | Regular | Tax Invoice / Bill of Supply / Invoice-cum-Bill of Supply (mixed taxable + exempt to an unregistered buyer) | CGST + SGST per rate; IGST only for an out-of-state buyer address/GSTIN |

6. **Purchases** are entered with tax-exclusive rates as printed on the supplier invoice. Lot cost per base
   unit = taxable value ÷ (qty + free qty) in base units; when the shop cannot claim input credit
   (unregistered/composition), GST is added into cost.
7. **Profit** = Σ(line taxable value or, when no tax is charged, line amount) − Σ(cost of lots sold).
   Labelled "gross profit (approx.)". Net result = gross profit − expenses, labelled separately.
8. Tax rates, HSN and schedule are copied onto each line at the time of the transaction.
9. The software prints correct documents and tax summaries. It does not file returns, generate e-invoices
   or claim GST compliance beyond the document contents.

GST facts relied on (verified against CBIC/GST Council text unless marked): slabs Nil / 5% / 18% / 40%
since 22 Sep 2025, medicines generally 5% with some nil; Rule 46, 46A, 49, 53 document contents; invoice
number ≤ 16 characters, consecutive, unique in a financial year; credit notes in their own series; no
invoice needed under ₹200 for unregistered buyers who do not ask (not applied to medicines — the drug rules
need a memo for every drug sale); buyer name/address mandatory from ₹50,000; HSN optional on B2C bills up
to ₹5 crore turnover; e-invoicing and dynamic QR do not apply to a shop this size (secondary source);
records kept 72 months. Items for the shop's CA are listed in `KNOWN_ISSUES.md`.

## Database portability (MySQL ⇄ SQLite)

- Integers for money and quantity; decimals only for rates, read as strings.
- No `ENUM`, no generated columns, no full-text indexes, no MySQL-only JSON functions.
- No `DB::raw` date functions (`DATE_FORMAT`, `YEAR`, `strftime`). Date ranges are computed in PHP and
  passed as bounds; grouping by day/month uses stored `business_date` columns.
- Search uses a lower-cased `search_text` column with `LIKE` (prefix and contains) — behaves the same on both.
- Stock and counters use atomic conditional updates inside transactions. `lockForUpdate` is used for MySQL;
  SQLite is single-writer (WAL mode, `busy_timeout`, foreign keys on).
- Migrations are additive after first release; column changes are tested on both engines.
- The full backend test suite runs on SQLite on every change and on MySQL before each milestone is declared done.

## Frontend structure

- Single-page app mounted from one Blade view; Laravel serves `/build` assets; all other non-API routes
  fall through to the SPA.
- Feature folders own their pages, hooks and API calls. Shared UI lives in `components/ui`.
- Permissions arrive with the user profile and gate routes and buttons; the backend re-checks everything.
- POS state lives in a small store persisted to `localStorage` after every change (cart recovery).
- Fonts and icons are bundled; the app makes no third-party network requests.
- Print views are dedicated routes/components with print CSS for 58 mm, 80 mm and A4.

## Roles (seeded; editable)

| Role | Default permissions |
|---|---|
| Owner | Everything, including settings, users, reports, audit history, backups, reopening a closed day. |
| Pharmacist | Sales incl. restricted medicines, prescription records, registers, medicine master, expiry handling, restock decision on returned medicines. |
| Store Manager | Purchases, suppliers, stock, adjustments, products, expenses, stock reports. |
| Cashier | Sales, customer search/add, receive payment, product lookup, returns within a configurable limit. No cost prices, profit or settings. |

A single user may hold several roles; a one-person shop uses one Owner account (optionally flagged as pharmacist).

## Background work

| Task | When | How |
|---|---|---|
| Expiry classification, low-stock snapshot | Daily + on demand | Scheduler / button |
| Integrity check (stock vs ledger, balances vs ledger) | Daily + at login for Owner | Scheduler |
| Backup + verification | Daily (if hosting allows) + at day closing + manual | Scheduler / button |

All are short synchronous commands; nothing depends on a queue worker.

## Deployment

Shape A (hosted) and shape B (Windows local) from the same code — see `OFFLINE_WINDOWS_STRATEGY.md`.
Build: `composer install --no-dev`, `npm ci && npm run build`, `php artisan migrate --force`, cache config/routes.
Deployment instructions are written in Phase 8.
