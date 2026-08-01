<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementCycleSourceReader;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleEvent;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicyDefinition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicySnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleSnapshotRequest;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleSourceRead;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessDimensionSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTransition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementTerminalReason;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCyclePolicyVersion;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementProcessEvent;
use DateTimeImmutable;
use Throwable;

final class EloquentProcurementCycleSourceReader implements ProcurementCycleSourceReader
{
    private const MAX_LINES = 50000;

    private const MAX_EVENTS = 500000;

    public function read(ProcurementCycleSnapshotRequest $request): ProcurementCycleSourceRead
    {
        $projectIds = $request->projectIds();
        if ($projectIds === []) {
            return new ProcurementCycleSourceRead([], [], 0, null);
        }

        try {
            $anchorQuery = ProcurementProcessEvent::query()
                ->where('organization_id', $request->scope->organizationId)
                ->where('event_code', ProcurementProcessEventCode::REQUEST_CREATED->value)
                ->whereIn('project_id', $projectIds)
                ->where('occurred_at', '<=', $request->asOf)
                ->orderBy('purchase_request_line_id');
            if ((clone $anchorQuery)->count() > self::MAX_LINES) {
                throw $this->unavailable();
            }
            $lineIds = (clone $anchorQuery)
                ->pluck('purchase_request_line_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            if ($lineIds === []) {
                return new ProcurementCycleSourceRead([], [], 0, null);
            }

            $eventQuery = ProcurementProcessEvent::query()
                ->where('organization_id', $request->scope->organizationId)
                ->whereIn('purchase_request_line_id', $lineIds)
                ->where('occurred_at', '<=', $request->asOf)
                ->orderBy('purchase_request_line_id')
                ->orderBy('occurred_at')
                ->orderBy('id');
            if ((clone $eventQuery)->count() > self::MAX_EVENTS) {
                throw $this->unavailable();
            }

            $eventsByLine = [];
            $policyIds = [];
            $maxEventId = 0;
            $maxOccurredAt = null;
            foreach ($eventQuery->get() as $record) {
                $event = $this->event($record);
                $lineId = $event->transition->purchaseRequestLineId;
                if (! in_array($event->transition->projectId, $projectIds, true)) {
                    throw $this->unavailable();
                }
                $eventsByLine[$lineId][] = $event;
                if ($event->transition->policyVersionId !== null) {
                    $policyIds[$event->transition->policyVersionId] = true;
                }
                $maxEventId = max($maxEventId, $event->id);
                $occurredAt = $event->transition->occurredAtUtc();
                $maxOccurredAt = $maxOccurredAt === null || $occurredAt > $maxOccurredAt ? $occurredAt : $maxOccurredAt;
            }

            $policies = $this->policies(array_keys($policyIds), $request->scope->organizationId, $projectIds);
            $eligible = [];
            foreach ($eventsByLine as $events) {
                $policyId = $events[0]->transition->policyVersionId;
                if ($policyId === null || ! isset($policies[$policyId])) {
                    continue;
                }
                foreach ($events as $event) {
                    if ($event->transition->policyVersionId !== $policyId
                        || ! hash_equals((string) $event->transition->policyHash, $policies[$policyId]->canonicalHash)
                        || ! hash_equals((string) $event->transition->calendarHash, $policies[$policyId]->definition->calendarHash())) {
                        throw $this->unavailable();
                    }
                }
                $eligible[] = $events;
            }

            return new ProcurementCycleSourceRead($eligible, $policies, $maxEventId, $maxOccurredAt);
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable($exception);
        }
    }

    private function event(ProcurementProcessEvent $record): ProcurementCycleEvent
    {
        $dimensions = $record->getAttribute('dimension_snapshot');
        $occurredAt = $record->getAttribute('occurred_at');
        $eventCode = $record->getAttribute('event_code');
        $terminalReason = $record->getAttribute('terminal_reason');
        if (! is_array($dimensions) || ! $occurredAt instanceof DateTimeImmutable || ! $eventCode instanceof ProcurementProcessEventCode) {
            throw $this->unavailable();
        }
        if ($terminalReason !== null && ! $terminalReason instanceof ProcurementTerminalReason) {
            throw $this->unavailable();
        }

        return new ProcurementCycleEvent(
            (int) $record->getAttribute('id'),
            new ProcurementProcessTransition(
                eventCode: $eventCode,
                organizationId: (int) $record->getAttribute('organization_id'),
                projectId: $this->nullableId($record->getAttribute('project_id')),
                purchaseRequestId: (int) $record->getAttribute('purchase_request_id'),
                purchaseRequestLineId: (int) $record->getAttribute('purchase_request_line_id'),
                occurredAt: $occurredAt,
                sourceKind: (string) $record->getAttribute('source_kind'),
                sourceId: (int) $record->getAttribute('source_id'),
                dimensionSnapshot: ProcurementProcessDimensionSnapshot::fromArray($dimensions),
                actorId: $this->nullableId($record->getAttribute('actor_id')),
                supplierRequestId: $this->nullableId($record->getAttribute('supplier_request_id')),
                supplierRequestLineId: $this->nullableId($record->getAttribute('supplier_request_line_id')),
                supplierPartyId: $this->nullableId($record->getAttribute('supplier_party_id')),
                supplierProposalId: $this->nullableId($record->getAttribute('supplier_proposal_id')),
                supplierProposalVersionId: $this->nullableId($record->getAttribute('supplier_proposal_version_id')),
                supplierProposalDecisionId: $this->nullableId($record->getAttribute('supplier_proposal_decision_id')),
                purchaseOrderId: $this->nullableId($record->getAttribute('purchase_order_id')),
                purchaseOrderItemId: $this->nullableId($record->getAttribute('purchase_order_item_id')),
                purchaseReceiptId: $this->nullableId($record->getAttribute('purchase_receipt_id')),
                purchaseReceiptLineId: $this->nullableId($record->getAttribute('purchase_receipt_line_id')),
                policyVersionId: $this->nullableId($record->getAttribute('policy_version_id')),
                policyHash: $record->getAttribute('policy_hash'),
                calendarVersion: $record->getAttribute('calendar_version'),
                calendarHash: $record->getAttribute('calendar_hash'),
                terminalReason: $terminalReason,
                sourceEventId: $this->nullableId($record->getAttribute('source_event_id')),
                eventVersion: (string) $record->getAttribute('event_version'),
            ),
        );
    }

    private function policies(array $ids, int $organizationId, array $projectIds): array
    {
        if ($ids === []) {
            return [];
        }
        $result = [];
        foreach (ProcurementCyclePolicyVersion::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->get() as $record) {
            $projectId = $this->nullableId($record->getAttribute('project_id'));
            if ($projectId !== null && ! in_array($projectId, $projectIds, true)) {
                throw $this->unavailable();
            }
            $definition = new ProcurementCyclePolicyDefinition(
                organizationId: (int) $record->getAttribute('organization_id'),
                projectId: $projectId,
                timezone: (string) $record->getAttribute('timezone'),
                weeklyWindows: (array) $record->getAttribute('weekly_windows'),
                exceptions: (array) $record->getAttribute('exceptions'),
                stageSlaSeconds: (array) $record->getAttribute('stage_sla_seconds'),
                totalSlaSeconds: (int) $record->getAttribute('total_sla_seconds'),
                terminalCancellationPolicy: (array) $record->getAttribute('terminal_cancellation_policy'),
                effectiveFrom: $record->getAttribute('effective_from'),
                effectiveTo: $record->getAttribute('effective_to'),
                formulaVersion: (string) $record->getAttribute('formula_version'),
                sourceSchemaVersion: (string) $record->getAttribute('source_schema_version'),
                eventSchemaVersion: (string) $record->getAttribute('event_schema_version'),
                calendarVersion: (string) $record->getAttribute('calendar_version'),
            );
            if (! hash_equals((string) $record->getAttribute('canonical_hash'), $definition->canonicalHash())
                || ! hash_equals((string) $record->getAttribute('calendar_hash'), $definition->calendarHash())) {
                throw $this->unavailable();
            }
            $snapshot = new ProcurementCyclePolicySnapshot((int) $record->getAttribute('id'), $definition);
            $result[$snapshot->versionId] = $snapshot;
        }
        if (count($result) !== count($ids)) {
            throw $this->unavailable();
        }

        return $result;
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function unavailable(?Throwable $previous = null): ReportContractException
    {
        return ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE, previous: $previous);
    }
}
