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

    public array $snapshotIdentity;

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
        public ?string $formulaIdentity = null,
        public ?string $formulaVersion = null,
        public string $roundingBoundary = 'formula_result',
        public string $unitCompatibility = 'exact',
        array $snapshotIdentity = [],
        public ?string $technologyDecisionId = null,
    ) {
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelInvariant::id($id, 'Derived quantity');
        ProjectModelInvariant::id($entityId, 'Derived quantity entity');
        if (trim($formula) === '' || strlen($formula) > 2000 || $operands === [] || ! array_is_list($operands)
            || trim($unit) === '' || ! in_array($roundingMode, ['half_up', 'half_even', 'floor', 'ceil'], true)
            || $roundingScale < 0 || $roundingScale > 12
            || ! in_array($status, ['candidate', 'confirmed', 'unresolved', 'invalidated'], true)) {
            throw new InvalidArgumentException('Derived quantity is invalid.');
        }
        $normalizedOperands = [];
        $unresolvedInputs = [];
        foreach ($operands as $operand) {
            if (! is_array($operand)
                || array_diff([
                    'fact_id', 'projection_version', 'status', 'current', 'value', 'unit',
                    'evidence_ids', 'decision_id',
                ], array_keys($operand)) !== []
                || ! is_string($operand['fact_id']) || ! is_string($operand['unit'])
                || ! is_string($operand['value']) || ! is_int($operand['projection_version'])
                || $operand['projection_version'] <= 0 || ! is_bool($operand['current'])
                || ! in_array($operand['status'], Fact::STATUSES, true)
                || ($operand['decision_id'] !== null && ! is_string($operand['decision_id']))) {
                throw new InvalidArgumentException('Derived quantity operand is invalid.');
            }
            ProjectModelInvariant::id($operand['fact_id'], 'Derived quantity operand fact');
            $operand['value'] = DecimalValue::canonical($operand['value']);
            if (isset($operand['source_value'])) {
                if (! is_string($operand['source_value'])) {
                    throw new InvalidArgumentException('Derived quantity source operand is invalid.');
                }
                $operand['source_value'] = DecimalValue::canonical($operand['source_value']);
            }
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
        self::assertRoundingScale($this->value, $this->status, $roundingScale);
        $this->evidenceIds = ProjectModelInvariant::uniqueIds(
            $evidenceIds,
            'Derived quantity evidence',
            $this->status === 'unresolved' || $this->hasDecisionLineage($normalizedOperands),
        );
        if (($formulaIdentity !== null && preg_match('/^[a-z0-9._:-]{1,120}$/D', $formulaIdentity) !== 1)
            || ($formulaVersion !== null && preg_match('/^[a-zA-Z0-9._:-]{1,80}$/D', $formulaVersion) !== 1)
            || ! in_array($roundingBoundary, ['formula_result', 'irrational_operation_then_formula_result'], true)
            || ! in_array($unitCompatibility, ['exact', 'canonical_conversion'], true)
            || ($snapshotIdentity !== [] && array_is_list($snapshotIdentity))) {
            throw new InvalidArgumentException('Derived quantity formula contract is invalid.');
        }
        if ($technologyDecisionId !== null) {
            ProjectModelInvariant::id($technologyDecisionId, 'Derived quantity technology decision');
        }
        $this->snapshotIdentity = $snapshotIdentity;
    }

    public static function assertRoundingScale(?string $value, string $status, int $roundingScale): void
    {
        if ($status !== 'confirmed' || $value === null) {
            return;
        }
        $canonical = DecimalValue::canonical($value);
        $fraction = explode('.', ltrim($canonical, '-'), 2)[1] ?? '';
        if (strlen($fraction) > $roundingScale) {
            throw new InvalidArgumentException('Confirmed derived quantity value exceeds its rounding scale.');
        }
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
