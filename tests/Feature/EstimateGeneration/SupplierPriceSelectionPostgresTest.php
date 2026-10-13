<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationPriceSources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationPriceSelection;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationEvaluationController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveCommercialWorkPrice;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\SupplierPriceSnapshotReader;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesCanonicalProcurementSelection;
use Tests\TestCase;

final class SupplierPriceSelectionPostgresTest extends TestCase
{
    use CreatesCanonicalProcurementSelection;

    public function test_supplier_sources_are_discovered_with_real_lineage_and_free_confirmed_region(): void
    {
        [$actor, $session, $version, $item] = $this->fixture();
        $sources = app(GetEvaluationPriceSources::class)->handle($actor, (int) $session->project_id, (int) $session->id, $item['key'], 'supplier');
        $source = collect($sources['sources'])->firstWhere('source_reference', 'supplier:'.$version->id.':'.$version->commercial_snapshot['lines'][0]['id']);
        self::assertNotNull($source);
        self::assertTrue($source['applicable']);
        self::assertTrue($source['compatible']);
        self::assertSame(['region'], $source['confirmable_conditions']);
        self::assertSame('0', $sources['cost_units']);
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'work_item_key' => $item['key'],
            'source_reference' => $source['source_reference'], 'source_hash' => $source['source_hash']];
        try {
            app(SaveEvaluationPriceSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
            self::fail('Missing region applicability was silently accepted.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('conditions_confirmation.region', $error->errors());
            self::assertSame(0, $session->fresh()->state_version);
        }
        $input['conditions_confirmation'] = ['region' => ['value' => 'Москва', 'basis' => 'Предложение относится к поставке на объект.']];
        $service = app(SaveEvaluationPriceSelection::class);
        $receipt = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertEquals($receipt, $service->handle($actor, (int) $session->project_id, (int) $session->id, $input));
        $price = app(ResolveCommercialWorkPrice::class)->resolve($actor, $session->fresh(), $item);
        self::assertSame('250', $price['unit_price']);
        self::assertNull($price['region']);
        self::assertSame('москва', $price['effective_region']);
        self::assertSame([], $price['conditions_missing']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_a_different_supplier_quantity_cannot_be_made_applicable_by_confirmation(): void
    {
        [$actor, $session, $version, $item] = $this->fixture('2');
        $reference = 'supplier:'.$version->id.':'.$version->commercial_snapshot['lines'][0]['id'];
        $price = app(SupplierPriceSnapshotReader::class)->read($actor, $session, $reference, new DateTimeImmutable('today'), $item);
        $this->expectException(ValidationException::class);
        app(SaveEvaluationPriceSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'work_item_key' => $item['key'],
                'source_reference' => $reference, 'source_hash' => $price['source_hash'],
                'conditions_confirmation' => ['region' => ['value' => 'Москва', 'basis' => 'Подтверждение не меняет коммерческий объём.']]]);
    }

    public function test_a_new_proposal_version_retires_the_old_price_and_revoked_actor_cannot_search(): void
    {
        [$actor, $session, $version, $item] = $this->fixture();
        $reference = 'supplier:'.$version->id.':'.$version->commercial_snapshot['lines'][0]['id'];
        $reader = app(SupplierPriceSnapshotReader::class);
        self::assertNotNull($reader->read($actor, $session, $reference, new DateTimeImmutable('today'), $item));
        SupplierProposalVersion::query()->create(['organization_id' => $version->organization_id, 'supplier_proposal_id' => $version->supplier_proposal_id,
            'version_number' => 2, 'commercial_snapshot' => $version->commercial_snapshot, 'attachment_snapshot' => $version->attachment_snapshot,
            'content_hash' => $version->content_hash, 'integrity_status' => 'verified', 'created_by' => $actor->id]);
        self::assertNull($reader->read($actor, $session, $reference, new DateTimeImmutable('today'), $item));
        $actor->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        app(GetEvaluationPriceSources::class)->handle($actor, (int) $session->project_id, (int) $session->id, $item['key'], 'supplier');
    }

    public function test_discovery_paginates_lines_and_excludes_other_organizations_and_projects(): void
    {
        [$actor, $session, $version, $item] = $this->fixture();
        [, , $foreignVersion] = $this->fixture();
        $organization = Organization::query()->findOrFail($session->organization_id);
        $otherRequest = $this->createCanonicalSelectionSupplierRequest($organization, 10000, 'OTHER-PR-'.Str::random(5), 'OTHER-SR-'.Str::random(5));
        $otherProposal = $this->createCanonicalSelectionProposal($organization, $otherRequest, 'OTHER-CP-'.Str::random(5), 250, vatAmount: 41.67);
        $otherVersion = SupplierProposalVersion::query()->where('supplier_proposal_id', $otherProposal->id)->firstOrFail();
        $service = app(GetEvaluationPriceSources::class);
        $first = $service->handle($actor, (int) $session->project_id, (int) $session->id, $item['key'], 'supplier', '', 1, 1);
        $second = $service->handle($actor, (int) $session->project_id, (int) $session->id, $item['key'], 'supplier', '', 2, 1);
        self::assertSame(2, $first['pagination']['total']);
        self::assertSame(2, $first['pagination']['last_page']);
        self::assertCount(1, $first['sources']);
        self::assertCount(1, $second['sources']);
        self::assertSame('supplier:'.$version->id.':'.$version->commercial_snapshot['lines'][0]['id'], $second['sources'][0]['source_reference']);
        foreach ([$foreignVersion, $otherVersion] as $excluded) {
            self::assertNull(app(SupplierPriceSnapshotReader::class)->read($actor, $session,
                'supplier:'.$excluded->id.':'.$excluded->commercial_snapshot['lines'][0]['id'], new DateTimeImmutable('today'), $item));
        }
        self::assertSame(0, $service->handle($actor, (int) $session->project_id, (int) $session->id, $item['key'], 'supplier', '%')['pagination']['total']);
    }

    public function test_supplier_permission_is_required_even_if_estimator_view_is_allowed(): void
    {
        [$actor, $session, , $item] = $this->fixture();
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturnUsing(static fn (...$args): bool => ! in_array('procurement.supplier_proposals.view', $args, true));
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->expectException(AuthorizationException::class);
        app(GetEvaluationPriceSources::class)->handle($actor, (int) $session->project_id, (int) $session->id, $item['key'], 'supplier');
    }

    public function test_confirmed_conditions_cannot_be_changed_without_the_original_fact_and_decision(): void
    {
        [$actor, $session, $version, $item] = $this->fixture();
        $reference = 'supplier:'.$version->id.':'.$version->commercial_snapshot['lines'][0]['id'];
        $price = app(SupplierPriceSnapshotReader::class)->read($actor, $session, $reference, new DateTimeImmutable('today'), $item);
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'work_item_key' => $item['key'],
            'source_reference' => $reference, 'source_hash' => $price['source_hash'],
            'conditions_confirmation' => ['region' => ['value' => 'Москва', 'basis' => 'Подтверждено для объекта.']]];
        app(SaveEvaluationPriceSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $payload = $session->fresh()->input_payload;
        $payload['price_selections'][$item['key']]['conditions_confirmation']['region']['basis'] = 'Неподтверждённая замена основания.';
        $session->fresh()->update(['input_payload' => $payload]);
        self::assertNull(app(ResolveCommercialWorkPrice::class)->resolve($actor, $session->fresh(), $item));
    }

    public function test_a_future_proposal_remains_unavailable_after_region_confirmation(): void
    {
        [$actor, $session, $version, $item] = $this->fixture('1', '2027-01-01');
        $reference = 'supplier:'.$version->id.':'.$version->commercial_snapshot['lines'][0]['id'];
        $price = app(SupplierPriceSnapshotReader::class)->read($actor, $session, $reference, new DateTimeImmutable('today'), $item);
        self::assertFalse($price['applicable']);
        $this->expectException(ValidationException::class);
        app(SaveEvaluationPriceSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'work_item_key' => $item['key'],
                'source_reference' => $reference, 'source_hash' => $price['source_hash'],
                'conditions_confirmation' => ['region' => ['value' => 'Москва', 'basis' => 'Регион не разрешает будущую цену.']]]);
    }

    public function test_http_contract_allows_supplier_discovery_and_preserves_nested_confirmation_in_admin_response(): void
    {
        [$actor, $session, $version, $item] = $this->fixture();
        $project = Project::query()->findOrFail($session->project_id);
        $controller = app(EstimateGenerationEvaluationController::class);
        $query = Request::create('/evaluation/prices', 'GET', ['work_item_key' => $item['key'], 'source_type' => 'supplier']);
        $query->setUserResolver(static fn (): User => $actor);
        $response = $controller->sources($query, $project, $session, app(GetEvaluationPriceSources::class));
        $body = $response->getData(true);
        self::assertTrue($body['success']);
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $reference = 'supplier:'.$version->id.':'.$version->commercial_snapshot['lines'][0]['id'];
        $price = collect($body['data']['sources'])->firstWhere('source_reference', $reference);
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'work_item_key' => $item['key'],
            'source_reference' => $reference, 'source_hash' => $price['source_hash'],
            'conditions_confirmation' => ['region' => ['value' => 'Москва', 'basis' => 'HTTP сохраняет подтверждённое условие.']]];
        $request = Request::create('/evaluation/price', 'POST', $input);
        $request->setUserResolver(static fn (): User => $actor);
        $saved = $controller->price($request, $project, $session, app(SaveEvaluationPriceSelection::class))->getData(true);
        self::assertTrue($saved['success']);
        self::assertSame('0', $saved['data']['cost_units']);
        self::assertSame('москва', $session->fresh()->input_payload['price_selections'][$item['key']]['conditions_confirmation']['region']['value']);
    }

    private function fixture(string $quantity = '1', ?string $proposalDate = null): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $request = $this->createCanonicalSelectionSupplierRequest($organization, 10000, 'PRICE-PR-'.Str::random(5), 'PRICE-SR-'.Str::random(5));
        $template = $this->createCanonicalSelectionProposal($organization, $request, 'PRICE-CP-'.Str::random(5), 250, vatAmount: 41.67);
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $organization->id, 'short_name' => 'шт'], ['name' => 'Штука', 'type' => 'count']);
        $material = Material::query()->create(['organization_id' => $organization->id, 'name' => 'Насос', 'code' => 'PUMP-'.Str::random(5),
            'measurement_unit_id' => $unit->id, 'is_active' => true]);
        $proposal = $template->replicate()->unsetRelations();
        $proposal->proposal_number = 'MAPPED-'.Str::random(6);
        if ($proposalDate !== null) {
            $proposal->proposal_date = $proposalDate;
        }
        $proposal->save();
        $line = $request->lines()->firstOrFail();
        $proposal->lines()->create(['supplier_request_line_id' => $line->id, 'material_id' => $material->id, 'name' => 'Насос',
            'quantity' => 1, 'unit' => 'шт', 'unit_price' => 250, 'total_amount' => 250]);
        $version = app(SupplierProposalVersionService::class)->createInitialVersion($proposal);
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $item = ['key' => 'equipment:purchase', 'work_key' => 'equipment_purchase', 'name' => 'Насос', 'quantity' => $quantity, 'unit' => 'count',
            'material_id' => (int) $material->id, 'metadata' => ['scope_entity_id' => 'equipment:1']];
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id,
            'project_id' => $request->purchaseRequest->siteRequest->project_id, 'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0,
            'input_payload' => ['evaluation_mode' => 'universal', 'price_policy' => 'mixed', 'region' => 'Москва', 'selected_sections' => ['equipment']],
            'draft_payload' => ['local_estimates' => [['key' => 'equipment', 'sections' => [['work_items' => [$item]]]]]]]);

        return [$actor, $session, $version, $item];
    }
}
