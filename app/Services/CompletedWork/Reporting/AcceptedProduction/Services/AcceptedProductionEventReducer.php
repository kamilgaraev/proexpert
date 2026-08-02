<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionUniverseEntry;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\ProductionAcceptanceFact;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Support\Reporting\CanonicalLineageAccumulator;
use InvalidArgumentException;
use LogicException;

final class AcceptedProductionEventReducer
{
    private ?ProductionAcceptanceEvent $first = null;

    private ?ProductionAcceptanceEvent $latest = null;

    private int $acceptedQuantity = 0;

    private CanonicalLineageAccumulator $lineage;

    public function __construct(private readonly array $candidate)
    {
        if ((int) ($candidate['performance_act_id'] ?? 0) < 1
            || (int) ($candidate['source_line_id'] ?? 0) < 1
            || trim((string) ($candidate['source_line_type'] ?? '')) === ''
        ) {
            throw new InvalidArgumentException('accepted_production_candidate_invalid');
        }
        $this->lineage = new CanonicalLineageAccumulator;
    }

    public function append(ProductionAcceptanceEvent $event): void
    {
        if ($this->key($event) !== $this->candidateKey()
            || (int) $event->id < 1
            || (int) $event->transition_version < 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) $event->source_hash) !== 1
        ) {
            throw new InvalidArgumentException('accepted_production_event_group_invalid');
        }
        if ($this->first !== null) {
            $this->assertStableIdentity($this->first, $event);
        }
        if ($event->approved_rate_minor === null
            || preg_match('/^[A-Z]{3}$/D', (string) $event->currency) !== 1
            || trim((string) $event->currency_source) === ''
        ) {
            throw new InvalidArgumentException('accepted_production_rate_identity_missing');
        }

        $this->lineage->append(
            (int) $event->transition_version,
            (int) $event->id,
            self::eventIdentity($event),
        );
        $this->acceptedQuantity += $this->scaled((string) $event->accepted_quantity_delta);
        $this->first ??= $event;
        $this->latest = $event;
    }

    public function finish(): AcceptedProductionUniverseEntry
    {
        $first = $this->first
            ?? throw new LogicException('accepted_production_event_group_invalid');
        $latest = $this->latest
            ?? throw new LogicException('accepted_production_event_group_invalid');

        return new AcceptedProductionUniverseEntry(
            candidate: $this->candidate,
            latest: $latest,
            fact: new ProductionAcceptanceFact(
                plannedQuantity: $this->decimal($this->scaled((string) $first->planned_quantity)),
                reportedQuantity: $this->decimal($this->scaled((string) $first->reported_quantity)),
                acceptedQuantityDelta: $this->decimal($this->acceptedQuantity),
                unitDimension: (string) $first->unit_dimension,
                unitCode: (string) $first->unit_code,
                conversionVersion: (string) $first->conversion_version,
                approvedRateMinor: (int) $first->approved_rate_minor,
                currency: (string) $first->currency,
                currencySource: (string) $first->currency_source,
            ),
            lineage: $this->lineage->finish(),
        );
    }

    public static function eventIdentity(ProductionAcceptanceEvent $event): array
    {
        return [
            'accepted_quantity_delta' => (string) $event->accepted_quantity_delta,
            'approved_rate_minor' => $event->approved_rate_minor,
            'currency' => $event->currency,
            'currency_source' => $event->currency_source,
            'event_type' => (string) $event->event_type,
            'id' => (int) $event->id,
            'performance_act_id' => (int) $event->performance_act_id,
            'recognized_at' => $event->recognized_at->format(DATE_ATOM),
            'source_hash' => (string) $event->source_hash,
            'source_line_id' => (int) $event->source_line_id,
            'source_line_type' => (string) $event->source_line_type,
            'transition_version' => (int) $event->transition_version,
        ];
    }

    private function assertStableIdentity(
        ProductionAcceptanceEvent $first,
        ProductionAcceptanceEvent $event,
    ): void {
        foreach ([
            'project_id',
            'performance_act_id',
            'source_line_type',
            'source_line_id',
            'work_id',
            'task_id',
            'wbs_code',
            'zone',
            'contractor_id',
            'unit_dimension',
            'unit_code',
            'conversion_version',
            'currency',
            'currency_source',
            'approved_rate_minor',
        ] as $field) {
            if ($event->getAttribute($field) !== $first->getAttribute($field)) {
                throw new InvalidArgumentException('accepted_production_event_identity_changed');
            }
        }
    }

    private function candidateKey(): array
    {
        return [
            (int) $this->candidate['performance_act_id'],
            (string) $this->candidate['source_line_type'],
            (int) $this->candidate['source_line_id'],
        ];
    }

    private function key(ProductionAcceptanceEvent $event): array
    {
        return [
            (int) $event->performance_act_id,
            (string) $event->source_line_type,
            (int) $event->source_line_id,
        ];
    }

    private function scaled(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/D', $unsigned, $matches) !== 1) {
            throw new InvalidArgumentException('accepted_production_quantity_invalid');
        }
        $scaled = ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');

        return $negative ? -$scaled : $scaled;
    }

    private function decimal(int $scaled): string
    {
        $absolute = abs($scaled);
        $value = intdiv($absolute, 1000).'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);

        return $scaled < 0 ? '-'.$value : $value;
    }
}
