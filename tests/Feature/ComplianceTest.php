<?php

namespace Tests\Feature;

use App\Actions\AddOpeningStock;
use App\Actions\SaveProduct;
use App\Enums\RoleName;
use App\Models\Doctor;
use App\Models\DrugSchedule;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\Store;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Phase 6: doctor master on the prescription step, prescription serials, the register reports, the licence reminder. */
class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $pharmacist;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-27 10:00', 'Asia/Kolkata'));
        $this->owner = $this->userWithRole(RoleName::Owner);
        $this->pharmacist = $this->userWithRole(RoleName::Pharmacist, ['is_pharmacist' => true]);
        $this->cashier = $this->userWithRole(RoleName::Cashier);
    }

    private function medicine(string $name, string $schedule): Product
    {
        $product = app(SaveProduct::class)->handle($this->owner, [
            'type' => 'medical', 'name' => $name, 'hsn_code' => '3004', 'tax_rate_id' => TaxRate::where('name', 'GST 5%')->value('id'),
            'base_unit_id' => Unit::where('code', 'TAB')->value('id'), 'pack_unit_id' => Unit::where('code', 'STRIP')->value('id'), 'pack_size' => 10,
            'mrp_paise' => 10000, 'selling_price_paise' => 10000,
            'medicine' => ['generic_name' => 'Test', 'drug_schedule_id' => DrugSchedule::where('code', $schedule)->value('id')],
        ]);
        app(AddOpeningStock::class)->handle($this->owner, $product, [['qty' => 20, 'cost_paise' => 8000, 'batch_no' => 'B9', 'expiry_date' => '2028-01-31']]);

        return $product;
    }

    private function sell(Product $product, array $prescription): TestResponse
    {
        return $this->postJson('/api/v1/sales', [
            'ulid' => (string) Str::ulid(),
            'lines' => [['product_id' => $product->id, 'unit' => 'pack', 'qty' => 1, 'batch_id' => null, 'discount_percent' => 0]],
            'payments' => [['method' => 'cash', 'amount_paise' => 10000]],
            'prescription' => $prescription,
        ]);
    }

    public function test_doctors_are_kept_by_medicine_managers_and_searched_by_sellers(): void
    {
        $this->actingAs($this->cashier);
        $this->postJson('/api/v1/doctors', ['name' => 'Dr. Patil'])->assertForbidden();

        $this->actingAs($this->pharmacist);
        $id = $this->postJson('/api/v1/doctors', ['name' => 'Dr. Anil Patil', 'reg_no' => 'MMC-12345', 'clinic' => 'Patil Clinic', 'address' => 'Station Road, Khadavli', 'phone' => '9822000111'])
            ->assertCreated()->json('id');
        $this->postJson('/api/v1/doctors', ['name' => 'Dr. X', 'phone' => '12'])->assertStatus(422);
        $this->putJson("/api/v1/doctors/{$id}", ['name' => 'Dr. Anil Patil', 'reg_no' => 'MMC-12345', 'clinic' => 'Patil Clinic & Nursing Home'])->assertOk()->assertJsonPath('clinic', 'Patil Clinic & Nursing Home');

        $this->actingAs($this->cashier);
        $this->getJson('/api/v1/doctors?search=pat')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reg_no', 'MMC-12345');
        $this->getJson('/api/v1/doctors?search=mmc-12')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/doctors?search=nobody')->assertOk()->assertJsonCount(0, 'data');

        $other = Store::create(['name' => 'Other']);
        $stranger = User::factory()->create(['store_id' => $other->id]);
        $stranger->assignRole(RoleName::Owner->value);
        $this->actingAs($stranger);
        $this->getJson('/api/v1/doctors?search=pat')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/doctors/{$id}", ['name' => 'Hijack'])->assertNotFound();
    }

    public function test_the_doctor_list_screen_shows_hidden_doctors_to_managers_and_preferences_follow_the_user(): void
    {
        $this->actingAs($this->pharmacist);
        $id = $this->postJson('/api/v1/doctors', ['name' => 'Dr. Old Timer'])->assertCreated()->json('id');
        $this->putJson("/api/v1/doctors/{$id}", ['name' => 'Dr. Old Timer', 'is_active' => false])->assertOk()->assertJsonPath('is_active', false);

        // Hidden from the prescription step, still on the management list; a manager without billing rights may read it.
        $this->getJson('/api/v1/doctors?search=old')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->userWithRole(RoleName::StoreManager));
        $this->getJson('/api/v1/doctors?all=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', false);

        // Screen preferences are saved on the user and come back with the profile, unknown keys dropped.
        $this->putJson('/api/v1/auth/preferences', ['menu_collapsed' => true, 'colour' => 'red'])->assertOk()->assertJsonPath('preferences.menu_collapsed', true);
        $this->assertArrayNotHasKey('colour', $this->getJson('/api/v1/auth/me')->json('preferences'));
        $this->putJson('/api/v1/auth/preferences', ['menu_collapsed' => 'maybe'])->assertStatus(422);
    }

    public function test_a_picked_doctor_fills_the_prescription_record_which_gets_a_serial_number(): void
    {
        $product = $this->medicine('Taxim-O 200', 'H1');
        $this->actingAs($this->pharmacist);
        $doctor = Doctor::create(['store_id' => $this->owner->store_id, 'name' => 'Dr. Meena Joshi', 'reg_no' => 'MMC-777', 'clinic' => 'Joshi Clinic', 'address' => 'Kalyan', 'search_text' => 'dr. meena joshi mmc-777']);

        $bill = $this->sell($product, ['doctor_id' => $doctor->id, 'patient_name' => 'Ramesh Patil', 'patient_address' => 'Khadavli'])->assertCreated();
        $bill->assertJsonPath('prescription.prescriber_name', 'Dr. Meena Joshi')
            ->assertJsonPath('prescription.prescriber_reg_no', 'MMC-777')
            ->assertJsonPath('prescription.prescriber_address', 'Joshi Clinic, Kalyan')
            ->assertJsonPath('prescription.serial_no', 'RX26-27/000001');
        $this->assertSame($doctor->id, Prescription::firstOrFail()->doctor_id);

        // Typed names still work (no doctor on the list), and get the next serial.
        $this->sell($product, ['prescriber_name' => 'Dr. Unknown', 'patient_name' => 'Sunita'])->assertCreated()->assertJsonPath('prescription.serial_no', 'RX26-27/000002');
        // A doctor id that is not on this shop's list is refused, not silently dropped.
        $this->sell($product, ['doctor_id' => 999, 'patient_name' => 'Lata'])->assertStatus(409)->assertJsonPath('code', 'doctor_missing');
        // The record is a snapshot: renaming the doctor later does not rewrite it.
        $doctor->update(['name' => 'Dr. M. Joshi']);
        $this->assertSame('Dr. Meena Joshi', Prescription::firstOrFail()->prescriber_name);
    }

    public function test_the_register_reports_list_prescription_lines_and_hide_them_from_users_without_the_permission(): void
    {
        $h1 = $this->medicine('Taxim-O 200', 'H1');
        $h = $this->medicine('Mox 500', 'H');
        $this->actingAs($this->pharmacist);
        $doctor = Doctor::create(['store_id' => $this->owner->store_id, 'name' => 'Dr. Meena Joshi', 'reg_no' => 'MMC-777', 'search_text' => 'dr. meena joshi']);
        $this->sell($h1, ['doctor_id' => $doctor->id, 'patient_name' => 'Ramesh'])->assertCreated();
        $this->sell($h, ['doctor_id' => $doctor->id, 'patient_name' => 'Sunita'])->assertCreated();

        $this->actingAs($this->owner);
        $all = $this->getJson('/api/v1/reports/prescription_sales?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->assertCount(2, $all->json('rows'));
        $this->assertSame(['RX26-27/000001', 'Ramesh', 'Dr. Meena Joshi', 'Taxim-O 200', 'H1', 'B9'], array_values(collect($all->json('rows.0'))->only(['serial', 'patient', 'doctor', 'product', 'schedule', 'batch_no'])->all()));

        $register = $this->getJson('/api/v1/reports/h1_register?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->assertCount(1, $register->json('rows'));
        $this->assertSame('H1', $register->json('rows.0.schedule'));

        $byDoctor = $this->getJson('/api/v1/reports/doctor_wise?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->assertSame(2, $byDoctor->json('rows.0.prescriptions'));
        $this->assertSame(20000, $byDoctor->json('rows.0.amount'));

        // A manager may see reports but not prescription data: the register comes back empty, with no patient columns.
        $manager = $this->userWithRole(RoleName::StoreManager);
        $this->actingAs($manager);
        $hidden = $this->getJson('/api/v1/reports/h1_register?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->assertSame([], $hidden->json('rows'));
        $this->assertNotContains('patient', array_column($hidden->json('columns'), 'key'));
    }

    public function test_the_dashboard_reminds_the_owner_of_the_licence_fee_within_sixty_days(): void
    {
        $this->actingAs($this->owner);
        $this->assertArrayNotHasKey('licence', $this->getJson('/api/v1/dashboard')->json());

        $this->owner->store->putSetting('licence_retention_due', '2027-01-10');
        $this->owner->unsetRelation('store');
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('licence.days', 44);

        $this->owner->store->putSetting('licence_retention_due', '2026-11-01');
        $this->owner->unsetRelation('store');
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('licence.days', -26);

        $this->owner->store->putSetting('licence_retention_due', '2027-06-01');
        $this->owner->unsetRelation('store');
        $this->assertArrayNotHasKey('licence', $this->getJson('/api/v1/dashboard')->json());

        $this->actingAs($this->cashier);
        $this->assertArrayNotHasKey('licence', $this->getJson('/api/v1/dashboard')->json());
    }
}
