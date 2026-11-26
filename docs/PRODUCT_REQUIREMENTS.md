# Product Requirements

Functional requirements by module. Status: **accepted 2026-11-11** (owner said to continue; no changes requested).

Priority: **MVP** = needed before the shop can run on the system · **V1** = part of version 1, after MVP ·
**Later** = designed for, not built in version 1. Phase numbers refer to `IMPLEMENTATION_PLAN.md`.
Detailed step-by-step behaviour is in `BUSINESS_WORKFLOWS.md`; screens in `UI_UX_GUIDELINES.md`.

## A. Dashboard (Phase 1 shell, completed in Phase 7)

| ID | Requirement | Pri |
|---|---|---|
| A1 | Cards: today's sales (amount, bills), cash received, UPI received, udhar to collect, purchases today, expenses today, gross profit estimate, supplier dues | MVP |
| A2 | Alert strip: expired, expiring (configurable days), low stock, out of stock, overdue udhar — each links to its list | MVP |
| A3 | Last-7-days sales chart; recent transactions list; product count with low/out counts | MVP |
| A4 | Profit and supplier cards hidden without permission; profit labelled as an estimate before expenses | MVP |
| A5 | Buttons: New Sale, Day Closing | MVP |

## B. POS / Billing (Phase 3)

| ID | Requirement | Pri |
|---|---|---|
| B1 | One-screen sale: search by name, local name, brand, generic/salt, SKU, barcode | MVP |
| B2 | USB scanner input captured anywhere on the screen; repeat scan increments quantity | MVP |
| B2a | Camera scanning: a Scan button on the sale screen, the product list and the product form opens the phone's (or laptop's) camera and reads the barcode, so a shop can start without buying a scanner. On the sale screen it stays open and adds pack after pack. Where the browser refuses a live camera (plain http address, permission denied) a photo of the barcode is read instead. | Built 2026-11-13 (D-050) |
| B3 | Quick-items grid for frequent non-barcode products | MVP |
| B4 | Quantity stepper; unit switch (strip/tablet, kg/g); weight or rupee-amount entry for loose items | MVP |
| B5 | Lot selection for batch-tracked items, FEFO default, expired lots blocked, near-expiry flagged | MVP |
| B6 | Line and bill discounts within the user's permitted limit; price override by permission; selling above MRP blocked | MVP |
| B7 | Tax per line from product configuration; totals show subtotal, discount, tax summary by rate, round-off, payable | MVP |
| B8 | Customer: walk-in by default; search or quick-add (name + mobile) | MVP |
| B9 | Payments: cash, UPI, card, bank, udhar; split across methods; partial payment with remainder to udhar; change calculation | MVP |
| B10 | Hold and resume bills (stored on server) | MVP |
| B11 | Print on completion (thermal 58/80 mm or A4), reprint any bill | MVP |
| B12 | Cart auto-saved locally and restored after browser/power failure; duplicate-safe completion | MVP |
| B13 | Sales history with search and filters; bill detail view | MVP |
| B14 | Returns and refunds against the original bill (see L) | Built 2026-11-19 |
| B15 | Cancel bill: same business day, permission + reason, full reversal, audit entry | Built 2026-11-19 |
| B16 | Keyboard shortcuts, each also a visible button | MVP |
| B17 | Prescription / pharmacist step when a line's schedule requires it (see P) | MVP |
| B18 | Share bill by WhatsApp link (opens WhatsApp with bill text; no paid API) | V1 |
| B19 | Manual "misc item" line (name + price + tax rate) by permission, general goods only, no stock effect | V1 |
| B20 | Exchange flow (return + new sale linked on one screen) | V1 |

## C. Product management (Phase 2)

| ID | Requirement | Pri |
|---|---|---|
| C1 | Product types medical and general with shared fields: name, local name, SKU (auto), category/subcategory, brand, manufacturer, HSN, tax rate, units, MRP, selling price, default discount, minimum stock, supplier, rack, image, active flag | MVP |
| C2 | Short add form with "More details"; save-and-add-stock shortcut | MVP |
| C3 | Barcode optional; multiple barcodes per product, each tied to a pack unit; duplicates blocked | MVP |
| C3a | Scan to add: an unknown barcode on the sale screen or the product list offers to add the product with that barcode, then its opening stock, then back to the sale (D-056) | Built 2026-11-22 |
| C4 | Generate internal barcode; print barcode labels (A4 label sheet and thermal label size) | V1 |
| C5 | Duplicate detection on create and import (barcode, name + pack + brand, similar name) | MVP |
| C6 | Price history and stock history per product | MVP |
| C7 | Import from CSV/Excel with a downloadable template, dry-run preview and error report; export to CSV/Excel | MVP (CSV) / V1 (XLSX) |
| C8 | Deactivate instead of delete once a product has transactions | MVP |
| C9 | Bulk price update screen | V1 |

## D. Medical products (Phase 2; compliance parts Phase 6)

| ID | Requirement | Pri |
|---|---|---|
| D1 | Medicine fields: generic/salt, composition, strength, dosage form, manufacturer, pack/strip sizes, schedule, NDPS flag, storage instructions, rack | MVP |
| D2 | Multiple lots per medicine with own batch number, expiry, MRP, cost, supplier; batch number and expiry mandatory | MVP |
| D3 | Lot-level stock, lot-wise purchase and sales history, batch-wise stock report | MVP |
| D4 | Expired stock blocked from sale by the backend; near-expiry warnings | MVP |
| D5 | Separate "Not for sale" bucket for expired/damaged/unsuitable returned stock | MVP |
| D6 | Schedule assigned by the shop per medicine; "schedule not set" report | MVP |
| D7 | "Same composition" information list from pharmacist-verified compositions; never automatic; warning for H/H1/X | V1 |
| D8 | Loose sale (cut strip) allowed per product; flagged for pharmacist supervision | MVP |

## E. General products (Phase 2)

| ID | Requirement | Pri |
|---|---|---|
| E1 | Per-product base unit and conversion units with integer factors; purchase unit ≠ sale unit | MVP |
| E2 | Weight/volume products in grams/millilitres; sale by weight or by amount | MVP |
| E3 | Each size is a separate product; shared brand/category for grouping | MVP |
| E4 | Automatic hidden lots for cost and MRP changes; optional expiry tracking per product | MVP |
| E5 | Conversion factor locked after first use | MVP |
| E6 | Variant family link between sizes | Later |

## F. Purchases (Phase 4)

| ID | Requirement | Pri |
|---|---|---|
| F1 | Purchase entry: supplier, invoice number/date, lines with quantity, free quantity, unit, rate, discount, GST, batch, expiry, MRP; other charges; round-off; notes; due date | MVP |
| F2 | Draft → Confirm; stock, lots, price history and supplier ledger created only on confirm | MVP |
| F3 | Payment at purchase (full/partial) and later payments; supplier ledger | MVP |
| F4 | Duplicate supplier-invoice warning; total check against paper invoice | MVP |
| F5 | Quick-add product from the purchase screen | MVP |
| F6 | Purchase return (debit note) from sellable or not-for-sale stock | MVP |
| F7 | Cancel a confirmed purchase only if none of its stock has moved; otherwise use return | MVP |
| F8 | Invoice photo/scan attachment | V1 |
| F9 | Low-stock purchase suggestion list | V1 |

## G. Inventory (Phase 2, completed through Phases 4 and 6)

| ID | Requirement | Pri |
|---|---|---|
| G1 | Current stock by product, category, type; medical and general stock views; batch-wise view | MVP |
| G2 | Low-stock and out-of-stock lists based on minimum stock | MVP |
| G3 | Movement ledger per product/lot with source document links | MVP |
| G4 | Opening stock entry | MVP |
| G5 | Stock adjustment with reason and permission; damage and expiry write-off | MVP |
| G6 | Physical stock verification with discrepancy report | V1 |
| G7 | Stock valuation at cost (and at MRP for information) | MVP |
| G8 | Negative stock blocked unless the store setting allows it | MVP |
| G9 | Integrity check: lot quantities vs movement ledger | MVP |
| G10 | Stock transfer between locations | Later |

## H. Expiry management (Phase 6)

| ID | Requirement | Pri |
|---|---|---|
| H1 | Tabs: expired, 30, 60, 90 days, custom range; periods configurable | MVP |
| H2 | Per lot: batch, quantity, cost value, MRP, supplier, suggested action, return status | MVP |
| H3 | Actions: move to Not for sale, return to supplier, write off | MVP |
| H4 | Print and export | MVP |

## I. Customers (Phase 5)

| ID | Requirement | Pri |
|---|---|---|
| I1 | Name, mobile, address, village, type, credit limit, notes; only name required | MVP |
| I2 | Purchase history, payment history, outstanding balance | MVP |
| I3 | Walk-in sales need no customer | MVP |
| I4 | Duplicate-mobile warning | MVP |
| I5 | Opening balances; import from CSV | MVP |
| I6 | Export / erase a customer's personal data on request, keeping legally required transaction records | V1 |

## J. Udhar / customer credit (Phase 5)

| ID | Requirement | Pri |
|---|---|---|
| J1 | Credit sale, partial payment at sale, later partial/full repayment, advance payment | MVP |
| J2 | Ledger with running balance that always reconciles to the customer balance | MVP |
| J3 | Corrections only by reversal/adjustment entries with reason and permission | MVP |
| J4 | Credit limit warning with permissioned override | MVP |
| J5 | Udhar list sorted by amount / days since last payment; overdue flag (configurable days) | MVP |
| J6 | Statement print; share as WhatsApp text link | MVP / V1 |
| J7 | Payment receipt print | V1 |
| J8 | Automated SMS/WhatsApp reminders | Later (needs paid service + consent) |

## K. Suppliers (Phase 4)

| ID | Requirement | Pri |
|---|---|---|
| K1 | Name, contact person, mobile, address, GSTIN, drug licence number, type, payment terms, opening balance | MVP |
| K2 | Supplier ledger, purchase history, payment history, outstanding and due-date report | MVP |

## L. Returns and exchanges (Phases 3–4)

| ID | Requirement | Pri |
|---|---|---|
| L1 | Customer return linked to the original bill and lot; quantity limits enforced | MVP |
| L2 | Per line: back to stock or not for sale; medicines default to not for sale; expired never restocked | MVP |
| L3 | Refund by cash/UPI, credit to udhar account, or exchange credit | MVP |
| L4 | Own number series for return documents (credit/debit notes) | MVP |
| L5 | Return without original bill (by permission, general goods only) | V1 |
| L6 | Supplier returns (F6) | MVP |

## M. Expenses (Phase 7)

| ID | Requirement | Pri |
|---|---|---|
| M1 | Entry: category, amount, date, payment method, description | MVP |
| M2 | Seeded categories (rent, electricity, internet, salary, transport, packaging, maintenance, other); editable | MVP |
| M3 | Daily, monthly, category-wise reports | MVP |
| M4 | Attachment | V1 |

## N. Cash register / day closing (Phase 7)

| ID | Requirement | Pri |
|---|---|---|
| N1 | Open day with opening cash; close day with counted cash and difference | MVP |
| N2 | Expected cash from cash sales, udhar received, refunds, expenses, supplier payments, cash in/out | MVP |
| N3 | Non-cash totals shown for comparison with UPI/bank | MVP |
| N4 | Closed day frozen; reopen by owner with reason | MVP |
| N5 | Day-closing summary print | MVP |
| N6 | Shift-wise sessions per operator | Later (table supports it) |

## O. Reports (Phase 7)

All reports: date filter (with ready-made periods), sort, print, and export as Excel (.xlsx), CSV and PDF in the
user's language. Changed 2026-11-11 at the owner's request (was: CSV only, XLSX in V1, PDF by browser print).

| Group | Reports | Pri |
|---|---|---|
| Sales | Day/week/month summary, date-wise, product-wise, category-wise, payment-method-wise, customer-wise, bill register | MVP |
| Purchases | Supplier-wise, product-wise, purchase returns | MVP |
| Inventory | Current stock, low, out, expired, expiring, valuation, movement | MVP |
| Finance | Gross profit estimate, expenses, net operating result estimate, customer outstanding, supplier outstanding, cash register, payment reconciliation | MVP |
| Tax | Sales tax summary by rate and HSN, purchase tax summary, document counts by series | MVP (regular GST mode) |
| Medical | Batch-wise sales, expiry, prescription sales record, H1 register, Schedule X statement | MVP (X: V1) |
| General | Fast-, slow-, non-moving products | V1 |

Gross profit is always labelled separately from the net estimate, and never uses MRP in place of real prices.

## P. Medical compliance support (Phase 6)

| ID | Requirement | Pri |
|---|---|---|
| P1 | Configurable drug schedules with per-schedule required records | MVP |
| P2 | Prescription record: prescriber name/address/reg. no., patient name/address, date, optional photo; serial number | MVP |
| P3 | Pharmacist identity on users; verification recorded on applicable sales (login or PIN) | MVP |
| P4 | Register entries (H1; prescription sales record) created automatically with batch, maker, expiry | MVP |
| P5 | Schedule X disabled unless licence number entered; statement to assist the physical register | V1 |
| P6 | Drug licence numbers on medicine bills; licence retention-fee reminder | MVP |
| P7 | Every bill containing a medicine is numbered and printable (no "skip bill") | MVP |
| P8 | Prescription data visible only to permitted roles | MVP |

## Q. GST and invoicing (Phases 1 and 3)

| ID | Requirement | Pri |
|---|---|---|
| Q1 | Shop GST mode: unregistered, composition, regular; GSTIN, address, state | MVP |
| Q2 | Document type and wording chosen automatically from mode and bill contents | MVP |
| Q3 | Per-product HSN and tax rate from an editable rate list (incl. nil/exempt/non-GST, cess) | MVP |
| Q4 | Tax-inclusive pricing with back-calculated taxable value; CGST/SGST; IGST for out-of-state buyer | MVP (IGST: V1) |
| Q5 | Bill numbering: configurable prefix, per financial year, ≤ 16 characters, consecutive | MVP |
| Q6 | Buyer GSTIN/name/address on a bill when provided | MVP |
| Q7 | Configurable bill header, footer, optional columns, language | MVP |
| Q8 | No claim of return filing or e-invoicing | — |

## R. Users, roles, security, backup (Phases 1 and 8)

| ID | Requirement | Pri |
|---|---|---|
| R1 | Login with username + password; optional short PIN for quick authorisation at the counter | MVP |
| R2 | Roles Owner, Pharmacist, Store Manager, Cashier; editable permissions; one-user shop supported | MVP |
| R3 | Backend authorisation on every action; audit log of sensitive actions | MVP |
| R4 | Manual backup with download, verification, history; scheduled backup where possible — **built 2026-11-26** (D-059): verified zip, USB/second-disk folder, automatic daily copy, history with download, cron command | MVP |
| R5 | Documented and tested restore — **built as a command** (`backup:restore`, tested on SQLite and MariaDB); restore screen pending (KNOWN_ISSUES D7) | MVP |
| R6 | Full business data export (CSV bundle) | V1 |

## S. Language (Phase 1 onward)

| ID | Requirement | Pri |
|---|---|---|
| S1 | Translation architecture from the first screen; English complete | MVP |
| S2 | Marathi interface | MVP |
| S3 | Hindi interface | Built 2026-11-11 (owner request) |
| S4 | Marathi receipt option; product local names on receipt | V1 |

## T. Doctors and medical representatives (owner question, 2026-11-19 — D-054)

| ID | Requirement | Pri |
|---|---|---|
| T1 | Doctor master: name, registration no., clinic/address, phone; picked (or added) on the prescription step instead of retyping; the register still stores the snapshot | Phase 6 |
| T2 | Doctor-wise report: prescriptions and medicine sales by prescriber for a period (information for the owner; no incentives, no payments to doctors are recorded) | Phase 6 |
| T3 | MR / supplier contacts: name, company, phone, usual visit day, schemes offered, notes; shown on the supplier and on purchase entry | Phase 4 |
| T4 | Reminder on the dashboard of the MR expected today (from the visit day) | V1 |

## Recommended MVP in one paragraph

Products (medical + general, units, barcodes, lots), opening stock, purchases with supplier dues, the POS
with all payment types and udhar, returns and cancellation, customers and udhar ledger, expiry control,
prescription/H1 record capture, expenses, day closing, the core reports, English + Marathi, backup and
restore. Deferred within version 1: label printing, XLSX, stock-take screen, exchange shortcut, WhatsApp
share, Hindi, fast/slow-moving analysis, attachments on purchases/expenses. Deferred beyond version 1:
variant families, multi-branch, transfers, shifts, automated reminders, sync.

## Evaluation of "additional useful features" (brief §15)

| Feature | Verdict | Cost / reason |
|---|---|---|
| WhatsApp bill sharing | V1, as a free `wa.me` link with bill text | No API cost; operator presses Send in WhatsApp |
| Customer payment reminders | V1 as "remind" button opening WhatsApp; automation Later | Automated SMS needs DLT registration and a paid gateway |
| Supplier payment reminders | MVP as dashboard due list | Free |
| Barcode label printing | V1 | Browser-generated; needs label stock |
| Low-stock purchase suggestions | V1 | Simple list from minimum stock and recent sales |
| Fast/slow-moving products | V1 | Report only |
| Import templates, opening stock and balances | MVP | Essential for go-live |
| Price updates, bulk stock adjustment | V1 | |
| Receipt customisation, Marathi receipt | MVP basic / V1 Marathi | |
| Multiple branches, multiple counters | Later | `store_id` and counter prefix keep the door open |

## Assumptions (to be corrected by the owner if wrong)

1. One shop, one stock location, one counter PC at launch; possibly a second PC later.
2. The shop holds retail drug licences (Form 20/21) and has a registered pharmacist; it does not sell Schedule X.
3. GST status is unknown → all three modes are supported; default for development is *regular*.
4. Bills are mostly small B2C sales to unregistered buyers within Maharashtra.
5. Printer is either an 80 mm thermal or an A4 printer with a normal Windows driver.
6. Operators read Marathi and basic English.
7. Financial year is April–March; times are IST.
