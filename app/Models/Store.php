<?php

namespace App\Models;

use App\Enums\GstMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'legal_name', 'address', 'village', 'taluka', 'district', 'state_code',
    'pincode', 'phone', 'gst_mode', 'gstin',
])]
class Store extends Model
{
    /** Settings the shop screen may read and write, with their defaults. */
    public const SHOP_SETTINGS = [
        'drug_licence_20' => null,
        'drug_licence_21' => null,
        'drug_licence_20f' => null,
        'licence_retention_due' => null,
        'bill_footer' => null,
        'counter_prefix' => '',
        'udhar_overdue_days' => 30,
    ];

    protected function casts(): array
    {
        return ['gst_mode' => GstMode::class];
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $row = $this->settings->firstWhere('key', $key);

        return $row?->value ?? $default;
    }

    public function putSetting(string $key, mixed $value): void
    {
        $this->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->unsetRelation('settings');
    }

    /** @return array<string, mixed> */
    public function shopSettings(): array
    {
        $values = [];
        foreach (self::SHOP_SETTINGS as $key => $default) {
            $values[$key] = $this->setting($key, $default);
        }

        return $values;
    }
}
