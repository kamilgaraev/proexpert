<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use PHPUnit\Framework\TestCase;

final class NativeNumericFactProofTest extends TestCase
{
    public function test_a_native_producer_marker_and_locator_do_not_confirm_an_unrelated_number(): void
    {
        [$fact, $evidence] = $this->fixture([]);
        self::assertFalse((new NativeNumericFactProof)->matches($fact, $evidence));
        foreach (['parameter' => 'height', 'entity_id' => 'entity:foreign', 'measurement' => 'm2:500',
            'evidence_fingerprint' => str_repeat('c', 64), 'source_version' => 'sha256:'.str_repeat('b', 64)] as $field => $value) {
            [$fact, $evidence] = $this->fixture([$field => $value]);
            self::assertFalse((new NativeNumericFactProof)->matches($fact, $evidence), $field);
        }
    }

    public function test_native_literal_binding_uses_exact_semantic_measurement_not_a_formatted_unit_string(): void
    {
        [$fact, $evidence] = $this->fixture(null);
        self::assertTrue((new NativeNumericFactProof)->matches($fact, $evidence));
    }

    public function test_only_a_current_certified_literal_with_compatible_dimension_can_be_a_quantity_choice(): void
    {
        [$fact, $evidence] = $this->fixture(null);
        $eligibility = new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\NativeQuantityFactEligibility;
        self::assertSame($evidence, $eligibility->evidence($fact, [$evidence->id => $evidence], 'm2'));
        self::assertNull($eligibility->evidence($fact, [$evidence->id => $evidence], 'm3'));
        self::assertNull($eligibility->evidence($fact, [], 'm2'));
        [$unproven, $evidence] = $this->fixture([]);
        self::assertNull($eligibility->evidence($unproven, [$evidence->id => $evidence], 'm2'));
    }

    private function fixture(?array $change): array
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $hash = hash('sha256', 'actual-source-node');
        $proof = ['entity_id' => 'entity:room', 'parameter' => 'area', 'measurement' => 'm2:20',
            'source_version' => $version, 'evidence_fingerprint' => $hash];
        $fact = new Fact('fact:area', 1, 2, 3, $version, 'entity:room', 'room_area', '200000', 'cm2', 0.0, 'document', 'confirmed', ['evidence:1'],
            nativeNumericProofs: $change === [] ? [] : ['evidence:1' => [...$proof, ...($change ?? [])]]);
        $evidence = new Evidence('evidence:1', 1, 2, 3, $version, 'document:1', 'document', nativeReference: 'xlsx:sheet:Размеры!C2',
            producerName: 'native_numeric_parser', fingerprint: $hash);

        return [$fact, $evidence];
    }
}
