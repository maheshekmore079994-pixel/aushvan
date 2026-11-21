<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Ravi Jadhav',
            'username' => 'Ravi',
            'mobile' => '9800000001',
            'password' => 'counter-pass-1',
            'pin' => '4321',
            'locale' => 'mr',
            'is_pharmacist' => false,
            'pharmacist_reg_no' => null,
            'is_active' => true,
            'roles' => ['cashier'],
        ];
    }

    public function test_owner_creates_a_cashier(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);

        $this->actingAs($owner)->postJson('/api/v1/users', $this->payload())
            ->assertCreated()
            ->assertJsonPath('username', 'ravi')
            ->assertJsonPath('roles', ['cashier'])
            ->assertJsonPath('has_pin', true)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('pin');

        $ravi = User::where('username', 'ravi')->sole();
        $this->assertTrue(Hash::check('counter-pass-1', $ravi->password));
        $this->assertTrue(Hash::check('4321', $ravi->pin));
        $this->assertSame($owner->store_id, $ravi->store_id);
        $this->assertSame(1, AuditLog::where('action', 'users.created')->count());
        $this->assertStringNotContainsString('counter-pass-1', AuditLog::where('action', 'users.created')->sole()->getRawOriginal('new_values'));
    }

    public function test_only_users_with_permission_can_manage_users(): void
    {
        foreach ([RoleName::Cashier, RoleName::Pharmacist, RoleName::StoreManager] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->getJson('/api/v1/users')->assertForbidden();
            $this->actingAs($user)->postJson('/api/v1/users', $this->payload())->assertForbidden();
            $this->actingAs($user)->putJson("/api/v1/users/{$user->id}", $this->payload(['roles' => ['owner']]))->assertForbidden();
        }

        $this->assertSame(0, User::where('username', 'ravi')->count());
    }

    public function test_guest_cannot_list_users(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
    }

    public function test_username_must_be_unique_and_pharmacist_needs_registration_number(): void
    {
        $owner = $this->userWithRole(RoleName::Owner, ['username' => 'asha']);
        $this->actingAs($owner);

        $this->postJson('/api/v1/users', $this->payload(['username' => 'ASHA']))
            ->assertStatus(422)->assertJsonValidationErrors('username');
        $this->postJson('/api/v1/users', $this->payload(['is_pharmacist' => true, 'roles' => ['pharmacist']]))
            ->assertStatus(422)->assertJsonValidationErrors('pharmacist_reg_no');
        $this->postJson('/api/v1/users', $this->payload(['roles' => ['superuser']]))
            ->assertStatus(422)->assertJsonValidationErrors('roles.0');
        $this->postJson('/api/v1/users', $this->payload(['password' => 'short']))
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_update_keeps_password_when_left_blank(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);
        $ravi = $this->userWithRole(RoleName::Cashier, ['username' => 'ravi']);

        $this->actingAs($owner)->putJson("/api/v1/users/{$ravi->id}", $this->payload([
            'password' => null, 'pin' => null, 'name' => 'Ravi J.', 'roles' => ['cashier', 'store_manager'],
        ]))->assertOk()->assertJsonPath('name', 'Ravi J.')->assertJsonPath('roles', ['cashier', 'store_manager']);

        $this->assertTrue(Hash::check('password', $ravi->refresh()->password));
        $this->assertTrue($ravi->can('purchases.manage'));
    }

    public function test_last_active_owner_cannot_be_demoted_or_switched_off(): void
    {
        $owner = $this->userWithRole(RoleName::Owner, ['username' => 'asha']);
        $base = ['username' => 'asha', 'password' => null, 'pin' => null];

        $this->actingAs($owner)->putJson("/api/v1/users/{$owner->id}", $this->payload($base + ['roles' => ['cashier']]))
            ->assertStatus(409)->assertJsonPath('code', 'last_owner');
        $this->actingAs($owner)->putJson("/api/v1/users/{$owner->id}", $this->payload($base + ['roles' => ['owner'], 'is_active' => false]))
            ->assertStatus(409);

        $this->assertTrue($owner->refresh()->isOwner());
        $this->assertTrue($owner->is_active);

        // With a second owner in place it is allowed.
        $this->userWithRole(RoleName::Owner);
        $this->actingAs($owner)->putJson("/api/v1/users/{$owner->id}", $this->payload($base + ['roles' => ['cashier']]))->assertOk();
    }

    public function test_repeated_create_request_does_not_create_two_users(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);
        $headers = ['Idempotency-Key' => '01JCREATEUSER0000000000001'];

        $first = $this->actingAs($owner)->postJson('/api/v1/users', $this->payload(), $headers)->assertCreated();
        $second = $this->actingAs($owner)->postJson('/api/v1/users', $this->payload(), $headers)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, User::where('username', 'ravi')->count());
    }

    public function test_failed_request_can_be_corrected_and_resent_with_the_same_key(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);
        $headers = ['Idempotency-Key' => '01JCREATEUSER0000000000002'];

        $this->actingAs($owner)->postJson('/api/v1/users', $this->payload(['password' => 'short']), $headers)->assertStatus(422);
        $this->actingAs($owner)->postJson('/api/v1/users', $this->payload(), $headers)
            ->assertCreated()->assertHeaderMissing('Idempotency-Replayed');
    }

    public function test_request_still_in_progress_is_not_run_twice(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);
        \DB::table('idempotency_keys')->insert([
            'user_id' => $owner->id, 'key' => 'in-flight', 'method' => 'POST', 'path' => 'api/v1/users', 'created_at' => now(),
        ]);

        $this->actingAs($owner)->postJson('/api/v1/users', $this->payload(), ['Idempotency-Key' => 'in-flight'])
            ->assertStatus(409)->assertJsonPath('code', 'request_in_progress');

        $this->assertSame(0, User::where('username', 'ravi')->count());
    }
}
