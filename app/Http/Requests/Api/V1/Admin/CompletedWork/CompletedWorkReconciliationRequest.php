<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\CompletedWork;

use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

final class CompletedWorkReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $this->attributes->get('current_organization');
        $organizationId = (int) ($organization?->id ?? $this->attributes->get('current_organization_id') ?? $user?->current_organization_id);
        $projectId = (int) ($this->route('project')?->id ?? $this->route('project'));

        return $user !== null && $organizationId > 0 && $projectId > 0
            && app(AuthorizationService::class)->can($user, 'completed_works.view', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
            ]);
    }

    public function rules(): array
    {
        return [
            'after_id' => ['nullable', 'integer', 'min:0'],
            'batch_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function afterId(): ?int
    {
        return $this->validated('after_id') === null ? null : (int) $this->validated('after_id');
    }

    public function batchSize(): int
    {
        return (int) ($this->validated('batch_size') ?? 50);
    }
}
