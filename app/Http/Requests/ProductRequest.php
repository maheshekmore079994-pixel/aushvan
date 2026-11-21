<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    /** Product managers may save anything; pharmacists (medicines.manage) may save medicines. */
    public function authorize(): bool
    {
        $user = $this->user();

        $existing = $this->route('product');
        $isMedicine = $this->input('type') === Product::TYPE_MEDICAL
            && ($existing === null || $existing->type === Product::TYPE_MEDICAL);

        return $user->can(Permission::PRODUCTS_MANAGE) || ($isMedicine && $user->can(Permission::MEDICINES_MANAGE));
    }

    public function rules(): array
    {
        $storeId = $this->user()->store_id;
        $productId = $this->route('product')?->id;

        return [
            'type' => ['required', Rule::in([Product::TYPE_MEDICAL, Product::TYPE_GENERAL])],
            'name' => ['required', 'string', 'max:150'],
            'name_local' => ['nullable', 'string', 'max:150'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('store_id', $storeId)],
            'brand' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'hsn_code' => ['nullable', 'regex:/^[0-9]{4,8}$/'],
            'tax_rate_id' => ['required', Rule::exists('tax_rates', 'id')->where('is_active', true)],
            'base_unit_id' => ['required', 'exists:units,id'],
            'pack_unit_id' => ['nullable', 'exists:units,id', 'different:base_unit_id'],
            'pack_size' => ['nullable', 'required_with:pack_unit_id', 'integer', 'min:2', 'max:1000000'],
            'allow_loose_sale' => ['boolean'],
            'mrp_paise' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'selling_price_paise' => ['required', 'integer', 'min:1', 'max:100000000'],
            'min_stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'tracks_expiry' => ['boolean'],
            'rack_location' => ['nullable', 'string', 'max:50'],
            'is_quick_item' => ['boolean'],
            'is_active' => ['boolean'],
            'generate_barcode' => ['boolean'],
            'confirm_duplicate' => ['boolean'],
            'version' => ['nullable', 'integer'],

            'barcodes' => ['array', 'max:10'],
            'barcodes.*.barcode' => [
                'required', 'string', 'regex:/^[A-Za-z0-9-]{4,40}$/', 'distinct',
                Rule::unique('product_barcodes', 'barcode')->where('store_id', $storeId)
                    ->when($productId, fn ($rule) => $rule->whereNot('product_id', $productId)),
            ],
            'barcodes.*.unit' => ['nullable', Rule::in(['pack', 'base'])],

            'medicine' => ['nullable', 'array'],
            'medicine.generic_name' => ['nullable', 'string', 'max:200'],
            'medicine.composition_text' => ['nullable', 'string', 'max:500'],
            'medicine.strength' => ['nullable', 'string', 'max:50'],
            'medicine.dosage_form' => ['nullable', Rule::in(config('shop.dosage_forms'))],
            'medicine.drug_schedule_id' => ['nullable', Rule::exists('drug_schedules', 'id')->where('is_enabled', true)],
            'medicine.is_ndps' => ['boolean'],
            'medicine.storage_instructions' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $mrp = (int) $this->input('mrp_paise', 0);
                if ($mrp > 0 && (int) $this->input('selling_price_paise') > $mrp) {
                    $validator->errors()->add('selling_price_paise', 'Selling price cannot be more than MRP.');
                }
            },
        ];
    }
}
