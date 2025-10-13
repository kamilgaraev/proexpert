<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Project; // Для проверки существования проекта в организации
use App\Models\Material; // Для проверки материала
use App\Models\User; // Для проверки пользователя (прораба)
use App\Models\Role;

class MaterialUsageReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (!$this->user()) {
            abort(401, 'Unauthorized');
        }

        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()->current_organization_id;
        if (!$organizationId) {
            abort(403, 'Контекст организации не определен');
        }

        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()->current_organization_id;

        return [
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId)
            ],
            'material_id' => [
                'nullable',
                'integer',
                Rule::exists('materials', 'id')->where('organization_id', $organizationId)
            ],
            'user_id' => [
                'nullable',
                'integer',
                // Проверяем, что пользователь существует и является прорабом в этой организации
                Rule::exists('users', 'id')->where(function ($query) use ($organizationId) {
                    return $query->whereHas('roleAssignments', function ($assignmentQuery) use ($organizationId) {
                        $assignmentQuery->where('role_slug', 'foreman')
                                        ->whereHas('context', function ($contextQuery) use ($organizationId) {
                                            $contextQuery->where('context_type', 'organization')
                                                         ->where('context_id', $organizationId);
                                        })->where('is_active', true);
                    });
                })
            ],
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
        ];
    }
} 