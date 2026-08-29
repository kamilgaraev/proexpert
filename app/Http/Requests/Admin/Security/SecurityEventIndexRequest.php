<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;

class SecurityEventIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
