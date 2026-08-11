<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Planning\OrganizationPreferenceContext;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class TechnologyRecommendationServiceTest extends TestCase
{
    private const SOURCE_VERSION = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_unknown_pitched_roof_material_returns_three_complete_systems_and_one_recommendation(): void
    {
        $catalog = TechnologySystemCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php');
        $service = new TechnologyRecommendationService(
            $catalog,
            static fn (string $key): string => $key,
        );
        $decision = $this->fact('fact:roof-material', 'material', null, 'unresolved', 'unresolved');
        $model = new ProjectModelSnapshot([], [
            $this->fact('fact:roof-type', 'roof_type', 'pitched'),
            $this->fact('fact:roof-slope', 'roof_slope_degrees', '28', unit: 'degree'),
            $this->fact('fact:roof-geometry', 'roof_geometry', 'simple_gable'),
            $this->fact('fact:roof-area', 'roof_area', '120', unit: 'm2'),
            $this->fact('fact:building-purpose', 'building_purpose', 'residential'),
            $this->fact('fact:climate', 'climate_zone', 'snow_3'),
        ], [], []);

        $recommendation = $service->recommend(
            $model,
            $decision,
            new OrganizationPreferenceContext(organizationId: 10, systemWeights: []),
        );

        self::assertSame('roof_covering_system', $recommendation->decisionKey);
        self::assertSame('pitched_roof.metal_tile', $recommendation->recommended->system->id);
        self::assertCount(3, $recommendation->options);
        self::assertCount(1, array_filter($recommendation->options, static fn ($option): bool => $option->recommended));
        $systemIds = array_map(
            static fn ($option): string => $option->system->id,
            $recommendation->options,
        );
        sort($systemIds, SORT_STRING);
        self::assertSame(['pitched_roof.flexible_shingle', 'pitched_roof.metal_tile', 'pitched_roof.standing_seam'], $systemIds);
        self::assertSame(['other', 'leave_unresolved'], array_column($recommendation->responseOptions, 'value'));
        self::assertFalse($recommendation->autoApply);
        self::assertFalse($recommendation->conditional);
        self::assertSame($catalog->version, $recommendation->catalogVersion);
        self::assertSame($catalog->contentHash, $recommendation->catalogHash);

        foreach ($recommendation->options as $option) {
            self::assertNotEmpty($option->scoreContributions);
            self::assertNotEmpty($option->system->materials);
            self::assertNotEmpty($option->system->works);
            self::assertNotEmpty($option->system->machinery);
            self::assertNotEmpty($option->system->quantityFormulas);
            self::assertNotEmpty($option->system->normIntents);
            self::assertArrayHasKey('available', $option->system->regionalPriceAvailability);
            self::assertArrayHasKey('available', $option->system->costPreview);
            self::assertNotEmpty($option->system->risks);
            self::assertNotEmpty($option->system->assumptions);
            self::assertNotEmpty($option->system->provenance);
        }
    }

    public function test_aggregate_ranking_is_permutation_stable_and_preferences_only_break_close_scores(): void
    {
        $facts = [
            $this->fact('fact:roof-type', 'roof_type', 'pitched'),
            $this->fact('fact:roof-slope', 'roof_slope_degrees', '28', unit: 'degree'),
            $this->fact('fact:roof-geometry', 'roof_geometry', 'simple_gable'),
            $this->fact('fact:building-purpose', 'building_purpose', 'residential'),
            $this->fact('fact:climate', 'climate_zone', 'snow_3'),
        ];
        $decision = $this->fact('fact:roof-material', 'material', null, 'unresolved', 'unresolved');
        $service = $this->service();

        $first = $service->recommend(new ProjectModelSnapshot([], $facts, [], []), $decision, new OrganizationPreferenceContext(10, []));
        $second = $service->recommend(new ProjectModelSnapshot([], array_reverse($facts), [], []), $decision, new OrganizationPreferenceContext(10, [
            'pitched_roof.flexible_shingle' => 2,
        ]));

        self::assertSame($first->recommended->system->id, $second->recommended->system->id);
        self::assertSame('pitched_roof.metal_tile', $second->recommended->system->id);

        $tie = $service->recommend(new ProjectModelSnapshot([], [
            $this->fact('fact:roof-type', 'roof_type', 'pitched'),
        ], [], []), $decision, new OrganizationPreferenceContext(10, [
            'pitched_roof.flexible_shingle' => 1,
        ]));
        self::assertSame('pitched_roof.flexible_shingle', $tie->recommended->system->id);
    }

    public function test_low_slope_is_ranked_from_geometry_and_not_from_catalog_order(): void
    {
        $recommendation = $this->service()->recommend(
            new ProjectModelSnapshot([], [
                $this->fact('fact:roof-type', 'roof_type', 'pitched'),
                $this->fact('fact:roof-slope', 'roof_slope_degrees', '5', unit: 'degree'),
                $this->fact('fact:roof-geometry', 'roof_geometry', 'simple_gable'),
                $this->fact('fact:roof-area', 'roof_area', '120', unit: 'm2'),
            ], [], []),
            $this->fact('fact:roof-material', 'material', null, 'unresolved', 'unresolved'),
            new OrganizationPreferenceContext(10, []),
        );

        self::assertSame('pitched_roof.standing_seam', $recommendation->recommended->system->id);
    }

    public function test_missing_facts_keep_recommendation_conditional_and_cross_tenant_is_rejected(): void
    {
        $decision = $this->fact('fact:roof-material', 'material', null, 'unresolved', 'unresolved');
        $recommendation = $this->service()->recommend(
            new ProjectModelSnapshot([], [$this->fact('fact:roof-type', 'roof_type', 'pitched')], [], []),
            $decision,
            new OrganizationPreferenceContext(10, []),
        );

        self::assertTrue($recommendation->conditional);
        self::assertSame(['roof_area', 'roof_geometry', 'roof_slope_degrees'], $recommendation->missingFacts);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->recommend(
            new ProjectModelSnapshot([], [$this->fact('fact:roof-type', 'roof_type', 'pitched')], [], []),
            $decision,
            new OrganizationPreferenceContext(11, []),
        );
    }

    public function test_select_other_and_leave_unresolved_use_canonical_decision_boundary_without_auto_apply(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $target = $this->fact('fact:roof-material', 'material', null, 'unresolved', 'unresolved');
        $repository->saveSourceModel([], [$target], []);
        $recommendation = $this->service()->recommend(
            new ProjectModelSnapshot([], [
                $target,
                $this->fact('fact:roof-type', 'roof_type', 'pitched'),
                $this->fact('fact:roof-slope', 'roof_slope_degrees', '28', unit: 'degree'),
                $this->fact('fact:roof-geometry', 'roof_geometry', 'simple_gable'),
            ], [], []),
            $target,
            new OrganizationPreferenceContext(10, []),
        );
        $decisions = new TechnologyRecommendationDecisionService(new ApplyProjectModelDecision($repository));

        self::assertNull($decisions->respond($recommendation, 'leave_unresolved', null, 'user:7', 'Оставить открытым', 'decision:leave'));
        self::assertCount(0, $repository->decisions);

        $selected = $decisions->respond(
            $recommendation,
            'pitched_roof.metal_tile',
            null,
            'user:7',
            'Выбран полный вариант кровельной системы',
            'decision:roof-system-1',
        );
        self::assertNotNull($selected);
        self::assertSame('user', $selected->actorType);
        self::assertCount(1, $repository->decisions);
        $selectedFact = $repository->fact(10, 20, 30, (string) $selected->selectedFactId);
        self::assertSame('user_assumption', $selectedFact?->origin);
        self::assertSame('pitched_roof.metal_tile', $selectedFact?->value['system_id'] ?? null);
        self::assertSame($recommendation->catalogVersion, $selectedFact?->value['catalog_version'] ?? null);

        $replayed = $decisions->respond(
            $recommendation,
            'pitched_roof.metal_tile',
            null,
            'user:7',
            'Выбран полный вариант кровельной системы',
            'decision:roof-system-1',
        );
        self::assertSame($selected->id, $replayed?->id);
        self::assertCount(1, array_filter(
            $repository->decisions,
            static fn ($decision): bool => $decision->id === 'decision:roof-system-1',
        ));

        $other = $decisions->respond(
            $recommendation,
            'other',
            'Натуральная черепица',
            'user:7',
            'Указан другой полный вариант',
            'decision:roof-system-2',
        );
        self::assertSame('Натуральная черепица', $repository->fact(10, 20, 30, (string) $other?->selectedFactId)?->value['other'] ?? null);
    }

    public function test_catalog_fails_closed_on_duplicates_and_keeps_candidates_bounded(): void
    {
        $data = require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php';
        foreach ($data['systems'] as $system) {
            self::assertLessThanOrEqual(40, count($system['materials']));
            self::assertLessThanOrEqual(40, count($system['works']));
            self::assertLessThanOrEqual(20, count($system['norm_intents']));
            foreach ($system['norm_intents'] as $intent) {
                self::assertLessThanOrEqual(5, $intent['max_candidates']);
            }
        }
        $data['systems'][] = $data['systems'][0];

        $this->expectException(\InvalidArgumentException::class);
        TechnologySystemCatalog::fromArray($data);
    }

    private function service(): TechnologyRecommendationService
    {
        return new TechnologyRecommendationService(
            TechnologySystemCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php'),
            static fn (string $key): string => $key,
        );
    }

    private function fact(
        string $id,
        string $type,
        mixed $value,
        string $origin = 'document',
        string $status = 'confirmed',
        ?string $unit = null,
    ): Fact {
        return new Fact(
            id: $id,
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            sourceVersion: self::SOURCE_VERSION,
            entityId: 'entity:roof',
            type: $type,
            value: $value,
            unit: $unit,
            confidence: $status === 'confirmed' ? 1.0 : 0.0,
            origin: $origin,
            status: $status,
            evidenceIds: $status === 'confirmed' ? ['evidence:1'] : [],
        );
    }
}
