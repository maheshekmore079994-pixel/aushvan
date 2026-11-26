<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** What an expense was for. Seeded per shop with the usual heads; the owner can add or hide them. */
#[Fillable(['store_id', 'name', 'name_local', 'sort_order', 'is_active'])]
class ExpenseCategory extends Model
{
    /** name => Marathi name; the Hindi screen falls back to the English name. */
    public const DEFAULTS = [
        'Rent' => 'भाडे', 'Electricity' => 'वीज बिल', 'Internet & phone' => 'इंटरनेट व फोन', 'Salary' => 'पगार',
        'Transport' => 'वाहतूक', 'Packaging' => 'पॅकिंग साहित्य', 'Maintenance & repairs' => 'दुरुस्ती', 'Tea & snacks' => 'चहा-नाश्ता',
        'Stationery & printing' => 'स्टेशनरी व छपाई', 'Licence & fees' => 'परवाना व फी', 'Other' => 'इतर',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** Makes the default heads for a shop that has none yet. */
    public static function seedDefaults(int $storeId): void
    {
        $order = 0;
        foreach (self::DEFAULTS as $name => $local) {
            self::firstOrCreate(['store_id' => $storeId, 'name' => $name], ['name_local' => $local, 'sort_order' => $order++]);
        }
    }
}
