<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\Enums\CurrencyCode;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionEventUniverse;
use App\Support\Reporting\DeterministicReadinessAccumulator;

final readonly class AcceptedProductionReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private AcceptedProductionEventUniverse $universe,
    ) {}

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
        $stream = $this->universe->stream($context->scope, $query);
        $readiness = new DeterministicReadinessAccumulator;
        foreach ($stream->entries() as $entry) {
            $event = $entry->latestEvent();
            $candidate = $entry->candidate;
            $readiness->eligible([
                'owner_version_id' => (int) $candidate['owner_version_id'],
                'source_line_id' => (int) $candidate['source_line_id'],
                'source_line_type' => (string) $candidate['source_line_type'],
            ]);
            if ($event === null
                || (string) $event->event_type !== (string) $candidate['event_type']
                || $event->approved_rate_minor === null
                || CurrencyCode::tryFrom((string) $event->currency) === null
                || trim((string) $event->currency_source) === ''
            ) {
                continue;
            }
            $readiness->projected([
                'event_id' => (int) $event->id,
                'source_hash' => (string) $event->source_hash,
            ]);
        }
        foreach ($stream->gaps() as $gap) {
            $readiness->eligible([
                'gap_id' => (int) ($gap['event_id'] ?? $gap['ledger_id'] ?? $gap['performance_act_id'] ?? 0),
                'kind' => (string) ($gap['kind'] ?? 'unproven'),
            ]);
        }

        $watermark = implode(':', [
            'owner',
            $stream->ownerWatermark,
            'member',
            $stream->ownerMemberWatermark,
            'event',
            $stream->eventWatermark,
            'checkpoint',
            $stream->historyBoundary->sourceHash,
        ]);

        return $readiness->finish(
            $stream->gapCount(),
            0,
            $watermark,
        );
    }
}
