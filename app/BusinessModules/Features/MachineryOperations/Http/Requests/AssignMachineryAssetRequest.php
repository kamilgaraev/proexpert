<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignMachineryAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
            'schedule_task_id' => ['nullable', 'integer'],
            'planned_start_at' => ['required', 'date'],
            'planned_end_at' => ['nullable', 'date', 'after:planned_start_at'],
            'planned_hours' => ['nullable', 'numeric', 'min:0'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.required' => trans_message('machinery_operations.validation.project_required'),
            'planned_start_at.required' => trans_message('machinery_operations.validation.planned_start_required'),
            'planned_end_at.after' => trans_message('machinery_operations.validation.planned_end_after_start'),
        ];
    }
}
