<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\Models\ContractPerformanceAct;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Support\Reporting\ReportSourceReadinessFactory;

final readonly class AcceptedProductionReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private ReportSourceReadinessFactory $readiness,
    ) {
    }

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'accepted_production_progress'
            && $definition->formulaVersion === 'accepted_production.v1';
    }

    public function reportCodes(): array
    {
        return ['accepted_production_progress'];
    }

    public function inspect(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSourceReadiness {
        $events = ProductionAcceptanceEvent::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('project_id', $context->scope->projectIds)
            ->where('recognized_at', '<=', $query->asOf)
            ->orderBy('id')
            ->get();
        $eventKeys = $events->mapWithKeys(
            static fn (ProductionAcceptanceEvent $event): array => [self::key($event) => true],
        );
        $laterKeys = ProductionAcceptanceEvent::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('project_id', $context->scope->projectIds)
            ->where('recognized_at', '>', $query->asOf)
            ->get()
            ->mapWithKeys(
                static fn (ProductionAcceptanceEvent $event): array => [self::key($event) => true],
            );
        $eligible = $events->map(static fn (ProductionAcceptanceEvent $event): array => [
            'event_id' => (int) $event->id,
            'source_hash' => (string) $event->source_hash,
        ])->all();
        $projected = $events
            ->filter(static fn (ProductionAcceptanceEvent $event): bool =>
                $event->approved_rate_minor !== null
                && preg_match('/^[A-Z]{3}$/D', (string) $event->currency) === 1
                && trim((string) $event->currency_source) !== '')
            ->map(static fn (ProductionAcceptanceEvent $event): array => [
                'event_id' => (int) $event->id,
                'source_hash' => (string) $event->source_hash,
            ])
            ->values()
            ->all();
        $gapCount = count($eligible) - count($projected);

        $acts = ContractPerformanceAct::query()
            ->whereIn('project_id', $context->scope->projectIds)
            ->where('created_at', '<=', $query->asOf)
            ->whereHas('contract', fn ($builder) => $builder
                ->where('organization_id', $context->scope->organizationId))
            ->where(function ($builder): void {
                $builder
                    ->where('is_approved', true)
                    ->orWhereIn('status', [
                        ContractPerformanceAct::STATUS_APPROVED,
                        ContractPerformanceAct::STATUS_SIGNED,
                    ]);
            })
            ->with(['lines:id,performance_act_id', 'completedWorks:id'])
            ->orderBy('id')
            ->get();
        foreach ($acts as $act) {
            if ($act->signed_at !== null && $act->signed_at->greaterThan($query->asOf)) {
                continue;
            }
            foreach ($this->sourceLines($act) as $sourceLine) {
                $key = implode(':', [(int) $act->id, $sourceLine['type'], $sourceLine['id']]);
                if ($eventKeys->has($key) || $laterKeys->has($key)) {
                    continue;
                }
                $eligible[] = [
                    'performance_act_id' => (int) $act->id,
                    'source_line_id' => $sourceLine['id'],
                    'source_line_type' => $sourceLine['type'],
                ];
                $gapCount++;
            }
        }

        $watermark = 'event:'.(int) ($events->max('id') ?? 0);

        return $this->readiness->make($eligible, $projected, $gapCount, 0, $watermark);
    }

    private function sourceLines(ContractPerformanceAct $act): array
    {
        $lines = $act->lines->map(static fn ($line): array => [
            'id' => (int) $line->id,
            'type' => 'performance_act_line',
        ]);
        if ($lines->isNotEmpty()) {
            return $lines->all();
        }

        return $act->completedWorks->map(static fn ($work): array => [
            'id' => (int) $work->id,
            'type' => 'completed_work',
        ])->all();
    }

    private static function key(ProductionAcceptanceEvent $event): string
    {
        return implode(':', [
            (int) $event->performance_act_id,
            (string) $event->source_line_type,
            (int) $event->source_line_id,
        ]);
    }
}
