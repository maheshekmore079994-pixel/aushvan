<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'generic_name', 'composition_text', 'strength', 'dosage_form',
    'drug_schedule_id', 'is_ndps', 'storage_instructions',
])]
class MedicineDetail extends Model
{
    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['is_ndps' => 'boolean'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(DrugSchedule::class, 'drug_schedule_id');
    }
}
