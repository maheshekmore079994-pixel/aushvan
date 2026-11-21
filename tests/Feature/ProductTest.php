<?php

namespace Tests\Feature;

use App\Domain\Catalog\Barcode;
use App\Enums\RoleName;
use App\Models\Category;
use App\Models\DrugSchedule;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->userWithRole(RoleName::Owner);
    }

    private function unit(string $code): int
    {
        return Unit::where('code', $code)->value('id');
    }

    private function medicine(array $overrides = []): array
    {
        return array_replace_recursive([
            'type' => 'medical',
            'name' => 'Crocin 500 Tablet',
            'category_id' => Category::where('name', 'Tablets & Capsules')->value('id'),
            'manufacturer' => 'GSK',
            'hsn_code' => '3004',
            'tax_rate_id' => TaxRate::where('name', 'GST 5%')->value('id'),
            'base_unit_id' => $this->unit('TAB'),
            'pack_unit_id' => $this->unit('STRIP'),
            'pack_size' => 15,
            'allow_loose_sale' => true,
            'mrp_paise' => 3000,
            'selling_price_paise' => 3000,
            'min_stock' => 5,
            'rack_location' => 'A1',
            'medicine' => [
                'generic_name' => 'Paracetamol',
                'strength' => '500 mg',
                'dosage_form' => 'tablet',
                'drug_schedule_id' => DrugSchedule::where('code', 'NONE')->value('id'),
            ],
        ], $overrides);
    }

    private function general(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'general',
            'name' => 'Parle-G Biscuit 50g',
            'name_local' => 'पार्ले-जी',
            'brand' => 'Parle',
            'tax_rate_id' => TaxRate::where('name', 'GST 5%')->value('id'),
            'base_unit_id' => $this->unit('PCS'),
            'mrp_paise' => 500,
            'selling_price_paise' => 500,
            'barcodes' => [['barcode' => '8901719100017']],
        ];
    }

    public function test_creates_a_medicine_with_units_and_forced_batch_tracking(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/v1/products', $this->medicine())
            ->assertCreated()
            ->assertJsonPath('sku', fn ($sku) => (bool) preg_match('/^M\d{5}$/', $sku))
            ->assertJsonPath('base_unit', 'TAB')
            ->assertJsonPath('pricing_unit', 'STRIP')
            ->assertJsonPath('pack_size', 15)
            ->assertJsonPath('min_stock_qty', 75) // 5 strips kept as 75 tablets
            ->assertJsonPath('tracks_batch', true)
            ->assertJsonPath('tracks_expiry', true)
            ->assertJsonPath('schedule', 'NONE')
            ->assertJsonPath('manufacturer', 'GSK')
            ->assertJsonPath('stock_status', 'out');

        $product = Product::findOrFail($response->json('id'));
        $this->assertStringContainsString('paracetamol', $product->search_text);
        $this->assertSame(2, DB::table('product_price_history')->where('product_id', $product->id)->count());
    }

    public function test_general_product_without_pack_unit_is_priced_per_piece(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/products', $this->general())
            ->assertCreated()
            ->assertJsonPath('sku', fn ($sku) => (bool) preg_match('/^G\d{5}$/', $sku))
            ->assertJsonPath('pricing_unit', 'PCS')
            ->assertJsonPath('pack_size', 1)
            ->assertJsonPath('tracks_batch', false)
            ->assertJsonPath('barcodes.0.barcode', '8901719100017');
    }

    public function test_validation_rules(): void
    {
        $this->actingAs($this->owner);

        $this->postJson('/api/v1/products', $this->medicine(['selling_price_paise' => 3100]))
            ->assertStatus(422)->assertJsonValidationErrors('selling_price_paise'); // above MRP
        $this->postJson('/api/v1/products', $this->medicine(['pack_size' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('pack_size');
        $this->postJson('/api/v1/products', $this->medicine(['pack_unit_id' => $this->unit('TAB')]))
            ->assertStatus(422)->assertJsonValidationErrors('pack_unit_id');
        $this->postJson('/api/v1/products', $this->medicine(['hsn_code' => 'ABC']))
            ->assertStatus(422)->assertJsonValidationErrors('hsn_code');
        $this->postJson('/api/v1/products', $this->medicine(['tax_rate_id' => 9999]))
            ->assertStatus(422)->assertJsonValidationErrors('tax_rate_id');

        // Schedule X is switched off until the shop enters its licence.
        $x = DrugSchedule::where('code', 'X')->value('id');
        $this->postJson('/api/v1/products', $this->medicine(['medicine' => ['drug_schedule_id' => $x]]))
            ->assertStatus(422)->assertJsonValidationErrors('medicine.drug_schedule_id');

        $this->assertSame(0, Product::count());
    }

    /** Scenario 15: duplicate product. */
    public function test_duplicate_name_is_caught_and_can_be_confirmed(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/products', $this->medicine())->assertCreated();

        $this->postJson('/api/v1/products', $this->medicine(['name' => 'crocin  500 tablet.']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'possible_duplicate')
            ->assertJsonPath('duplicates.0.name', 'Crocin 500 Tablet');
        $this->assertSame(1, Product::count());

        // A different size is a different product.
        $this->postJson('/api/v1/products', $this->medicine(['name' => 'Crocin 650 Tablet']))->assertCreated();

        // The operator may insist.
        $this->postJson('/api/v1/products', $this->medicine(['confirm_duplicate' => true]))->assertCreated();
        $this->assertSame(3, Product::count());
    }

    public function test_similar_names_are_suggested_while_typing(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/products', $this->medicine())->assertCreated();

        $this->getJson('/api/v1/products/similar?name=crocin')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.exact', false);
        $this->getJson('/api/v1/products/similar?name=Crocin 500 tablet')->assertJsonPath('data.0.exact', true);
        $this->getJson('/api/v1/products/similar?name=dolo')->assertJsonCount(0, 'data');
    }

    public function test_barcode_cannot_be_used_by_two_products(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/products', $this->general())->assertCreated();

        $this->postJson('/api/v1/products', $this->general(['name' => 'Parle-G Biscuit 100g']))
            ->assertStatus(422)->assertJsonValidationErrors('barcodes.0.barcode');
    }

    public function test_shop_barcode_can_be_generated_for_a_product_without_one(): void
    {
        $id = $this->actingAs($this->owner)
            ->postJson('/api/v1/products', $this->general(['barcodes' => [], 'generate_barcode' => true]))
            ->assertCreated()
            ->assertJsonPath('barcodes.0.is_internal', true)
            ->json('id');

        $barcode = DB::table('product_barcodes')->where('product_id', $id)->value('barcode');
        $this->assertSame(Barcode::internal($id), $barcode);
        $this->assertSame(13, strlen($barcode));
        $this->assertSame((int) $barcode[12], Barcode::checkDigit(substr($barcode, 0, 12)));
        // Known EAN-13: 4006381333931
        $this->assertSame(1, Barcode::checkDigit('400638133393'));
    }

    public function test_search_by_name_generic_local_name_and_barcode(): void
    {
        $this->actingAs($this->owner);
        $this->postJson('/api/v1/products', $this->medicine())->assertCreated();
        $this->postJson('/api/v1/products', $this->general())->assertCreated();

        $names = fn (string $query) => $this->getJson('/api/v1/products?'.$query)->assertOk()->json('data.*.name');

        $this->assertSame(['Crocin 500 Tablet'], $names('search=croc'));
        $this->assertSame(['Crocin 500 Tablet'], $names('search=PARACET')); // generic name
        $this->assertSame(['Crocin 500 Tablet'], $names('search=tab 500')); // words in any order
        $this->assertSame(['Parle-G Biscuit 50g'], $names('search='.urlencode('पार्ले'))); // Marathi name
        $this->assertSame(['Parle-G Biscuit 50g'], $names('search=8901719100017')); // barcode
        $this->assertSame(['Parle-G Biscuit 50g'], $names('type=general'));
        $this->assertSame([], $names('search=100%25')); // % is not a wildcard
        $this->assertCount(2, $names(''));
        $this->getJson('/api/v1/products?search=8901719100017')->assertJsonPath('matched_barcode', true);
    }

    public function test_update_changes_price_with_history_and_rejects_stale_edits(): void
    {
        $this->actingAs($this->owner);
        $id = $this->postJson('/api/v1/products', $this->medicine())->json('id');

        $this->putJson("/api/v1/products/{$id}", $this->medicine(['mrp_paise' => 3200, 'selling_price_paise' => 3100, 'version' => 1]))
            ->assertOk()->assertJsonPath('selling_price_paise', 3100)->assertJsonPath('version', 2);

        $history = DB::table('product_price_history')->where('product_id', $id)->where('source', 'product_edit')->get();
        $this->assertCount(2, $history);
        $this->assertSame(3000, (int) $history->firstWhere('field', 'mrp')->old_paise);

        // Someone still looking at version 1 cannot overwrite the newer save.
        $this->putJson("/api/v1/products/{$id}", $this->medicine(['mrp_paise' => 2900, 'selling_price_paise' => 2900, 'version' => 1]))
            ->assertStatus(409)->assertJsonPath('code', 'stale_version');
        $this->assertSame(3200, Product::find($id)->mrp_paise);
    }

    public function test_pack_size_is_locked_once_stock_exists(): void
    {
        $this->actingAs($this->owner);
        $id = $this->postJson('/api/v1/products', $this->medicine())->json('id');
        $this->postJson("/api/v1/products/{$id}/opening-stock", [
            'items' => [['qty' => 2, 'cost_paise' => 2300, 'batch_no' => 'B1', 'expiry_month' => now()->addYear()->format('Y-m')]],
        ], ['Idempotency-Key' => 'open-1'])->assertCreated();

        $this->putJson("/api/v1/products/{$id}", $this->medicine(['pack_size' => 10]))
            ->assertStatus(409)->assertJsonPath('code', 'units_locked');
        $this->assertSame(15, Product::find($id)->load('units')->pricingUnit()->factor_to_base);

        // Other fields can still be edited.
        $this->putJson("/api/v1/products/{$id}", $this->medicine(['rack_location' => 'B7']))->assertOk()->assertJsonPath('rack_location', 'B7');
    }

    public function test_permissions_for_saving_products(): void
    {
        $cashier = $this->userWithRole(RoleName::Cashier);
        $pharmacist = $this->userWithRole(RoleName::Pharmacist);
        $manager = $this->userWithRole(RoleName::StoreManager);

        $this->actingAs($cashier)->postJson('/api/v1/products', $this->general())->assertForbidden();
        $this->actingAs($cashier)->postJson('/api/v1/products', $this->medicine())->assertForbidden();
        $this->actingAs($cashier)->getJson('/api/v1/products')->assertOk(); // can look products up

        // A pharmacist manages medicines but not general goods.
        $this->actingAs($pharmacist)->postJson('/api/v1/products', $this->general())->assertForbidden();
        $this->actingAs($pharmacist)->postJson('/api/v1/products', $this->medicine())->assertCreated();

        $this->actingAs($manager)->postJson('/api/v1/products', $this->general())->assertCreated();
    }

    public function test_guests_cannot_reach_products_and_downloads_do_not_crash(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
        $this->getJson('/api/v1/catalog/options')->assertUnauthorized();
        // A browser download request is not JSON; it must be turned away cleanly.
        $this->get('/api/v1/products/export')->assertUnauthorized();
    }

    public function test_options_and_csv_export(): void
    {
        $this->actingAs($this->owner);
        $this->postJson('/api/v1/products', $this->general())->assertCreated();

        $this->getJson('/api/v1/catalog/options')->assertOk()
            ->assertJsonStructure(['units', 'tax_rates', 'drug_schedules', 'categories', 'manufacturers', 'dosage_forms'])
            ->assertJsonMissing(['code' => 'X']); // disabled schedule is not offered

        $csv = $this->get('/api/v1/products/export')->assertOk()->streamedContent();
        $this->assertMatchesRegularExpression('/G\d{5},general,"Parle-G Biscuit 50g",पार्ले-जी/u', $csv); // the SKU number follows the database id, which MySQL does not reset between tests
        $this->assertStringContainsString('5.00', $csv);
    }
}
