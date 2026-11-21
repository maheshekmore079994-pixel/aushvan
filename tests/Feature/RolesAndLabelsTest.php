<?php

namespace Tests\Feature;

use App\Actions\SaveProduct;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_changes_what_a_role_may_do_and_it_takes_effect(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);
        $cashier = $this->userWithRole(RoleName::Cashier);
        $this->actingAs($cashier)->getJson('/api/v1/reports/bills')->assertForbidden();

        $list = $this->actingAs($owner)->getJson('/api/v1/roles')->assertOk()->json();
        $this->assertCount(4, $list['data']);
        $this->assertSame(Permission::all(), $list['permissions']);
        $cashierRole = collect($list['data'])->firstWhere('name', 'cashier');
        $this->assertTrue($cashierRole['builtin']);
        $this->assertSame(1, $cashierRole['users']);

        $this->putJson("/api/v1/roles/{$cashierRole['id']}", ['permissions' => [...$cashierRole['permissions'], 'reports.view']])
            ->assertOk()->assertJsonPath('name', 'cashier');

        $this->actingAs($cashier->refresh())->getJson('/api/v1/reports/bills')->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'roles.updated')->count());
    }

    public function test_owner_role_is_fixed_and_only_owners_manage_roles(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);
        $manager = $this->userWithRole(RoleName::StoreManager);
        $manager->givePermissionTo(Permission::USERS_MANAGE);

        $this->actingAs($manager)->getJson('/api/v1/roles')->assertForbidden();
        $this->postJson('/api/v1/roles', ['name' => 'Boss', 'permissions' => Permission::all()])->assertForbidden();
        $this->putJson('/api/v1/roles/'.Role::findByName('store_manager', 'web')->id, ['permissions' => Permission::all()])->assertForbidden();

        $this->actingAs($owner)->putJson('/api/v1/roles/'.Role::findByName('owner', 'web')->id, ['permissions' => []])->assertStatus(409);
        $this->deleteJson('/api/v1/roles/'.Role::findByName('cashier', 'web')->id)->assertStatus(409)->assertJsonPath('code', 'role_builtin');
        $this->putJson('/api/v1/roles/'.Role::findByName('cashier', 'web')->id, ['permissions' => ['not.a.permission']])->assertUnprocessable();
    }

    public function test_custom_role_can_be_made_given_to_a_user_and_deleted_when_unused(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);
        $this->actingAs($owner);

        $role = $this->postJson('/api/v1/roles', ['name' => 'मदतनीस', 'permissions' => ['sales.create']])->assertCreated()->json();
        $this->assertFalse($role['builtin']);
        $this->postJson('/api/v1/roles', ['name' => 'मदतनीस', 'permissions' => []])->assertUnprocessable();
        $this->postJson('/api/v1/roles', ['name' => 'cashier', 'permissions' => []])->assertUnprocessable();

        $user = $this->postJson('/api/v1/users', [
            'name' => 'Sunil', 'username' => 'sunil', 'password' => 'helper-pass-1', 'locale' => 'hi',
            'is_pharmacist' => false, 'is_active' => true, 'roles' => ['मदतनीस'],
        ])->assertCreated()->json();
        $this->assertSame(['मदतनीस'], $user['roles']);
        $this->postJson('/api/v1/users', [
            'name' => 'X', 'username' => 'xuser', 'password' => 'helper-pass-1', 'locale' => 'en',
            'is_pharmacist' => false, 'is_active' => true, 'roles' => ['nobody'],
        ])->assertUnprocessable();

        $this->deleteJson("/api/v1/roles/{$role['id']}")->assertStatus(409)->assertJsonPath('code', 'role_in_use');

        $this->putJson("/api/v1/users/{$user['id']}", [
            'name' => 'Sunil', 'username' => 'sunil', 'locale' => 'hi',
            'is_pharmacist' => false, 'is_active' => true, 'roles' => ['cashier'],
        ])->assertOk();
        $this->putJson("/api/v1/roles/{$role['id']}", ['name' => 'Helper', 'permissions' => []])->assertOk()->assertJsonPath('name', 'Helper');
        $this->deleteJson("/api/v1/roles/{$role['id']}")->assertNoContent();
        $this->assertNull(Role::where('name', 'Helper')->first());
    }

    public function test_label_data_and_one_click_shop_barcode(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);
        $cashier = $this->userWithRole(RoleName::Cashier);
        $save = fn (string $name, array $barcodes) => app(SaveProduct::class)->handle($owner, [
            'type' => 'general', 'name' => $name, 'tax_rate_id' => TaxRate::where('name', 'GST 5%')->value('id'),
            'base_unit_id' => Unit::where('code', 'PCS')->value('id'), 'mrp_paise' => 4500, 'selling_price_paise' => 4200,
            'barcodes' => $barcodes,
        ]);
        $soap = $save('Lifebuoy Soap', [['barcode' => '8901030000017']]);
        $loose = $save('Loose Jaggery', []);

        $this->actingAs($cashier)->postJson("/api/v1/products/{$loose->id}/barcode")->assertForbidden();

        $this->actingAs($owner);
        $rows = $this->getJson("/api/v1/products/labels?ids[]={$soap->id}&ids[]={$loose->id}")->assertOk()->json('data');
        $this->assertSame('8901030000017', $rows[0]['barcode']);
        $this->assertSame(4500, $rows[0]['mrp_paise']);
        $this->assertNull($rows[1]['barcode']);

        $made = $this->postJson("/api/v1/products/{$loose->id}/barcode")->assertOk()->json('barcode');
        $this->assertMatchesRegularExpression('/^20\d{11}$/', $made);
        // Asking again keeps the same barcode; scanning it finds the product.
        $this->postJson("/api/v1/products/{$loose->id}/barcode")->assertJsonPath('barcode', $made);
        $this->getJson("/api/v1/products?search={$made}")->assertJsonPath('data.0.id', $loose->id)->assertJsonPath('matched_barcode', true);
    }
}
