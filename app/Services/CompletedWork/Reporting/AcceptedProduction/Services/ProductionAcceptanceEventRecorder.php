<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\ContractPerformanceAct;
use App\Models\PerformanceActCompletedWork;
use App\Models\PerformanceActLine;
use App\Services\Acting\ActingQuantityReservationService;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\ApprovedAcceptanceRate;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Events\ProductionAcceptanceTransitioned;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ProductionAcceptanceEventRecorder
{
    private const ACCEPTED_STATES = ['approved', 'signed'];

    private const PROJECTION_GAP_REASONS = [
        'approved_acceptance_currency_missing' => 'currency_unavailable',
        'approved_acceptance_money_invalid' => 'rate_unavailable',
        'approved_acceptance_quantity_invalid' => 'quantity_unavailable',
        'approved_acceptance_rate_not_exact' => 'rate_unavailable',
        'production_acceptance_line_identity_unavailable' => 'source_identity_unavailable',
        'production_acceptance_active_quantity_invalid' => 'source_identity_unavailable',
        'production_acceptance_owner_member_invalid' => 'source_identity_unavailable',
        'production_acceptance_owner_membership_empty' => 'source_identity_unavailable',
        'production_acceptance_pivot_unavailable' => 'source_identity_unavailable',
        'production_acceptance_quantity_zero' => 'quantity_unavailable',
        'production_acceptance_rate_unavailable' => 'rate_unavailable',
        'production_acceptance_reversal_without_acceptance' => 'legacy_history_unavailable',
        'production_acceptance_scope_mismatch' => 'scope_unavailable',
        'production_acceptance_source_unavailable' => 'source_identity_unavailable',
        'production_acceptance_unit_mismatch' => 'unit_unavailable',
        'production_acceptance_unit_unavailable' => 'unit_unavailable',
    ];

    public function __construct(
        private ProductionAcceptanceEventIdentity $identity,
        private ApprovedAcceptanceRateResolver $rates,
        private ProductionAcceptanceReversalSource $reversals,
        private ProductionAcceptanceOwnerVersionWriter $ownerVersions,
        private ProductionAcceptanceCoverageGapRecorder $coverageGaps,
        private ActingQuantityReservationService $quantityReservations,
    ) {}

    public function recordTransition(
        ContractPerformanceAct $act,
        string $previousStatus,
        string $currentStatus,
        CarbonImmutable $occurredAt,
        ?int $actorId,
    ): ProductionAcceptanceTransitioned {
        $wasAccepted = in_array($previousStatus, self::ACCEPTED_STATES, true);
        $isAccepted = in_array($currentStatus, self::ACCEPTED_STATES, true);
        if ($wasAccepted === $isAccepted) {
            $existing = ProductionAcceptanceEvent::query()
                ->where('performance_act_id', $act->id)
                ->orderByDesc('transition_version')
                ->orderByDesc('id')
                ->first();
            if ($existing === null) {
                throw new InvalidArgumentException('production_acceptance_transition_not_applicable');
            }

            return new ProductionAcceptanceTransitioned(
                (int) $existing->id,
                [(int) $existing->id],
                (int) $act->id,
                (string) $existing->event_type,
            );
        }

        $eventType = $isAccepted ? 'accepted' : 'reversed';
        $act->loadMissing([
            'contract',
            'lines.completedWork.workType.measurementUnit',
            'lines.completedWork.scheduleTask',
            'completedWorks.workType.measurementUnit',
            'completedWorks.scheduleTask',
        ]);
        $completedWorkLines = $act->lines->filter(
            static fn (PerformanceActLine $line): bool => $line->line_type === PerformanceActLine::TYPE_COMPLETED_WORK,
        )->sortBy('id')->values();
        $canonicalWorkIds = array_fill_keys(
            $completedWorkLines
                ->pluck('completed_work_id')
                ->filter(static fn ($id): bool => $id !== null)
                ->map(static fn ($id): int => (int) $id)
                ->all(),
            true,
        );
        $pivotOnlyWorks = $act->completedWorks->filter(
            static fn (CompletedWork $work): bool => ! isset($canonicalWorkIds[(int) $work->id]),
        )->sortBy('id')->values();
        if ($act->contract === null || ($completedWorkLines->isEmpty() && $pivotOnlyWorks->isEmpty())) {
            throw new InvalidArgumentException('production_acceptance_source_unavailable');
        }

        return DB::transaction(function () use (
            $act,
            $completedWorkLines,
            $pivotOnlyWorks,
            $eventType,
            $occurredAt,
            $actorId,
        ): ProductionAcceptanceTransitioned {
            $workIds = $completedWorkLines->pluck('completed_work_id')
                ->merge($pivotOnlyWorks->pluck('id'))
                ->filter(static fn ($id): bool => $id !== null)
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values();
            $lockedWorks = CompletedWork::query()
                ->whereIn('id', $workIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            $acceptedSourceQuantities = [];
            if ($eventType === 'accepted') {
                $organizationId = (int) $act->contract->organization_id;
                $acceptedSourceQuantities = $this->quantityReservations->approvedQuantities(
                    $lockedWorks,
                    $organizationId,
                    (int) $act->id,
                );
                foreach ($this->activeAcceptedQuantities(
                    $organizationId,
                    $workIds->all(),
                    (int) $act->id,
                ) as $workId => $quantity) {
                    $acceptedSourceQuantities[$workId] = ($acceptedSourceQuantities[$workId] ?? 0) + $quantity;
                }
            }

            $this->ownerVersions->record($act, $eventType, $occurredAt);
            $eventIds = [];
            foreach ($completedWorkLines as $line) {
                $eventIds[] = (int) $this->recordLine(
                    $act,
                    $line,
                    $eventType,
                    $occurredAt,
                    $actorId,
                    $acceptedSourceQuantities,
                )->id;
            }
            foreach ($pivotOnlyWorks as $work) {
                $eventIds[] = (int) $this->recordWork(
                    $act,
                    $work,
                    $eventType,
                    $occurredAt,
                    $actorId,
                    $acceptedSourceQuantities,
                )->id;
            }
            sort($eventIds, SORT_NUMERIC);

            return new ProductionAcceptanceTransitioned(
                $eventIds[0],
                $eventIds,
                (int) $act->id,
                $eventType,
            );
        });
    }

    public function recordTransitionIfApplicable(
        ContractPerformanceAct $act,
        string $previousStatus,
        string $currentStatus,
        CarbonImmutable $occurredAt,
        ?int $actorId,
    ): ?ProductionAcceptanceTransitioned {
        $wasAccepted = in_array($previousStatus, self::ACCEPTED_STATES, true);
        $isAccepted = in_array($currentStatus, self::ACCEPTED_STATES, true);
        if ($wasAccepted === $isAccepted) {
            return null;
        }

        try {
            return $this->recordTransition($act, $previousStatus, $currentStatus, $occurredAt, $actorId);
        } catch (InvalidArgumentException $exception) {
            $reason = self::PROJECTION_GAP_REASONS[$exception->getMessage()] ?? null;
            if ($reason === null) {
                throw $exception;
            }

            $act->loadMissing('contract');
            $this->coverageGaps->record(
                $act,
                $isAccepted ? 'acceptance' : 'reversal',
                $reason,
                $occurredAt,
            );

            return null;
        }
    }

    private function recordWork(
        ContractPerformanceAct $act,
        CompletedWork $work,
        string $eventType,
        CarbonImmutable $occurredAt,
        ?int $actorId,
        array &$acceptedSourceQuantities,
    ): ProductionAcceptanceEvent {
        $unit = $work->workType?->measurementUnit;
        if ($unit === null || trim((string) $unit->short_name) === '') {
            throw new InvalidArgumentException('production_acceptance_unit_unavailable');
        }
        $organizationId = (int) $act->contract->organization_id;
        if ($organizationId < 1
            || (int) $work->organization_id !== $organizationId
            || (int) $work->project_id !== (int) $act->project_id
        ) {
            throw new InvalidArgumentException('production_acceptance_scope_mismatch');
        }
        $pivot = $work->pivot;
        if (! $pivot instanceof PerformanceActCompletedWork) {
            throw new InvalidArgumentException('production_acceptance_pivot_unavailable');
        }
        $workId = (int) $work->id;
        $acceptedSourceQuantities[$workId] ??= 0;

        return $this->recordSource(
            act: $act,
            work: $work,
            sourceLineType: 'completed_work',
            sourceLineId: (int) $work->id,
            acceptedQuantity: (string) ($work->pivot->included_quantity ?? '0'),
            unitDimension: (string) ($unit->type ?? 'work'),
            unitCode: (string) $unit->short_name,
            conversionVersion: 'unit_'.(int) $unit->id,
            approvedRate: $eventType === 'accepted'
                ? $this->rates->fromPivot($pivot, $act->currency)
                : null,
            eventType: $eventType,
            occurredAt: $occurredAt,
            actorId: $actorId,
            acceptedQuantityBefore: $acceptedSourceQuantities[$workId],
        );
    }

    private function recordLine(
        ContractPerformanceAct $act,
        PerformanceActLine $line,
        string $eventType,
        CarbonImmutable $occurredAt,
        ?int $actorId,
        array &$acceptedSourceQuantities,
    ): ProductionAcceptanceEvent {
        $work = $line->completedWork;
        $unit = $work?->workType?->measurementUnit;
        if ($line->line_type !== PerformanceActLine::TYPE_COMPLETED_WORK || $work === null || $unit === null) {
            throw new InvalidArgumentException('production_acceptance_line_identity_unavailable');
        }
        if (trim((string) $line->unit) !== '' && (string) $line->unit !== (string) $unit->short_name) {
            throw new InvalidArgumentException('production_acceptance_unit_mismatch');
        }
        $workId = (int) $work->id;
        $acceptedSourceQuantities[$workId] ??= 0;

        return $this->recordSource(
            act: $act,
            work: $work,
            sourceLineType: 'performance_act_line',
            sourceLineId: (int) $line->id,
            acceptedQuantity: (string) $line->quantity,
            unitDimension: (string) ($unit->type ?? 'work'),
            unitCode: (string) $unit->short_name,
            conversionVersion: 'unit_'.(int) $unit->id,
            approvedRate: $eventType === 'accepted'
                ? $this->rates->fromLine($line, $act->currency)
                : null,
            eventType: $eventType,
            occurredAt: $occurredAt,
            actorId: $actorId,
            acceptedQuantityBefore: $acceptedSourceQuantities[$workId],
        );
    }

    private function recordSource(
        ContractPerformanceAct $act,
        CompletedWork $work,
        string $sourceLineType,
        int $sourceLineId,
        string $acceptedQuantity,
        string $unitDimension,
        string $unitCode,
        string $conversionVersion,
        ?ApprovedAcceptanceRate $approvedRate,
        string $eventType,
        CarbonImmutable $occurredAt,
        ?int $actorId,
        int &$acceptedQuantityBefore,
    ): ProductionAcceptanceEvent {
        $organizationId = (int) $act->contract->organization_id;
        if ($organizationId < 1
            || (int) $work->organization_id !== $organizationId
            || (int) $work->project_id !== (int) $act->project_id
        ) {
            throw new InvalidArgumentException('production_acceptance_scope_mismatch');
        }
        $acceptedQuantity = $this->decimal($acceptedQuantity);
        $acceptedQuantityScaled = AcceptedProductionQuantity::scaled(
            $acceptedQuantity,
            'production_acceptance_quantity_invalid',
        );
        if ($acceptedQuantityScaled <= 0) {
            throw new InvalidArgumentException('production_acceptance_quantity_zero');
        }
        $delta = $eventType === 'reversed' ? '-'.$acceptedQuantity : $acceptedQuantity;
        $sourcePlannedQuantity = $this->decimal((string) ($work->quantity ?? '0'));
        $sourceReportedQuantity = $this->decimal((string) ($work->completed_quantity ?? $work->quantity ?? '0'));
        $plannedQuantity = $sourcePlannedQuantity;
        $reportedQuantity = $sourceReportedQuantity;
        $workId = (int) $work->id;
        $taskId = $work->schedule_task_id === null ? null : (int) $work->schedule_task_id;
        $wbsCode = $work->scheduleTask?->wbs_code;
        $additionalInfo = is_array($work->additional_info) ? $work->additional_info : [];
        $zone = isset($additionalInfo['zone']) && is_scalar($additionalInfo['zone'])
            ? trim((string) $additionalInfo['zone'])
            : null;
        $contractorId = $work->contractor_id === null ? null : (int) $work->contractor_id;
        if ($eventType === 'accepted' && $approvedRate === null) {
            throw new InvalidArgumentException('production_acceptance_rate_unavailable');
        }
        $latestEvent = ProductionAcceptanceEvent::query()
            ->where('organization_id', $organizationId)
            ->where('performance_act_id', $act->id)
            ->where('source_line_type', $sourceLineType)
            ->where('source_line_id', $sourceLineId)
            ->orderByDesc('transition_version')
            ->first();
        if ($latestEvent?->event_type === $eventType) {
            if ($eventType === 'reversed') {
                return $latestEvent;
            }
            $this->assertRepeatedEventMatches(
                $latestEvent,
                $act,
                $work,
                $delta,
                $sourceLineType,
                $sourceLineId,
                $unitDimension,
                $unitCode,
                $conversionVersion,
                $approvedRate,
            );

            return $latestEvent;
        }
        if ($eventType === 'accepted') {
            $activeAcceptedQuantity = $this->activeAcceptedQuantity($organizationId, $workId);
            if ($activeAcceptedQuantity < 0) {
                throw new InvalidArgumentException('production_acceptance_active_quantity_invalid');
            }
            $reportedQuantityScaled = AcceptedProductionQuantity::scaled(
                $sourceReportedQuantity,
                'production_acceptance_quantity_invalid',
            );
            if ($acceptedQuantityBefore < 0
                || $acceptedQuantityScaled > $reportedQuantityScaled - $acceptedQuantityBefore
            ) {
                throw new BusinessLogicException(
                    trans_message('act_reports.accepted_quantity_exceeded'),
                    422,
                );
            }
            if ($activeAcceptedQuantity > 0) {
                $plannedQuantity = '0.000';
                $reportedQuantity = '0.000';
            }
        }
        $acceptedEvent = null;
        if ($eventType === 'reversed') {
            $acceptedEvent = $latestEvent?->event_type === 'reversed'
                ? ProductionAcceptanceEvent::query()->find($latestEvent->reverses_event_id)
                : $latestEvent;
        }
        if ($eventType === 'reversed' && $acceptedEvent?->event_type !== 'accepted') {
            throw new InvalidArgumentException('production_acceptance_reversal_without_acceptance');
        }
        if ($eventType === 'reversed') {
            $reversal = $this->reversals->fromAccepted($acceptedEvent);
            $delta = $reversal['accepted_quantity_delta'];
            $unitDimension = $reversal['unit_dimension'];
            $unitCode = $reversal['unit_code'];
            $conversionVersion = $reversal['conversion_version'];
            $approvedRate = $reversal['approved_rate'];
            $workId = $reversal['work_id'];
            $taskId = $reversal['task_id'];
            $wbsCode = $reversal['wbs_code'];
            $zone = $reversal['zone'];
            $contractorId = $reversal['contractor_id'];
            $remainingAcceptedQuantity = $this->activeAcceptedQuantity($organizationId, $workId)
                + AcceptedProductionQuantity::scaled(
                    $delta,
                    'production_acceptance_quantity_invalid',
                );
            if ($remainingAcceptedQuantity < 0) {
                throw new InvalidArgumentException('production_acceptance_active_quantity_invalid');
            }
            if ($remainingAcceptedQuantity === 0) {
                $activeBasis = $this->activeBasis($organizationId, $workId);
                if ($activeBasis['planned'] < 0 || $activeBasis['reported'] < 0) {
                    throw new InvalidArgumentException('production_acceptance_active_quantity_invalid');
                }
                $plannedQuantity = AcceptedProductionQuantity::decimal(
                    -$activeBasis['planned'],
                );
                $reportedQuantity = AcceptedProductionQuantity::decimal(
                    -$activeBasis['reported'],
                );
            } else {
                $plannedQuantity = '0.000';
                $reportedQuantity = '0.000';
            }
        }
        if (! $approvedRate instanceof ApprovedAcceptanceRate) {
            throw new InvalidArgumentException('production_acceptance_rate_unavailable');
        }
        $version = $latestEvent === null ? 1 : (int) $latestEvent->transition_version + 1;
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'accepted_quantity_delta' => $delta,
            'approved_rate_minor' => $approvedRate->minor,
            'contractor_id' => $contractorId,
            'currency' => $approvedRate->currency,
            'currency_source' => $approvedRate->source,
            'event_type' => $eventType,
            'performance_act_id' => (int) $act->id,
            'planned_quantity' => $plannedQuantity,
            'recognized_at' => $occurredAt->format(DATE_ATOM),
            'reported_quantity' => $reportedQuantity,
            'source_line_id' => $sourceLineId,
            'source_line_type' => $sourceLineType,
            'task_id' => $taskId,
            'transition_version' => $version,
            'unit_code' => $unitCode,
            'unit_dimension' => $unitDimension,
            'conversion_version' => $conversionVersion,
            'wbs_code' => $wbsCode,
            'work_id' => $workId,
            'zone' => $zone,
        ]));

        $existing = ProductionAcceptanceEvent::query()
            ->where('organization_id', $organizationId)
            ->where('performance_act_id', $act->id)
            ->where('source_line_type', $sourceLineType)
            ->where('source_line_id', $sourceLineId)
            ->where('transition_version', $version)
            ->where('event_type', $eventType)
            ->first();
        if ($existing !== null) {
            if (! hash_equals((string) $existing->source_hash, $sourceHash)) {
                throw new InvalidArgumentException('production_acceptance_event_immutable');
            }

            return $existing;
        }

        try {
            $created = DB::transaction(fn (): ProductionAcceptanceEvent => ProductionAcceptanceEvent::query()->create([
                'organization_id' => $organizationId,
                'project_id' => (int) $act->project_id,
                'contract_id' => (int) $act->contract_id,
                'performance_act_id' => (int) $act->id,
                'source_line_type' => $sourceLineType,
                'source_line_id' => $sourceLineId,
                'work_id' => $workId,
                'task_id' => $taskId,
                'wbs_code' => $wbsCode,
                'zone' => $zone,
                'contractor_id' => $contractorId,
                'transition_version' => $version,
                'event_type' => $eventType,
                'reverses_event_id' => $acceptedEvent?->id,
                'accepted_quantity_delta' => $delta,
                'planned_quantity' => $plannedQuantity,
                'reported_quantity' => $reportedQuantity,
                'unit_dimension' => $unitDimension,
                'unit_code' => $unitCode,
                'conversion_version' => $conversionVersion,
                'approved_rate_minor' => $approvedRate->minor,
                'currency' => $approvedRate->currency,
                'currency_source' => $approvedRate->source,
                'recognized_at' => $occurredAt,
                'actor_id' => $actorId,
                'source_hash' => $sourceHash,
                'evidence_refs' => [
                    ['type' => 'performance_act', 'id' => (int) $act->id, 'project_id' => (int) $act->project_id],
                    ['type' => $sourceLineType, 'id' => $sourceLineId, 'project_id' => (int) $act->project_id],
                    ['type' => 'completed_work', 'id' => $workId, 'project_id' => (int) $act->project_id],
                    ...($work->journal_entry_id === null ? [] : [[
                        'type' => 'construction_journal_entry',
                        'id' => (int) $work->journal_entry_id,
                        'project_id' => (int) $act->project_id,
                    ]]),
                ],
            ]));
            if ($eventType === 'accepted') {
                $acceptedQuantityBefore += $acceptedQuantityScaled;
            }

            return $created;
        } catch (QueryException $exception) {
            $concurrent = ProductionAcceptanceEvent::query()
                ->where('organization_id', $organizationId)
                ->where('performance_act_id', $act->id)
                ->where('source_line_type', $sourceLineType)
                ->where('source_line_id', $sourceLineId)
                ->where('transition_version', $version)
                ->where('event_type', $eventType)
                ->first();
            if ($concurrent === null) {
                throw $exception;
            }
            if (! hash_equals((string) $concurrent->source_hash, $sourceHash)) {
                throw new InvalidArgumentException('production_acceptance_event_immutable');
            }
            if ($eventType === 'accepted') {
                $acceptedQuantityBefore += $acceptedQuantityScaled;
            }

            return $concurrent;
        }
    }

    private function assertRepeatedEventMatches(
        ProductionAcceptanceEvent $event,
        ContractPerformanceAct $act,
        CompletedWork $work,
        string $delta,
        string $sourceLineType,
        int $sourceLineId,
        string $unitDimension,
        string $unitCode,
        string $conversionVersion,
        ApprovedAcceptanceRate $approvedRate,
    ): void {
        $this->identity->assertMatches($event, [
            'accepted_quantity_delta' => $delta,
            'approved_rate_minor' => $approvedRate->minor,
            'contract_id' => (int) $act->contract_id,
            'performance_act_id' => (int) $act->id,
            'planned_quantity' => $this->decimal((string) $event->planned_quantity),
            'project_id' => (int) $act->project_id,
            'reported_quantity' => $this->decimal((string) $event->reported_quantity),
            'source_line_id' => $sourceLineId,
            'source_line_type' => $sourceLineType,
            'unit_code' => $unitCode,
            'unit_dimension' => $unitDimension,
            'conversion_version' => $conversionVersion,
            'currency' => $approvedRate->currency,
            'currency_source' => $approvedRate->source,
            'work_id' => (int) $work->id,
        ]);
    }

    private function decimal(string $value): string
    {
        return AcceptedProductionQuantity::normalize(
            $value,
            'production_acceptance_quantity_invalid',
        );
    }

    private function activeAcceptedQuantity(int $organizationId, int $workId): int
    {
        $quantity = 0;
        $deltas = ProductionAcceptanceEvent::query()
            ->where('organization_id', $organizationId)
            ->where('work_id', $workId)
            ->orderBy('id')
            ->pluck('accepted_quantity_delta');
        foreach ($deltas as $delta) {
            $quantity += AcceptedProductionQuantity::scaled(
                (string) $delta,
                'production_acceptance_quantity_invalid',
            );
        }

        return $quantity;
    }

    /**
     * @param list<int> $workIds
     * @return array<int, int>
     */
    private function activeAcceptedQuantities(
        int $organizationId,
        array $workIds,
        int $performanceActId,
    ): array {
        $quantities = [];
        $events = ProductionAcceptanceEvent::query()
            ->where('organization_id', $organizationId)
            ->where('performance_act_id', $performanceActId)
            ->whereIn('work_id', $workIds)
            ->orderBy('id')
            ->get(['work_id', 'accepted_quantity_delta']);
        foreach ($events as $event) {
            $workId = (int) $event->work_id;
            $quantities[$workId] = ($quantities[$workId] ?? 0) + AcceptedProductionQuantity::scaled(
                (string) $event->accepted_quantity_delta,
                'production_acceptance_quantity_invalid',
            );
        }

        return $quantities;
    }

    /** @return array{planned: int, reported: int} */
    private function activeBasis(int $organizationId, int $workId): array
    {
        $basis = ['planned' => 0, 'reported' => 0];
        $events = ProductionAcceptanceEvent::query()
            ->where('organization_id', $organizationId)
            ->where('work_id', $workId)
            ->orderBy('id')
            ->get(['planned_quantity', 'reported_quantity']);
        foreach ($events as $event) {
            $basis['planned'] += AcceptedProductionQuantity::scaled(
                (string) $event->planned_quantity,
                'production_acceptance_quantity_invalid',
            );
            $basis['reported'] += AcceptedProductionQuantity::scaled(
                (string) $event->reported_quantity,
                'production_acceptance_quantity_invalid',
            );
        }

        return $basis;
    }
}
