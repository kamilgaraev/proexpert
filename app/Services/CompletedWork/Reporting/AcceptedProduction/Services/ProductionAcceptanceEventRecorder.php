<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Models\CompletedWork;
use App\Models\ContractPerformanceAct;
use App\Models\PerformanceActLine;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Events\ProductionAcceptanceTransitioned;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ProductionAcceptanceEventRecorder
{
    private const ACCEPTED_STATES = ['approved', 'signed'];

    public function __construct(private ProductionAcceptanceEventIdentity $identity)
    {
    }

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
        if ($act->contract === null || ($act->lines->isEmpty() && $act->completedWorks->isEmpty())) {
            throw new InvalidArgumentException('production_acceptance_source_unavailable');
        }

        return DB::transaction(function () use ($act, $eventType, $occurredAt, $actorId): ProductionAcceptanceTransitioned {
            $eventIds = [];
            if ($act->lines->isNotEmpty()) {
                foreach ($act->lines as $line) {
                    $eventIds[] = (int) $this->recordLine($act, $line, $eventType, $occurredAt, $actorId)->id;
                }
            } else {
                foreach ($act->completedWorks as $work) {
                    $eventIds[] = (int) $this->recordWork($act, $work, $eventType, $occurredAt, $actorId)->id;
                }
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

    private function recordWork(
        ContractPerformanceAct $act,
        CompletedWork $work,
        string $eventType,
        CarbonImmutable $occurredAt,
        ?int $actorId,
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

        return $this->recordSource(
            act: $act,
            work: $work,
            sourceLineType: 'completed_work',
            sourceLineId: (int) $work->id,
            acceptedQuantity: (string) ($work->pivot->included_quantity ?? '0'),
            unitDimension: (string) ($unit->type ?? 'work'),
            unitCode: (string) $unit->short_name,
            conversionVersion: 'unit_'.(int) $unit->id,
            eventType: $eventType,
            occurredAt: $occurredAt,
            actorId: $actorId,
        );
    }

    private function recordLine(
        ContractPerformanceAct $act,
        PerformanceActLine $line,
        string $eventType,
        CarbonImmutable $occurredAt,
        ?int $actorId,
    ): ProductionAcceptanceEvent {
        $work = $line->completedWork;
        $unit = $work?->workType?->measurementUnit;
        if ($line->line_type !== PerformanceActLine::TYPE_COMPLETED_WORK || $work === null || $unit === null) {
            throw new InvalidArgumentException('production_acceptance_line_identity_unavailable');
        }
        if (trim((string) $line->unit) !== '' && (string) $line->unit !== (string) $unit->short_name) {
            throw new InvalidArgumentException('production_acceptance_unit_mismatch');
        }

        return $this->recordSource(
            act: $act,
            work: $work,
            sourceLineType: 'performance_act_line',
            sourceLineId: (int) $line->id,
            acceptedQuantity: (string) $line->quantity,
            unitDimension: (string) ($unit->type ?? 'work'),
            unitCode: (string) $unit->short_name,
            conversionVersion: 'unit_'.(int) $unit->id,
            eventType: $eventType,
            occurredAt: $occurredAt,
            actorId: $actorId,
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
        string $eventType,
        CarbonImmutable $occurredAt,
        ?int $actorId,
    ): ProductionAcceptanceEvent {
        $organizationId = (int) $act->contract->organization_id;
        if ($organizationId < 1
            || (int) $work->organization_id !== $organizationId
            || (int) $work->project_id !== (int) $act->project_id
        ) {
            throw new InvalidArgumentException('production_acceptance_scope_mismatch');
        }
        $acceptedQuantity = $this->decimal($acceptedQuantity);
        if ($acceptedQuantity === '0.000') {
            throw new InvalidArgumentException('production_acceptance_quantity_zero');
        }
        $delta = $eventType === 'reversed' ? '-'.$acceptedQuantity : $acceptedQuantity;
        $latestEvent = ProductionAcceptanceEvent::query()
            ->where('organization_id', $organizationId)
            ->where('performance_act_id', $act->id)
            ->where('source_line_type', $sourceLineType)
            ->where('source_line_id', $sourceLineId)
            ->orderByDesc('transition_version')
            ->first();
        if ($latestEvent?->event_type === $eventType) {
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
            );

            return $latestEvent;
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
        $version = $latestEvent === null ? 1 : (int) $latestEvent->transition_version + 1;
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'accepted_quantity_delta' => $delta,
            'event_type' => $eventType,
            'performance_act_id' => (int) $act->id,
            'recognized_at' => $occurredAt->format(DATE_ATOM),
            'source_line_id' => $sourceLineId,
            'source_line_type' => $sourceLineType,
            'transition_version' => $version,
            'unit_code' => $unitCode,
            'unit_dimension' => $unitDimension,
            'conversion_version' => $conversionVersion,
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
            if (!hash_equals((string) $existing->source_hash, $sourceHash)) {
                throw new InvalidArgumentException('production_acceptance_event_immutable');
            }

            return $existing;
        }

        try {
            return DB::transaction(fn (): ProductionAcceptanceEvent => ProductionAcceptanceEvent::query()->create([
                'organization_id' => $organizationId,
                'project_id' => (int) $act->project_id,
                'contract_id' => (int) $act->contract_id,
                'performance_act_id' => (int) $act->id,
                'source_line_type' => $sourceLineType,
                'source_line_id' => $sourceLineId,
                'work_id' => (int) $work->id,
                'task_id' => $work->schedule_task_id === null ? null : (int) $work->schedule_task_id,
                'wbs_code' => $work->scheduleTask?->wbs_code,
                'zone' => null,
                'contractor_id' => $work->contractor_id === null ? null : (int) $work->contractor_id,
                'transition_version' => $version,
                'event_type' => $eventType,
                'reverses_event_id' => $acceptedEvent?->id,
                'accepted_quantity_delta' => $delta,
                'planned_quantity' => $this->decimal((string) ($work->quantity ?? '0')),
                'reported_quantity' => $this->decimal((string) ($work->completed_quantity ?? $work->quantity ?? '0')),
                'unit_dimension' => $unitDimension,
                'unit_code' => $unitCode,
                'conversion_version' => $conversionVersion,
                'approved_rate_minor' => null,
                'currency' => null,
                'currency_source' => null,
                'recognized_at' => $occurredAt,
                'actor_id' => $actorId,
                'source_hash' => $sourceHash,
                'evidence_refs' => [
                    ['type' => 'performance_act', 'id' => (int) $act->id],
                    ['type' => $sourceLineType, 'id' => $sourceLineId],
                    ['type' => 'completed_work', 'id' => (int) $work->id],
                ],
            ]));
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
            $this->assertRepeatedEventMatches(
                $concurrent,
                $act,
                $work,
                $delta,
                $sourceLineType,
                $sourceLineId,
                $unitDimension,
                $unitCode,
                $conversionVersion,
            );

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
    ): void {
        $this->identity->assertMatches($event, [
            'accepted_quantity_delta' => $delta,
            'contract_id' => (int) $act->contract_id,
            'performance_act_id' => (int) $act->id,
            'planned_quantity' => $this->decimal((string) ($work->quantity ?? '0')),
            'project_id' => (int) $act->project_id,
            'reported_quantity' => $this->decimal((string) ($work->completed_quantity ?? $work->quantity ?? '0')),
            'source_line_id' => $sourceLineId,
            'source_line_type' => $sourceLineType,
            'unit_code' => $unitCode,
            'unit_dimension' => $unitDimension,
            'conversion_version' => $conversionVersion,
            'work_id' => (int) $work->id,
        ]);
    }

    private function decimal(string $value): string
    {
        if (preg_match('/^\+?(\d+)(?:\.(\d{1,4}))?$/D', trim($value), $matches) !== 1) {
            throw new InvalidArgumentException('production_acceptance_quantity_invalid');
        }

        $fraction = $matches[2] ?? '';
        if (strlen($fraction) === 4 && $fraction[3] !== '0') {
            throw new InvalidArgumentException('production_acceptance_quantity_precision_unsupported');
        }

        return ltrim($matches[1], '0') === ''
            ? '0.'.str_pad(substr($fraction, 0, 3), 3, '0')
            : ltrim($matches[1], '0').'.'.str_pad(substr($fraction, 0, 3), 3, '0');
    }
}
