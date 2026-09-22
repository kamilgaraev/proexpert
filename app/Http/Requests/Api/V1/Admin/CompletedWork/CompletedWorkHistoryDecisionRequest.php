<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\CompletedWork;

use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

final class CompletedWorkHistoryDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $this->attributes->get('current_organization');
        $organizationId = (int) ($organization?->id ?? $this->attributes->get('current_organization_id') ?? $user?->current_organization_id);
        $projectId = (int) ($this->route('project')?->id ?? $this->route('project'));

        return $user !== null && $organizationId > 0 && $projectId > 0
            && app(AuthorizationService::class)->can($user, 'completed_works.edit', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'strict_project_scope' => true,
            ]);
    }

    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'string', 'max:128'],
            'expected_version' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
            'source' => ['required', 'string', 'in:quantity,completed_quantity,explicit'],
            'canonical_quantity' => ['required_if:source,explicit', 'prohibited_unless:source,explicit', 'nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999.9999'],
        ];
    }
}
