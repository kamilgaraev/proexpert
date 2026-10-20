<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationDraftArithmeticRecalculator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WallWorkItemExpander;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class WallAssemblyEvaluationPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_legacy_apply_preserves_preview_quantities_above_the_universal_decision_limit(): void
    {
        [$actor, $session, $walls] = $this->fixture(false);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        $factIds = array_column($capture['snapshot']->facts, 'id');
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, $factIds]);
        $sourceDecision = $decisions[0];
        for ($index = 0; $index < 257; $index++) {
            $decisions[] = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision('decision:legacy-'.$index,
                ...[...$scope, $sourceDecision->sourceVersion, $sourceDecision->targetType, $sourceDecision->targetId,
                    $sourceDecision->selectedFactId, $sourceDecision->actorType, $sourceDecision->actorId,
                    $sourceDecision->reason, $sourceDecision->version, $sourceDecision->evidenceIds]);
        }
        $previewModels = Mockery::mock(ProjectModelRepository::class);
        $previewModels->shouldReceive('snapshotForPlanning')->with(...[...$scope, 10001])->andReturn($capture);
        $previewModels->shouldReceive('decisionsForSelectedFacts')->with(...[...$scope, $factIds])->andReturn($decisions);
        $preview = new \App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService($previewModels,
            new \App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory);
        $quantities = app(\App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'];
        $items = [];
        foreach ($walls as $wall) {
            $source = array_values(array_filter($quantities, static fn ($quantity): bool => $quantity->formulaKey === 'wall_net_area'
                && ($quantity->formulaInputs['entity_id'] ?? null) === $wall->id))[0];
            $items[] = ['key' => 'legacy:'.$wall->id, 'unit' => 'm2', 'metadata' => ['quantity_key' => $source->key,
                'composition_work_key' => 'finishing.rough_walls', 'scope_entity_id' => $wall->id]];
        }
        $draft = $this->recalculate(['evaluation_policy' => ['price_policy' => 'normative'],
            'local_estimates' => [['sections' => [['work_items' => $items]]]]], $actor, $session);
        self::assertSame(['15', '15'], array_column($draft['local_estimates'][0]['sections'][0]['work_items'], 'quantity'));
        self::assertSame(array_keys($quantities), array_keys($preview->previewInput(...$scope)['quantities']));
        $guard = new CurrentDraftQuantityProofGuard($preview,
            app(\App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository::class), $previewModels);
        DB::transaction(fn () => $guard->assertCurrent(...[...$scope, $draft]));
        self::addToAssertionCount(1);
        $draft['generation_contract'] = \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector::CONTRACT;
        $this->assertRejected($guard, $scope, $draft);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_legacy_wall_recalculation_and_apply_preserve_real_quantities_without_an_assembly(): void
    {
        [$actor, $session, $walls] = $this->fixture(false);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $quantities = app(\App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'];
        $items = [];
        foreach (WallWorkItemExpander::WORKS as $work) {
            $formula = $work === 'walls.external_volume' ? 'wall_net_volume' : 'wall_net_area';
            $source = array_values(array_filter($quantities, static fn ($quantity): bool => $quantity->formulaKey === $formula
                && ($quantity->formulaInputs['entity_id'] ?? null) === $walls[0]->id))[0];
            $items[] = ['key' => 'legacy:'.$work, 'unit' => $source->unit, 'metadata' => ['quantity_key' => $source->key,
                'composition_work_key' => $work, 'scope_entity_id' => $walls[0]->id]];
        }
        $draft = ['evaluation_policy' => ['price_policy' => 'normative'],
            'local_estimates' => [['sections' => [['work_items' => $items]]]]];
        $updated = $this->recalculate($draft, $actor, $session);
        $rows = $updated['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(5, $rows);
        self::assertSame(['2.1', '15', '15', '15', '15'], array_column($rows, 'quantity'));
        DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope, $updated]));
        self::addToAssertionCount(1);
        $universal = $draft;
        $universal['generation_contract'] = \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector::CONTRACT;
        $universal = $this->recalculate($universal, $actor, $session);
        self::assertSame([null, null, null, null, null], array_column($universal['local_estimates'][0]['sections'][0]['work_items'], 'quantity'));
        $updated['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['generation_source'] = 'canonical_wall_assembly';
        $this->assertRejected(app(CurrentDraftQuantityProofGuard::class), $scope, $updated);
        self::assertNull($this->recalculate($updated, $actor, $session)['local_estimates'][0]['sections'][0]['work_items'][0]['quantity']);
    }

    public function test_free_recalculation_expands_verified_walls_and_apply_rejects_forged_surfaces_or_stale_geometry(): void
    {
        [$actor, $session, $walls] = $this->fixture();
        $beforeCalls = count(Http::recorded());
        $draft = $this->recalculate($this->draft(), $actor, $session);
        $items = $draft['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(10, $items);
        self::assertSame('Preserve my note', $items[array_key_last($items)]['name']);
        $concrete = array_values(array_filter($items, static fn ($item): bool => ($item['metadata']['composition_work_key'] ?? null) === 'walls.external_volume'))[0];
        self::assertSame('2.1', $concrete['quantity']);
        self::assertSame('m3', $concrete['unit']);
        self::assertNotEmpty($concrete['quantity_evidence_id']);
        self::assertSame($walls[0]->id, $concrete['metadata']['scope_entity_id']);
        foreach (array_slice($items, 0, 9) as $item) {
            self::assertNotNull($item['quantity']);
            self::assertSame([], $item['materials']);
            self::assertNull($item['unit_price']);
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $guard = app(CurrentDraftQuantityProofGuard::class);
        $models = app(ProjectModelRepository::class);
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        $snapshot = $capture['snapshot'];
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $stale = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:old-wall-choice', ...[...$scope,
            'sha256:'.str_repeat('d', 64), $walls[0]->id, 'wall_assembly', [], null, 0, 'user_assumption', 'confirmed', []]);
        $withStale = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot($snapshot->entities,
            [...$snapshot->facts, $stale], $snapshot->evidence, $snapshot->conflicts);
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Quantities\WallEstimateScope::select($snapshot, $decisions, []),
            \App\BusinessModules\Addons\EstimateGeneration\Quantities\WallEstimateScope::select($withStale, $decisions, []));
        DB::transaction(fn () => $guard->assertCurrent(...[...$scope, $draft]));
        self::addToAssertionCount(1);
        $forged = $draft;
        $forged['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['wall_assembly']['role'] = 'partition';
        $this->assertRejected($guard, $scope, $forged);
        $forged = $draft;
        $forged['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['wall_surface'] = 'outside';
        $this->assertRejected($guard, $scope, $forged);
        $priced = $draft;
        foreach ($priced['local_estimates'][0]['sections'][0]['work_items'] as &$item) {
            if (($item['item_type'] ?? null) === 'priced_work') {
                $item['unit_price'] = '100';
                $item['total_cost'] = '210';
                $item['materials'] = [['code' => 'stale-resource', 'quantity' => '999']];
            }
        }
        unset($item);
        app(SaveManualProjectFacts::class)->handle($actor, $scope[1], $scope[2], 2, (string) Str::uuid(), ['entities' => [[
            'key' => 'length-answer', 'type' => 'wall', 'existing_entity_id' => $walls[0]->id,
            'parameters' => ['length' => ['value' => '6', 'unit' => 'm', 'basis' => 'measurement']],
        ]]]);
        $this->assertRejected($guard, $scope, $draft);
        $updated = $this->recalculate($priced, $actor, $session->fresh());
        $row = $updated['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('2.52', $row['quantity']);
        self::assertNull($row['unit_price']);
        self::assertNull($row['total_cost']);
        self::assertSame([], $row['materials']);
        DB::transaction(fn () => $guard->assertCurrent(...[...$scope, $updated]));
        self::addToAssertionCount(1);
        $currentSnapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $currentWall = array_values(array_filter($currentSnapshot->entities, static fn ($wall): bool => $wall->id === $walls[0]->id))[0];
        app(SaveManualProjectFacts::class)->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid(), ['entities' => [[
            'key' => 'choice-confirmed-again', 'type' => 'wall', 'existing_entity_id' => $currentWall->id,
            'wall_assembly' => ['role' => 'external', 'finished_surfaces' => ['inside', 'outside'],
                'entity_fingerprint' => GeometryInventory::entityFingerprint($currentWall, []), 'reason' => 'Повторно принято то же назначение'],
        ]]]);
        $rebound = $this->recalculate($updated, $actor, $session->fresh());
        self::assertCount(10, $rebound['local_estimates'][0]['sections'][0]['work_items']);
        self::assertNotSame($row['metadata']['wall_assembly']['fact_id'], $rebound['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['wall_assembly']['fact_id']);
        self::assertSame('2.52', $rebound['local_estimates'][0]['sections'][0]['work_items'][0]['quantity']);
        DB::transaction(fn () => $guard->assertCurrent(...[...$scope, $rebound]));
        self::addToAssertionCount(1);
        self::assertSame($beforeCalls, count(Http::recorded()));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_a_real_manual_quantity_never_reactivates_a_facade_after_the_wall_became_a_partition(): void
    {
        [$actor, $session, $walls] = $this->fixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $draft = $this->recalculate($this->draft(), $actor, $session);
        $draft['source_input_version'] = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session);
        $draft = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector::class)->project($draft);
        $draft = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft::class)->seal($draft);
        $session->update(['draft_payload' => $draft]);
        $facade = array_values(array_filter($draft['local_estimates'][0]['sections'][0]['work_items'],
            static fn ($item): bool => ($item['metadata']['composition_work_key'] ?? null) === 'facade.area'))[0];
        $saved = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], [
            'request_id' => (string) Str::uuid(), 'state_version' => 2, 'work_item_key' => $facade['key'],
            'value' => '15', 'unit' => 'm2', 'reason' => 'Подтверждён объём текущей наружной стороны',
        ]);
        $session->refresh();
        app(SaveManualProjectFacts::class)->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid(), ['entities' => [[
            'key' => 'changed-role', 'type' => 'wall', 'existing_entity_id' => $walls[0]->id,
            'wall_assembly' => ['role' => 'partition', 'finished_surfaces' => ['side_a'],
                'entity_fingerprint' => GeometryInventory::entityFingerprint($walls[0], []), 'reason' => 'Принята перегородка без фасада'],
        ]]]);
        $current = app(\App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService::class)
            ->previewInput(...$scope)['quantities'][$saved['quantity_key']];
        self::assertSame('direct_quantity', $current->formulaKey);
        $facade['metadata']['quantity_key'] = $current->key;
        $facade['quantity'] = $current->amount;
        $facade['quantity_evidence'] = $current->toArray();
        $this->assertRejected(app(CurrentDraftQuantityProofGuard::class), $scope,
            ['local_estimates' => [['sections' => [['work_items' => [$facade]]]]]]);
        $updated = $this->recalculate($session->fresh()->draft_payload, $actor, $session->fresh());
        $items = $updated['local_estimates'][0]['sections'][0]['work_items'];
        self::assertNotContains($facade['key'], array_column($items, 'key'));
        $bodies = array_values(array_filter($items, static fn ($item): bool => ($item['metadata']['composition_work_key'] ?? null) === 'walls.internal'));
        self::assertCount(2, $bodies);
        foreach ($bodies as $row) {
            self::assertNotNull($row['quantity']);
            self::assertNull($row['unit_price']);
            self::assertSame([], $row['materials']);
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    private function recalculate(array $draft, User $actor, EstimateGenerationSession $session): array
    {
        return DB::transaction(function () use ($draft, $actor, $session): array {
            $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
            $models = app(ProjectModelRepository::class);
            $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
            $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($capture['snapshot']->facts, 'id')]);
            $version = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session);
            $context = new PipelineContext($scope[2], $scope[0], $scope[1], (int) $session->state_version, $version,
                $session->status->value, baseInputVersion: $version);

            return app(EvaluationDraftArithmeticRecalculator::class)->recalculate($draft, $context, $capture['snapshot'], $decisions, $actor, $session);
        });
    }

    private function assertRejected(CurrentDraftQuantityProofGuard $guard, array $scope, array $draft): void
    {
        try {
            DB::transaction(fn () => $guard->assertCurrent(...[...$scope, $draft]));
            self::fail('Stale or forged wall basis accepted for application.');
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
    }

    private function fixture(bool $assembly = true): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0,
            'input_payload' => ['evaluation_mode' => 'universal', 'selected_sections' => ['structures', 'finishing'], 'price_policy' => 'normative']]);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $writer = app(SaveManualProjectFacts::class);
        $writer->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), ['entities' => array_map(static fn ($key): array => [
            'key' => $key, 'type' => 'wall', 'parameters' => array_combine(['length', 'height', 'thickness'], array_map(
                static fn ($value): array => ['value' => $value, 'unit' => 'm', 'basis' => 'measurement'], ['5', '3', '0.14'])),
            'coverage' => ['wall_openings' => 'covered_empty'],
        ], ['wall-1', 'wall-2'])]);
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $walls = $snapshot->entities;
        if ($assembly) {
            $writer->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), ['entities' => array_map(static fn ($wall, $index): array => [
                'key' => 'assembly-'.$index, 'type' => 'wall', 'existing_entity_id' => $wall->id,
                'wall_assembly' => ['role' => $index === 0 ? 'external' : 'partition',
                    'finished_surfaces' => $index === 0 ? ['inside', 'outside'] : ['side_a', 'side_b'],
                    'entity_fingerprint' => GeometryInventory::entityFingerprint($wall, []), 'reason' => 'Принято для предварительной оценки'],
            ], $walls, array_keys($walls))]);
        }

        return [$actor, $session->fresh(), $walls];
    }

    private function draft(): array
    {
        $items = array_map(static fn ($work): array => ['key' => $work, 'name' => $work, 'item_type' => 'priced_work',
            'quantity' => null, 'unit' => 'm2', 'metadata' => ['composition_work_key' => $work, 'quantity_key' => $work,
                'quantity_source' => 'unknown', 'generation_source' => 'qualitative_work_catalog']], WallWorkItemExpander::WORKS);
        $items[] = ['key' => 'note', 'name' => 'Preserve my note', 'item_type' => 'review_note'];

        return ['generation_contract' => \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector::CONTRACT,
            'evaluation_policy' => ['price_policy' => 'normative'], 'local_estimates' => [['key' => 'structures', 'sections' => [['key' => 'walls', 'work_items' => $items]]]]];
    }
}
