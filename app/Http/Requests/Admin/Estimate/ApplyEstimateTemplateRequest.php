<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Estimate;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApplyEstimateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $organizationId = (int) $this->user()?->current_organization_id;

        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where(
                fn ($query) => $query->whereIn('id', Project::query()
                    ->accessibleByOrganization($organizationId)->select('projects.id')),
            )],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->where('organization_id', $organizationId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'estimate_date' => ['required', 'date'],
        ];
    }
}
