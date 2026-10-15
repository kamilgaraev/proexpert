<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectPhoto;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectPhotoReferencePolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Models\Organization;
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
        $organization = $issue->relationLoaded('organization') ? $issue->getRelation('organization') : null;
        $author = $issue->relationLoaded('createdBy') ? $issue->getRelation('createdBy') : null;
        $assignee = $issue->relationLoaded('assignedUser') ? $issue->getRelation('assignedUser') : null;
        $history = $issue->relationLoaded('statusHistory') ? $issue->getRelation('statusHistory') : collect();
        $photos = $issue->relationLoaded('photos') ? $issue->getRelation('photos') : collect();
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
            'author' => $author ? ['id' => (int) $author->id, 'name' => $author->name] : null,
            'assignee' => $assignee ? ['id' => (int) $assignee->id, 'name' => $assignee->name] : null,
            'history' => $history->filter(static fn ($event): bool => (int) $event->organization_id === (int) $issue->organization_id)->map(fn ($event): array => ['id' => (int) $event->id, 'from_status' => $this->value($event->from_status), 'to_status' => $this->value($event->to_status), 'comment' => $event->comment, 'changed_by' => $event->changed_by, 'author' => $event->relationLoaded('changedBy') && $event->changedBy ? ['id' => (int) $event->changedBy->id, 'name' => $event->changedBy->name] : null, 'changed_at' => $event->changed_at?->toISOString(), 'evidence_refs' => $event->reporting_evidence_refs ?? []])->values()->all(),
            'photos' => $photos->map(fn (QualityDefectPhoto $photo): ?array => $this->photoPayload($photo, $issue, $organization))->filter()->values()->all(),
            'result' => ['resolved_at' => $issue->resolved_at?->toISOString(), 'verified_at' => $issue->verified_at?->toISOString()],
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

    private function photoPayload(QualityDefectPhoto $photo, QualityDefect $issue, ?Organization $organization): ?array
    {
        $references = app(QualityDefectPhotoReferencePolicy::class);
        if (! $references->belongsToDefect($photo, $issue)) {
            return null;
        }
        $path = $references->storagePath((string) $photo->url, $issue);
        $externalUrl = $references->externalUrl((string) $photo->url);
        if ($path === null && $externalUrl === null) {
            return null;
        }

        return [
            'id' => (int) $photo->id,
            'type' => $photo->type,
            'caption' => $photo->caption,
            'url' => $path !== null ? app(FileService::class)->temporaryUrl($path, 60, $organization) : $externalUrl,
            'mime_type' => $photo->mime_type,
            'size_bytes' => $photo->size_bytes,
            'created_at' => $photo->created_at?->toISOString(),
        ];
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
        if (! is_string($path) || ! str_starts_with($path, 'org-'.$issue->organization_id.'/')) {
            return null;
        }

        $organization = $issue->relationLoaded('organization')
            ? $issue->getRelation('organization')
            : null;

        return app(FileService::class)->temporaryUrl($path, 60, $organization);
    }
}
