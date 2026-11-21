# AUSHVAN — how to run it, log in, and demo it

Everything here is run from the project folder (`f:\Mahesh\RMGSMS` on the development PC), in PowerShell.

## 1. Start the application (every time)

```powershell
php artisan serve --port=8000
```

Then open **http://localhost:8000** in Chrome or Edge. Keep that PowerShell window open while you use the app.

### On a phone (same Wi-Fi as the PC)

Start the server so that other devices can reach it:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

Then open **http://192.168.0.108:8000** on the phone (that is this PC's Wi-Fi address today; check with
`ipconfig` → "Wireless LAN adapter Wi-Fi" → "IPv4 Address" if the Wi-Fi changed, and update the
`SANCTUM_STATEFUL_DOMAINS` line in `.env` to the new `address:8000`, otherwise the login is refused).

The first time, allow it through Windows Firewall — run **PowerShell as Administrator** once:

```powershell
netsh advfirewall firewall add rule name="AUSHVAN 8000" dir=in action=allow protocol=TCP localport=8000
```

On the phone, use the browser's menu → "Add to Home screen" to get an app icon. On a plain `http` address the
camera scanner works in "take a photo of the barcode" mode; live scanning needs https (hosting).

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

## 7. A pack that is not in the system yet (scan to add)

Scan or type the barcode on the sale screen. If no product has it, a yellow bar says so with **Add product with
this barcode** (owner and manager). The product form opens with the barcode filled in: choose Medicine or General
item, type the name and MRP, Save. The "Add stock" box opens at once — quantity and purchase cost (medicines also
batch and expiry) — and you are back on the sale screen, where the same scan now adds the item. Expect about
20 seconds per new pack. The same button appears on the Products screen when a barcode search finds nothing.

## 8. Windows desktop package

```powershell
powershell -ExecutionPolicy Bypass -File build\windows\make-desktop.ps1 -Version 0.9.0
```

makes `build\windows\dist\AUSHVAN\` and `build\windows\out\AUSHVAN-0.9.0-windows.zip` (about 200 MB; with
Inno Setup installed it makes `AUSHVAN-Setup-0.9.0.exe` instead). On the shop PC: unzip, run `AUSHVAN.exe`.
The first start takes about a minute (it creates the database), then the app opens in a window; the small
AUSHVAN window stays in the tray and "Stop and close" stops it. Data: `%LOCALAPPDATA%\AUSHVAN\data\aushvan.sqlite`;
log: `%LOCALAPPDATA%\AUSHVAN\launcher.log`. The first visit shows the shop setup screen (no demo data).
Windows shows an "unknown publisher" warning for the unsigned program — "More info → Run anyway".

## 9. What NOT to promise in a demo

Purchases/supplier entry, expenses, day closing, backup, the Windows installer and the mobile app are **not
built yet**. The software has not run on a real printer or on a real phone with real packs. GST reports are
for information; GST filing and e-invoicing are not implemented. See `docs/IMPLEMENTATION_PLAN.md`.
