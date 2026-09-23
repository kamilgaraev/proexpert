<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

final class StoreExecutiveApprovedListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', File::types(['pdf', 'doc', 'docx', 'xls', 'xlsx'])->min(1)->max(25 * 1024)],
            'approved_by_party' => ['required', 'string', 'max:255'],
            'approved_at' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.key' => ['required', 'string', 'max:128', 'distinct'],
            'items.*.profile_type' => ['required', 'string', 'max:100'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.stage' => ['sometimes', 'string', 'max:64'],
            'items.*.work_type_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.completed_work_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.project_location_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.conditions' => ['sometimes', 'array'],
            'items.*.conditions.designer_supervision' => ['sometimes', 'boolean'],
            'items.*.conditions.separate_executor' => ['sometimes', 'boolean'],
        ];
    }
}
