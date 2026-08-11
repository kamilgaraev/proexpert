<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateAssetRequest extends FormRequest
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
            'purpose' => ['required', 'string', 'max:2000'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'required_profile' => ['nullable', 'array'],
            'required_profile.operational_mode' => ['nullable', Rule::in(['custody', 'site_operation', 'shift_operation'])],
            'required_profile.tracks_meter' => ['nullable', 'boolean'],
            'required_profile.tracks_fuel' => ['nullable', 'boolean'],
            'required_profile.tracks_production' => ['nullable', 'boolean'],
            'required_profile.maintenance_enabled' => ['nullable', 'boolean'],
        ];
    }
}
