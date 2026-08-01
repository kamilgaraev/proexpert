<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WorkforceCapacityFrozenCapturePins
{
    public const SOURCE_SCHEMA_VERSION = 'workforce-capacity-source.v1';

    public const FORMULA_VERSION = 'workforce-capacity-formula.v1';

    public function __construct(
        public WorkforceCapacityCaptureCommand $command,
        public WorkforceCapacityPolicyDefinition $policy,
        public DateTimeImmutable $capturedAt,
        public string $businessDate,
        public string $sourceSchemaVersion = self::SOURCE_SCHEMA_VERSION,
        public string $formulaVersion = self::FORMULA_VERSION,
    ) {
        $businessDate = DateTimeImmutable::createFromFormat('!Y-m-d', $this->businessDate);
        if ($businessDate === false
            || $businessDate->format('Y-m-d') !== $this->businessDate
            || $this->capturedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('workforce_capacity_frozen_pins_invalid');
        }
        if ($this->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || $this->formulaVersion !== self::FORMULA_VERSION) {
            throw new InvalidArgumentException('workforce_capacity_frozen_version_invalid');
        }

        $this->assertNoRestrictedFields([
            'old_state' => $this->pinnedState($this->command->oldState),
            'new_state' => $this->pinnedState($this->command->newState),
        ]);
    }

    public function commandCanonical(): string
    {
        return json_encode($this->canonicalValue([
            'mutation_id' => $this->command->mutationId,
            'organization_id' => $this->command->organizationId,
            'source_type' => $this->command->sourceType,
            'old_state' => $this->pinnedState($this->command->oldState),
            'new_state' => $this->pinnedState($this->command->newState),
            'capture_kind' => $this->command->captureKind,
            'actor_user_id' => $this->command->actorUserId,
            'service_actor' => $this->command->serviceActor,
            'source_schema_version' => $this->sourceSchemaVersion,
            'formula_version' => $this->formulaVersion,
        ]), JSON_THROW_ON_ERROR);
    }

    public function commandHash(): string
    {
        return hash('sha256', $this->commandCanonical());
    }

    public function policyCanonical(): string
    {
        return json_encode($this->canonicalValue($this->policy->canonical()), JSON_THROW_ON_ERROR);
    }

    public function policyHash(): string
    {
        return hash('sha256', $this->policyCanonical());
    }

    public static function fromCanonical(
        string $commandCanonical,
        string $commandHash,
        string $policyCanonical,
        string $policyHash,
        DateTimeImmutable $capturedAt,
        string $businessDate,
        string $sourceSchemaVersion,
        string $formulaVersion,
    ): self {
        if (! hash_equals($commandHash, hash('sha256', $commandCanonical))
            || ! hash_equals($policyHash, hash('sha256', $policyCanonical))) {
            throw new InvalidArgumentException('workforce_capacity_frozen_pins_hash_invalid');
        }
        $command = json_decode($commandCanonical, true, flags: JSON_THROW_ON_ERROR);
        $policy = json_decode($policyCanonical, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($command) || ! is_array($policy)) {
            throw new InvalidArgumentException('workforce_capacity_frozen_pins_canonical_invalid');
        }
        $pins = new self(
            command: new WorkforceCapacityCaptureCommand(
                mutationId: (string) ($command['mutation_id'] ?? ''),
                organizationId: (int) ($command['organization_id'] ?? 0),
                sourceType: (string) ($command['source_type'] ?? ''),
                oldState: isset($command['old_state']) ? (array) $command['old_state'] : null,
                newState: isset($command['new_state']) ? (array) $command['new_state'] : null,
                captureKind: (string) ($command['capture_kind'] ?? ''),
                actorUserId: isset($command['actor_user_id']) ? (int) $command['actor_user_id'] : null,
                serviceActor: isset($command['service_actor']) ? (string) $command['service_actor'] : null,
            ),
            policy: WorkforceCapacityPolicyDefinition::v1((string) ($policy['timezone'] ?? '')),
            capturedAt: $capturedAt,
            businessDate: $businessDate,
            sourceSchemaVersion: $sourceSchemaVersion,
            formulaVersion: $formulaVersion,
        );
        if (! hash_equals($commandHash, $pins->commandHash())
            || ! hash_equals($policyHash, $pins->policyHash())) {
            throw new InvalidArgumentException('workforce_capacity_frozen_pins_canonical_invalid');
        }

        return $pins;
    }

    private function assertNoRestrictedFields(array $value): void
    {
        $restricted = array_fill_keys($this->policy->redactedFields, true);
        $walk = function (mixed $nested) use (&$walk, $restricted): void {
            if (! is_array($nested)) {
                return;
            }
            foreach ($nested as $key => $item) {
                if (is_string($key) && isset($restricted[strtolower($key)])) {
                    throw new InvalidArgumentException('workforce_capacity_restricted_source_field');
                }
                $walk($item);
            }
        };
        $walk($value);
    }

    private function canonicalValue(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->canonicalValue($nested);
            }
        }

        return $value;
    }

    private function pinnedState(?array $state): ?array
    {
        if ($state === null) {
            return null;
        }

        unset($state['assignments']);

        return $state;
    }
}
