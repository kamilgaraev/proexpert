<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CrmListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:64'],
            'owner_user_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'source_id' => ['nullable', 'uuid'],
            'pipeline_id' => ['nullable', 'uuid'],
            'stage_id' => ['nullable', 'uuid'],
            'pipeline_code' => ['nullable', 'string', 'max:64'],
            'stage_code' => ['nullable', 'string', 'max:64'],
            'archived' => ['nullable', 'boolean'],
            'merged' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'string', Rule::in([
                'created_at',
                'updated_at',
                'name',
                'full_name',
                'title',
                'status',
                'last_activity_at',
                'expected_close_at',
                'due_at',
            ])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
