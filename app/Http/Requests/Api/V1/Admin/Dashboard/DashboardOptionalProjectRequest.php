<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Dashboard;

use App\Rules\ProjectAccessibleRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class DashboardOptionalProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $organizationId = Auth::user()?->current_organization_id;

        return [
            'project_id' => [
                'nullable',
                'integer',
                new ProjectAccessibleRule($organizationId === null ? null : (int) $organizationId),
            ],
            'status' => ['nullable', 'string', 'max:100'],
            'is_archived' => ['nullable', 'boolean'],
        ];
    }
}
