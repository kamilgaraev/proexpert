<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignProjectIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var QualityDefect $issue */
        $issue = $this->resource;
        $context = (array) (($issue->metadata ?? [])['design_issue_context'] ?? []);

        return [
            'id' => $issue->id,
            'revision' => (int) $issue->getAttribute('row_version'),
            'project_id' => $issue->project_id,
            'kind' => $issue->kind,
            'title' => $issue->title,
            'description' => $issue->description,
            'severity' => $this->value($issue->severity),
            'status' => $this->value($issue->status),
            'author_id' => $issue->created_by,
            'assignee_id' => $issue->assigned_to,
            'due_date' => $issue->due_date?->format('Y-m-d'),
            'resolved_at' => $issue->resolved_at?->toIso8601String(),
            'verified_at' => $issue->verified_at?->toIso8601String(),
            'is_blocking' => (bool) (($issue->metadata ?? [])['blocking']['active'] ?? false),
            'blocking_reason' => ($issue->metadata ?? [])['blocking']['reason'] ?? null,
            'context' => $context,
            'snapshot_url' => $this->snapshotUrl($issue, $context),
            'available_actions' => $this->availableActions($issue, $request->user()),
            'created_at' => $issue->created_at?->toIso8601String(),
            'updated_at' => $issue->updated_at?->toIso8601String(),
        ];
    }

    private function value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private function availableActions(QualityDefect $issue, ?User $actor): array
    {
        if ($actor === null) {
            return [];
        }
        $authorization = app(AuthorizationService::class);
        $scope = ['organization_id' => (int) $issue->organization_id, 'project_id' => (int) $issue->project_id];
        $canReview = $authorization->can($actor, 'design-management.review', $scope);
        $canBlock = $authorization->can($actor, 'design-management.issues.manage_blocking', $scope);

        return [
            ['key' => 'assign', 'label' => trans_message('design_issues.actions.assign'), 'enabled' => $canReview && $issue->canBeAssigned()],
            ['key' => 'resolve', 'label' => trans_message('design_issues.actions.resolve'), 'enabled' => $canReview && $issue->canBeResolved()],
            ['key' => 'verify', 'label' => trans_message('design_issues.actions.verify'), 'enabled' => $canReview && $issue->canBeVerified()],
            ['key' => 'blocking_flag', 'label' => trans_message('design_issues.actions.blocking_flag'), 'enabled' => $canBlock],
        ];
    }

    private function snapshotUrl(QualityDefect $issue, array $context): ?string
    {
        $path = $context['snapshot']['path'] ?? null;
        if (! is_string($path) || $path === '') {
            return null;
        }

        return app(FileService::class)->temporaryUrl($path, 60, Organization::query()->find($issue->organization_id));
    }
}
