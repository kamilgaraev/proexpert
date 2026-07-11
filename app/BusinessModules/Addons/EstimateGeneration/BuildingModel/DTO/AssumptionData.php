<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use InvalidArgumentException;

final readonly class AssumptionData
{
    public string $code;

    public string $severity;

    public array $affectedKeys;

    public array $evidenceIds;

    public bool $requiresConfirmation;

    public function __construct(string $code, string $severity, array $affectedKeys, array $evidenceIds, bool $requiresConfirmation, ?array $extra = null)
    {
        if ($extra !== null) {
            throw new InvalidArgumentException('Assumption requires exact keys.');
        }
        if (! in_array($code, ['geometry_conflict', 'scale_conflict', 'scale_estimated', 'scale_missing', 'element_conflict'], true)) {
            throw new InvalidArgumentException('Assumption code is invalid.');
        }
        if (! in_array($severity, ['warning', 'blocking'], true)) {
            throw new InvalidArgumentException('Assumption severity is invalid.');
        }
        if (! array_is_list($affectedKeys) || $affectedKeys === []) {
            throw new InvalidArgumentException('Assumption affected keys are invalid.');
        }
        $keys = array_map(static fn (mixed $key): string => BuildingModelSchema::key($key, 'Affected key'), $affectedKeys);
        sort($keys, SORT_STRING);
        $this->code = $code;
        $this->severity = $severity;
        $this->affectedKeys = array_values(array_unique($keys));
        $this->evidenceIds = BuildingModelSchema::evidenceIds($evidenceIds);
        $this->requiresConfirmation = $requiresConfirmation;
    }

    public function toArray(): array
    {
        return ['code' => $this->code, 'severity' => $this->severity, 'affected_keys' => $this->affectedKeys, 'evidence_ids' => $this->evidenceIds, 'requires_confirmation' => $this->requiresConfirmation];
    }

    public static function fromArray(array $data): self
    {
        BuildingModelSchema::exactKeys($data, ['code', 'severity', 'affected_keys', 'evidence_ids', 'requires_confirmation']);
        if (! is_bool($data['requires_confirmation'])) {
            throw new InvalidArgumentException('Assumption confirmation flag is invalid.');
        }

        return BuildingModelSchema::typed(static fn (): self => new self($data['code'], $data['severity'], $data['affected_keys'], $data['evidence_ids'], $data['requires_confirmation']));
    }
}
