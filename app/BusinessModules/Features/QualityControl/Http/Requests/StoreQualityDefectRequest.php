<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreQualityDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
            'contractor_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'severity' => ['nullable', 'string', Rule::in(['minor', 'major', 'critical'])],
            'location_name' => ['nullable', 'string', 'max:255'],
            'schedule_task_id' => ['nullable', 'integer'],
            'construction_journal_entry_id' => ['nullable', 'integer'],
            'completed_work_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
            'inspection_required' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
            'photos' => ['nullable', 'array'],
            'photos.*.type' => ['required_with:photos', 'string', Rule::in(['before', 'after', 'evidence', 'other'])],
            'photos.*.url' => ['required_with:photos', 'string', 'max:2000'],
            'photos.*.caption' => ['nullable', 'string', 'max:255'],
            'photos.*.metadata' => ['nullable', 'array'],
        ];
    }
}
