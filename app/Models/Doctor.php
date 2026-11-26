<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A prescriber the shop sees often, so the prescription step picks instead of retyping. The prescription
 * record still keeps its own copy of the name, address and registration number as they were that day.
 * The software records nothing about payments or incentives to doctors (D-054).
 */
#[Fillable(['store_id', 'name', 'reg_no', 'clinic', 'address', 'phone', 'search_text', 'is_active'])]
class Doctor extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** The address line written on the prescription record: clinic, then address. */
    public function addressLine(): ?string
    {
        $line = trim(implode(', ', array_filter([$this->clinic, $this->address])));

        return $line === '' ? null : $line;
    }
}
