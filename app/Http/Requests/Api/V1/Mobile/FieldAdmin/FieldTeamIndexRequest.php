<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile\FieldAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class FieldTeamIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
