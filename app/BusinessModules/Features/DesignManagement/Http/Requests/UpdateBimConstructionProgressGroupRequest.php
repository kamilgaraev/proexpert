<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateBimConstructionProgressGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'floor' => ['sometimes', 'nullable', 'string', 'max:120'],
            'zone' => ['sometimes', 'nullable', 'string', 'max:120'],
            'work_kind' => ['sometimes', 'nullable', 'string', 'max:120'],
            'element_ids' => ['sometimes', 'array', 'min:1', 'max:1000'],
            'element_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
