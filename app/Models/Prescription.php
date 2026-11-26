<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Prescriber and patient details recorded for a sale of prescription medicine. */
#[Fillable([
    'store_id', 'serial_no', 'customer_id', 'doctor_id', 'patient_name', 'patient_address', 'prescriber_name', 'prescriber_address',
    'prescriber_reg_no', 'prescription_date', 'created_by',
])]
class Prescription extends Model
{
    protected function casts(): array
    {
        return ['prescription_date' => 'date:Y-m-d'];
    }
}
