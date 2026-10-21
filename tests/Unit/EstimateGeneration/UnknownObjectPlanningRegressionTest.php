<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\ObjectProfileData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnknownObjectPlanningRegressionTest extends TestCase
{
    #[DataProvider('unknownTypes')]
    public function test_unknown_type_without_evidence_has_no_template_and_requires_scope(string $type): void
    {
        $planner = new PackagePlannerService;
        $profile = $planner->profileFromAnalysis(['object' => ['building_type' => $type]]);

        self::assertSame($type, $profile->objectType);
        self::assertSame([], $planner->plan($profile)->packages);
        self::assertSame('document_limited', $planner->documentRequirements($profile)['coverage']);
        self::assertTrue($planner->documentRequirements($profile)['missing_for_full_estimate']);
        self::assertNotEmpty($planner->documentRequirements($profile)['items']);

        $parsed = (new ConstructionSemanticParser)->parse(['building_type' => $type, 'description' => 'Новый объект'], []);
        $parsedProfile = $planner->profileFromAnalysis($parsed);
        self::assertSame($type, $parsedProfile->objectType);
        self::assertSame([], $planner->plan($parsedProfile)->packages);
    }

    public static function unknownTypes(): iterable
    {
        foreach (['private_building', 'apartment_building', 'housing_district', 'heritage_reconstruction',
            'business_center', 'shopping_center', 'hospital', 'school', 'hotel_complex', 'automated_logistics',
            'machine_building_plant', 'metallurgical_complex', 'livestock_complex', 'poultry_farm',
            'greenhouse_complex', 'grain_elevator', 'thermal_power_station', 'substation_with_transmission',
            'bridge', 'sewage_treatment', 'never_seen_orbital_habitat', 'custom'] as $type) {
            yield $type => [$type];
        }
    }

    public function test_explicit_unknown_is_not_reclassified_by_a_residential_element(): void
    {
        $planner = new PackagePlannerService;
        $profile = $planner->profileFromAnalysis([
            'object' => ['building_type' => 'bridge', 'description' => 'Мост у жилого дома',
                'zones' => [['label' => 'Жилой дом']]],
            'document_context' => ['context_text' => 'Жилой дом. Стены и окна соседнего здания.'],
        ]);

        self::assertSame('bridge', $profile->objectType);
        self::assertSame([], $planner->plan($profile)->packages);
    }

    public function test_parser_defaults_are_not_evidence_for_an_unspecified_object(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['description' => 'Нужна смета на новый объект'], []);
        $planner = new PackagePlannerService;
        $profile = $planner->profileFromAnalysis($analysis);

        self::assertSame('custom', $profile->objectType);
        self::assertSame([], $planner->plan($profile)->packages);
        self::assertSame('document_limited', $planner->documentRequirements($profile)['coverage']);
    }

    public function test_parser_keeps_a_requested_known_work_scope_without_a_building_template(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['description' => 'Устройство фундамента'], []);
        $planner = new PackagePlannerService;
        $profile = $planner->profileFromAnalysis($analysis);

        self::assertSame('custom', $profile->objectType);
        self::assertSame(['foundation'], array_column($planner->plan($profile)->packages, 'key'));
        self::assertSame('document_limited', $planner->documentRequirements($profile)['coverage']);
    }

    public function test_parser_preserves_an_explicit_unknown_identity_with_residential_context(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse([
            'building_type' => 'bridge', 'description' => 'Мост у жилого дома',
        ], []);
        $planner = new PackagePlannerService;
        $profile = $planner->profileFromAnalysis($analysis);

        self::assertSame('bridge', $analysis['object']['object_type']);
        self::assertSame('bridge', $profile->objectType);
        self::assertSame([], $planner->plan($profile)->packages);
    }

    public function test_document_mention_without_primary_residential_identity_does_not_enable_house(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['description' => 'Новый объект'], [[
            'id' => 82, 'filename' => 'adjacent.pdf', 'status' => 'ready',
            'quality' => ['level' => 'good'], 'extracted_text' => 'Рядом находится жилой дом.',
            'document_understanding' => ['role_for_estimation' => 'quantity_source'],
        ]]);
        $planner = new PackagePlannerService;
        $profile = $planner->profileFromAnalysis($analysis);

        self::assertSame('custom', $profile->objectType);
        self::assertSame([], $planner->plan($profile)->packages);
    }

    public function test_unknown_keeps_only_document_supported_packages_and_input_uncertainty(): void
    {
        $planner = new PackagePlannerService;
        $analysis = ['object' => ['building_type' => 'bridge'],
            'assumptions' => ['Требуется обследование'], 'missing_inputs' => ['Спецификация опор'],
            'document_context' => ['quantity_takeoffs' => [[
                'quantity_key' => 'foundation.concrete', 'quantity' => 12, 'unit' => 'м3',
                'source_refs' => [['document_id' => 82, 'page_number' => 3]],
            ]]]];
        $profile = $planner->profileFromAnalysis($analysis);
        $plan = $planner->plan($profile);

        self::assertSame('bridge', $profile->objectType);
        self::assertSame(['custom-foundation'], array_column($plan->packages, 'key'));
        self::assertSame($analysis['assumptions'], $plan->assumptions);
        self::assertSame($analysis['missing_inputs'], $profile->missingInputs);
        self::assertSame('document_limited', $planner->documentRequirements($profile)['coverage']);
    }

    public function test_known_warehouse_keeps_the_existing_mixed_office_specialization(): void
    {
        $planner = new PackagePlannerService;
        $profile = $planner->profileFromAnalysis([
            'object' => ['building_type' => 'warehouse'],
            'document_context' => ['context_text' => 'Склад с офисной зоной'],
        ]);

        self::assertSame('mixed_warehouse_office', $profile->objectType);
        self::assertContains('office_partitions', array_column($planner->plan($profile)->packages, 'key'));
    }

    #[DataProvider('knownTypes')]
    public function test_known_profiles_keep_their_existing_completeness_without_evaluation_policy(string $type): void
    {
        $planner = new PackagePlannerService;
        $analysis = (new ConstructionSemanticParser)->parse(['building_type' => $type], []);
        foreach ([ObjectProfileData::fromArray(['object_type' => $type]), $planner->profileFromAnalysis($analysis)] as $profile) {
            $requirements = $planner->documentRequirements($profile);
            self::assertSame('document_backed', $requirements['coverage']);
            self::assertFalse($requirements['missing_for_full_estimate']);
            self::assertSame([], $requirements['items']);
        }
    }

    public static function knownTypes(): iterable
    {
        foreach (['house', 'warehouse', 'industrial', 'mixed_warehouse_office'] as $type) {
            yield $type => [$type];
        }
    }
}
