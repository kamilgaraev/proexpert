<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceContract;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelLocatorFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelValueFingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelCanonicalEvidenceTest extends TestCase
{
    #[Test]
    public function decimal_794_has_a_stable_value_fingerprint_without_binary_float_tail(): void
    {
        self::assertSame(ProjectModelValueFingerprint::for(['unit' => 'm2', 'value' => 7.94]), ProjectModelValueFingerprint::for(['value' => 7.9400000000001, 'unit' => 'm2']));
    }

    #[Test]
    public function recursively_reordered_locator_has_the_same_identity(): void
    {
        $first = ['document_id' => 501, 'unit_index' => 1, 'page' => 1, 'region_key' => 'region:'.str_repeat('a', 64), 'element_key' => 'element:'.str_repeat('b', 64), 'bbox' => [1, 2, 3, 4]];
        $second = ['bbox' => [1.0, 2.0, 3.0, 4.0], 'element_key' => 'element:'.str_repeat('b', 64), 'page' => 1, 'region_key' => 'region:'.str_repeat('a', 64), 'unit_index' => 1, 'document_id' => 501];

        self::assertSame(ProjectModelLocatorFingerprint::for($first), ProjectModelLocatorFingerprint::for($second));
    }

    #[Test]
    public function evidence_requires_the_candidate_locator_not_merely_any_valid_locator(): void
    {
        $locator = ['document_id' => 501, 'unit_index' => 1, 'page' => 1, 'region_key' => 'region:'.str_repeat('a', 64), 'element_key' => 'element:'.str_repeat('b', 64), 'bbox' => [1, 2, 3, 4]];
        $wrong = [...$locator, 'element_key' => 'element:'.str_repeat('c', 64)];
        $evidence = ['type' => 'extracted', 'source_type' => 'document_unit', 'producer_name' => 'drawing_analyzer', 'producer_version' => 'model:v2', 'source_ref' => 'document:501', 'locator' => $locator, 'value' => ['field_key' => 'room_area', 'field_value' => 7.94, 'unit' => 'm2']];

        self::assertTrue(ProjectModelEvidenceContract::confirms('explicit_dimension', $evidence, ['value' => 7.94, 'unit' => 'm2'], $locator));
        self::assertFalse(ProjectModelEvidenceContract::confirms('explicit_dimension', $evidence, ['value' => 7.94, 'unit' => 'm2'], $wrong));
        self::assertFalse(ProjectModelEvidenceContract::confirms('explicit_dimension', $evidence, ['value' => 7.95, 'unit' => 'm2'], $locator));
    }
}
