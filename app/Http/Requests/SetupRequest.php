<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The action itself refuses to run once a user exists.
    }

    public function rules(): array
    {
        return [
            'shop_name' => ['required', 'string', 'max:150'],
            'village' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
            'locale' => ['nullable', Rule::in(config('shop.languages'))],
        ];
    }
}
