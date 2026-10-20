<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\ScopedQuantityInputReadiness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScopedQuantityInputReadinessTest extends TestCase
{
    public static function formulas(): array
    {
        return ['walls' => ['wall_net_area', 'wall', 'm2', 'structures'],
            'equipment' => ['equipment_count', 'equipment', 'count', 'equipment']];
    }

    #[DataProvider('formulas')]
    public function test_complete_current_separate_sources_permit_partial_planning_without_aggregating_values(string $formula, string $type, string $unit, string $section): void
    {
        [$snapshot, $warning, $quantities] = $this->fixture($formula, $type, $unit);
        $before = serialize([$snapshot, $warning, $quantities]);
        self::assertTrue(ScopedQuantityInputReadiness::permitsPartialPlanning($warning, $snapshot, str_repeat('a', 64), [$section], $quantities, [], []));
        self::assertSame($before, serialize([$snapshot, $warning, $quantities]));
        self::assertSame(['3', '7'], array_column(array_map(static fn (QuantityData $q): array => $q->toArray(), array_values($quantities)), 'amount'));
    }

    public static function unsafeSources(): array
    {
        return array_combine($cases = ['missing_reference', 'duplicate_reference', 'duplicate_key', 'truncated', 'incomplete_count',
            'missing_quantity', 'stale_model', 'stale_snapshot', 'blocked_quantity', 'zero_quantity', 'wrong_unit',
            'missing_evidence', 'unsupported_assumption', 'foreign_entity', 'duplicate_entity', 'unreferenced_quantity',
            'unknown_formula', 'unknown_warning', 'unselected_scope', 'stale_entity'], array_map(static fn (string $case): array => [$case], $cases));
    }

    #[DataProvider('unsafeSources')]
    public function test_incomplete_ambiguous_stale_or_out_of_scope_sources_do_not_remove_review(string $case): void
    {
        [$snapshot, $warning, $quantities] = $this->fixture('wall_net_area', 'wall', 'm2');
        $sections = ['structures'];
        $first = $quantities['q:a']->toArray();
        switch ($case) {
            case 'missing_reference': unset($warning['quantity_keys']);
                break;
            case 'duplicate_reference': $warning['quantity_ids'][1] = $warning['quantity_ids'][0];
                break;
            case 'duplicate_key': $warning['quantity_keys'][1] = $warning['quantity_keys'][0];
                break;
            case 'truncated': $warning['truncated'] = true;
                break;
            case 'incomplete_count': $warning['total_quantities'] = 3;
                break;
            case 'missing_quantity': unset($quantities['q:b']);
                break;
            case 'stale_model': $first['model_version'] = str_repeat('b', 64);
                break;
            case 'stale_snapshot': $first['formula_inputs']['snapshot_identity']['input_fingerprint'] = str_repeat('b', 64);
                break;
            case 'blocked_quantity': $first['review_blockers'] = ['operand_source_conflict'];
                break;
            case 'zero_quantity': $first['amount'] = '0';
                break;
            case 'wrong_unit': $first['unit'] = 'm3';
                break;
            case 'missing_evidence': $first['evidence_ids'] = [];
                break;
            case 'unsupported_assumption': $first['source'] = 'estimated';
                break;
            case 'foreign_entity': $first['formula_inputs']['entity_id'] = 'foreign';
                break;
            case 'duplicate_entity': $first['formula_inputs']['entity_id'] = 'entity:b';
                break;
            case 'unreferenced_quantity': $quantities['extra'] = QuantityData::fromArray([...$first, 'key' => 'extra']);
                break;
            case 'unknown_formula': $warning['formula'] = 'sloped_roof_area';
                break;
            case 'unknown_warning': $warning['code'] = 'canonical_quantity_unresolved';
                break;
            case 'unselected_scope': $sections = ['equipment'];
                break;
            case 'stale_entity':
                $snapshot = new ProjectModelSnapshot($snapshot->entities, [], [], []);
                break;
        }
        $quantities['q:a'] = QuantityData::fromArray($first);
        self::assertFalse(ScopedQuantityInputReadiness::permitsPartialPlanning($warning, $snapshot, str_repeat('a', 64), $sections, $quantities, [], []));
    }

    private function fixture(string $formula, string $type, string $unit): array
    {
        $entities = $facts = $quantities = [];
        foreach (['a' => '3', 'b' => '7'] as $id => $amount) {
            $source = 'sha256:'.str_repeat('b', 64);
            $entityId = 'entity:'.$id;
            $entities[] = new Entity($entityId, 1, 2, 3, $source, in_array($type, Entity::TYPES, true) ? $type : 'quantity', $entityId,
                ['semantic_type' => $type, 'identity' => ['manual_key' => $id]]);
            $facts[] = new Fact('fact:'.$id, 1, 2, 3, $source, $entityId, $type === 'wall' ? 'length' : 'equipment_count',
                $amount, $type === 'wall' ? 'm' : 'count', 0, 'user_input', 'confirmed', ['evidence:'.$id]);
            $quantities['q:'.$id] = new QuantityData('q:'.$id, $unit, $amount, $formula, '1',
                ['entity_id' => $entityId, 'snapshot_identity' => ['input_fingerprint' => str_repeat('a', 64)]],
                QuantitySource::Evidenced, ['evidence:'.$id], str_repeat('a', 64));
        }

        return [new ProjectModelSnapshot($entities, $facts, [], []),
            ['code' => 'canonical_quantity_aggregate_unresolved', 'formula' => $formula,
                'quantity_ids' => ['quantityv:a', 'quantityv:b'], 'quantity_keys' => ['q:a', 'q:b'], 'total_quantities' => 2, 'truncated' => false], $quantities];
    }
}
