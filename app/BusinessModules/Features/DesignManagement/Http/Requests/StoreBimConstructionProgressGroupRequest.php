<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBimConstructionProgressGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', 'min:1'],
            'element_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'element_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'title' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:120'],
            'zone' => ['nullable', 'string', 'max:120'],
            'work_kind' => ['nullable', 'string', 'max:120'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ];
    }
}
