# Medical Store Domain Guide

Plain-language guide to how a retail chemist works, what the law requires the shop to record, and how the
software supports it. Researched 11 Nov 2026.

> **Important.** This is a software design aid, not legal advice. The software is an administrative tool;
> responsibility for lawful sale stays with the licence holder and the registered pharmacist. Points marked
> **CONFIRM** must be checked with the local Drug Inspector / Maharashtra FDA office before the shop relies
> on them. Drug schedules and rules change — the software keeps them as editable data.

Evidence tags: **[P]** read in the primary text (CDSCO copy of the Drugs Rules, 1945, or the Gazette) during
research; **[S]** secondary sources only; **[U]** not verified. The consolidated rule text used predates
2023; 2026 Gazette amendments were layered on top.

Sources:
- Drugs Rules, 1945 (CDSCO): https://cdsco.gov.in/opencms/resources/UploadCDSCOWeb/2022/drug_rules/Drugs%20Rules,%201945.pdf
- CDSCO Gazette notifications: https://www.cdsco.gov.in/opencms/opencms/en/Notifications/Gazette-Notifications
- eGazette: https://egazette.gov.in
- Maharashtra FDA circulars: https://fda.maharashtra.gov.in/document-category/drugs-section-orders-circulars/

## 1. Vocabulary

| Term | Meaning |
|---|---|
| Brand name | The name on the pack (Crocin). |
| Generic / salt / composition | The active ingredient(s) and amount (Paracetamol 500 mg). Many brands share one composition. |
| Strength | Amount of active ingredient per unit (500 mg, 125 mg/5 ml). |
| Dosage form | Tablet, capsule, syrup, injection, cream, drops, inhaler, powder… |
| Strip / pack | Tablets are sold in strips (10, 15…), strips come in boxes. Syrups in bottles. |
| Batch number | Manufacturer's production lot printed on the pack. Recalls and complaints are traced by batch. |
| Mfg / Exp date | Manufacture and expiry, usually month/year. Valid through the last day of the expiry month. |
| MRP | Printed maximum retail price, inclusive of all taxes. Can differ between batches. |
| PTR / PTS | Price to retailer / price to stockist — what the shop pays the wholesaler (before GST). |
| Scheme / free qty | "10 + 1": buy 10, get 1 free. Lowers the real cost per unit. |
| Schedule | Legal class of a drug (H, H1, X, …) deciding prescription and record rules. |
| OTC | Informal term for medicines sold without prescription; not a legal schedule in India. |
| Rx | Prescription. `Rx` on a label = Schedule H; red `Rx` in a box = H1; `XRx` = X; `NRx` = narcotic. |
| FEFO | First Expiry, First Out — sell the batch that expires soonest. |
| Rack / shelf | Where the medicine is kept in the shop. |
| Cash/credit memo | The bill. |
| Wholesaler / stockist | The licensed supplier the shop buys medicines from. |
| Expiry return | Sending expired / near-expiry stock back to the supplier for credit. |

## 2. Licences

- Retail sale needs a licence per premises: **Form 20** (drugs other than Schedule C, C(1), X) and
  **Form 21** (Schedule C and C(1) — biologicals such as vaccines, insulin, many injections). Schedule X
  drugs need a separate **Form 20F**. Restricted licences **20A/21A** cover only named drugs that do not
  need a qualified person. [P: Rules 61, 62, 62A]
- A licence stays valid while a retention fee is paid every five years. [P: Rule 63]
- The licence must be displayed in the shop. [P: licence conditions]

**Software:** shop settings hold each licence number and its retention-fee due date (with a reminder), and
print licence numbers on medicine bills. Schedule X selling is **off by default** and can only be switched
on by the owner after entering a Form 20F number.

## 3. Registered pharmacist

- Supplying any drug on a prescription must be done by or under the personal supervision of a registered
  pharmacist. [P: Rule 65(2); Pharmacy Act s.42]
- Supplying medicine outside the manufacturer's original container — loose tablets or a cut strip — needs
  the pharmacist's direct supervision and a label with drug name, quantity and the shop's name and address.
  [P: Rule 65(19)]
- Enforcement in Maharashtra reportedly focuses on pharmacist absence. [S]

**Software:** users can be marked as pharmacist with registration number. Sales that include items whose
schedule requires it record which pharmacist verified the sale (own login, or PIN when another user is
billing). Loose-tablet lines are flagged for pharmacist supervision. The software records the verification;
it cannot prove physical presence.

## 4. Drug schedules that matter at the counter

| Schedule | What it means | Sale rule | Records |
|---|---|---|---|
| (none) | Not in H/H1/X; includes household remedies | No prescription needed by these rules | Bill |
| **H** | Prescription drugs | Only on a doctor's prescription [P: 65(9)(a)] | Bill + prescription record incl. batch, maker, expiry |
| **H1** | Selected antibiotics, anti-TB, habit-forming drugs | Prescription | As H **plus a separate H1 register**, kept 3 years [P: 65(3)(1)(h)] |
| **X** | Certain psychotropic drugs | Prescription in duplicate, one copy kept 2 years; separate licence; lock and key [P: 65(9), 65(12)] | Bound register, page per drug [P: 65(21)] |
| **C / C(1)** | Biologicals (vaccines, insulin, sera…) | Form 21 licence | Batch, maker, expiry recorded |
| NDPS (narcotic/psychotropic) | Controlled under the NDPS Act, overlaps with the above | Separate strict rules | **CONFIRM** — not verified |

Recent changes found (illustrating why schedules are data, not code):
- **Pregabalin** added to H1 (entry 51), Gazette 13 May 2026, in force about mid-Nov 2026. [P] **CONFIRM date**
- **Oral liquids with >12% alcohol in packs over 30 ml** added to H1 (entry 52), in force about Jan 2027. [P]
- **Cough syrups removed from the household-remedy exemption** (Schedule K), June 2026 — they are no longer
  something an unlicensed general counter can sell. [P for the amendment; S for the interpretation] **CONFIRM**
- Draft proposals (not in force): more drugs into H1; CCTV recording of prescription sales; blue strip on
  antimicrobials. [S]
- No official machine-readable schedule list exists. The authoritative lists are the Schedules in the Drugs
  Rules plus later Gazette notifications.

**Software:**
- `drug_schedules` is a settings table. Each schedule row carries switches: prescription required,
  prescriber details required, patient details required, pharmacist verification required, register
  required, register retention years, keep prescription copy, enabled/disabled, warning text.
  Seeded with None, H, H1, X (disabled), C/C(1), plus an independent **NDPS flag** on the medicine.
- The shop (pharmacist) assigns the schedule to each medicine from the pack label (`Rx`, red `Rx`, `XRx`,
  `NRx`). The software never guesses a schedule. Medicines with no schedule set show "Schedule not set" and
  a report lists them for the pharmacist to complete.
- At billing, a bill containing a restricted item cannot be completed until the fields that schedule
  requires are filled. The software does not stop a pharmacist from dispensing; it makes sure the record exists.

## 5. Records the shop must keep

### Sale against prescription [P: Rule 65(3)]
Recorded at the time of supply: serial number (also written on the prescription), date, prescriber name and
address, patient name and address, drug name and quantity, and — for Schedule C, H, H1 — manufacturer, batch
number and expiry; signed by the pharmacist. For medicines sold in original packs the same details may be
kept in a serially numbered memo (bill) book instead of a register, if the shop has told the Licensing
Authority in writing.

### Bill (cash/credit memo) [P: Rule 65(4)(3)]
Every retail sale of a drug must be made against a memo showing the shop's name, address and licence number,
memo serial number, and the name and quantity of the drug; copies are kept as records. No small-value
exemption was found. **Software: a medicine sale always produces a numbered bill**, even if the general-store
side is allowed to skip tiny bills.

### H1 register [P]
Separate register: prescriber name and address, patient name, drug name, quantity; retained 3 years.

### Schedule X register [P: Rule 65(21)]
A **bound, page-numbered book** with separate pages per drug (receipts and supplies, supplier and licence
number, patient, prescription reference, bill number, signature). The wording implies a physical book.
**Software: can print a matching statement as an aid, but does not claim to replace the bound register.**

### Purchases [P: Rule 65(4)(4)]
Date, supplier name, address and licence number, drug name, quantity, batch number, manufacturer; purchase
bills numbered serially by the shop and filed in date order. Stock must be bought from a licensed dealer or
manufacturer against a bill.

### Retention
- General rule: at least 2 years from the last entry. [P: Rule 65(7)]
- H1 register: 3 years. Schedule X prescription copy: 2 years. [P]
- GST law separately requires about 6 years (72 months from the annual return due date).
- **Software: business records are never hard-deleted.** Archiving, if ever needed, will respect the longest period.

### CONFIRM with the Drug Inspector
1. Whether computer-generated bills and printed/electronic registers are accepted in place of the carbon
   memo book, prescription register and H1 register, how the pharmacist's signature is to be shown, and the
   written declaration needed.
2. Whether the Schedule X register must remain a physical bound book.
3. NDPS obligations for any codeine / tramadol / psychotropic stock.
4. Current Maharashtra FDA circulars (MTP kits, cough syrups, pharmacist attendance, any reporting portal).
5. What the general-store counter may sell without the drug licence after the 2026 Schedule K changes.
6. Whether the pharmacist's registration number should be printed on bills (no central rule found).

## 6. Substitution

For a prescription of a Schedule H, H1 or X drug the seller may **not supply any other preparation in its
place, whether or not it contains the same substance**. [P: Rule 65(11-A)] No change to this was found
through 2026. [S]

**Software:**
- No automatic substitution anywhere.
- "Same composition" is an information list the operator opens deliberately. It only lists medicines whose
  composition has been marked verified by a pharmacist user and matches exactly (ingredients, strength, form).
- When the medicine being looked up is Schedule H/H1/X the list carries a fixed notice: "Do not substitute
  a prescribed medicine. Shown for stock information only."
- The software never shows dosage, indication or treatment advice.

## 7. Expired and damaged medicine

- No drug may be sold or kept for sale after expiry. Expired stock waiting for return or disposal must be
  stored separately and marked "Not for sale". [P: Rule 65(17)]
- Physician samples and government-supply stock must not be stocked or sold. [P: Rule 65(18)]

**Software:**
- Expiry is stored as a date (last day of the printed month). A lot is unsellable from the day after.
- The backend refuses to bill an expired lot. No setting or role can override this.
- Expired/damaged/unsuitable-returned stock sits in a separate **Not for sale** bucket, visible in reports,
  until returned to the supplier or written off — mirroring the physical separation the rule requires.
- Alerts at configurable periods (default 30/60/90 days); suggested action "Return to supplier" while the
  supplier's return window is likely open (setting, default 90 days before expiry), else "Write off".
- Returned medicines default to *Not for sale*; restocking needs a permitted user's explicit decision.

## 8. Batches in the software

- Every medicine purchase line creates a lot: batch number, expiry, MRP, cost, supplier, quantity.
- The same medicine can have many lots with different MRP, cost and expiry.
- Billing proposes FEFO; the operator can pick the lot physically in hand.
- Bill lines store the lot, so batch-wise sales and "who bought batch X" (recall) can be reported.
- Batch number and expiry are mandatory for medicines at purchase and opening stock.

## 9. Storage and other fields

Storage instructions ("store below 25°C", "refrigerate 2–8°C") are recorded for display. The software does
not monitor temperature.

## 10. What the software deliberately does not do

- Decide whether a sale is lawful, or whether a prescription is genuine.
- Maintain an authoritative list of scheduled drugs.
- Check drug interactions, doses or allergies.
- Replace the pharmacist's signature or physical registers where the authority requires them.
