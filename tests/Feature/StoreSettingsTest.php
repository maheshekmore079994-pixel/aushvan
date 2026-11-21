<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Shree Medical & General Stores',
            'address' => 'Main Road',
            'village' => 'Wadgaon',
            'taluka' => 'Maval',
            'district' => 'Pune',
            'state_code' => '27',
            'pincode' => '412106',
            'phone' => '9800000000',
            'gst_mode' => 'regular',
            'gstin' => '27abcde1234f1z5',
            'settings' => [
                'drug_licence_20' => 'MH-PZ4-123456',
                'drug_licence_21' => 'MH-PZ4-123457',
                'licence_retention_due' => '2030-03-31',
                'counter_prefix' => 'A',
            ],
        ], $overrides);
    }

    public function test_owner_updates_shop_details_and_change_is_audited(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);

        $this->actingAs($owner)->putJson('/api/v1/store', $this->payload())
            ->assertOk()
            ->assertJsonPath('gstin', '27ABCDE1234F1Z5')
            ->assertJsonPath('gst_mode', 'regular')
            ->assertJsonPath('settings.drug_licence_20', 'MH-PZ4-123456')
            ->assertJsonPath('settings.drug_licence_20f', null);

        $this->actingAs($owner)->getJson('/api/v1/store')
            ->assertOk()->assertJsonPath('settings.counter_prefix', 'A');

        $log = AuditLog::where('action', 'settings.shop_updated')->sole();
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame('regular', $log->new_values['gst_mode']);
        $this->assertSame('unregistered', $log->old_values['gst_mode']);
    }

    public function test_saving_without_changes_writes_no_audit_entry(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);

        $this->actingAs($owner)->putJson('/api/v1/store', $this->payload())->assertOk();
        $this->actingAs($owner)->putJson('/api/v1/store', $this->payload())->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'settings.shop_updated')->count());
    }

    public function test_cashier_can_read_but_not_change_shop_details(): void
    {
        $cashier = $this->userWithRole(RoleName::Cashier);

        $this->actingAs($cashier)->getJson('/api/v1/store')->assertOk();
        $this->actingAs($cashier)->putJson('/api/v1/store', $this->payload())->assertForbidden();
    }

    public function test_guest_cannot_read_shop_details(): void
    {
        $this->getJson('/api/v1/store')->assertUnauthorized();
    }

    public function test_gstin_rules(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);
        $this->actingAs($owner);

        // Registered shop must give a GSTIN
        $this->putJson('/api/v1/store', $this->payload(['gstin' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('gstin');
        // Wrong shape
        $this->putJson('/api/v1/store', $this->payload(['gstin' => '27ABCDE1234']))
            ->assertStatus(422)->assertJsonValidationErrors('gstin');
        // State code mismatch (29 = Karnataka)
        $this->putJson('/api/v1/store', $this->payload(['gstin' => '29ABCDE1234F1Z5']))
            ->assertStatus(422)->assertJsonValidationErrors('gstin');
        // Unregistered shop needs none
        $this->putJson('/api/v1/store', $this->payload(['gst_mode' => 'unregistered', 'gstin' => null]))->assertOk();
        // Unknown mode
        $this->putJson('/api/v1/store', $this->payload(['gst_mode' => 'something']))
            ->assertStatus(422)->assertJsonValidationErrors('gst_mode');
    }

    public function test_unknown_setting_keys_are_ignored(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);

        $this->actingAs($owner)
            ->putJson('/api/v1/store', $this->payload(['settings' => ['allow_negative_stock' => true]]))
            ->assertOk()
            ->assertJsonMissingPath('settings.allow_negative_stock');

        $this->assertDatabaseMissing('settings', ['key' => 'allow_negative_stock']);
    }
}
