<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\NormativeResourcePriceTarget;
use PHPUnit\Framework\TestCase;

final class NormativeResourcePriceTargetTest extends TestCase
{
    public function test_price_identity_keeps_the_normative_consumption_and_changes_with_product_and_scope(): void
    {
        $item = $this->item();
        $reader = new NormativeResourcePriceTarget;
        $target = $reader->find($item, 'generation:first', 29);
        self::assertSame('0.125', $target['quantity_per_unit']);
        self::assertSame('3.5', $target['quantity']);
        self::assertSame(71, $target['norm_source_resource_id']);
        self::assertSame('norm-original-code', $target['resource_code']);
        $quantityOnly = $item;
        $quantityOnly['materials'][0]['quantity'] = '7';
        self::assertSame($target['target_hash'], $reader->find($quantityOnly, 'generation:first', 29)['target_hash']);
        foreach ([['scope_entity_id' => 'room:other'], ['opening_system' => ['system' => 'changed']]] as $context) {
            $changed = $item;
            $changed['metadata'] = [...$changed['metadata'], ...$context];
            self::assertNotSame($target['target_hash'], $reader->find($changed, 'generation:first', 29)['target_hash']);
        }
        self::assertNotSame($target['target_hash'], $reader->find($item, 'generation:second', 29)['target_hash']);
        $consumption = $item;
        $consumption['materials'][0]['quantity_per_unit'] = '0.25';
        self::assertNotSame($target['target_hash'], $reader->find($consumption, 'generation:first', 29)['target_hash']);
        self::assertSame($this->item(), $item);
    }

    public function test_duplicate_normative_resources_and_unproven_materials_are_not_price_targets(): void
    {
        $reader = new NormativeResourcePriceTarget;
        $item = $this->item();
        $item['labor'][] = $item['materials'][0];
        self::assertSame([], $reader->targets($item, 'generation:first'));
        foreach (['quantity_basis' => 'model_guess', 'resource_type' => 'labor', 'quantity_per_unit' => null] as $key => $value) {
            $invalid = $this->item();
            $invalid['materials'][0][$key] = $value;
            self::assertSame([], $reader->targets($invalid, 'generation:first'));
        }
    }

    private function item(): array
    {
        return ['key' => 'some-work:arbitrary', 'normative_match' => ['norm_id' => 13],
            'metadata' => ['scope_entity_id' => 'room:any', 'opening_system' => ['system' => 'initial']],
            'materials' => [['key' => 'resource:29', 'name' => 'Материал из нормативного состава', 'unit' => 'm3',
                'resource_type' => 'material', 'quantity_basis' => 'normative_resource', 'quantity_per_unit' => '0.1250', 'quantity' => '3.5',
                'normative_ref' => ['norm_id' => 13, 'norm_resource_id' => 29, 'norm_source_resource_id' => 71, 'resource_code' => 'norm-original-code']]]];
    }
}
