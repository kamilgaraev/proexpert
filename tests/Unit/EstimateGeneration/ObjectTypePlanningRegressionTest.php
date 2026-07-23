<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObjectTypePlanningRegressionTest extends TestCase
{
    #[DataProvider('residentialDescriptions')]
    public function test_residential_wording_is_classified_as_house_and_receives_only_residential_plan(string $description): void
    {
        [$analysis, $profile, $packageKeys] = $this->parseAndPlan($description);

        self::assertSame('house', $analysis['object']['object_type']);
        self::assertSame('residential', ObjectTypeSignalClassifier::canonical($profile->objectType));
        self::assertContains('foundation', $packageKeys);
        self::assertContains('walls', $packageKeys);
        self::assertNotContains('industrial_floor', $packageKeys);
        self::assertNotContains('metal_frame', $packageKeys);
        self::assertNotContains('office_partitions', $packageKeys);
    }

    #[DataProvider('nonResidentialDescriptions')]
    public function test_industrial_or_warehouse_wording_never_becomes_residential_or_receives_house_catalog(
        string $description,
        string $expectedCanonicalType,
    ): void {
        [$analysis, $profile, $packageKeys] = $this->parseAndPlan($description);

        self::assertNotSame('house', $analysis['object']['object_type']);
        self::assertSame($expectedCanonicalType, ObjectTypeSignalClassifier::canonical($profile->objectType));
        self::assertContains('industrial_floor', $packageKeys);
        self::assertContains('metal_frame', $packageKeys);
        self::assertNotContains('office_partitions', $packageKeys);
        self::assertNotContains('foundation', $packageKeys);
        self::assertNotContains('walls', $packageKeys);
        self::assertNotContains('finish_finishing', $packageKeys);
    }

    public static function residentialDescriptions(): iterable
    {
        yield 'object_izhs' => ['Объект ИЖС'];
        yield 'individual_housing_construction' => ['Индивидуальное жилищное строительство'];
    }

    public static function nonResidentialDescriptions(): iterable
    {
        yield 'warehouse' => ['Склад', 'warehouse'];
        yield 'production_building' => ['Производственный корпус', 'industrial'];
        yield 'factory' => ['Завод', 'industrial'];
    }

    /**
     * @return array{0:array<string,mixed>,1:\App\BusinessModules\Addons\EstimateGeneration\DTOs\ObjectProfileData,2:list<string>}
     */
    private function parseAndPlan(string $description): array
    {
        $analysis = (new ConstructionSemanticParser)->parse([
            'description' => $description,
        ], []);
        $planner = new PackagePlannerService;
        $profile = $planner->profileFromAnalysis($analysis);
        $packageKeys = array_values(array_column($planner->plan($profile)->packages, 'key'));

        return [$analysis, $profile, $packageKeys];
    }
}
