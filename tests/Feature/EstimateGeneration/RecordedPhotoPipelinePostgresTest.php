<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverProfile;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\RecordedUniversalPipelineAdapter;

final class RecordedPhotoPipelinePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_actual_photo_upload_quote_vision_publication_and_generation_preserve_unknown_sizes(): void
    {
        $this->replayPhoto('photo-no-scale-v1.json', 1);
    }

    public function test_photo_without_a_description_uses_only_the_selected_scope_and_never_fabricates_measurements_or_a_description_fact(): void
    {
        $this->replayPhoto('photo-only-empty-context-v1.json', 0);
    }

    public function test_explicit_simple_context_photo_runs_one_observer_and_preserves_unknowns_and_replay_accounting(): void
    {
        $this->replayPhoto('photo-simple-context-v1.json', 0, 1);
    }

    public function test_user_entered_floor_area_is_calculated_and_wall_assumptions_are_not_reported_as_measurements_or_prices(): void
    {
        $this->replayPhoto('photo-user-area-v1.json', 0, 1, '20');
    }

    public function test_successful_document_cost_is_charged_once_with_the_useful_revision(): void
    {
        $run = $this->replayPhoto('photo-document-cost-v1.json', 0, 1, '20', 150);
        $operation = DB::table('estimate_generation_credit_operations')->where('session_id', $run['session_id'])->where('kind', 'generation')->sole();
        $receipt = json_decode($operation->receipt, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(249750, $receipt['carried_document_cost_micro_rub']);
        self::assertSame(9450, $receipt['own_supplier_cost_micro_rub']);
        self::assertSame(259200, $receipt['measured_supplier_cost_micro_rub']);
        self::assertSame(150, $receipt['consumed_units_minor']);
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(249750, $plan['document_costs']['cost_micro_rub']);
        self::assertSame(array_sum(array_map(static fn ($role): int => $role['max_calls'] * $role['maximum_cost_micro_rub'], $plan['roles'])) + 249750,
            $plan['maximum_provider_cost_micro_rub']);
        $allocation = DB::table('ai_credit_provider_cost_allocations')->where('session_id', $run['session_id'])->sole();
        self::assertSame(249750, (int) $allocation->cost_micro_rub);
        $documentOperation = DB::table('estimate_generation_credit_operations')->where('session_id', $run['session_id'])->where('kind', 'documents')->sole();
        self::assertSame((int) $documentOperation->reservation_id, (int) DB::table('ai_credit_provider_usages')->where('id', $allocation->source_provider_usage_id)->value('ai_credit_reservation_id'));
        $session = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()->findOrFail($run['session_id']);
        $costs = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCostAllocation::class);
        self::assertSame([], $costs->capture($session)['items']);
        self::assertSame(1, DB::table('ai_credit_provider_cost_allocations')->where('session_id', $run['session_id'])->count());
        self::assertSame(1, DB::table('ai_credit_ledger_entries')->where('organization_id', $session->organization_id)->where('type', 'consume')->where('amount_minor', '<', 0)->count());
        $limits = [config('estimate-generation.credits.service_daily_expense_limit_rub'), config('estimate-generation.credits.service_monthly_expense_limit_rub')];
        try {
            config(['estimate-generation.credits.service_daily_expense_limit_rub' => '0.000001', 'estimate-generation.credits.service_monthly_expense_limit_rub' => '0.000001']);
            DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorServiceExpenseGuard::class)->admit((int) $session->organization_id, 1));
        } finally {
            config(['estimate-generation.credits.service_daily_expense_limit_rub' => $limits[0], 'estimate-generation.credits.service_monthly_expense_limit_rub' => $limits[1]]);
        }
        try {
            DB::transaction(fn () => DB::table('ai_credit_provider_cost_allocations')->where('source_provider_usage_id', $allocation->source_provider_usage_id)->update(['cost_micro_rub' => 1]));
            self::fail('A paid allocation changed its historical cost.');
        } catch (\Illuminate\Database\QueryException $error) {
            self::assertStringContainsString('document_cost_allocation_immutable', $error->getMessage());
        }
        self::assertSame(249750, (int) DB::table('ai_credit_provider_cost_allocations')->where('source_provider_usage_id', $allocation->source_provider_usage_id)->value('cost_micro_rub'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('allocationQaRuns')]
    public function test_qa_counts_allocated_document_cost_once_and_preserves_its_original_run(bool $sameRun): void
    {
        $sourceRun = (string) \Illuminate\Support\Str::uuid();
        $targetRun = $sameRun ? $sourceRun : (string) \Illuminate\Support\Str::uuid();
        config(['estimate-generation.live_qa.enabled' => true, 'estimate-generation.live_qa.run_id' => $sourceRun]);
        $run = $this->replayPhoto('photo-document-cost-v1.json', 0, 1, '20', 150,
            static function () use ($targetRun): void {
                config(['estimate-generation.live_qa.run_id' => $targetRun]);
            });
        $guard = app(\App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorQaSupplierExpenseGuard::class);
        $operation = DB::table('estimate_generation_credit_operations')->where('session_id', $run['session_id'])->where('kind', 'generation')->sole();
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame($sameRun ? 249750 : 0, $plan['qa_expense_budget']['carried_document_cost_micro_rub']);
        self::assertSame($plan['qa_expense_budget']['maximum_provider_cost_micro_rub'] + ($sameRun ? 249750 : 0), $plan['maximum_provider_cost_micro_rub']);
        self::assertSame(50000, (int) DB::table('ai_credit_reservations')->where('id', $operation->reservation_id)->value('reserved_minor'));
        self::assertSame(1, DB::table('ai_credit_provider_cost_allocations')->where('session_id', $run['session_id'])->count());
        self::assertSame(49850, DB::transaction(fn () => $guard->quoteBudget())['remaining_units_minor']);
        config(['estimate-generation.live_qa.run_id' => $sourceRun]);
        self::assertSame(49850, DB::transaction(fn () => $guard->quoteBudget())['remaining_units_minor']);
        self::assertSame($sameRun ? 3 : 1, DB::table('estimate_generation_qa_expense_attempts')->where('run_id', $sourceRun)->count());
        $session = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()->findOrFail($run['session_id']);
        $attempt = (string) \Illuminate\Support\Str::uuid();
        DB::table('estimate_generation_vision_physical_attempts')->insert(['attempt_id' => $attempt,
            'request_fingerprint' => hash('sha256', $attempt), 'logical_request_fingerprint' => hash('sha256', $attempt),
            'organization_id' => $session->organization_id, 'project_id' => $session->project_id, 'session_id' => $session->id,
            'state' => 'pre_wire', 'owner_token' => $attempt, 'lease_expires_at' => now()->addMinutes(5),
            'usage_recorded' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => $guard->admit($attempt,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost('89.73000000', 'RUB', 'available')));
        self::assertSame($sameRun ? 4 : 2, DB::table('estimate_generation_qa_expense_attempts')->where('run_id', $sourceRun)->count());
    }

    public static function allocationQaRuns(): array
    {
        return ['one run' => [true], 'different runs keep historical attribution' => [false]];
    }

    public function test_information_only_result_leaves_document_cost_available_without_allocating_or_charging(): void
    {
        $run = $this->replayPhoto('photo-document-cost-info-v1.json', 0, 1);
        $session = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()->findOrFail($run['session_id']);
        $carry = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCostAllocation::class)->capture($session);
        self::assertSame(249750, $carry['cost_micro_rub']);
        self::assertCount(1, $carry['items']);
        self::assertSame(0, DB::table('ai_credit_provider_cost_allocations')->where('session_id', $session->id)->count());
    }

    public function test_a_saved_quantitative_photo_gets_a_new_quote_without_resending_models_or_rebilling_documents(): void
    {
        $run = $this->replayPhoto('photo-document-cost-v1.json', 0, 1, '20', 150);
        $session = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()->findOrFail($run['session_id']);
        $saved = $session->draft_payload;
        $actor = \App\Models\User::query()->findOrFail($session->user_id);
        $id = (string) \Illuminate\Support\Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, (int) $session->state_version, $id, 'generation');
        self::assertSame($quote, $operations->quote($actor, (int) $session->project_id, (int) $session->id, (int) $session->state_version, $id, 'generation'));
        $plan = json_decode(DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('processing_plan'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('business:v1', $plan['text_payload_policy']['audit_draft_view']);
        self::assertSame(hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($saved)), $plan['known_context']['saved_draft_hash']);
        self::assertSame(0, $plan['document_costs']['cost_micro_rub']);
        self::assertSame($saved, $session->fresh()->draft_payload);
        self::assertSame(3, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(1, DB::table('ai_credit_provider_cost_allocations')->where('session_id', $session->id)->count());
        self::assertSame(1, DB::table('ai_credit_ledger_entries')->where('organization_id', $session->organization_id)->where('type', 'consume')->where('amount_minor', '<', 0)->count());
        self::assertSame('quoted', DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('status'));
        DB::transaction(fn () => $operations->begin($actor, $session, (int) $session->state_version, $id, $quote['quote_id'], 'generation'));
        try {
            $input = app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory::class)->capture(
                (int) $session->organization_id, (int) $session->project_id, (int) $session->id, $saved,
                new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope((int) $session->state_version, null, $id));
            self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditDraftView::project($saved, 'business:v1'), $input->draft);
            self::assertArrayNotHasKey('evaluation_result', $input->draft);
            self::assertArrayNotHasKey('quality_summary', $input->draft);
            self::assertNotEmpty($input->facts);
            self::assertNotEmpty($input->derivedQuantities);
            self::assertNotEmpty($input->evidence);
            self::assertSame($saved, $session->fresh()->draft_payload);
            self::assertSame(3, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        } finally {
            DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
                ->abort($session->fresh(), $id, 'fixture_finished'));
        }
        $organization = \App\Models\Organization::query()->findOrFail($session->organization_id);
        self::assertSame(1000000 - 150, app(\App\Services\Credits\AICreditService::class)->balance($organization)['available_minor']);
    }

    public function test_allocation_rolls_back_and_cannot_commit_without_a_paid_published_result(): void
    {
        $run = $this->replayPhoto('photo-document-cost-info-v1.json', 0, 1);
        $session = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()->findOrFail($run['session_id']);
        $actor = \App\Models\User::query()->findOrFail($session->user_id);
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $id = (string) \Illuminate\Support\Str::uuid();
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, (int) $session->state_version, $id, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, (int) $session->state_version, $id, $quote['quote_id'], 'generation'));
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        $reservation = \App\Models\Credits\AICreditReservation::query()->findOrFail($operation->reservation_id);
        $allocate = fn () => app(\App\Services\Credits\AICreditService::class)->allocateEstimatorDocumentCost($reservation, $id,
            $plan['document_costs']['items'][0], null, str_repeat('a', 64));
        try {
            DB::transaction(function () use ($allocate): void {
                $allocate();
                throw new \RuntimeException('allocation_publication_rollback');
            });
            self::fail('Allocation survived publication rollback.');
        } catch (\RuntimeException $error) {
            self::assertSame('allocation_publication_rollback', $error->getMessage());
        }
        try {
            DB::transaction(function () use ($allocate): void {
                $allocate();
                DB::statement('SET CONSTRAINTS eg_document_cost_allocation_publication_trg IMMEDIATE');
            });
            self::fail('An unpaid, unpublished allocation committed.');
        } catch (\Illuminate\Database\QueryException $error) {
            self::assertStringContainsString('document_cost_allocation_unpublished', $error->getMessage());
        }
        self::assertSame(0, DB::table('ai_credit_provider_cost_allocations')->where('session_id', $session->id)->count());
        self::assertSame(0, (int) $reservation->fresh()->consumed_minor);
        self::assertSame(249750, app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCostAllocation::class)->capture($session)['cost_micro_rub']);
        $secondId = (string) \Illuminate\Support\Str::uuid();
        $secondQuote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, (int) $session->state_version, $secondId, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, (int) $session->state_version, $secondId, $secondQuote['quote_id'], 'generation'));
        $secondReservation = \App\Models\Credits\AICreditReservation::query()->findOrFail(DB::table('estimate_generation_credit_operations')->where('operation_id', $secondId)->value('reservation_id'));
        try {
            DB::transaction(function () use ($allocate, $secondReservation, $secondId, $plan): void {
                $allocate();
                app(\App\Services\Credits\AICreditService::class)->allocateEstimatorDocumentCost($secondReservation, $secondId,
                    $plan['document_costs']['items'][0], null, str_repeat('b', 64));
            });
            self::fail('Two approved consumers allocated the same physical source.');
        } catch (\Illuminate\Database\QueryException $error) {
            self::assertSame('23505', $error->errorInfo[0]);
        }
        self::assertSame(0, DB::table('ai_credit_provider_cost_allocations')->where('session_id', $session->id)->count());
        self::assertSame(0, (int) $secondReservation->fresh()->consumed_minor);
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)->abort($session, $secondId));
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)->abort($session, $id));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedSourcePins')]
    public function test_a_changed_source_pin_rejects_the_frozen_cost_without_allocation(string $change): void
    {
        $run = $this->replayPhoto('photo-document-cost-info-v1.json', 0, 1);
        $session = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()->findOrFail($run['session_id']);
        $costs = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCostAllocation::class);
        $frozen = ['document_costs' => $costs->capture($session)];
        $pin = $frozen['document_costs']['items'][0];
        try {
            DB::transaction(function () use ($change, $pin, $costs, $session, $frozen): void {
                if ($change === 'excluded_page') {
                    DB::table('estimate_generation_document_pages')->where('id', $pin['page_id'])->update(['status' => 'excluded', 'excluded_at' => now()]);
                } else {
                    DB::table('estimate_generation_processing_units')->where('id', $pin['unit_id'])
                        ->update(['metadata' => DB::raw("metadata || jsonb_build_object('processing_attempt_id', '".(string) \Illuminate\Support\Str::uuid()."')")]);
                }
                $costs->validate($session, $frozen);
                self::fail('Changed source pins kept their approved cost.');
            });
        } catch (\DomainException $error) {
            self::assertSame('estimator_credit_document_costs_changed', $error->getMessage());
        }
        self::assertSame($frozen['document_costs'], $costs->capture($session));
        self::assertSame(0, DB::table('ai_credit_provider_cost_allocations')->where('session_id', $session->id)->count());
    }

    public static function changedSourcePins(): array
    {
        return [['excluded_page'], ['processing_lineage']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalDocumentOperations')]
    public function test_successful_document_usage_from_a_failed_or_cancelled_operation_is_never_carried(string $reason): void
    {
        $sessionId = null;
        $stop = new \RuntimeException('terminal_document_source_checked');
        $completionType = \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\CompleteEstimatorDocumentOperation::class;
        $originalCompletion = $this->app->make($completionType);
        $deferredCompletion = new class
        {
            public int $calls = 0;

            public function handle($session): bool
            {
                $this->calls++;

                return true;
            }
        };
        // Pause only automatic settlement. Real unit processing, authorization, HTTP and usage still run.
        $this->app->instance($completionType, $deferredCompletion);
        try {
            $this->replayPhoto('photo-document-cost-info-v1.json', 0, 1, afterDocumentUnits: function ($session, string $id) use ($reason, &$sessionId, $stop, $deferredCompletion): void {
                $sessionId = (int) $session->id;
                self::assertGreaterThan(0, $deferredCompletion->calls);
                DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)->abort($session, $id, $reason));
                self::assertSame($reason, DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('status'));
                self::assertSame(1, DB::table('ai_credit_provider_usages')->where('usage_key', DB::table('estimate_generation_credit_attempts')->where('operation_id', $id)->value('attempt_id'))->where('is_successful', true)->count());
                self::assertSame(['contract' => 'estimator-document-costs:v1', 'items' => [], 'cost_micro_rub' => 0],
                    app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCostAllocation::class)->capture($session));
                throw $stop;
            });
            self::fail('A terminal source continued to generation.');
        } catch (\RuntimeException $error) {
            if ($error !== $stop) {
                throw $error;
            }
        } finally {
            $this->app->instance($completionType, $originalCompletion);
        }
        self::assertNotNull($sessionId);
        self::assertSame(0, DB::table('ai_credit_provider_cost_allocations')->where('session_id', $sessionId)->count());
        self::assertSame(0, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $sessionId)->count());
    }

    public static function terminalDocumentOperations(): array
    {
        return [['failed'], ['cancelled']];
    }

    private function replayPhoto(string $recording, int $descriptionFacts, ?int $expectedVisionCalls = null, ?string $expectedArea = null, ?int $expectedChargeOverride = null, ?\Closure $afterDocumentUnits = null): array
    {
        $image = imagecreatetruecolor(32, 24);
        imagefill($image, 0, 0, imagecolorallocate($image, 235, 235, 235));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $objects = new class($png) implements BenchmarkObjectReader
        {
            public function __construct(private readonly string $png) {}

            public function read(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData|BenchmarkPredictionCaseData $case, string $role, int $maxBytes): string
            {
                if ($role !== 'input') {
                    throw new \LogicException('Prediction cannot read expected.');
                }

                return $this->png;
            }
        };
        $fixture = base_path('tests/Fixtures/EstimateGeneration/universal-replay/'.$recording);
        $adapter = new RecordedUniversalPipelineAdapter($this->app, $objects, $fixture, hash_file('sha256', $fixture), $afterDocumentUnits);
        $result = $adapter->run(new BenchmarkPredictionCaseData('photo-no-scale', BenchmarkDatasetType::Regression, BenchmarkSourceType::SitePhoto,
            'room.png', hash('sha256', $png), ['photo_no_scale', 'current_repair'], []), 300000);
        self::assertSame('success', $result->status);
        self::assertSame($descriptionFacts, $adapter->lastRun['description_fact_count']);
        if ($descriptionFacts === 0) {
            self::assertNotContains('painting-from-description', $result->prediction['work_ids']);
            self::assertNotEmpty($result->prediction['work_ids']);
            self::assertFalse($adapter->lastRun['evaluation']['can_confirm_for_apply']);
        }
        if ($expectedArea === null) {
            self::assertSame([], $result->prediction['areas']);
            self::assertSame([], $result->prediction['quantities']);
        } else {
            self::assertCount(1, $result->prediction['areas']);
            foreach ($result->prediction['areas'] as $area) {
                self::assertTrue(\Brick\Math\BigDecimal::of($area)->isEqualTo($expectedArea));
            }
            $positions = $adapter->lastRun['evaluation']['scenarios'][0]['positions'];
            $supported = array_values(array_filter($positions, static fn ($position): bool => $position['quantity_status'] === 'supported'));
            self::assertNotEmpty($supported);
            foreach ($supported as $position) {
                self::assertTrue(\Brick\Math\BigDecimal::of($position['quantity'])->isEqualTo($expectedArea));
                self::assertSame('evidenced', $position['quantity_basis']['source']);
                self::assertSame([], $position['quantity_basis']['assumptions']);
            }
            $assumed = array_values(array_filter($positions, static fn ($position): bool => $position['quantity_status'] === 'assumed'));
            self::assertNotEmpty($assumed);
            foreach ($assumed as $position) {
                self::assertSame('estimated', $position['quantity_basis']['source']);
                self::assertNotEmpty($position['quantity_basis']['assumptions']);
                self::assertSame('preliminary_quantity_coefficients:v1', $position['quantity_basis']['formula_inputs']['scenario_id']);
            }
            self::assertSame(['area'], array_column($adapter->lastRun['confirmed_numeric_facts'], 'type'));
            self::assertSame('scenario_estimate', $adapter->lastRun['evaluation']['result_class']);
            self::assertNotEmpty($adapter->lastRun['evaluation']['scenarios'][0]['assumptions']);
        }
        self::assertSame([], $result->prediction['costs']);
        $run = $adapter->lastRun['document_run'];
        self::assertSame(['completed'], $run['units']);
        self::assertSame($expectedVisionCalls ?? count(ObserverProfile::cases()), $run['vision_requests']);
        self::assertSame(array_fill(0, $run['vision_requests'], true), $run['provider_ownership_preserved']);
        self::assertSame($run['vision_requests'], $run['vision_requests_after_replay']);
        self::assertSame(0, $run['receipt']['consumed_units_minor']);
        $expectedCharge = $expectedChargeOverride ?? ($expectedArea === null ? 0 : 50);
        self::assertSame($expectedCharge, $adapter->lastRun['consumed_units_minor']);
        self::assertSame(1000000 - $expectedCharge, $adapter->lastRun['available_minor']);
        self::assertNotEmpty($adapter->lastRun['evaluation']['priority_questions']);
        self::assertFalse($adapter->lastRun['evaluation']['accuracy_calibrated']);
        self::assertSame($run['vision_requests'] + 2, count($adapter->lastRun['usage']));
        self::assertSame(count($adapter->lastRun['usage']), $adapter->lastRun['usage_after_replay']);
        self::assertGreaterThan(0, $run['receipt']['measured_supplier_cost_micro_rub']);
        self::assertSame(2, DB::table('estimate_generation_credit_operations')->where('session_id', $adapter->lastRun['session_id'])->count());

        return $adapter->lastRun;
    }
}
