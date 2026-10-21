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

    #[DataProvider('evaluationProfiles')]
    public function test_unknown_keeps_only_document_supported_packages_and_input_uncertainty(?string $evaluationProfile): void
    {
        $planner = new PackagePlannerService;
        $analysis = ['object' => ['building_type' => 'bridge'], 'evaluation_policy' => ['profile_id' => $evaluationProfile],
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

    public static function evaluationProfiles(): iterable
    {
        yield 'default' => [null];
        yield 'industrial' => ['industrial'];
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

    #[DataProvider('explicitIdentityInputs')]
    public function test_explicit_identity_precedes_incidental_residential_context(array $input, string $expected): void
    {
        $planner = new PackagePlannerService;
        $analysis = (new ConstructionSemanticParser)->parse($input, []);
        $profile = $planner->profileFromAnalysis($analysis);
        foreach (['object_type', 'building_type'] as $key) {
            if (array_key_exists($key, $input)) { self::assertSame($input[$key], $analysis['object'][$key]); }
        }
        self::assertSame($expected, $profile->objectType);
        if (! in_array($expected, ['house', 'warehouse', 'industrial'], true)) {
            self::assertSame([], $planner->plan($profile)->packages);
            self::assertSame('document_limited', $planner->documentRequirements($profile)['coverage']);
            self::assertTrue($planner->documentRequirements($profile)['missing_for_full_estimate']);
        } else {
            self::assertNotEmpty($planner->plan($profile)->packages);
        }
    }

    public static function explicitIdentityInputs(): iterable
    {
        foreach (['bridge', 'hospital', 'warehouse', 'industrial', 'orbital_lab_42'] as $type) {
            yield 'object_type_'.$type => [['object_type' => $type, 'description' => 'Рядом находится жилой дом'], $type];
        }
        yield 'building_type_bridge' => [['building_type' => 'bridge', 'description' => 'Рядом находится жилой дом'], 'bridge'];
        yield 'conflicting_residential_object' => [['object_type' => 'house', 'building_type' => 'bridge'], 'custom'];
        yield 'conflicting_residential_building' => [['object_type' => 'bridge', 'building_type' => 'house'], 'custom'];
        yield 'conflicting_known_nonresidential' => [['object_type' => 'warehouse', 'building_type' => 'industrial'], 'custom'];
        yield 'compatible_residential_aliases' => [['object_type' => 'house', 'building_type' => 'residential'], 'house'];
        yield 'known_residential_object' => [['object_type' => 'residential'], 'house'];
        yield 'known_residential_building' => [['building_type' => 'house'], 'house'];
        yield 'blank_legacy' => [[], 'custom'];
        yield 'blank_legacy_fields' => [['object_type' => '', 'building_type' => '', 'description' => 'Жилой дом'], 'house'];
        yield 'neutral_custom_with_named_unknown' => [['object_type' => 'bridge', 'building_type' => 'custom', 'description' => 'Рядом находится жилой дом'], 'bridge'];
        yield 'primary_legacy_residential' => [['description' => 'Жилой дом'], 'house'];
    }

    public function test_conflicting_types_keep_explicit_and_document_work_without_defaults(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse([
            'object_type' => 'warehouse', 'building_type' => 'industrial', 'description' => 'Устройство стен',
        ], [[
            'id' => 82, 'filename' => 'foundation.pdf', 'status' => 'ready', 'quality' => ['level' => 'good'],
            'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'quantity_takeoffs' => [['quantity' => 12, 'unit' => 'м3', 'source_refs' => [['document_id' => 82]],
                'normalized_payload' => ['quantity_key' => 'foundation.concrete']]],
        ]]);
        $planner = new PackagePlannerService;
        $profile = $planner->profileFromAnalysis($analysis);
        self::assertSame('custom', $profile->objectType);
        self::assertSame(['custom-foundation', 'walls'], array_column($planner->plan($profile)->packages, 'key'));
        self::assertSame('document_limited', $planner->documentRequirements($profile)['coverage']);
    }

    #[DataProvider('unknownIndustrialProfileTypes')]
    public function test_industrial_evaluation_profile_is_not_object_identity_or_document_evidence(string $type): void
    {
        $analysis = (new ConstructionSemanticParser)->parse(['object_type' => $type, 'description' => 'Новый объект'], []);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'profile_id' => 'industrial', 'selected_sections' => ['structures']];
        $planner = new PackagePlannerService;
        $profile = $planner->profileFromAnalysis($analysis);
        self::assertSame($type, $profile->objectType);
        self::assertSame([], $planner->plan($profile)->packages);
        self::assertSame('document_limited', $planner->documentRequirements($profile)['coverage']);
        self::assertTrue($planner->documentRequirements($profile)['missing_for_full_estimate']);
    }

    public static function unknownIndustrialProfileTypes(): iterable
    {
        foreach (['bridge', 'custom', 'orbital_lab_42'] as $type) { yield $type => [$type]; }
    }

    public function test_industrial_evaluation_profile_preserves_known_primary_types_and_industrial_aliases(): void
    {
        $planner = new PackagePlannerService;
        foreach (['industrial', 'factory', 'производственный', 'house', 'warehouse'] as $type) {
            $expectedType = in_array($type, ['factory', 'производственный'], true) ? 'industrial' : $type;
            $expected = $planner->plan(ObjectProfileData::fromArray(['object_type' => $expectedType]));
            $profile = ObjectProfileData::fromArray(['object_type' => $type, 'planning_signals' => ['evaluation_profile' => 'industrial']]);
            self::assertSame(array_column($expected->packages, 'key'), array_column($planner->plan($profile)->packages, 'key'), $type);
            self::assertSame('document_backed', $planner->documentRequirements($profile)['coverage']);
        }
    }
}
