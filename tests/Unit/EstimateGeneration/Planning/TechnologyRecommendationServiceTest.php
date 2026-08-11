<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\PlanningReanalysisTrigger;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectCompletenessCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningPipeline;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactArbitrator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactArbitratorFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingBudget;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\OrganizationPreferenceContext;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackageBuilder;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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

        self::assertStringStartsWith('roof_covering_system.', $recommendation->decisionKey);
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
        self::assertNull($tie->recommendedOption());
        self::assertTrue($tie->conditional);
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
        self::assertSame('applicable', $recommendation->recommended->applicabilityStatus);
        $unavailable = array_values(array_map(
            static fn ($option): string => $option->system->id,
            array_filter($recommendation->options, static fn ($option): bool => $option->applicabilityStatus === 'unavailable'),
        ));
        sort($unavailable, SORT_STRING);
        self::assertSame(
            ['pitched_roof.flexible_shingle', 'pitched_roof.metal_tile'],
            $unavailable,
        );
    }

    public function test_applicability_boundaries_and_invalid_units_fail_closed(): void
    {
        $expected = [
            ['3', 'degree', 'pitched_roof.standing_seam', 2],
            ['5', 'degree', 'pitched_roof.standing_seam', 2],
            ['12', 'degree', 'pitched_roof.standing_seam', 1],
            ['14', 'degree', 'pitched_roof.standing_seam', 0],
            [null, null, null, 0],
            ['14', null, null, 3],
            ['14', 'm', null, 3],
        ];
        foreach ($expected as [$slope, $unit, $recommended, $unavailable]) {
            $facts = [$this->fact('fact:roof-type', 'roof_type', 'pitched')];
            if ($slope !== null) {
                $facts[] = $this->fact('fact:roof-slope', 'roof_slope_degrees', $slope, unit: $unit);
            }
            $result = $this->service()->recommend(
                new ProjectModelSnapshot([], $facts, [], []),
                $this->fact('fact:roof-material', 'roof_covering_system', null, 'unresolved', 'unresolved'),
                new OrganizationPreferenceContext(10, []),
            );

            self::assertSame($recommended, $result->recommendedOption()?->system->id, (string) $slope.' '.$unit);
            self::assertSame($unavailable, count(array_filter(
                $result->options,
                static fn ($option): bool => $option->applicabilityStatus === 'unavailable',
            )), (string) $slope.' '.$unit);
        }
    }

    public function test_recommendation_and_selection_use_only_the_target_roof_facts(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $target = $this->fact('fact:roof-1-material', 'roof_covering_system', null, 'unresolved', 'unresolved', entityId: 'entity:roof-1');
        $facts = [
            $target,
            $this->fact('fact:roof-1-type', 'roof_type', 'pitched', entityId: 'entity:roof-1'),
            $this->fact('fact:roof-1-slope', 'roof_slope_degrees', '5', unit: 'degree', entityId: 'entity:roof-1'),
            $this->fact('fact:roof-1-base', 'solid_deck_type', 'boards', entityId: 'entity:roof-1'),
            $this->fact('fact:roof-2-type', 'roof_type', 'pitched', entityId: 'entity:roof-2'),
            $this->fact('fact:roof-2-slope', 'roof_slope_degrees', '20', unit: 'degree', entityId: 'entity:roof-2'),
            $this->fact('fact:roof-2-base', 'solid_deck_type', 'osb', entityId: 'entity:roof-2'),
        ];
        $repository->saveSourceModel([
            new Entity('entity:roof-1', 10, 20, 30, self::SOURCE_VERSION, 'material', 'roof:1', []),
            new Entity('entity:roof-2', 10, 20, 30, self::SOURCE_VERSION, 'material', 'roof:2', []),
        ], $facts, [new Evidence(
            'evidence:1', 10, 20, 30, self::SOURCE_VERSION, 'document:1', 'document', page: 1,
        )]);
        $recommendationService = $this->service();
        $recommendation = $recommendationService->recommend(
            new ProjectModelSnapshot([], $facts, [], []),
            $target,
            new OrganizationPreferenceContext(10, []),
        );

        $options = [];
        foreach ($recommendation->options as $option) {
            $options[$option->system->id] = $option;
        }
        self::assertSame('pitched_roof.standing_seam', $recommendation->recommendedOption()?->system->id);
        self::assertSame('unavailable', $options['pitched_roof.metal_tile']->applicabilityStatus);
        self::assertSame('unavailable', $options['pitched_roof.flexible_shingle']->applicabilityStatus);
        self::assertNotContains('fact:roof-2-slope', array_column(
            $recommendation->recommendedOption()?->applicabilityEvidence ?? [],
            'fact_id',
        ));

        $token = $repository->snapshotForPlanning(10, 20, 30, 10001)['token'];
        self::assertTrue($repository->replaceTechnologyRecommendations(
            10, 20, 30, self::SOURCE_VERSION, $token,
            $recommendation->catalogVersion, $recommendation->catalogHash, [$recommendation], [],
        ));
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $service = new TechnologyRecommendationDecisionService(
            $repository,
            new ApplyProjectModelDecision($repository),
            $recommendationService,
            $authorization,
            new class implements PlanningReanalysisTrigger
            {
                public function trigger(int $sessionId, ActorContext $context): void {}
            },
            static fn (string $key): string => 'Доступ запрещён',
        );
        $actor = new User;
        $actor->setAttribute('id', 7);
        $actor->setAttribute('current_organization_id', 10);
        $session = new EstimateGenerationSession;
        $session->setAttribute('id', 30);
        $session->setAttribute('organization_id', 10);
        $session->setAttribute('project_id', 20);

        $this->expectException(\InvalidArgumentException::class);
        $service->respond(
            $actor,
            $session,
            new ActorContext(10, 20, 7, 'multi-roof-selection', self::SOURCE_VERSION),
            1,
            $recommendation->decisionKey,
            'pitched_roof.metal_tile',
            null,
            'Проверка применимости к выбранной крыше',
        );
    }

    public function test_decision_key_is_entity_specific_and_alias_stable(): void
    {
        $model = new ProjectModelSnapshot([], [
            $this->fact('fact:roof-type', 'roof_type', 'pitched'),
            $this->fact('fact:roof-slope', 'roof_slope_degrees', '20', unit: 'degree'),
        ], [], []);
        $service = $this->service();
        $material = $service->recommend($model, $this->fact('fact:material', 'material', null, 'unresolved', 'unresolved'), new OrganizationPreferenceContext(10, []));
        $alias = $service->recommend($model, $this->fact('fact:material-name', 'material_name', null, 'unresolved', 'unresolved'), new OrganizationPreferenceContext(10, []));
        $otherRoof = $service->recommend($model, $this->fact('fact:other-roof', 'roof_covering_system', null, 'unresolved', 'unresolved', entityId: 'entity:roof-2'), new OrganizationPreferenceContext(10, []));

        self::assertSame($material->decisionKey, $alias->decisionKey);
        self::assertNotSame($material->decisionKey, $otherRoof->decisionKey);
        self::assertLessThanOrEqual(128, strlen($material->decisionKey));
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
        $facts = [
            $target,
            $this->fact('fact:roof-type', 'roof_type', 'pitched'),
            $this->fact('fact:roof-slope', 'roof_slope_degrees', '28', unit: 'degree'),
            $this->fact('fact:roof-geometry', 'roof_geometry', 'simple_gable'),
        ];
        $evidence = new Evidence(
            'evidence:1', 10, 20, 30, self::SOURCE_VERSION,
            'document:1', 'document', page: 1,
        );
        $repository->saveSourceModel([], $facts, [$evidence]);
        $recommendation = $this->service()->recommend(
            new ProjectModelSnapshot([], $facts, [], []),
            $target,
            new OrganizationPreferenceContext(10, []),
        );
        $token = $repository->snapshotForPlanning(10, 20, 30, 10001)['token'];
        self::assertTrue($repository->replaceTechnologyRecommendations(
            10, 20, 30, self::SOURCE_VERSION, $token,
            $recommendation->catalogVersion, $recommendation->catalogHash, [$recommendation], [],
        ));
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $trigger = new class implements PlanningReanalysisTrigger
        {
            public int $count = 0;

            public function trigger(int $sessionId, ActorContext $context): void
            {
                $this->count++;
            }
        };
        $decisions = new TechnologyRecommendationDecisionService(
            $repository,
            new ApplyProjectModelDecision($repository),
            $this->service(),
            $authorization,
            $trigger,
            static fn (string $key): string => 'Доступ запрещён',
        );
        $actor = new User;
        $actor->setAttribute('id', 7);
        $actor->setAttribute('current_organization_id', 10);
        $session = new EstimateGenerationSession;
        $session->setAttribute('id', 30);
        $session->setAttribute('organization_id', 10);
        $session->setAttribute('project_id', 20);
        $context = new ActorContext(10, 20, 7, 'technology-choice-0001', self::SOURCE_VERSION);

        self::assertNull($decisions->respond(
            $actor, $session, $context, 1, $recommendation->decisionKey,
            'leave_unresolved', null, 'Оставить открытым',
        ));
        self::assertCount(0, $repository->decisions);

        $selected = $decisions->respond(
            $actor, $session, $context, 1, $recommendation->decisionKey,
            'pitched_roof.metal_tile',
            null,
            'Выбран полный вариант кровельной системы',
        );
        self::assertNotNull($selected);
        self::assertSame('user', $selected->actorType);
        self::assertCount(1, $repository->decisions);
        $selectedFact = $repository->fact(10, 20, 30, (string) $selected->selectedFactId);
        self::assertSame('user_assumption', $selectedFact?->origin);
        self::assertSame('pitched_roof.metal_tile', $selectedFact?->value['system_id'] ?? null);
        self::assertSame($recommendation->catalogVersion, $selectedFact?->value['catalog_version'] ?? null);

        $replayed = $decisions->respond(
            $actor, $session, $context, 1, $recommendation->decisionKey,
            'pitched_roof.metal_tile',
            null,
            'Выбран полный вариант кровельной системы',
        );
        self::assertSame($selected->id, $replayed?->id);
        self::assertCount(1, $repository->decisions);
        self::assertSame(2, $trigger->count);
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

    public function test_selection_boundary_rejects_stale_inapplicable_unauthorized_and_cross_tenant_requests(): void
    {
        [$service, $repository, $actor, $session, $context, $recommendation] = $this->selectionFixture('5', true);
        try {
            $service->respond(
                $actor, $session, $context, 1, $recommendation->decisionKey,
                'pitched_roof.metal_tile', null, 'Проверка неприменимого варианта',
            );
            self::fail('Inapplicable system was selected.');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $repository->decisions);
        }

        [$service, , $actor, $session, $context, $recommendation] = $this->selectionFixture('20', false);
        $this->expectException(AuthorizationException::class);
        $service->respond(
            $actor, $session, $context, 1, $recommendation->decisionKey,
            'pitched_roof.standing_seam', null, 'Проверка прав',
        );
    }

    public function test_other_requires_explicit_value_and_never_trusts_a_forged_run_identifier(): void
    {
        [$service, $repository, $actor, $session, $context, $recommendation] = $this->selectionFixture('20', true);
        try {
            $service->respond(
                $actor, $session, $context, 999, $recommendation->decisionKey,
                'other', 'Натуральная черепица', 'Проверка сохранённой рекомендации',
            );
            self::fail('Forged planning run was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $repository->decisions);
        }
        try {
            $service->respond(
                $actor, $session, $context, 1, $recommendation->decisionKey,
                'other', '   ', 'Проверка обязательного значения',
            );
            self::fail('Empty other value was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $repository->decisions);
        }
        $selected = $service->respond(
            $actor, $session, $context, 1, $recommendation->decisionKey,
            'other', 'Натуральная черепица', 'Указан другой полный вариант',
        );

        self::assertSame(
            'Натуральная черепица',
            $repository->fact(10, 20, 30, (string) $selected?->selectedFactId)?->value['other'] ?? null,
        );
    }

    public function test_selection_boundary_rejects_cross_tenant_actor_context(): void
    {
        [$service, $repository, $actor, $session, , $recommendation] = $this->selectionFixture('20', true);

        try {
            $service->respond(
                $actor,
                $session,
                new ActorContext(11, 20, 7, 'cross-tenant-selection', self::SOURCE_VERSION),
                1,
                $recommendation->decisionKey,
                'pitched_roof.standing_seam',
                null,
                'Проверка границы организации',
            );
            self::fail('Cross-tenant selection was accepted.');
        } catch (AuthorizationException) {
            self::assertCount(0, $repository->decisions);
        }
    }

    public function test_selection_rechecks_snapshot_inside_atomic_persistence_boundary(): void
    {
        [$service, $repository, $actor, $session, $context, $recommendation] = $this->selectionFixture('20', true);
        $repository->beforeTechnologyDecisionLock = function () use ($repository): void {
            $repository->saveSourceModel([], [new Fact(
                'fact:roof-slope:v2', 10, 20, 30, self::SOURCE_VERSION, 'entity:roof',
                'roof_slope_degrees', '2', 'degree', 1.0, 'user_assumption', 'confirmed', [], 2,
                'fact:roof-slope',
            )], []);
        };

        try {
            $service->respond(
                $actor, $session, $context, 1, $recommendation->decisionKey,
                'pitched_roof.standing_seam', null, 'Проверка конкурентной замены',
            );
            self::fail('Concurrent snapshot replacement was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $repository->decisions);
        }
    }

    public function test_retry_after_post_commit_reanalysis_failure_recovers_and_payload_mismatch_fails_fast(): void
    {
        [, $repository, $actor, $session, $context, $recommendation] = $this->selectionFixture('20', true);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $trigger = new class($this->planningPipeline($repository)) implements PlanningReanalysisTrigger
        {
            public int $calls = 0;

            public function __construct(private ProjectPlanningPipeline $pipeline) {}

            public function trigger(int $sessionId, ActorContext $context): void
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new RuntimeException('reanalysis failed after commit');
                }
                $this->pipeline->refresh(
                    $context->organizationId,
                    $context->projectId,
                    $sessionId,
                    $context->idempotencyKey,
                    $this->calls,
                );
            }
        };
        $service = new TechnologyRecommendationDecisionService(
            $repository,
            new ApplyProjectModelDecision($repository),
            $this->service(),
            $authorization,
            $trigger,
            static fn (string $key): string => 'Доступ запрещён',
        );

        try {
            $service->respond(
                $actor,
                $session,
                $context,
                1,
                $recommendation->decisionKey,
                'pitched_roof.standing_seam',
                null,
                'Выбран вариант после проверки',
            );
            self::fail('Injected reanalysis failure was not propagated.');
        } catch (RuntimeException $exception) {
            self::assertSame('reanalysis failed after commit', $exception->getMessage());
        }
        self::assertCount(1, $repository->decisions);

        $recovered = $service->respond(
            $actor,
            $session,
            $context,
            1,
            $recommendation->decisionKey,
            'pitched_roof.standing_seam',
            null,
            'Выбран вариант после проверки',
        );

        self::assertNotNull($recovered);
        self::assertSame(2, $trigger->calls);
        self::assertCount(1, $repository->decisions);
        self::assertNotNull($repository->currentCompleteness(10, 20, 30));

        $service->respond(
            $actor,
            $session,
            $context,
            1,
            $recommendation->decisionKey,
            'pitched_roof.standing_seam',
            null,
            'Выбран вариант после проверки',
        );
        self::assertSame(2, $trigger->calls);

        foreach ([
            [$recommendation->decisionKey, 'pitched_roof.metal_tile', null, 'Выбран вариант после проверки'],
            ['roof_covering_system.changed', 'pitched_roof.standing_seam', null, 'Выбран вариант после проверки'],
            [$recommendation->decisionKey, 'pitched_roof.standing_seam', null, 'Изменённая причина'],
        ] as [$decisionKey, $response, $other, $reason]) {
            try {
                $service->respond($actor, $session, $context, 1, $decisionKey, $response, $other, $reason);
                self::fail('Idempotency payload mismatch was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(2, $trigger->calls);
    }

    public function test_catalog_hash_is_permutation_stable_and_sensitive_to_meaningful_changes(): void
    {
        $data = require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php';
        $first = TechnologySystemCatalog::fromArray($data);
        $permuted = $data;
        $permuted['systems'] = array_reverse($permuted['systems']);
        foreach ($permuted['systems'] as &$system) {
            $system['materials'] = array_reverse($system['materials']);
            $system['works'] = array_reverse($system['works']);
        }
        unset($system);
        self::assertSame($first->contentHash, TechnologySystemCatalog::fromArray($permuted)->contentHash);

        $changed = $data;
        $changed['systems'][0]['applicability'][1]['minimum_slope_degrees'] = '15';
        self::assertNotSame($first->contentHash, TechnologySystemCatalog::fromArray($changed)->contentHash);
    }

    public function test_catalog_rejects_bad_formula_units_unknown_keys_and_global_budgets(): void
    {
        $base = require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php';
        $invalid = [];
        $badUnit = $base;
        $badUnit['systems'][0]['quantity_formulas'][0]['result_unit'] = 'unknown-unit';
        $invalid[] = $badUnit;
        $badOperand = $base;
        $badOperand['systems'][0]['quantity_formulas'][0]['expression'] = 'roof_area * absent_operand';
        $invalid[] = $badOperand;
        $unknown = $base;
        $unknown['systems'][0]['unexpected'] = true;
        $invalid[] = $unknown;
        $oversized = $base;
        $oversized['systems'][0]['risks'][0] = str_repeat('x', 1_100_000);
        $invalid[] = $oversized;

        foreach ($invalid as $data) {
            try {
                TechnologySystemCatalog::fromArray($data);
                self::fail('Invalid catalog was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_catalog_requires_every_runtime_field_and_rejects_invalid_nested_types(): void
    {
        $base = require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php';
        $invalid = [];
        foreach ([
            ['materials', 0, 'intent'],
            ['works', 0, 'intent'],
            ['machinery', 0, 'intent'],
            ['norm_intents', 0, 'stable_intent'],
            ['quantity_formulas', 0, 'result_unit'],
        ] as [$collection, $index, $field]) {
            $candidate = $base;
            unset($candidate['systems'][0][$collection][$index][$field]);
            $invalid[] = $candidate;
        }
        $badScoreValues = $base;
        $badScoreValues['systems'][0]['score_rules'][0]['values'] = 'pitched';
        $invalid[] = $badScoreValues;
        $badAvailability = $base;
        $badAvailability['systems'][0]['regional_price_availability']['available'] = 'false';
        $invalid[] = $badAvailability;
        $badRequiredFacts = $base;
        $badRequiredFacts['recommendation_required_facts'][0] = 123;
        $invalid[] = $badRequiredFacts;
        $badApplicability = $base;
        $badApplicability['systems'][0]['applicability'] = 'roof_type';
        $invalid[] = $badApplicability;
        $badSlopeApplicability = $base;
        $badSlopeApplicability['systems'][0]['applicability'][1]['minimum_slope_degrees'] = 'unknown';
        $invalid[] = $badSlopeApplicability;
        $tooManyMaterials = $base;
        $tooManyMaterials['systems'][0]['materials'] = array_map(
            static fn (int $index): array => ['id' => 'material:'.$index, 'intent' => 'intent:'.$index],
            range(1, 51),
        );
        $invalid[] = $tooManyMaterials;

        foreach ($invalid as $data) {
            try {
                TechnologySystemCatalog::fromArray($data);
                self::fail('Incomplete or mistyped technology catalog was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function service(): TechnologyRecommendationService
    {
        return new TechnologyRecommendationService(
            TechnologySystemCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php'),
            static fn (string $key): string => $key,
        );
    }

    private function selectionFixture(string $slope, bool $allowed): array
    {
        $repository = new InMemoryProjectModelRepository;
        $target = $this->fact('fact:roof-material', 'material', null, 'unresolved', 'unresolved');
        $facts = [
            $target,
            $this->fact('fact:roof-type', 'roof_type', 'pitched'),
            $this->fact('fact:roof-slope', 'roof_slope_degrees', $slope, unit: 'degree'),
            $this->fact('fact:roof-geometry', 'roof_geometry', 'simple_gable'),
        ];
        $repository->saveSourceModel([new Entity(
            'entity:roof', 10, 20, 30, self::SOURCE_VERSION, 'material', 'roof:main', [],
        )], $facts, [new Evidence(
            'evidence:1', 10, 20, 30, self::SOURCE_VERSION, 'document:1', 'document', page: 1,
        )]);
        $recommendationService = $this->service();
        $recommendation = $recommendationService->recommend(
            new ProjectModelSnapshot([], $facts, [], []), $target, new OrganizationPreferenceContext(10, []),
        );
        $token = $repository->snapshotForPlanning(10, 20, 30, 10001)['token'];
        $repository->replaceTechnologyRecommendations(
            10, 20, 30, self::SOURCE_VERSION, $token,
            $recommendation->catalogVersion, $recommendation->catalogHash, [$recommendation], [],
        );
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn($allowed);
        $trigger = new class implements PlanningReanalysisTrigger
        {
            public function trigger(int $sessionId, ActorContext $context): void {}
        };
        $service = new TechnologyRecommendationDecisionService(
            $repository, new ApplyProjectModelDecision($repository), $recommendationService, $authorization, $trigger,
            static fn (string $key): string => 'Доступ запрещён',
        );
        $actor = new User;
        $actor->setAttribute('id', 7);
        $actor->setAttribute('current_organization_id', 10);
        $session = new EstimateGenerationSession;
        $session->setAttribute('id', 30);
        $session->setAttribute('organization_id', 10);
        $session->setAttribute('project_id', 20);

        return [$service, $repository, $actor, $session, new ActorContext(
            10, 20, 7, 'technology-fixture-'.str_pad($slope, 8, '0'), self::SOURCE_VERSION,
        ), $recommendation];
    }

    private function planningPipeline(InMemoryProjectModelRepository $repository): ProjectPlanningPipeline
    {
        $translator = static fn (string $key, array $replace = []): string => strtr($key, $replace);
        $technology = TechnologySystemCatalog::fromArray(
            require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php',
        );
        $rules = CompletenessRuleCatalog::fromArray(
            require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php',
        );

        return new ProjectPlanningPipeline(
            new ProjectUnderstandingCoordinator(
                $repository,
                new TargetedConflictResolver($translator),
                new TechnologyRetryNoopArbitratorFactory,
                ProjectUnderstandingBudget::defaults(),
            ),
            new ProjectPlanningCoordinator(
                $repository,
                new TechnologyRecommendationService($technology, $translator),
                $technology,
                100,
                10,
            ),
            new ProjectCompletenessCoordinator(
                $repository,
                new ProjectCompletenessAnalyzer(
                    $rules,
                    new TechnologyWorkPackageBuilder(static fn (string $key): string => 'Человекочитаемое название'),
                    50,
                    50,
                    200,
                ),
                $rules,
                100,
            ),
        );
    }

    private function fact(
        string $id,
        string $type,
        mixed $value,
        string $origin = 'document',
        string $status = 'confirmed',
        ?string $unit = null,
        string $entityId = 'entity:roof',
    ): Fact {
        return new Fact(
            id: $id,
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            sourceVersion: self::SOURCE_VERSION,
            entityId: $entityId,
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

final class TechnologyRetryNoopArbitratorFactory implements CrossDocumentFactArbitrator, CrossDocumentFactArbitratorFactory
{
    public function create(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $checkpointClaimToken,
        int $logicalAttempt,
    ): CrossDocumentFactArbitrator {
        return $this;
    }

    public function arbitrate(string $operationIdentity, array $payload, array $scope): array
    {
        return ['status' => 'unresolved', 'selected_fact_id' => null, 'reason' => 'not_required'];
    }
}
