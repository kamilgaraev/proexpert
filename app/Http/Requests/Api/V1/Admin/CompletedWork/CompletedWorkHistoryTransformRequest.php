<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\CompletedWork;

use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

final class CompletedWorkHistoryTransformRequest extends FormRequest
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
            'after_id' => ['nullable', 'integer', 'min:0'],
            'batch_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }

    public function afterId(): ?int
    {
        $value = $this->validated('after_id') ?? null;

        return $value === null ? null : (int) $value;
    }

    public function batchSize(): int
    {
        return (int) ($this->validated('batch_size') ?? 50);
    }

    public function dryRun(): bool
    {
        return (bool) ($this->validated('dry_run') ?? false);
    }
}
