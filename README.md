# AUSHVAN — Medical & General Store Management Software

*Every Store. Better Managed.* — a product of TriMora Technologies (TriMora Group).

Billing, stock and udhar (credit) software for a small combined medical and general store in rural
Maharashtra. Laravel 13 + React 19, served as one application.

**Status:** Built — shop setup, login, users and editable roles, products and batch stock, expiry alerts, billing
(cash, UPI, card, bank, split, udhar), bill printing, sales history, customers and udhar, prescription and pharmacist
checks, analytics dashboard, ten reports with Excel / CSV / PDF export, barcode label printing, phone-friendly
screens, English / Marathi / Hindi. **Not built yet:** returns and bill cancellation, purchases and suppliers,
expenses, day closing, backup, Windows desktop build, mobile app. See [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md).

Day-to-day commands, demo logins and a 15-minute demo script are in [HOW_TO_RUN.md](HOW_TO_RUN.md).

## Run it on a development PC

Needs PHP 8.3+ (with `pdo_sqlite`, `mbstring`, `intl`, `bcmath`), Composer 2 and Node.js 20+.

```sh
composer install
npm install
cp .env.example .env        # Windows: copy .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

Open http://localhost:8000. The first visit asks for the shop name and creates the owner login.

## Try it with a demo shop

```sh
php artisan migrate:fresh
php artisan db:seed --class=DemoDataSeeder
```

Sign in as `owner`, `pharmacist`, `manager` or `cashier` with password `demo-pass-123` (pharmacist PIN `1234`).
The demo has 40 medicines and 40 general items with low-stock, out-of-stock, near-expiry and expired examples,
6 customers and 62 bills spread over the last 14 days. Its drug
schedules, GST rates and prices are for illustration only.

## Checks

```sh
php artisan test      # backend
npm test              # frontend
npm run typecheck
vendor/bin/pint
```

## Documentation

Everything about what is being built and why is in [docs/](docs/). Start with
[PROJECT_VISION.md](docs/PROJECT_VISION.md). Rules for development sessions are in [CLAUDE.md](CLAUDE.md).

Deployment to hosting and the Windows desktop build are documented in later phases
([OFFLINE_WINDOWS_STRATEGY.md](docs/OFFLINE_WINDOWS_STRATEGY.md)).
