<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionEventUniverse;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionLifecycleCompleteness;
use App\Support\Reporting\ReportSourceReadinessFactory;

final readonly class AcceptedProductionReadinessProbe implements ReportSourceReadinessProbe
{
    private AcceptedProductionLifecycleCompleteness $completeness;

    public function __construct(
        private ReportSourceReadinessFactory $readiness,
        private AcceptedProductionEventUniverse $universe,
        ?AcceptedProductionLifecycleCompleteness $completeness = null,
    ) {
        $this->completeness = $completeness ?? new AcceptedProductionLifecycleCompleteness;
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
        $universe = $this->universe->resolve($context->scope, $query);
        $events = $universe['events'];
        $eligible = $events->map(static fn (ProductionAcceptanceEvent $event): array => [
            'event_id' => (int) $event->id,
            'source_hash' => (string) $event->source_hash,
        ])->all();
        $projected = $events
            ->filter(static fn (ProductionAcceptanceEvent $event): bool => $event->approved_rate_minor !== null
                && preg_match('/^[A-Z]{3}$/D', (string) $event->currency) === 1
                && trim((string) $event->currency_source) !== '')
            ->map(static fn (ProductionAcceptanceEvent $event): array => [
                'event_id' => (int) $event->id,
                'source_hash' => (string) $event->source_hash,
            ])
            ->values()
            ->all();
        $gapCount = count($eligible) - count($projected);

        foreach ($this->completeness->inspect(
            $context->scope,
            $query->asOf,
            $events,
            $universe,
        ) as $gap) {
            $eligible[] = $gap;
            $gapCount++;
        }

        $watermark = 'event:'.(int) ($events->max('id') ?? 0);

        return $this->readiness->make($eligible, $projected, $gapCount, 0, $watermark);
    }
}
