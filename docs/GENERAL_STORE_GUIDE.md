# General Store (Kirana) Guide

How general merchandise is handled, and why. Read with `DATABASE_DESIGN.md` (units, batches).

## How a village kirana actually works

- Most packaged goods carry a printed **MRP** (maximum retail price, inclusive of all taxes). The shop sells
  at MRP or a little below. The shop may never charge above MRP.
- Goods arrive in **cartons / boxes / jars / sacks** and are sold as single packets, pieces, or loose by weight.
- Many items have **no barcode**: loose grains, sugar, pulses, jaggery, eggs, local snacks, single sachets
  cut from a strip, items from local makers. Some have a barcode on the carton but not the piece.
- The same product comes in many **sizes** (₹5, ₹10, 100 g, 250 g, 1 kg). Each size has its own MRP and barcode.
- MRPs change often. Old-MRP and new-MRP stock of the same item sit on the shelf together.
- Many food items have a **best-before date**; the shopkeeper usually tracks this by eye, not by batch.
- Regular customers buy on **udhar** and settle weekly, monthly or at harvest.

## Design consequences

### 1. Every size is its own product
"Parle-G 50 g" and "Parle-G 100 g" are two products with their own price, barcode and stock. This matches the
shelf and the barcode, and avoids a variant engine the shop would never configure. Products can share a brand
and category so they group together in search. (A formal "variant family" link is deferred — see
`DECISION_LOG.md`.)

### 2. Base unit and unit conversion
Every product has one **base unit**: the smallest quantity the shop will ever sell or count.

| Product | Base unit | Other units (factor to base) |
|---|---|---|
| Biscuit packet | PCS | BOX = 24 PCS |
| Shampoo sachet | PCS | STRIP = 12 PCS, BOX = 960 PCS |
| Sugar (loose) | G | KG = 1000 G, BAG = 50,000 G |
| Cooking oil (loose) | ML | L = 1000 ML, TIN = 15,000 ML |
| Paracetamol 500 | TAB | STRIP = 10 TAB, BOX = 100 TAB |
| Cough syrup 100 ml | BTL | — |

Rules:

- Stock is always stored and counted as a **whole number of base units**. No fractions anywhere in stock.
- Conversions are **per product** (one brand's box is 12, another's is 24). There is no global "box = 12".
- A product is purchased in one unit (BOX) and sold in another (PCS); the system converts using that
  product's factor. The factor used is saved on each purchase and sale line, so later edits to the product
  do not change history.
- A conversion factor cannot be changed once the unit has been used in a transaction. Create a new unit
  row (or a new product) instead. This prevents silent stock corruption.
- Weight/volume products use a small base unit (gram, millilitre) so that "250 g" is the whole number 250.

### 3. Prices
- Each product has a **pricing unit** — the unit the MRP is printed on (packet, strip, kg).
- MRP and selling price are stored per pricing unit. Other units are priced proportionally unless the shop
  sets an explicit price for that unit (for example a cheaper price for a full box).
- Selling price can be equal to or lower than MRP. The system warns if selling price is above MRP.
- When a purchase arrives with a new MRP, a new stock lot is created with that MRP; old lots keep the old
  MRP. The bill uses the MRP of the lot actually sold. Price changes are written to price history.

### 4. Stock lots for general goods (hidden batches)
Every purchase line creates a **stock lot** that remembers its cost, MRP and (optionally) expiry date.
For general goods the operator never has to see or choose a lot: the system consumes the oldest lot first
(earliest expiry first when dates are present). This gives correct cost and profit and allows optional
best-before tracking for food, without adding steps at the counter.

A general product can switch on **"Track expiry"** (useful for packaged food, baby food, cosmetics). It is
off by default for general goods and always on for medicines.

### 5. Tax
- GST rate and HSN code are set per product from a configurable list of tax rates. Kirana items span
  nil-rated, low-rate and standard-rate goods, so nothing is assumed.
- Retail prices are tax-inclusive; tax is calculated backwards from the price. See `TECHNICAL_ARCHITECTURE.md`.

### 6. Selling without a barcode
- Search by name (English or local name), brand, or short code; the top results are large buttons.
- "Quick items" grid on the POS for the shop's most-sold non-barcode goods (sugar, eggs, loose oil).
- For weight items the operator types either the weight (250 g) or the amount (₹20 of sugar) and the
  system works out the other.
- The shop can print its own barcode labels for any product, but never has to.

## Common categories (seeded, editable)

Groceries & staples · Snacks & biscuits · Beverages · Dairy & eggs · Personal care · Household & cleaning ·
Baby products · Stationery · Pooja items · Health & hygiene (non-medicine) · Other.

## Things deliberately not built in version 1

- Weighing-scale integration and scale-printed barcodes.
- Recipe/kit products (bundles), loyalty points, schemes engine ("buy 2 get 1" is handled as a discount or
  as free quantity on purchase).
- Automatic re-ordering to suppliers (version 1 shows a low-stock purchase suggestion list only).
