<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

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
            'photos.*.url' => ['nullable', 'required_without:photos.*.file', 'string', 'max:2000'],
            'photos.*.file' => ['nullable', 'required_without:photos.*.url', File::image()->max(10 * 1024)],
            'photos.*.caption' => ['nullable', 'string', 'max:255'],
            'photos.*.metadata' => ['nullable', 'array'],
        ];
    }
}
