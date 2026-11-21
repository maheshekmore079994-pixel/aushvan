# UI / UX Guidelines

The design system and usability rules. The product should feel like a modern retail POS, not an ERP.

## Principles

1. **One job per screen, the main job obvious.** Each screen has one primary action, in one colour, in one place.
2. **Search first.** Lists open with the cursor in a search box. Results appear as you type.
3. **Few fields first.** Show the 4–6 fields needed nine times out of ten; the rest sit under "More details".
4. **Smart defaults, visible.** Today's date, walk-in customer, cash, quantity 1, FEFO lot — pre-filled and changeable.
5. **Plain words.** Sell, Purchase, Stock, Udhar, Payment, Day Closing. No "ledger posting", "COGS", "SKU" on operator screens.
6. **Compact, not cramped (changed 2026-11-11, D-038).** On a PC: 14 px text, 38 px controls, 36 px table rows, so a
   working day's information fits without scrolling. On phones and tablets: 15 px text and 41 px+ touch targets.
   The sale screen keeps a large search box and a large Pay button. Density is one setting: the root font size in `app.css`.
7. **Keyboard works everywhere, never required.** Every shortcut is also a visible button with its key shown on it.
8. **Confirm only what is hard to undo** (cancel bill, write-off, close day, delete). Everything else is immediate with an Undo toast where possible.
9. **Errors say what to do.** "Only 4 strips in stock. Reduce quantity or pick another batch." Shown beside the field.
10. **Fast on old PCs.** No heavy animation, no large images, paginated/virtualised lists, instant search from a local product cache.

## Design tokens

| Token | Use | Colour |
|---|---|---|
| Primary | main actions, links, active choices | Indigo `#4353E0` (dark `#3340B8`, soft `#ECEEFD`) |
| Success | paid, in stock, saved, New Sale / Pay | Green `#12946A` |
| Warning | low stock, near expiry, udhar | Amber `#A85F08` on `#FDF1DC` |
| Danger | expired, out of stock, cancel, errors | Red `#CF3F4A` |
| Medical accent | medicine tags and chart marks | Teal `#0B7D74` (chart `#0D9E94`) |
| General accent | general-store tags and chart marks | Violet `#6247D6` (chart `#7A5AF5`) |
| Surface | page / card | `#F4F5F9` / `#FFFFFF` |
| Text | primary / secondary | `#1B2236` / `#5A6478` |
| Rail | side menu, sign-in picture | Navy `#171C33` |

Changed 2026-11-11: the first palette (pure blue, green, orange, red) looked raw to the owner. The colours are
now one indigo for actions and slightly muted status colours used mostly as soft backgrounds with dark text;
solid colour is kept for the one main button of a screen. Tokens live in `resources/css/app.css`; screens use
token names (`bg-primary`, `text-ink-soft`), never raw colour classes.

- Colour always comes with an icon or word (never colour alone) — for clarity and colour-blind users.
- Status badges are consistent everywhere: `In stock` green, `Low` orange, `Out` red, `Expiring` orange,
  `Expired` red, `Udhar` orange, `Paid` green, `Held` blue, `Cancelled` grey.
- Typography: Inter for Latin text and numbers, Noto Sans Devanagari for Marathi and Hindi, both bundled
  locally — no CDN. Numbers use tabular figures so amounts line up.
- Spacing scale 4/8/12/16/24/32. Cards: 10 px radius, hairline border, almost no shadow. Controls: 7 px radius. One icon set (Lucide), bundled.
- Icons sit in a soft coloured tile (`IconTile`) beside numbers and names; empty screens show a picture and a next step (`EmptyState`).
- Amounts are always shown as `₹1,23,456.00` (Indian digit grouping).
- Dates are shown as `11 Nov 2026`; medicine expiry as `Nov 2026` (MM/YYYY entry).

## Mascot, loading and motion (D-044, D-045)

- **Sathi**, the mascot: `<Mascot mood="hello|happy|empty|sad" />`. Use it where a person would otherwise see a
  blank or a bare icon — sign-in, start-up, empty lists (`EmptyState` shows it automatically), failures. Never
  inside tables, forms, the sale screen or printed pages, and never as the only sign that something went wrong.
- **Loading**: `<Spinner label shape="rows|page|form|tiles" />` draws a skeleton of the screen that is coming.
  Pick the shape that matches. Buttons keep their small spinner while busy.
- **Motion classes** (in `app.css`): `animate-rise` (content arriving), `animate-pop` (menus, dialogs),
  `animate-slide-in` (toasts), `stagger` (children arrive in turn), `grow-x` / `grow-y` (chart marks).
  Keep it under about 400 ms, never animate something the operator is waiting to click, and do not add loops.

## Charts

- Hand-drawn SVG in `features/dashboard/charts.tsx`: columns (one value per day), a split bar (parts of a
  whole) and a ranked list with bars. No chart library.
- Thin marks, rounded at the data end, a white gap between touching marks, grey hairline grid, three axis ticks.
- Text is always normal text colour; a coloured square beside it carries identity. Two or more series always
  have a legend with the values written out. One series needs no legend: the card title names it.
- Chart colours are their own tokens (`--color-chart-*`), checked for colour-blind separation. Medicine is
  always teal and general goods always violet. Status colours are never used as series colours.
- Every chart has the same numbers as a table for screen readers, and a hover / touch / keyboard tooltip.
- Never two scales on one chart.

## Phones and tablets

- Under 1024 px the side menu slides in from the "More" button and a bottom bar shows Home, Stock, Sell, Udhar, More.
- The top bar keeps Quick links, connection state, language and sign out as icons.
- Wide tables become stacked cards on phones where it matters (product list so far); others scroll sideways.
- Dialogs fit the screen height and scroll inside. Touch targets stay 44 px or more.

## Components (built once, reused)

Button (primary / secondary / danger / ghost; sizes md, lg, xl) · Input, NumberInput, MoneyInput, QtyStepper ·
SearchBox with result list · Select / Combobox (type to filter) · DateInput and MonthYearInput · Badge ·
StatCard · DataTable (sticky header, sort, pagination, row actions, empty state) · Modal and side Drawer ·
Toast (success, error, undo) · ConfirmDialog (for destructive actions only) · PageHeader · EmptyState ·
FormSection with "More details" expander · Tabs · PrintLayout (58 mm, 80 mm, A4).

Component base: headless accessible primitives (Radix UI) styled with Tailwind; no heavy UI kit.

## Navigation

Left sidebar (light, 210 px, collapsible to a 56 px icon strip; the choice is remembered), icons + labels, grouped. Items appear only when their screen works:

```
New Sale (button) · Dashboard
SALES     Sales History · Udhar                      (later: Customers, Day Closing)
STOCK     Products · Medical Stock · General Stock · Expiry Alerts · Barcode Labels   (later: Purchases, Suppliers)
REPORTS   Reports                                    (later: Expenses)
SETTINGS  Shop details · Users · Roles
```

- **Quick links**: a button in the top bar on every screen (New Sale, Add product, Receive payment, Expiry
  Alerts, Barcode Labels, Reports) and the same as large tiles at the top of the dashboard, filtered by permission.
- **New Sale** is visually distinct (filled green/blue button at the top) and reachable with `F2` from anywhere.
- Day Closing is reached from the Dashboard and from the top bar (cash drawer icon showing "Day open/closed").
- Advanced/administrative functions live inside **Settings** (users & roles, shop & GST details, bill format,
  tax rates, drug schedules, units, categories, backup & restore, audit history, import/export).
- Top bar (44 px): menu button (phones), **Search** — "Search products or jump to a screen", also on `Ctrl+K` —
  Quick links, and the account menu (name, role, language English / मराठी / हिंदी, Sign out). A "No connection"
  pill appears only when the connection is lost.
- Menu items the user has no permission for are not shown.

## Wireframes

### POS — New Sale (the most important screen)

```
+----------------------------------------------------------------------------------------------+
| [=] New Sale                         Day: OPEN   (o) Online    EN | मराठी | हिंदी    Asha v  |
+-------------------------------------------------------------+--------------------------------+
| [ Search name / salt / scan barcode...            (F3) ]    |  Customer: Walk-in   [Change F4]|
| +---------------------------------------------------------+ |  ------------------------------ |
| | Crocin 500 Tab   Paracetamol 500mg   Strip ₹30   12 in  | |  Items            4             |
| | Crocin Advance   Paracetamol 500mg   Strip ₹21    3 LOW | |  Subtotal         ₹ 356.00      |
| | Crocin Syrup 60ml                    Btl  ₹45    EXPIRED| |  Discount       - ₹   6.00      |
| +---------------------------------------------------------+ |  (incl. GST ₹ 21.43)            |
|                                                             |  ------------------------------ |
|  #  Item                    Batch/Exp    Qty      Rate  Amt |                                |
|  1  Crocin 500 Tab [MED]    B123 Nov27  [-] 2 [+]  30    60 |   TOTAL                        |
|     strip v                                              x  |   ₹ 350.00                     |
|  2  Sugar loose             -           [ 500 g ]  42/kg 21 |                                |
|  3  Parle-G 100g            -           [-] 3 [+]  10    30 |  +--------------------------+  |
|  4  Dettol Soap 75g         -           [-] 5 [+]  49   245 |  |      PAY  (F9)           |  |
|                                                             |  +--------------------------+  |
|  Quick items:                                               |  [ Hold F6 ] [ Held bills 2 ]  |
|  [ Sugar ] [ Eggs ] [ Milk ] [ Oil loose ] [ ORS ] [ + ]    |  [ Clear ]   [ Last bill F8 ]  |
+-------------------------------------------------------------+--------------------------------+
```

Behaviour:
- Search box keeps focus by default; a barcode scan from anywhere on the screen is captured.
- **Camera scanning.** The search box carries a camera button (`ScanButton`) on any device with a usable camera or
  a touch screen; a counter PC with neither does not show it. The scanner dialog shows the camera picture with an
  aiming box, says what happened to each pack in words (green "Added: name × 2", red "No product has the barcode …",
  "out of stock") with a beep and a buzz, and stays open until Done. A pack held still is added once: a barcode
  must be read in two frames running, and counts again only after it has been out of the picture for 3 seconds
  (each barcode is remembered separately, so a pack with two barcodes cannot repeat). "Checking…" shows while the
  lookup is on its way; a lookup that returns after Done adds nothing. One more than the stock is refused there. A camera number is looked up as
  a barcode only, never searched as a name. On the product list one scan fills the search box; on the product
  form one scan fills that barcode box.
- Result rows show: name, salt (medicines), unit price, stock, and a status badge. Arrow keys + Enter, or click.
- Each line: unit dropdown (strip/tablet, kg/g), lot chip for batch-tracked items (click to change),
  big quantity stepper, editable rate only with permission, line discount under a small "%" button.
- `[MED]` teal tag / `[GEN]` violet tag for product type; `Rx` tag when a prescription record is needed.
- Right panel is always visible; TOTAL is the largest text on the screen.

### Pay dialog

```
+------------------------------- Pay  ₹ 350.00 -------------------------------+
|  [ CASH ]  [ UPI ]  [ CARD ]  [ BANK ]  [ UDHAR ]        (+ add another)    |
|                                                                              |
|   Cash received   [ 500        ]     quick: [350] [400] [500] [1000]         |
|   Return change     ₹ 150.00                                                 |
|                                                                              |
|   Split example:  Cash ₹200  + Udhar ₹150 (customer required)                |
|                                                                              |
|   [x] Print bill      ( ) Thermal  ( ) A4                                    |
|                                    [ Cancel Esc ]   [ COMPLETE SALE  Enter ] |
+------------------------------------------------------------------------------+
```

- Default: full amount in cash; Enter completes. Two key presses for the ordinary sale (F9, Enter).
- Choosing Udhar with a walk-in customer opens customer search/quick-add (name + mobile only).
- If prescription/pharmacist details are required, a step appears *before* this dialog with only the required fields.
- After completion: green "Saved — Bill 2026-27/000123" toast with **Print again** and **Share**, screen resets.

### Dashboard

```
+------------------------------------------------------------------------------------+
| Good morning, Asha                                   [ + New Sale ]  [ Day Closing ] |
| ! 3 medicines expired   ! 12 expiring in 30 days   ! 8 products low on stock        |
+------------------+------------------+------------------+---------------------------+
| Today's Sales    | Cash received    | UPI received     | Udhar to collect          |
| ₹ 8,450   23 bills| ₹ 5,200         | ₹ 2,150          | ₹ 34,600   41 customers   |
+------------------+------------------+------------------+---------------------------+
| Purchases today  | Expenses today   | Today's profit*  | To pay suppliers          |
| ₹ 12,000         | ₹ 350            | ₹ 1,640 (approx) | ₹ 18,200   3 due this week|
+------------------+------------------+------------------+---------------------------+
| Sales - last 7 days (bars)             | Recent bills                              |
| ▂ ▅ ▃ ▇ ▆ ▄ █                          | 000123  Walk-in      ₹350  Cash   10:42   |
|                                        | 000122  Ramesh P.    ₹500  Udhar  10:31   |
| Stock: 1,240 products  8 low  2 out    | 000121  Walk-in      ₹ 45  UPI    10:20   |
+----------------------------------------+-------------------------------------------+
* Sales minus cost of goods sold. Expenses not deducted.
```

- Alert strip at the top is only shown when there is something to act on; each alert is a link.
- Eight cards maximum. Financial cards (profit, supplier dues) are hidden for roles without permission.

### Product form (add / edit)

```
 Type:  ( Medical )  ( General )
 Name [______________________]   Local name [______________]
 Category [ v ]   Sold in [ Strip v ]  1 Strip = [10] Tablets   [x] allow loose
 MRP [____]  Selling price [____]  GST [ 5% v ]
 Barcode [____________] (optional)  [Generate]
 -- Medicine --  Salt/Generic [________]  Strength [____]  Form [Tablet v]  Schedule [None v]
 > More details (HSN, brand, manufacturer, rack, min stock, supplier, storage, image, extra units/barcodes)
                                                       [ Save ]  [ Save & add stock ]
```

### Purchase entry

```
 Supplier [ search v ]   Invoice no [______]  Invoice date [__]        Status: DRAFT
 +----+----------------+--------+--------+-----+------+------+-------+------+------+---------+
 | #  | Product        | Batch  | Expiry | Qty | Free | Unit | Rate  | Disc | GST  | Amount  |
 | 1  | Crocin 500 Tab | B123   | 11/27  | 10  | 1    | Box  | 250.00| 0%   | 5%   | 2625.00 |
 | 2  | [search/scan to add next line...]                                                    |
 +----+----------------+--------+--------+-----+------+------+-------+------+------+---------+
 Other charges [__]   Round off [__]        Total ₹ 2,625.00   (invoice total matches? [____])
 Paid now [____] by [Cash v]   Due date [__]   [Attach invoice photo]
                                   [ Save draft ]   [ CONFIRM & ADD TO STOCK ]
```

Tab/Enter moves cell to cell like a spreadsheet; batch/expiry cells appear only for batch-tracked products.

### Udhar (customer credit)

```
 [ Search customer name / mobile ]                          Total to collect: ₹ 34,600
 Ramesh Patil   Wadgaon   98xxxxxx10        ₹ 1,200   45 days    [ Receive payment ]
 Sunita Jadhav  Wadgaon   97xxxxxx22        ₹   800   12 days    [ Receive payment ]

 -- Ramesh Patil — statement --                                [ Print ] [ Share ]
 Date        Details                  Udhar (+)   Paid (-)   Balance
 01 Nov      Bill 000087               500.00                 500.00
 01 Nov      Cash received                         200.00     300.00
 05 Nov      Cash received                         100.00     200.00
```

### Expiry Alerts

```
 [ Expired 3 ] [ 30 days 12 ] [ 60 days 20 ] [ 90 days 31 ] [ Custom ]        [ Print ] [ Export ]
 Medicine         Batch   Expiry    Qty   Value(cost)  Supplier      Action
 Crocin Syrup     S77     Oct 2026  4     ₹ 128        Shree Pharma  [ Return to supplier ] [ Write off ]
```

### Day Closing

```
 Opening cash             ₹ 1,000        Counted cash in drawer  [ ________ ]
 + Cash sales             ₹ 5,200        Difference              ₹ 0   (OK)
 + Udhar received (cash)  ₹   600
 - Cash refunds           ₹    50        UPI today   ₹ 2,150
 - Expenses (cash)        ₹   350        Card today  ₹     0
 - Paid to suppliers      ₹ 2,000
 - Cash taken out         ₹     0                              [ CLOSE DAY ]
 = Cash that should be there  ₹ 4,400
```

## Keyboard shortcuts (shown on the buttons)

`F2` New Sale · `F3` focus search · `F4` customer · `F6` hold · `F8` reprint last bill · `F9` pay ·
`Enter` confirm · `Esc` close/cancel · `+` / `-` quantity on the selected line · `Del` remove line ·
`Alt+number` quick item. Browser-reserved keys (F1, F5, F11, F12, Ctrl+P) are not overridden.

## Language

- All text comes from translation files (`en`, `mr`, `hi`). English is the default; switch is in the top bar
  and remembered per user.
- Product names are data, not translated; each product has an optional **local name** which is searchable
  and can be printed on the bill.
- Receipts can be printed in English or Marathi (setting).
- Layout must tolerate Devanagari text that is ~30% longer/taller than English.

## Forms and validation

- Inline validation on blur and on submit; the first invalid field gets focus.
- Server validation errors are mapped back to the same fields.
- Mandatory fields are kept to the minimum the business rule needs and are marked with `*`.
- Money inputs accept `350`, `350.5`, `350.50`; quantity inputs accept whole numbers in the chosen unit.
- Autofocus the first field; Enter moves forward; submit buttons show progress and are disabled while saving.

## Printing

- Bill layouts: thermal 58 mm, thermal 80 mm, A4/A5. Built as HTML + print CSS; no printer-specific driver code.
- Shop header, licence numbers, GSTIN, bill footer text and which optional columns to print are settings.
- Every report has Print and Export (CSV/Excel); PDF through the browser's "Save as PDF".

## Accessibility and robustness

- Contrast at least 4.5:1 for text. Visible focus ring on every control.
- Works at 1366×768 (common low-end monitor) without horizontal scrolling; usable down to tablet width.
- Every list has an empty state that says what to do next ("No products yet — Add product / Import from Excel").
- Long operations show progress; nothing blocks the screen without feedback for more than a moment.
