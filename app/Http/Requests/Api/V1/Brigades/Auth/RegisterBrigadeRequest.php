<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Brigades\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterBrigadeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'team_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'contact_person' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:50'],
            'contact_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'regions' => ['nullable', 'array'],
            'regions.*' => ['string', 'max:255'],
            'specializations' => ['nullable', 'array'],
            'specializations.*' => ['string', 'max:255'],
        ];
    }
}
