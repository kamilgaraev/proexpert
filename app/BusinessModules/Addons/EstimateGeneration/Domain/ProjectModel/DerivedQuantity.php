<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

final readonly class DerivedQuantity
{
    public array $evidenceIds;

    public array $operands;

    public ?string $value;

    public string $status;

    public array $unresolvedInputs;

    public function __construct(
        public string $id,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $entityId,
        public string $formula,
        array $operands,
        ?string $value,
        public string $unit,
        public string $roundingMode,
        public int $roundingScale,
        array $evidenceIds,
        string $status,
    ) {
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelInvariant::id($id, 'Derived quantity');
        ProjectModelInvariant::id($entityId, 'Derived quantity entity');
        if (trim($formula) === '' || strlen($formula) > 2000 || $operands === [] || ! array_is_list($operands)
            || trim($unit) === '' || ! in_array($roundingMode, ['half_up', 'half_even', 'floor', 'ceil'], true)
            || $roundingScale < 0 || $roundingScale > 8
            || ! in_array($status, ['candidate', 'confirmed', 'unresolved', 'invalidated'], true)) {
            throw new InvalidArgumentException('Derived quantity is invalid.');
        }
        $normalizedOperands = [];
        $unresolvedInputs = [];
        foreach ($operands as $operand) {
            if (! is_array($operand) || array_keys($operand) !== [
                'fact_id',
                'projection_version',
                'status',
                'current',
                'value',
                'unit',
                'evidence_ids',
                'decision_id',
            ]
                || ! is_string($operand['fact_id']) || ! is_string($operand['unit'])
                || ! is_string($operand['value']) || ! is_int($operand['projection_version'])
                || $operand['projection_version'] <= 0 || ! is_bool($operand['current'])
                || ! in_array($operand['status'], Fact::STATUSES, true)
                || ($operand['decision_id'] !== null && ! is_string($operand['decision_id']))) {
                throw new InvalidArgumentException('Derived quantity operand is invalid.');
            }
            ProjectModelInvariant::id($operand['fact_id'], 'Derived quantity operand fact');
            $operand['value'] = DecimalValue::canonical($operand['value']);
            $operand['evidence_ids'] = ProjectModelInvariant::uniqueIds(
                $operand['evidence_ids'],
                'Derived quantity operand evidence',
                true,
            );
            if ($operand['decision_id'] !== null) {
                ProjectModelInvariant::id($operand['decision_id'], 'Derived quantity operand decision');
            }
            if (! $operand['current'] || $operand['status'] !== 'confirmed'
                || ($operand['evidence_ids'] === [] && $operand['decision_id'] === null)) {
                $unresolvedInputs[] = $operand['fact_id'];
            }
            $normalizedOperands[] = $operand;
        }
        $this->operands = $normalizedOperands;
        $this->unresolvedInputs = ProjectModelInvariant::uniqueIds(
            $unresolvedInputs,
            'Derived quantity unresolved input',
            true,
        );
        $this->status = $unresolvedInputs === [] ? $status : 'unresolved';
        $this->value = $this->status === 'unresolved'
            ? null
            : ($value === null ? throw new InvalidArgumentException('Derived quantity value is required.') : DecimalValue::canonical($value));
        $this->evidenceIds = ProjectModelInvariant::uniqueIds(
            $evidenceIds,
            'Derived quantity evidence',
            $this->status === 'unresolved' || $this->hasDecisionLineage($normalizedOperands),
        );
    }

    private function hasDecisionLineage(array $operands): bool
    {
        foreach ($operands as $operand) {
            if ($operand['decision_id'] !== null) {
                return true;
            }
        }

        return false;
    }
}
