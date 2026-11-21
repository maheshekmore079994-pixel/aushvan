<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) or update (PUT) a staff login. */
class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware checks users.manage.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->username)) {
            $this->merge(['username' => strtolower(trim($this->username))]);
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'username' => [
                'required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($userId),
            ],
            'mobile' => ['nullable', 'string', 'max:15'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'min:8', 'max:100'],
            'pin' => ['nullable', 'digits_between:4,6'],
            'locale' => ['required', Rule::in(config('shop.languages'))],
            'is_pharmacist' => ['required', 'boolean'],
            'pharmacist_reg_no' => ['nullable', 'required_if:is_pharmacist,true', 'string', 'max:50'],
            'is_active' => ['required', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }
}
