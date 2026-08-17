<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use App\BusinessModules\Features\MachineryOperations\Models\AssetRequest;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestHistory;
use App\Models\Project;
use Carbon\CarbonInterface;
use DomainException;

final class SiteRequestAssetProjectionService
{
    public function project(SiteRequest $siteRequest, int $actorId): AssetRequest
    {
        if ($siteRequest->request_type !== SiteRequestTypeEnum::EQUIPMENT_REQUEST) {
            throw new DomainException('machinery_site_request_type_mismatch');
        }

        if (! Project::query()
            ->whereKey((int) $siteRequest->project_id)
            ->where('organization_id', (int) $siteRequest->organization_id)
            ->exists()) {
            throw new DomainException('machinery_site_request_project_scope_mismatch');
        }

        $start = $this->startAt($siteRequest);
        if ($start === null) {
            throw new DomainException('machinery_site_request_start_required');
        }

        $request = AssetRequest::withTrashed()->firstOrNew([
            'site_request_id' => (int) $siteRequest->id,
        ]);
        if ($request->trashed()) {
            $request->restore();
        }

        $wasNew = ! $request->exists;
        $currentStatus = $request->exists ? (string) $request->status : null;
        $request->fill([
            'organization_id' => (int) $siteRequest->organization_id,
            'project_id' => (int) $siteRequest->project_id,
            'requested_by_user_id' => (int) $siteRequest->user_id,
            'origin_type' => 'site_request',
            'status' => $this->projectedStatus($siteRequest, $currentStatus),
            'priority' => $this->priority($siteRequest),
            'planned_start_at' => $start,
            'planned_end_at' => $this->endAt($siteRequest),
            'required_profile' => [],
            'requirements' => $this->trimmed($siteRequest->equipment_specs),
            'purpose' => $this->purpose($siteRequest),
        ]);

        $changed = $request->isDirty();
        $request->save();
        if ($wasNew || $changed) {
            $this->audit($request, $actorId, $wasNew ? 'site_request_projected' : 'site_request_projection_updated', [
                'site_request_id' => (int) $siteRequest->id,
                'site_request_status' => $siteRequest->status->value,
            ]);
        }

        return $request->fresh(['events', 'siteRequest']) ?? $request;
    }

    public function synchronizeFromSiteRequest(SiteRequest $siteRequest, int $actorId): AssetRequest
    {
        $request = $this->project($siteRequest, $actorId);
        $target = match ($siteRequest->status) {
            SiteRequestStatusEnum::CANCELLED => 'cancelled',
            SiteRequestStatusEnum::REJECTED => 'rejected',
            SiteRequestStatusEnum::FULFILLED, SiteRequestStatusEnum::COMPLETED => 'completed',
            default => null,
        };
        if ($target === null) {
            return $request;
        }

        $statusChanged = $request->status !== $target;
        if ($statusChanged) {
            $request->update(['status' => $target]);
        }
        $closedAssignments = MachineryAssignment::query()
            ->where('asset_request_id', $request->id)
            ->where('status', 'active')
            ->update([
                'status' => $target === 'completed' ? 'completed' : 'cancelled',
                'actual_end_at' => now(),
            ]);
        if ($statusChanged || $closedAssignments > 0) {
            $this->audit($request, $actorId, 'site_request_closed', [
                'site_request_id' => (int) $siteRequest->id,
                'status' => $target,
                'closed_assignments' => $closedAssignments,
            ]);
        }

        return $request->fresh(['events', 'siteRequest']) ?? $request;
    }

    public function cancelDeletedSiteRequest(SiteRequest $siteRequest, int $actorId): void
    {
        $request = AssetRequest::query()->where('site_request_id', $siteRequest->id)->first();
        if ($request === null || ! in_array($request->status, ['pending', 'approved'], true)) {
            return;
        }
        $request->update(['status' => 'cancelled']);
        $this->audit($request, $actorId, 'site_request_deleted', ['site_request_id' => (int) $siteRequest->id]);
    }

    public function markSiteRequestInProgress(AssetRequest $assetRequest, int $actorId): void
    {
        $siteRequest = $assetRequest->siteRequest()->lockForUpdate()->first();
        if ($siteRequest === null || $siteRequest->status->isFinal() || $siteRequest->status === SiteRequestStatusEnum::IN_PROGRESS) {
            return;
        }

        $this->changeSiteRequestStatus($siteRequest, SiteRequestStatusEnum::IN_PROGRESS, $actorId);
    }

    public function completeFromAssignment(MachineryAssignment $assignment, int $actorId): void
    {
        $assetRequest = $assignment->assetRequest()->lockForUpdate()->first();
        if ($assetRequest === null) {
            return;
        }
        if (! in_array($assetRequest->status, ['completed', 'cancelled', 'rejected'], true)) {
            $assetRequest->update(['status' => 'completed']);
            $this->audit($assetRequest, $actorId, 'assignment_completed', ['assignment_id' => (int) $assignment->id]);
        }

        $siteRequest = $assetRequest->siteRequest()->lockForUpdate()->first();
        if ($siteRequest !== null && ! $siteRequest->status->isFinal()) {
            $this->changeSiteRequestStatus($siteRequest, SiteRequestStatusEnum::COMPLETED, $actorId);
        }
    }

    private function changeSiteRequestStatus(SiteRequest $request, SiteRequestStatusEnum $status, int $actorId): void
    {
        $oldStatus = $request->status->value;
        $request->update(['status' => $status->value]);
        SiteRequestHistory::logStatusChanged($request, $actorId, $oldStatus, $status->value, 'Синхронизация с назначением техники');
    }

    private function projectedStatus(SiteRequest $request, ?string $current): string
    {
        $terminal = match ($request->status) {
            SiteRequestStatusEnum::CANCELLED => 'cancelled',
            SiteRequestStatusEnum::REJECTED => 'rejected',
            SiteRequestStatusEnum::FULFILLED, SiteRequestStatusEnum::COMPLETED => 'completed',
            default => null,
        };
        if ($terminal !== null) {
            return $terminal;
        }
        if ($current !== null && ! in_array($current, ['pending', 'approved'], true)) {
            return $current;
        }

        return $request->status === SiteRequestStatusEnum::APPROVED ? 'approved' : 'pending';
    }

    private function priority(SiteRequest $request): string
    {
        return $request->priority->value === 'medium' ? 'normal' : $request->priority->value;
    }

    private function purpose(SiteRequest $request): string
    {
        return implode("\n", array_filter([
            trim((string) $request->title),
            $this->trimmed($request->description),
        ], static fn (?string $value): bool => $value !== null && $value !== ''));
    }

    private function startAt(SiteRequest $request): ?CarbonInterface
    {
        return $request->equipment_start_at
            ?? $request->rental_start_date?->copy()->startOfDay()
            ?? $request->required_date?->copy()->startOfDay();
    }

    private function endAt(SiteRequest $request): ?CarbonInterface
    {
        return $request->equipment_end_at
            ?? $request->rental_end_date?->copy()->endOfDay();
    }

    private function trimmed(mixed $value): ?string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        return $trimmed === '' ? null : $trimmed;
    }

    /** @param array<string, mixed> $payload */
    private function audit(AssetRequest $request, int $actorId, string $eventType, array $payload): void
    {
        $request->events()->create([
            'organization_id' => (int) $request->organization_id,
            'actor_user_id' => $actorId,
            'event_type' => $eventType,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
