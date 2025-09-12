<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Project;
use App\Models\WorkType; // Для проверки вида работ
use App\Models\User;
use App\Models\Role;

class WorkCompletionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id');
        if (!$organizationId) {
            return ['organization_error' => 'required'];
        }

        return [
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId)
            ],
            'work_type_id' => [
                'nullable',
                'integer',
                Rule::exists('work_types', 'id')->where('organization_id', $organizationId)
            ],
            'user_id' => [
                'nullable',
                'integer',
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