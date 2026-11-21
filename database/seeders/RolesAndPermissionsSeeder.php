<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Safe to run repeatedly. Adds any missing permission and role; gives a role
 * its default permissions only when the role is first created, so later
 * edits by the owner are never overwritten.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionName::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (RoleName::cases() as $roleName) {
            $role = Role::where('name', $roleName->value)->where('guard_name', 'web')->first();
            if ($role === null) {
                $role = Role::create(['name' => $roleName->value, 'guard_name' => 'web']);
                $role->syncPermissions($roleName->defaultPermissions());
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
