<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_asset_id' => ['required', 'integer'],
            'project_id' => ['required', 'integer'],
            'planned_start_at' => ['required', 'date'],
            'planned_end_at' => ['nullable', 'date', 'after:planned_start_at'],
            'asset_request_id' => ['nullable', 'integer'],
            'schedule_task_id' => ['nullable', 'integer'],
            'planned_hours' => ['nullable', 'numeric', 'min:0'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'replaces_assignment_id' => ['nullable', 'integer'],
        ];
    }
}
