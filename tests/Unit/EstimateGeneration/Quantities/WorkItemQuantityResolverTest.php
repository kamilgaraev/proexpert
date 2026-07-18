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
        ]);

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
            ], []);

            self::assertNotNull($quantity, $source);
            self::assertSame('12.500000', $quantity->amount, $source);
            self::assertSame(QuantitySource::Evidenced, $quantity->source, $source);
        }
    }
}
