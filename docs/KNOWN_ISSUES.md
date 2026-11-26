# Known Issues, Limitations and Items to Confirm

Updated 2026-11-11 (Phases 1–2, the core of Phase 3, and the owner-requested redesign, reports, roles, labels and Hindi).

## Environment

| # | Issue | Impact | Action |
|---|---|---|---|
| E1 | MySQL/MariaDB is not installed on the development PC. | Production database cannot be tested locally; SQLite alone would hide MySQL-specific problems. | Owner to allow installation (question Q4) before Phase 1 closes. |
| E2 | PHP extensions checked 2026-11-11: `pdo_sqlite`, `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `bcmath`, `fileinfo`, `sodium` are all loaded. | None. | Closed. |
| E3 | Node 26 with Vite 8: build and tests run without problems (2026-11-11). | None. | Closed. |

## Phase 1 limitations

| # | Issue | Impact | Action |
|---|---|---|---|
| P1 | Closed 2026-11-19: the suite runs on MariaDB 10.10 (WAMP, port 3307, database `rmgsms_test`). It found two real problems, both fixed: the server's default engine was MyISAM (no transactions) — the application now forces InnoDB; and role names came back in a different order than on SQLite — now sorted. SKU tests assumed ids restart from 1. | — | Keep running both: `DB_CONNECTION=mysql DB_PORT=3307 DB_DATABASE=rmgsms_test DB_USERNAME=root php artisan test`. |
| P2 | Screens were checked by automated tests and from screenshots of the running app taken by the developer tool (laptop 1366 px and phone 390 px; dashboard, products, sale, reports, roles, labels). Not every screen and state was captured, and no person has used them yet. | Remaining layout problems are likely on the less-used screens. | Owner review. |
| P3 | Marathi text was written by the developer tool, not a native speaker. | Wording may be unnatural. | Review by a Marathi speaker before the pilot. |
| P4 | Validation messages from the server (for example "The username has already been taken.") are English only. | Marathi users see some English error text. | Add Laravel `mr` validation translations in Phase 2. |
| P5 | Closed 2026-11-11: Hindi added. Like the Marathi, it was written by the developer tool. | Wording may be unnatural. | Review by a Hindi speaker before the pilot. |
| P6 | Closed 2026-11-11: the owner edits roles on Settings → Roles. | — | — |
| P7 | The top-bar connection indicator uses the browser's online/offline signal. | It shows "Online" when the PC has a network but the server is unreachable; requests then show their own error. | Add a server heartbeat with the POS in Phase 3. |
| P8 | The counter PIN can be set but nothing uses it yet. | — | Used from Phase 3 (overrides) and Phase 6 (pharmacist verification). |
| P9 | The skeleton's `jobs`, `cache` and `personal_access_tokens` tables exist but are unused. | None. | Leave; harmless. |

## Phase 2 limitations

| # | Issue | Impact | Action |
|---|---|---|---|
| S1 | Closed 2026-11-11: billing is built. | — | — |
| S2 | Products cannot be imported from CSV/Excel; only exported. | Opening data must be typed product by product. | Next task. |
| S3 | No settings screens for categories, tax rates, units or drug schedules; the seeded lists apply. | A new GST rate, or enabling Schedule X, needs a developer. | Next task. |
| S4 | One pack level per product (tablet + strip). No box/carton unit. | Purchases in boxes cannot be entered yet. | Phase 4. |
| S5 | Opening stock assumes the cost typed includes GST; it has no supplier or invoice. | Acceptable for opening stock only. | Purchases (Phase 4) record supplier, invoice and tax. |
| S6 | Adjustment quantities are typed in the smallest unit (tablets, grams). | Slower for large quantities. | Review after owner feedback. |
| S7 | The demo seeder assigns drug schedules, GST rates, HSN codes and prices for illustration. | Must not be copied into a real shop. | Stated in the seeder; real data is entered by the shop. |
| S8 | Product images, bulk price update, barcode label printing and a physical stock count screen are not built. | — | V1 items after the MVP. |
| S9 | The "same composition" list is not built. | — | Requirement D7 (V1). |
| S10 | Export downloads through a plain link; with an expired session the browser shows a short JSON message instead of the sign-in screen. | Cosmetic. | Handle with the reports module. |
| S11 | Expiry-date comparisons in stock queries were tested on SQLite only. | A MySQL-specific date difference would not be seen. | Covered by the pending MySQL run (P1). |

## Phase 3 limitations

| # | Issue | Impact | Action |
|---|---|---|---|
| B1 | No returns, refunds or bill cancellation. | A wrong bill cannot be corrected in the system. | Next task. Do not use for real trade before this exists. |
| B2 | No hold / resume. | One bill at a time per browser. | Phase 3. |
| B3 | No discount control on the sale screen (the server supports a line discount percent). No price override. | Bills are always at the batch selling price. | With permission limits, Phase 3. |
| B4 | One bill layout, sized for 80 mm. Not tried on a real printer; 58 mm and a full A4 invoice are not built. | Layout may need adjusting for the shop's printer. | Needs the printer model (question Q5). |
| B5 | The sale screen asks the server to price the cart after every change. | On a slow connection totals lag behind typing. | Acceptable for a local install; measure on hosted. |
| B6 | Every cut-strip sale is allowed without pharmacist approval. | See D-029. | Confirm with the Drug Inspector. |
| B7 | No H1 register or prescription-sales report yet; the data is saved with each bill. | Registers cannot be printed. | Phase 6. |
| B8 | Buyer GSTIN / address cannot be entered on a bill; IGST is never charged. | Bills to registered or out-of-state buyers are not supported. | V1. |
| B9 | Bills below Rs 200 are always issued; there is no daily consolidated bill. | None for compliance; every sale gets a number. | By design for now. |
| B10 | No cash session: payments are not tied to an opened day. | Day closing cannot be done. | Phase 7. |
| B11 | The udhar limit and its override reuse the `sales.price_override` permission. | Only the owner can set limits or go over them. | Owner to confirm (D-027). |
| B12 | No opening udhar balances; no reversal entries from the screen. | Existing customer debts cannot be brought in. | Phase 5. |
| B13 | Store managers cannot see sales history (it needs the billing permission). | — | Reports module. |

## Limitations of the 2026-11-11 redesign, reports, roles and labels

| # | Issue | Impact | Action |
|---|---|---|---|
| R1 | Labels have not been printed on real sticker paper or a label printer. Sticker sizes are common Indian sizes, not measured against a product. | Labels may not line up with the stickers; margins depend on the printer. | Test with the shop's sticker paper; add adjustable margins if needed. |
| R2 | One barcode per product. No batch-wise barcode; batch and expiry are not printed on labels. | A scan cannot choose the batch; the sale screen picks first-expiry-first. | With purchases (Phase 4). |
| R3 | Only the product list turns into cards on a phone. Sales history, udhar, expiry, users and report tables scroll sideways. | Usable but not comfortable on a phone. | Convert the most used ones after owner feedback. |
| R4 | The application can be installed from the browser but does nothing without a connection. | "Installed" must not be read as "offline". | Phase 9. |
| R5 | No product photos. Icons and colour tiles are used instead. | Operators cannot recognise products by picture. | Listed under the owner-requested work. |
| R6 | Reports are limited to 5,000 lines each and are calculated on request. | A very long period shows a "choose a shorter period" note. | Measure with real volumes (Phase 8 performance pass). |
| R7 | Profit in reports and on the dashboard is gross profit before expenses, and opening-stock cost is taken as typed (GST-inclusive). | For a GST-registered shop profit on opening stock is understated. | Correct once purchases record tax separately (Phase 4). |
| R8 | The PDF library adds about 94 MB to the installed size. | Larger download for the future Windows build. | Trim unused fonts in Phase 9. |
| R9 | The demo data shows a few products sold below cost and a very large "than yesterday" percentage. | Demo only. | None. |
| R10 | Role names typed by the owner are shown as typed in every language. | A custom role is not translated. | By design. |
| R11 | No reports yet for purchases, expenses, day book, cash register, or the H1 / prescription registers. | — | With their modules (Phases 4, 6, 7). |

## To confirm with the Drug Inspector / Maharashtra FDA (shop owner)

1. Are computer-generated bills and printed/electronic registers accepted in place of the carbon memo book,
   the prescription register and the H1 register? How should the pharmacist's signature appear? Is a
   written declaration to the Licensing Authority needed?
2. Must the Schedule X register remain a physical bound book? (The software assumes yes.)
3. NDPS obligations for any codeine / tramadol / psychotropic stock.
4. Exact commencement dates and existing-stock treatment for the 2026 H1 additions (pregabalin; high-alcohol oral liquids).
5. What the general-store side may sell without the drug licence after the June 2026 Schedule K change (cough syrups).
6. Current Maharashtra FDA circulars (MTP kits, cough syrups, pharmacist attendance, reporting portals).
   No Maharashtra-specific official document could be read during research.
7. Status of the draft rule on CCTV recording of prescription sales.
8. Whether the pharmacist's registration number should be printed on bills.

## To confirm with the shop's CA / tax practitioner

1. Registration status and the applicable threshold. Selling ice cream, pan masala or tobacco removes the
   ₹40 lakh exemption (falls back to ₹20 lakh); turnover includes exempt sales.
2. Composition eligibility if applicable.
3. HSN and GST rate for the shop's actual items — the software ships a rate list, not an item-to-rate master.
   Rates for detergents, cleaners, pens and tobacco products were not verified.
4. Whether to print HSN on B2C bills; GSTR-1 Table 12/13 expectations.
5. Rounding convention and the preferred document title for mixed taxable + exempt bills.
6. Tax treatment of B2C sales returns (credit notes) after the October 2025 change.

## Research gaps (not verified from primary sources)

- Pharmacy Practice Regulations 2015 details; current Pharmacy Act penalties.
- NDPS Act and Maharashtra NDPS Rules requirements for retailers.
- E-invoicing and dynamic-QR thresholds (secondary sources only; both far above this shop's scale).
- DPDP Rules commencement dates and breach-reporting timelines (secondary sources; government pages were unreachable).
- Installer size and memory use of the Windows packaging options (no published figures; to be measured in Phase 9).
- Whether NativePHP auto-update works with unsigned Windows builds.
- Hosting plan features and prices (affiliate sources only).
- The consolidated Drugs Rules text used is a 2016/2022 CDSCO edition with 2026 Gazette amendments layered
  on; amendments between 2022 and 2026 other than those listed in `MEDICAL_DOMAIN_GUIDE.md` were not individually checked.

## Product limitations by design (version 1)

- Hosted deployment cannot complete bills without internet; it preserves the cart and retries safely.
- No two-way sync, no multi-branch, no stock transfers, no shifts.
- No GST return filing, e-invoice or e-way bill.
- No drug-schedule master data, no interaction/dose checking, no automatic substitution.
- No weighing-scale or cash-drawer integration; thermal printing goes through the Windows print dialog
  unless the browser is started in kiosk-printing mode.
- No automated SMS/WhatsApp messages.

## Review of 2026-11-11 — left open on purpose

| # | Issue | Impact | Action |
|---|---|---|---|
| V1 | A PDF of a 5,000-line report has not been timed. | A very large export may be slow or run out of memory. | Measure in the Phase 8 performance pass. |
| V2 | Changing a product's selling price does not change batches already in stock; the sale screen charges the batch price. | A price change applies to new stock only. | Owner to decide; bulk price update is a V1 item. |
| V3 | An unknown username answers slightly faster than a wrong password. | Usernames could be guessed by timing; login is throttled. | Phase 8 security review. |
| V4 | Two first-run setups at the same instant could both pass on MySQL. | One-time event on a new install. | Guard when MySQL testing starts. |
| V6 | Roles are shared by every shop in one database. | Fine for one shop per install; wrong if two shops ever share a database. | Decide before any multi-shop use. |
| V7 | A repeated bill id with different goods returns the first bill without a warning. | The second cart is ignored silently (nothing is saved twice). | Show a notice on the sale screen. |
| V8 | Billing a switched-off customer answers "not found" with no translated message. | The operator sees a generic error. | Give it an error code. |
| V9 | Five wrong passwords lock that username on that PC for a minute. | Someone at the counter can briefly lock the owner out. | Accepted for now. |
| V10 | Any signed-in user can download the product list (without cost). | A cashier can export the catalogue. | Owner to decide whether to restrict. |
| V11 | Existing sign-in tests reuse the test session, so their "after sign out" checks prove little. | The tester's own sign-out test does prove it. | Rewrite the older checks the same way. |
| V5 | Stock and customer row locks are not exercised by tests (SQLite, which the tests use, does not lock rows). | Lock behaviour is unproven until the MySQL run. | Covered by the pending MySQL run (P1). |

## Frontend review of 2026-11-12 — left open on purpose

| # | Issue | Impact | Action |
|---|---|---|---|
| F1 | Add stock, add customer and add product keep one request key while the form is open. | After a lost answer, an edited retry is answered with the first attempt's result. Udhar payments and bills are protected (D-047); these three are not yet. | Freeze or re-key them the same way. |
| F2 | The label count box snaps to 1 when emptied. | Typing a new count needs select-all first. | Use the sale screen's quantity box. |
| F3 | Report date presets are worked out when the screen opens. | "Today" is yesterday if a report stays open past midnight. | Recompute on use. |
| F4 | On the Roles screen, switching role discards unsaved ticks without asking. | Lost edits. | Ask before switching. |
| F5 | Saving a medicine rewrites its composition text from the generic name and strength. | A hand-edited composition would be replaced. | Review with the "same composition" feature. |

## Browser test of 2026-11-22 (phone and laptop, Wi-Fi address) — left open on purpose

Fixed from this round: debug mode was on for the demo server; the Barcode Labels page grew to twice the phone width
once a product was chosen; a refusal inside a scrolled dialog (past expiry month) hid under the dialog header; a wrong
pharmacist PIN said "a pharmacist must approve" instead of "wrong PIN" (now `pin_wrong` with tries left); bill quantity
and rate ran together and read "1 strips"; the return dialog hid the batch when one product appeared twice and showed a
typed quantity above the maximum; the credit note said "Billed by"; report exports throttled at 20 a minute (now 60);
the demo script's "Enter twice" step.

| # | Issue | Impact | Action |
|---|---|---|---|
| T1 | Phone tables still scroll sideways with their buttons off-screen: product batches (Adjust), Expiry Alerts (Set aside / Write off), Users (Edit), Sales History columns, customer statement, every report. Nothing is unreachable, but nothing hints at it. (Same as U1.) | Operators on a phone may not find Adjust / Set aside. | Card layouts for these lists; a "swipe for more" shadow meanwhile. |
| T2 | English on Marathi screens: bill footer text (a shop setting), "TAX INVOICE" document title, drug-schedule and tax-rate names in selects, stock-history notes, rack codes. | Mixed-language bill and product form. | Local names for schedules and tax rates (data), a Marathi bill title (confirm the legal wording with the CA). |
| T3 | Some phone controls are under the 38 px rule: header buttons 34 px, drawer rows 34 px, filter chips 30 px, labels count box 23 px tall. | Harder to tap. | Raise them in the next UI pass. |
| T4 | `<html lang>` returns to "en" after a reload while the screens stay in Marathi. | Screen readers and font choice only. | Set it from the saved language at start-up. |
| T5 | The sales-history date box follows the browser locale (mm/dd/yyyy on an English-US browser). (Same as U3.) | Cosmetic on Indian phones (dd/mm). | — |

## Phone access and the desktop build (2026-11-22)

| # | Issue | Impact | Action |
|---|---|---|---|
| M1 | On a plain-http address (a phone opening the shop PC as http://192.168.x.x) browsers hide the "secure context" APIs: `crypto.randomUUID` (fixed — request keys now use `getRandomValues`), the live camera (photo mode is used), clipboard. Any new code must avoid secure-only APIs or test on such an address. | Was the cause of "product add did not work" on the owner's phone. | The browser tester now runs against http://192.168.0.108:8000. |
| M2 | The phone must be on the same Wi-Fi as the PC and the PC's firewall must allow port 8000 (rule in HOW_TO_RUN). The PC's address changes when the router hands out a new one, so the address typed on the phone (and its home-screen icon) goes stale; signing in from any private address works without a setting since D-058. | Operator must find the PC's new address (command in HOW_TO_RUN). | A fixed address for the PC in the router (DHCP reservation) or hosting (https). |
| D1 | The Windows launcher was proven only from the developer tool, which suspends windowed programs it starts: it worked after the process was resumed by hand (database made, server up, app window opened). It has not been started by a double-click on a clean Windows PC yet. Run again the same way on 2026-11-26 with the rebuilt package: it upgraded the data of the 22 Nov install (one new migration), the server came up and the backup folder setting was written. | The .exe may behave differently on a shop PC (SmartScreen warning for an unsigned program is expected). | Owner: run `build\windows\out\AUSHVAN-0.9.0-windows.zip` → `AUSHVAN.exe` on another PC and report. |
| D2 | Inno Setup could not be installed from the developer tool (download refused), so there is a zip, not a Setup.exe. | Install = unzip and run; no Start-menu entry or uninstaller. | Install Inno Setup 6 once (`winget install JRSoftware.InnoSetup`) and re-run `make-desktop.ps1`; the script then builds the installer. |
| D3 | ~~The desktop build has no backup yet.~~ Closed 2026-11-26: Settings → Backup takes a verified backup to a USB stick / second disk, automatically once a day when the stick is in (D-059). Still open: restore is a command, not a screen (D7); the archive is not encrypted (D8); the owner must test it on the desktop build. | — | — |
| D7 | Restoring a backup is a command line step (`php artisan backup:restore <file>`; on the desktop build `php\php.exe app\artisan backup:restore <file>` from the install folder, with AUSHVAN stopped). There is no Restore screen yet. | A shopkeeper needs help on the phone to restore. | A guarded Restore screen (owner password, typed confirmation) in Phase 8/9, or a "Restore" entry in the launcher's tray menu. |
| D8 | Backup archives are plain zips: anyone holding the USB stick can read bills, customer phone numbers and prescription records. | Loss of the stick is a data leak. | Password-protected archives once a safe password-recovery story exists (the owner forgetting it must not lose every backup); keep the stick locked away meanwhile (HOW_TO_RUN §10). |
| D10 | On the desktop build PHP's built-in server handles one request at a time, so the automatic backup (about a second for a small shop, longer for a big one) pauses the sale screen while it runs. | A short freeze once a day on the first visit to the home screen. | Run backups in a second PHP process from the launcher, or give the launcher a worker count (`PHP_CLI_SERVER_WORKERS`). |
| D11 | A restore brings back `audit_logs` as they were at backup time, so audit rows written after the backup (including the safety backup's own entry) are gone; the restore itself is logged afterwards. Uploaded files not in the archive are kept, not deleted. | Audit history has a gap after a restore. | Keep the current audit rows and append the backup's, or write the restore record to the launcher log as well. |
| D9 | On MySQL/MariaDB the backup is a SQL dump: verification recounts the rows in the dump but cannot open it as a database the way SQLite's `integrity_check` does; a restore replays it statement by statement (slow for very large shops). | Lighter verification for hosted installs. | Restore into a scratch database on hosts that allow CREATE DATABASE. |
| D4 | The desktop build serves only the PC itself (127.0.0.1). Phones on the shop Wi-Fi cannot open it. | Phone billing needs the server shape (`php artisan serve --host=0.0.0.0`) or hosting. | Option to bind to the LAN in the launcher, with the firewall rule, if wanted. |
| D5 | ~~The package is about 200 MB (PHP runtime 30 MB, vendor 150 MB with mPDF fonts).~~ Closed 2026-11-26: the package ships only the three font families the PDF reports use (D-060): 131 MB unpacked, 50 MB zip. What is left is the PHP runtime (about 55 MB, 30 MB of it the ICU data file) and vendor code. Consequence everywhere (not only the desktop build): a PDF report prints text in a script other than Latin or Devanagari (Kannada, Arabic, Chinese, emoji) as boxes; it no longer crashes (`ReportPdfFontsTest`). CSV and Excel exports carry every script. | A Kannada or Urdu product name shows as boxes in a PDF report. | Add that script's font to `ReportExporter::PDF_FONTS` and to the build script's keep pattern when a shop needs it. |
| D6 | The packaged application has no code signing certificate. | Windows SmartScreen shows "unknown publisher" the first time. | Accept for the pilot; a certificate later (see OFFLINE_WINDOWS_STRATEGY). |

## Purchases and suppliers (2026-11-26)

| # | Issue | Impact | Action |
|---|---|---|---|
| Q1 | The purchase screen offers the first 25 suppliers in a dropdown; there is no supplier search on that screen. | A shop with more suppliers cannot pick the 26th from the purchase screen (it can start the purchase from the supplier's own page). | A searchable supplier picker when a shop reports more than 25. |
| Q2 | The paper invoice total typed on the purchase screen is compared and shown (green/orange), not enforced: a purchase can be confirmed while it differs. | A typing mistake in a line is caught only if the operator reads the warning. | Owner decision: block confirm on a mismatch, or keep the warning (D-061). |
| Q3 | No barcode scanning or camera on the purchase screen; lines are added by name search. Invoice photo attachment (F8) and the low-stock purchase suggestion list (F9) are not built. | Slower entry of long invoices. | F8/F9 after the pilot; a scan box is a small addition. |
| Q4 | A purchase return from a composition or unregistered shop is valued at the batch cost (which already holds the GST paid) with no separate tax line; a regular shop's debit note shows GST at the purchase-time rate. Whether the supplier's credit note must match this exactly is a CA question. | The debit note amount may differ from the supplier's credit note by the GST treatment. | Confirm with the CA (SECURITY/KNOWN_ISSUES CA list). |
| Q5 | Batches bought before Phase 4 (opening stock) carry no supplier, so they can be returned against any supplier and the "purchases by item" report does not include them. | Historic stock is outside the supplier reports. | Expected; nothing to do. |
| Q6 | The MRP printed on a newly bought pack becomes the product's MRP (D-061). Older batches keep their own MRP and sell at it. A wrong MRP typed on a purchase changes what the sale screen shows for new batches until corrected on the product. | A typo spreads to the product; cancelling the purchase puts it back. | Keep; the product screen shows the price history. |

## Returns and cancellation (2026-11-19)

| # | Issue | Impact | Action |
|---|---|---|---|
| X1 | Sales reports (sales by day, by item, GST summary, dashboard) count bills only; returns are a separate report and are not deducted from them. | A day with Rs 1,000 of bills and Rs 100 returned shows Rs 1,000 of sales. The GST summary does not net credit notes. | Add a returns line / net figures to those reports with the day-closing work (Phase 7); confirm the GST treatment with the CA. |
| X2 | Cancelling a part-paid udhar bill leaves the customer's "last payment" date set by that bill. | Cosmetic on the udhar list. | Recompute from the remaining ledger when cancelling. |
| X3 | Return without the original bill (L5), and exchange on one screen (B20), are not built. An exchange is a return followed by a new sale. | Two documents, two steps. | V1. |
| X4 | On a credit note for part of a line, the printed line's tax split may be a paisa off its own figures; the totals and the sum over all parts are exact. | Cosmetic. | None planned. |
| X5 | A crash in the few milliseconds between the return/cancel transaction committing and the idempotency record being marked done would let a retry after 60 s make a second document. Bills are protected by the cart id; returns are not. | Very unlikely double credit note. | Derive the return's id from the request key, or write the key inside the transaction. |
| X7 | When part refunds of one line were rounded up to the rupee, the cap "never more than the bill" can take that rupee off a later line's refund (3 × Rs 33.50 refunded as 34 + 34 + 34, then a Rs 50 item on the same bill gives back Rs 49). The total over the bill is always exact. | A customer may see one rupee less on the last credit note. | Round part refunds down instead of to nearest, or carry the bill's round-off only on the final note. |
| X8 | The repeat-safety key compares method and path, not the request body: the same key with a changed quantity replays the first answer. The screen uses one key per dialog and shows the server's answer, so nothing is double-counted. | A hand-made retry with a different body would be told the first body was accepted. | Compare a hash of the body too. |
| X9 | "Sales by day" cash/UPI columns count money in only; money paid back on a cancellation or return is not deducted from them (it is in the returns report). | The day's cash column is higher than the drawer by the refunds. | Net the out side when day closing is built (Phase 7). |
| X10 | The server lets a return be credited to an inactive customer's account and lets it take an udhar balance below zero (an advance). The screen offers "take it off the udhar" only while the customer owes something, so this needs a hand-made request. | None in normal use. | Decide with the owner whether advances are wanted (exchange flow); then either allow them on screen or refuse them on the server. |
| X6 | SKUs follow the database id, so on MySQL a shop's SKUs need not start at 00001 and have gaps after failed saves. | Cosmetic; tests now accept any number. | A per-store counter if the owner wants tidy SKUs. |

## Camera scanning (2026-11-13)

| # | Issue | Impact | Action |
|---|---|---|---|
| C1 | Live camera scanning needs a secure address (https or localhost). This is a browser rule. | A phone opening the shop PC as `http://192.168.x.x` gets only "take a photo of the barcode", one photo per pack. | Serve over https (hosting or a certificate on the shop PC) before relying on phones. |
| C2 | Tried only in desktop Chrome with a simulated camera showing a clean barcode, and with a clean picture as the photo. Not tried on a real phone, a real pack, in poor light, or on an iPhone (Safari). | Reading speed and reliability on real packs are unknown. | Try on the shop's own phones with real stock before promising it. |
| C3 | QR codes and the square 2-D codes printed on some medicine packs are not read; only the striped barcodes. | Those packs are found by name. | Add once pack-level QR data (GTIN, batch, expiry) is used. |
| C4 | An unknown barcode only says so; it does not offer "add this product". | Adding the product means going to Products. | A shortcut for users who may add products. |
| C6 | A 12-digit UPC-A barcode (imported goods) may be read by the camera with a leading 0, unlike a plug-in scanner. Not checked with a real pack. | Such a product saved by one device could be "unknown" to the other. | Test with a real UPC-A pack; normalise if confirmed. |
| C7 | To add a second pack of the same item by camera, it must be out of view for 3 seconds first. | Slower than a plug-in scanner for multiples. | Use the + button or type the quantity; the wait is what stops double adding. |
| C5 | On phones other than Android Chrome the reader (about 0.4 MB compressed) is downloaded the first time Scan is pressed. | A few seconds' wait on a slow connection, once. | None needed. |

## Browser test of 2026-11-12 (about 200 screenshots) — left open on purpose

| # | Issue | Impact | Action |
|---|---|---|---|
| U1 | On a phone these tables still scroll sideways: expiry alerts, customer statement, product batches and history, users, reports. | Some columns and buttons are off-screen until scrolled. | Card layouts like the product list; most-used first. |
| U2 | Reports show quantities in the smallest unit (tablets); the stock screens show packs (strips). | "20 tablets" in a report is "2 strips" on the product page. | Show packs in reports too, or both. |
| U3 | Date boxes follow the browser's own language setting; on a browser set to English (US) they read month/day/year. | 11/12/2026 could be read as 11 December. | Check on the shop PC; replace with a day-month-year field if needed. |
| U4 | "Sales by product" and "GST summary" add up to the bills total less the rupee round-off (Rs 0.30 on the demo data). | Two reports differ by the round-off. | Add a round-off line to those reports. |
| U5 | Category names and notes typed in English stay in English on Marathi and Hindi screens. | Mixed-language rows. | Categories have a local-name field; show it. |
| U6 | An unknown address shows the dashboard without saying so; a missing bill or product shows one red line. | Mildly confusing. | A proper "not found" screen with the mascot. |
| U7 | "No limit" shows as Rs 0.00 in the udhar report. | Reads as a zero limit. | Show a dash. |
| U8 | Printed bills, labels and the Excel / PDF / CSV files were not part of this test; no sale or payment was completed through the screen by the tester. | Untested paths remain. | Next test round; real printer still needed. |

Fixed from the same test, each confirmed on screen or by a test: Enter on the sale screen added the wrong product
(a salt match ranked above the name; out-of-stock first); the "Not for sale" list valued the whole batch instead of
the set-aside quantity; the Pay dialog was taller than a laptop screen and put the cursor on the close button; on a
phone the total and Pay button were below the fold; the Roles Save button floated over the list; bill columns ran
together on a phone; Marathi screens mixed two kinds of digits; "1 strips" and "per strips"; two "Pharmacist"
badges on one user; oversized leftover buttons.

## Bugs

None open. Found and fixed on 2026-11-11 while testing with demo data: the expiring-batches list crashed when `days` was given (a string reached a date function); a signed-out request for a non-JSON address under `/api` returned a server error instead of 401.

Found and fixed later on 2026-11-11:
- **Sign out did nothing on screen** (owner report). The cache was cleared before the "signed out" state was set, so the screen stayed. Fixed, with a screen test and a check in a real browser.
- From the code review and the tester (each now has a test): a user manager could make themselves owner; profit and cost values were sent to users not allowed to see them (hidden only in the headings); any cashier could send a discount up to 100%; no limit on pharmacist PIN tries; a crashed request blocked its retry for ever; 0% and 40% GST printed as "%" and "4%" in exports on SQLite; exported cells could be read as formulas by Excel; a medicines-only user could convert and edit a general product; a bill id belonging to another shop was looked up without the shop; the udhar limit and the count correction read without a lock; a prescription dated today was refused between midnight and 05:30; the stock-record check counted every shop.
