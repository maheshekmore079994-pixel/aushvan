# Implementation Plan

Legend: `[x]` done and verified · `[~]` in progress · `[ ]` pending · `[!]` blocked.
A feature is ticked only when it meets the definition of done in `CLAUDE.md`.

## Current status

- **Phase:** 3 — POS and billing (core built). On 2026-11-11 the owner reviewed the screens and asked for a
  better look, an analytics dashboard, quick links, strong stock reports, Excel/CSV/PDF export, barcode label
  printing, role management, Hindi, and phone-friendly screens, pointing at MargBooks and Vyapar as references.
  That work was done next, ahead of the planned order (decision D-030): it brought forward the reports from
  Phase 7, the role screen from Phase 8, Hindi and label printing from V1.
- **Not built:** hold/resume, line discounts on the sale screen, restore screen,
  Windows installer (zip exists), mobile app. Purchases and suppliers (Phase 4) and backup were built on 2026-11-26.
- **Phases 1–3 are not closed.** Open for all: no
  bill, report or label has been printed on a real printer. Screens were checked from screenshots of the running
  app (laptop and phone widths, three languages) by the developer tool, not yet by the owner.
- **Demo shop:** `php artisan migrate:fresh` then `php artisan db:seed --class=DemoDataSeeder`. Logins
  `owner`, `pharmacist`, `manager`, `cashier`, password `demo-pass-123`; pharmacist PIN `1234`. 80 products
  with stock, 6 customers, 62 bills over 14 days (10 today: cash, UPI, card, split, udhar, prescription).
- **Next task (owner instruction of 2026-11-26: finish the remaining modules, then host on the friend's cPanel server and demo):**
  owner review items of 2026-11-27 first: a consistency pass over fonts, spacing and card styles on the newer screens, and a livelier dashboard; then POS extras (hold / resume, discount limits per role, price override), the restore screen, the Android APK wrapper (shared directly, no Play Store) once the site is on https; hosting and the demo as soon as the cPanel details arrive. (doctor master, H1 register, expiry actions, licence
  on bills), POS extras (hold/resume, discount limits), restore screen; then the hosted deployment (subdomain on
  naturepestservices.com, https, cron for backups) and the demo. Desktop: owner test of the zip and the Inno installer still pending.
- **2026-11-27 (owner review):** branding footer on every screen and the sign-in page; language choice removed from sign-in; "Fully paid" reworded; mascot named Ashu and its origin logged (D-066); menu-collapse preference saved per user (`PUT auth/preferences`); Settings → Doctors screen (list incl. hidden, add, edit); one MySQL-only migration rollback bug fixed (composite index on prescriptions).
- **2026-11-26 (Phase 6):** doctor master on the prescription step, prescription serial numbers, prescription-sales / H1 register / sales-by-doctor reports, licence-fee reminder, return-to-supplier from Expiry (D-065). Review of the udhar corrections fixed two real issues (a reversed payment now mirrors its cash row out; a relative backup folder resolves from the application folder) and added the overdue-days setting to Shop details.
- **2026-11-26 (Phase 5 extras + hosting):** opening balances, reversal and adjustment entries, overdue flag (D-063); hosting package and cPanel guide (D-064, `docs/HOSTING_CPANEL.md`), proven by unpacking the zip and running `post-upload.sh` against MariaDB on this PC. Waiting on the owner for the cPanel subdomain, database and access.
- **2026-11-26 (Phase 7):** day closing (cash day with stamp-and-adopt payments, D-062), cash movements, expenses with heads, two reports, dashboard tile and card. Reviewer: 11 findings (reopen without the shop lock, a payment lost at the closing second, expense refunds counted as udhar in sales-by-day, stale fields after reopen, duplicate heads) — fixed; the tester agent was stopped by the usage limit, so the robustness tests (8) were written by the developer. 15 backend tests, 4 frontend tests.
- **2026-11-26 (Phase 4):** suppliers with MR contacts, purchases (draft → confirm → stock), supplier payments, purchase cancel and returns (D-061); 3 purchase reports and a supplier-dues card. Review + tester round: reviewer 14 findings (MRP put back on cancel, return taxed at purchase-time rate, batch returnable only to its supplier, no float in price history, locked draft delete, product checks on confirm, bounded dates and percents), tester 24 tests and one bug (zero-total confirm crashed); all fixed. 42 backend tests for the module.
- **2026-11-26 (later):** desktop zip rebuilt with Backup and proven with the bundled PHP (migrate, demo seed, Marathi PDF, verified backup, restore) and by starting the launcher over the 22 Nov install; package slimmed to 131 MB / 50 MB zip (D-060); build script no longer packs development backups or uploads.
- **2026-11-26:** backup to a USB stick / second disk with verification, automatic daily copy, restore command (D-059); desktop zip must be rebuilt to include it. Review + tester round: 16 findings, all confirmed ones fixed (folder check on real paths before anything is created, restore source protected from retention, one backup at a time, failed attempts leave no file, volatile tables left out, uploads checksummed, auto-backup back-off, counts checked inside the restore transaction, folder errors as translated codes); 39 backup tests (`BackupTest`, `BackupRobustnessTest`).
- **2026-11-22 (later):** phone sign-in from any shop-network address (D-058), after the Wi-Fi changed.
- **2026-11-22:** scan-to-add products (D-056), phone fixes (request keys on plain http, visible errors), demo
  shop configurable (`DEMO_*` in .env; currently Jay Mahakali Medical and General Stores, Khadavli), first
  Windows package built and proven (KNOWN_ISSUES D1–D6).
- **MySQL:** the suite now runs on the MariaDB found on this machine (WAMP, port 3307); see KNOWN_ISSUES P1.
- **Blocked on:** nothing. Awaiting the AUSHVAN logo file, the answers to Q2, Q3, Q5, the cPanel details (subdomain, database, FTP) and the mascot gender (Ashwini / Ashwin, D-066).

## Open questions for the owner

| # | Question | Default if unanswered |
|---|---|---|
| Q1 | How reliable is the shop's internet, and does the owner need to see data from outside the shop? Decides whether the pilot is hosted or installed on the shop PC (see `OFFLINE_WINDOWS_STRATEGY.md`). | Build hosted first as the brief says; Windows local in Phase 9. |
| Q2 | GST status of the shop: unregistered, composition, or regular? | Support all three; develop with *regular*. |
| Q3 | Which drug licences does the shop hold (20/21/20F), is a registered pharmacist present, and are H1 or Schedule X drugs sold? | 20 + 21, pharmacist present, H/H1 sold, X not sold. |
| Q4 | Answered 2026-11-19: yes. MariaDB was already present (WAMP); the suite runs on it. | — |
| Q5 | Printer at the shop: thermal (58 or 80 mm) or A4? Model if known. | Support all three layouts; default 80 mm. |

## Phase 0 — Research and product discovery

- [x] Inspect project directory (empty) and development toolchain
- [x] Create `CLAUDE.md` and the 13 documents in `docs/`
- [x] Research: pharmacy regulation (Drugs Rules 1945, 2026 amendments)
- [x] Research: GST invoicing and DPDP
- [x] Research: Windows packaging, SQLite/MySQL portability, current framework versions, hosting
- [x] Product requirements with MVP scope
- [x] Business workflows for the 15 scenarios
- [x] Architecture comparison and recommendation
- [x] Database design with ER diagram
- [x] UI wireframes (text)
- [x] Owner review — owner instructed "continue" on 2026-11-11; plan taken as accepted with defaults
- [ ] Shop-side confirmations listed in `KNOWN_ISSUES.md` (Drug Inspector, CA) — can proceed in parallel with development

## Phase 1 — Project foundation

- [x] Laravel 13.42 project, git repository (nothing committed yet), Pint code style, TypeScript strict
- [x] React 19 + TypeScript + Vite 8 + Tailwind 4 wired into Laravel; SPA shell served by Laravel; fonts bundled
- [~] Test harness: PHPUnit on SQLite (104 tests pass), Vitest (12 pass). **MySQL run pending (Q4).**
- [x] Shared kernel: Money, TaxCalculator, FinancialYear, NumberSequencer, Idempotency middleware, Audit — tested.
      (Rate and Qty helpers are added in Phase 2 with the first tables that use them.)
- [x] Migrations: stores, settings, users, sessions, roles/permissions, audit_logs, number_sequences, idempotency_keys.
      (`attachments` moves to the first phase that uploads a file.)
- [x] First-run setup, login/logout, session cookie auth with CSRF, login lockout, switched-off users lose access
- [x] Roles and permissions seeded; routes gated on the backend; menu filtered by permission
- [x] Base components (button, fields, card, badge, alert, modal, toast), layout, sidebar, top bar with connection status
- [x] i18n with English + Marathi for every screen so far; language switch saved per user
- [x] Settings: shop details, GST mode + GSTIN checks, drug licences, bill footer, counter letter; users and roles
- [x] Dashboard: "getting your shop ready" checklist from real data. Sales/cash/stock cards are added by the
      phases that create that data — no placeholder numbers.
- [x] Commands and code map in `CLAUDE.md`
- [ ] Owner looks at the screens in a browser (not verifiable by automated tests)

Not built in Phase 1, moved with reason: role-permission editing screen (Phase 8, with audit history —
roles are editable in data and seeded defaults are in use); React Hook Form/Zod/Radix (adopted when the
POS and purchase forms need them; current forms are small).

Exit: a user can set up the shop, log in, switch language, manage users; tests green on both databases.

## Phase 2 — Products and inventory

- [~] Categories, manufacturers, units, tax rates, drug schedules — tables, starting data (seeded at shop
      setup) and use in the product form are done. **Settings screens to edit them are not built.**
- [x] Products (medical + general) with base unit + one pack unit, barcodes (several per product, shop-made
      barcode), medicine details, SKU, search by name / salt / Marathi name / barcode
- [x] Duplicate detection (Scenario 15) with "save anyway"; similar-name hint while typing; price history
- [x] Stock lots, append-only movement ledger (`StockLedger` is the only writer), opening stock entry
- [x] Stock views: Products, Medical Stock, General Stock, product page with batches and history, low/out filters
- [x] Stock adjustment: set aside (not for sale), damage write-off, expiry write-off, count correction — reason required, audited
- [x] Expiry Alerts screen (expired / 30 / 60 / 90 days / not for sale) with set-aside and write-off.
      Brought forward from Phase 6; return-to-supplier arrives with Phase 4.
- [x] Dashboard stock cards and alert strip
- [~] CSV export done. **CSV import (template, dry run) not built.**
- [~] Integrity check: runs for the owner on the dashboard summary (`ledger_mismatches`). A scheduled
      command and customer/supplier balance checks come with those ledgers.
- [x] Demo seed data — 40 medicines and 40 general items (smaller than first planned; enough to show every
      stock situation). Created through the real actions and covered by a test.
- [x] Tests: 131 backend (SQLite), 30 frontend, incl. Scenario 15 and the stock side of Scenario 9
- [ ] More than one pack level per product (strip → box) — needed for purchase entry in Phase 4
- [ ] Barcode label printing, bulk price update, physical stock count screen (all V1, after MVP)

## Phase 3 — POS and billing

- [x] Sale-screen product search (name, salt, Marathi name, barcode), scanner Enter adds the item, quick items
- [x] Cart: units (pack / loose), batch choice with first-expiry-first default, quantity; totals priced by the
      server (`SalePricer`) so screen and saved bill cannot differ. **Line discount: backend only, no control on screen yet.**
- [x] Payment dialog: cash / UPI / card / bank, split, udhar as the unpaid remainder, change to give back
- [x] `CompleteSale`: stock by batch, payments, udhar ledger, bill number, safe to repeat (cart id)
- [x] Cart survives a closed browser or power cut and re-sends the same id (Scenario 14)
- [x] Bill view and print (one layout sized for 80 mm, also prints on A4), reprint from history.
      **58 mm and a full A4 invoice layout are not built.**
- [x] Sales history with search by bill number, customer or product, and by date
- [x] Customers at the counter (quick add, duplicate-mobile warning), udhar list, statement, receive payment (Scenario 7)
- [x] Prescription details and pharmacist approval (own login or PIN) for medicines whose schedule needs them
- [x] Customer returns (credit notes) with refund or udhar credit, restock rules, printable credit note (2026-11-19, D-051)
- [x] Bill cancellation, same day, with reversal rows and a cancelled banner on the bill
- [ ] Hold / resume
- [ ] Line and bill discount controls with permission limits; price override
- [ ] Tests done: Scenarios 1, 2, 3, 4, 5, 7, 14 (backend) and the sale screen (frontend). Pending: 10, hosted part of 13.

## Owner-requested work, 2026-11-11 (brought forward — D-030)

- [x] New look: calmer colour tokens, dark side menu, cards, focus rings, sign-in picture, icon tiles, empty states
- [x] Phone and tablet layout: bottom bar (Home, Stock, Sell, Udhar, More), slide-in menu, product list as cards
      on phones. **Other tables (sales history, udhar, expiry, users, reports) scroll sideways on a phone.**
- [x] Hindi: every screen text in English, Marathi and Hindi; a test keeps the three files in step
- [x] Dashboard: today / month sales with comparison, udhar to collect, profit (permission), 14-day chart, money
      received by method, medicine vs general split, best sellers, to-order list, expiring soon, latest bills,
      biggest udhar. Each block is sent only to users allowed to see it.
- [x] Quick links: tiles on the dashboard, a "Quick links" button on every screen, shortcuts in the phone bar
- [x] Reports (10): daily sales, sales by product, bill register, GST summary, stock summary, to order, expiry,
      slow-moving stock, stock movements, udhar to collect — date presets, type filter, sortable, totals, print
- [x] Export of every report as Excel (.xlsx), CSV and PDF, in the chosen language; each export is audit-logged
- [x] Roles: owner edits what each role may do, adds and deletes custom roles; every change audit-logged
- [x] Barcode labels: choose products and counts, five sticker sizes (A4 sheets and label rolls), price and shop
      name optional, one-press shop barcode for products without one. **Not yet printed on real sticker paper.**
- [x] Installable from the browser (web app manifest and icon). **No offline use — that is Phase 9.**
- [x] Second look, same day (D-038): compact density, Inter font, light collapsible menu, search with Ctrl+K,
      account menu; sign-out bug fixed
- [x] Review round 1 (D-043): 15 reviewer findings and the tester's findings fixed, each with a test
- [x] Polish, 2026-11-12 (D-044, D-045): mascot "Sathi", skeleton loaders on every screen, motion, calmer
      dashboard stock strip and alerts
- [x] Review round 2 (frontend): 14 findings on the sale screen, session and forms; fixed with tests, the rest
      listed in KNOWN_ISSUES (F1–F5). A browser tester then went through every screen.
- [x] Browser test of every screen (laptop and phone, three languages, four roles): 3 high and 7 other findings
      fixed; the rest are U1–U8 in KNOWN_ISSUES
- [x] Camera barcode scanning on the sale screen, product list and product form (2026-11-13, D-050): live camera
      on a secure address, photo of the barcode otherwise. Checked in Chrome with a simulated camera; not yet on a
      real phone (KNOWN_ISSUES C1–C7). Reviewed: 12 findings, 11 fixed (tests for the money ones: double adding with
      two barcodes in view, late add after closing, single-frame misreads, over-stock, button on a PC without a
      camera; the sound, lost-camera, retry and focus fixes have no automated test), 1 listed as C6
- [x] Tests: 230 backend (SQLite and MariaDB) including 30 + 25 robustness tests from the tester and 9 review-fix tests; 71 frontend
- [x] Browser test of 2026-11-22 on the phone address (every screen, cashier and owner, English and Marathi, 15 findings): 9 fixed, the rest are T1–T5 in KNOWN_ISSUES
- [x] Returns review and test round (2026-11-19): reviewer found 7 (duplicate line doubled the refund — high; per-note round-off; lock order; soft-deleted customer; 3 low), tester found 1 (ledger silently skipped a deleted customer), screen reviewer found 8 (a stale "to give back" figure could be confirmed for a moment; expired general goods defaulted to the shelf; duplicate labels for two lines of one product; 5 low) — all fixed, the money ones with tests; the rest are X1–X10 in KNOWN_ISSUES
- [ ] Product photos (upload, thumbnails on lists and quick items)
- [ ] Batch-wise barcodes and labels with batch / expiry printed
- [ ] WhatsApp bill sharing, payment reminders (seen in both reference products)
- [ ] Settings screens for categories, tax rates, units, drug schedules; CSV product import

## Phase 4 — Purchases and suppliers

- [x] Supplier and MR contacts: name, company, phone, visit day, schemes offered (D-054) — built 2026-11-26; "visiting today" shown on the supplier list
- [x] Suppliers, opening balances — Suppliers screen, duplicate warning, ledger statement
- [x] Purchase draft/confirm, lot creation, cost calculation, price history (D-061) — Purchases screen, line editor with server-side preview, confirm with part payment
- [x] Supplier ledger and payments — on account or against one purchase; dashboard card and reports (purchases, purchases by item, supplier dues)
- [x] Purchase returns; purchase cancellation — debit notes from any bucket; cancel only while stock is untouched
- [x] Tests: Scenarios 6, 8, 11 — `PurchasesTest` (18) and `PurchaseRobustnessTest` (24); frontend `purchases.test.tsx`
- [ ] Not built: invoice photo attachment (F8), low-stock purchase suggestion list (F9), barcode scanning on the purchase screen, supplier picker beyond the first 25 suppliers (KNOWN_ISSUES Q1–Q4)

## Phase 5 — Customers and udhar

- [~] Customer management, opening balances, import — customers and opening balances built (D-063, 2026-11-26); CSV import not built
- [x] Udhar list, receive payment, advance, credit limit — built in Phase 3; days since payment and the overdue flag added 2026-11-26
- [~] Statement print; reversal entries — statement prints from the browser; reversal and adjustment entries built 2026-11-26 (D-063); WhatsApp share and receipt print not built
- [x] Tests: Scenario 7 — `SalesTest` (udhar flows) and `UdharExtrasTest` (5)

## Phase 6 — Medical compliance and expiry

- [x] Prescription capture step in POS; pharmacist verification (login/PIN) — built in Phase 3
- [x] Doctor master (name, reg. no., clinic, phone) picked on the prescription step; doctor-wise prescription-sales report (D-054) — built 2026-11-26 (D-065)
- [x] Register entries; H1 register and prescription sales record reports — built 2026-11-26: the registers are reports over the bill lines (serial, patient, doctor, batch, maker, expiry, pharmacist); Schedule X statement (P5) not built
- [x] Expiry Alerts screen and actions; daily classification — screen built earlier; "Return to supplier" added 2026-11-26; classification is computed on every query (no daily job needed)
- [x] Licence details on bills; licence reminder — licences print on bills since Phase 3; dashboard reminder from 60 days before the retention date added 2026-11-26
- [~] "Same composition" information list — the name-based similar-product search exists; a pharmacist-verified composition list is not built (needs verified composition data)
- [x] Tests: Scenarios 4, 9 — `SalesTest`, `ComplianceTest` (4), `StockTest` (expiry)

## Phase 7 — Expenses, day closing, reports

- [x] Expenses — built 2026-11-26 (D-062): heads per shop, entry with method and date, cancel with reason, period list by head, report
- [x] Open day / close day / reopen — built 2026-11-26 (D-062): Day Closing screen with the drawer arithmetic, cash in/out, counted cash and difference, history of closed days, owner reopen; dashboard tile and "Expenses today" card; reports "Expenses" and "Day closings"
- [~] Reports with print and export — sales, stock, GST and udhar reports are built (see above). Purchases,
      expenses, day book, cash register and the medical registers come with their modules.
- [~] Dashboard — built except purchases, expenses, supplier dues and the day open/closed state
- [ ] Tests: Scenario 12; report totals reconcile with ledgers

## Phase 8 — Production readiness

- [~] Backup, verification, restore (both databases) — built 2026-11-26 (D-059): archive + verifier + restorer, Settings → Backup, USB folder, automatic daily copy, `backup:run` / `backup:restore`; **pending:** restore screen (D7), encryption (D8), recovery rehearsal on the desktop build with the owner
- [ ] Security review (see `SECURITY_AND_BACKUP.md` checklist)
- [ ] Performance pass on a low-end PC with 5,000 products / 50,000 bills
- [ ] Playwright end-to-end run of all 15 scenarios
- [ ] Marathi translation complete; operator quick guide
- [ ] Installation and deployment documentation; pilot in the shop

## Phase 9 — Windows and offline

- [x] Package script `build/windows/make-desktop.ps1`: trimmed PHP runtime + production app + launcher → folder, zip, or Inno installer (D-057)
- [x] Launcher `AUSHVAN.exe`: first-run key and migrations, data in %LOCALAPPDATA%\AUSHVAN\data, server on 127.0.0.1, app window, tray, log, PHP dies with the launcher
- [x] Backup to USB (D3) — Settings → Backup, D-059; the launcher keeps local copies in `%LOCALAPPDATA%\AUSHVAN\backups`
- [x] Package size (D5): 131 MB / 50 MB zip after dropping the unused mPDF fonts (D-060); the zip carries no development data
- [ ] Owner runs the zip on a clean Windows PC (D1); Inno installer (D2)

- [ ] Proof of concept: NativePHP vs local-server install on the target PC; measure and decide
- [ ] SQLite production configuration; local backup to USB + off-site upload
- [ ] Printer and scanner tests on real hardware; silent receipt printing
- [ ] Installer, update procedure, recovery procedure
- [ ] Tests: Scenario 13 (local build) and 14 on the packaged application

## Rough effort

Relative size, to set expectations; not a commitment. One "unit" ≈ one focused development session with review.

| Phase | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 |
|---|---|---|---|---|---|---|---|---|---|
| Units | 3 | 4 | 6 | 3 | 2 | 3 | 4 | 3 | 3 |

Phase 3 is the largest and the one to spend review time on. A usable pilot exists after Phase 7; Phase 8
must be finished before real shop data depends on it.

## Scenario test map

| # | Scenario | Phase |
|---|---|---|
| 1 | Cash sale | 3 |
| 2 | Barcode sale | 3 |
| 3 | Non-barcode sale | 3 |
| 4 | Medical sale with controls | 6 (basic lot/expiry part in 3) |
| 5 | Mixed bill, per-line tax | 3 |
| 6 | Purchase | 4 |
| 7 | Customer credit | 5 |
| 8 | Supplier payment | 4 |
| 9 | Expired medicine | 6 |
| 10 | Customer return | 3 |
| 11 | Purchase return | 4 |
| 12 | Day closing | 7 |
| 13 | Internet failure | 3 (hosted), 9 (local) |
| 14 | Power failure / duplicate bill | 3 |
| 15 | Duplicate product | 2 |
