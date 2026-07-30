<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Models\ContractPerformanceAct;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class AcceptedProductionLifecycleCompleteness
{
    public function inspect(
        ReportScope $scope,
        DateTimeImmutable $asOf,
        Collection $events,
        ?array $universe = null,
    ): array {
        $latestEvents = $events
            ->groupBy(static fn (ProductionAcceptanceEvent $event): string => self::key(
                (int) $event->performance_act_id,
                (string) $event->source_line_type,
                (int) $event->source_line_id,
            ))
            ->map(static fn (Collection $lineEvents): ?ProductionAcceptanceEvent => $lineEvents->last());
        $acts = ContractPerformanceAct::query()
            ->whereIn('project_id', $universe['project_ids'] ?? $scope->projectIds)
            ->where('created_at', '<=', $asOf)
            ->whereHas('contract', static fn ($builder) => $builder
                ->where('organization_id', $scope->organizationId))
            ->where(static function ($builder) use ($asOf): void {
                $builder
                    ->where(static fn ($signed) => $signed
                        ->whereNotNull('signed_at')
                        ->where('signed_at', '<=', $asOf))
                    ->orWhere(static fn ($approved) => $approved
                        ->whereNotNull('approval_date')
                        ->where('approval_date', '<=', $asOf));
            })
            ->when(
                ($universe['act_ids'] ?? null) !== null,
                static fn ($builder) => $builder->whereIn('id', $universe['act_ids']),
            )
            ->with(['lines:id,performance_act_id,completed_work_id', 'completedWorks:id'])
            ->orderBy('id')
            ->get();

        $eventKeys = $events
            ->mapWithKeys(static fn (ProductionAcceptanceEvent $event): array => [
                self::key(
                    (int) $event->performance_act_id,
                    (string) $event->source_line_type,
                    (int) $event->source_line_id,
                ) => true,
            ])
            ->all();
        $gaps = [];
        foreach ($acts as $act) {
            $sourceLines = array_values(array_filter(
                $this->sourceLines($act),
                static function (array $sourceLine) use ($universe, $eventKeys, $act): bool {
                    if (($universe['act_line_ids'] ?? null) !== null
                        && ($sourceLine['type'] !== 'performance_act_line'
                            || ! in_array($sourceLine['id'], $universe['act_line_ids'], true))
                    ) {
                        return false;
                    }
                    if (($universe['work_ids'] ?? null) !== null
                        && ! in_array($sourceLine['work_id'], $universe['work_ids'], true)
                    ) {
                        return false;
                    }

                    return ! ($universe['restrict_to_event_keys'] ?? false)
                        || isset($eventKeys[self::key(
                            (int) $act->id,
                            $sourceLine['type'],
                            $sourceLine['id'],
                        )]);
                },
            ));
            if ($sourceLines === []) {
                if ($universe === null || ! ($universe['restrict_source_lines'] ?? false)) {
                    $gaps[] = [
                        'performance_act_id' => (int) $act->id,
                        'reason' => 'accepted_production_owner_history_unproven',
                    ];
                }

                continue;
            }
            $rejectedAsOf = $act->rejected_at !== null
                && $act->rejected_at->lessThanOrEqualTo($asOf);
            foreach ($sourceLines as $sourceLine) {
                $latest = $latestEvents->get(self::key(
                    (int) $act->id,
                    $sourceLine['type'],
                    $sourceLine['id'],
                ));
                if (! $latest instanceof ProductionAcceptanceEvent
                    || ($rejectedAsOf && $latest->event_type !== 'reversed')
                ) {
                    $gaps[] = [
                        'performance_act_id' => (int) $act->id,
                        'reason' => 'accepted_production_owner_history_unproven',
                        'source_line_id' => $sourceLine['id'],
                        'source_line_type' => $sourceLine['type'],
                    ];
                }
            }
        }

        return $gaps;
    }

    public function assertComplete(
        ReportScope $scope,
        DateTimeImmutable $asOf,
        Collection $events,
        ?array $universe = null,
    ): void {
        if ($this->inspect($scope, $asOf, $events, $universe) !== []) {
            throw new InvalidArgumentException('accepted_production_owner_history_unproven');
        }
    }

    private function sourceLines(ContractPerformanceAct $act): array
    {
        $lines = $act->lines->map(static fn ($line): array => [
            'id' => (int) $line->id,
            'type' => 'performance_act_line',
            'work_id' => (int) $line->completed_work_id,
        ]);
        if ($lines->isNotEmpty()) {
            return $lines->all();
        }

        return $act->completedWorks->map(static fn ($work): array => [
            'id' => (int) $work->id,
            'type' => 'completed_work',
            'work_id' => (int) $work->id,
        ])->all();
    }

    private static function key(int $actId, string $sourceLineType, int $sourceLineId): string
    {
        return implode(':', [$actId, $sourceLineType, $sourceLineId]);
    }
}
