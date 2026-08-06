<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\Enums\CurrencyCode;
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

    private int $plannedQuantity = 0;

    private int $reportedQuantity = 0;

    private int $acceptedAmountMinor = 0;

    private CanonicalLineageAccumulator $lineage;

    private array $candidate;

    private array $ownerVersions = [];

    private array $rateIdentities = [];

    public function __construct(array $candidate)
    {
        $this->assertCandidate($candidate);
        $this->candidate = $candidate;
        $this->rememberOwner($candidate);
        $this->lineage = new CanonicalLineageAccumulator;
    }

    public function append(ProductionAcceptanceEvent $event, ?array $candidate = null): void
    {
        if ($candidate !== null) {
            $this->assertCandidate($candidate);
            if ($this->candidateKey() !== $this->candidateKeyFrom($candidate)) {
                throw new InvalidArgumentException('accepted_production_candidate_invalid');
            }
            $this->candidate = $candidate;
            $this->rememberOwner($candidate);
        }
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
            || CurrencyCode::tryFrom((string) $event->currency) === null
            || trim((string) $event->currency_source) === ''
        ) {
            throw new InvalidArgumentException('accepted_production_rate_identity_missing');
        }

        $this->lineage->append(
            (int) $event->transition_version,
            (int) $event->id,
            self::eventIdentity($event),
        );
        $acceptedQuantity = AcceptedProductionQuantity::scaled(
            (string) $event->accepted_quantity_delta,
            'accepted_production_quantity_invalid',
        );
        $this->plannedQuantity += AcceptedProductionQuantity::scaled(
            (string) $event->planned_quantity,
            'accepted_production_quantity_invalid',
        );
        $this->reportedQuantity += AcceptedProductionQuantity::scaled(
            (string) $event->reported_quantity,
            'accepted_production_quantity_invalid',
        );
        $approvedRateMinor = (int) $event->approved_rate_minor;
        $this->acceptedQuantity += $acceptedQuantity;
        $this->acceptedAmountMinor += AcceptedProductionQuantity::multiplyRateMinor(
            $acceptedQuantity,
            $approvedRateMinor,
            'accepted_production_amount_invalid',
        );
        $this->rateIdentities[$approvedRateMinor.':'.(string) $event->currency_source] = true;
        $this->first ??= $event;
        $this->latest = $event;
    }

    private function assertCandidate(array $candidate): void
    {
        if ((int) ($candidate['performance_act_id'] ?? 0) < 1
            || (int) ($candidate['project_id'] ?? 0) < 1
            || (int) ($candidate['source_line_id'] ?? 0) < 1
            || trim((string) ($candidate['source_line_type'] ?? '')) === ''
            || (int) ($candidate['work_id'] ?? 0) < 1
            || (int) ($candidate['owner_version_id'] ?? 0) < 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($candidate['owner_source_hash'] ?? '')) !== 1
        ) {
            throw new InvalidArgumentException('accepted_production_candidate_invalid');
        }
    }

    private function rememberOwner(array $candidate): void
    {
        $this->ownerVersions[(int) $candidate['owner_version_id']] = [
            'id' => (int) $candidate['owner_version_id'],
            'source_hash' => (string) $candidate['owner_source_hash'],
        ];
    }

    public function finish(): AcceptedProductionUniverseEntry
    {
        $latest = $this->latest
            ?? throw new LogicException('accepted_production_event_group_invalid');
        $singleRate = count($this->rateIdentities) === 1;

        return new AcceptedProductionUniverseEntry(
            candidate: [
                ...$this->candidate,
                'owner_versions' => array_values($this->ownerVersions),
            ],
            latest: $latest,
            fact: new ProductionAcceptanceFact(
                plannedQuantity: AcceptedProductionQuantity::decimal($this->plannedQuantity),
                reportedQuantity: AcceptedProductionQuantity::decimal($this->reportedQuantity),
                acceptedQuantityDelta: AcceptedProductionQuantity::decimal($this->acceptedQuantity),
                unitDimension: (string) $latest->unit_dimension,
                unitCode: (string) $latest->unit_code,
                conversionVersion: (string) $latest->conversion_version,
                approvedRateMinor: $singleRate ? (int) $latest->approved_rate_minor : null,
                currency: (string) $latest->currency,
                currencySource: $singleRate
                    ? (string) $latest->currency_source
                    : 'production_acceptance_events.approved_rates',
                acceptedAmountMinor: $this->acceptedAmountMinor,
            ),
            lineage: $this->lineage->finish(),
        );
    }

    public static function eventIdentity(ProductionAcceptanceEvent $event): array
    {
        return [
            'accepted_quantity_delta' => AcceptedProductionQuantity::normalize(
                (string) $event->accepted_quantity_delta,
                'accepted_production_quantity_invalid',
            ),
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
        ] as $field) {
            if ($event->getAttribute($field) !== $first->getAttribute($field)) {
                throw new InvalidArgumentException('accepted_production_event_identity_changed');
            }
        }
    }

    private function candidateKey(): array
    {
        return $this->candidateKeyFrom($this->candidate);
    }

    private function candidateKeyFrom(array $candidate): array
    {
        return [
            (int) $candidate['performance_act_id'],
            (string) $candidate['source_line_type'],
            (int) $candidate['source_line_id'],
            (int) $candidate['work_id'],
        ];
    }

    private function key(ProductionAcceptanceEvent $event): array
    {
        return [
            (int) $event->performance_act_id,
            (string) $event->source_line_type,
            (int) $event->source_line_id,
            (int) $event->work_id,
        ];
    }

}
