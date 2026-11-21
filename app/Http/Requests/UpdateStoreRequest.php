<?php

namespace App\Http\Requests;

use App\Enums\GstMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware checks settings.manage.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->gstin)) {
            $this->merge(['gstin' => strtoupper(trim($this->gstin)) ?: null]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'village' => ['nullable', 'string', 'max:100'],
            'taluka' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'state_code' => ['required', 'digits:2'],
            'pincode' => ['nullable', 'digits:6'],
            'phone' => ['nullable', 'string', 'max:20'],
            'gst_mode' => ['required', Rule::enum(GstMode::class)],
            'gstin' => [
                'nullable',
                Rule::requiredIf(fn () => GstMode::tryFrom((string) $this->gst_mode)?->requiresGstin() ?? false),
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
            ],
            'settings' => ['sometimes', 'array'],
            'settings.drug_licence_20' => ['nullable', 'string', 'max:50'],
            'settings.drug_licence_21' => ['nullable', 'string', 'max:50'],
            'settings.drug_licence_20f' => ['nullable', 'string', 'max:50'],
            'settings.licence_retention_due' => ['nullable', 'date_format:Y-m-d'],
            'settings.bill_footer' => ['nullable', 'string', 'max:200'],
            'settings.counter_prefix' => ['nullable', 'string', 'max:2', 'regex:/^[A-Z0-9]*$/'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // The first two digits of a GSTIN are the state code.
                if ($this->gstin && ! $validator->errors()->has('gstin')
                    && substr($this->gstin, 0, 2) !== (string) $this->state_code) {
                    $validator->errors()->add('gstin', 'The GSTIN must start with the state code.');
                }
            },
        ];
    }
}
