# Business Workflows

Real shop workflows and the exact effect each has on stock, money and ledgers. Each scenario here has an
automated test with the same number (see `IMPLEMENTATION_PLAN.md`). Terms: **batch/lot** = one received
quantity of a product with its own cost, MRP and expiry; **ledger** = the dated list of entries that makes
up a customer's or supplier's balance.

## Golden rules behind every workflow

1. One operation = one database transaction. Either everything is saved or nothing is.
2. Stock changes only through `inventory_movements`. Money changes only through `payments`. Party balances
   change only through ledger entries.
3. Completed documents are never edited. They are cancelled, returned or reversed by a new document that
   points to the original.
4. Every "create" request from the screen carries a unique key generated on the screen (idempotency key).
   If the same request arrives twice (double click, retry after network or power loss), the second one
   returns the first result instead of creating a duplicate.
5. The bill number is issued only at the moment the bill is completed, inside the same transaction, so
   numbers are consecutive and never duplicated.

## Document life cycles

```
Sale:      (cart on screen) -> HELD -> COMPLETED -> CANCELLED (same day, authorised)
                                         \-> RETURN document(s) (credit note)
Purchase:  DRAFT -> CONFIRMED -> CANCELLED (only if none of its stock has moved)
                       \-> PURCHASE RETURN document(s) (debit note)
Cash day:  OPEN -> CLOSED
```

Stock moves only on COMPLETED sale, CONFIRMED purchase, and on returns/cancellations/adjustments.

---

## S1. Normal cash sale
1. Operator opens **New Sale** (cursor is already in the search box).
2. Types a few letters, picks the product (Enter or click). Line is added with quantity 1.
3. Changes quantity if needed. Presses **Pay** (F9), amount defaults to full cash. Enter.
4. System, in one transaction: checks stock, picks lots (earliest expiry first), writes sale + lines +
   lot allocations, stock movements (−), payment (cash in), issues bill number.
5. Receipt prints (or the print step is skipped per setting). Screen resets for the next customer.

Result: stock down; cash in today's register up; no ledger entry (walk-in).

## S2. Barcode sale
Scanner types the code and Enter into the search box. Exact barcode match adds the line immediately; scanning
the same item again increases quantity. Unknown barcode shows "Not found — search by name / add product"
without losing the cart. The barcode may belong to a pack unit (e.g. box), in which case that unit is added.

## S3. Non-barcode sale
Name search (English or local name, brand, generic name, short code) or a tap on the **Quick items** grid.
For loose items the operator enters weight or rupee amount. No step requires a barcode.

## S4. Medical sale
1. Search by brand or generic/salt name. Results show strength, form, stock, nearest expiry, rack.
2. Line is added in the default sale unit (e.g. strip; loose tablets allowed if the product permits).
3. The system proposes the lot with the earliest expiry that is not expired (FEFO). The operator may choose
   another lot of the same product (for example the strip actually in hand). Expired lots are shown greyed
   and cannot be selected. Near-expiry lots show an orange badge.
4. If any line's drug schedule is configured as restricted, the Pay step first opens the **Prescription
   panel** asking only for the fields that schedule requires (prescriber, patient, prescription date,
   optional photo). If the schedule requires pharmacist verification, a pharmacist user must confirm
   (their own login, or PIN when someone else is logged in).
5. Completion writes the same records as S1 plus the prescription record, pharmacist verification and the
   register entries for schedules that need a register.

The system never proposes a different medicine. A "same composition" list can be opened by the operator for
information only (see `MEDICAL_DOMAIN_GUIDE.md`).

## S5. Mixed bill
Medicine + grocery + personal care on one bill. Each line carries its own HSN and tax rate; the bill footer
shows the tax summary grouped by rate. The document title (Tax Invoice / Bill of Supply / Invoice-cum-Bill of
Supply / Cash Memo) is chosen automatically from the shop's GST mode and the lines on the bill.

## S6. Purchase
*Built 2026-11-26 (D-061). Step 5 (invoice photo) is not built.*
1. **Purchases → New**. Choose supplier, type supplier invoice number and date. The system warns if that
   supplier invoice number already exists.
2. Add lines: product, quantity + free quantity, purchase unit, rate, discount, GST, and for batch-tracked
   products batch number, expiry (month/year), MRP. Unknown product → quick-add without leaving the screen.
3. Save as **Draft** at any time (no stock effect). Totals are compared with the paper invoice total.
4. **Confirm**: one transaction creates stock lots, stock movements (+), purchase price history, supplier
   ledger entry (amount owed), and any payment made now.
5. Optional: attach a photo/scan of the invoice.

Cost per unit of a lot = line taxable value ÷ (paid qty + free qty), plus GST when the shop cannot claim
input credit (unregistered/composition). Free quantity therefore lowers unit cost; it is never "zero cost stock".

## S7. Customer credit (udhar)
1. Bill ₹500, customer chosen (required for credit). Operator enters ₹200 cash; remaining ₹300 is shown
   as **Udhar**. If the new balance would exceed the customer's credit limit, the system warns and requires
   a user with permission to continue.
2. Completion: payment ₹200 (cash in), ledger entry "Sale ₹500" and "Payment ₹200"; balance ₹300.
3. Later: **Udhar → Receive payment** ₹100 → payment (in) + ledger entry; balance ₹200.
4. Statement lists every entry with running balance; the last line always equals the balance shown on the
   customer card. An advance payment makes the balance negative (shop owes customer) and is used up by later bills.

Mistakes are fixed by a reversal entry (permission required, reason required), never by editing.

## S8. Supplier payment
*Built 2026-11-26: Suppliers → Pay (on account or against one purchase); the dashboard card shows supplier dues and what is past its date.*
Confirmed purchase of ₹10,000 with ₹4,000 paid → supplier balance ₹6,000 with due date. **Suppliers → Pay**
₹3,000 by UPI → payment (out) + supplier ledger entry; balance ₹3,000. Dashboard "Pending supplier
payments" reflects it. Cash payments reduce the day's expected cash.

## S9. Expired medicine
1. A daily check (and every stock query) classifies lots by expiry date. Expired lots are excluded from
   sale by the backend regardless of the screen.
2. **Expiry Alerts** lists expired / 30 / 60 / 90 days / custom, with quantity, cost value, supplier and a
   suggested action (return to supplier / write off).
3. **Move to expired stock**: movement from sellable to non-sellable bucket (stock value is still held).
4. Then either **Return to supplier** (S11) or **Write off** (movement out, type expiry, loss recorded).

## S10. Customer return
1. **Sales History** → find bill (number, date, customer, mobile, product) → **Return**.
2. Select lines and quantities (cannot exceed sold minus already returned). Each line returns to the lot
   it was sold from.
3. For each line choose **Back to stock** or **Not sellable** (damaged/expired/opened). Medicines default to
   *Not sellable* and require a user with permission to mark as restockable; expired lots can never go back
   to sellable stock.
4. Choose refund: pay back now (cash/UPI/card/bank), or take it off the udhar balance when the bill has a
   customer account. (Built 2026-11-19, D-051. "Keep as advance" is not a separate choice: an exchange is this
   return, then a new sale.)
5. One transaction: return document (credit note number), stock movements (+ to chosen bucket), payment
   (out) or ledger credit. The original bill is untouched and shows "Returned: qty".

**Exchange** = return (as above, refund kept as credit on the screen) followed immediately by a new sale that
uses that credit. Two linked documents, one flow on screen.

**Wrong bill** = cancel (same day, S-cancel below) or return + new sale.

**Cancel bill**: allowed for a completed bill on the same business day before day closing, permission and
reason required. Reverses stock, payments and ledger entries with linked reversal rows; the bill number is
kept and marked CANCELLED.

## S11. Purchase return
*Built 2026-11-26: Purchases → Return to supplier; bought stock goes back only to the supplier it came from, at the purchase-time tax rate.*
Choose supplier and lot (from sellable or non-sellable bucket), quantity and reason. One transaction: return
document (debit note number), stock movement (−), supplier ledger reduced (or refund received as payment in).
Quantity cannot exceed what is in the chosen lot.

## S12. Day closing
1. Day starts with **Open day**: opening cash (defaults to yesterday's counted cash).
2. During the day every cash payment in/out is tied to the open day.
3. **Day Closing** shows: opening cash + cash sales + cash received against udhar − cash refunds − cash
   expenses − cash paid to suppliers − cash taken out (bank deposit / owner) = **expected cash**.
4. Operator counts the drawer and types the amount. Difference is shown in green (0), orange (small) or red.
5. Close: figures are frozen. Later entries belong to the next day. A closed day can be reopened only by
   the owner, with a reason, and this is logged.

UPI/card/bank totals are listed separately for the owner to compare with the bank/UPI app.

## S13. Internet failure
Behaviour depends on the installed architecture (see `OFFLINE_WINDOWS_STRATEGY.md`):

- **Hosted (online) install:** the cart is kept on the PC (browser storage) and a red "No connection"
  banner appears. Billing cannot be completed until the connection returns; when it does, pressing Pay
  sends the same request key, so a bill that was actually saved before the drop is not created twice.
  This is *not* offline billing and is never described as such.
- **Local (shop PC) install:** the application and database are on the shop PC, so internet loss has no
  effect on billing. Only the off-site backup upload waits and shows "Backup pending".

## S14. Power failure
- Database writes are transactional: a bill is either fully saved or not saved at all.
- The cart in progress is saved on the PC after every change and is restored when the screen is reopened.
- If power failed after Pay was pressed, the restored cart re-sends the same request key: the system
  answers "already saved as bill no. X" and offers reprint instead of creating a second bill.
- A startup self-check compares lot quantities with the movement ledger and reports any mismatch.

## S15. Duplicate product
On product creation (and import) the system checks: same barcode (blocked), same name + pack size + brand
(warning with the existing product shown), very similar name (warning). The operator can open the existing
product instead. Supplier invoice numbers and customer mobile numbers get the same warn-on-duplicate treatment.

---

## Other everyday workflows

**Opening setup (first day):** shop details → import or add products → enter opening stock (lot, expiry,
quantity, cost) → opening balances of customers and suppliers. Each creates proper "opening" entries.

**Stock adjustment:** choose lot, new counted quantity or +/− difference, reason (damage, count
correction, sample, theft, other). Permission required; shows in the movement ledger with user and note.

**Physical stock verification:** start a count for a category or the whole shop, type counted quantities,
review differences, post. Posting creates adjustments. Sales may continue during a count; differences are
calculated against stock at the time each line is counted.

**Hold / resume bill:** Hold saves the cart on the server with a label (customer name or note); stock is
not reserved. Resume re-checks price and stock.

**Price change:** edit price on the product or on a lot → price history entry. Past bills are unaffected.
