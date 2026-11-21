<?php

namespace Database\Seeders;

use App\Actions\AddOpeningStock;
use App\Actions\AdjustStock;
use App\Actions\CompleteSale;
use App\Actions\ReceiveCustomerPayment;
use App\Actions\SaveProduct;
use App\Actions\SetUpShop;
use App\Domain\Sales\SalePricer;
use App\Enums\GstMode;
use App\Enums\RoleName;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DrugSchedule;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A sample shop for trying the system: php artisan db:seed --class=DemoDataSeeder
 *
 * Everything is created through the same actions the screens use, so the
 * stock ledger, price history and audit log are filled in exactly as they
 * would be in real use.
 *
 * FOR DEMONSTRATION ONLY. Prices, GST rates, HSN codes and above all the
 * drug schedules given to the sample medicines are illustrative. A real shop
 * must set each medicine's schedule from its pack label.
 */
class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'demo-pass-123';

    /** Days before today that get a few walk-in bills each (52 bills in all), for charts and reports. */
    public const PAST_DAYS = 13;

    private User $owner;

    /** @var array<string, int> */
    private array $units;

    /** @var array<string, int> */
    private array $taxes;

    /** @var array<string, int> */
    private array $schedules;

    /** @var array<string, int> */
    private array $categories;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data cannot be loaded into a production installation.');
        }
        if (User::withTrashed()->exists()) {
            throw new RuntimeException('Demo data can only be loaded into an empty database. Run: php artisan migrate:fresh');
        }

        mt_srand(20261111); // the same demo shop every time

        $this->shopAndUsers();
        Auth::setUser($this->owner);

        $this->units = Unit::pluck('id', 'code')->all();
        $this->taxes = TaxRate::pluck('id', 'name')->all();
        $this->schedules = DrugSchedule::pluck('id', 'code')->all();
        $this->categories = Category::where('store_id', $this->owner->store_id)->pluck('id', 'name')->all();

        $this->medicines();
        $this->generalGoods();
        $this->customersAndBills();

        Carbon::setTestNow();
        Auth::forgetUser();

        $this->command?->info('Demo shop ready. Logins: owner, pharmacist, manager, cashier — password '.self::PASSWORD);
    }

    private function shopAndUsers(): void
    {
        $this->owner = app(SetUpShop::class)->handle([
            'shop_name' => 'Shree Medical & General Stores',
            'village' => 'Wadgaon',
            'phone' => '9800000000',
            'name' => 'Asha Patil',
            'username' => 'owner',
            'password' => self::PASSWORD,
            'locale' => 'en',
        ]);

        $store = $this->owner->store;
        $store->update([
            'legal_name' => 'Shree Medical & General Stores',
            'address' => 'Main Road, near Bus Stand',
            'taluka' => 'Maval',
            'district' => 'Pune',
            'pincode' => '412106',
            'gst_mode' => GstMode::Regular,
            'gstin' => '27AAAAA0000A1Z5',
        ]);
        $store->putSetting('drug_licence_20', 'MH-PZ4-000001');
        $store->putSetting('drug_licence_21', 'MH-PZ4-000002');
        $store->putSetting('bill_footer', 'Thank you. Visit again.');

        foreach ([
            ['Dr. Suresh Kulkarni', 'pharmacist', RoleName::Pharmacist, true, 'en'],
            ['Mahesh Jadhav', 'manager', RoleName::StoreManager, false, 'mr'],
            ['Ravi Shinde', 'cashier', RoleName::Cashier, false, 'mr'],
        ] as [$name, $username, $role, $pharmacist, $locale]) {
            $user = User::create([
                'store_id' => $store->id,
                'name' => $name,
                'username' => $username,
                'password' => self::PASSWORD,
                'pin' => '1234',
                'locale' => $locale,
                'is_pharmacist' => $pharmacist,
                'pharmacist_reg_no' => $pharmacist ? 'MSPC-123456' : null,
            ]);
            $user->assignRole($role->value);
        }
    }

    private function medicines(): void
    {
        // name, generic, strength, form, schedule (null = not set), base, pack, size, MRP Rs, category, maker, rack
        $rows = [
            ['Crocin 500 Tablet', 'Paracetamol', '500 mg', 'tablet', 'NONE', 'TAB', 'STRIP', 15, 30.00, 'Tablets & Capsules', 'GSK', 'A1'],
            ['Dolo 650 Tablet', 'Paracetamol', '650 mg', 'tablet', 'NONE', 'TAB', 'STRIP', 15, 33.60, 'Tablets & Capsules', 'Micro Labs', 'A1'],
            ['Calpol 250 Suspension 60ml', 'Paracetamol', '250 mg/5 ml', 'suspension', 'NONE', 'BTL', null, 1, 45.00, 'Syrups & Liquids', 'GSK', 'B1'],
            ['Combiflam Tablet', 'Ibuprofen + Paracetamol', '400 mg + 325 mg', 'tablet', 'H', 'TAB', 'STRIP', 20, 47.00, 'Tablets & Capsules', 'Sanofi', 'A1'],
            ['Cetzine 10 Tablet', 'Cetirizine', '10 mg', 'tablet', 'NONE', 'TAB', 'STRIP', 15, 23.50, 'Tablets & Capsules', 'Dr. Reddys', 'A2'],
            ['Allegra 120 Tablet', 'Fexofenadine', '120 mg', 'tablet', 'H', 'TAB', 'STRIP', 10, 218.00, 'Tablets & Capsules', 'Sanofi', 'A2'],
            ['Mox 500 Capsule', 'Amoxicillin', '500 mg', 'capsule', 'H', 'CAP', 'STRIP', 15, 108.00, 'Tablets & Capsules', 'Sun Pharma', 'A3'],
            ['Augmentin 625 Duo Tablet', 'Amoxicillin + Clavulanic Acid', '500 mg + 125 mg', 'tablet', 'H', 'TAB', 'STRIP', 10, 223.00, 'Tablets & Capsules', 'GSK', 'A3'],
            ['Azithral 500 Tablet', 'Azithromycin', '500 mg', 'tablet', 'H', 'TAB', 'STRIP', 5, 132.00, 'Tablets & Capsules', 'Alembic', 'A3'],
            ['Taxim-O 200 Tablet', 'Cefixime', '200 mg', 'tablet', 'H1', 'TAB', 'STRIP', 10, 118.00, 'Tablets & Capsules', 'Alkem', 'A3'],
            ['Ciplox 500 Tablet', 'Ciprofloxacin', '500 mg', 'tablet', 'H', 'TAB', 'STRIP', 10, 46.00, 'Tablets & Capsules', 'Cipla', 'A3'],
            ['Pan 40 Tablet', 'Pantoprazole', '40 mg', 'tablet', 'H', 'TAB', 'STRIP', 15, 170.00, 'Tablets & Capsules', 'Alkem', 'A4'],
            ['Omez 20 Capsule', 'Omeprazole', '20 mg', 'capsule', 'H', 'CAP', 'STRIP', 20, 68.00, 'Tablets & Capsules', 'Dr. Reddys', 'A4'],
            ['Rantac 150 Tablet', 'Ranitidine', '150 mg', 'tablet', 'H', 'TAB', 'STRIP', 30, 42.00, 'Tablets & Capsules', 'JB Chemicals', 'A4'],
            ['Digene Gel Mint 200ml', 'Antacid', null, 'gel', 'NONE', 'BTL', null, 1, 165.00, 'Syrups & Liquids', 'Abbott', 'B1'],
            ['Glycomet 500 Tablet', 'Metformin', '500 mg', 'tablet', 'H', 'TAB', 'STRIP', 20, 42.00, 'Tablets & Capsules', 'USV', 'A5'],
            ['Amlong 5 Tablet', 'Amlodipine', '5 mg', 'tablet', 'H', 'TAB', 'STRIP', 15, 38.00, 'Tablets & Capsules', 'Micro Labs', 'A5'],
            ['Telma 40 Tablet', 'Telmisartan', '40 mg', 'tablet', 'H', 'TAB', 'STRIP', 30, 236.00, 'Tablets & Capsules', 'Glenmark', 'A5'],
            ['Atorva 10 Tablet', 'Atorvastatin', '10 mg', 'tablet', 'H', 'TAB', 'STRIP', 15, 96.00, 'Tablets & Capsules', 'Zydus', 'A5'],
            ['Ecosprin 75 Tablet', 'Aspirin', '75 mg', 'tablet', 'H', 'TAB', 'STRIP', 14, 5.80, 'Tablets & Capsules', 'USV', 'A5'],
            ['Thyronorm 50 Tablet', 'Thyroxine', '50 mcg', 'tablet', 'H', 'TAB', 'BTL', 120, 215.00, 'Tablets & Capsules', 'Abbott', 'A6'],
            ['Montair LC Tablet', 'Montelukast + Levocetirizine', '10 mg + 5 mg', 'tablet', 'H', 'TAB', 'STRIP', 15, 310.00, 'Tablets & Capsules', 'Cipla', 'A2'],
            ['Asthalin Inhaler', 'Salbutamol', '100 mcg', 'inhaler', 'H', 'PCS', null, 1, 172.00, 'Drops & Sprays', 'Cipla', 'C1'],
            ['Benadryl Cough Syrup 100ml', 'Diphenhydramine', null, 'syrup', null, 'BTL', null, 1, 148.00, 'Syrups & Liquids', 'J&J', 'B2'],
            ['Ascoril LS Syrup 100ml', 'Ambroxol + Levosalbutamol + Guaiphenesin', null, 'syrup', 'H', 'BTL', null, 1, 128.00, 'Syrups & Liquids', 'Glenmark', 'B2'],
            ['Zincovit Tablet', 'Multivitamin + Zinc', null, 'tablet', 'NONE', 'TAB', 'STRIP', 15, 110.00, 'Tablets & Capsules', 'Apex', 'A6'],
            ['Shelcal 500 Tablet', 'Calcium + Vitamin D3', '500 mg', 'tablet', 'NONE', 'TAB', 'STRIP', 15, 134.00, 'Tablets & Capsules', 'Torrent', 'A6'],
            ['Becosules Capsule', 'B-Complex + Vitamin C', null, 'capsule', 'NONE', 'CAP', 'STRIP', 20, 52.00, 'Tablets & Capsules', 'Pfizer', 'A6'],
            ['Electral Powder 21.8g', 'Oral Rehydration Salts', null, 'sachet', 'NONE', 'SACHET', null, 1, 22.00, 'Health & Hygiene', 'FDC', 'C2'],
            ['Betadine Ointment 20g', 'Povidone Iodine', '10%', 'ointment', 'NONE', 'TUBE', null, 1, 128.00, 'Creams & Ointments', 'Win-Medicare', 'C3'],
            ['Soframycin Cream 30g', 'Framycetin', '1%', 'cream', 'H', 'TUBE', null, 1, 56.00, 'Creams & Ointments', 'Sanofi', 'C3'],
            ['Volini Gel 30g', 'Diclofenac', '1.16%', 'gel', null, 'TUBE', null, 1, 135.00, 'Creams & Ointments', 'Sun Pharma', 'C3'],
            ['Candid Cream 20g', 'Clotrimazole', '1%', 'cream', 'H', 'TUBE', null, 1, 98.00, 'Creams & Ointments', 'Glenmark', 'C3'],
            ['Otrivin Nasal Drops 10ml', 'Xylometazoline', '0.1%', 'drops', 'NONE', 'BTL', null, 1, 96.00, 'Drops & Sprays', 'GSK', 'C1'],
            ['Moxicip Eye Drops 5ml', 'Moxifloxacin', '0.5%', 'drops', 'H', 'BTL', null, 1, 118.00, 'Drops & Sprays', 'Cipla', 'C1'],
            ['Insugen 30/70 Injection 10ml', 'Human Insulin', '40 IU/ml', 'injection', 'C', 'VIAL', null, 1, 165.00, 'Injections', 'Biocon', 'FRIDGE'],
            ['Alprax 0.25 Tablet', 'Alprazolam', '0.25 mg', 'tablet', 'H1', 'TAB', 'STRIP', 15, 28.00, 'Tablets & Capsules', 'Torrent', 'LOCKED'],
            ['Meftal Spas Tablet', 'Mefenamic Acid + Dicyclomine', '250 mg + 10 mg', 'tablet', 'H', 'TAB', 'STRIP', 10, 52.00, 'Tablets & Capsules', 'Blue Cross', 'A1'],
            ['Norflox TZ Tablet', 'Norfloxacin + Tinidazole', '400 mg + 600 mg', 'tablet', 'H', 'TAB', 'STRIP', 10, 98.00, 'Tablets & Capsules', 'Cipla', 'A3'],
            ['Vicks Action 500 Tablet', 'Paracetamol + Phenylephrine + Caffeine', null, 'tablet', 'NONE', 'TAB', 'STRIP', 10, 35.00, 'Tablets & Capsules', 'P&G', 'A1'],
        ];

        foreach ($rows as $index => [$name, $generic, $strength, $form, $schedule, $base, $pack, $size, $mrp, $category, $maker, $rack]) {
            $product = app(SaveProduct::class)->handle($this->owner, [
                'type' => Product::TYPE_MEDICAL,
                'name' => $name,
                'category_id' => $this->categories[$category],
                'manufacturer' => $maker,
                'hsn_code' => '3004',
                'tax_rate_id' => $this->taxes['GST 5%'],
                'base_unit_id' => $this->units[$base],
                'pack_unit_id' => $pack ? $this->units[$pack] : null,
                'pack_size' => $pack ? $size : null,
                'allow_loose_sale' => $pack === 'STRIP',
                'mrp_paise' => $this->paise($mrp),
                'selling_price_paise' => $this->paise($mrp),
                'min_stock' => $pack ? 5 : 3,
                'rack_location' => $rack,
                'medicine' => [
                    'generic_name' => $generic,
                    'composition_text' => trim($generic.' '.($strength ?? '')),
                    'strength' => $strength,
                    'dosage_form' => $form,
                    'drug_schedule_id' => $schedule ? $this->schedules[$schedule] : null,
                    'storage_instructions' => $rack === 'FRIDGE' ? 'Refrigerate at 2-8 C. Do not freeze.' : null,
                ],
            ]);

            // Every 13th medicine is left without stock, to show "out of stock".
            if ($index % 13 !== 12) {
                $this->stockMedicine($product, $index, $this->paise($mrp));
            }
        }
    }

    private function stockMedicine(Product $product, int $index, int $mrp): void
    {
        $cost = (int) round($mrp * 0.78);
        $batchNo = fn () => chr(65 + mt_rand(0, 25)).chr(65 + mt_rand(0, 25)).mt_rand(1000, 9999);
        $month = fn (int $monthsAhead) => now()->addMonthsNoOverflow($monthsAhead)->format('Y-m');
        $line = fn (int $qty, int $monthsAhead, int $batchMrp) => [
            'qty' => $qty, 'unit' => 'pack', 'cost_paise' => (int) round($batchMrp * 0.78),
            'mrp_paise' => $batchMrp, 'batch_no' => $batchNo(), 'expiry_month' => null,
            'expiry_date' => Carbon::createFromFormat('!Y-m', $month($monthsAhead))->endOfMonth()->toDateString(),
        ];
        $add = fn (array $lines) => app(AddOpeningStock::class)->handle($this->owner, $product, $lines, 'Opening stock');

        if ($index % 9 === 4) {
            // Stock that arrived long ago and has now expired on the shelf. Entered "in the past"
            // because expired stock can never be added for sale.
            Carbon::setTestNow(now()->subMonths(10));
            $add([$line(mt_rand(2, 6), 9, $mrp)]);
            Carbon::setTestNow();
        }

        $lines = match (true) {
            $index % 7 === 3 => [$line(mt_rand(1, 4), mt_rand(14, 24), $mrp)],                 // low stock
            $index % 5 === 0 => [$line(mt_rand(6, 12), 0, $mrp), $line(mt_rand(15, 30), 18, $mrp)], // one batch expiring this month
            $index % 5 === 1 => [$line(mt_rand(6, 12), 2, $mrp), $line(mt_rand(15, 30), 20, (int) round($mrp * 1.06))], // near expiry + new MRP
            default => [$line(mt_rand(12, 40), mt_rand(8, 26), $mrp)],
        };
        $add($lines);

        if ($index === 0) {
            // A damaged strip set aside so the "not for sale" stock is visible.
            $batch = $product->batches()->orderByDesc('id')->first();
            $pack = $product->load('units')->pricingUnit()->factor_to_base;
            app(AdjustStock::class)->handle($this->owner, $batch, AdjustStock::MARK_UNSELLABLE, $pack, 'sellable', 'Strip damaged by water');
        }
    }

    private function generalGoods(): void
    {
        // name, local name, brand, category, base, pack, size, MRP Rs (0 = loose, no MRP), selling Rs, GST, HSN, barcode, quick, expiry, stock packs, min
        $rows = [
            ['Sugar (loose)', 'साखर', null, 'Groceries & Staples', 'G', 'KG', 1000, 0, 44.00, 'GST 5%', '1701', null, true, false, 50, 10],
            ['Rice Kolam (loose)', 'तांदूळ कोलम', null, 'Groceries & Staples', 'G', 'KG', 1000, 0, 58.00, 'GST 0% (Nil)', '1006', null, true, false, 75, 15],
            ['Toor Dal (loose)', 'तूर डाळ', null, 'Groceries & Staples', 'G', 'KG', 1000, 0, 148.00, 'GST 0% (Nil)', '0713', null, true, false, 30, 8],
            ['Wheat Flour Aashirvaad 5kg', 'गव्हाचे पीठ', 'Aashirvaad', 'Groceries & Staples', 'PKT', null, 1, 285.00, 280.00, 'GST 5%', '1101', '8901725121112', false, true, 12, 4],
            ['Tata Salt 1kg', 'मीठ', 'Tata', 'Groceries & Staples', 'PKT', null, 1, 28.00, 28.00, 'GST 0% (Nil)', '2501', '8904043901015', false, false, 40, 10],
            ['Fortune Sunflower Oil 1L', 'सूर्यफूल तेल', 'Fortune', 'Groceries & Staples', 'PKT', null, 1, 165.00, 158.00, 'GST 5%', '1512', '8906007280013', false, true, 24, 6],
            ['Groundnut Oil (loose)', 'शेंगदाणा तेल', null, 'Groceries & Staples', 'ML', 'L', 1000, 0, 190.00, 'GST 5%', '1508', null, true, false, 15, 5],
            ['Red Label Tea 250g', 'चहा पावडर', 'Brooke Bond', 'Groceries & Staples', 'PKT', null, 1, 150.00, 148.00, 'GST 5%', '0902', '8901030865278', false, true, 18, 5],
            ['Eggs', 'अंडी', null, 'Groceries & Staples', 'PCS', 'DOZ', 12, 0, 84.00, 'GST 0% (Nil)', '0407', null, true, false, 10, 3],
            ['Parle-G Biscuit 50g', 'पार्ले-जी', 'Parle', 'Snacks & Biscuits', 'PCS', null, 1, 5.00, 5.00, 'GST 5%', '1905', '8901719100017', true, true, 144, 48],
            ['Parle-G Biscuit 100g', 'पार्ले-जी', 'Parle', 'Snacks & Biscuits', 'PCS', null, 1, 10.00, 10.00, 'GST 5%', '1905', '8901719100031', false, true, 96, 24],
            ['Good Day Butter 75g', null, 'Britannia', 'Snacks & Biscuits', 'PCS', null, 1, 10.00, 10.00, 'GST 5%', '1905', '8901063092730', false, true, 60, 24],
            ['Haldiram Aloo Bhujia 150g', 'आलू भुजिया', 'Haldiram', 'Snacks & Biscuits', 'PKT', null, 1, 50.00, 50.00, 'GST 5%', '2106', '8904004400052', false, true, 30, 10],
            ['Maggi Noodles 70g', 'मॅगी', 'Nestle', 'Snacks & Biscuits', 'PCS', null, 1, 14.00, 14.00, 'GST 5%', '1902', '8901058000290', true, true, 2, 24],
            ['Lays Classic Salted 26g', null, 'Lays', 'Snacks & Biscuits', 'PCS', null, 1, 10.00, 10.00, 'GST 5%', '2005', '8901491101837', false, true, 48, 12],
            ['Thums Up 750ml', null, 'Coca-Cola', 'Beverages', 'BTL', null, 1, 40.00, 40.00, 'GST 40%', '2202', '8901764012273', false, true, 24, 6],
            ['Bisleri Water 1L', 'पाणी बाटली', 'Bisleri', 'Beverages', 'BTL', null, 1, 20.00, 20.00, 'GST 5%', '2201', '8906017290019', true, true, 60, 12],
            ['Frooti 200ml', null, 'Parle Agro', 'Beverages', 'PCS', null, 1, 10.00, 10.00, 'GST 5%', '2202', '8901764031205', false, true, 0, 12],
            ['Amul Taaza Milk 1L (UHT)', 'दूध', 'Amul', 'Beverages', 'PKT', null, 1, 72.00, 72.00, 'GST 0% (Nil)', '0401', '8901262010016', false, true, 20, 6],
            ['Lifebuoy Soap 100g', 'साबण', 'Lifebuoy', 'Personal Care', 'PCS', null, 1, 35.00, 34.00, 'GST 5%', '3401', '8901030704215', false, false, 48, 12],
            ['Dettol Soap 75g', 'डेटॉल साबण', 'Dettol', 'Personal Care', 'PCS', null, 1, 49.00, 48.00, 'GST 5%', '3401', '8901396393504', false, false, 36, 12],
            ['Clinic Plus Shampoo Sachet', 'शाम्पू पाऊच', 'Clinic Plus', 'Personal Care', 'PCS', 'STRIP', 12, 24.00, 24.00, 'GST 5%', '3305', null, true, false, 30, 5],
            ['Colgate Strong Teeth 100g', 'टूथपेस्ट', 'Colgate', 'Personal Care', 'TUBE', null, 1, 68.00, 66.00, 'GST 5%', '3306', '8901314010322', false, true, 24, 6],
            ['Parachute Coconut Oil 100ml', 'खोबरेल तेल', 'Parachute', 'Personal Care', 'BTL', null, 1, 48.00, 47.00, 'GST 5%', '3305', '8901088136327', false, true, 3, 6],
            ['Gillette Guard Razor', null, 'Gillette', 'Personal Care', 'PCS', null, 1, 25.00, 25.00, 'GST 18%', '8212', '4902430494441', false, false, 20, 5],
            ['Whisper Choice 7 Pads', 'सॅनिटरी पॅड', 'Whisper', 'Health & Hygiene', 'PKT', null, 1, 42.00, 42.00, 'GST 0% (Nil)', '9619', '4902430410144', false, false, 25, 8],
            ['Dettol Antiseptic Liquid 125ml', 'डेटॉल', 'Dettol', 'Health & Hygiene', 'BTL', null, 1, 86.00, 85.00, 'GST 5%', '3808', '8901396310105', false, true, 15, 4],
            ['Cotton Roll 50g', 'कापूस', null, 'Health & Hygiene', 'PKT', null, 1, 45.00, 42.00, 'GST 5%', '3005', null, false, false, 20, 5],
            ['Crepe Bandage 6cm', 'बँडेज', null, 'Health & Hygiene', 'PCS', null, 1, 110.00, 100.00, 'GST 5%', '3005', null, false, false, 10, 3],
            ['Digital Thermometer', 'थर्मामीटर', 'Dr. Morepen', 'Health & Hygiene', 'PCS', null, 1, 250.00, 220.00, 'GST 5%', '9025', '8906010110017', false, false, 6, 2],
            ['Pampers Pants M 8 pcs', 'डायपर', 'Pampers', 'Baby Products', 'PKT', null, 1, 120.00, 118.00, 'GST 5%', '9619', '4902430901338', false, false, 14, 4],
            ['Cerelac Wheat 300g', null, 'Nestle', 'Baby Products', 'PKT', null, 1, 265.00, 262.00, 'GST 5%', '1901', '8901058812343', false, true, 8, 3],
            ['Surf Excel Easy Wash 1kg', 'कपडे धुण्याची पावडर', 'Surf Excel', 'Household & Cleaning', 'PKT', null, 1, 150.00, 146.00, 'GST 18%', '3402', '8901030763311', false, false, 18, 5],
            ['Vim Bar 200g', 'भांडी साबण', 'Vim', 'Household & Cleaning', 'PCS', null, 1, 20.00, 20.00, 'GST 18%', '3401', '8901030717574', false, false, 40, 12],
            ['Harpic 500ml', null, 'Harpic', 'Household & Cleaning', 'BTL', null, 1, 110.00, 106.00, 'GST 18%', '3808', '8901396151104', false, false, 12, 4],
            ['Good Knight Refill 45ml', null, 'Good Knight', 'Household & Cleaning', 'PCS', null, 1, 85.00, 82.00, 'GST 18%', '3808', '8901157001013', false, false, 16, 4],
            ['Match Box', 'काडेपेटी', null, 'Household & Cleaning', 'PCS', 'PKT', 10, 20.00, 20.00, 'GST 5%', '3605', null, true, false, 20, 5],
            ['Classmate Notebook 172 pages', 'वही', 'Classmate', 'Stationery', 'PCS', null, 1, 60.00, 58.00, 'GST 0% (Nil)', '4820', '8902519003004', false, false, 30, 10],
            ['Cello Ball Pen Blue', 'पेन', 'Cello', 'Stationery', 'PCS', 'BOX', 20, 200.00, 200.00, 'GST 18%', '9608', null, false, false, 5, 1],
            ['Agarbatti Cycle 3-in-1', 'अगरबत्ती', 'Cycle', 'Other', 'PKT', null, 1, 60.00, 58.00, 'GST 5%', '3307', '8900000100018', false, false, 22, 6],
        ];

        foreach ($rows as [$name, $local, $brand, $category, $base, $pack, $size, $mrp, $selling, $tax, $hsn, $barcode, $quick, $expiry, $stock, $min]) {
            $product = app(SaveProduct::class)->handle($this->owner, [
                'type' => Product::TYPE_GENERAL,
                'name' => $name,
                'name_local' => $local,
                'brand' => $brand,
                'category_id' => $this->categories[$category],
                'hsn_code' => $hsn,
                'tax_rate_id' => $this->taxes[$tax],
                'base_unit_id' => $this->units[$base],
                'pack_unit_id' => $pack ? $this->units[$pack] : null,
                'pack_size' => $pack ? $size : null,
                'allow_loose_sale' => $pack !== null,
                'mrp_paise' => $this->paise($mrp),
                'selling_price_paise' => $this->paise($selling),
                'min_stock' => $min,
                'tracks_expiry' => $expiry,
                'is_quick_item' => $quick,
                'barcodes' => $barcode ? [['barcode' => $barcode, 'unit' => 'pack']] : [],
                'generate_barcode' => $barcode === null && ! $quick,
                // The two Parle-G sizes share a local name; that is not a duplicate.
                'confirm_duplicate' => true,
            ]);

            if ($stock > 0) {
                app(AddOpeningStock::class)->handle($this->owner, $product, [[
                    'qty' => $stock,
                    'unit' => 'pack',
                    'cost_paise' => (int) round($this->paise($selling) * 0.88),
                    'expiry_date' => $expiry ? now()->addMonths(mt_rand(3, 10))->endOfMonth()->toDateString() : null,
                ]], 'Opening stock');
            }
        }
    }

    /** A morning of trade: cash, UPI, split, udhar and prescription bills, and one udhar repayment. */
    private function customersAndBills(): void
    {
        $storeId = $this->owner->store_id;
        $customers = [];
        foreach ([
            ['Ramesh Patil', '9800000010', 'Wadgaon', 200000], ['Sunita Jadhav', '9700000022', 'Wadgaon', 0],
            ['Ganesh More', '9600000033', 'Kanhe', 100000], ['Lata Shinde', null, 'Wadgaon', 0],
            ['Vitthal Pawar', '9500000044', 'Takwe', 0], ['Anganwadi Centre No. 3', '9400000055', 'Wadgaon', 500000],
        ] as [$name, $mobile, $village, $limit]) {
            $customer = Customer::create([
                'ulid' => (string) Str::ulid(), 'store_id' => $storeId, 'name' => $name, 'mobile' => $mobile,
                'village' => $village, 'search_text' => Product::buildSearchText($name, $mobile, $village),
            ]);
            $customer->forceFill(['credit_limit_paise' => $limit])->save();
            $customers[$name] = $customer;
        }

        $cashier = User::where('username', 'cashier')->sole();
        $pharmacist = User::where('username', 'pharmacist')->sole();
        $id = fn (string $name) => Product::where('store_id', $storeId)->where('name', $name)->value('id');
        $line = fn (string $name, int $qty, string $unit = 'pack') => ['product_id' => $id($name), 'qty' => $qty, 'unit' => $unit];
        $rx = ['prescriber_name' => 'Dr. A. Deshmukh', 'prescriber_address' => 'Primary Health Centre, Wadgaon'];

        // [who bills, customer, lines, payments (method => rupees; the rest becomes udhar), patient for prescription bills]
        $bills = [
            [$cashier, null, [$line('Crocin 500 Tablet', 1), $line('Parle-G Biscuit 50g', 4)], ['cash' => 'all'], null],
            [$cashier, null, [$line('Sugar (loose)', 500, 'base'), $line('Eggs', 6, 'base'), $line('Tata Salt 1kg', 1)], ['upi' => 'all'], null],
            [$cashier, 'Ramesh Patil', [$line('Fortune Sunflower Oil 1L', 2), $line('Red Label Tea 250g', 1), $line('Surf Excel Easy Wash 1kg', 1)], ['cash' => 200], null],
            [$pharmacist, 'Sunita Jadhav', [$line('Mox 500 Capsule', 1), $line('Pan 40 Tablet', 1), $line('Electral Powder 21.8g', 3)], ['cash' => 'all'], 'Sunita Jadhav'],
            [$cashier, null, [$line('Thums Up 750ml', 2), $line('Lays Classic Salted 26g', 3)], ['cash' => 'all'], null],
            [$cashier, 'Ganesh More', [$line('Wheat Flour Aashirvaad 5kg', 1), $line('Toor Dal (loose)', 2), $line('Dolo 650 Tablet', 5, 'base')], [], null],
            [$pharmacist, null, [$line('Azithral 500 Tablet', 1), $line('Cetzine 10 Tablet', 1)], ['cash' => 100, 'upi' => 'rest'], 'Baby of Lata Shinde'],
            [$cashier, 'Anganwadi Centre No. 3', [$line('Electral Powder 21.8g', 10), $line('Dettol Antiseptic Liquid 125ml', 2), $line('Cotton Roll 50g', 4)], [], null],
            [$cashier, null, [$line('Classmate Notebook 172 pages', 3), $line('Lifebuoy Soap 100g', 2), $line('Colgate Strong Teeth 100g', 1)], ['card' => 'all'], null],
            [$cashier, 'Vitthal Pawar', [$line('Groundnut Oil (loose)', 500, 'base'), $line('Good Day Butter 75g', 2)], ['cash' => 50], null],
        ];

        $pricer = app(SalePricer::class);

        // The fortnight before today: a few small walk-in bills each day, so the dashboard chart,
        // "than yesterday" and the reports have something to show. Dated by moving the clock.
        $everyday = [
            ['Parle-G Biscuit 50g', 4], ['Bisleri Water 1L', 2], ['Good Day Butter 75g', 1], ['Lays Classic Salted 26g', 1],
            ['Parle-G Biscuit 100g', 3], ['Haldiram Aloo Bhujia 150g', 1], ['Vicks Action 500 Tablet', 1], ['Becosules Capsule', 1],
        ];
        $turn = 0;
        for ($daysAgo = self::PAST_DAYS; $daysAgo >= 1; $daysAgo--) {
            Carbon::setTestNow(now(config('shop.timezone'))->subDays($daysAgo)->setTime(11, 0));
            Auth::setUser($cashier);
            for ($n = 0; $n < 3 + $daysAgo % 3; $n++) {
                $lines = [$line(...$everyday[$turn % 8]), $line(...$everyday[($turn + 3) % 8])];
                app(CompleteSale::class)->handle($cashier, [
                    'ulid' => (string) Str::ulid(),
                    'customer_id' => null,
                    'lines' => $lines,
                    'payments' => [['method' => $turn % 3 === 0 ? 'upi' : 'cash', 'amount_paise' => $pricer->price($this->owner->store, $lines)['totals']['total_paise']]],
                    'prescription' => null,
                ]);
                $turn++;
            }
            Carbon::setTestNow();
        }

        foreach ($bills as [$user, $customerName, $lines, $pay, $patient]) {
            Auth::setUser($user);
            $total = $pricer->price($this->owner->store, $lines)['totals']['total_paise'];

            $payments = [];
            $left = $total;
            foreach ($pay as $method => $amount) {
                $paise = is_int($amount) ? min($amount * 100, $left) : $left;
                if ($paise > 0) {
                    $payments[] = ['method' => $method, 'amount_paise' => $paise];
                    $left -= $paise;
                }
            }

            app(CompleteSale::class)->handle($user, [
                'ulid' => (string) Str::ulid(),
                'customer_id' => $customerName ? $customers[$customerName]->id : null,
                'lines' => $lines,
                'payments' => $payments,
                'prescription' => $patient ? $rx + ['patient_name' => $patient] : null,
            ]);
        }

        // Ramesh pays Rs 300 of his udhar by UPI.
        app(ReceiveCustomerPayment::class)->handle($cashier, $customers['Ramesh Patil']->refresh(), 30000, 'upi', 'UPI-DEMO-1');
        Auth::setUser($this->owner);
    }

    private function paise(float $rupees): int
    {
        return (int) round($rupees * 100);
    }
}
