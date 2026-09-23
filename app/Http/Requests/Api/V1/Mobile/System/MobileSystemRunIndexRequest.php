<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile\System;

use Illuminate\Foundation\Http\FormRequest;

final class MobileSystemRunIndexRequest extends FormRequest
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
