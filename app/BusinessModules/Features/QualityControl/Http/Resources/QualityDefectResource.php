<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Http\Resources;

use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectPhoto;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectStatusHistory;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectWorkflowService;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QualityDefect */
final class QualityDefectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var QualityDefect $defect */
        $defect = $this->resource;
        $workflow = app(QualityDefectWorkflowService::class)->forDefect($defect);

        return [
            'id' => $defect->id,
            'organization_id' => $defect->organization_id,
            'project_id' => $defect->project_id,
            'contractor_id' => $defect->contractor_id,
            'created_by' => $defect->created_by,
            'assigned_to' => $defect->assigned_to,
            'defect_number' => $defect->defect_number,
            'title' => $defect->title,
            'description' => $defect->description,
            'severity' => $defect->severity->value,
            'severity_label' => $defect->severity->label(),
            'severity_color' => $defect->severity->color(),
            'status' => $defect->status->value,
            'status_label' => $defect->status->label(),
            'status_color' => $defect->status->color(),
            'location_name' => $defect->location_name,
            'schedule_task_id' => $defect->schedule_task_id,
            'construction_journal_entry_id' => $defect->construction_journal_entry_id,
            'completed_work_id' => $defect->completed_work_id,
            'due_date' => $defect->due_date?->format('Y-m-d'),
            'inspection_required' => $defect->inspection_required,
            'resolved_at' => $defect->resolved_at?->toIso8601String(),
            'verified_at' => $defect->verified_at?->toIso8601String(),
            'metadata' => $defect->metadata,
            'can_be_assigned' => $defect->canBeAssigned(),
            'can_be_started' => $defect->canBeStarted(),
            'can_be_resolved' => $defect->canBeResolved(),
            'can_be_verified' => $defect->canBeVerified(),
            'workflow_summary' => $workflow->toArray(),
            'problem_flags' => array_map(
                fn (array $flag): array => $this->problemFlagPayload($flag),
                $workflow->problemFlags
            ),
            'available_actions' => $workflow->availableActions,
            'available_action_details' => array_map(
                fn (string $action): array => $this->actionPayload($action),
                $workflow->availableActions
            ),
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ] : null),
            'contractor' => $this->whenLoaded('contractor', fn () => $this->contractor ? [
                'id' => $this->contractor->id,
                'name' => $this->contractor->name,
            ] : null),
            'created_user' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ] : null),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(
                fn (QualityDefectPhoto $photo): array => $this->photoPayload($photo, $defect)
            )),
            'status_history' => $this->whenLoaded(
                'statusHistory',
                fn () => $this->statusHistory->map(
                    fn (QualityDefectStatusHistory $history): array => $this->historyPayload($history)
                )
            ),
            'created_at' => $defect->created_at->toIso8601String(),
            'updated_at' => $defect->updated_at->toIso8601String(),
        ];
    }

    private function actionPayload(string $action): array
    {
        return [
            'key' => $action,
            'label' => trans_message('quality_control.workflow.actions.' . $action),
        ];
    }

    private function problemFlagPayload(array $flag): array
    {
        $code = (string) ($flag['code'] ?? $flag['key'] ?? '');
        $message = (string) ($flag['message'] ?? $flag['label'] ?? '');

        return array_merge($flag, [
            'code' => $code,
            'key' => $code,
            'message' => $message,
            'label' => $message,
        ]);
    }

    private function historyPayload(QualityDefectStatusHistory $history): array
    {
        $changedBy = $history->relationLoaded('changedBy')
            ? $history->changedBy
            : null;

        return [
            'id' => $history->id,
            'from_status' => $history->from_status?->value,
            'to_status' => $history->to_status->value,
            'comment' => $history->comment,
            'changed_by_id' => $history->changed_by,
            'changed_by' => $changedBy ? $this->userPayload($changedBy) : null,
            'changed_by_user' => $changedBy ? $this->userPayload($changedBy) : null,
            'changed_at' => $history->changed_at?->toIso8601String(),
            'created_at' => $history->changed_at?->toIso8601String(),
        ];
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    private function photoPayload(
        QualityDefectPhoto $photo,
        QualityDefect $defect,
    ): array
    {
        $rawUrl = trim((string) $photo->url);
        $path = $this->resolvePhotoStoragePath($rawUrl);
        $organization = $defect->relationLoaded('organization')
            ? $defect->organization
            : null;
        $previewUrl = $this->resolvePhotoPreviewUrl(
            $rawUrl,
            $path,
            $organization
        );

        return [
            'id' => $photo->id,
            'type' => $photo->type,
            'url' => $rawUrl,
            'path' => $path,
            'preview_url' => $previewUrl,
            'caption' => $photo->caption,
            'uploaded_by_id' => $photo->uploaded_by,
            'uploaded_by' => $photo->relationLoaded('uploadedBy') && $photo->uploadedBy
                ? $this->userPayload($photo->uploadedBy)
                : null,
            'created_at' => $photo->created_at?->toIso8601String(),
        ];
    }

    private function resolvePhotoPreviewUrl(
        string $rawUrl,
        ?string $path,
        ?Organization $organization
    ): ?string
    {
        if ($rawUrl === '') {
            return null;
        }

        $scheme = parse_url($rawUrl, PHP_URL_SCHEME);

        if (in_array($scheme, ['http', 'https'], true)) {
            return $rawUrl;
        }

        if ($path === null) {
            return null;
        }

        $fileService = app(FileService::class);

        return $fileService->temporaryUrl($path, 60, $organization)
            ?? $fileService->url($path, $organization);
    }

    private function resolvePhotoStoragePath(string $rawUrl): ?string
    {
        if ($rawUrl === '') {
            return null;
        }

        $scheme = parse_url($rawUrl, PHP_URL_SCHEME);

        if ($scheme === null || $scheme === '') {
            return ltrim($rawUrl, '/');
        }

        if ($scheme !== 's3') {
            return null;
        }

        $host = parse_url($rawUrl, PHP_URL_HOST);
        $path = parse_url($rawUrl, PHP_URL_PATH);
        $parts = array_filter([
            $host,
            $path !== null ? ltrim($path, '/') : null,
        ]);

        return $parts === [] ? null : implode('/', $parts);
    }
}
