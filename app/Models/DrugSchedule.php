<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Legal class of a medicine and the records it needs. Configuration, never hardcoded. */
#[Fillable([
    'code', 'name', 'requires_prescription', 'requires_prescriber', 'requires_patient', 'requires_pharmacist',
    'requires_register', 'register_retention_years', 'keep_prescription_copy', 'is_enabled', 'warning_text', 'sort_order',
])]
class DrugSchedule extends Model
{
    protected function casts(): array
    {
        return [
            'requires_prescription' => 'boolean', 'requires_prescriber' => 'boolean', 'requires_patient' => 'boolean',
            'requires_pharmacist' => 'boolean', 'requires_register' => 'boolean', 'keep_prescription_copy' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}
