<?php

namespace App\Actions;

use App\Domain\Shared\Audit;
use App\Enums\RoleName;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** First-run setup: creates the shop and its Owner account. Runs once. */
final class SetUpShop
{
    /**
     * @param  array{shop_name: string, village?: ?string, phone?: ?string, name: string, username: string, password: string, locale?: string}  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data) {
            if (User::withTrashed()->exists()) {
                throw new LogicException('The shop is already set up.');
            }

            (new RolesAndPermissionsSeeder)->run();

            $store = Store::create([
                'name' => $data['shop_name'],
                'village' => $data['village'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]);

            $owner = User::create([
                'store_id' => $store->id,
                'name' => $data['name'],
                'username' => strtolower($data['username']),
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'en',
            ]);
            $owner->assignRole(RoleName::Owner->value);

            // Units, GST rates, drug schedules and categories the shop starts with.
            (new ReferenceDataSeeder)->run();

            Audit::log('shop.setup', $store, userId: $owner->id, storeId: $store->id);

            return $owner->refresh();
        });
    }
}
