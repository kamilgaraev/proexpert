<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkItemQuantityResolverTest extends TestCase
{
    #[Test]
    public function legacy_wall_document_takeoff_is_preserved_while_universal_requires_an_assembly(): void
    {
        $resolver = new WorkItemQuantityResolver;
        foreach (\App\BusinessModules\Addons\EstimateGeneration\Quantities\WallWorkItemExpander::WORKS as $work) {
            $item = ['key' => $work, 'quantity' => '2', 'unit' => $work === 'walls.external_volume' ? 'm3' : 'm2',
                'quantity_formula' => $work, 'source_refs' => [['document_id' => 17, 'page_number' => 1]],
                'metadata' => ['quantity_key' => $work, 'quantity_source' => 'drawing_takeoff']];
            self::assertSame('2.000000', $resolver->resolve($item, [], $this->generationIdentity())?->amount);
            self::assertTrue(\App\BusinessModules\Addons\EstimateGeneration\Quantities\WallWorkItemExpander::permits($item, [], false));
            self::assertNull($resolver->resolve($item, [], [...$this->generationIdentity(), 'evaluation_mode' => 'universal']));
            $item['metadata']['generation_source'] = 'canonical_wall_assembly';
            self::assertNull($resolver->resolve($item, [], $this->generationIdentity()));
            self::assertFalse(\App\BusinessModules\Addons\EstimateGeneration\Quantities\WallWorkItemExpander::permits($item, [], false));
        }
    }

    #[Test]
    public function foundation_sources_require_the_exact_element_and_never_use_floor_area_as_beam_height(): void
    {
        $resolver = new WorkItemQuantityResolver;
        foreach (['foundation_beam_volume', 'foundation_slab_volume'] as $formula) {
            $source = new \App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData(
                key: 'foundation_volume', unit: 'm3', amount: '3.2', formulaKey: $formula, formulaVersion: '1',
                formulaInputs: ['entity_id' => 'entity:slab', 'operands' => [['fact_id' => 'fact:slab']], 'snapshot_identity' => ['input_fingerprint' => 'current']],
                source: QuantitySource::Evidenced, evidenceIds: ['evidence:1'], modelVersion: 'current');
            $item = ['unit' => 'm3', 'metadata' => ['quantity_key' => 'foundation.concrete']];
            self::assertNull($resolver->resolve($item, [$source->key => $source]));
            $item['metadata']['scope_entity_id'] = 'entity:beam';
            self::assertNull($resolver->resolve($item, [$source->key => $source]));
            $item['metadata']['scope_entity_id'] = 'entity:slab';
            self::assertSame('3.2', $resolver->resolve($item, [$source->key => $source])?->amount);
            self::assertSame('entity:slab', $resolver->resolve($item, [$source->key => $source])?->formulaInputs['entity_id']);
        }
    }

    #[Test]
    public function multiple_foundation_elements_are_mapped_individually_without_a_global_alias(): void
    {
        $quantities = [];
        foreach (['beam' => 'foundation_beam_volume', 'slab' => 'foundation_slab_volume'] as $entity => $formula) {
            $key = 'quantity:'.$formula.':'.$entity;
            $quantities[$key] = new \App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData(
                key: $key, unit: 'm3', amount: $entity === 'beam' ? '1' : '3.2', formulaKey: $formula, formulaVersion: '1',
                formulaInputs: ['entity_id' => $entity], source: QuantitySource::Evidenced, evidenceIds: ['evidence:'.$entity], modelVersion: 'current');
        }
        $resolver = new WorkItemQuantityResolver;
        $item = ['unit' => 'm3', 'metadata' => ['quantity_key' => 'foundation.concrete']];
        self::assertNull($resolver->resolve($item, $quantities));
        foreach (['beam' => '1', 'slab' => '3.2'] as $entity => $expected) {
            $item['metadata']['scope_entity_id'] = $entity;
            self::assertSame($expected, $resolver->resolve($item, $quantities)?->amount);
        }
        unset($quantities['quantity:foundation_beam_volume:beam']);
        $item['metadata']['scope_entity_id'] = 'beam';
        self::assertNull($resolver->resolve($item, $quantities));
    }

    #[Test]
    public function canonical_engineering_alias_cannot_supply_another_entity_or_an_unscoped_work(): void
    {
        $source = new \App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData(
            key: 'electrical.main_cable', unit: 'm', amount: '40', formulaKey: 'cable_length', formulaVersion: '1',
            formulaInputs: ['entity_id' => 'entity:b'], source: QuantitySource::Evidenced, evidenceIds: ['evidence:1'], modelVersion: 'current');
        $resolver = new WorkItemQuantityResolver;
        $item = ['unit' => 'm', 'metadata' => ['quantity_key' => 'electrical.main_cable', 'scope_entity_id' => 'entity:a']];
        self::assertNull($resolver->resolve($item, [$source->key => $source]));
        unset($item['metadata']['scope_entity_id']);
        self::assertNull($resolver->resolve($item, [$source->key => $source]));
        $item['metadata']['scope_entity_id'] = 'entity:b';
        self::assertSame($source, $resolver->resolve($item, [$source->key => $source]));
    }

    #[Test]
    public function confirmed_document_takeoff_has_priority_and_is_canonicalized(): void
    {
        $quantity = (new WorkItemQuantityResolver)->resolve([
            'quantity' => '2',
            'unit' => 'шт',
            'quantity_formula' => 'stairs.flights',
            'quantity_basis' => 'Спецификация лестниц',
            'source_refs' => [['type' => 'drawing', 'filename' => 'АР.pdf', 'page_number' => 4]],
            'validation_flags' => ['normative_required'],
            'metadata' => ['quantity_key' => 'stairs.flights', 'quantity_source' => 'document_quantity'],
        ], [
            'floor_area' => [
                'key' => 'floor_area', 'unit' => 'm2', 'amount' => '180.000000',
                'formula_key' => 'floor.area.sum', 'formula_version' => '1.0.0',
                'formula_inputs' => [], 'source' => 'evidenced', 'evidence_ids' => ['plan:1'],
                'model_version' => 'building-model:v1', 'assumptions' => [], 'review_blockers' => [],
            ],
        ], $this->generationIdentity());

        self::assertNotNull($quantity);
        self::assertSame('stairs.flights', $quantity->key);
        self::assertSame('2.000000', $quantity->amount);
        self::assertSame('pcs', $quantity->unit);
        self::assertSame(QuantitySource::Evidenced, $quantity->source);
        self::assertCount(1, $quantity->evidenceIds);
    }

    #[Test]
    public function unconfirmed_direct_takeoff_cannot_bypass_review(): void
    {
        $quantity = (new WorkItemQuantityResolver)->resolve([
            'quantity' => '2', 'unit' => 'шт', 'quantity_formula' => 'stairs.flights',
            'source_refs' => [['type' => 'drawing', 'filename' => 'АР.pdf', 'page_number' => 4]],
            'validation_flags' => ['quantity_review_required'],
            'metadata' => ['quantity_key' => 'stairs.flights', 'quantity_source' => 'document_quantity'],
        ], []);

        self::assertNull($quantity);
    }

    #[Test]
    public function facts_summary_area_with_general_document_refs_remains_estimated(): void
    {
        $quantity = (new WorkItemQuantityResolver)->resolve([
            'quantity' => '180', 'unit' => 'm2', 'quantity_formula' => 'finish.floor',
            'source_refs' => [['type' => 'document', 'filename' => 'project.pdf', 'page_number' => 1]],
            'validation_flags' => ['normative_required'],
            'metadata' => ['quantity_key' => 'finish.floor', 'quantity_source' => 'facts_summary_area'],
        ], [
            'floor_area' => [
                'key' => 'floor_area', 'unit' => 'm2', 'amount' => '180.000000',
                'formula_key' => 'floor.area.sum', 'formula_version' => '1.0.0',
                'formula_inputs' => [], 'source' => 'evidenced', 'evidence_ids' => ['plan:1'],
                'model_version' => 'building-model:v1', 'assumptions' => [], 'review_blockers' => [],
            ],
        ]);

        self::assertNotNull($quantity);
        self::assertSame('finish.floor', $quantity->key);
        self::assertSame(QuantitySource::Estimated, $quantity->source);
    }

    #[Test]
    public function persisted_scope_inference_sources_keep_direct_quantity(): void
    {
        foreach (['specification', 'work_volume_statement'] as $source) {
            $quantity = (new WorkItemQuantityResolver)->resolve([
                'quantity' => '12.5', 'unit' => 'm3', 'quantity_formula' => 'foundation.concrete',
                'source_refs' => [['type' => 'document', 'filename' => 'Ведомость.pdf', 'page_number' => 2]],
                'validation_flags' => ['normative_required'],
                'metadata' => ['quantity_key' => 'foundation.concrete', 'quantity_source' => $source],
            ], [], $this->generationIdentity());

            self::assertNotNull($quantity, $source);
            self::assertSame('12.500000', $quantity->amount, $source);
            self::assertSame(QuantitySource::Evidenced, $quantity->source, $source);
        }
    }

    /** @return array<string, mixed> */
    private function generationIdentity(): array
    {
        return [
            'input_snapshot_hash' => str_repeat('a', 64),
            'scope_identity' => [
                'organization_id' => 10,
                'project_id' => 20,
                'session_id' => 30,
                'source_version' => 'sha256:'.str_repeat('d', 64),
            ],
        ];
    }
}
