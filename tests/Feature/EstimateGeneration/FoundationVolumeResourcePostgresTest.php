<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\InspectFactoryInputs;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\InspectFactoryPlanning;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\MaterializeFactoryDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\SelectNormativeCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveSessionPricingSource;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class FoundationVolumeResourcePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_factory_foundation_keeps_formula_resources_and_structural_coverage_but_requires_preparation(): void
    {
        [$actor, $session, , , $revision] = $this->prepareFoundation();
        $before = $session->fresh()->getRawOriginal();
        try {
            $this->confirm($actor, $session, $revision);
            self::fail('An incomplete foundation scope was confirmed.');
        } catch (ValidationException $exception) {
            self::assertSame(['scenario'], array_keys($exception->errors()));
        }
        self::assertSame($before, $session->fresh()->getRawOriginal());
        self::assertArrayNotHasKey('confirmed_evaluation', $session->input_payload);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    public function test_public_height_change_invalidates_the_actual_factory_row_at_the_apply_quantity_guard(): void
    {
        [$actor, $session, , $quantity] = $this->prepareFoundation();
        $draft = $session->fresh()->draft_payload;
        $key = $this->concrete($session)['key'];
        foreach ($draft['local_estimates'] as &$local) {
            foreach ($local['sections'] as &$section) {
                $section['work_items'] = array_values(array_filter($section['work_items'], static fn (array $item): bool => $item['key'] === $key));
            }
            unset($section);
        }
        unset($local);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $guard = app(CurrentDraftQuantityProofGuard::class);
        DB::transaction(fn () => $guard->assertCurrent(...[...$scope, $draft]));
        self::addToAssertionCount(1);
        $saved = app(SaveManualProjectFacts::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid(), ['entities' => [[
                'key' => 'changed-height', 'type' => 'foundation_beam', 'existing_entity_id' => $quantity['formula_inputs']['entity_id'],
                'parameters' => ['height' => ['value' => '800', 'unit' => 'mm', 'basis' => 'input']],
            ]]]);
        self::assertSame('4', $saved['quantities']['foundation_volume']['amount']);
        self::assertArrayNotHasKey('confirmed_evaluation', $session->fresh()->input_payload);
        try {
            DB::transaction(fn () => $guard->assertCurrent(...[...$scope, $draft]));
            self::fail('The apply quantity guard accepted an outdated foundation row.');
        } catch (ValidationException $exception) {
            self::assertSame(['draft' => [trans_message('estimate_generation.stage6_snapshot_stale')]], $exception->errors());
        }
        Http::assertNothingSent();
    }

    private function prepareFoundation(): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        config(['cache.default' => 'array', 'estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $authorization->allows('can')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        foreach ([['Кубический метр', 'м3', 'volume'], ['Час', 'ч', 'time']] as [$name, $unit, $type]) {
            MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => $name, 'short_name' => $unit, 'type' => $type]);
        }
        $catalog = $this->catalog();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => [
                'evaluation_mode' => 'universal', 'building_type' => 'Одноэтажный жилой дом', 'floors' => 1,
                'selected_sections' => ['structures'], 'profile_id' => 'construction', 'construction_type' => 'new_construction',
                'price_policy' => 'normative', 'regional_context' => $catalog['context'],
            ]]);
        app(SaveSessionPricingSource::class)->handle($actor, (int) $project->id, (int) $session->id,
            ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                'estimate_regional_price_version_id' => $catalog['context']['estimate_regional_price_version_id'],
                'conditions' => ['as_of_date' => '2026-10-01', 'vat_mode' => 'excluded', 'vat_rate' => '22',
                    'delivery' => 'outside_scope', 'basis' => 'Синтетическая проверка объёма и трудозатрат; цена не является рыночной.']]);
        $saved = app(SaveManualProjectFacts::class)->handle($actor, (int) $project->id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid(), ['entities' => [[
                'key' => 'beam', 'type' => 'foundation_beam', 'parameters' => [
                    'length' => ['value' => '10000', 'unit' => 'mm', 'basis' => 'input'],
                    'width' => ['value' => '500', 'unit' => 'mm', 'basis' => 'input'],
                    'height' => ['value' => '600', 'unit' => 'mm', 'basis' => 'input'],
                ],
            ]]]);
        $quantity = $saved['quantities']['foundation_volume'];
        self::assertSame('3', $quantity['amount']);
        self::assertSame('foundation_beam_volume', $quantity['formula_key']);
        self::assertCount(3, $quantity['formula_inputs']['operands']);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 100])['snapshot'];
        $factIds = [];
        foreach ($snapshot->facts as $fact) {
            if (in_array($fact->type, ['length', 'width', 'height'], true)) {
                self::assertSame('user_input', $fact->origin);
                self::assertSame([], $fact->nativeNumericProofs);
                self::assertSame($quantity['formula_inputs']['entity_id'], $fact->entityId);
                $factIds[] = $fact->id;
            }
        }
        self::assertCount(3, $factIds);
        self::assertEqualsCanonicalizing($factIds, array_column($quantity['formula_inputs']['operands'], 'fact_id'));
        $source = app(InspectFactoryInputs::class)->handle($actor, (int) $project->id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid());
        $planning = app(InspectFactoryPlanning::class)->handle($actor, (int) $project->id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid(), $source['checkpoint']['id']);
        $materialized = app(MaterializeFactoryDraft::class)->handle($actor, (int) $project->id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid(), $planning['checkpoint']['id']);
        self::assertSame('draft_materialized', $materialized['checkpoint']['status'], json_encode($materialized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $item = $this->concrete($session);
        self::assertSame('3', $item['quantity']);
        self::assertSame('m3', $item['unit']);
        self::assertSame('structures', $item['metadata']['requirement_key']);
        self::assertSame($quantity['formula_inputs']['entity_id'], $item['metadata']['scope_entity_id']);
        self::assertEqualsCanonicalizing($factIds, array_column($item['quantity_evidence']['formula_inputs']['operands'], 'fact_id'));
        self::assertNotEmpty($item['quantity_evidence_id']);
        app(SelectNormativeCandidate::class)->handle((int) $session->id, (int) $organization->id, (int) $project->id,
            $actor, $session->fresh()->state_version, $item['key'], $catalog['norm_id'], true);
        app(SaveEvaluationScopeSelection::class)->handle($actor, (int) $project->id, (int) $session->id,
            ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                'selected_sections' => ['structures'], 'profile_id' => 'construction', 'construction_type' => 'new_construction', 'price_policy' => 'normative',
                'selected_work_item_keys' => [$item['key']], 'artifact_hash' => $session->fresh()->draft_payload['artifact_hash'],
                'scope_reason' => 'Проверяется только бетонирование фундаментной балки по явно введённым размерам.']);
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, (int) $project->id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid());
        $item = $this->concrete($session);
        self::assertSame('3', $item['quantity']);
        self::assertSame('0.01', NormativeUnitNormalizer::safeQuantityFactorDecimal($item['unit'], $item['normative_match']['unit']));
        self::assertSame('0.3', $item['labor'][0]['quantity']);
        self::assertSame('0.1', (string) BigDecimal::of($item['labor'][0]['quantity_per_work_unit'])->strippedOfTrailingZeros());
        self::assertSame('30.00', $item['total_cost']);
        self::assertSame($catalog['resource_id'], $item['labor'][0]['normative_ref']['norm_resource_id']);
        self::assertSame($catalog['price_id'], $item['labor'][0]['normative_ref']['price_id']);
        self::assertSame('30.00', $revision['result']['scenarios'][0]['known_subtotals']['RUB']);
        $coverage = $revision['result']['coverage'];
        self::assertSame(['structures'], array_column($coverage['covered_requirements'], 'requirement'));
        self::assertSame([$item['key']], $coverage['covered_requirements'][0]['position_keys']);
        self::assertSame($quantity['formula_inputs']['entity_id'], $coverage['covered_requirements'][0]['entity_id']);
        self::assertSame(['preparation'], array_column($revision['result']['missing_requirements'], 'requirement'));
        self::assertSame(['work_scope_missing'], array_column($revision['result']['missing_requirements'], 'reason'));
        self::assertSame($quantity['formula_inputs']['entity_id'], $revision['result']['missing_requirements'][0]['entity_id']);
        self::assertTrue($revision['result']['scope_confirmed']);
        self::assertFalse($revision['result']['can_confirm_for_apply']);

        return [$actor, $session, $catalog, $quantity, $revision];
    }

    private function concrete(EstimateGenerationSession $session): array
    {
        $items = [];
        foreach ($session->fresh()->draft_payload['local_estimates'] as $local) {
            foreach ($local['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    if (($item['metadata']['composition_work_key'] ?? null) === 'foundation.concrete') {
                        $items[] = $item;
                    }
                }
            }
        }
        self::assertCount(1, $items);

        return $items[0];
    }

    private function confirm(User $actor, EstimateGenerationSession $session, array $revision): array
    {
        return app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'],
                'scenario_id' => 'base', 'assumptions_accepted' => true]);
    }

    private function catalog(): array
    {
        $now = now();
        $suffix = strtolower((string) Str::ulid());
        $key = 'synthetic-foundation2026Q4-'.$suffix;
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key,
            'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-prices',
            'bucket' => 'test', 'prefix' => $key.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => 'SYNTHETIC-'.$suffix,
            'name' => 'Synthetic foundation numeric fixture 2026 Q4', 'norm_type' => 'gesn', 'source_file' => 'synthetic-foundation2026Q4',
            'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => '06', 'name' => 'Synthetic concrete',
            'path' => '06/synthetic', 'created_at' => $now, 'updated_at' => $now]);
        $norm = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => '06-99-999-01',
            'section_code' => '06', 'section_name' => 'Synthetic concrete', 'section_id' => $section,
            'name' => 'Бетонирование железобетонных фундаментов', 'unit' => '100 м3',
            'work_composition' => json_encode(['Бетонирование железобетонных фундаментов'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'valid_from' => '2026-10-01', 'valid_to' => '2026-12-31', 'created_at' => $now, 'updated_at' => $now]);
        $resourceCode = 'R08-FOUNDATION-LABOR-'.$suffix;
        $resource = DB::table('estimate_norm_resources')->insertGetId(['estimate_norm_id' => $norm,
            'resource_code' => $resourceCode, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'quantity' => '10.000000',
            'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'R08-'.substr($suffix, -10), 'name' => 'Synthetic region',
            'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Synthetic zone',
            'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->where('year', 2026)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => 'Synthetic 2026 Q4',
                'year' => 2026, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $price = DB::table('estimate_resource_prices')->insertGetId(['dataset_version_id' => $prices,
            'regional_price_version_id' => $version, 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period,
            'resource_code' => $resourceCode, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'base_price' => '100.0000',
            'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return ['norm_id' => $norm, 'resource_id' => $resource, 'price_id' => $price, 'context' => ['region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'business_date' => '2026-10-01', 'year' => 2026, 'quarter' => 4,
            'version_key' => $key, 'estimate_regional_price_version_id' => $version, 'normative_dataset_version' => $key]];
    }
}
