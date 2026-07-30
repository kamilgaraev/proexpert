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
        $latestEvents = $events
            ->groupBy(static fn (ProductionAcceptanceEvent $event): string => implode(':', [
                (int) $event->performance_act_id,
                (string) $event->source_line_type,
                (int) $event->source_line_id,
            ]))
            ->map(static fn ($lineEvents): ?ProductionAcceptanceEvent => $lineEvents->last());
        $eligible = array_map(static fn (array $candidate): array => [
            'owner_source_hash' => (string) $candidate['owner_source_hash'],
            'owner_version_id' => (int) $candidate['owner_version_id'],
            'performance_act_id' => (int) $candidate['performance_act_id'],
            'source_line_id' => (int) $candidate['source_line_id'],
            'source_line_type' => (string) $candidate['source_line_type'],
        ], $universe['candidates']);
        foreach ($universe['orphan_events'] as $orphan) {
            $eligible[] = [
                'event_id' => (int) $orphan['event_id'],
                'kind' => 'owner_version_missing',
                'performance_act_id' => (int) $orphan['performance_act_id'],
            ];
        }
        foreach ($universe['legacy_gaps'] as $legacyGap) {
            $eligible[] = [
                'kind' => 'legacy_owner_unprovable',
                'ledger_id' => (int) ($legacyGap['ledger_id'] ?? 0),
                'performance_act_id' => (int) $legacyGap['performance_act_id'],
            ];
        }
        $projected = [];
        foreach ($universe['candidates'] as $candidate) {
            $event = $latestEvents->get(implode(':', [
                (int) $candidate['performance_act_id'],
                (string) $candidate['source_line_type'],
                (int) $candidate['source_line_id'],
            ]));
            if (! $event instanceof ProductionAcceptanceEvent
                || (string) $event->event_type !== (string) $candidate['event_type']
                || $event->approved_rate_minor === null
                || preg_match('/^[A-Z]{3}$/D', (string) $event->currency) !== 1
                || trim((string) $event->currency_source) === ''
            ) {
                continue;
            }
            $projected[] = [
                'event_id' => (int) $event->id,
                'owner_source_hash' => (string) $candidate['owner_source_hash'],
                'source_hash' => (string) $event->source_hash,
            ];
        }
        $gapCount = count($eligible) - count($projected);

        $this->completeness->inspect(
            $context->scope,
            $query->asOf,
            $events,
            $universe,
        );

        $watermark = implode(':', [
            'owner',
            (int) collect($universe['candidates'])->max('owner_version_id'),
            'event',
            (int) ($events->max('id') ?? 0),
        ]);

        return $this->readiness->make($eligible, $projected, $gapCount, 0, $watermark);
    }
}
