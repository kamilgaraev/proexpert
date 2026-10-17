<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveSessionPricingSource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class SessionPricingSourcePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_native_price_terms_are_bound_to_current_source_and_choice_proof_without_any_manual_amount(): void
    {
        [$actor, $session, $version] = $this->fixture();
        $before = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session);
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'estimate_regional_price_version_id' => $version,
            'conditions' => ['as_of_date' => '2099-10-01', 'vat_mode' => 'excluded', 'vat_rate' => '22.00',
                'delivery' => 'outside_scope', 'basis' => 'Подтверждены условия прямых затрат; доставка за пределами состава.']];
        $service = app(SaveSessionPricingSource::class);
        $result = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertEquals($result, $service->handle($actor, (int) $session->project_id, (int) $session->id, $input));
        $session->refresh();
        self::assertNotSame($before, app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session));
        $models = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = $models->snapshot(...$scope);
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $draft = ['regional_context' => $session->input_payload['regional_context'],
            'evaluation_policy' => ['regional_price_selection' => $session->input_payload['regional_price_selection']]];
        $terms = new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationRegionalPriceConditions;
        $accepted = $terms->accepted($draft, $snapshot, $decisions);
        self::assertNotNull($accepted);
        self::assertSame('22', $accepted['vat_rate']);
        self::assertSame('2099-10-01', $accepted['as_of_date']);
        self::assertSame('outside_scope', $accepted['delivery']);
        $changed = $draft;
        $changed['evaluation_policy']['regional_price_selection']['conditions']['vat_rate'] = '20';
        self::assertNull($terms->accepted($changed, $snapshot, $decisions));
        $draft['regional_context']['estimate_regional_price_version_id'] = $version + 1;
        self::assertNull($terms->accepted($draft, $snapshot, $decisions));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->count());
    }

    public function test_invalid_or_monetary_terms_cannot_be_saved_as_native_price_conditions(): void
    {
        [$actor, $session, $version] = $this->fixture();
        $conditions = ['as_of_date' => '2099-10-01', 'vat_mode' => 'excluded', 'vat_rate' => '22',
            'delivery' => 'outside_scope', 'basis' => 'Контрольная оценка выбранных работ без отдельной доставки.'];
        foreach ([['as_of_date' => '2099-02-30'], ['as_of_date' => '2099-12-31'], ['vat_mode' => 'unknown'], ['vat_mode' => 'included'], ['vat_rate' => '101'],
            ['delivery' => 'unknown'], ['unit_price' => '999.99']] as $change) {
            try {
                app(SaveSessionPricingSource::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                    ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'estimate_regional_price_version_id' => $version,
                        'conditions' => [...$conditions, ...$change]]);
                self::fail('Invalid source terms were accepted.');
            } catch (\Illuminate\Validation\ValidationException) {
                self::assertSame(0, $session->fresh()->state_version);
                self::assertArrayNotHasKey('regional_price_selection', $session->fresh()->input_payload);
            }
        }
    }

    public function test_a_regional_price_source_is_saved_without_ai_or_reserve_and_a_replay_has_one_decision(): void
    {
        [$actor, $session, $version] = $this->fixture();
        Queue::fake();
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'estimate_regional_price_version_id' => $version];
        $service = app(SaveSessionPricingSource::class);
        $result = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertSame('0', $result['cost_units']);
        self::assertFalse($result['recalculation_required']);
        self::assertSame(1, $result['state_version']);
        self::assertEquals($result, $service->handle($actor, (int) $session->project_id, (int) $session->id, $input));
        self::assertSame(1, $session->fresh()->state_version);
        self::assertSame($version, $session->fresh()->input_payload['estimate_regional_price_version_id']);
        self::assertSame('active', $session->fresh()->input_payload['regional_context']['status']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->count());
        Queue::assertNothingPushed();
    }

    public function test_an_unavailable_version_cannot_fall_back_to_another_active_source(): void
    {
        [$actor, $session] = $this->fixture();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            app(SaveSessionPricingSource::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'estimate_regional_price_version_id' => 2147483647]);
        } finally {
            self::assertSame(0, $session->fresh()->state_version);
            self::assertArrayNotHasKey('regional_price_selection', $session->fresh()->input_payload);
        }
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => []]);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $now = now();
        $key = strtolower((string) Str::ulid());
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key,
            'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $region = DB::table('estimate_regions')->insertGetId(['code' => $key, 'name' => 'Регион источника',
            'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Зона источника',
            'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->where('year', 2099)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999),
                'name' => $key, 'year' => 2099, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_resource_prices')->insert(['dataset_version_id' => $dataset, 'regional_price_version_id' => $version,
            'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => $key, 'resource_name' => 'Труд рабочих',
            'unit' => 'h', 'base_price' => '30.0000', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return [$actor, $session, $version];
    }
}
