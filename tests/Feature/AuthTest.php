<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private array $setup = [
        'shop_name' => 'Shree Medical & General Stores',
        'village' => 'Wadgaon',
        'name' => 'Asha Patil',
        'username' => 'Asha',
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
        'locale' => 'mr',
    ];

    public function test_fresh_install_asks_for_setup(): void
    {
        $this->getJson('/api/v1/app/status')->assertOk()->assertJson(['needs_setup' => true]);
    }

    public function test_setup_creates_shop_and_owner_and_signs_in(): void
    {
        $this->postJson('/api/v1/setup', $this->setup)
            ->assertCreated()
            ->assertJsonPath('username', 'asha')
            ->assertJsonPath('roles', ['owner'])
            ->assertJsonPath('store.name', 'Shree Medical & General Stores');

        $this->assertSame(1, Store::count());
        $this->assertTrue(User::first()->isOwner());
        $this->assertNotSame('secret-pass-1', User::first()->password);
        $this->getJson('/api/v1/app/status')->assertJson(['needs_setup' => false]);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('locale', 'mr');
    }

    public function test_setup_cannot_run_twice(): void
    {
        $this->postJson('/api/v1/setup', $this->setup)->assertCreated();
        $this->postJson('/api/v1/setup', ['username' => 'intruder'] + $this->setup)->assertStatus(409);

        $this->assertSame(1, User::count());
    }

    public function test_setup_validates_input(): void
    {
        $this->postJson('/api/v1/setup', ['password' => 'short', 'password_confirmation' => 'short'] + $this->setup)
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/setup', ['password_confirmation' => 'different-1'] + $this->setup)
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_login_and_logout(): void
    {
        $user = $this->userWithRole(RoleName::Cashier, ['username' => 'ravi']);

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', ['username' => 'RAVI ', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('roles', ['cashier'])
            ->assertJsonMissingPath('password');

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('id', $user->id);
        $this->assertNotNull($user->refresh()->last_login_at);

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_wrong_password_is_rejected_and_logged(): void
    {
        $this->userWithRole(RoleName::Cashier, ['username' => 'ravi']);

        $this->postJson('/api/v1/auth/login', ['username' => 'ravi', 'password' => 'wrong-password'])
            ->assertStatus(422)->assertJsonValidationErrors('username');

        $this->assertGuest('web');
        $this->assertSame(1, AuditLog::where('action', 'auth.login_failed')->count());
    }

    public function test_switched_off_user_cannot_sign_in(): void
    {
        $this->userWithRole(RoleName::Cashier, ['username' => 'ravi', 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['username' => 'ravi', 'password' => 'password'])->assertStatus(422);
        $this->assertGuest('web');
    }

    public function test_user_switched_off_while_signed_in_loses_access(): void
    {
        $user = $this->userWithRole(RoleName::Cashier);
        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk();

        $user->update(['is_active' => false]);

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_repeated_wrong_passwords_are_locked_out(): void
    {
        $this->userWithRole(RoleName::Cashier, ['username' => 'ravi']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['username' => 'ravi', 'password' => 'wrong-password'])->assertStatus(422);
        }

        // Even the right password is refused during the lockout.
        $this->postJson('/api/v1/auth/login', ['username' => 'ravi', 'password' => 'password'])->assertStatus(429);
    }

    public function test_user_can_change_language(): void
    {
        $user = $this->userWithRole(RoleName::Cashier);

        $this->actingAs($user)->putJson('/api/v1/auth/locale', ['locale' => 'mr'])->assertOk()->assertJsonPath('locale', 'mr');
        $this->actingAs($user)->putJson('/api/v1/auth/locale', ['locale' => 'xx'])->assertStatus(422);
    }

    public function test_owner_holds_every_permission_and_cashier_only_their_own(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);
        $cashier = $this->userWithRole(RoleName::Cashier);

        $this->assertTrue($owner->can('users.manage'));
        $this->assertTrue($owner->can('backup.manage'));
        $this->assertTrue($cashier->can('sales.create'));
        $this->assertFalse($cashier->can('users.manage'));
        $this->assertFalse($cashier->can('stock.view_cost'));
    }
}
