<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Export\ReadConfirmedEvaluationExport;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetCurrentEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationPriceSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScenarioSet;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CataloguePriceSnapshotReader;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Integrations\EstimateGeneration\EstimateGenerationExcelExportService;
use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationScenarioConfirmationPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('exportPermissions')]
    public function test_confirmed_export_requires_export_permission_without_view(array $permissions, bool $allowed): void
    {
        [$actor, $session, , $input] = $this->fixture();
        Queue::fake();
        app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $before = $session->fresh()->getRawOriginal();
        $requested = [];
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturnUsing(static function (User $user, string $permission, array $context) use ($permissions, &$requested): bool {
            $requested[] = $permission;

            return in_array($permission, $permissions, true);
        });
        $this->app->instance(AuthorizationService::class, $authorization);
        $results = [];
        try {
            $projection = app(ReadConfirmedEvaluationExport::class)->read($actor, (int) $session->project_id, (int) $session->id);
            $results['reader'] = 200;
            self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ConfirmedEvaluationDraftProjector::CONTRACT, $projection->draft_payload['generation_contract']);
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $results['reader'] = 403;
        }
        foreach (['csv', 'json', 'excel'] as $format) {
            try {
                $results[$format] = $this->exportResponse($actor, $session, $format)->getStatusCode();
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                $results[$format] = 403;
            }
        }
        self::assertSame(array_fill_keys(['reader', 'csv', 'json', 'excel'], $allowed ? 200 : 403), $results);
        self::assertSame(array_fill(0, 4, 'estimate_generation.export'), $requested);
        self::assertSame($before, $session->fresh()->getRawOriginal());
    }

    public static function exportPermissions(): array
    {
        return [
            'export without view' => [['estimate_generation.export'], true],
            'view without export' => [['estimate_generation.view'], false],
            'neither permission' => [[], false],
        ];
    }

    public function test_csv_json_and_xlsx_export_the_confirmed_non_base_catalog_scenario_with_separate_net_totals(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        [$actor, $session, , $input] = $this->fixture('included', '120', reserve: ['quoted_amount' => '120', 'vat_mode' => 'included']);
        Queue::fake();
        $input['scenario_id'] = 'maximum';
        $confirmed = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $before = $session->fresh()->getRawOriginal();
        $json = $this->exportResponse($actor, $session, 'json');
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $json);
        $projection = json_decode($this->streamedBody($json), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ConfirmedEvaluationDraftProjector::CONTRACT, $projection['generation_contract']);
        self::assertSame('maximum', $projection['confirmed_evaluation']['scenario_id']);
        $items = $projection['local_estimates'][0]['sections'][0]['work_items'];
        self::assertSame(['preparation' => '1', 'finishing' => '30'], array_column($items, 'quantity', 'key'));
        self::assertSame(['100.00', '3000.00'], array_column($items, 'total_cost'));
        self::assertSame('100.00', $projection['contingency_amount']);
        self::assertSame('3840.00', $projection['accepted_payable_total']);
        $csv = $this->exportResponse($actor, $session, 'csv');
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $csv);
        $rows = $this->csvRows($this->streamedBody($csv));
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertCount(7, $row);
        }
        self::assertSame(['preparation', 'finishing'], array_column(array_slice($rows, 1), 2));
        self::assertSame(['1', '30'], array_column(array_slice($rows, 1), 4));
        self::assertSame(['100.00', '3000.00'], array_column(array_slice($rows, 1), 5));
        $xlsx = $this->exportResponse($actor, $session, 'excel');
        self::assertSame(200, $xlsx->getStatusCode());
        $this->assertConfirmedXlsx($xlsx->getContent(), '3200.00', '3840.00', '100.00');
        self::assertSame($before, $session->fresh()->getRawOriginal());
        $session->refresh();
        $published = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate::class)->handle(
            new \App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand((int) $session->id,
                (int) $session->organization_id, (int) $session->project_id, $confirmed['state_version'],
                idempotencyKey: (string) Str::uuid(), artifactHash: $session->draft_payload['artifact_hash'], actor: $actor));
        $estimate = \App\Models\Estimate::query()->findOrFail($published->estimateId);
        self::assertSame(['100.00', '3000.00'], $estimate->items()->whereNull('parent_work_id')->orderBy('id')->pluck('total_amount')->all());
        self::assertSame('3200.00', $estimate->total_amount);
        self::assertSame('3840.00', $estimate->total_amount_with_vat);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_csv_and_json_controller_reject_unconfirmed_stale_and_foreign_scopes(): void
    {
        [$actor, $session, $catalog, $input] = $this->fixture();
        foreach (['csv', 'json'] as $format) {
            self::assertSame(422, $this->exportResponse($actor, $session, $format)->getStatusCode());
        }
        $foreign = User::factory()->create(['current_organization_id' => Organization::factory()->create()->id]);
        foreach (['csv', 'json'] as $format) {
            try {
                $this->exportResponse($foreign, $session, $format);
                self::fail('Cross-organization export must be rejected.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }
        }
        app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $before = $session->fresh()->getRawOriginal();
        $catalog->update(['unit_price' => '101']);
        foreach (['csv', 'json'] as $format) {
            self::assertSame(422, $this->exportResponse($actor, $session, $format)->getStatusCode());
        }
        self::assertSame($before, $session->fresh()->getRawOriginal());
    }

    private function exportResponse(User $actor, EstimateGenerationSession $session, string $format): \Symfony\Component\HttpFoundation\Response
    {
        $request = \Illuminate\Http\Request::create('/export', 'GET', ['format' => $format]);
        $request->setUserResolver(static fn (): User => $actor);

        return app(\App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationPackageController::class)
            ->export($request, Project::query()->findOrFail($session->project_id), $session->fresh());
    }

    private function streamedBody(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();
        try {
            $response->sendContent();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function csvRows(string $content): array
    {
        $handle = fopen('php://temp', 'r+');
        try {
            fwrite($handle, $content);
            rewind($handle);
            $rows = [];
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    public function test_parameter_cases_recalculate_confirm_and_become_stale_after_operand_change_without_ai(): void
    {
        \Illuminate\Support\Facades\Http::fake([]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        [$actor, $session] = $this->fixture();
        Queue::fake();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $room = array_values(array_filter($models->snapshot(...$scope)->entities, static fn (Entity $entity): bool => $entity->id === 'room:fixture'))[0];
        foreach (['length' => '5', 'width' => '10'] as $parameter => $value) {
            $fact = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:scenario-'.$parameter,
                ...[...$scope, $room->sourceVersion, $room->id, $parameter, $value, 'm', 0.0, 'user_assumption', 'confirmed', []]);
            $models->saveSourceModel([], [$fact], []);
            $models->applyDecision(new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision('decision:scenario-'.$parameter,
                ...[...$scope, $room->sourceVersion, 'fact', $fact->id, $fact->id, 'user', (string) $actor->id,
                    'Введённое пользователем допущение для сценария, не измерение фотографии.', 1, []]), $fact);
        }
        $draft = $session->fresh()->draft_payload;
        foreach ($draft['local_estimates'][0]['sections'][0]['work_items'] as &$work) {
            $work['metadata']['quantity_key'] = $work['key'] === 'preparation' ? 'office.ceiling' : 'finish.floor';
            $work['name'] = $work['key'] === 'preparation' ? 'Отделка потолка' : 'Отделка пола';
            $work['metadata']['scope_entity_id'] = $room->id;
        }
        unset($work);
        $session->update(['draft_payload' => (new BuildMostEstimateDraft)->seal((new UniversalReviewSummaryProjector)->project($draft))]);
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        self::assertTrue($models->replaceTechnologyRecommendations(...[...$scope, $room->sourceVersion, $capture['token'], 'technology:v1', str_repeat('c', 64), [], []]));
        self::assertTrue($models->replaceCompleteness(...[...$scope, $room->sourceVersion, $capture['token'], 'technology:v1', str_repeat('c', 64), 'rules:v1', str_repeat('d', 64), [], []]));
        $cases = [];
        foreach (['minimum' => '5', 'base' => '6', 'maximum' => '7'] as $id => $value) {
            $cases[] = ['id' => $id, 'basis' => 'Согласованный вариант длины '.$value.' м', 'parameters' => [[
                'source_fact_id' => 'fact:scenario-length', 'source_fact_version' => 1, 'entity_id' => $room->id,
                'source_value_hash' => \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding::valueHash('5'),
                'unit' => 'm', 'value' => $value,
            ]]];
        }
        $request = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version, 'parameter_cases' => $cases];
        $parameters = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationScenarioParameters::class)
            ->handle($actor, $scope[1], $scope[2], $request['state_version']);
        self::assertSame(['length', 'width'], array_column($parameters['parameters'], 'parameter'));
        self::assertSame('5', $parameters['parameters'][0]['value']);
        self::assertSame('0', $parameters['cost_units']);
        $saved = app(SaveEvaluationScenarioSet::class)->handle($actor, $scope[1], $scope[2], $request);
        self::assertEquals($saved, app(SaveEvaluationScenarioSet::class)->handle($actor, $scope[1], $scope[2], $request));
        self::assertSame('0', $saved['cost_units']);
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], $saved['state_version'], (string) Str::uuid());
        self::assertSame(['50', '60', '70'], array_map(static fn (array $scenario): mixed => array_column($scenario['positions'], 'quantity', 'key')['preparation'], $revision['result']['scenarios']),
            json_encode(array_map(static fn (array $scenario): array => ['id' => $scenario['id'], 'quantities' => array_column($scenario['positions'], 'quantity', 'key')], $revision['result']['scenarios']), JSON_THROW_ON_ERROR));
        $basePosition = array_column($revision['result']['scenarios'][1]['positions'], null, 'key')['preparation'];
        self::assertSame('floor_area', $basePosition['quantity_formula']);
        self::assertSame('user_assumption', $basePosition['quantity_parameter_recipe']['status']);
        self::assertSame('5', $models->fact(...[...$scope, 'fact:scenario-length'])->value);
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode([
            'missing' => $revision['result']['missing_requirements'],
            'unpriced' => $revision['result']['scenarios'][1]['unpriced_positions'],
            'conditions' => $revision['result']['scenarios'][1]['price_conditions_unresolved'],
            'tax' => $revision['result']['scenarios'][1]['tax_breakdown_unknown_positions'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true];
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, $scope[1], $scope[2], $input);
        $current = app(GetCurrentEvaluation::class)->handle($actor, $scope[1], $scope[2]);
        self::assertFalse($current['result_stale']);
        self::assertSame('base', $current['confirmed_scenario']['scenario_id']);
        config(['cache.default' => 'array']);
        $this->actingAs($actor);
        $session->refresh();
        $command = new \App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand(
            $scope[2], $scope[0], $scope[1], $confirmation['state_version'], idempotencyKey: (string) Str::uuid(),
            artifactHash: $session->draft_payload['artifact_hash'], actor: $actor);
        $apply = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate::class);
        $published = $apply->handle($command);
        self::assertSame($published->estimateId, $apply->handle($command)->estimateId);
        $estimate = \App\Models\Estimate::query()->findOrFail($published->estimateId);
        self::assertSame('12000.00', $estimate->total_amount);
        $money = $estimate->only(['total_amount', 'total_amount_with_vat']);
        $width = $models->fact(...[...$scope, 'fact:scenario-width']);
        $changed = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:scenario-width-v2',
            ...[...$scope, $room->sourceVersion, $room->id, 'width', '11', 'm', 0.0, 'user_assumption', 'confirmed', [], 2, $width->id]);
        $models->saveSourceModel([], [$changed], []);
        $models->applyDecision(new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision('decision:scenario-width-v2',
            ...[...$scope, $room->sourceVersion, 'fact', $width->id, $changed->id, 'user', (string) $actor->id, 'Изменилось исходное допущение ширины.', 2, []]), $changed);
        $stale = app(GetCurrentEvaluation::class)->handle($actor, $scope[1], $scope[2]);
        self::assertTrue($stale['result_stale']);
        self::assertNull($stale['confirmed_scenario']);
        self::assertSame($current['revision_id'], $stale['revision_id']);
        self::assertSame($money, $estimate->fresh()->only(['total_amount', 'total_amount_with_vat']));
        \Illuminate\Support\Facades\Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_current_materialized_assumptions_reuse_their_signed_binding_without_new_history(): void
    {
        [$actor, $session, , $input] = $this->fixture();
        Queue::fake();
        app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $session->refresh();
        $current = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        $selected = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\SelectedEvaluationScenario)->select($current['result'], 'base');
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $before = $session->getRawOriginal();
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001]);
        $counts = [];
        foreach (['estimate_generation_project_model_assertions', 'estimate_generation_project_model_corrections'] as $table) {
            $counts[$table] = DB::table($table)->where('session_id', $session->id)->count();
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            self::assertFalse(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\MaterializeSelectedEvaluationQuantities::class)
                ->materialize($actor, $session, $selected, (string) Str::uuid(), $current['revision_id']));
            self::assertSame($before, $session->fresh()->getRawOriginal());
            self::assertSame($snapshot['token'], $models->snapshotForPlanning(...[...$scope, 10001])['token']);
            foreach ($counts as $table => $count) {
                self::assertSame($count, DB::table($table)->where('session_id', $session->id)->count());
            }
        }
        Queue::assertNothingPushed();
    }

    public function test_materialization_transfer_preserves_exact_raw_state_and_rejects_unexplained_context(): void
    {
        [$actor, $session, , $input] = $this->fixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $capture = static function () use ($models, $scope, $session): array {
            $state = $models->snapshotForPlanning(...[...$scope, 10001, true]);

            return [...$state, 'decisions' => $models->decisionsForSelectedFacts(...[...$scope, array_column($state['snapshot']->facts, 'id')]),
                'selections' => $session->input_payload['quantity_selections'] ?? [], 'input_payload' => $session->input_payload];
        };
        $before = $capture();
        $default = $models->snapshotForPlanning(...[...$scope, 10001]);
        self::assertSame(['snapshot', 'token'], array_keys($default));
        self::assertSame($default['token'], $before['token']);
        self::assertSame($before['token'], \App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingInputFingerprint::fromExactState($before['token_state']));
        $selector = new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\SelectedEvaluationScenario;
        $selected = $selector->select($session->draft_payload['evaluation_result'], 'base');
        self::assertTrue(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\MaterializeSelectedEvaluationQuantities::class)
            ->materialize($actor, $session, $selected, (string) Str::uuid(), $input['revision_id']));
        $after = $capture();
        $evaluation = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], $session->state_version, (string) Str::uuid());
        $materialized = $selector->select($evaluation['result'], 'base');
        $predicate = new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationScenarioSourceBinding;
        self::assertTrue($predicate->materializationMatches($before, $after, $selected, $materialized, (string) $actor->id, $input['revision_id']));
        $mutations = [];
        $changed = $after;
        $changed['input_payload']['price_policy'] = 'normative';
        $mutations['price policy'] = $changed;
        $changed = $after;
        $changed['token_state']['role_runs'][] = ['role' => 'geometry_expert', 'subject_version' => 'sha256:'.str_repeat('a', 64), 'input_fingerprint' => str_repeat('b', 64)];
        $mutations['completed role tuple'] = $changed;
        $oldIds = array_column($before['token_state']['facts'], 'id');
        foreach ($after['token_state']['facts'] as $index => $fact) {
            if (in_array($fact['id'], $oldIds, true)) {
                $changed = $after;
                $changed['token_state']['facts'][$index]['projection_version']++;
                $mutations['unchanged fact projection version'] = $changed;
                break;
            }
        }
        foreach ($after['token_state']['bindings'] as $index => $binding) {
            if (in_array($binding['fact_id'], $oldIds, true)) {
                $changed = $after;
                $changed['token_state']['bindings'][$index]['evidence_invalidation_version']++;
                $mutations['unchanged evidence binding version'] = $changed;
                break;
            }
        }
        foreach ($after['token_state']['facts'] as $index => $fact) {
            if (! in_array($fact['id'], $oldIds, true)) {
                $changed = $after;
                $changed['token_state']['facts'][$index]['projection_version']++;
                $mutations['new fact projection must match its actual written version'] = $changed;
                foreach ($after['token_state']['bindings'] as $bindingIndex => $binding) {
                    if ($binding['fact_id'] === $fact['id']) {
                        $changed = $after;
                        unset($changed['token_state']['bindings'][$bindingIndex]);
                        $mutations['new source must retain its actual evidence binding'] = $changed;
                        break;
                    }
                }
                break;
            }
        }
        self::assertCount(6, $mutations);
        foreach ($mutations as $name => $changed) {
            self::assertFalse($predicate->materializationMatches($before, $changed, $selected, $materialized, (string) $actor->id, $input['revision_id']), $name);
        }
        $oldRole = $before;
        $newRole = $after;
        $oldRole['token_state']['role_runs'] = $mutations['completed role tuple']['token_state']['role_runs'];
        $newRole['token_state']['role_runs'] = $oldRole['token_state']['role_runs'];
        $newRole['token_state']['role_runs'][0]['subject_version'] = 'sha256:'.str_repeat('c', 64);
        self::assertFalse($predicate->materializationMatches($oldRole, $newRole, $selected, $materialized, (string) $actor->id, $input['revision_id']));
        self::assertFalse($predicate->materializationMatches($before, $after, $selected, $materialized, (string) ($actor->id + 1), $input['revision_id']));
        $changed = $materialized;
        $changed['scenario']['positions'][0]['price_snapshot']['source_hash'] = str_repeat('d', 64);
        self::assertFalse($predicate->materializationMatches($before, $after, $selected, $changed, (string) $actor->id, $input['revision_id']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('materializerChanges')]
    public function test_materializer_does_not_reuse_changed_or_unproven_quantity_bindings(string $change): void
    {
        [$actor, $session, , $input] = $this->fixture();
        Queue::fake();
        app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $session->refresh();
        $current = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        $selected = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\SelectedEvaluationScenario)->select($current['result'], 'base');
        $selected['scenario']['positions'] = array_values(array_filter($selected['scenario']['positions'], static fn (array $position): bool => $position['key'] === 'preparation'));
        self::assertCount(1, $selected['scenario']['positions']);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $before = $session->input_payload['quantity_selections']['preparation'];
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $facts = array_column($snapshot->facts, null, 'id');
        $target = array_column($snapshot->entities, null, 'id')[$selected['scenario']['positions'][0]['entity_id']];
        if ($change === 'value') {
            $selected['scenario']['positions'][0]['quantity'] = '2';
        } elseif ($change === 'unit') {
            $selected['scenario']['positions'][0]['unit'] = 'count';
        } elseif ($change === 'target') {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts::class)->handle($actor, $scope[1], $scope[2],
                (int) $session->fresh()->state_version, (string) Str::uuid(),
                ['entities' => [['key' => 'materializer-second', 'type' => 'room', 'name' => 'Другая комната']]]);
            $session->refresh();
            $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
            $sources = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetRoomIdentitySources::class)
                ->handle($actor, $scope[1], $scope[2], includeManual: true)['sources'];
            $sources = array_values(array_filter($sources, static fn (array $source): bool => $source['name'] === 'Другая комната'));
            self::assertCount(1, $sources);
            $target = array_column($snapshot->entities, null, 'id')[$sources[0]['entity_id']];
            $selected['scenario']['positions'][0]['entity_id'] = $target->id;
        } elseif ($change === 'stale') {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\UserWorkQuantityFactWriter::class)
                ->write($actor, $session, 'preparation', '2', 'm2', 'Пользователь изменил прежнее допущение объёма.', (string) Str::uuid(), true, $target);
        } elseif ($change === 'revoked') {
            $models->invalidateSourceVersion(...[...$scope, $facts[$before['fact_id']]->sourceVersion, 'sha256:'.hash('sha256', 'revoked-choice')]);
        } elseif ($change === 'foreign') {
            [$foreignActor, $foreign, , $foreignInput] = $this->fixture();
            app(ConfirmEvaluationScenario::class)->handle($foreignActor, (int) $foreign->project_id, (int) $foreign->id, $foreignInput);
            $payload = $session->input_payload;
            $payload['quantity_selections']['preparation'] = $foreign->fresh()->input_payload['quantity_selections']['preparation'];
            $session->update(['input_payload' => $payload]);
        } elseif ($change === 'origin') {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2],
                ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version, 'work_item_key' => 'preparation',
                    'value' => '1', 'unit' => 'm2', 'reason' => 'Пользователь вводит текущий объём без сценарного допущения.']);
            $session->refresh();
            $source = $models->fact(...[...$scope, $session->input_payload['quantity_selections']['preparation']['source_fact_id']]);
            self::assertSame('user_input', $source->origin);
        } elseif (in_array($change, ['payload', 'malformed'], true)) {
            $payload = $session->input_payload;
            if ($change === 'malformed') {
                $payload['quantity_selections']['preparation']['fact_id'] = [];
            } else {
                $payload['quantity_selections']['preparation']['source_value_hash'] = str_repeat('0', 64);
            }
            $session->update(['input_payload' => $payload]);
        }
        $beforeReplacement = $session->fresh()->input_payload['quantity_selections']['preparation'];
        self::assertTrue(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\MaterializeSelectedEvaluationQuantities::class)
            ->materialize($actor, $session, $selected, (string) Str::uuid(), $current['revision_id']));
        $selection = $session->fresh()->input_payload['quantity_selections']['preparation'];
        self::assertNotSame($beforeReplacement['fact_id'], $selection['fact_id']);
        $source = $models->fact(...[...$scope, $selection['source_fact_id']]);
        self::assertSame('user_assumption', $source->origin);
        self::assertSame($change === 'value' ? '2' : '1', $source->value);
        self::assertSame($change === 'unit' ? 'count' : 'm2', $source->unit);
        self::assertSame($target->id, $source->entityId);
        self::assertSame([], $source->nativeNumericProofs);
        Queue::assertNothingPushed();
    }

    public static function materializerChanges(): array
    {
        return array_map(static fn (string $change): array => [$change], ['value', 'unit', 'target', 'stale', 'revoked', 'foreign', 'origin', 'payload', 'malformed']);
    }

    public function test_confirmation_is_a_real_free_choice_of_one_current_scenario_and_replays_once(): void
    {
        [$actor, $session, $catalog, $input] = $this->fixture();
        Queue::fake();
        $service = app(ConfirmEvaluationScenario::class);
        $result = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertEquals($result, $service->handle($actor, (int) $session->project_id, (int) $session->id, $input));
        self::assertSame('0', $result['cost_units']);
        $current = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertFalse($current['result_stale']);
        self::assertSame('base', $current['confirmed_scenario']['scenario_id']);
        self::assertNotSame($input['revision_id'], $current['revision_id']);
        self::assertSame($current['revision_id'], $result['result_revision_id']);
        self::assertSame(['RUB' => '2100.00'], $current['confirmed_scenario']['total_by_currency']);
        self::assertNotEmpty($current['confirmed_scenario']['assumptions']);
        $payload = $session->fresh()->input_payload;
        $forged = $payload;
        $forged['confirmed_evaluation']['scenario_id'] = 'maximum';
        $session->update(['input_payload' => $forged]);
        self::assertNull(app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id)['confirmed_scenario']);
        $session->update(['input_payload' => $payload]);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $facts = app(ProjectModelRepository::class)->snapshot(...$scope)->facts;
        self::assertCount(1, array_filter($facts, static fn ($fact): bool => $fact->type === 'evaluation_scenario' && ($fact->value['answer_kind'] ?? null) === 'selected_scenario'));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $session->organization_id)->count());
        $item = $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('1', $item['quantity']);
        self::assertSame('assumed', $item['quantity_status']);
        self::assertSame('direct_quantity', $item['quantity_basis_details']['formula_key']);
        $numbers = array_values(array_filter($facts, static fn ($fact): bool => \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary::parameter($fact->type) === 'quantity'));
        self::assertCount(2, $numbers);
        self::assertSame(['room:fixture'], array_values(array_unique(array_column($numbers, 'entityId'))));
        self::assertSame(['user_assumption'], array_values(array_unique(array_column($numbers, 'origin'))));
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard::class)
            ->assertCurrent(...[...$scope, $session->fresh()->draft_payload]));
        Queue::assertNothingPushed();
        $catalog->update(['unit_price' => '200']);
        try {
            $service->handle($actor, (int) $session->project_id, (int) $session->id,
                [...$input, 'revision_id' => $current['revision_id'], 'content_hash' => $current['content_hash'],
                    'request_id' => (string) Str::uuid(), 'state_version' => $result['state_version']]);
            self::fail('Changed catalog price was silently confirmed.');
        } catch (ValidationException) {
            self::assertSame($result['state_version'], $session->fresh()->state_version);
        }
        $session->update(['input_payload' => [...$payload, 'description' => 'Новый состав после подтверждения']]);
        $stale = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertTrue($stale['result_stale']);
        self::assertNull($stale['confirmed_scenario']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('confirmationDenials')]
    public function test_confirmation_rejects_unaccepted_assumptions_old_revisions_and_changed_scope(string $change): void
    {
        [$actor, $session, , $input] = $this->fixture();
        if ($change === 'assumptions') {
            $input['assumptions_accepted'] = false;
        } elseif ($change === 'revision') {
            $input['revision_id'] = (string) Str::uuid();
        } elseif ($change === 'scope') {
            $session->update(['input_payload' => [...$session->input_payload, 'selected_sections' => ['roofing']]]);
        } elseif ($change === 'actor') {
            $actor->update(['is_active' => false]);
        }
        $before = $session->fresh()->state_version;
        try {
            app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
            self::fail('An invalid scenario confirmation was accepted.');
        } catch (ValidationException|\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame($before, $session->fresh()->state_version);
            self::assertArrayNotHasKey('confirmed_evaluation', $session->fresh()->input_payload);
        }
    }

    public static function confirmationDenials(): array
    {
        return array_map(static fn (string $change): array => [$change], ['assumptions', 'revision', 'scope', 'actor']);
    }

    public function test_final_confirmation_remains_verifiable_without_changing_numerical_snapshot_but_quantity_inputs_change_it(): void
    {
        [$actor, $session, , $input] = $this->fixture();
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $before = $models->snapshotForPlanning(...[...$scope, 10001]);
        $row = app(GetCurrentEvaluation::class)->handle($actor, $scope[1], $scope[2]);
        $selected = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\SelectedEvaluationScenario)->select($row['result'], 'base');
        $choice = ['answer_kind' => 'selected_scenario', 'revision_id' => $input['revision_id'], 'content_hash' => $input['content_hash'],
            'input_hash' => $row['input_hash'], 'scenario_id' => 'base', 'scenario_hash' => $selected['scenario_hash'],
            'selected_scope' => ['finishing'], 'assumptions_accepted' => true];
        $proof = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\UserEvaluationChoiceWriter::class)
            ->write($actor, $session, 'selected-scenario', 'evaluation_scenario', $choice, 'Принят показанный сценарий.', (string) Str::uuid());
        $after = $models->snapshotForPlanning(...[...$scope, 10001]);
        self::assertSame($before['token'], $after['token']);
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($after['snapshot']->facts, 'id')]);
        self::assertTrue((new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationSelectionVerifier)
            ->accepted($after['snapshot'], $decisions, [...$choice, ...$proof], 'evaluation_scenario', $choice));
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\UserWorkQuantityFactWriter::class)
            ->write($actor, $session, 'finishing', '30', 'm2', 'Новое явное допущение объёма.', (string) Str::uuid(), true);
        self::assertNotSame($after['token'], $models->snapshotForPlanning(...[...$scope, 10001])['token']);
    }

    public function test_confirmed_catalog_scenario_creates_one_ordinary_estimate_with_identical_payable_money(): void
    {
        [$actor, $session, $catalog, $input] = $this->fixture('included', '0.03');
        Queue::fake();
        config(['cache.default' => 'array']);
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $session->refresh();
        self::assertSame('ready_to_apply', $session->status->value);
        $this->actingAs($actor);
        $snapshot = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionOperationalSnapshotBuilder::class)
            ->handle($session, ['estimate_generation.view', 'estimate_generation.apply']);
        self::assertTrue($snapshot->canApply, json_encode($snapshot->blockingIssues));
        self::assertContains('apply', array_column($snapshot->availableActions, 'action'));
        self::assertSame('review', $snapshot->recommendedStep);
        self::assertSame('0.63', $snapshot->evaluationConfirmation['total_by_currency']['RUB']);
        self::assertSame('0.63', $snapshot->estimateSummary['total_cost']);
        // Cosmetic timestamps are not part of the accepted business price or its scenario identity.
        $catalog->touch();
        $command = new \App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand(
            (int) $session->id, (int) $session->organization_id, (int) $session->project_id, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->draft_payload['artifact_hash'], actor: $actor);
        $apply = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate::class);
        $published = $apply->handle($command);
        self::assertSame($published->estimateId, $apply->handle($command)->estimateId);
        $estimate = \App\Models\Estimate::query()->findOrFail($published->estimateId);
        self::assertSame('analog', $estimate->calculation_method);
        self::assertSame('0.53', $estimate->total_amount);
        self::assertSame('0.63', $estimate->total_amount_with_vat);
        self::assertSame(2, $estimate->items()->whereNull('parent_work_id')->count());
        self::assertSame(0, $estimate->items()->whereNotNull('normative_rate_id')->count());
        foreach ($estimate->items as $item) {
            self::assertNotEmpty($item->metadata['generated_source_money']['confirmation']['fact_id']);
            self::assertSame('included', $item->metadata['generated_source_money']['money']['vat_mode']);
        }
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            self::assertSame('0.63', $estimate->fresh()->total_amount_with_vat);
        }
        $export = (new \ReflectionMethod(\App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService::class, 'prepareTotalsData'))
            ->invoke(app(\App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService::class), $estimate->fresh());
        self::assertSame('0.63', (string) $export['total_amount_with_vat']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_linked_delivery_survives_confirmation_readiness_and_ordinary_publication_with_current_sources(): void
    {
        [$actor, $session, , $input, $deliveryCatalog] = $this->fixture(linkedDelivery: true);
        Queue::fake();
        config(['cache.default' => 'array']);
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $session->refresh();
        $this->actingAs($actor);
        $evaluation = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertNotNull($evaluation['confirmed_scenario']);
        $snapshot = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionOperationalSnapshotBuilder::class)
            ->handle($session, ['estimate_generation.view', 'estimate_generation.apply']);
        self::assertTrue($snapshot->canApply, json_encode($snapshot->blockingIssues));
        self::assertSame('2140.00', $snapshot->evaluationConfirmation['total_by_currency']['RUB']);
        self::assertSame('2000.00', $session->draft_payload['evaluation_result']['audit_dispositions'][0]['calculated_amount']);
        $deliveryCatalog->update(['unit_price' => '41']);
        $changed = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertNull($changed['confirmed_scenario']);
        self::assertTrue($changed['result_stale']);
        self::assertSame($confirmation['state_version'], $session->fresh()->state_version);
        $deliveryCatalog->update(['unit_price' => '40']);
        $command = new \App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand(
            (int) $session->id, (int) $session->organization_id, (int) $session->project_id, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->draft_payload['artifact_hash'], actor: $actor);
        $apply = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate::class);
        $published = $apply->handle($command);
        self::assertSame($published->estimateId, $apply->handle($command)->estimateId);
        $estimate = \App\Models\Estimate::query()->findOrFail($published->estimateId);
        self::assertSame('2140.00', $estimate->total_amount_with_vat);
        self::assertSame(3, $estimate->items()->whereNull('parent_work_id')->count());
        app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService::class)->recalculateAll($estimate);
        self::assertSame('2140.00', $estimate->fresh()->total_amount_with_vat);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_a_catalog_change_revokes_read_only_confirmation_and_the_apply_action_without_changing_the_revision(): void
    {
        [$actor, $session, $catalog, $input] = $this->fixture();
        app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $this->actingAs($actor);
        $session->refresh();
        $originalState = $session->state_version;
        $originalRevisionCount = DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count();
        $catalog->update(['unit_price' => '200']);
        $evaluation = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertNull($evaluation['confirmed_scenario']);
        self::assertTrue($evaluation['result_stale']);
        $snapshot = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionOperationalSnapshotBuilder::class)
            ->handle($session->fresh(), ['estimate_generation.view', 'estimate_generation.apply']);
        self::assertFalse($snapshot->canApply);
        self::assertNotContains('apply', array_column($snapshot->availableActions, 'action'));
        self::assertSame($originalState, $session->fresh()->state_version);
        self::assertSame($originalRevisionCount, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignSourceCases')]
    public function test_foreign_source_rounding_survives_confirmation_ordinary_publication_recalculation_and_export(string $mode, string $net, string $payable): void
    {
        [$actor, $session, , $input] = $this->fixture($mode, '0.03', 'USD');
        Queue::fake();
        config(['cache.default' => 'array']);
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $session->refresh();
        $command = new \App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand(
            (int) $session->id, (int) $session->organization_id, (int) $session->project_id, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->draft_payload['artifact_hash'], actor: $actor);
        $published = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate::class)->handle($command);
        $estimate = \App\Models\Estimate::query()->findOrFail($published->estimateId);
        self::assertSame($net, $estimate->total_amount);
        self::assertSame($payable, $estimate->total_amount_with_vat);
        foreach ($estimate->items as $item) {
            self::assertSame('USD', $item->metadata['generated_source_money']['money']['currency_conversion']['source_snapshot']['currency']);
            self::assertSame('90', $item->metadata['generated_source_money']['money']['currency_conversion']['rate']['rub_per_unit']);
        }
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            $estimate->refresh();
            self::assertSame($net, $estimate->total_amount);
            self::assertSame($payable, $estimate->total_amount_with_vat);
            $export = (new \ReflectionMethod(\App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService::class, 'prepareTotalsData'))
                ->invoke(app(\App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService::class), $estimate);
            self::assertSame($payable, (string) \Brick\Math\BigDecimal::of((string) $export['total_amount_with_vat'])->toScale(2));
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public static function foreignSourceCases(): array
    {
        return [['included', '47.70', '56.70'], ['excluded', '56.70', '68.40']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reserveCases')]
    public function test_confirmed_fixed_contingency_is_preserved_as_a_separate_net_component_with_exact_payable_tax(string $mode, string $quoted,
        string $reserveNet, string $totalNet, string $payable, string $legacyPercent = '0'): void
    {
        [$actor, $session, , $input] = $this->fixture('included', '0.03', 'RUB', ['vat_mode' => $mode, 'quoted_amount' => $quoted, 'legacy_percent' => $legacyPercent]);
        Queue::fake();
        config(['cache.default' => 'array']);
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $session->refresh();
        self::assertSame($payable, $session->draft_payload['evaluation_result']['scenarios'][1]['total_by_currency']['RUB']);
        self::assertCount(2, $session->draft_payload['evaluation_result']['scenarios'][1]['positions']);
        $beforeExport = $session->getRawOriginal();
        $exportSession = app(ReadConfirmedEvaluationExport::class)->read($actor, (int) $session->project_id, (int) $session->id);
        self::assertNotSame($session, $exportSession);
        self::assertSame($beforeExport, $session->fresh()->getRawOriginal());
        self::assertSame(UniversalDraftProjector::CONTRACT, $session->fresh()->draft_payload['generation_contract']);
        $this->assertConfirmedXlsx(app(EstimateGenerationExcelExportService::class)->export($exportSession)['content'], $totalNet, $payable, $reserveNet);
        $command = new \App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand(
            (int) $session->id, (int) $session->organization_id, (int) $session->project_id, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->draft_payload['artifact_hash'], actor: $actor);
        $published = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate::class)->handle($command);
        $estimate = \App\Models\Estimate::query()->findOrFail($published->estimateId);
        self::assertSame($reserveNet, $estimate->contingency_amount);
        self::assertSame((string) \Brick\Math\BigDecimal::of($quoted)->toScale(2), $estimate->metadata['source_contingency_money']['quoted_amount']);
        self::assertSame($mode, $estimate->metadata['source_contingency_money']['vat_mode']);
        self::assertCount(2, $estimate->items);
        self::assertNotEmpty($estimate->metadata['confirmed_contingency']['fact_id']);
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            $estimate->refresh();
            self::assertSame($totalNet, $estimate->total_amount);
            self::assertSame($payable, $estimate->total_amount_with_vat);
            self::assertSame($reserveNet, $estimate->contingency_amount);
            $export = (new \ReflectionMethod(\App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService::class, 'prepareTotalsData'))
                ->invoke(app(\App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService::class), $estimate);
            self::assertSame($payable, (string) \Brick\Math\BigDecimal::of((string) $export['total_amount_with_vat'])->toScale(2));
            self::assertSame($reserveNet, (string) \Brick\Math\BigDecimal::of((string) $export['contingency_amount'])->toScale(2));
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_universal_xlsx_rejects_unconfirmed_stale_and_cross_organization_sources_without_generation(): void
    {
        \Illuminate\Support\Facades\Http::fake([]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        [$actor, $session, , $input] = $this->fixture();
        $reader = app(ReadConfirmedEvaluationExport::class);
        try {
            app(EstimateGenerationExcelExportService::class)->export($session);
            self::fail('The formatter must reject an unconfirmed universal draft without a verified projection.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('draft', $exception->errors());
        }
        try {
            $reader->read($actor, (int) $session->project_id, (int) $session->id);
            self::fail('An unconfirmed scenario must not be exported as a payable estimate.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('draft', $exception->errors());
        }
        $other = User::factory()->create(['current_organization_id' => Organization::factory()->create()->id]);
        try {
            $reader->read($other, (int) $session->project_id, (int) $session->id);
            self::fail('Cross-organization export must be rejected.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            self::addToAssertionCount(1);
        }
        app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $confirmed = $session->fresh();
        $before = $confirmed->getRawOriginal();
        $revisionCount = DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count();
        $reader->read($actor, (int) $session->project_id, (int) $session->id);
        self::assertSame($before, $session->fresh()->getRawOriginal());
        self::assertSame($revisionCount, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        $price = EstimatePositionCatalog::query()->where('organization_id', $session->organization_id)->firstOrFail();
        $price->update(['unit_price' => '101']);
        try {
            $reader->read($actor, (int) $session->project_id, (int) $session->id);
            self::fail('A changed source price must invalidate the payable export.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('draft', $exception->errors());
        }
        self::assertSame($before, $session->fresh()->getRawOriginal());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    private function assertConfirmedXlsx(string $content, string $net, string $gross, string $reserve): void
    {
        $path = tempnam(sys_get_temp_dir(), 'most-confirmed-scenario-export-');
        self::assertNotFalse($path);
        $book = null;
        try {
            file_put_contents($path, $content);
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('Смета');
            self::assertNotNull($sheet);
            $values = [];
            for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
                $label = (string) $sheet->getCell('F'.$row)->getValue();
                if (in_array($label, ['Резерв на непредвиденные расходы:', 'ИТОГО без НДС:', 'ВСЕГО С НДС:', 'НДС по подтверждённым источникам:'], true)) {
                    $values[$label][] = (string) \Brick\Math\BigDecimal::of(str_replace(' ', '', (string) $sheet->getCell('G'.$row)->getValue()))->toScale(2);
                }
            }
            self::assertSame([$net], $values['ИТОГО без НДС:'] ?? []);
            self::assertSame([$gross], $values['ВСЕГО С НДС:'] ?? []);
            self::assertSame([(string) \Brick\Math\BigDecimal::of($gross)->minus($net)->toScale(2)], $values['НДС по подтверждённым источникам:'] ?? []);
            self::assertSame($reserve === '0.00' ? [] : [$reserve], $values['Резерв на непредвиденные расходы:'] ?? []);
            $metadata = json_decode((string) $book->getSheetByName('_METADATA_')->getCell('A1')->getValue(), true, 64, JSON_THROW_ON_ERROR);
            self::assertCount(2, $metadata['items']);
            foreach ($metadata['items'] as $item) {
                self::assertNotEmpty($item['metadata']['source_money']);
                self::assertNotEmpty($item['metadata']['evaluation_confirmation']);
                self::assertNotEmpty($item['metadata']['price_snapshot']['source_hash']);
            }
        } finally {
            $book?->disconnectWorksheets();
            unlink($path);
        }
    }

    public static function reserveCases(): array
    {
        return [['included', '120', '100.00', '100.53', '120.63'], ['excluded', '100', '100.00', '100.53', '120.63'],
            ['not_applicable', '100', '100.00', '100.53', '100.63'], ['included', '0.03', '0.03', '0.56', '0.66'],
            ['not_applicable', '0', '0.00', '0.53', '0.63', '7']];
    }

    public function test_supplementary_work_reserves_the_full_ledger_path_through_scenario_confirmation(): void
    {
        [$actor, $session, $catalog] = $this->fixture();
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $room = array_column($snapshot->entities, null, 'id')['room:fixture'];
        $requestId = (string) Str::uuid();
        $factId = 'fact:'.hash('sha256', $requestId.'|'.$room->id.'|entity_identity');
        $value = ['answer_kind' => 'entity_identity', 'entity_id' => $room->id, 'entity_type' => 'room',
            'name' => 'Комната', 'identity_confirmed' => true, 'decision_id' => 'decision:'.hash('sha256', $requestId.'|'.$factId),
            'reason' => 'Пользователь подтвердил помещение для сметного расчёта.'];
        $hash = hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($value));
        $node = app(\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository::class)->insertOrGet(
            new \App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData(...[...$scope,
                \App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType::SourceFact,
                \App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType::UserInput, 'input:'.$scope[2], $room->sourceVersion,
                ['source_key' => 'source:'.hash('sha256', $factId), 'native_reference' => 'input_payload.manual_facts'],
                ['fact_key' => 'evaluation_choice', 'fact_value' => $hash], 0.0,
                \App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer::UserInputNormalizer->value, 'sha256:'.hash('sha256', 'manual-entity-identity:v1')]));
        $proof = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence('evidence:'.$node->id,
            ...[...$scope, $room->sourceVersion, 'input:'.$scope[2], 'user_input', null, null, 'input_payload.manual_facts',
                \App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer::UserInputNormalizer->value, 'input:'.$scope[2], $hash]);
        $identity = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact($factId,
            ...[...$scope, $room->sourceVersion, $room->id, 'entity_identity', $value, null, 0.0, 'user_input', 'confirmed', [$proof->id]]);
        $models->saveSourceModel([], [$identity], [$proof]);
        $models->applyDecision(new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision($value['decision_id'],
            ...[...$scope, $room->sourceVersion, 'fact', $factId, $factId, 'user', (string) $actor->id, $value['reason'], 1, [$proof->id]]), $identity);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $template = (array) DB::table('estimate_generation_project_model_assertions')->where('organization_id', $scope[0])
            ->where('session_id', $scope[2])->where('stable_key', 'fact:room-name')->first();
        $projection = (array) DB::table('estimate_generation_project_model_fact_projections')->where('fact_id', $template['id'])->first();
        unset($template['id'], $projection['id']);
        $fillers = [];
        for ($index = count($snapshot->facts); $index < 9983; $index++) {
            $fillers[] = [...$template, 'stable_key' => 'fact:capacity-'.$index, 'assertion_type' => 'capacity_fixture_'.$index];
        }
        foreach (array_chunk($fillers, 500) as $chunk) {
            DB::table('estimate_generation_project_model_assertions')->insert($chunk);
        }
        $inserted = DB::table('estimate_generation_project_model_assertions')->where('organization_id', $scope[0])
            ->where('session_id', $scope[2])->where('stable_key', 'like', 'fact:capacity-%')->get(['id', 'assertion_type']);
        $projections = [];
        foreach ($inserted as $fact) {
            $projections[] = [...$projection, 'fact_id' => $fact->id, 'fact_type' => $fact->assertion_type];
        }
        foreach (array_chunk($projections, 500) as $chunk) {
            DB::table('estimate_generation_project_model_fact_projections')->insert($chunk);
        }
        self::assertCount(9983, $models->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts);
        $composition = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationWorkComposition::class);
        $offered = $composition->show($actor, $scope[1], $scope[2]);
        self::assertSame(1, $offered['maximum_additional_rows']);
        $added = $composition->accept($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'preview_fingerprint' => $offered['preview_fingerprint'],
            'work_keys' => ['ceiling.insulation'], 'reason' => 'Добавлен пропущенный слой утепления потолка по исходному заданию.']);
        $key = $added['added_work_item_keys'][0];
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BindEvaluationWorkEntity::class)->handle($actor, $scope[1], $scope[2], [
            'request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version, 'work_item_key' => $key,
            'source_fact_id' => $identity->id, 'source_fact_version' => 1,
            'source_value_hash' => \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding::valueHash($value),
            'reason' => 'Утепление потолка относится к уже подтверждённому помещению.']);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], [
            'request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'work_item_key' => $key, 'value' => '5', 'unit' => 'm2', 'reason' => 'Сметное допущение площади дополнительного утепления потолка.']);
        $source = app(CataloguePriceSnapshotReader::class)->read($scope[0], 'catalog:'.$catalog->id);
        app(SaveEvaluationPriceSelection::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'work_item_key' => $key,
            'source_reference' => $source['source_reference'], 'source_hash' => $source['source_hash']]);
        app(SaveEvaluationScopeSelection::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'selected_sections' => ['finishing'],
            'profile_id' => 'construction', 'construction_type' => 'new_construction', 'price_policy' => 'catalog']);
        app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], $session->fresh()->state_version, (string) Str::uuid());
        $definitions = [];
        foreach (['minimum', 'base', 'maximum'] as $id) {
            $definitions[] = ['id' => $id, 'basis' => 'Согласованные сметные количества выбранного состава',
                'quantities' => [['work_item_key' => 'preparation', 'value' => '1', 'unit' => 'm2'],
                    ['work_item_key' => 'finishing', 'value' => '20', 'unit' => 'm2'], ['work_item_key' => $key, 'value' => '5', 'unit' => 'm2']]];
        }
        app(SaveEvaluationScenarioSet::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'definitions' => $definitions]);
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], $session->fresh()->state_version, (string) Str::uuid());
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode($revision['result']['missing_requirements']));
        $confirmed = app(ConfirmEvaluationScenario::class)->handle($actor, $scope[1], $scope[2], [
            'request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true]);
        self::assertSame('0', $confirmed['cost_units']);
        self::assertLessThanOrEqual(10000, count($models->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts));
        $current = app(GetCurrentEvaluation::class)->handle($actor, $scope[1], $scope[2]);
        self::assertFalse($current['result_stale']);
        self::assertSame('base', $current['confirmed_scenario']['scenario_id']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->count());
    }

    private function fixture(string $vatMode = 'not_applicable', string $unitPrice = '100', string $currency = 'RUB', ?array $reserve = null, bool $linkedDelivery = false): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $unit = MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => 'Квадратный метр', 'short_name' => 'м²', 'type' => 'area']);
        $catalog = EstimatePositionCatalog::query()->create(['organization_id' => $organization->id, 'name' => 'Работы по полу', 'code' => 'P-'.Str::random(6),
            'item_type' => 'work', 'measurement_unit_id' => $unit->id, 'unit_price' => $unitPrice, 'is_active' => true, 'created_by_user_id' => $actor->id,
            'metadata' => ['currency' => $currency, 'price_as_of_date' => '2026-10-01', 'price_region' => 'Москва', 'vat_mode' => $vatMode, 'vat_rate' => '20', 'delivery_included' => ! $linkedDelivery]]);
        $deliveryCatalog = null;
        if ($linkedDelivery) {
            $deliveryUnit = MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => 'Штука', 'short_name' => 'шт', 'type' => 'piece']);
            $deliveryCatalog = EstimatePositionCatalog::query()->create(['organization_id' => $organization->id, 'name' => 'Доставка', 'code' => 'D-'.Str::random(6),
                'item_type' => 'work', 'measurement_unit_id' => $deliveryUnit->id, 'unit_price' => '40', 'is_active' => true, 'created_by_user_id' => $actor->id,
                'metadata' => ['currency' => 'RUB', 'price_as_of_date' => '2026-10-01', 'price_region' => 'Москва', 'vat_mode' => 'not_applicable', 'delivery_included' => true]]);
        }
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'status' => 'estimate_review_required', 'state_version' => 0,
            'input_payload' => ['evaluation_mode' => 'universal', 'price_policy' => 'catalog', 'region' => 'Москва', 'selected_sections' => ['finishing']]]);
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $entity = new Entity('room:fixture', ...[...$scope, 'sha256:'.hash('sha256', 'scope-without-photo-dimensions'), 'room', 'room:fixture',
            ['semantic_type' => 'room', 'identity' => ['room_name' => 'Комната']]]);
        $roomName = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:room-name',
            ...[...$scope, $entity->sourceVersion, $entity->id, 'room_name', 'Комната', null, 0.0, 'user_assumption', 'confirmed', []]);
        $models->saveSourceModel([$entity], [$roomName], []);
        $models->applyDecision(new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision('decision:room-name',
            ...[...$scope, $entity->sourceVersion, 'fact', $roomName->id, $roomName->id, 'user', (string) $actor->id,
                'Пользователь задаёт помещение сценария без утверждения его размеров.', 1, []]), $roomName);
        $items = [];
        foreach (['preparation', 'finishing', ...($linkedDelivery ? ['delivery'] : [])] as $key) {
            $items[] = ['key' => $key, 'name' => $key, 'work_key' => $key, 'item_type' => 'priced_work', 'unit' => 'm2',
                'quantity' => null, 'quantity_status' => 'unknown', 'metadata' => ['entity_id' => $entity->id],
                ...($key === 'delivery' ? ['unit' => 'pcs'] : [])];
        }
        $draft = ['generation_contract' => UniversalDraftProjector::CONTRACT,
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session),
            'evaluation_policy' => ['selected_sections' => ['finishing'], 'price_policy' => 'catalog'],
            'audit_review_items' => $linkedDelivery ? [['finding_id' => 'audit:zero', 'item_key' => 'finishing',
                'type' => 'suspicious_zero', 'severity' => 'material', 'reason' => 'Итог ранее отсутствовал.']] : [],
            'local_estimates' => [['key' => 'finishing', 'sections' => [['key' => 'floor', 'work_items' => $items]]]]];
        $draft['evaluation_result'] = (new UniversalEvaluationBuilder)->build($draft, $models->snapshot(...$scope));
        $session->update(['draft_payload' => (new BuildMostEstimateDraft)->seal((new UniversalReviewSummaryProjector)->project($draft))]);
        app(SaveEvaluationScopeSelection::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(), 'state_version' => 0,
            'selected_sections' => ['finishing'], 'profile_id' => 'construction', 'construction_type' => 'new_construction', 'price_policy' => 'catalog']);
        $source = app(CataloguePriceSnapshotReader::class)->read($scope[0], 'catalog:'.$catalog->id);
        foreach (['preparation', 'finishing', ...($linkedDelivery ? ['delivery'] : [])] as $key) {
            $selectedSource = $key === 'delivery' ? app(CataloguePriceSnapshotReader::class)->read($scope[0], 'catalog:'.$deliveryCatalog->id) : $source;
            app(SaveEvaluationPriceSelection::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
                'state_version' => $session->fresh()->state_version, 'work_item_key' => $key,
                'source_reference' => $selectedSource['source_reference'], 'source_hash' => $selectedSource['source_hash'],
                ...($linkedDelivery && $key !== 'delivery' ? ['conditions_confirmation' => ['delivery' => [
                    'work_item_key' => 'delivery', 'basis' => 'Доставка учтена отдельной работой в выбранном составе.']]] : [])]);
        }
        if ($currency !== 'RUB') {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationCurrencySelection::class)->handle($actor, $scope[1], $scope[2],
                ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version, 'from_currency' => $currency,
                    'to_currency' => 'RUB', 'rub_per_unit' => '90', 'as_of_date' => '2026-10-12', 'rate_source_reference' => 'Курс договора']);
        }
        $definitions = [];
        foreach (['minimum' => '12', 'base' => '20', 'maximum' => '30'] as $id => $value) {
            $definitions[] = ['id' => $id, 'basis' => 'Явно заданный пользователем сценарный объём без подтверждения размеров фотографии',
                'quantities' => [['work_item_key' => 'preparation', 'value' => '1', 'unit' => 'm2'], ['work_item_key' => 'finishing', 'value' => $value, 'unit' => 'm2'],
                    ...($linkedDelivery ? [['work_item_key' => 'delivery', 'value' => '1', 'unit' => 'pcs']] : [])]];
        }
        app(SaveEvaluationScenarioSet::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'definitions' => $definitions]);
        if ($reserve !== null) {
            if (($reserve['legacy_percent'] ?? '0') !== '0') {
                $legacyDraft = $session->fresh()->draft_payload;
                $legacyDraft['contingency_percent'] = $reserve['legacy_percent'];
                $session->update(['draft_payload' => (new BuildMostEstimateDraft)->seal($legacyDraft)]);
            }
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationContingency::class)->handle($actor, $scope[1], $scope[2],
                ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version, 'kind' => 'fixed', 'currency' => 'RUB',
                    'quoted_amount' => $reserve['quoted_amount'], 'vat_mode' => $reserve['vat_mode'], 'vat_rate' => '20',
                    'basis' => 'Подтверждённый резерв выбранного состава.']);
        }
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], $session->fresh()->state_version, (string) Str::uuid());
        self::assertTrue($revision['result']['scope_confirmed']);
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode([
            'requirements' => $revision['result']['missing_requirements'],
            'unpriced' => $revision['result']['scenarios'][1]['unpriced_positions'],
            'conditions' => $revision['result']['scenarios'][1]['price_conditions_unresolved'],
            'tax_unknown' => $revision['result']['scenarios'][1]['tax_breakdown_unknown_positions'],
            'currency_required' => $revision['result']['currency_conversion_required'],
            'prices' => array_column($revision['result']['scenarios'][1]['positions'], 'price_snapshot'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true];

        return [$actor, $session->fresh(), $catalog, $input, $deliveryCatalog];
    }
}
