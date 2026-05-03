<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing\Support;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'email' => ['nullable', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
