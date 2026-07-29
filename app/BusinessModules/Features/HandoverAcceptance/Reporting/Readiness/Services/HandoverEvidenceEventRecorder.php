<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklistItem;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackageDocument;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverEvidenceEvent;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class HandoverEvidenceEventRecorder
{
    public function record(
        AcceptanceScope $scope,
        string $eventType,
        string $sourceType,
        int $sourceId,
        CarbonImmutable $occurredAt,
        ?int $actorId,
    ): HandoverEvidenceEvent {
        if (
            preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $eventType) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $sourceType) !== 1
            || $sourceId < 1
            || ($actorId !== null && $actorId < 1)
            || ! $scope->exists
        ) {
            throw new InvalidArgumentException('handover_evidence_event_invalid');
        }

        return DB::transaction(function () use (
            $scope,
            $eventType,
            $sourceType,
            $sourceId,
            $occurredAt,
            $actorId,
        ): HandoverEvidenceEvent {
            AcceptanceScope::query()
                ->whereKey((int) $scope->id)
                ->where('organization_id', (int) $scope->organization_id)
                ->where('project_id', (int) $scope->project_id)
                ->lockForUpdate()
                ->firstOrFail();
            $identity = $this->sourceIdentity($scope, $sourceType, $sourceId);
            $status = match ($eventType) {
                'inspection_resulted' => match ($identity['status']) {
                    'accepted' => 'successful',
                    'rejected' => 'failed',
                    default => $identity['status'],
                },
                default => $identity['status'],
            };
            $existing = HandoverEvidenceEvent::query()
                ->where('organization_id', (int) $scope->organization_id)
                ->where('acceptance_scope_id', (int) $scope->id)
                ->where('event_type', $eventType)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('occurred_at', $occurredAt)
                ->first();
            if ($existing instanceof HandoverEvidenceEvent) {
                return $existing;
            }
            $last = HandoverEvidenceEvent::query()
                ->where('organization_id', (int) $scope->organization_id)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->orderByDesc('source_version')
                ->first();
            if ($last !== null && $occurredAt < $last->occurred_at) {
                throw new InvalidArgumentException('handover_evidence_event_sequence_invalid');
            }
            $sourceVersion = $last === null ? 1 : ((int) $last->source_version) + 1;
            $causation = $this->causation($scope, $eventType, $sourceType, $sourceId);
            if ($causation !== null && $occurredAt < $causation->occurred_at) {
                throw new InvalidArgumentException('handover_evidence_event_sequence_invalid');
            }
            $evidence = [
                'acceptance_scope_id' => (int) $scope->id,
                'source_code' => $identity['source_code'],
                'status' => $status,
            ];
            $hash = hash('sha256', CanonicalJson::encode([
                'actor_id' => $actorId,
                'event_type' => $eventType,
                'evidence' => $evidence,
                'occurred_at' => $occurredAt->toISOString(),
                'organization_id' => (int) $scope->organization_id,
                'project_id' => (int) $scope->project_id,
                'source_id' => $sourceId,
                'source_type' => $sourceType,
                'source_version' => $sourceVersion,
            ]));

            return HandoverEvidenceEvent::query()->create([
                'event_id' => (string) Str::uuid(),
                'organization_id' => (int) $scope->organization_id,
                'project_id' => (int) $scope->project_id,
                'acceptance_scope_id' => (int) $scope->id,
                'event_type' => $eventType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_version' => $sourceVersion,
                'source_code' => $identity['source_code'],
                'status' => $status,
                'causation_event_id' => $causation?->id,
                'actor_id' => $actorId,
                'occurred_at' => $occurredAt,
                'evidence_hash' => $hash,
                'evidence' => $evidence,
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
        }, 3);
    }

    private function sourceIdentity(AcceptanceScope $scope, string $sourceType, int $sourceId): array
    {
        $source = match ($sourceType) {
            'acceptance_scope', 'inspection' => AcceptanceScope::query()
                ->whereKey($sourceId)
                ->where('organization_id', (int) $scope->organization_id)
                ->where('project_id', (int) $scope->project_id)
                ->first(),
            'acceptance_checklist_item' => AcceptanceChecklistItem::query()
                ->whereKey($sourceId)
                ->whereHas(
                    'checklist',
                    static fn ($query) => $query->where('acceptance_scope_id', (int) $scope->id),
                )
                ->first(),
            'acceptance_finding' => AcceptanceFinding::query()
                ->whereKey($sourceId)
                ->where('acceptance_scope_id', (int) $scope->id)
                ->first(),
            'rfi' => ChangeManagementRfi::query()
                ->whereKey($sourceId)
                ->where('organization_id', (int) $scope->organization_id)
                ->where('project_id', (int) $scope->project_id)
                ->first(),
            'change', 'change_request' => ChangeRequest::query()
                ->whereKey($sourceId)
                ->where('organization_id', (int) $scope->organization_id)
                ->where('project_id', (int) $scope->project_id)
                ->first(),
            'quality_defect' => QualityDefect::query()
                ->whereKey($sourceId)
                ->where('organization_id', (int) $scope->organization_id)
                ->where('project_id', (int) $scope->project_id)
                ->first(),
            'constraint' => WorkConstraint::query()
                ->whereKey($sourceId)
                ->where('organization_id', (int) $scope->organization_id)
                ->where('project_id', (int) $scope->project_id)
                ->first(),
            'handover_document', 'document' => HandoverPackageDocument::query()
                ->whereKey($sourceId)
                ->whereHas(
                    'package',
                    static fn ($query) => $query->where('acceptance_scope_id', (int) $scope->id),
                )
                ->first(),
            default => null,
        };

        if (! $source instanceof Model) {
            throw new InvalidArgumentException('handover_evidence_source_not_found');
        }
        if (! $this->isPersistentlyLinked($scope, $sourceType, $source)) {
            throw new InvalidArgumentException('handover_evidence_source_link_missing');
        }

        $rawStatus = $source->getAttribute('status');
        $status = $rawStatus instanceof BackedEnum
            ? (string) $rawStatus->value
            : (string) ($rawStatus ?? 'recorded');
        $rawCode = $source->getAttribute('document_type') ?? $source->getAttribute('code');
        $sourceCode = is_string($rawCode) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $rawCode) === 1
            ? $rawCode
            : $sourceType.'_'.$sourceId;

        return ['source_code' => $sourceCode, 'status' => $status];
    }

    private function isPersistentlyLinked(
        AcceptanceScope $scope,
        string $sourceType,
        Model $source,
    ): bool {
        if (in_array($sourceType, [
            'acceptance_scope',
            'inspection',
            'acceptance_checklist_item',
            'acceptance_finding',
            'handover_document',
            'document',
        ], true)) {
            return true;
        }

        $scopeId = (int) $scope->id;
        if ($sourceType === 'quality_defect') {
            $metadata = $source->getAttribute('metadata');

            return is_array($metadata)
                && (int) ($metadata['source']['acceptance_scope_id'] ?? 0) === $scopeId;
        }
        if (in_array($sourceType, ['rfi', 'constraint'], true)) {
            $metadata = $source->getAttribute('metadata');

            return is_array($metadata)
                && (int) ($metadata['acceptance_scope_id'] ?? 0) === $scopeId;
        }
        if (in_array($sourceType, ['change', 'change_request'], true)) {
            $links = $source->getAttribute('linked_entities');
            if (! is_array($links)) {
                return false;
            }
            foreach ($links as $link) {
                if (
                    is_array($link)
                    && ($link['type'] ?? null) === 'acceptance_scope'
                    && (int) ($link['id'] ?? 0) === $scopeId
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function causation(
        AcceptanceScope $scope,
        string $eventType,
        string $sourceType,
        int $sourceId,
    ): ?HandoverEvidenceEvent {
        $requiredEvent = match ($eventType) {
            'finding_resolved', 'blocker_resolved' => [
                'finding_opened',
                'finding_reopened',
                'blocker_opened',
                'blocker_reopened',
            ],
            'inspection_resulted' => ['inspection_attempted'],
            default => null,
        };
        if ($requiredEvent === null) {
            return null;
        }

        return HandoverEvidenceEvent::query()
            ->where('organization_id', (int) $scope->organization_id)
            ->where('acceptance_scope_id', (int) $scope->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('event_type', $requiredEvent)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first()
            ?? throw new InvalidArgumentException('handover_evidence_causation_missing');
    }
}
