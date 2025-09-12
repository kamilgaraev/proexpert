<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Project;
use App\Models\User;

class ForemanActivityReportRequest extends FormRequest
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
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) use ($organizationId) {
                    return $query->whereHas('roles', function ($roleQuery) use ($organizationId) {
                        // TODO: Обновить для новой системы авторизации
                        $roleQuery->where('role_user.organization_id', $organizationId);
                    });
                })
            ],
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
        ];
    }
} 