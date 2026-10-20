<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\NativeParameterBacklog;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationDraftArithmeticRecalculator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SaveManualProjectFactsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningEstimateScope;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningWorkItemExpander;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class OpeningScopeEvaluationPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[DataProvider('openingReasons')]
    public function test_opening_reason_validation_matches_persisted_decision_byte_limit(string $reason): void
    {
        [$actor, $session] = $this->fixture();
        $beforeCalls = count(Http::recorded());
        $input = $this->answer($this->questions($session)[0], 'door');
        $input['entities'][0]['opening_scope']['reason'] = $reason;
        $request = ['state_version' => 2, 'request_id' => (string) Str::uuid(), ...$input];
        $rules = (new SaveManualProjectFactsRequest)->rules();
        self::assertFalse(Validator::make($request, $rules)->fails());
        foreach ([str_repeat('я', 501), str_repeat('a', 1001), str_repeat('😀', 251), str_repeat('я', 9)] as $invalidReason) {
            $invalid = $request;
            $invalid['entities'][0]['opening_scope']['reason'] = $invalidReason;
            $validator = Validator::make($invalid, $rules);
            self::assertTrue($validator->fails());
            self::assertTrue($validator->errors()->has('entities.0.opening_scope.reason'));
        }
        $result = app(SaveManualProjectFacts::class)->handle($actor, $session->project_id, $session->id, 2, $request['request_id'], $input);
        self::assertSame('0', $result['cost_units']);
        [$capture, $decisions] = $this->capture($session);
        $facts = array_values(array_filter($capture['snapshot']->facts, static fn ($fact): bool => $fact->type === 'opening_scope'));
        self::assertCount(1, $facts);
        self::assertSame($reason, $facts[0]->value['reason']);
        self::assertCount(1, OpeningEstimateScope::select($capture['snapshot'], $decisions, []));
        self::assertCount($beforeCalls, Http::recorded());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->count());
    }

    public static function openingReasons(): array
    {
        return ['cyrillic' => [str_repeat('я', 500)], 'ascii' => [str_repeat('a', 1000)], 'emoji' => [str_repeat('😀', 250)]];
    }

    #[DataProvider('openingSystems')]
    public function test_product_system_is_a_separate_current_entity_bound_choice(string $system, string $kind): void
    {
        [$actor, $session] = $this->fixture();
        $writer = app(SaveManualProjectFacts::class);
        $scope = $this->scope($session);
        $beforeCalls = count(Http::recorded());
        $question = $this->questions($session)[0];
        $writer->handle($actor, $scope[1], $scope[2], 2, (string) Str::uuid(), $this->answer($question, $kind));
        [$capture, $decisions] = $this->capture($session->fresh());
        self::assertSame([], \App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningSystemScope::select($capture['snapshot'], $decisions, []));
        $backlog = app(NativeParameterBacklog::class)->current($session->fresh(), $capture['snapshot'], $capture['token']);
        $offered = array_values(array_filter($backlog['questions'], static fn (array $row): bool => $row['answer']['kind'] === 'opening_system'));
        self::assertCount(1, $offered);
        $question = $offered[0];
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningSystemScope::choices($kind), array_column($question['input_contract']['choices'], 'value'));
        $input = ['entities' => [['key' => 'system-answer', 'type' => 'opening', 'existing_entity_id' => $question['answer']['entity_id'],
            'native_question' => $question['answer']['native_question'], 'opening_system' => ['system' => $system,
                'entity_fingerprint' => $question['answer']['entity_fingerprint'], 'reason' => 'Изделие выбрано по обозначению и спецификации исходного чертежа.']]]];
        self::assertFalse(Validator::make(['state_version' => 3, 'request_id' => (string) Str::uuid(), ...$input],
            (new SaveManualProjectFactsRequest)->rules())->fails());
        $before = DB::table('estimate_generation_project_model_assertions')->where('session_id', $scope[2])->count();
        foreach (['incompatible', 'fingerprint', 'reason_bytes', 'binding', 'mixed', 'missing_binding', 'extra'] as $variant) {
            $invalid = $input;
            match ($variant) {
                'incompatible' => $invalid['entities'][0]['opening_system']['system'] = $kind === 'window' ? 'timber_hollow_core_door' : 'steel_glazed_window',
                'fingerprint' => $invalid['entities'][0]['opening_system']['entity_fingerprint'] = str_repeat('f', 64),
                'reason_bytes' => $invalid['entities'][0]['opening_system']['reason'] = str_repeat('я', 501),
                'binding' => $invalid['entities'][0]['native_question']['version'] = str_repeat('f', 64),
                'mixed' => $invalid['entities'][0]['parameters'] = ['width' => ['value' => '1', 'unit' => 'm', 'basis' => 'input']],
                'missing_binding' => $invalid['entities'][0]['native_question'] = null,
                'extra' => $invalid['entities'][0]['opening_system']['automatic'] = true,
            };
            try {
                $writer->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid(), $invalid);
                self::fail('Invalid product system was accepted: '.$variant);
            } catch (ValidationException) {
                self::assertSame($before, DB::table('estimate_generation_project_model_assertions')->where('session_id', $scope[2])->count());
                self::assertSame(3, $session->fresh()->state_version);
            }
        }
        $request = (string) Str::uuid();
        $result = $writer->handle($actor, $scope[1], $scope[2], 3, $request, $input);
        self::assertEquals($result, $writer->handle($actor, $scope[1], $scope[2], 3, $request, $input));
        self::assertSame('0', $result['cost_units']);
        [$capture, $decisions] = $this->capture($session->fresh());
        $systems = \App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningSystemScope::select($capture['snapshot'], $decisions, []);
        self::assertCount(1, $systems);
        self::assertSame($system, $systems[$question['answer']['entity_id']]['system']);
        self::assertSame($question['answer']['scope_fact_id'], $systems[$question['answer']['entity_id']]['scope_fact_id']);
        $systemDecision = $systems[$question['answer']['entity_id']]['decision_id'];
        self::assertSame([], \App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningSystemScope::select($capture['snapshot'],
            array_values(array_filter($decisions, static fn ($decision): bool => $decision->id !== $systemDecision)), []));
        $remaining = app(NativeParameterBacklog::class)->current($session->fresh(), $capture['snapshot'], $capture['token']);
        self::assertCount(0, array_filter($remaining['questions'], static fn (array $row): bool => $row['answer']['kind'] === 'opening_system'));
        foreach (['scope_fact_version', 'entity_fingerprint', 'target_source_version', 'decision_id', 'fact_version', 'invalidated'] as $variant) {
            $facts = array_map(static function ($fact) use ($variant) {
                if ($fact->type !== 'opening_system') {
                    return $fact;
                }
                $properties = get_object_vars($fact);
                match ($variant) {
                    'scope_fact_version' => $properties['value']['scope_fact_version']++,
                    'entity_fingerprint' => $properties['value']['entity_fingerprint'] = str_repeat('f', 64),
                    'target_source_version' => $properties['value']['target_source_version'] = 'sha256:'.str_repeat('f', 64),
                    'decision_id' => $properties['value']['decision_id'] = 'decision:foreign',
                    'fact_version' => $properties['version']++,
                    'invalidated' => $properties['status'] = 'invalidated',
                };

                return new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact(...$properties);
            }, $capture['snapshot']->facts);
            $changed = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot(
                $capture['snapshot']->entities, $facts, $capture['snapshot']->evidence, $capture['snapshot']->conflicts);
            self::assertSame([], \App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningSystemScope::select($changed, $decisions, []), $variant);
        }
        self::assertCount($beforeCalls, Http::recorded());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $scope[2])->count());
        self::assertSame(0, DB::table('estimate_generation_credit_operations')->where('session_id', $scope[2])->count());
        self::assertSame(500, $session->fresh()->input_payload['qa_limit_units']);
    }

    public static function openingSystems(): array
    {
        return array_map(static fn (string $kind, string $system): array => [$system, $kind],
            \App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningSystemScope::SYSTEMS,
            array_keys(\App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningSystemScope::SYSTEMS));
    }

    public function test_native_choices_are_source_bound_replayable_and_never_inferred_from_names_or_paid(): void
    {
        [$actor, $session] = $this->fixture();
        $beforeCalls = count(Http::recorded());
        $scope = $this->scope($session);
        [$capture, $decisions] = $this->capture($session);
        self::assertSame([], OpeningEstimateScope::select($capture['snapshot'], $decisions, []));
        $questions = $this->questions($session);
        self::assertCount(2, $questions);
        $input = $this->answer($questions[0], 'door');
        $validator = Validator::make(['state_version' => 2, 'request_id' => (string) Str::uuid(), ...$input], (new SaveManualProjectFactsRequest)->rules());
        self::assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
        $writer = app(SaveManualProjectFacts::class);
        $before = DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count();
        foreach (['kind', 'fingerprint', 'reason', 'binding', 'mixed', 'missing_binding', 'extra_choice_key'] as $variant) {
            $invalid = $input;
            match ($variant) {
                'kind' => $invalid['entities'][0]['opening_scope']['opening_kind'] = 'inferred_from_name',
                'fingerprint' => $invalid['entities'][0]['opening_scope']['entity_fingerprint'] = str_repeat('f', 64),
                'reason' => $invalid['entities'][0]['opening_scope']['reason'] = '',
                'binding' => $invalid['entities'][0]['native_question']['version'] = str_repeat('f', 64),
                'mixed' => $invalid['entities'][0]['parameters'] = ['width' => ['value' => '9', 'unit' => 'm', 'basis' => 'input']],
                'missing_binding' => $invalid['entities'][0]['native_question'] = null,
                'extra_choice_key' => $invalid['entities'][0]['opening_scope']['automatic'] = true,
            };
            try {
                $writer->handle($actor, $scope[1], $scope[2], 2, (string) Str::uuid(), $invalid);
                self::fail('Forged opening choice accepted: '.$variant);
            } catch (ValidationException) {
                self::assertSame($before, DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count());
                self::assertSame(2, $session->fresh()->state_version);
            }
        }
        $request = (string) Str::uuid();
        $result = $writer->handle($actor, $scope[1], $scope[2], 2, $request, $input);
        self::assertSame('0', $result['cost_units']);
        self::assertEquals($result, $writer->handle($actor, $scope[1], $scope[2], 2, $request, $input));
        self::assertCount(1, $this->questions($session->fresh()));
        try {
            $writer->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid(), $input);
            self::fail('Stale opening question accepted.');
        } catch (ValidationException) {
            self::assertSame(3, $session->fresh()->state_version);
        }
        $second = $this->questions($session->fresh())[0];
        $writer->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid(), $this->answer($second, 'window'));
        [$capture, $decisions] = $this->capture($session);
        $selected = OpeningEstimateScope::select($capture['snapshot'], $decisions, []);
        self::assertCount(2, $selected);
        self::assertSame([], OpeningEstimateScope::select($capture['snapshot'], [], []));
        self::assertSame([], $this->questions($session->fresh()));
        $saved = $session->fresh();
        self::assertSame(500, $saved->input_payload['qa_limit_units']);
        self::assertSame('5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', $saved->input_payload['qa_run_id']);
        foreach (['estimate_generation_ai_usage', 'estimate_generation_credit_operations', 'estimate_generation_factory_runs'] as $table) {
            self::assertSame(0, DB::table($table)->where('session_id', $session->id)->count());
        }
        self::assertCount($beforeCalls, Http::recorded());
    }

    public function test_free_recalculation_creates_per_opening_rows_preserves_removals_and_blocks_stale_application(): void
    {
        [$actor, $session] = $this->fixture();
        $beforeCalls = count(Http::recorded());
        $writer = app(SaveManualProjectFacts::class);
        while (($questions = $this->questions($session->fresh())) !== []) {
            $question = $questions[0];
            $kind = str_contains($question['answer']['entity_name'], '813') ? 'door' : 'window';
            $writer->handle($actor, $session->project_id, $session->id, $session->fresh()->state_version, (string) Str::uuid(), $this->answer($question, $kind));
        }
        $draft = $this->recalculate($this->draft(), $actor, $session->fresh());
        try {
            DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$this->scope($session), $draft]));
            self::fail('An opening without a product choice was accepted for application.');
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
        while (true) {
            [$capture] = $this->capture($session->fresh());
            $questions = array_values(array_filter(app(NativeParameterBacklog::class)->current($session->fresh(), $capture['snapshot'], $capture['token'])['questions'],
                static fn (array $question): bool => $question['answer']['kind'] === 'opening_system'));
            if ($questions === []) {
                break;
            }
            $question = $questions[0];
            $writer->handle($actor, $session->project_id, $session->id, $session->fresh()->state_version, (string) Str::uuid(), ['entities' => [[
                'key' => 'product', 'type' => 'opening', 'existing_entity_id' => $question['answer']['entity_id'],
                'native_question' => $question['answer']['native_question'], 'opening_system' => [
                    'system' => $question['answer']['opening_kind'] === 'window' ? 'steel_glazed_window' : 'timber_hollow_core_door',
                    'entity_fingerprint' => $question['answer']['entity_fingerprint'], 'reason' => 'Изделие подтверждено по спецификации исходного чертежа.',
                ],
            ]]]);
        }
        $draft = $this->recalculate($draft, $actor, $session->fresh());
        $rows = $draft['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(3, $rows);
        $byWork = array_column(array_filter($rows, static fn ($row): bool => isset($row['metadata']['composition_work_key'])), null, 'work_key');
        self::assertSame('1.652016', $byWork['openings.doors']['quantity']);
        self::assertSame('0.969878', $byWork['openings.windows']['quantity']);
        self::assertSame('Keep this note', $rows[2]['name']);
        $scope = $this->scope($session);
        DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope, $draft]));
        self::addToAssertionCount(1);
        $proofGuard = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Review\CurrentOpeningSystemProofGuard::class);
        foreach ($byWork as $row) {
            $proofGuard->assertCurrent($session->fresh(), $row);
            $row['metadata']['opening_system']['fact_id'] = 'fact:foreign';
            try {
                $proofGuard->assertCurrent($session->fresh(), $row);
                self::fail('Stale product proof reached normative selection.');
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
        $matcher = new class extends \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher
        {
            public array $seen = [];

            public function __construct() {}

            public function searchWorkItemCandidates(array $workItem, array $context = [], int $limit = 10): ?array
            {
                $this->seen[] = $workItem;

                return ['candidates' => [
                    ['norm_id' => 901, 'name' => 'Установка стальных остеклённых оконных блоков', 'unit' => 'm2'],
                    ['norm_id' => 902, 'name' => 'Установка оконных блоков из поливинилхлоридных профилей', 'unit' => 'm2'],
                    ['norm_id' => 903, 'name' => 'Установка оконных блоков', 'unit' => 'm2'],
                ]];
            }
        };
        $draft['source_input_version'] = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh());
        $session->refresh()->update(['draft_payload' => app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($draft))]);
        $search = new \App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateManualSearchService(
            $matcher, new \App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter);
        foreach (['', 'ПВХ', '09-01-001-01'] as $query) {
            $result = $search->search($session->fresh(), $byWork['openings.windows']['key'], $query);
            self::assertSame([901], array_column($result['candidates'], 'norm_id'));
            self::assertStringStartsWith('Установка стальных остеклённых оконных блоков', $matcher->seen[array_key_last($matcher->seen)]['normative_search_text']);
            self::assertSame('Установка стальных остеклённых оконных блоков'.($query === '' ? '' : ' '.$query),
                $matcher->seen[array_key_last($matcher->seen)]['description']);
        }
        self::assertEquals($draft, $this->recalculate($draft, $actor, $session->fresh()));
        $deleted = $draft;
        array_shift($deleted['local_estimates'][0]['sections'][0]['work_items']);
        $retained = $this->recalculate($deleted, $actor, $session->fresh());
        self::assertCount(2, $retained['local_estimates'][0]['sections'][0]['work_items']);
        $window = $byWork['openings.windows']['entity_id'];
        $writer->handle($actor, $scope[1], $scope[2], $session->fresh()->state_version, (string) Str::uuid(), ['entities' => [[
            'key' => 'change', 'type' => 'opening', 'existing_entity_id' => $window,
            'parameters' => ['width' => ['value' => '1.2', 'unit' => 'm', 'basis' => 'measurement']],
        ]]]);
        try {
            DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope, $draft]));
            self::fail('Stale opening area accepted for application.');
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
        $updated = $this->recalculate($draft, $actor, $session->fresh());
        $updatedRows = array_column($updated['local_estimates'][0]['sections'][0]['work_items'], null, 'key');
        self::assertSame('1.1388', $updatedRows[$byWork['openings.windows']['key']]['quantity']);
        self::assertCount($beforeCalls, Http::recorded());
    }

    private function fixture(): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        Http::preventStrayRequests();
        Http::fake();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0,
            'input_payload' => ['evaluation_mode' => 'universal', 'selected_sections' => ['structures'], 'price_policy' => 'normative',
                'qa_run_id' => '5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', 'qa_limit_units' => 500]]);
        $writer = app(SaveManualProjectFacts::class);
        $entities = [['key' => 'wall', 'type' => 'wall', 'parameters' => ['length' => ['value' => '5', 'unit' => 'm', 'basis' => 'measurement'],
            'height' => ['value' => '3', 'unit' => 'm', 'basis' => 'measurement']]]];
        foreach (['door' => ['0.813', '2.032'], 'window' => ['1.022', '0.949']] as $key => [$width, $height]) {
            $entities[] = ['key' => $key, 'type' => 'opening', 'name' => 'Object '.$width, 'parent_key' => 'wall',
                'parameters' => ['width' => ['value' => $width, 'unit' => 'm', 'basis' => 'measurement'],
                    'height' => ['value' => $height, 'unit' => 'm', 'basis' => 'measurement']]];
        }
        $writer->handle($actor, $project->id, $session->id, 0, (string) Str::uuid(), ['entities' => $entities]);
        [$capture] = $this->capture($session);
        $wall = array_values(array_filter($capture['snapshot']->entities, static fn ($entity): bool => GeometryInventory::type($entity) === 'wall'))[0];
        $inventory = GeometryInventory::capture($capture['snapshot'], $wall->id, 'wall_openings', []);
        $writer->handle($actor, $project->id, $session->id, 1, (string) Str::uuid(), ['entities' => [[
            'key' => 'coverage', 'type' => 'wall', 'existing_entity_id' => $wall->id,
            'coverage' => ['wall_openings' => 'covered_with_entities'], 'coverage_fingerprints' => ['wall_openings' => $inventory['fingerprint']],
        ]]]);
        $draft = $this->draft();
        $draft['source_input_version'] = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh());
        $session->refresh()->update(['draft_payload' => app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($draft))]);

        return [$actor, $session->fresh()];
    }

    private function scope(EstimateGenerationSession $session): array
    {
        return [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
    }

    private function capture(EstimateGenerationSession $session): array
    {
        $models = app(ProjectModelRepository::class);
        $scope = $this->scope($session);
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);

        return [$capture, $models->decisionsForSelectedFacts(...[...$scope, array_column($capture['snapshot']->facts, 'id')])];
    }

    private function questions(EstimateGenerationSession $session): array
    {
        [$capture] = $this->capture($session);

        return array_values(array_filter(app(NativeParameterBacklog::class)->current($session, $capture['snapshot'], $capture['token'])['questions'],
            static fn ($question): bool => $question['answer']['kind'] === 'opening_scope'));
    }

    private function answer(array $question, string $kind): array
    {
        return ['entities' => [['key' => 'answer', 'type' => 'opening', 'existing_entity_id' => $question['answer']['entity_id'],
            'native_question' => $question['answer']['native_question'], 'opening_scope' => ['opening_kind' => $kind,
                'entity_fingerprint' => $question['answer']['entity_fingerprint'], 'reason' => 'Тип изделия принят по условному обозначению на чертеже']]]];
    }

    private function recalculate(array $draft, User $actor, EstimateGenerationSession $session): array
    {
        return DB::transaction(function () use ($draft, $actor, $session): array {
            [$capture, $decisions] = $this->capture($session);
            $scope = $this->scope($session);
            $version = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session);
            $context = new PipelineContext($scope[2], $scope[0], $scope[1], (int) $session->state_version, $version,
                $session->status->value, baseInputVersion: $version);

            return app(EvaluationDraftArithmeticRecalculator::class)->recalculate($draft, $context, $capture['snapshot'], $decisions, $actor, $session);
        });
    }

    private function draft(): array
    {
        $rows = array_map(static fn ($work): array => ['key' => $work, 'work_key' => $work, 'name' => $work, 'item_type' => 'priced_work',
            'quantity' => null, 'unit' => 'm2', 'metadata' => ['composition_work_key' => $work, 'quantity_key' => $work,
                'quantity_source' => 'unknown', 'generation_source' => 'qualitative_work_catalog']], OpeningWorkItemExpander::WORKS);
        $rows[] = ['key' => 'note', 'name' => 'Keep this note', 'item_type' => 'review_note'];

        return ['generation_contract' => UniversalDraftProjector::CONTRACT, 'evaluation_policy' => ['price_policy' => 'normative'],
            'local_estimates' => [['key' => 'structures', 'sections' => [['key' => 'openings', 'work_items' => $rows]]]]];
    }
}
