# Project Vision

## One-sentence objective

A reliable, affordable, attractive and easy-to-operate management system that the owner of a combined
medical and general store in a Maharashtra village can use every day without confusion.

## Who it is for

- **The shop**: one small rural shop selling medicines, kirana, household, personal-care and hygiene goods.
- **The operator**: the owner or a family member / helper with limited computer experience, using an
  ordinary Windows desktop with keyboard and mouse, possibly a USB barcode scanner and a thermal printer.
- **The pharmacist**: the registered pharmacist responsible for medicine sales.
- **The project owner**: a developer who reviews progress and is not a pharmacy-domain expert.

## What success looks like

1. A normal cash sale takes a few seconds on a single screen, with or without a barcode scanner.
2. Stock on the screen matches stock on the shelf, batch by batch for medicines.
3. Expired medicine cannot be sold, and medicine that is about to expire is visible in time to return it.
4. Every customer's udhar balance is correct and can be explained line by line.
5. At the end of the day the owner knows: sales, cash that should be in the drawer, money owed to the shop,
   money the shop owes suppliers.
6. Nothing is lost when the power or internet fails, and a broken computer can be recovered from a backup.
7. Running cost stays close to the price of basic shared hosting (or zero for a local install).

## What the product is

- A fast point-of-sale for mixed medicine + general bills.
- Batch- and expiry-aware stock control with a full movement ledger.
- Purchases, suppliers and supplier dues.
- Customers and udhar ledger.
- Returns, expenses, day closing.
- Plain-language reports with print and export.
- The records a retail chemist is required to keep, as an administrative aid.

## What the product is not (boundaries)

- Not an accounting package (no double-entry books, balance sheet, TDS, payroll).
- Not a GST filing or e-invoicing tool. It produces correct bills and tax summaries for the shop's accountant.
- Not a medical tool. It never recommends, substitutes or decides anything about treatment.
- Not a substitute for the pharmacist, a doctor, a chartered accountant or a legal adviser.
- Not a multi-branch / multi-warehouse / franchise system in version 1 (the data model must not block it later).
- Not an online ordering or e-pharmacy system.
- Not dependent on paid third-party services.

## Guiding principles

1. **Operational usefulness first.** A feature earns its place by saving time or preventing a mistake at the counter.
2. **Simple on the surface, correct underneath.** Few fields on screen; strict ledgers, transactions and audit trail behind them.
3. **Never lose or silently change business data.** Corrections are new entries, not edits.
4. **Cheap to run.** One Laravel application, one database, ordinary hosting or the shop's own PC.
5. **Honest software.** It does not claim offline ability, compliance or backup success it has not verified.
6. **Configurable law.** Tax rates, drug schedules and retention periods are data, because they change.
7. **Barcode optional, internet failure expected, operator not technical.**

## Delivery order

Version 1 is a dependable application built so that the same code can later be installed as a Windows desktop
application working without internet. See `OFFLINE_WINDOWS_STRATEGY.md` for the recommendation and
`IMPLEMENTATION_PLAN.md` for phases.
