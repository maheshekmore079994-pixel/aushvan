# AUSHVAN — how to run it, log in, and demo it

Everything here is run from the project folder (`f:\Mahesh\RMGSMS` on the development PC), in PowerShell.

## 1. Start the application (every time)

```powershell
php artisan serve --port=8000
```

Then open **http://localhost:8000** in Chrome or Edge. Keep that PowerShell window open while you use the app.

To use it from a phone on the same Wi-Fi, start it with your PC's address instead (find it with `ipconfig`,
"IPv4 Address"), for example:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

and open `http://<your-pc-ip>:8000` on the phone. (Windows Firewall may ask once — allow it. On a plain `http`
address the phone's camera scanner works in "take a photo" mode; live scanning needs https or hosting.)

## 2. Demo shop and logins

Load the demo shop (this **wipes the local database** — never run it on a real shop's database):

```powershell
php artisan migrate:fresh
php artisan db:seed --class=DemoDataSeeder
```

Do this on the **morning of a demo**: the demo bills are dated relative to the day you seed, so "today's sales"
on the dashboard are then really today's.

| Login | Password | Role | What they can do |
|---|---|---|---|
| `owner` | `demo-pass-123` | Owner | Everything: reports, roles, users, cancel bills, settings |
| `pharmacist` | `demo-pass-123` | Pharmacist (PIN **1234**) | Bill, approve prescription medicine, put returned medicine back on sale |
| `manager` | `demo-pass-123` | Store manager | Products, stock, reports |
| `cashier` | `demo-pass-123` | Cashier | Bill, customers, udhar, returns |

Demo shop: "Shree Medical & General Stores", Wadgaon. 80 products (40 medicines with batches and expiry,
40 kirana items), 6 udhar customers, about 60 bills over the last two weeks.

## 3. After pulling new code

```powershell
composer install
npm install
php artisan migrate
npm run build
```

## 4. First-time install on a new PC

Needs PHP 8.3+ (with pdo_sqlite, mbstring, intl, bcmath, gd), Composer 2, Node.js 20+.

```powershell
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

The first visit shows the shop setup screen (shop name + owner login) — or load the demo shop as in section 2.

## 5. Checks before saying "done"

```powershell
php artisan test        # backend (SQLite)
npm test                # frontend
npm run typecheck
vendor\bin\pint --test
```

Backend on MariaDB as well (WAMP's server on this PC, port 3307):

```powershell
$env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3307'; $env:DB_DATABASE='rmgsms_test'; $env:DB_USERNAME='root'; $env:DB_PASSWORD=''; php artisan test
```

## 6. Demo script (about 15 minutes)

Sign in as **owner** unless said otherwise.

1. **Dashboard** — today's sales, udhar to collect, low stock and expiry alerts, charts. Switch the language
   (account menu, top right) to Marathi and back.
2. **New Sale** (Ctrl+K or the green button) — type `cro` → Crocin; press Enter twice (quantity goes to 2);
   scan a barcode with the phone camera if a phone is handy (camera button in the search box); add loose
   tablets (unit drop-down); choose customer *Ramesh Patil* (F4); **Pay** (F9): cash with change, or part UPI
   part udhar. The bill opens — **Print**.
3. **Prescription medicine** — sign in as **cashier**, sell *Taxim-O 200* (schedule H1): the Pay dialog asks for
   doctor, patient and the pharmacist's PIN (**1234**).
4. **Udhar** — the customer list with balances, open *Ramesh Patil*, statement, **Receive payment**.
5. **Return goods** — open a bill from Sales History → Return goods: tick an item, see the amount come from
   the bill's prices, choose "pay back now" or "take it off the udhar"; the credit note prints. Then
   **Cancel bill** on today's bill made by mistake (owner only).
6. **Stock** — Products: search, a product's batches and stock history; **Expiry Alerts**: expired and
   near-expiry batches with values; **Barcode Labels**: print stickers for an item without a barcode.
7. **Reports** — Sales by day, GST summary, Expiry, Udhar outstanding; save one as Excel and one as PDF.
8. **Roles and Users** — show that a cashier cannot cancel a bill or see cost prices; change a permission.
9. On a **phone** (same Wi-Fi, section 1): open the sale screen, scan a pack with the camera.

## 7. What NOT to promise in a demo

Purchases/supplier entry, expenses, day closing, backup, the Windows installer and the mobile app are **not
built yet**. The software has not run on a real printer or on a real phone with real packs. GST reports are
for information; GST filing and e-invoicing are not implemented. See `docs/IMPLEMENTATION_PLAN.md`.
