<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

final readonly class DerivedQuantity
{
    public array $evidenceIds;

    public function __construct(
        public string $id,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $entityId,
        public string $formula,
        public array $operands,
        public int|float|null $value,
        public string $unit,
        public string $roundingMode,
        public int $roundingScale,
        array $evidenceIds,
        public string $status,
    ) {
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelInvariant::id($id, 'Derived quantity');
        ProjectModelInvariant::id($entityId, 'Derived quantity entity');
        if (trim($formula) === '' || strlen($formula) > 2000 || $operands === [] || ! array_is_list($operands)
            || trim($unit) === '' || ! in_array($roundingMode, ['half_up', 'half_even', 'floor', 'ceil'], true)
            || $roundingScale < 0 || $roundingScale > 8
            || ! in_array($status, ['candidate', 'confirmed', 'unresolved', 'invalidated'], true)
            || ($status === 'unresolved' && $value !== null) || ($status !== 'unresolved' && $value === null)) {
            throw new InvalidArgumentException('Derived quantity is invalid.');
        }
        foreach ($operands as $operand) {
            if (! is_array($operand) || array_keys($operand) !== ['fact_id', 'value', 'unit', 'evidence_ids']
                || ! is_string($operand['fact_id']) || ! is_string($operand['unit'])
                || (! is_int($operand['value']) && ! is_float($operand['value']))) {
                throw new InvalidArgumentException('Derived quantity operand is invalid.');
            }
            ProjectModelInvariant::id($operand['fact_id'], 'Derived quantity operand fact');
            ProjectModelInvariant::uniqueIds($operand['evidence_ids'], 'Derived quantity operand evidence', true);
        }
        $this->evidenceIds = ProjectModelInvariant::uniqueIds($evidenceIds, 'Derived quantity evidence', $status === 'unresolved');
    }
}
