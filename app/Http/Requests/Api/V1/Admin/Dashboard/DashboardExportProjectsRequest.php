<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Dashboard;

use App\Rules\ProjectAccessibleRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DashboardExportProjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $organizationId = Auth::user()?->current_organization_id;

        return [
            'format' => ['required', 'string', Rule::in(['csv', 'excel'])],
            'visible_only' => ['nullable', 'boolean'],
            'filters' => ['nullable', 'array'],
            'filters.project_id' => [
                'nullable',
                'integer',
                new ProjectAccessibleRule($organizationId === null ? null : (int) $organizationId),
            ],
            'filters.organization_id' => ['prohibited'],
            'filters.status' => ['nullable', 'array'],
            'filters.status.*' => ['string', 'max:50'],
            'filters.health' => ['nullable', 'array'],
            'filters.health.*' => ['string', Rule::in(['good', 'warning', 'critical'])],
            'filters.budget_min' => ['nullable', 'numeric', 'min:0'],
            'filters.budget_max' => ['nullable', 'numeric', 'min:0'],
            'filters.start_date' => ['nullable', 'date_format:Y-m-d'],
            'filters.end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filters.start_date'],
            'bounds' => ['nullable', 'array'],
            'bounds.north' => ['required_with:bounds', 'numeric', 'between:-90,90'],
            'bounds.south' => ['required_with:bounds', 'numeric', 'between:-90,90'],
            'bounds.east' => ['required_with:bounds', 'numeric', 'between:-180,180'],
            'bounds.west' => ['required_with:bounds', 'numeric', 'between:-180,180'],
        ];
    }
}
