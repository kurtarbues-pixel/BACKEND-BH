<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => Str::lower(trim($this->input('username')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/^[\pL\pM]+(?:[ \x27\x{2019}-][\pL\pM]+)*$/u'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{2,49}$/', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^(?:09\d{9}|\+639\d{9})$/'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in([Role::TENANT, Role::LANDLORD])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Use letters, spaces, hyphens, or apostrophes only.',
            'phone.regex' => 'Enter a Philippine mobile number as 09XXXXXXXXX or +639XXXXXXXXX.',
            'email.email' => 'Enter a valid email address.',
        ];
    }
}
