<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BindEvaluationWorkEntity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CurrentWorkEntityBindingProof;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CurrentWorkEntitySources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationDraftArithmeticRecalculator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetWorkEntitySources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationPriceSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\UserEvaluationChoiceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CataloguePriceSnapshotReader;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class WorkEntitySourcesPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[DataProvider('workTypes')]
    public function test_sources_binding_quantity_and_apply_use_the_current_compatible_physical_entity(string $work, string $type): void
    {
        [$actor, $session] = $this->fixture($work);
        $scope = $this->scope($session);
        $beforeHttp = count(Http::recorded());
        $reader = app(GetWorkEntitySources::class);
        $result = $reader->handle($actor, $scope[1], $scope[2], 'target');
        self::assertSame('work-entity-sources:v1', $result['contract']);
        self::assertSame('target', $result['work_item_key']);
        self::assertSame([$type], $result['entity_types']);
        self::assertSame(1, $result['state_version']);
        self::assertSame('0', $result['cost_units']);
        self::assertTrue($result['available']);
        self::assertCount(1, $result['sources']);
        $selected = $result['sources'][0];
        self::assertSame($type, $selected['entity_type']);
        self::assertSame('confirmed', $selected['status']);
        self::assertTrue($selected['identity_only']);
        self::assertFalse($selected['dimensions_confirmed']);
        self::assertSame([], $reader->handle($actor, $scope[1], $scope[2], 'target', 'absent')['sources']);
        $all = DB::transaction(fn () => app(CurrentWorkEntitySources::class)->rowsForTypes($session->fresh(), ['room', 'roof', 'site']));
        self::assertCount(3, $all);
        $wrong = array_values(array_filter($all, static fn (array $row): bool => $row['entity_type'] !== $type))[0];
        $binding = app(BindEvaluationWorkEntity::class);
        foreach (['type', 'version', 'hash', 'foreign'] as $variant) {
            $input = $this->bindingInput($session, $variant === 'type' ? $wrong : $selected);
            match ($variant) {
                'version' => $input['source_fact_version']++,
                'hash' => $input['source_value_hash'] = str_repeat('a', 64),
                'foreign' => $input['source_fact_id'] = 'fact:'.str_repeat('f', 64),
                default => null,
            };
            try {
                $binding->handle($actor, $scope[1], $scope[2], $input);
                self::fail('Incompatible source accepted: '.$variant);
            } catch (ValidationException) {
                self::assertSame(1, $session->fresh()->state_version);
            }
        }
        $neighbor = $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'][1];
        $input = $this->bindingInput($session, $selected);
        $receipt = $binding->handle($actor, $scope[1], $scope[2], $input);
        self::assertSame('0', $receipt['cost_units']);
        self::assertTrue($receipt['quantity_reset']);
        self::assertSame(CanonicalPipelineJson::encode($receipt), CanonicalPipelineJson::encode($binding->handle($actor, $scope[1], $scope[2], $input)));
        self::assertSame($neighbor, $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'][1]);
        $item = $this->item($session);
        self::assertSame($selected['entity_id'], $item['metadata']['scope_entity_id']);
        self::assertNotSame($receipt['entity_id'], $item['metadata']['scope_entity_id']);
        self::assertSame('work-entity-binding:v2', $item['metadata']['scope_entity_binding']['binding_contract']);
        DB::transaction(fn () => app(CurrentWorkEntityBindingProof::class)->evidenceIds($session->fresh(), [$item], requireBindings: true));
        $quantity = app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], $this->quantityInput($session));
        $source = app(ProjectModelRepository::class)->fact(...[...$scope, $quantity['source_fact_id']]);
        self::assertSame($selected['entity_id'], $source->entityId);
        $draft = $this->recalculate($session);
        $ready = $draft['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('12.5', $ready['quantity']);
        $single = ['generation_contract' => UniversalDraftProjector::CONTRACT,
            'local_estimates' => [['sections' => [['work_items' => [$ready]]]]]];
        DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope, $single]));
        app(SaveManualProjectFacts::class)->handle($actor, $scope[1], $scope[2], $session->fresh()->state_version,
            (string) Str::uuid(), ['entities' => [['key' => $type.'-one', 'type' => $type, 'name' => 'Updated source']]]);
        foreach (['quantity', 'recalculate', 'apply'] as $action) {
            try {
                match ($action) {
                    'quantity' => app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], $this->quantityInput($session)),
                    'recalculate' => $this->recalculate($session),
                    'apply' => DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope, $single])),
                };
                self::fail('Stale binding accepted by '.$action);
            } catch (ValidationException $error) {
                self::assertNotEmpty($error->errors(), $action);
                if ($action !== 'apply') {
                    self::assertArrayHasKey('entity', $error->errors());
                }
            }
        }
        self::assertSame(CanonicalPipelineJson::encode($receipt), CanonicalPipelineJson::encode($binding->handle($actor, $scope[1], $scope[2], $input)));
        $newSource = $reader->handle($actor, $scope[1], $scope[2], 'target')['sources'][0];
        self::assertNotSame($selected['source_fact_id'], $newSource['source_fact_id']);
        self::assertTrue($binding->handle($actor, $scope[1], $scope[2], $this->bindingInput($session, $newSource))['quantity_reset']);
        self::assertArrayNotHasKey('target', $session->fresh()->input_payload['quantity_selections']);
        self::assertNotNull(app(ProjectModelRepository::class)->fact(...[...$scope, $quantity['source_fact_id']]));
        self::assertSame(500, $session->fresh()->input_payload['qa_limit_units']);
        self::assertSame('5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', $session->fresh()->input_payload['qa_run_id']);
        self::assertCount($beforeHttp, Http::recorded());
        foreach (['estimate_generation_ai_usage', 'estimate_generation_credit_operations'] as $table) {
            self::assertSame(0, DB::table($table)->where('session_id', $scope[2])->count());
        }
    }

    public function test_unbound_supplementary_quantity_is_rejected_but_partial_recalculation_is_available(): void
    {
        [$actor, $session] = $this->fixture('roof.membrane');
        $scope = $this->scope($session);
        $before = count(app(ProjectModelRepository::class)->snapshot(...$scope)->facts);
        try {
            app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], $this->quantityInput($session));
            self::fail('Unbound supplementary quantity accepted.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('entity', $error->errors());
        }
        self::assertSame($before, count(app(ProjectModelRepository::class)->snapshot(...$scope)->facts));
        self::assertNull($this->recalculate($session)['local_estimates'][0]['sections'][0]['work_items'][0]['quantity']);
        $other = Organization::factory()->create();
        $actor->update(['current_organization_id' => $other->id]);
        $this->expectException(ModelNotFoundException::class);
        app(GetWorkEntitySources::class)->handle($actor->fresh(), $scope[1], $scope[2], 'target');
    }

    public function test_copied_or_tampered_binding_proof_does_not_authorize_a_work_quantity(): void
    {
        [$actor, $session] = $this->fixture('roof.membrane');
        $scope = $this->scope($session);
        $source = app(GetWorkEntitySources::class)->handle($actor, $scope[1], $scope[2], 'target')['sources'][0];
        app(BindEvaluationWorkEntity::class)->handle($actor, $scope[1], $scope[2], $this->bindingInput($session, $source));
        foreach (['missing_pointer', 'wrong_type', 'legacy_roof', 'other_work', 'other_anchor'] as $variant) {
            $current = $session->fresh();
            $item = $this->item($current);
            $payload = $current->input_payload;
            match ($variant) {
                'missing_pointer' => $payload['work_entity_selections'] = [],
                'wrong_type' => $item['metadata']['scope_entity_binding']['source_entity_type'] = 'room',
                'legacy_roof' => $item['metadata']['scope_entity_binding'] = array_diff_key($item['metadata']['scope_entity_binding'], ['binding_contract' => true, 'source_entity_type' => true]),
                'other_work' => $item['key'] = 'another',
                'other_anchor' => $item['metadata']['scope_entity_binding']['ledger_entity_id'] = $source['entity_id'],
            };
            if ($variant !== 'missing_pointer') {
                $payload['work_entity_selections'][$item['key']] = $item['metadata']['scope_entity_binding'];
            }
            $current->input_payload = $payload;
            try {
                DB::transaction(fn () => app(CurrentWorkEntityBindingProof::class)->evidenceIds($current, [$item], requireBindings: true));
                self::fail('Forged binding accepted: '.$variant);
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
        app(EvidenceRepository::class)->invalidate(...[...$scope, [(int) substr($source['evidence']['id'], 9)], 'Source was withdrawn']);
        self::assertSame([], app(GetWorkEntitySources::class)->handle($actor, $scope[1], $scope[2], 'target')['sources']);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(CurrentWorkEntityBindingProof::class)->evidenceIds($session->fresh(), [$this->item($session)], requireBindings: true));
    }

    public function test_existing_room_binding_without_a_new_contract_remains_valid_and_active_evidence_is_required(): void
    {
        [$actor, $session] = $this->fixture('ceiling.hatch');
        $scope = $this->scope($session);
        $source = app(GetWorkEntitySources::class)->handle($actor, $scope[1], $scope[2], 'target')['sources'][0];
        $selection = ['answer_kind' => 'work_entity_binding', 'work_item_key' => 'target', 'entity_id' => $source['entity_id'],
            'source_fact_id' => $source['source_fact_id'], 'source_fact_version' => $source['source_fact_version'],
            'source_value_hash' => $source['source_value_hash'], 'source_version' => $source['source_version']];
        $proof = app(UserEvaluationChoiceWriter::class)->write($actor, $session, 'work-entity:target', 'evaluation_answer',
            $selection, 'Existing room binding from the previous contract.', (string) Str::uuid());
        $binding = [...$selection, 'fact_id' => $proof['fact_id'], 'decision_id' => $proof['decision_id'], 'ledger_entity_id' => $proof['entity_id']];
        $payload = $session->fresh()->input_payload;
        $payload['work_entity_selections']['target'] = $binding;
        $session->input_payload = $payload;
        $draft = $session->draft_payload;
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['metadata'] += [
            'scope_entity_id' => $source['entity_id'], 'scope_entity_binding' => $binding];
        $draft['source_input_version'] = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session);
        $session->update(['input_payload' => $payload,
            'draft_payload' => app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($draft))]);
        $ids = DB::transaction(fn () => app(CurrentWorkEntityBindingProof::class)->evidenceIds($session->fresh(), [$this->item($session)], requireBindings: true));
        self::assertCount(2, $ids);
        $result = app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], $this->quantityInput($session));
        self::assertSame('0', $result['cost_units']);
        $bindingFact = app(ProjectModelRepository::class)->fact(...[...$scope, $proof['fact_id']]);
        app(EvidenceRepository::class)->invalidate(...[...$scope, [(int) substr($bindingFact->evidenceIds[0], 9)], 'Binding decision was withdrawn']);
        $this->expectException(ValidationException::class);
        app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], $this->quantityInput($session));
    }

    #[DataProvider('priceScopeChanges')]
    public function test_changed_scope_revokes_the_saved_price_and_frozen_snapshot_but_preserves_its_history(bool $differentEntity): void
    {
        [$actor, $session] = $this->fixture('roof.membrane');
        $scope = $this->scope($session);
        $reader = app(GetWorkEntitySources::class);
        $source = $reader->handle($actor, $scope[1], $scope[2], 'target')['sources'][0];
        app(BindEvaluationWorkEntity::class)->handle($actor, $scope[1], $scope[2], $this->bindingInput($session, $source));
        app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], $this->quantityInput($session));
        $unit = MeasurementUnit::query()->create(['organization_id' => $scope[0], 'name' => 'Square metre', 'short_name' => 'm2', 'type' => 'area']);
        $catalog = EstimatePositionCatalog::query()->create(['organization_id' => $scope[0], 'name' => 'Membrane installation',
            'code' => 'P-'.Str::random(6), 'item_type' => 'work', 'measurement_unit_id' => $unit->id, 'unit_price' => '10',
            'is_active' => true, 'created_by_user_id' => $actor->id, 'metadata' => ['currency' => 'RUB', 'price_as_of_date' => '2026-10-01',
                'price_region' => 'Москва', 'vat_mode' => 'not_applicable', 'delivery_included' => true]]);
        $price = app(CataloguePriceSnapshotReader::class)->read($scope[0], 'catalog:'.$catalog->id);
        $priceInput = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'work_item_key' => 'target', 'source_reference' => $price['source_reference'], 'source_hash' => $price['source_hash']];
        $priceReceipt = app(SaveEvaluationPriceSelection::class)->handle($actor, $scope[1], $scope[2], $priceInput);
        $priced = $this->recalculate($session, $actor);
        $pricedItem = $priced['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertArrayHasKey('commercial_price_snapshot', $pricedItem);
        self::assertTrue(\Brick\Math\BigDecimal::of($pricedItem['commercial_price_snapshot']['unit_price'])->isEqualTo('10'));
        $session->refresh();
        $priced['source_input_version'] = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session);
        $session->update(['draft_payload' => app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($priced))]);
        $revision = $session->input_payload['price_selection_revision'];
        app(SaveManualProjectFacts::class)->handle($actor, $scope[1], $scope[2], $session->fresh()->state_version,
            (string) Str::uuid(), ['entities' => [['key' => $differentEntity ? 'roof-two' : 'roof-one', 'type' => 'roof', 'name' => 'Second roof source']]]);
        $new = array_values(array_filter($reader->handle($actor, $scope[1], $scope[2], 'target')['sources'],
            static fn (array $row): bool => $row['name'] === 'Second roof source'))[0];
        self::assertSame($differentEntity, $new['entity_id'] !== $source['entity_id']);
        self::assertTrue(app(BindEvaluationWorkEntity::class)->handle($actor, $scope[1], $scope[2], $this->bindingInput($session, $new))['quantity_reset']);
        self::assertArrayNotHasKey('target', $session->fresh()->input_payload['price_selections']);
        self::assertSame($revision + 1, $session->fresh()->input_payload['price_selection_revision']);
        self::assertArrayNotHasKey('commercial_price_snapshot', $this->item($session));
        self::assertArrayNotHasKey('price_snapshot', $this->item($session));
        self::assertNull($this->item($session)['unit_price']);
        app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], $this->quantityInput($session));
        $recalculated = $this->recalculate($session, $actor)['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertArrayNotHasKey('commercial_price_snapshot', $recalculated);
        self::assertNull($recalculated['unit_price']);
        $models = app(ProjectModelRepository::class);
        $oldFact = $models->fact(...[...$scope, $priceReceipt['fact_id']]);
        self::assertNotNull($oldFact);
        $currentPrices = array_values(array_filter($models->snapshot(...$scope)->facts, static fn ($fact): bool => $fact->type === 'evaluation_price'));
        self::assertCount(1, $currentPrices);
        self::assertSame('price_scope_invalidated', $currentPrices[0]->value['answer_kind']);
        self::assertSame($oldFact->id, $currentPrices[0]->supersedesFactId);
        self::assertArrayHasKey($priceInput['request_id'], $session->fresh()->input_payload['evaluation_price_receipts']);
    }

    public static function priceScopeChanges(): array
    {
        return [[true], [false]];
    }

    public static function workTypes(): array
    {
        return [['roof.membrane', 'roof'], ['site.apron', 'site'], ['ceiling.insulation', 'room']];
    }

    private function bindingInput(EstimateGenerationSession $session, array $source): array
    {
        return ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version, 'work_item_key' => 'target',
            'source_fact_id' => $source['source_fact_id'], 'source_fact_version' => $source['source_fact_version'],
            'source_value_hash' => $source['source_value_hash'], 'reason' => 'The work belongs to this confirmed physical object.'];
    }

    private function quantityInput(EstimateGenerationSession $session): array
    {
        return ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version, 'work_item_key' => 'target',
            'value' => '12.5', 'unit' => 'm2', 'reason' => 'Explicit estimating assumption for the selected object.'];
    }

    private function item(EstimateGenerationSession $session): array
    {
        return $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];
    }

    private function recalculate(EstimateGenerationSession $session, ?User $actor = null): array
    {
        return DB::transaction(function () use ($session, $actor): array {
            $session = $session->fresh();
            $scope = $this->scope($session);
            $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
            $decisions = app(ProjectModelRepository::class)->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
            $version = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session);
            $context = new PipelineContext($scope[2], $scope[0], $scope[1], $session->state_version, $version, $session->status->value, baseInputVersion: $version);

            return app(EvaluationDraftArithmeticRecalculator::class)->recalculate($session->draft_payload, $context, $snapshot, $decisions, $actor, $session);
        });
    }

    private function scope(EstimateGenerationSession $session): array
    {
        return [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
    }

    private function fixture(string $work): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $items = [['key' => 'target', 'name' => 'Work requiring a physical object', 'unit' => 'm2', 'quantity' => null,
            'metadata' => ['generation_source' => 'accepted_supplementary_work', 'composition_work_key' => $work]],
            ['key' => 'neighbor', 'name' => 'Existing work', 'quantity' => null, 'unit' => 'm2']];
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => ['evaluation_mode' => 'universal',
                'price_policy' => 'mixed', 'region' => 'Москва', 'qa_limit_units' => 500, 'qa_run_id' => '5ac504c5-5ca3-41dc-95b0-f3c8745ccc44'],
            'draft_payload' => ['local_estimates' => [['sections' => [['work_items' => $items]]]]]]);
        app(SaveManualProjectFacts::class)->handle($actor, (int) $project->id, (int) $session->id, 0, (string) Str::uuid(),
            ['entities' => [['key' => 'roof-one', 'type' => 'roof', 'name' => 'Roof'],
                ['key' => 'site-one', 'type' => 'site', 'name' => 'Site'], ['key' => 'room-one', 'type' => 'room', 'name' => 'Room']]]);
        $session->refresh();
        $draft = $session->draft_payload;
        $draft['generation_contract'] = UniversalDraftProjector::CONTRACT;
        $draft['source_input_version'] = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session);
        $session->update(['draft_payload' => app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($draft))]);

        return [$actor, $session];
    }
}
