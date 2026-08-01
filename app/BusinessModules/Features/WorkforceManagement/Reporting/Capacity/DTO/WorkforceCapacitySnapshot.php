<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WorkforceCapacitySnapshot
{
    public function __construct(
        public WorkforceCapacityCohortKey $key,
        public string $captureKind,
        public DateTimeImmutable $capturedAt,
        public ?int $actorUserId,
        public ?string $serviceActor,
        public string $schemaVersion,
        public string $formulaVersion,
        public WorkforceCapacityPolicyDefinition $policy,
        public ?string $authorizedFte,
        public string $assignedFte,
        public string $availableFte,
        public string $approvedUnavailabilityFte,
        public ?string $openFte,
        public ?string $overallocatedFte,
        public ?string $scheduledHours,
        public string $capacityStatus,
        public array $gapCodes,
        public array $sourceCounts,
        public int $itemCount,
        public string $itemsHash,
        public string $itemsCanonical,
        public string $stateHash,
        public string $stateCanonical,
        public string $sourceHash,
        public string $sourceCanonical,
        public array $items,
        public string $semanticLabel = 'planned_capacity',
    ) {
        if (! in_array($this->captureKind, $this->policy->captureKinds, true)
            || ($this->captureKind === 'manual_recompute' && ($this->actorUserId === null || $this->actorUserId < 1))
            || ($this->captureKind !== 'manual_recompute' && ($this->serviceActor === null || trim($this->serviceActor) === ''))
            || $this->capturedAt->getOffset() !== 0
            || ! in_array($this->capacityStatus, ['gap', 'understaffed', 'balanced', 'overallocated', 'unavailable'], true)
            || $this->itemCount !== count($this->items)) {
            throw new InvalidArgumentException('workforce_capacity_snapshot_contract_invalid');
        }

        foreach ([$this->itemsHash, $this->stateHash, $this->sourceHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new InvalidArgumentException('workforce_capacity_snapshot_hash_invalid');
            }
        }

        if (! hash_equals($this->itemsHash, hash('sha256', $this->itemsCanonical))
            || ! hash_equals($this->stateHash, hash('sha256', $this->stateCanonical))
            || ! hash_equals($this->sourceHash, hash('sha256', $this->sourceCanonical))) {
            throw new InvalidArgumentException('workforce_capacity_snapshot_canonical_hash_mismatch');
        }
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'organizationId' => $this->key->organizationId,
            'asOfDate' => $this->key->asOfDate,
            'monthStart' => $this->key->monthStart,
            'staffUnitId' => $this->key->staffUnitId,
            'projectId' => $this->key->projectId,
            default => throw new InvalidArgumentException('workforce_capacity_snapshot_property_invalid'),
        };
    }

    public function toPersistence(): array
    {
        return [
            ...$this->key->canonical(),
            'capture_kind' => $this->captureKind,
            'captured_at' => $this->capturedAt->format('Y-m-d\TH:i:s.uP'),
            'actor_user_id' => $this->actorUserId,
            'service_actor' => $this->serviceActor,
            'source_schema_version' => $this->schemaVersion,
            'formula_version' => $this->formulaVersion,
            'policy_version' => $this->policy->version,
            'policy_hash' => $this->policy->hash(),
            'policy_definition' => $this->policy->canonical(),
            'policy_canonical' => json_encode($this->policy->canonical(), JSON_THROW_ON_ERROR),
            'authorized_fte' => $this->authorizedFte,
            'assigned_fte' => $this->assignedFte,
            'available_fte' => $this->availableFte,
            'approved_unavailability_fte' => $this->approvedUnavailabilityFte,
            'open_fte' => $this->openFte,
            'overallocated_fte' => $this->overallocatedFte,
            'scheduled_hours' => $this->scheduledHours,
            'capacity_status' => $this->capacityStatus,
            'gap_codes' => $this->gapCodes,
            'source_counts' => $this->sourceCounts,
            'item_count' => $this->itemCount,
            'items_hash' => $this->itemsHash,
            'items_canonical' => $this->itemsCanonical,
            'state_hash' => $this->stateHash,
            'state_canonical' => $this->stateCanonical,
            'source_hash' => $this->sourceHash,
            'source_canonical' => $this->sourceCanonical,
            'semantic_label' => $this->semanticLabel,
        ];
    }
}
