<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationPriceSources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationPriceSelection;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CataloguePriceSnapshotReader;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveCommercialWorkPrice;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationPriceSelectionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_a_price_for_100_square_metres_is_selected_with_real_proof_and_no_fictitious_volume(): void
    {
        [$actor, $session, $catalog, $item] = $this->fixture();
        $source = app(CataloguePriceSnapshotReader::class)->read((int) $session->organization_id, 'catalog:'.$catalog->id);
        $request = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'work_item_key' => $item['key'],
            'source_reference' => $source['source_reference'], 'source_hash' => $source['source_hash']];
        $save = app(SaveEvaluationPriceSelection::class);
        $result = $save->handle($actor, (int) $session->project_id, (int) $session->id, $request);
        self::assertSame(CanonicalPipelineJson::encode($result), CanonicalPipelineJson::encode($save->handle($actor, (int) $session->project_id, (int) $session->id, $request)));
        $price = app(ResolveCommercialWorkPrice::class)->resolve($actor, $session->fresh(), $item);
        self::assertNotNull($price);
        self::assertSame('10.0000', $price['unit_price']);
        self::assertSame('m2', $price['unit']);
        self::assertSame('100м2', $price['source_unit']);
        self::assertNull($session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0]['quantity']);
        $snapshot = app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        self::assertCount(1, $snapshot->facts);
        self::assertSame('evaluation_price', $snapshot->facts[0]->type);
        self::assertArrayNotHasKey('area', $snapshot->entities[0]->attributes);
        self::assertArrayNotHasKey('value', $snapshot->entities[0]->attributes);
        self::assertSame('0', $result['cost_units']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_source_search_is_scoped_and_changed_catalog_data_does_not_silently_keep_the_old_price(): void
    {
        [$actor, $session, $catalog, $item] = $this->fixture();
        $source = app(CataloguePriceSnapshotReader::class)->read((int) $session->organization_id, 'catalog:'.$catalog->id);
        $sources = app(GetEvaluationPriceSources::class)->handle($actor, (int) $session->project_id, (int) $session->id, $item['key']);
        self::assertSame(1, $sources['pagination']['total']);
        self::assertTrue($sources['sources'][0]['compatible']);
        $catalog->update(['unit_price' => '2000']);
        try {
            app(SaveEvaluationPriceSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'work_item_key' => $item['key'],
                    'source_reference' => $source['source_reference'], 'source_hash' => $source['source_hash']]);
            self::fail('Changed source was accepted.');
        } catch (ValidationException) {
            self::assertSame(0, $session->fresh()->state_version);
        }
    }

    public function test_unknown_prices_remain_visible_and_unselected_or_ambiguous_work_cannot_be_changed(): void
    {
        [$actor, $session, $catalog, $item] = $this->fixture();
        $catalog->update(['unit_price' => '0']);
        $sources = app(GetEvaluationPriceSources::class)->handle($actor, (int) $session->project_id, (int) $session->id, $item['key']);
        self::assertSame(1, $sources['pagination']['total']);
        self::assertCount(1, $sources['sources']);
        self::assertNull($sources['sources'][0]['unit_price']);
        self::assertFalse($sources['sources'][0]['compatible']);
        self::assertSame('price_or_unit_unavailable', $sources['sources'][0]['unavailable_reason']);
        $catalog->update(['unit_price' => '1000']);
        $source = app(CataloguePriceSnapshotReader::class)->read((int) $session->organization_id, 'catalog:'.$catalog->id);
        $payload = $session->input_payload;
        $payload['selected_sections'] = ['roofing'];
        $session->update(['input_payload' => $payload]);
        $request = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'work_item_key' => $item['key'],
            'source_reference' => $source['source_reference'], 'source_hash' => $source['source_hash']];
        try {
            app(SaveEvaluationPriceSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id, $request);
            self::fail('An excluded work item was changed.');
        } catch (ValidationException) {
            self::assertSame(0, $session->fresh()->state_version);
        }
        $payload['selected_sections'] = ['finishing'];
        $draft = $session->draft_payload;
        $draft['source_input_version'] = 'sha256:'.str_repeat('a', 64);
        $draft['local_estimates'][0]['sections'][0]['work_items'][] = $item;
        $draft = app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($draft));
        $session->update(['input_payload' => $payload, 'draft_payload' => $draft]);
        try {
            app(SaveEvaluationPriceSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id, $request);
            self::fail('An ambiguous work identity was accepted.');
        } catch (ValidationException) {
            self::assertSame(0, $session->fresh()->state_version);
        }
    }

    public function test_confirmed_exchange_rate_is_free_replayable_and_requires_its_real_current_fact(): void
    {
        [$actor, $session, $catalog, $item] = $this->fixture();
        $catalog->update(['unit_price' => '0.03', 'metadata' => [...$catalog->metadata, 'currency' => 'USD', 'vat_mode' => 'included', 'vat_rate' => '20']]);
        $source = app(CataloguePriceSnapshotReader::class)->read((int) $session->organization_id, 'catalog:'.$catalog->id);
        app(SaveEvaluationPriceSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'work_item_key' => $item['key'],
                'source_reference' => $source['source_reference'], 'source_hash' => $source['source_hash']]);
        $item['quantity'] = '100';
        $resolver = app(ResolveCommercialWorkPrice::class);
        self::assertSame('USD', $resolver->resolve($actor, $session->fresh(), $item)['currency']);
        $request = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'from_currency' => 'USD', 'to_currency' => 'RUB', 'rub_per_unit' => '90', 'as_of_date' => '2026-10-12',
            'rate_source_reference' => 'Курс по договору поставки'];
        $service = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationCurrencySelection::class);
        $beforeHash = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session->fresh());
        $receipt = $service->handle($actor, (int) $session->project_id, (int) $session->id, $request);
        self::assertEquals($receipt, $service->handle($actor, (int) $session->project_id, (int) $session->id, $request));
        self::assertSame('0', $receipt['cost_units']);
        self::assertNotSame($beforeHash, app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session->fresh()));
        $price = $resolver->resolve($actor, $session->fresh(), $item);
        self::assertSame('RUB', $price['currency']);
        self::assertSame('USD', $price['currency_conversion']['source_snapshot']['currency']);
        self::assertSame($source['source_hash'], $price['source_hash']);
        $money = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationCurrencyConversion)->money($price['currency_conversion']);
        self::assertSame(['2.70', '0.00', '2.70'], [$money['net_amount'], $money['vat_amount'], $money['gross_amount']]);
        $payload = $session->fresh()->input_payload;
        $payload['currency_selections']['USD']['rub_per_unit'] = '91';
        $session->update(['input_payload' => $payload]);
        self::assertSame('USD', $resolver->resolve($actor, $session->fresh(), $item)['currency']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $session->organization_id)->count());
    }

    public function test_exchange_rate_rejects_conflicting_delivery_stale_state_and_revoked_actor(): void
    {
        [$actor, $session] = $this->fixture();
        $service = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationCurrencySelection::class);
        $request = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'from_currency' => 'USD', 'to_currency' => 'RUB',
            'rub_per_unit' => '90', 'as_of_date' => '2026-10-12', 'rate_source_reference' => 'Курс договора'];
        $service->handle($actor, (int) $session->project_id, (int) $session->id, $request);
        foreach ([[...$request, 'rub_per_unit' => '91'], [...$request, 'request_id' => (string) Str::uuid()]] as $invalid) {
            try {
                $service->handle($actor, (int) $session->project_id, (int) $session->id, $invalid);
                self::fail('Conflicting or stale currency confirmation was accepted.');
            } catch (ValidationException|\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState) {
                self::assertSame(1, $session->fresh()->state_version);
            }
        }
        $actor->update(['is_active' => false]);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $service->handle($actor, (int) $session->project_id, (int) $session->id, $request);
    }

    public function test_explicit_reserve_is_a_free_canonical_choice_and_retry_does_not_create_another_fact(): void
    {
        [$actor, $session] = $this->fixture();
        $service = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationContingency::class);
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'kind' => 'fixed', 'currency' => 'RUB',
            'quoted_amount' => '120', 'vat_mode' => 'included', 'vat_rate' => '20', 'basis' => 'Резерв согласован для выбранного состава.'];
        $receipt = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertEquals($receipt, $service->handle($actor, (int) $session->project_id, (int) $session->id, $input));
        self::assertSame('0', $receipt['cost_units']);
        self::assertSame(1, $session->fresh()->state_version);
        $snapshot = app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        self::assertCount(1, $snapshot->facts);
        self::assertSame('evaluation_contingency', $snapshot->facts[0]->type);
        self::assertSame('120.00', $snapshot->facts[0]->value['quoted_amount']);
        self::assertSame($receipt['fact_id'], $session->fresh()->input_payload['contingency_selection']['fact_id']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        try {
            $service->handle($actor, (int) $session->project_id, (int) $session->id, [...$input, 'quoted_amount' => '121']);
            self::fail('Conflicting reserve confirmation was accepted.');
        } catch (ValidationException) {
            self::assertSame(1, $session->fresh()->state_version);
        }
        $actor->update(['is_active' => false]);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
    }

    public function test_automatic_catalog_rate_is_exact_scoped_and_keeps_unknown_volume_and_totals(): void
    {
        [$actor, $session, $catalog, $item] = $this->fixture();
        $item['unit_price'] = '999999';
        $resolver = app(ResolveCommercialWorkPrice::class);
        $price = $resolver->resolveAutomatic($session, $item);
        self::assertNotNull($price);
        self::assertSame('10.0000', $price['unit_price']);
        self::assertSame('m2', $price['unit']);
        self::assertSame('automatic_exact_name', $price['selection_mode']);
        self::assertNull($item['quantity']);
        self::assertArrayNotHasKey('total_cost', $price);
        self::assertSame('catalog:'.$catalog->id, $price['source_reference']);
        self::assertSame([], $session->fresh()->input_payload['price_selections'] ?? []);
        self::assertSame([], app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id)->facts);
        $frozenItem = [...$item, 'commercial_price_snapshot' => $price];
        self::assertNotNull($resolver->resolveFrozen($actor, $session, $frozenItem));
        self::assertNull($resolver->resolveFrozen($actor, $session, [...$frozenItem, 'name' => 'Отделка стен']));
        $catalog->update(['unit_price' => '2000']);
        self::assertNull($resolver->resolveFrozen($actor, $session, $frozenItem));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_automatic_price_never_replaces_an_explicit_selection_or_chooses_between_ambiguous_sources(): void
    {
        [, $session, $catalog, $item] = $this->fixture();
        $copy = $catalog->replicate();
        $copy->code = 'COPY-'.Str::random(6);
        $copy->save();
        $resolver = app(ResolveCommercialWorkPrice::class);
        self::assertNull($resolver->resolveAutomatic($session, $item));
        $copy->update(['is_active' => false]);
        self::assertNotNull($resolver->resolveAutomatic($session, $item));
        $payload = $session->input_payload;
        $payload['price_selections'][$item['key']] = ['source_reference' => 'catalog:999999', 'source_hash' => str_repeat('a', 64)];
        $session->update(['input_payload' => $payload]);
        self::assertNull($resolver->resolveAutomatic($session->fresh(), $item));
    }

    public function test_real_pricing_stage_publishes_a_verified_unit_rate_without_a_volume_or_model_price(): void
    {
        [, $session, , $item] = $this->fixture();
        $session->update(['status' => 'generating']);
        $data = ['regional_context' => [], 'supplementary_materials' => [],
            'evaluation_policy' => ['mode' => 'universal', 'price_policy' => 'catalog', 'profile_id' => 'construction', 'selected_sections' => ['finishing']],
            'local_estimates' => [['key' => 'finishing', 'sections' => [['key' => 'floor', 'work_items' => [[...$item, 'item_type' => 'priced_work', 'unit_price' => '999999']]]]]]];
        $base = 'sha256:'.str_repeat('a', 64);
        $attempt = (string) Str::uuid();
        $identity = [(int) $session->id, (int) $session->organization_id, (int) $session->project_id, 0, $base, 'generating'];
        $factory = new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory(
            new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore,
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph::standard());
        $stage = \App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage::AssembleResources;
        $prior = $factory->make(new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext(...$identity,
            generationAttemptId: $attempt, baseInputVersion: $base, stage: $stage, dependencyVersions: ['match_normatives' => $base]), $stage, $data);
        $context = new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext(...$identity,
            priorOutputs: new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs([$stage->value => $prior->output], [$stage->value => $data]),
            generationAttemptId: $attempt, baseInputVersion: $base,
            stage: \App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage::ResolvePrices,
            dependencyVersions: [$stage->value => $prior->output->version]);
        $pricing = new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ResolvePricesStage(
            app(\App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService::class), $factory,
            app(ResolveCommercialWorkPrice::class), app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization::class));
        $output = $pricing->execute($context)->transientData;
        $result = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)
            ->build($output, app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id));
        $position = $result['scenarios'][0]['positions'][0];
        self::assertSame('10.0000', $position['unit_price']);
        self::assertNull($position['quantity']);
        self::assertNull($position['total_cost']);
        self::assertSame('automatic_exact_name', $position['price_snapshot']['selection_mode']);
        self::assertFalse($result['can_confirm_for_apply']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    private function fixture(): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $unit = MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => '100 квадратных метров', 'short_name' => '100 м²', 'type' => 'area']);
        $catalog = EstimatePositionCatalog::query()->create(['organization_id' => $organization->id, 'name' => 'Отделка пола', 'code' => 'P-'.Str::random(6),
            'item_type' => 'work', 'measurement_unit_id' => $unit->id, 'unit_price' => '1000', 'is_active' => true, 'created_by_user_id' => $actor->id,
            'metadata' => ['currency' => 'RUB', 'price_as_of_date' => '2026-10-01', 'price_region' => 'Москва', 'vat_mode' => 'not_applicable', 'delivery_included' => true]]);
        $item = ['key' => 'finish:1', 'name' => 'Отделка пола', 'unit' => 'm2', 'quantity' => null];
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'state_version' => 0, 'status' => 'draft', 'input_payload' => ['evaluation_mode' => 'universal', 'price_policy' => 'catalog', 'selected_sections' => ['finishing']],
            'draft_payload' => ['local_estimates' => [['key' => 'finishing', 'sections' => [['key' => 'finish', 'work_items' => [$item]]]]]]]);

        return [$actor, $session, $catalog, $item];
    }
}
