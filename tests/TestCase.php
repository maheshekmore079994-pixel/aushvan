<?php

namespace Tests;

use App\Enums\RoleName;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Requests come from the React screens on the same origin (session cookie auth).
        $this->withHeader('Origin', config('app.url'));
    }

    /** A shop with one user holding the given role. */
    protected function userWithRole(RoleName $role, array $attributes = []): User
    {
        (new RolesAndPermissionsSeeder)->run();
        $store = Store::first() ?? Store::create(['name' => 'Shree Medical & General Stores']);

        (new ReferenceDataSeeder)->run();

        $user = User::factory()->create(['store_id' => $store->id] + $attributes);
        $user->assignRole($role->value);
        $user->refresh();

        return $user;
    }
}
