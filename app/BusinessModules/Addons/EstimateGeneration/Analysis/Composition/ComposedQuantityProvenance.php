<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\WorkQuantityInputUnit;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use InvalidArgumentException;

/** A model chooses a work, never turns an arbitrary contextual number into its measured volume. */
final readonly class ComposedQuantityProvenance
{
    private array $quantitiesById;

    private array $factsById;

    private array $knownEntities;

    public function __construct(array $quantities, array $facts)
    {
        $this->quantitiesById = array_column($quantities, null, 'id');
        $this->factsById = array_column($facts, null, 'id');
        $this->knownEntities = array_fill_keys(array_values(array_filter(array_merge(array_column($quantities, 'entity_id'),
            array_column($facts, 'entity_id')), 'is_string')), true);
        if (count($this->quantitiesById) !== count($quantities) || count($this->factsById) !== count($facts)) {
            throw new InvalidArgumentException('estimate_composer_quantity_provenance_ambiguous');
        }
    }

    /** @return array{quantity:array<string,mixed>,entity_id:string,operand_fact_ids:list<string>,eligible:bool}|null */
    public function binding(array $intent): ?array
    {
        if (isset($intent['entity_id'])) {
            if (! isset($this->knownEntities[$intent['entity_id']])) {
                throw new InvalidArgumentException('estimate_composer_work_entity_invalid');
            }
        }
        $quantityId = $intent['derived_quantity_id'] ?? null;
        if ($quantityId === null) {
            return null;
        }
        $quantity = $this->quantitiesById[$quantityId] ?? null;
        $entityId = $quantity['entity_id'] ?? null;
        $operands = $quantity['operand_fact_ids'] ?? null;
        if (! is_array($quantity) || ! is_string($entityId) || $entityId === ''
            || ! is_array($operands) || ! array_is_list($operands) || $operands === []
            || count(array_filter($operands, 'is_string')) !== count($operands)
            || count($operands) !== count(array_unique($operands, SORT_STRING))
            || (isset($intent['entity_id']) && $intent['entity_id'] !== $entityId)) {
            throw new InvalidArgumentException('estimate_composer_quantity_entity_or_lineage_invalid');
        }
        $unit = is_string($quantity['unit'] ?? null) ? WorkQuantityInputUnit::parse($quantity['unit']) : null;
        $requestedUnit = isset($intent['unit']) && is_string($intent['unit']) ? WorkQuantityInputUnit::parse($intent['unit']) : $unit;
        if ($unit === null || $requestedUnit === null || $unit['unit'] !== $requestedUnit['unit']) {
            throw new InvalidArgumentException('estimate_composer_quantity_dimension_invalid');
        }
        foreach ($operands as $factId) {
            if (! is_string($factId) || ! isset($this->factsById[$factId])) {
                throw new InvalidArgumentException('estimate_composer_quantity_operand_invalid');
            }
        }
        foreach ($intent['source_fact_ids'] ?? [] as $factId) {
            $fact = $this->factsById[$factId] ?? null;
            if (! is_array($fact)) {
                throw new InvalidArgumentException('estimate_composer_source_fact_invalid');
            }
            // Other numeric measurements cannot masquerade as evidence for this formula.
            if ((is_int($fact['value'] ?? null) || is_float($fact['value'] ?? null)
                || (is_string($fact['value'] ?? null) && is_numeric($fact['value']))) && ! in_array($factId, $operands, true)) {
                throw new InvalidArgumentException('estimate_composer_quantity_reference_unrelated');
            }
        }

        $purpose = CurrentProjectDerivedQuantityService::FORMULA_PURPOSES[$quantity['formula_key'] ?? '']
            ?? $quantity['quantity_key'] ?? null;
        $eligible = ($quantity['source'] ?? null) === 'evidenced' && ($quantity['review_blockers'] ?? []) === []
            && is_string($purpose) && is_string($intent['work_key'] ?? null)
            && (new WorkItemQuantityMapper)->acceptsExactSource($intent['work_key'], $purpose, $quantity['unit']);

        return ['quantity' => $quantity, 'entity_id' => $entityId, 'operand_fact_ids' => $operands, 'eligible' => $eligible];
    }

    /** Audit repairs use the same proof, including when the model response is replayed from storage.
     * @return array{quantity:array<string,mixed>,entity_id:string,operand_fact_ids:list<string>,eligible:bool}
     */
    public function correction(array $correction, ?array $item = null): array
    {
        $id = $correction['derived_quantity_id'] ?? null;
        $quantity = is_string($id) ? ($this->quantitiesById[$id] ?? null) : null;
        if (! is_array($quantity)) {
            throw new InvalidArgumentException('estimate_composer_correction_quantity_invalid');
        }
        if ($correction['operation'] === 'add_work') {
            $intent = ['work_key' => $correction['work_key'], 'derived_quantity_id' => $id,
                'entity_id' => $quantity['entity_id'] ?? null, 'source_fact_ids' => $correction['source_fact_ids']];
        } else {
            if ($item === null) {
                throw new InvalidArgumentException('estimate_composer_correction_target_invalid');
            }
            $entity = $item['metadata']['scope_entity_id'] ?? $item['quantity_basis_details']['formula_inputs']['entity_id']
                ?? $item['metadata']['entity_id'] ?? null;
            if (! is_string($entity) || $entity === '') {
                throw new InvalidArgumentException('estimate_composer_correction_target_entity_unconfirmed');
            }
            $key = $item['work_key'] ?? $item['key'] ?? '';
            $mapper = new WorkItemQuantityMapper;
            if (! is_string($key) || ! $mapper->isKnownWorkKey($key)) {
                $key = $item['metadata']['quantity_key'] ?? '';
            }
            $intent = ['work_key' => $key, 'entity_id' => $entity, 'derived_quantity_id' => $id,
                'source_fact_ids' => $quantity['operand_fact_ids'] ?? []];
        }
        $binding = $this->binding($intent);
        if ($binding === null || ! $binding['eligible']) {
            throw new InvalidArgumentException('estimate_composer_correction_quantity_purpose_unverified');
        }

        return $binding;
    }

    /** The canonical recipe comes from the server quantity factory, never from a model repair. */
    public function basis(array $binding): array
    {
        $quantity = $binding['quantity'];
        $basis = $quantity['quantity_basis'] ?? null;
        if (! is_array($basis) || ($basis['amount'] ?? null) !== $quantity['value']
            || ($basis['unit'] ?? null) !== $quantity['unit']
            || ($basis['formula_key'] ?? null) !== ($quantity['formula_key'] ?? null)
            || ($basis['formula_inputs']['entity_id'] ?? null) !== $binding['entity_id']
            || ($basis['source'] ?? null) !== 'evidenced'
            || ! is_array($basis['formula_inputs']['operands'] ?? null)) {
            throw new InvalidArgumentException('estimate_composer_correction_canonical_basis_missing');
        }
        $ids = array_column($basis['formula_inputs']['operands'], 'fact_id');
        $expected = $binding['operand_fact_ids'];
        sort($ids, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($ids !== $expected) {
            throw new InvalidArgumentException('estimate_composer_correction_canonical_basis_invalid');
        }

        return $basis;
    }
}
