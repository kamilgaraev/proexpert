<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentAiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorCreditAttemptAdmission;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\CommercialOrder;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditCommercialService;
use App\Services\Credits\AICreditService;
use App\Services\Credits\EstimatorCreditRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EstimatorCreditWalletPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_a_full_generation_quote_publishes_the_frozen_cycle_and_replays_it_without_reserving(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        [$organization, $actor, $scope] = $this->fixture();
        config(['estimate-generation.live_qa.enabled' => false]);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $id = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        self::assertFalse($this->app->resolved(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\TimewebEstimateComposerModel::class));
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        self::assertFalse($this->app->resolved(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\TimewebEstimateComposerModel::class),
            'A new-generation quote must not eagerly construct a retained audit model.');
        self::assertSame($quote, $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation'));
        $row = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        $plan = json_decode($row->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        $cycle = $quote['provider_budget']['generation_cycle'];
        self::assertSame(CanonicalPipelineJson::encode($plan['generation_cycle_preflight']), CanonicalPipelineJson::encode($cycle));
        self::assertTrue($cycle['full_cycle_fits']);
        self::assertSame($quote['maximum_calls'], $cycle['maximum_calls']);
        self::assertSame((int) $row->maximum_provider_cost_micro_rub, $cycle['maximum_total_provider_cost_micro_rub']);
        self::assertSame($plan['maximum_provider_cost_micro_rub'], $cycle['maximum_remaining_cycle_cost_micro_rub']);
        self::assertEqualsCanonicalizing(array_keys($plan['roles']), array_keys($cycle['role_budgets']));
        self::assertTrue($cycle['retries_included']);
        self::assertFalse($cycle['completion_guaranteed']);
        self::assertArrayNotHasKey('remaining_units_minor', $cycle);
        self::assertSame(0, app(AICreditService::class)->balance($organization)['reserved_minor']);
        self::assertSame(1, DB::table('ai_credit_quotes')->where('organization_id', $scope->organizationId)->count());
        self::assertSame(1, DB::table('estimate_generation_credit_operations')->where('organization_id', $scope->organizationId)->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $scope->organizationId)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('organization_id', $scope->organizationId)->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_an_old_partial_generation_quote_cannot_replay_or_begin_and_remains_immutable(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        [$organization, $actor, $scope] = $this->fixture();
        config(['estimate-generation.live_qa.enabled' => false]);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $sourceId = (string) Str::uuid();
        $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $sourceId, 'generation');
        $source = DB::table('estimate_generation_credit_operations')->where('operation_id', $sourceId)->sole();
        $plan = json_decode($source->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        unset($plan['generation_cycle_preflight']);
        $plan['maximum_provider_cost_micro_rub'] = 1080000;
        $planHash = hash('sha256', CanonicalPipelineJson::encode($plan));
        $id = (string) Str::uuid();
        $tariff = json_decode($source->credit_request, true, 64, JSON_THROW_ON_ERROR)['supplier_tariff'];
        $request = new EstimatorCreditRequest($scope->organizationId, $scope->projectId, $scope->sessionId, (int) $actor->id,
            $id, 'generation', 0, $source->input_hash, $source->source_version, $source->manifest_hash, $planHash,
            1080000, (int) $source->maximum_calls, $tariff);
        $credits = app(AICreditService::class);
        $quote = $credits->quoteEstimator($organization, $actor, $request);
        $quoteId = DB::table('ai_credit_quotes')->where('public_id', $quote['quote_id'])->value('id');
        $legacy = (array) $source;
        $legacy['operation_id'] = $id;
        $legacy['quote_id'] = $quoteId;
        $legacy['processing_plan'] = CanonicalPipelineJson::encode($plan);
        $legacy['plan_hash'] = $planHash;
        $legacy['credit_request'] = CanonicalPipelineJson::encode($request->identity());
        $legacy['maximum_provider_cost_micro_rub'] = 1080000;
        DB::table('estimate_generation_credit_operations')->insert($legacy);
        $before = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        $quotesBefore = DB::table('ai_credit_quotes')->where('organization_id', $scope->organizationId)->get()->all();
        $balance = $credits->balance($organization);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        foreach (['replay', 'begin'] as $action) {
            try {
                if ($action === 'replay') {
                    $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
                } else {
                    DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation'));
                }
                self::fail('An old partial generation quote passed '.$action);
            } catch (\Illuminate\Validation\ValidationException $error) {
                self::assertSame([trans_message('estimate_generation.generation_cycle_budget_insufficient')],
                    $error->errors()[$action === 'replay' ? 'quote' : 'quote_id']);
            }
        }
        self::assertEquals($before, DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole());
        self::assertEquals($quotesBefore, DB::table('ai_credit_quotes')->where('organization_id', $scope->organizationId)->get()->all());
        self::assertSame($balance, $credits->balance($organization));
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $scope->organizationId)->count());
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('organization_id', $scope->organizationId)->count());
        self::assertSame(0, $session->fresh()->state_version);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_a_new_generation_quote_rejects_a_partial_qa_envelope_before_creating_a_quote_or_reserve(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $runId = (string) Str::uuid();
        config(['estimate-generation.live_qa.enabled' => true, 'estimate-generation.live_qa.run_id' => $runId]);
        $prior = (string) Str::uuid();
        DB::table('estimate_generation_vision_physical_attempts')->insert(['attempt_id' => $prior,
            'request_fingerprint' => hash('sha256', $prior), 'logical_request_fingerprint' => hash('sha256', $prior),
            'organization_id' => $scope->organizationId, 'project_id' => $scope->projectId, 'session_id' => $scope->sessionId,
            'state' => 'pre_wire', 'owner_token' => $prior, 'lease_expires_at' => now()->addMinutes(5),
            'usage_recorded' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorQaSupplierExpenseGuard::class)
            ->admit($prior, new AiCost('88.20000000', 'RUB', 'available')));
        $runBefore = DB::table('estimate_generation_qa_expense_runs')->where('run_id', $runId)->sole();
        $credits = app(AICreditService::class);
        $credits->grant($organization, 1000000, 'purchase', null, 'test:full-cycle-insufficient');
        $balance = $credits->balance($organization);
        $quotes = DB::table('ai_credit_quotes')->where('organization_id', $scope->organizationId)->count();
        try {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
                ->quote($actor, $scope->projectId, $scope->sessionId, 0, (string) Str::uuid(), 'generation');
            self::fail('A partial QA allowance was offered as a full generation quote.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            self::assertSame([trans_message('estimate_generation.generation_cycle_budget_insufficient')], $error->errors()['quote']);
        }
        self::assertSame($quotes, DB::table('ai_credit_quotes')->where('organization_id', $scope->organizationId)->count());
        self::assertSame(0, DB::table('estimate_generation_credit_operations')->where('organization_id', $scope->organizationId)->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $scope->organizationId)->count());
        self::assertSame($balance, $credits->balance($organization));
        self::assertEquals($runBefore, DB::table('estimate_generation_qa_expense_runs')->where('run_id', $runId)->sole());
        self::assertSame(1, DB::table('estimate_generation_qa_expense_attempts')->where('run_id', $runId)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('organization_id', $scope->organizationId)->count());
        self::assertSame(0, EstimateGenerationSession::query()->findOrFail($scope->sessionId)->state_version);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    #[DataProvider('paidBillingTerms')]
    public function test_estimator_quotes_reconcile_monthly_paid_term_grants_and_reserve_the_shared_wallet(int $months): void
    {
        $start = CarbonImmutable::parse('2026-10-31 09:00:00', 'UTC');
        $this->travelTo($start);
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $account = OrganizationCommercialAccount::query()->create(['organization_id' => $organization->id, 'status' => 'active',
            'quote_version' => 1, 'billing_period' => $months.'_months', 'current_period_start_at' => $start,
            'current_period_end_at' => $start->addMonthsNoOverflow($months)]);
        $order = $this->paidTermOrder($organization, $actor, $account, $start, $start->addMonthsNoOverflow($months), $months.'_months');
        $this->paidTermSubscription($organization, $account, $order);
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $credits = app(AICreditService::class);

        for ($month = 0; $month < 3; $month++) {
            $this->travelTo($start->addMonthsNoOverflow($month));
            $requestId = (string) Str::uuid();
            $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $requestId, 'generation');
            self::assertSame($quote, $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $requestId, 'generation'));
            self::assertSame(500000, $quote['available_units_minor']);
            self::assertSame(500000, $credits->balance($organization)['included_minor']);
            self::assertSame($month + 1, DB::table('ai_credit_ledger_entries')->where('organization_id', $organization->id)->where('type', 'grant')->count());
            $lot = \App\Models\Credits\AICreditLot::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
            self::assertSame($order->id, $lot->commercial_order_id);
            self::assertTrue($start->addMonthsNoOverflow($month + 1)->equalTo($lot->expires_at));
        }

        $credits->grant($organization, 1000000, 'purchase', null, 'test:paid-term-shared-reserve');
        $requestId = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $requestId, 'generation');
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        self::assertSame($requestId, DB::transaction(fn () => $operations->begin($actor, $session, 0, $requestId, $quote['quote_id'], 'generation')));
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $requestId)->sole();
        $reservation = \App\Models\Credits\AICreditReservation::query()->findOrFail($operation->reservation_id);
        self::assertGreaterThan(0, $reservation->reserved_minor);
        self::assertSame($quote['max_units_minor'], $reservation->reserved_minor);
        self::assertSame(1500000 - $reservation->reserved_minor, $credits->balance($organization)['available_minor']);
        self::assertSame(1, DB::table('ai_credit_wallets')->where('organization_id', $organization->id)->count());
        $credits->cancel($reservation);
        self::assertSame(1500000, $credits->balance($organization)['available_minor']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $scope->sessionId)->count());
        $this->travelBack();
    }

    public static function paidBillingTerms(): array
    {
        return ['six months' => [6], 'twelve months' => [12]];
    }

    public function test_estimator_quotes_follow_prepaid_renewal_and_apply_refunds_to_each_month_once(): void
    {
        $start = CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC');
        $this->travelTo($start->addDay());
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $oldEnd = $start->addDays(30);
        $account = OrganizationCommercialAccount::query()->create(['organization_id' => $organization->id, 'status' => 'active',
            'quote_version' => 1, 'billing_period' => '6_months', 'current_period_start_at' => $oldEnd,
            'current_period_end_at' => $oldEnd->addMonthsNoOverflow(6)]);
        $old = $this->paidTermOrder($organization, $actor, $account, $start, $oldEnd, '30_days');
        $next = $this->paidTermOrder($organization, $actor, $account, $oldEnd, $oldEnd->addMonthsNoOverflow(6), '6_months');
        $next->forceFill(['renew_subscription' => true, 'paid_period_snapshot' => ['package_order_ids' => ['working-entry' => $old->id]]])->save();
        $this->paidTermSubscription($organization, $account, $next);
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $credits = app(AICreditService::class);
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, (string) Str::uuid(), 'generation');
        self::assertSame(500000, $quote['available_units_minor']);
        self::assertSame($old->id, \App\Models\Credits\AICreditLot::query()->where('organization_id', $organization->id)->sole()->commercial_order_id);

        $this->travelTo($oldEnd);
        $requestId = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $requestId, 'generation');
        self::assertSame($quote, $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $requestId, 'generation'));
        self::assertSame(500000, $quote['available_units_minor']);
        self::assertSame($next->id, \App\Models\Credits\AICreditLot::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail()->commercial_order_id);
        $refunded = intdiv((int) $next->amount_minor, 2);
        DB::table('commercial_payments')->insert(['commercial_order_id' => $next->id, 'amount_minor' => $next->amount_minor,
            'currency' => 'RUB', 'provider_idempotency_key' => (string) Str::uuid(), 'refunded_amount_minor' => $refunded]);
        app(AICreditCommercialService::class)->refund($next, $refunded);
        $replayed = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $requestId, 'generation');
        self::assertSame($quote['quote_id'], $replayed['quote_id']);
        self::assertSame($quote['max_units_minor'], $replayed['max_units_minor']);
        self::assertSame(250000, $replayed['available_units_minor']);

        $this->travelTo($oldEnd->addMonthsNoOverflow(1));
        $requestId = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $requestId, 'generation');
        self::assertSame($quote, $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $requestId, 'generation'));
        app(AICreditCommercialService::class)->refund($next, $refunded);
        self::assertSame(250000, $quote['available_units_minor']);
        self::assertSame(250000, $credits->balance($organization)['included_minor']);
        self::assertSame(3, DB::table('ai_credit_ledger_entries')->where('organization_id', $organization->id)->where('type', 'grant')->count());
        self::assertSame(250000, (int) \App\Models\Credits\AICreditLot::query()->where('organization_id', $organization->id)
            ->where('commercial_order_id', $next->id)->latest('id')->firstOrFail()->remaining_minor);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $scope->sessionId)->count());
        $this->travelBack();
    }

    private function paidTermOrder(Organization $organization, User $actor, OrganizationCommercialAccount $account, CarbonImmutable $start, CarbonImmutable $end, string $term): CommercialOrder
    {
        return CommercialOrder::query()->create(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id,
            'user_id' => $actor->id, 'commercial_account_id' => $account->id, 'kind' => 'purchase', 'status' => 'paid',
            'offer_type' => 'packages', 'quote_version' => 1, 'selected_package_slugs' => ['working-entry'], 'current_package_slugs' => [],
            'amount_minor' => 40000000, 'amount' => '400000.00', 'currency' => 'RUB', 'client_idempotency_key' => (string) Str::uuid(),
            'billing_period' => $term, 'period_start_at' => $start, 'period_end_at' => $end]);
    }

    private function paidTermSubscription(Organization $organization, OrganizationCommercialAccount $account, CommercialOrder $order): void
    {
        DB::table('organization_package_subscriptions')->insert(['organization_id' => $organization->id, 'commercial_account_id' => $account->id,
            'package_slug' => 'working-entry', 'status' => 'active', 'access_source' => 'paid_package', 'source_order_id' => $order->id,
            'current_period_start_at' => $order->period_start_at, 'current_period_end_at' => $order->period_end_at]);
    }

    public function test_an_unsuccessful_generation_result_rolls_back_the_reserve_receipt_and_jobs(): void
    {
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['status' => 'ready_to_generate', 'input_payload' => ['generation_mode' => 'strict_documents']]);
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:rejected-generation');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        $readiness = \Mockery::mock(\App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService::class);
        $readiness->shouldReceive('evaluate')->once()->andReturnUsing(function () use ($organization): array {
            self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());

            return ['can_generate' => false, 'summary' => ['pending_count' => 0], 'blocking_message_key' => 'estimate_generation.documents_require_action'];
        });
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService::class, $readiness);
        \Illuminate\Support\Facades\Queue::fake();
        $result = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration::class)->handle($session, 0, null, $actor,
            creditApproval: ['request_id' => $id, 'quote_id' => $quote['quote_id'], 'state_version' => 0]);
        self::assertFalse($result->successful);
        self::assertSame(409, $result->httpStatus);
        self::assertSame(0, $result->session->state_version);
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->first();
        self::assertSame('quoted', $operation->status);
        self::assertNull($operation->receipt);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_a_stale_service_session_cannot_reserve_a_quote_after_a_database_only_session_change(): void
    {
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:stale-service-session');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        DB::table('estimate_generation_sessions')->where('id', $session->id)->update(['state_version' => 1,
            'input_payload' => json_encode(['profile_id' => 'industrial'], JSON_THROW_ON_ERROR)]);
        try {
            DB::transaction(function () use ($operations, $actor, $session, $id, $quote): void {
                $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation');
                self::fail('The stale service object created a reserve despite a newer database state.');
            });
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState) {
            self::assertSame(1, $session->fresh()->state_version);
        }
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame('quoted', DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('status'));
    }

    public function test_qa_analysis_quote_caps_the_shared_wallet_reserve_and_enforces_the_remaining_total_without_reducing_scope(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        [$organization, $actor, $scope] = $this->fixture();
        config(['estimate-generation.live_qa.enabled' => false]);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['input_payload' => ['selected_sections' => ['structures', 'roofing', 'earthworks', 'finishing']]]);
        $credits = app(AICreditService::class);
        $credits->grant($organization, 1000, 'purchase', null, 'test:qa-capped-quote');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $regularId = (string) Str::uuid();
        $regularQuote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $regularId, 'analysis');
        $regularPlan = json_decode(DB::table('estimate_generation_credit_operations')->where('operation_id', $regularId)->value('processing_plan'), true, 64, JSON_THROW_ON_ERROR);
        self::assertGreaterThan(50000, $regularQuote['max_units_minor']);
        self::assertSame(array_sum(array_map(static fn (array $role): int => $role['max_calls'] * $role['maximum_cost_micro_rub'],
            $regularPlan['roles'])), $regularPlan['maximum_provider_cost_micro_rub']);
        $runId = (string) Str::uuid();
        config(['estimate-generation.live_qa.enabled' => true, 'estimate-generation.live_qa.run_id' => $runId]);
        $priorAttempt = (string) Str::uuid();
        DB::table('estimate_generation_vision_physical_attempts')->insert(['attempt_id' => $priorAttempt,
            'request_fingerprint' => hash('sha256', $priorAttempt), 'logical_request_fingerprint' => hash('sha256', $priorAttempt),
            'organization_id' => $scope->organizationId, 'project_id' => $scope->projectId, 'session_id' => $scope->sessionId,
            'state' => 'pre_wire', 'owner_token' => $priorAttempt, 'lease_expires_at' => now()->addMinutes(5),
            'usage_recorded' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorQaSupplierExpenseGuard::class)
            ->admit($priorAttempt, new AiCost('88.20000000', 'RUB', 'available')));
        $runBefore = DB::table('estimate_generation_qa_expense_runs')->where('run_id', $runId)->sole();
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'analysis');
        $row = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        $plan = json_decode($row->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(1000, $quote['max_units_minor']);
        self::assertSame(1800000, $plan['maximum_provider_cost_micro_rub']);
        self::assertSame($regularPlan['roles'], $plan['roles']);
        self::assertSame($regularPlan['maximum_calls'], $plan['maximum_calls']);
        self::assertSame(128, $plan['work_item_limit']);
        self::assertSame($runId, $plan['qa_expense_budget']['run_id']);
        self::assertEquals($runBefore, DB::table('estimate_generation_qa_expense_runs')->where('run_id', $runId)->sole());
        self::assertSame(1, DB::table('estimate_generation_qa_expense_attempts')->where('run_id', $runId)->count());
        self::assertSame($regularQuote['max_units_minor'], $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $regularId, 'analysis')['max_units_minor']);
        [$otherOrganization, $otherActor, $otherScope] = $this->fixture();
        $credits->grant($otherOrganization, 1000000, 'purchase', null, 'test:qa-concurrent-quote');
        $otherId = (string) Str::uuid();
        $otherQuote = $operations->quote($otherActor, $otherScope->projectId, $otherScope->sessionId, 0, $otherId, 'analysis');
        self::assertSame(1000, $otherQuote['max_units_minor']);
        self::assertSame($id, DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'analysis')));
        self::assertSame($id, DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'analysis')));
        $otherSession = EstimateGenerationSession::query()->findOrFail($otherScope->sessionId);
        try {
            DB::transaction(fn () => $operations->begin($otherActor, $otherSession, 0, $otherId, $otherQuote['quote_id'], 'analysis'));
            self::fail('Another organization reserved the same remaining QA budget.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_live_qa_expense_limit_reached', $exception->reason);
        }
        self::assertSame(0, $credits->balance($otherOrganization)['reserved_minor']);
        self::assertSame('quoted', DB::table('estimate_generation_credit_operations')->where('operation_id', $otherId)->value('status'));
        self::assertSame(0, \App\Models\Credits\AICreditReservation::query()->where('organization_id', $otherOrganization->id)->count());
        $row = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        $reservation = \App\Models\Credits\AICreditReservation::query()->findOrFail($row->reservation_id);
        self::assertSame(1000, $reservation->reserved_minor);
        $legacyAttempt = (string) Str::uuid();
        DB::table('estimate_generation_vision_physical_attempts')->insert(['attempt_id' => $legacyAttempt,
            'request_fingerprint' => hash('sha256', $legacyAttempt), 'logical_request_fingerprint' => hash('sha256', $legacyAttempt),
            'organization_id' => $otherScope->organizationId, 'project_id' => $otherScope->projectId, 'session_id' => $otherScope->sessionId,
            'state' => 'pre_wire', 'owner_token' => $legacyAttempt, 'lease_expires_at' => now()->addMinutes(5),
            'usage_recorded' => false, 'created_at' => now(), 'updated_at' => now()]);
        $qaGuard = app(\App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorQaSupplierExpenseGuard::class);
        try {
            DB::transaction(fn () => $qaGuard->admit($legacyAttempt, new AiCost('0.09000000', 'RUB', 'available')));
            self::fail('A legacy call spent the remaining budget held by another operation.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_live_qa_expense_limit_reached', $exception->reason);
        }
        self::assertFalse(DB::table('estimate_generation_qa_expense_attempts')->where('attempt_id', $legacyAttempt)->exists());
        self::assertSame(1000, $reservation->fresh()->reserved_minor);
        $attempt = (string) Str::uuid();
        $admission = app(EstimatorCreditAttemptAdmission::class);
        DB::transaction(fn () => $admission->admit($id, $attempt, $scope->organizationId, $scope->projectId, $scope->sessionId,
            'estimate_composition', new AiCost('1.00000000', 'RUB', 'available')));
        DB::table('estimate_generation_vision_physical_attempts')->insert(['attempt_id' => $attempt,
            'credit_operation_id' => $id, 'credit_role' => 'estimate_composition',
            'request_fingerprint' => hash('sha256', $attempt), 'logical_request_fingerprint' => hash('sha256', $attempt),
            'organization_id' => $scope->organizationId, 'project_id' => $scope->projectId, 'session_id' => $scope->sessionId,
            'state' => 'pre_wire', 'owner_token' => $attempt, 'lease_expires_at' => now()->addMinutes(5),
            'usage_recorded' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => $qaGuard->admit($attempt, new AiCost('1.00000000', 'RUB', 'available')));
        self::assertTrue(DB::table('estimate_generation_qa_expense_attempts')->where('attempt_id', $attempt)->exists());
        try {
            DB::transaction(fn () => $admission->admit($id, (string) Str::uuid(), $scope->organizationId, $scope->projectId, $scope->sessionId,
                'estimate_composition', new AiCost('1.00000000', 'RUB', 'available')));
            self::fail('A second call exceeded the overall QA quote budget.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_credit_supplier_limit_reached', $exception->reason);
        }
        self::assertSame(1, DB::table('estimate_generation_credit_attempts')->where('operation_id', $id)->count());
        $identity = json_decode($row->credit_request, true, 64, JSON_THROW_ON_ERROR);
        app(EloquentAiUsageStore::class)->record(new AiUsageData(new AiOperationContext((string) Str::uuid(), $attempt,
            $scope->organizationId, $scope->projectId, $scope->sessionId, 'plan_work_items', 'estimate_composition', 1, creditOperationId: $id),
            'timeweb', 'openai/gpt-6-luna', 'succeeded', 10, reportedModel: 'openai/gpt-6-luna', usageStatus: 'measured',
            inputTokens: 1000, outputTokens: 100, reasoningTokens: 20, priceSnapshot: AiPriceSnapshot::fromArray($identity['supplier_tariff'])));
        $draft = ['source_input_version' => $row->source_version, 'local_estimates' => [['sections' => [['work_items' => [
            ['key' => 'work:known', 'quantity' => '3', 'quantity_evidence' => ['source_type' => 'user_input']],
        ]]]]]];
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft);
        $receipt = DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
            ->publish($session->fresh(), $id, $draft));
        self::assertSame(50, $receipt['consumed_units_minor']);
        self::assertSame(950, $credits->balance($organization)['available_minor']);
        self::assertSame(0, $credits->balance($organization)['reserved_minor']);
        $freshOtherId = (string) Str::uuid();
        $freshOtherQuote = $operations->quote($otherActor, $otherScope->projectId, $otherScope->sessionId, 0, $freshOtherId, 'analysis');
        self::assertSame(950, $freshOtherQuote['max_units_minor']);
        self::assertSame($freshOtherId, DB::transaction(fn () => $operations->begin($otherActor, $otherSession, 0, $freshOtherId, $freshOtherQuote['quote_id'], 'analysis')));
        self::assertSame(950, $credits->balance($otherOrganization)['reserved_minor']);
    }

    public function test_qa_rejects_a_stale_analysis_quote_when_supplier_expense_changes_before_begin(): void
    {
        [$organization, $actor, $scope] = $this->fixture();
        config(['estimate-generation.live_qa.enabled' => true, 'estimate-generation.live_qa.run_id' => (string) Str::uuid()]);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $credits = app(AICreditService::class);
        $credits->grant($organization, 100000, 'purchase', null, 'test:qa-stale-quote');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'analysis');
        self::assertSame(50000, $quote['max_units_minor']);
        $prior = (string) Str::uuid();
        DB::table('estimate_generation_vision_physical_attempts')->insert(['attempt_id' => $prior,
            'request_fingerprint' => hash('sha256', $prior), 'logical_request_fingerprint' => hash('sha256', $prior),
            'organization_id' => $scope->organizationId, 'project_id' => $scope->projectId, 'session_id' => $scope->sessionId,
            'state' => 'pre_wire', 'owner_token' => $prior, 'lease_expires_at' => now()->addMinutes(5),
            'usage_recorded' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorQaSupplierExpenseGuard::class)
            ->admit($prior, new AiCost('1.00000000', 'RUB', 'available')));
        try {
            DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'analysis'));
            self::fail('The quote reserved more than the remaining QA budget.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_live_qa_expense_limit_reached', $exception->reason);
        }
        self::assertSame(0, $credits->balance($organization)['reserved_minor']);
        self::assertSame(0, \App\Models\Credits\AICreditReservation::query()->where('organization_id', $organization->id)->count());
        $freshId = (string) Str::uuid();
        $freshQuote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $freshId, 'analysis');
        self::assertSame(49400, $freshQuote['max_units_minor']);
        DB::transaction(function () use ($operations, $actor, $session, $freshId, $freshQuote): void {
            self::assertSame($freshId, $operations->begin($actor, $session, 0, $freshId, $freshQuote['quote_id'], 'analysis'));
            self::assertGreaterThanOrEqual(1, (int) DB::selectOne("SELECT COUNT(*) AS locks FROM pg_locks WHERE locktype='advisory' AND pid=pg_backend_pid()")->locks);
        });
        self::assertSame(49400, $credits->balance($organization)['reserved_minor']);
    }

    public function test_equipment_expansion_is_planned_in_a_free_quote_before_reservation(): void
    {
        [$organization, $actor, $scope, $session, $operations] = $this->equipmentQuoteFixture(17);
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        $stored = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        $plan = json_decode($stored->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(64, $plan['work_item_limit']);
        self::assertSame(32, $plan['known_context']['equipment_work_scope']['expansion_delta']);
        self::assertSame(34, $plan['known_context']['equipment_work_scope']['operation_count']);
        self::assertGreaterThanOrEqual(35, $plan['audit_part_limit']);
        self::assertSame($quote['quote_id'], $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation')['quote_id']);
        self::assertSame($stored->processing_plan, DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('processing_plan'));
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_changed_equipment_scope_with_the_same_count_requires_a_new_quote_without_mutating_the_frozen_plan(): void
    {
        [$organization, $actor, $scope, $session, $operations] = $this->equipmentQuoteFixture(16);
        foreach ([18, 19] as $index) {
            $this->saveKnownQuoteEquipment($scope, $index, ['estimate_scope' => 'requires_confirmation', 'visual_inventory_category' => 'sanitary_fixture']);
            $fact = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:visual-quote-'.$index,
                $scope->organizationId, $scope->projectId, $scope->sessionId, 'sha256:'.str_repeat('a', 64), 'equipment:quote-'.$index,
                'sanitary_fixture', 'Прибор '.$index, null, 0.7, 'document', 'candidate', []);
            app(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class)->saveSourceModel([], [$fact], []);
            $this->answerEquipmentQuoteScope($scope, $index, $index === 18 ? 'installation_only' : 'exclude');
        }
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        $stored = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        $plan = json_decode($stored->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(33, $plan['known_context']['equipment_work_scope']['operation_count']);
        self::assertSame(63, $plan['work_item_limit']);
        $this->answerEquipmentQuoteScope($scope, 18, 'exclude');
        $this->answerEquipmentQuoteScope($scope, 19, 'installation_only');
        $current = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorKnownContextSnapshot::class)->capture($session, false);
        self::assertSame(33, $current['equipment_work_scope']['operation_count']);
        self::assertNotSame($plan['known_context']['equipment_work_scope']['fingerprint'], $current['equipment_work_scope']['fingerprint']);
        try {
            DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation'));
            self::fail('A changed equipment scope must require a new free quote.');
        } catch (\DomainException $error) {
            self::assertSame('estimator_known_context_changed', $error->getMessage());
        }
        self::assertEquals($stored, DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_oversized_equipment_expansion_requires_a_split_before_an_operation_or_reservation_exists(): void
    {
        [$organization, $actor, $scope, $session, $operations] = $this->equipmentQuoteFixture(256);
        $id = (string) Str::uuid();
        try {
            $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
            self::fail('An oversized equipment expansion must fail before paid generation.');
        } catch (\DomainException $error) {
            self::assertSame('estimator_processing_plan_requires_section_split', $error->getMessage());
        }
        self::assertSame(0, DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    #[DataProvider('nonResidentialEquipmentQuotes')]
    public function test_non_residential_equipment_does_not_reserve_a_residential_expansion(string $objectType, string $profile): void
    {
        [$organization, $actor, $scope, $session, $operations] = $this->equipmentQuoteFixture(256);
        $session->update(['input_payload' => [...$session->input_payload, 'object_type' => $objectType, 'profile_id' => $profile]]);
        $id = (string) Str::uuid();
        $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        $plan = json_decode(DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('processing_plan'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(32, $plan['work_item_limit']);
        self::assertArrayNotHasKey('equipment_work_scope', $plan['known_context']);
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public static function nonResidentialEquipmentQuotes(): array
    {
        return [['warehouse', 'construction'], ['office', 'construction'], ['mixed_warehouse_office', 'construction'], ['house', 'industrial']];
    }

    public function test_document_derived_residential_equipment_is_planned_and_a_changed_object_profile_invalidates_the_quote_before_reservation(): void
    {
        [$organization, $actor, $scope, $session, $operations] = $this->equipmentQuoteFixture(17);
        $session->update(['input_payload' => [...$session->input_payload, 'object_type' => 'custom']]);
        $document = $this->equipmentProfileDocument($scope, $actor, 'equipment.pdf', 'Одноэтажный жилой дом, оборудование санузла.');
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        $stored = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        $plan = json_decode($stored->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(64, $plan['work_item_limit']);
        self::assertSame(34, $plan['known_context']['equipment_work_scope']['operation_count']);
        $document->update(['extracted_text' => 'Склад оборудования', 'checksum_sha256' => str_repeat('c', 64),
            'source_version' => 'sha256:'.str_repeat('c', 64)]);
        $current = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorKnownContextSnapshot::class)->capture($session, false);
        self::assertArrayNotHasKey('equipment_work_scope', $current);
        self::assertNotSame($plan['known_context']['fingerprint'], $current['fingerprint']);
        try {
            DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation'));
            self::fail('A changed document-derived object profile must require a new free quote.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState $error) {
            self::assertSame(sprintf('Estimate generation session %d no longer has state version 0.', $scope->sessionId), $error->getMessage());
        }
        self::assertEquals($stored, DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_an_oversized_equipment_profile_document_requires_a_split_before_a_quote_or_reservation(): void
    {
        [$organization, $actor, $scope, $session, $operations] = $this->equipmentQuoteFixture(1);
        $this->equipmentProfileDocument($scope, $actor, 'oversized.pdf',
            str_repeat('x', \App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentGenerationPipelineDataGateway::MAX_SOURCE_BYTES + 1));
        $id = (string) Str::uuid();
        try {
            $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
            self::fail('An oversized profile source must not be materialized for a quote.');
        } catch (\DomainException $error) {
            self::assertSame('estimator_known_context_requires_source_split', $error->getMessage());
        }
        self::assertSame(0, DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    private function equipmentProfileDocument(EstimatorCreditRequest $scope, User $actor, string $filename, string $text): EstimateGenerationDocument
    {
        $checksum = hash('sha256', $text);
        $document = EstimateGenerationDocument::query()->create([
            'organization_id' => $scope->organizationId, 'project_id' => $scope->projectId, 'session_id' => $scope->sessionId,
            'user_id' => $actor->id, 'filename' => $filename, 'mime_type' => 'application/pdf',
            'status' => 'ready', 'quality_level' => 'good', 'checksum_sha256' => $checksum,
            'source_version' => 'sha256:'.$checksum, 'extracted_text' => $text,
            'facts_summary' => ['document_understanding' => ['role_for_estimation' => 'quantity_source']],
        ]);
        $document->processingUnits()->create([
            'organization_id' => $scope->organizationId, 'project_id' => $scope->projectId, 'session_id' => $scope->sessionId,
            'unit_type' => 'pdf_page', 'unit_index' => 1, 'source_version' => $document->source_version, 'status' => 'completed',
            'output_version' => hash('sha256', 'equipment-fixture-output'), 'output_count' => 1, 'completed_at' => now(),
            'locator' => ['source_kind' => 'pdf', 'source_version' => $document->source_version,
                'coordinate_space' => 'pdf_page_pixels', 'artifact_path' => 'fixture/private/equipment/page.png',
                'artifact_sha256' => 'sha256:'.hash('sha256', 'equipment-fixture-page'), 'artifact_version_id' => 'fixture.v1'],
            'metadata' => ['processing_attempt_id' => (string) Str::uuid()],
        ]);

        return $document;
    }

    private function equipmentQuoteFixture(int $count): array
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['input_payload' => ['evaluation_mode' => 'universal', 'profile_id' => 'construction',
            'object_type' => 'house', 'selected_sections' => ['equipment']]]);
        for ($index = 1; $index <= $count; $index++) {
            $this->saveKnownQuoteEquipment($scope, $index);
        }
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:equipment-quote');

        return [$organization, $actor, $scope, $session, app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)];
    }

    private function saveKnownQuoteEquipment(EstimatorCreditRequest $scope, int $index, array $properties = []): void
    {
        $source = 'sha256:'.str_repeat('a', 64);
        $entity = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('equipment:quote-'.$index,
            $scope->organizationId, $scope->projectId, $scope->sessionId, $source, 'equipment', 'equipment:quote-'.$index,
            ['name' => 'Оборудование '.$index, 'equipment_code' => 'quote:equipment-'.$index,
                'properties' => ['semantic_type' => 'equipment', ...$properties]]);
        $fact = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:equipment-quote-'.$index,
            $scope->organizationId, $scope->projectId, $scope->sessionId, $source, $entity->id, 'equipment_count', '1', 'count',
            0.0, 'user_assumption', 'confirmed', []);
        app(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class)->saveSourceModel([$entity], [$fact], []);
    }

    private function answerEquipmentQuoteScope(EstimatorCreditRequest $scope, int $index, string $choice): void
    {
        app(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision::class)->applyClarificationChoice(
            $scope->organizationId, $scope->projectId, $scope->sessionId, 'sha256:'.str_repeat('a', 64), 'fact:visual-quote-'.$index,
            'project_question_visual'.$index, 'selected', $choice, $choice, null, str_repeat('c', 64),
            ['conflict_id' => 'visual-inventory:quote-'.$index, 'fact_ids' => ['fact:visual-quote-'.$index]],
            (string) $scope->actorUserId, 'Подтверждение области оборудования', 'decision:'.Str::uuid());
    }

    public function test_a_quote_reserves_the_real_known_composer_parts_even_with_one_selected_section(): void
    {
        [$organization, $actor, $scope] = $this->fixture();
        config(['estimate-generation.estimate_composer.max_input_bytes' => 5000]);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['input_payload' => ['selected_sections' => ['finishing']]]);
        $this->saveKnownQuoteFact($scope, 1, str_repeat('word ', 500));
        $this->saveKnownQuoteFact($scope, 2, str_repeat('word ', 500));
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:known-composer-parts');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        $plan = json_decode(DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('processing_plan'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(2, $plan['known_context']['part_limits']['composer']);
        self::assertSame(34, $plan['composer_part_limit']);
        self::assertSame(102, $plan['roles']['estimate_composition']['max_calls']);
        self::assertSame('known_context_lower_bound', $plan['known_context']['basis']);
        self::assertStringNotContainsString('word word', json_encode($plan, JSON_THROW_ON_ERROR));
        self::assertSame($quote['quote_id'], $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation')['quote_id']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame($id, DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation')));
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
            ->abort($session->fresh(), $id, 'fixture_finished'));
    }

    public function test_changed_known_project_facts_require_a_new_quote_before_any_wallet_reservation(): void
    {
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $this->saveKnownQuoteFact($scope, 1, 'Original known context');
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:known-context-change');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        $this->saveKnownQuoteFact($scope, 2, 'New source data arrived without changing the session input');
        try {
            DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation'));
            self::fail('Changed model context was reserved using the previous quote.');
        } catch (\DomainException $error) {
            self::assertSame('estimator_known_context_changed', $error->getMessage());
        }
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_a_stale_saved_draft_can_quote_recomposition_after_actual_fact_supersession(): void
    {
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $old = $this->saveKnownQuoteFact($scope, 1, 'Original room name');
        $draft = ['local_estimates' => [['key' => 'local:1', 'sections' => [['key' => 'section:1', 'work_items' => [[
            'key' => 'work:1', 'item_type' => 'priced_work', 'name' => 'Paint', 'unit' => 'm2', 'quantity' => '10',
            'source_refs' => [['fact_id' => $old->id]],
        ]]]]]]];
        $draft['source_input_version'] = $scope->sourceVersion;
        $draft['quality_summary'] = ['content_version' => \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot::contentVersion($draft),
            'review_items' => \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot::create($draft, [])];
        $session->update(['draft_payload' => $draft]);
        $new = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:quote-1:rev2',
            $scope->organizationId, $scope->projectId, $scope->sessionId, $old->sourceVersion, $old->entityId, 'room_name',
            'Updated room name', null, 0.0, 'user_assumption', 'confirmed', [], 2, $old->id);
        $models = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class);
        $models->saveSourceModel([], [$new], []);
        self::assertNotContains($old->id, array_map(static fn ($fact): string => $fact->id,
            $models->snapshotForPlanning($scope->organizationId, $scope->projectId, $scope->sessionId, 2000)['snapshot']->facts));
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:stale-draft-quote');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        $plan = json_decode(DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('processing_plan'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('recomposition_required', $plan['known_context']['draft_context_status']);
        self::assertSame(1, $plan['known_context']['known_work_count']);
        self::assertSame(0, $plan['known_context']['part_limits']['audit']);
        self::assertSame(CanonicalPipelineJson::encode($draft), CanonicalPipelineJson::encode($session->fresh()->draft_payload));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame($id, DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation')));
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
            ->abort($session->fresh(), $id, 'fixture_finished'));
    }

    private function saveKnownQuoteFact(EstimatorCreditRequest $scope, int $index, string $value): \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact
    {
        $source = 'sha256:'.str_repeat('a', 64);
        $entity = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('room:quote-'.$index,
            $scope->organizationId, $scope->projectId, $scope->sessionId, $source, 'room', 'room:quote-'.$index,
            ['semantic_type' => 'room', 'identity' => ['room_name' => 'Quote room '.$index]]);
        $fact = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:quote-'.$index,
            $scope->organizationId, $scope->projectId, $scope->sessionId, $source, $entity->id, 'room_name', $value,
            null, 0.0, 'user_assumption', 'confirmed', []);
        app(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class)->saveSourceModel([$entity], [$fact], []);

        return $fact;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('composerPartApprovals')]
    public function test_actual_composer_preflights_all_parts_against_the_original_reserve_and_replays(bool $approved): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        config(['estimate-generation.estimate_composer.max_input_bytes' => 5000,
            'estimate-generation.estimate_composer.max_output_tokens' => 4096,
            'estimate-generation.estimate_auditor.max_output_tokens' => 4096,
            'ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key',
            'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['input_payload' => ['selected_sections' => $approved ? ['finishing', 'roofing'] : ['finishing']]]);
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:composer-parts');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $operationId = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $operationId, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $operationId, $quote['quote_id'], 'generation'));
        $frozenPlan = json_decode(DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->value('processing_plan'), true, 64, JSON_THROW_ON_ERROR);
        $frozenOutput = $frozenPlan['roles']['estimate_composition']['output_ceiling'];
        self::assertSame(8192, $frozenOutput);
        config(['ai-assistant-credits.profiles.normal' => ['input_tokens' => 32768, 'output_tokens' => 4096, 'max_calls' => 6]]);
        $facts = $candidates = [];
        foreach (range(1, $approved ? 2 : 33) as $id) {
            $facts[] = ['id' => 'fact:'.$id, 'entity_id' => 'room:'.$id, 'value' => str_repeat('word ', 500), 'status' => 'confirmed'];
            if ($id <= 2) {
                $candidates[] = ['candidate_id' => 'candidate:'.$id, 'work_key' => 'work:'.$id, 'name' => 'Работа '.$id,
                    'unit' => null, 'quantity' => null, 'quantity_formula' => null,
                    'source_fact_ids' => ['fact:'.$id], 'technology_package_candidate' => null];
            }
        }
        $input = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput(
            (int) $organization->id, $scope->projectId, $scope->sessionId, str_repeat('a', 64), $facts, [], [], $candidates, [],
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer::PROMPT_CONTRACT,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope(0, null, $operationId));
        $history = [];
        $response = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'chatcmpl-composer-parts', 'object' => 'chat.completion', 'created' => 1, 'model' => 'openai/gpt-6-luna',
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"work_intents":[]}']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        ], JSON_THROW_ON_ERROR));
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([$response, $response]));
        $handler->push(\GuzzleHttp\Middleware::history($history));
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient::class,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient(
                app(\App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory::class),
                app(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard::class),
                app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore::class),
                httpClient: new \GuzzleHttp\Client(['handler' => $handler])));
        $composer = app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer::class);
        if (! $approved) {
            try {
                $composer->run($input);
                self::fail('Composer sent a part beyond the approved operation.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_composer_parts_exceed_approved_plan', $exception->getMessage());
            }
            self::assertCount(0, $history);
            self::assertSame(0, DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)->count());
        } else {
            $result = $composer->run($input);
            self::assertCount(2, $result);
            self::assertCount(2, $history);
            self::assertSame($frozenOutput, json_decode((string) $history[0]['request']->getBody(), true, 64, JSON_THROW_ON_ERROR)['max_completion_tokens']);
            self::assertSame($result, $composer->run($input));
            self::assertCount(2, $history);
            self::assertSame(2, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
        }
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
            ->abort($session->fresh(), $operationId, 'fixture_finished'));
    }

    public static function composerPartApprovals(): array
    {
        return [[true], [false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('textOnlyCompositionCases')]
    public function test_text_only_job_publishes_a_worklist_with_unknown_quantities_and_questions_without_charging_for_unpriced_work(bool $supplementary, bool $infoOnly = false): void
    {
        (new \Tests\Support\EstimateGeneration\InMemoryS3HttpStorage)->install();
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true, 'cache.default' => 'array',
            'ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key',
            'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $queue = \Illuminate\Support\Facades\Queue::fake();
        $session = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CreateEstimateGenerationSession::class)->handle([
            'organization_id' => $organization->id, 'project_id' => $scope->projectId,
            'input_payload' => ['description' => $infoOnly ? 'Нужно уточнить состав выбранных работ для объекта.'
                : 'Отделка комнаты: штукатурка и окраска стен, площадь около 12 м² требует проверки.',
                'generation_mode' => 'ai_assisted', 'evaluation_mode' => 'universal', 'profile_id' => 'renovation',
                'construction_type' => 'current_repair', 'price_policy' => 'catalog', 'selected_sections' => [$infoOnly ? 'automation' : 'finishing']],
        ], $actor);
        $history = [];
        $composerResponse = ['work_intents' => []];
        if ($supplementary) {
            $facts = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class)
                ->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id)->facts;
            $composerResponse['work_intents'][] = ['kind' => 'supplementary', 'work_key' => 'painting-from-description', 'name' => 'Окраска стен',
                'derived_quantity_id' => null, 'source_fact_ids' => [$facts[0]->id], 'technology_package_candidate' => null,
                'unit' => 'm2', 'work_section' => 'finishing', 'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => []];
        }
        $responses = array_map(static fn (array $payload): \GuzzleHttp\Psr7\Response => new \GuzzleHttp\Psr7\Response(200,
            ['Content-Type' => 'application/json'], json_encode([
                'id' => 'chatcmpl-text-only', 'object' => 'chat.completion', 'created' => 1, 'model' => 'openai/gpt-6-luna',
                'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => json_encode($payload, JSON_THROW_ON_ERROR)]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ], JSON_THROW_ON_ERROR)), [$composerResponse, ['findings' => []]]);
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));
        $handler->push(\GuzzleHttp\Middleware::history($history));
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient::class,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient(
                app(\App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory::class),
                app(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard::class),
                app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore::class),
                httpClient: new \GuzzleHttp\Client(['handler' => $handler])));
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:text-only-pipeline');
        $operationId = (string) Str::uuid();
        $quote = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
            ->quote($actor, (int) $session->project_id, (int) $session->id, 0, $operationId, 'generation');
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration::class)
            ->handle($session, 0, 'ai_assisted', $actor, creditApproval: ['request_id' => $operationId, 'quote_id' => $quote['quote_id']]);
        $job = $queue->pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class)->first();
        foreach (\App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage::cases() as $stage) {
            $job->handle(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft::class));
        }
        $session->refresh();
        if ($infoOnly) {
            self::assertSame('input_review_required', $session->status->value);
            self::assertTrue($session->draft_payload['source_info_only']);
            self::assertNull($session->draft_payload['evaluation_result']);
            self::assertSame([], $session->draft_payload['local_estimates']);
            self::assertSame('estimate_composer_work_section_not_selected', $session->draft_payload['estimate_composition']['request_info'][0]['reason']);
            self::assertCount(1, $history);
            self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
            self::assertSame(0, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
            $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->sole();
            self::assertSame('settled', $operation->status);
            self::assertSame(0, (int) $operation->consumed_units_minor);
            self::assertGreaterThan(0, json_decode($operation->receipt, true, 64, JSON_THROW_ON_ERROR)['measured_supplier_cost_micro_rub']);
            self::assertSame(1000000, app(AICreditService::class)->balance($organization)['available_minor']);
            $job->handle(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft::class));
            self::assertCount(1, $history);
            self::assertSame('input_review_required', $session->fresh()->status->value);

            return;
        }
        self::assertSame('estimate_review_required', $session->status->value);
        $evaluation = $session->draft_payload['evaluation_result'];
        if ($supplementary) {
            $position = array_column($evaluation['scenarios'][0]['positions'], null, 'key')['painting-from-description'] ?? null;
            self::assertNotNull($position);
            self::assertSame('finishing', $position['section']);
            self::assertSame('m2', $position['unit']);
            self::assertNull($position['quantity']);
        }
        self::assertNotEmpty($evaluation['scenarios'][0]['positions']);
        foreach ($evaluation['scenarios'][0]['positions'] as $position) {
            self::assertNull($position['quantity']);
            self::assertNull($position['price_snapshot']);
        }
        self::assertNotEmpty($evaluation['priority_questions']);
        self::assertLessThanOrEqual(3, count($evaluation['priority_questions']));
        self::assertCount(2, $history);
        self::assertSame(2, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->sole();
        self::assertSame(0, (int) $operation->consumed_units_minor);
        self::assertSame(1000000, app(AICreditService::class)->balance($organization)['available_minor']);
        $job->handle(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft::class));
        self::assertCount(2, $history);
        self::assertSame(1, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
    }

    public static function textOnlyCompositionCases(): array
    {
        return [[false], [true], [true, true]];
    }

    public function test_fresh_text_without_files_queues_only_with_an_approved_quote_and_replays_once(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['input_payload' => ['description' => 'Ремонт помещения, размеры уточним',
            'generation_mode' => 'ai_assisted', 'evaluation_mode' => 'universal', 'profile_id' => 'renovation',
            'construction_type' => 'current_repair', 'price_policy' => 'catalog', 'selected_sections' => ['finishing']]]);
        self::assertSame(0, $session->documents()->count());
        $generate = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration::class);
        try {
            $generate->handle($session, 0, 'ai_assisted', $actor);
            self::fail('Text-only generation bypassed the price approval.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
            \Illuminate\Support\Facades\Queue::assertNotPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class);
        }
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:text-only');
        $id = (string) Str::uuid();
        $quote = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
            ->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        $approval = ['request_id' => $id, 'quote_id' => $quote['quote_id']];
        $result = $generate->handle($session, 0, 'ai_assisted', $actor, creditApproval: $approval);
        self::assertTrue($result->successful);
        self::assertSame('generating', $result->session->status->value);
        self::assertSame($id, $result->session->input_payload['generation_credit_operation_id']);
        self::assertTrue($generate->handle($session->fresh(), 0, 'ai_assisted', $actor, creditApproval: $approval)->successful);
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        \Illuminate\Support\Facades\Queue::assertPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class, 1);
    }

    public function test_confirmed_manual_input_with_ignored_system_failure_queues_with_one_approved_reservation(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['status' => 'ready_to_generate', 'input_payload' => [
            'description' => 'Ремонт помещения по подтверждённым ручным размерам', 'manual_input_revision' => 1,
            'generation_mode' => 'ai_assisted', 'evaluation_mode' => 'universal', 'profile_id' => 'renovation',
            'construction_type' => 'current_repair', 'price_policy' => 'catalog', 'selected_sections' => ['finishing'],
        ]]);
        $session->documents()->create([
            'organization_id' => $organization->id, 'project_id' => $scope->projectId, 'user_id' => $actor->id,
            'filename' => 'project.pdf', 'mime_type' => 'application/pdf', 'storage_path' => 'org-'.$organization->id.'/project.pdf',
            'checksum_sha256' => str_repeat('d', 64), 'source_version' => 'sha256:'.str_repeat('d', 64),
            'status' => 'ignored', 'processing_stage' => 'completed', 'error_code' => 'document_processing_system_failed',
            'facts_summary' => ['processing_outcome' => ['type' => 'system_failure']],
        ]);
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:manual-ignored-system-failure');
        $id = (string) Str::uuid();
        $quote = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
            ->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        $approval = ['request_id' => $id, 'quote_id' => $quote['quote_id']];
        $generate = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration::class);

        $result = $generate->handle($session, 0, 'ai_assisted', $actor, creditApproval: $approval);

        self::assertTrue($result->successful);
        self::assertSame('generating', $result->session->status->value);
        self::assertSame($id, $result->session->input_payload['generation_credit_operation_id']);
        self::assertTrue($generate->handle($session->fresh(), 0, 'ai_assisted', $actor, creditApproval: $approval)->successful);
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        $readiness = app(\App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService::class)
            ->evaluate($session->fresh(['documents']));
        self::assertSame(0, $readiness['summary']['system_failure_count']);
        self::assertTrue($readiness['summary']['items'][0]['is_system_failure']);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class, 1);
    }

    public function test_ignored_permanent_failure_does_not_change_the_active_document_failure_reason(): void
    {
        foreach (['temporary_failure' => 'document_processing_temporarily_unavailable', 'system_failure' => 'document_processing_system_failed'] as $type => $code) {
            [$organization, $actor, $scope] = $this->fixture();
            $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
            $session->update(['status' => 'processing_documents', 'processing_stage' => 'processing_documents']);
            foreach ([['ignored', 'system_failure', 'document_processing_system_failed', 'd'], ['failed', $type, $code, 'e']] as [$status, $outcome, $error, $hash]) {
                $session->documents()->create([
                    'organization_id' => $organization->id, 'project_id' => $scope->projectId, 'user_id' => $actor->id,
                    'filename' => $status.'.pdf', 'mime_type' => 'application/pdf', 'storage_path' => 'org-'.$organization->id.'/'.$status.'.pdf',
                    'checksum_sha256' => str_repeat($hash, 64), 'source_version' => 'sha256:'.str_repeat($hash, 64),
                    'status' => $status, 'processing_stage' => 'completed', 'error_code' => $error,
                    'facts_summary' => ['processing_outcome' => ['type' => $outcome]],
                ]);
            }

            $failed = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReconcileEstimateGenerationDocuments::class)->reconcile($session);

            self::assertSame('failed', $failed->status->value);
            self::assertSame($code, $failed->failure_code);
            self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        }
    }

    public function test_simple_paid_understanding_keeps_current_conflict_questions_without_any_model_expense(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts::class)->handle(
            $actor, $scope->projectId, $scope->sessionId, 0, (string) Str::uuid(), ['entities' => [
                ['key' => 'room-a', 'type' => 'room', 'name' => 'Первое помещение'],
                ['key' => 'room-b', 'type' => 'room', 'name' => 'Второе помещение'],
            ]]);
        $models = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class);
        $facts = $models->snapshot((int) $organization->id, $scope->projectId, $scope->sessionId)->facts;
        self::assertCount(2, $facts);
        $conflict = \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict::between(
            'conflict:manual:scope-confirmation', $facts, 'manual_scope_confirmation_required');
        $models->saveSourceModel([], [], [], [$conflict]);
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:simple-understanding');
        $operationId = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 1, $operationId, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session->fresh(), 1, $operationId, $quote['quote_id'], 'generation'));
        $wire = new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope(1, null, $operationId);
        $coordinator = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingCoordinator::class);
        $result = $coordinator->refresh((int) $organization->id, $scope->projectId, $scope->sessionId, (string) Str::uuid(), 1, $wire);
        self::assertSame(0, $result->providerCalls);
        self::assertSame([$conflict->id], array_column($result->questions, 'conflict_id'));
        $factIds = array_column($facts, 'id');
        sort($factIds, SORT_STRING);
        self::assertSame($factIds, $result->questions[0]['fact_ids']);
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $scope->sessionId)->count());
        $replayed = $coordinator->refresh((int) $organization->id, $scope->projectId, $scope->sessionId, (string) Str::uuid(), 2, $wire);
        self::assertEquals($result->questions, $replayed->questions);
        self::assertSame([$conflict->id], array_column($replayed->conflicts, 'id'));
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
            ->abort($session->fresh(), $operationId, 'fixture_finished'));
        $row = DB::table('estimate_generation_project_understanding_runs')->where('session_id', $scope->sessionId)->sole();
        $payload = json_decode($row->result_payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectUnderstandingFingerprint::CONTRACT, $payload['result_contract']);
        $payload['questions'][0]['fact_ids'] = [];
        DB::table('estimate_generation_project_understanding_runs')->where('id', $row->id)
            ->update(['result_payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project understanding fingerprint collision.');
        $coordinator->refresh((int) $organization->id, $scope->projectId, $scope->sessionId, (string) Str::uuid(), 3, $wire);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('auditPartitionApprovals')]
    public function test_real_audit_parts_use_the_original_reserve_and_reject_an_unapproved_expansion(bool $enoughParts, bool $largeContext = false): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['input_payload' => ['selected_sections' => $enoughParts ? ['finishing', 'structure', 'equipment'] : ['finishing']]]);
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:audit-parts');
        $operationId = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $operationId, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $operationId, $quote['quote_id'], 'generation'));
        $responses = [];
        for ($index = 0; $index < 3; $index++) {
            $responses[] = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'chatcmpl-part-'.$index, 'object' => 'chat.completion', 'created' => 1, 'model' => 'openai/gpt-6-luna',
                'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"accepted":true,"findings":[]}']]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ], JSON_THROW_ON_ERROR));
        }
        config(['ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key', 'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        $httpHistory = [];
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));
        $handler->push(\GuzzleHttp\Middleware::history($httpHistory));
        $wire = new \App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient(
            app(\App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore::class),
            httpClient: new \GuzzleHttp\Client(['handler' => $handler]));
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient::class, $wire);
        $items = array_map(static fn (int $index): array => ['key' => 'work:'.$index, 'name' => 'Работа '.$index, 'quantity' => '1', 'unit' => 'm2'], range(1, 65));
        $facts = $evidence = [];
        if ($largeContext) {
            for ($bucket = 0; $bucket < 3; $bucket++) {
                for ($suffix = 0; ; $suffix++) {
                    $id = 'fact:context:'.$bucket.':'.$suffix;
                    if ((int) hexdec(substr(hash('sha256', $id), 0, 7)) % 3 === $bucket) {
                        break;
                    }
                }
                $facts[] = ['id' => $id, 'value' => str_repeat('x', 200000)];
                $evidence[] = ['fact_id' => $id, 'locator' => ['page' => $bucket + 1, 'evidence_id' => 'evidence:'.$bucket]];
            }
        }
        $input = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput(
            (int) $organization->id, $scope->projectId, $scope->sessionId, str_repeat('a', 64), 0, $facts, [],
            ['local_estimates' => [['key' => 'building', 'sections' => [['key' => 'works', 'work_items' => $items]]]]], $evidence,
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit::PROMPT_CONTRACT,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope(0, null, $operationId));
        $cycles = app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::class);
        if ($largeContext) {
            try {
                $cycles->apply($input);
                self::fail('An indivisible pathological source reached tokenization or wire.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_audit_context_requires_source_split', $exception->getMessage());
                self::assertCount(0, $httpHistory);
                self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
            }
        } elseif (! $enoughParts) {
            try {
                $cycles->apply($input);
                self::fail('The auditor expanded the approved part plan.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_work_items_exceed_approved_plan', $exception->getMessage());
                self::assertCount(0, $httpHistory);
                self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
            }
        } else {
            $first = $cycles->apply($input);
            self::assertSame('accepted', $first['audit']['status']);
            self::assertCount(3, $httpHistory);
            self::assertSame($first, $cycles->apply($input));
            self::assertCount(3, $httpHistory);
            self::assertSame(3, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
            self::assertSame(3, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        }
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
            ->abort($session->fresh(), $operationId, 'fixture_finished'));
    }

    public function test_global_work_ceiling_blocks_direct_composer_auditor_and_corrector_before_claim_callback_or_http(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:global-work-bound');
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $id = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation'));
        config(['ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key', 'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        $history = [];
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([]));
        $handler->push(\GuzzleHttp\Middleware::history($history));
        $wire = new \App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient(
            app(\App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore::class),
            httpClient: new \GuzzleHttp\Client(['handler' => $handler]));
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient::class, $wire);
        $items = array_map(static fn ($i): array => ['key' => 'work:'.$i, 'name' => 'Work '.$i, 'quantity' => null, 'unit' => 'm2'], range(1, 33));
        $draft = ['local_estimates' => [['key' => 'building', 'sections' => [['key' => 'works', 'work_items' => $items]]]]];
        $wireScope = new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope(0, null, $id);
        $audit = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput(
            (int) $organization->id, $scope->projectId, $scope->sessionId, str_repeat('a', 64), 0, [], [], $draft, [],
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit::PROMPT_CONTRACT, $wireScope);
        $correction = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionInput($audit, [['finding_id' => 'finding:missing']]);
        $candidates = array_map(static fn ($i): array => ['candidate_id' => 'candidate:'.$i, 'work_key' => 'work:'.$i,
            'name' => 'Work '.$i, 'unit' => null, 'quantity' => null, 'quantity_formula' => null,
            'source_fact_ids' => [], 'technology_package_candidate' => null], range(1, 33));
        $composer = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput(
            (int) $organization->id, $scope->projectId, $scope->sessionId, str_repeat('a', 64), [], [], [], $candidates, [],
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer::PROMPT_CONTRACT, $wireScope);
        $callback = static fn () => self::fail('A physical attempt callback must not run above the frozen work ceiling.');
        $calls = [
            fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::class)->apply($audit),
            fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit::class)->run($audit),
            fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditModel::class)->audit($audit, $callback),
            fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer::class)->runDetailed($composer),
            fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel::class)->compose($composer, $callback),
            fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection::class)->run($correction),
            fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionModel::class)->correct($correction, $callback),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('An over-limit role was admitted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_work_items_exceed_approved_plan', $exception->getMessage());
            }
        }
        self::assertCount(0, $history);
        self::assertSame(0, DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $id)->count());
        try {
            \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit::forOperation(
                (int) $organization->id + 1000, $scope->projectId, $scope->sessionId, $id);
            self::fail('Another organization resolved the approved work limit.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('estimate_work_approved_plan_missing', $exception->getMessage());
        }
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)->abort($session->fresh(), $id, 'fixture_finished'));
    }

    #[DataProvider('physicalComposerCeilings')]
    public function test_composer_filters_physical_duplicates_before_the_frozen_ceiling(bool $multipart, bool $duplicate): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:physical-composer-ceiling');
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['input_payload' => ['selected_sections' => $multipart ? ['roofing', 'structures', 'equipment'] : ['roofing']]]);
        $id = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation'));
        $limit = \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit::forOperation(
            (int) $organization->id, $scope->projectId, $scope->sessionId, $id);
        self::assertSame($multipart ? 96 : 32, $limit);
        $items = array_map(static fn (int $i): array => ['key' => 'work:'.$i, 'name' => 'Работа '.$i,
            'metadata' => ['scope_entity_id' => 'roof:1', 'composition_work_key' => $i === 1 ? 'roof_underlay' : 'work:'.$i]], range(1, $limit));
        $estimates = [['key' => 'building', 'sections' => [['key' => 'works', 'work_items' => $items]]]];
        $candidates = array_map(static fn (array $item): array => ['candidate_id' => 'candidate:'.$item['key'],
            'work_key' => $item['key'], 'name' => $item['name'], 'unit' => null, 'quantity' => null, 'quantity_formula' => null,
            'source_fact_ids' => ['fact:room'], 'technology_package_candidate' => null], $items);
        $input = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput(
            (int) $organization->id, $scope->projectId, $scope->sessionId, str_repeat('a', 64),
            [['id' => 'fact:room', 'entity_id' => 'roof:1', 'entity_type' => 'roof', 'type' => 'plan_area',
                'value' => '50', 'unit' => 'm2', 'status' => 'confirmed', 'origin' => 'document', 'evidence_ids' => ['proof:roof']]], [], [], $candidates, [],
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer::PROMPT_CONTRACT,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope(0, null, $id), allowedWorkSections: ['roofing']);
        $model = new class($duplicate) implements \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel
        {
            public int $calls = 0;

            public function __construct(private bool $duplicate) {}

            public function compose(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
            {
                $this->calls++;
                $onPhysicalAttemptReserved((string) Str::uuid());

                return ['work_intents' => [['kind' => 'supplementary', 'work_key' => $this->duplicate ? 'roof_underlay' : 'roof_covering',
                    'name' => 'Работа', 'entity_id' => 'roof:1', 'unit' => 'm2', 'work_section' => 'roofing',
                    'derived_quantity_id' => null, 'source_fact_ids' => ['fact:room'], 'technology_package_candidate' => null,
                    'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => []]]];
            }
        };
        $stored = [];
        $runs = \Mockery::mock(\App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository::class);
        $runs->allows('claim')->andReturnUsing(static function ($input, $owner) use (&$stored) {
            return isset($stored[$input->subjectVersion])
                ? new \App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim(1, 'replay', result: $stored[$input->subjectVersion])
                : new \App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim(count($stored) + 1, 'owned', $owner);
        });
        $versions = array_column((new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerBatchPlanner)->plan($input), 'batchKey');
        $runs->allows('startPhysicalAttempt')->andReturnNull();
        $runs->allows('complete')->andReturnUsing(static function ($runId, $owner, $result) use (&$stored, $versions, $input): void {
            $key = $versions[$runId - 1] === null ? $input->snapshotToken : hash('sha256', $input->snapshotToken.'|'.$versions[$runId - 1]);
            $stored[$key] = $result;
        });
        $composer = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer(
            $runs, $model, 'openai/gpt-6-luna', allowance: app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerAllowance::class));
        if ($duplicate) {
            $result = $composer->runDetailed($input, $estimates);
            self::assertCount($limit, $result['intents']);
            self::assertCount($multipart ? 3 : 1, $result['quarantined']);
            self::assertSame(['estimate_composer_physical_duplicate_covered'], array_values(array_unique(array_column($result['quarantined'], 'reason'))));
            self::assertSame(['work:1'], array_values(array_unique(array_column($result['quarantined'], 'covered_by_position_key'))));
            self::assertSame($result, $composer->runDetailed($input, $estimates));
            self::assertSame($multipart ? 3 : 1, $model->calls);
        } else {
            try {
                $composer->runDetailed($input, $estimates);
                self::fail('A unique supplementary work exceeded the frozen ceiling.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_work_items_exceed_approved_plan', $exception->getMessage());
                self::assertSame(1, $model->calls);
            }
        }
        self::assertSame($limit, \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit::forOperation(
            (int) $organization->id, $scope->projectId, $scope->sessionId, $id));
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)->abort($session->fresh(), $id, 'fixture_finished'));
    }

    public static function physicalComposerCeilings(): array
    {
        return ['single duplicate' => [false, true], 'multipart duplicate' => [true, true],
            'single unique overflow' => [false, false], 'multipart unique overflow' => [true, false]];
    }

    public function test_additions_in_two_audit_parts_share_one_frozen_work_ceiling_and_stop_before_the_next_audit(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:cross-part-work-bound');
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $id = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation'));
        $basis = ['key' => 'floor_area', 'amount' => '12.7500', 'unit' => 'м2', 'formula_key' => 'direct_floor_area',
            'formula_version' => 'v1', 'source' => 'evidenced', 'model_version' => 'test:v1', 'evidence_ids' => [],
            'assumptions' => [], 'review_blockers' => [], 'formula_inputs' => ['entity_id' => 'entity:room',
                'operands' => [['fact_id' => 'fact:floor-area', 'value' => '12.7500', 'unit' => 'm2']]]];
        $quantity = ['id' => 'quantity:floor', 'value' => '12.7500', 'unit' => 'м2', 'entity_id' => 'entity:room',
            'operand_fact_ids' => ['fact:floor-area'], 'formula_key' => 'direct_floor_area', 'source' => 'evidenced', 'quantity_basis' => $basis];
        $items = array_map(static fn ($i): array => ['key' => 'work:'.$i, 'name' => 'Work '.$i, 'entity_id' => 'entity:room',
            'description' => str_repeat('accepted input '.$i.' ', 10), 'quantity' => null, 'unit' => 'м2'], range(1, 31));
        $root = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput(
            (int) $organization->id, $scope->projectId, $scope->sessionId, str_repeat('a', 64), 0,
            [['id' => 'fact:floor-area', 'entity_id' => 'entity:room', 'value' => '12.7500', 'unit' => 'm2', 'status' => 'confirmed']], [$quantity],
            ['local_estimates' => [['key' => 'building', 'sections' => [['key' => 'works', 'work_items' => $items]]]]],
            [['fact_id' => 'fact:floor-area', 'locator' => ['document_id' => 7, 'page' => 2]]],
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit::PROMPT_CONTRACT,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope(0, null, $id));
        self::assertGreaterThan(1, count((new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditContextPlanner)->prepare($root, 4000)));
        $auditModel = new class implements \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditModel
        {
            public int $calls = 0;

            public function audit(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput $input, callable $onAttemptStarted): array
            {
                $this->calls++;
                if ($this->calls > 3) {
                    throw new \LogicException('An additional audit ran above the global work ceiling.');
                }
                $onAttemptStarted(sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $this->calls));
                if ($this->calls === 2) {
                    return ['accepted' => true, 'findings' => []];
                }

                return ['accepted' => false, 'findings' => [['finding_id' => 'missing:'.$this->calls, 'type' => 'omission',
                    'severity' => 'material', 'item_key' => null, 'source_fact_ids' => ['fact:floor-area'],
                    'source_locator' => ['document_id' => 7, 'page' => 2], 'reason' => 'В выбранном составе отсутствует работа пола.',
                    'impact' => 'Состав работ по помещению неполный.', 'recommendation' => 'Добавить работу по подтверждённой площади пола.',
                    'correction' => ['operation' => 'operator_review']]]];
            }
        };
        $correctionModel = new class implements \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionModel
        {
            public int $calls = 0;

            public function correct(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionInput $input, callable $onPhysicalAttemptReserved): array
            {
                $key = ['finish.floor', 'warehouse.floor_hardener'][$this->calls++];
                $onPhysicalAttemptReserved(sprintf('cccccccc-cccc-4ccc-8ccc-%012d', $this->calls));

                return ['corrections' => [['operation' => 'add_work', 'finding_id' => $input->findings[0]['finding_id'],
                    'work_key' => $key, 'name' => $key === 'finish.floor' ? 'Устройство покрытия пола' : 'Упрочнение покрытия пола',
                    'derived_quantity_id' => 'quantity:floor', 'source_fact_ids' => ['fact:floor-area']]]];
            }
        };
        $runs = new \Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;
        $cycles = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle(
            new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit($runs, $auditModel, 'openai/gpt-6-luna'),
            new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection($runs, $correctionModel, 'openai/gpt-6-luna'), 4000);
        try {
            $cycles->apply($root);
            self::fail('Two parts independently expanded the global work scope above 32.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('estimate_work_items_exceed_approved_plan', $exception->getMessage());
            self::assertSame(3, $auditModel->calls);
            self::assertSame(2, $correctionModel->calls);
        }
        self::assertSame(32, \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit::forOperation(
            (int) $organization->id, $scope->projectId, $scope->sessionId, $id));
        self::assertSame(0, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)->abort($session->fresh(), $id, 'fixture_finished'));
    }

    public static function auditPartitionApprovals(): array
    {
        return [[true], [false], [true, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('engineerPartitionApprovals')]
    public function test_real_engineer_candidate_identity_matches_the_paid_physical_attempt_and_replays_once(int $linkCount, bool $enoughParts, bool $complex = true, bool $largeContext = false): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['input_payload' => ['profile_id' => $complex ? 'industrial' : 'construction',
            'selected_sections' => $enoughParts ? ['structure', 'finishing'] : ['structure']]]);
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:engineer-identity');
        $operationId = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $operationId, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $operationId, $quote['quote_id'], 'generation'));
        $history = [];
        $version = 'sha256:'.str_repeat('a', 64);
        $facts = $links = [];
        for ($index = 0; $index < $linkCount; $index++) {
            foreach (['a', 'b'] as $suffix) {
                $facts[] = ['id' => 'fact:'.$index.':'.$suffix, 'source_version' => $version, 'current' => true];
            }
            $links[] = ['id' => 'link:room-'.$index, 'fact_ids' => ['fact:'.$index.':a', 'fact:'.$index.':b']];
        }
        if ($largeContext) {
            for ($index = 0; $index < 140; $index++) {
                $facts[] = ['id' => 'fact:unrelated:'.$index, 'source_version' => $version, 'current' => true,
                    'entity_id' => 'room:unrelated:'.$index, 'value' => str_repeat('x', 4500)];
            }
        }
        $parts = (new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisBatchPlanner)->plan($links, []);
        $responses = [];
        foreach ($parts as $part) {
            $content = json_encode(['accepted_link_ids' => array_column($part['links'], 'id'), 'question_conflict_ids' => []], JSON_THROW_ON_ERROR);
            $responses[] = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'chatcmpl-engineer', 'object' => 'chat.completion', 'created' => 1, 'model' => 'openai/gpt-6-luna',
                'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $content]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ], JSON_THROW_ON_ERROR));
        }
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));
        $handler->push(\GuzzleHttp\Middleware::history($history));
        config(['ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key', 'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient::class,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient(
                app(\App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory::class),
                app(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard::class),
                app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore::class),
                httpClient: new \GuzzleHttp\Client(['handler' => $handler])));
        $input = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInput(
            (int) $organization->id, $scope->projectId, $scope->sessionId, [$version],
            $facts,
            [], [], ['arbiter' => [], 'geometry_expert' => []],
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\RunProjectSynthesis::PROMPT_CONTRACT,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope(0, null, $operationId));
        $engineer = app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\RunProjectSynthesis::class);
        if (! $enoughParts) {
            try {
                $engineer->run($input, $links, []);
                self::fail('Unapproved engineer parts were sent.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('project_synthesis_parts_exceed_approved_plan', $exception->getMessage());
                self::assertCount(0, $history);
                self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
            }
        } else {
            $first = $engineer->run($input, $links, []);
            self::assertCount($linkCount, $first->acceptedLinkIds);
            self::assertSame($first->toArray(), $engineer->run($input, $links, [])->toArray());
            self::assertCount(count($parts), $history);
            self::assertSame(count($parts), DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
            self::assertSame(count($parts), DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
            if ($largeContext) {
                $sent = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $sentInput = json_decode($sent['messages'][1]['content'], true, 512, JSON_THROW_ON_ERROR)['project'];
                self::assertSame(142, $sentInput['context_manifest']['total_facts']);
                self::assertSame($input->fingerprint(), $sentInput['context_manifest']['parent_fingerprint']);
                self::assertSame(['fact:0:a', 'fact:0:b'], array_column($sentInput['facts'], 'id'));
            }
        }
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
            ->abort($session->fresh(), $operationId, 'fixture_finished'));
    }

    public static function engineerPartitionApprovals(): array
    {
        return [[1, true], [65, true], [65, false], [1, false, false], [1, true, true, true]];
    }

    public function test_an_unknown_legacy_paid_balance_keeps_legacy_billing_without_a_new_shared_reserve(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $policy = \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::class;
        self::assertTrue($policy::enabledForOrganization((int) $organization->id));
        $allocation = \App\Models\OrganizationResourceAllocation::query()->create(['organization_id' => $organization->id,
            'resource_slug' => 'extra_ai_estimates', 'limit_key' => 'ai_estimates_month', 'quantity' => 10,
            'source' => 'paid_addon', 'status' => 'active', 'period_start_at' => now()->subDay(), 'period_end_at' => now()->addDay(),
            'metadata' => ['commercial_order_id' => 'unknown-payment-origin']]);
        self::assertFalse($policy::enabledForOrganization((int) $organization->id));
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        try {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
                ->quote($actor, $request->projectId, $request->sessionId, 0, (string) Str::uuid(), 'generation');
            self::fail('An unresolved legacy balance was silently converted to shared billing.');
        } catch (\DomainException $exception) {
            self::assertSame('estimator_credit_legacy_balance_requires_reconciliation', $exception->getMessage());
        }
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame('active', $allocation->fresh()->status);
        self::assertSame('10.00', $allocation->fresh()->quantity);
        $allocation->update(['period_end_at' => now()->subSecond()]);
        self::assertTrue($policy::enabledForOrganization((int) $organization->id));
    }

    public function test_manual_generation_retry_requires_a_new_quote_and_replays_one_approved_operation(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $session->update(['status' => 'failed', 'resume_status' => 'generating']);
        $retry = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSession::class);
        $command = new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSessionCommand(
            (int) $session->id, (int) $organization->id, (int) $session->project_id, 0, $actor);
        try {
            $retry->handle($command);
            self::fail('An old client started paid retry without a quote.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
            self::assertSame('failed', $session->fresh()->status->value);
        }
        $credits = app(AICreditService::class);
        $credits->grant($organization, 1000000, 'purchase', null, 'test:approved-retry');
        $id = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'generation');
        $approved = new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSessionCommand(
            (int) $session->id, (int) $organization->id, (int) $session->project_id, 0, $actor, ['request_id' => $id, 'quote_id' => $quote['quote_id']]);
        $started = $retry->handle($approved);
        self::assertSame('generating', $started->status->value);
        self::assertSame($id, $started->input_payload['generation_attempt_id']);
        self::assertSame($id, $started->input_payload['generation_credit_operation_id']);
        $replayed = $retry->handle($approved);
        self::assertSame($started->state_version, $replayed->state_version);
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        \Illuminate\Support\Facades\Queue::assertPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class, 1);
    }

    public function test_a_failed_document_releases_the_operation_and_blocks_units_even_while_generation_is_pending(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $session->update(['status' => 'processing_documents']);
        $source = 'sha256:'.hash('sha256', 'failed-document-fixture');
        $lineage = (string) Str::uuid();
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $actor->id,
            'filename' => 'failed.pdf', 'mime_type' => 'application/pdf', 'storage_path' => 'fixture/private/failed.pdf',
            'checksum_sha256' => substr($source, 7), 'source_version' => $source, 'page_count' => 1, 'status' => 'queued',
            'processing_control_status' => 'active', 'processing_control_source_version' => $source,
            'processing_control_attempt_id' => $lineage, 'meta' => ['processing_attempt_id' => $lineage]]);
        $unit = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id, 'document_id' => $document->id,
            'unit_type' => 'pdf_page', 'unit_index' => 1, 'source_version' => $source, 'status' => 'pending',
            'locator' => ['source_kind' => 'pdf', 'source_version' => $source, 'coordinate_space' => 'pdf_page_pixels',
                'artifact_path' => 'fixture/private/failed/page.png', 'artifact_sha256' => 'sha256:'.hash('sha256', 'failed-fixture-page'), 'artifact_version_id' => 'fixture.v1'],
            'metadata' => ['processing_attempt_id' => $lineage]]);
        $credits = app(AICreditService::class);
        $credits->grant($organization, 1000000, 'purchase', null, 'test:failed-document');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation'));
        $binding = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCreditBinding::class);
        self::assertTrue($binding->canDispatch($unit->fresh()));
        $snapshot = \App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot::capture($session,
            'document_manifest', $lineage, (int) $document->id, $source);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\FailDocumentProcessing::class)->handle($snapshot, 'fixture_failure');
        self::assertSame('failed', $document->fresh()->status);
        self::assertSame('cancelled', $document->fresh()->processing_control_status);
        self::assertFalse($binding->canDispatch($unit->fresh()));
        self::assertArrayNotHasKey('credit_operation_id', $unit->fresh()->metadata);
        self::assertSame('failed', DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('status'));
        self::assertSame(0, (int) DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('consumed_units_minor'));
        self::assertSame(1000000, $credits->balance($organization)['available_minor']);
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('organization_id', $organization->id)->count());
    }

    public function test_service_expense_limit_is_independent_of_a_large_customer_balance(): void
    {
        [$organization] = $this->fixture();
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:expense-guard');
        config(['estimate-generation.credits.service_daily_expense_limit_rub' => '0.02']);
        try {
            DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorServiceExpenseGuard::class)
                ->admit((int) $organization->id, 30000));
            self::fail('The service expense ceiling was bypassed by a large user balance.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_credit_service_expense_limit_reached', $exception->reason);
        }
        self::assertSame(1000000, app(AICreditService::class)->balance($organization)['available_minor']);
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('organization_id', $organization->id)->count());
    }

    public function test_document_units_are_paused_until_the_exact_manifest_is_approved_and_mutation_invalidates_it(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $sourceVersion = 'sha256:'.hash('sha256', 'fixture-pdf');
        $lineage = (string) Str::uuid();
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'session_id' => $session->id, 'organization_id' => $organization->id, 'project_id' => $session->project_id, 'user_id' => $actor->id,
            'filename' => 'fixture.pdf', 'mime_type' => 'application/pdf', 'storage_path' => 'fixture/private.pdf',
            'checksum_sha256' => substr($sourceVersion, 7), 'source_version' => $sourceVersion, 'page_count' => 1, 'status' => 'queued',
            'processing_control_status' => 'active', 'processing_control_source_version' => $sourceVersion,
            'processing_control_attempt_id' => $lineage, 'meta' => ['processing_attempt_id' => $lineage]]);
        $unit = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id, 'document_id' => $document->id,
            'unit_type' => 'pdf_page', 'unit_index' => 1, 'source_version' => $sourceVersion, 'status' => 'pending',
            'locator' => ['source_kind' => 'pdf', 'source_version' => $sourceVersion, 'coordinate_space' => 'pdf_page_pixels',
                'artifact_path' => 'fixture/private/page.png', 'artifact_sha256' => 'sha256:'.hash('sha256', 'fixture-page'), 'artifact_version_id' => 'fixture.v1'],
            'metadata' => ['processing_attempt_id' => $lineage]]);
        $binding = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCreditBinding::class);
        self::assertFalse($binding->canDispatch($unit));
        $credits = app(AICreditService::class);
        $credits->grant($organization, 1000000, 'purchase', null, 'test:document-quote');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $requestId = (string) Str::uuid();
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, 0, $requestId, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $requestId, $quote['quote_id'], 'generation'));
        self::assertSame($requestId, $unit->fresh()->metadata['credit_operation_id']);
        self::assertTrue($binding->canDispatch($unit->fresh()));
        $metadata = $unit->fresh()->metadata;
        $metadata['processing_attempt_id'] = (string) Str::uuid();
        $unit->update(['metadata' => $metadata]);
        self::assertFalse($binding->canDispatch($unit->fresh()));
        $metadata['processing_attempt_id'] = $lineage;
        $unit->update(['metadata' => $metadata]);
        $locator = $unit->fresh()->locator;
        $locator['artifact_sha256'] = 'sha256:'.hash('sha256', 'different-page');
        $unit->update(['locator' => $locator]);
        self::assertFalse($binding->canDispatch($unit->fresh()));
        $locator['artifact_sha256'] = 'sha256:'.hash('sha256', 'fixture-page');
        $unit->update(['locator' => $locator, 'status' => 'running', 'claim_token' => (string) Str::uuid(),
            'attempt_count' => 1, 'lease_expires_at' => now()->addMinutes(5), 'started_at' => now()]);
        config(['estimate-generation.ocr.timeweb.api_key' => 'fixture-key',
            'estimate-generation.ocr.timeweb.base_uri' => 'https://fixture.invalid/v1']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(function () use ($actor, $session) {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession::class)
                ->handle($session->fresh(), 0, \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent::Cancelled, $actor);

            return \Illuminate\Support\Facades\Http::response(['model' => 'openai/gpt-6-luna',
                'choices' => [['message' => ['content' => '{"pages":[{"page_number":1,"text":"fixture"}]}']]],
                'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100]], 200);
        });
        $correlationId = (string) Str::uuid();
        $context = new AiOperationContext($correlationId, (string) Str::uuid(), (int) $organization->id, (int) $session->project_id,
            (int) $session->id, 'understand_documents', 'ocr', 1, documentId: (int) $document->id, unitId: (int) $unit->id,
            processingLineageId: $lineage, creditOperationId: $requestId);
        $recognition = app(\App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface::class)
            ->recognize(new \App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput('fixture-image', 'image/png', 'fixture.png', 1, $context));
        self::assertSame('openai/gpt-6-luna', $recognition->model);
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        self::assertSame('cancelled', $session->fresh()->status->value);
        self::assertSame('cancelled', DB::table('estimate_generation_credit_operations')->where('operation_id', $requestId)->value('status'));
        self::assertSame(1, DB::table('ai_credit_provider_usages')->where('organization_id', $organization->id)->count());
        $usage = \App\Models\Credits\AICreditProviderUsage::query()->where('organization_id', $organization->id)->firstOrFail();
        self::assertSame(20250, $usage->cost_micro_rub);
        self::assertTrue($usage->metadata['late_provider_result']);
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
            ->abort($session, $requestId));
        self::assertSame(1000000, $credits->balance($organization)['available_minor']);
    }

    public function test_server_quote_is_scoped_idempotent_and_start_reserve_is_atomic(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $credits = app(AICreditService::class);
        $credits->grant($organization, 1000000, 'purchase', null, 'test:server-quote');
        $requestId = (string) Str::uuid();
        $quote = $operations->quote($actor, $request->projectId, $request->sessionId, 0, $requestId, 'generation');
        self::assertSame($quote, $operations->quote($actor, $request->projectId, $request->sessionId, 0, $requestId, 'generation'));
        self::assertSame('openai/gpt-6-luna', $quote['model']);
        self::assertGreaterThan(0, $quote['maximum_calls']);
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        try {
            DB::transaction(function () use ($operations, $actor, $session, $quote, $requestId): void {
                $operations->begin($actor, $session, 0, $requestId, $quote['quote_id'], 'generation');
                throw new \RuntimeException('forced_start_rollback');
            });
            self::fail('The simulated rollback was lost.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced_start_rollback', $exception->getMessage());
        }
        self::assertSame('quoted', DB::table('estimate_generation_credit_operations')->where('operation_id', $requestId)->value('status'));
        self::assertSame(0, DB::table('ai_credit_reservations')->where('request_id', 'estimator:generation:'.$requestId)->count());
        self::assertSame($requestId, DB::transaction(fn () => $operations->begin($actor, $session, 0, $requestId, $quote['quote_id'], 'generation')));
        self::assertSame($requestId, DB::transaction(fn () => $operations->begin($actor, $session, 0, $requestId, $quote['quote_id'], 'generation')));
        self::assertSame(1, DB::table('ai_credit_reservations')->where('request_id', 'estimator:generation:'.$requestId)->count());
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $requestId)->first();
        $reservation = \App\Models\Credits\AICreditReservation::query()->findOrFail($operation->reservation_id);
        $credits->cancel($reservation);
        self::assertSame(1000000, $credits->balance($organization)['available_minor']);
    }

    public function test_a_valid_saved_v1_quote_replays_its_exact_terms_under_the_new_v2_builder(): void
    {
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $id = (string) Str::uuid();
        $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        $row = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        $plan = json_decode($row->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        $plan['contract_version'] = 'estimator-processing-plan:v1';
        unset($plan['work_ceiling_policy'], $plan['document_costs'], $plan['generation_cycle_preflight']);
        unset($plan['text_payload_policy']['audit_draft_view']);
        $plan['known_context'] = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorKnownContextSnapshot::class)
            ->capture(EstimateGenerationSession::query()->findOrFail($scope->sessionId), (bool) $plan['complex'], $plan['text_payload_policy']);
        $plan['audit_part_limit'] = 1;
        $plan['roles']['estimate_composition']['max_calls'] = 3;
        $plan['roles']['estimate_audit']['max_calls'] = 9;
        $plan['roles']['estimate_composer_correction']['max_calls'] = 6;
        $plan['maximum_calls'] = array_sum(array_column($plan['roles'], 'max_calls'));
        $plan['maximum_provider_cost_micro_rub'] = array_sum(array_map(static fn ($role): int => $role['maximum_cost_micro_rub'] * $role['max_calls'], $plan['roles']));
        $identity = json_decode($row->credit_request, true, 64, JSON_THROW_ON_ERROR);
        $id = (string) Str::uuid();
        $identity['operation_id'] = $id;
        $identity['processing_plan_hash'] = hash('sha256', CanonicalPipelineJson::encode($plan));
        $identity['maximum_calls'] = $plan['maximum_calls'];
        $identity['maximum_provider_cost_micro_rub'] = $plan['maximum_provider_cost_micro_rub'];
        $request = new EstimatorCreditRequest($identity['organization_id'], $identity['project_id'], $identity['session_id'],
            $identity['actor_user_id'], $identity['operation_id'], $identity['operation_kind'], $identity['state_version'],
            $identity['evaluation_input_hash'], $identity['source_input_version'], $identity['manifest_hash'],
            $identity['processing_plan_hash'], $identity['maximum_provider_cost_micro_rub'], $identity['maximum_calls'], $identity['supplier_tariff']);
        $legacyQuote = app(AICreditService::class)->quoteEstimator($organization, $actor, $request);
        $quote = \App\Models\Credits\AICreditQuote::query()->where('public_id', $legacyQuote['quote_id'])->sole();
        $legacyRow = (array) $row;
        unset($legacyRow['id']);
        DB::table('estimate_generation_credit_operations')->insert([...$legacyRow, 'operation_id' => $id,
            'processing_plan' => CanonicalPipelineJson::encode($plan), 'credit_request' => CanonicalPipelineJson::encode($identity),
            'quote_id' => $quote->id, 'plan_hash' => $identity['processing_plan_hash'],
            'maximum_calls' => $request->maximumCalls, 'maximum_provider_cost_micro_rub' => $request->maximumProviderCostMicroRub,
        ]);
        $before = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        $quoteCount = DB::table('ai_credit_quotes')->count();
        config(['ai-assistant-credits.price_version' => 99]);
        $replay = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        self::assertSame($legacyQuote['quote_id'], $replay['quote_id']);
        self::assertSame($legacyQuote['max_units_minor'], $replay['max_units_minor']);
        self::assertSame($legacyQuote['price_version'], $replay['price_version']);
        self::assertSame($legacyQuote['expires_at'], $replay['expires_at']);
        self::assertSame(18, $replay['maximum_calls']);
        self::assertEquals($before, DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole());
        self::assertSame($quoteCount, DB::table('ai_credit_quotes')->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        $this->travelTo($quote->expires_at->addSecond());
        try {
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('estimator_credit_quote_expired');
            $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        } finally {
            $this->travelBack();
        }
    }

    public function test_unknown_actual_model_is_not_billed_and_historical_receipts_are_frozen_on_replay(): void
    {
        foreach ([null, 'openai/gpt-6-sol', 'historical'] as $case) {
            [$organization, $actor, $request] = $this->fixture();
            $credits = app(AICreditService::class);
            $credits->grant($organization, 10000, 'purchase', null, 'test:physical-wallet');
            $quote = $credits->quoteEstimator($organization, $actor, $request);
            $reservation = $credits->beginEstimator($organization, $actor, $quote['quote_id'], $request);
            DB::table('estimate_generation_credit_operations')->insert(['operation_id' => $request->operationId,
                'organization_id' => $request->organizationId, 'project_id' => $request->projectId, 'session_id' => $request->sessionId,
                'actor_user_id' => $actor->id, 'kind' => $request->kind, 'status' => 'reserved', 'approved_state_version' => $request->stateVersion,
                'input_hash' => $request->inputHash, 'source_version' => $request->sourceVersion, 'manifest_hash' => $request->manifestHash,
                'plan_hash' => $request->planHash, 'processing_plan' => CanonicalPipelineJson::encode($this->plan()),
                'credit_request' => CanonicalPipelineJson::encode($request->identity()), 'quote_id' => $reservation->ai_credit_quote_id,
                'reservation_id' => $reservation->id, 'maximum_provider_cost_micro_rub' => $request->maximumProviderCostMicroRub,
                'maximum_calls' => $request->maximumCalls, 'created_at' => now(), 'updated_at' => now()]);
            $attemptId = (string) Str::uuid();
            $admission = app(EstimatorCreditAttemptAdmission::class);
            $arguments = [$request->operationId, $attemptId, $request->organizationId, $request->projectId, $request->sessionId,
                'estimate_composition', new AiCost('0.030000', 'RUB', 'available')];
            DB::transaction(fn () => $admission->admit(...$arguments));
            $context = new AiOperationContext((string) Str::uuid(), $attemptId, $request->organizationId,
                $request->projectId, $request->sessionId, 'plan_work_items', 'estimate_composition', 1,
                creditOperationId: $request->operationId);
            $usage = new AiUsageData($context, 'timeweb', 'openai/gpt-6-luna', 'malformed_response', 10,
                reportedModel: $case === 'historical' ? null : $case, usageStatus: 'measured',
                inputTokens: 1000, outputTokens: 100, priceSnapshot: AiPriceSnapshot::fromArray($request->supplierTariff));
            if ($case === 'historical') {
                // Seed an already persisted legacy receipt with the old available-cost policy.
                $seedAttempt = (string) Str::uuid();
                $legacyContext = new AiOperationContext($context->correlationId, $seedAttempt, $request->organizationId,
                    $request->projectId, $request->sessionId, 'plan_work_items', 'estimate_composition', 1);
                $legacyUsage = new AiUsageData($legacyContext, 'timeweb', 'openai/gpt-6-luna', 'malformed_response', 10,
                    reportedModel: 'openai/gpt-6-luna', usageStatus: 'measured', inputTokens: 1000, outputTokens: 100,
                    priceSnapshot: AiPriceSnapshot::fromArray($request->supplierTariff));
                app(EloquentAiUsageStore::class)->record($legacyUsage);
                $historical = (array) DB::table('estimate_generation_ai_usage')->where('attempt_id', $seedAttempt)->sole();
                unset($historical['id']);
                DB::table('estimate_generation_ai_usage')->insert(array_replace($historical, [
                    'attempt_id' => $attemptId,
                    'immutable_fingerprint' => $usage->immutableFingerprint, 'reported_model' => null,
                    'cost_amount' => '0.123456', 'currency' => 'RUB', 'pricing_status' => 'available',
                ]));
            }
            $store = app(EloquentAiUsageStore::class);
            $store->record($usage);
            $store->record($usage);
            $row = DB::table('estimate_generation_ai_usage')->where('attempt_id', $attemptId)->sole();
            self::assertSame(1000, (int) $row->input_tokens);
            self::assertSame('measured', $row->usage_status);
            self::assertSame($case === 'historical' ? 'available' : 'unavailable', $row->pricing_status);
            self::assertSame($case === 'historical' ? '0.12345600' : null, $row->cost_amount);
            $receipt = DB::table('ai_credit_provider_usages')->where('usage_key', $attemptId)->sole();
            self::assertSame($case === 'historical' ? 123456 : 0, (int) $receipt->cost_micro_rub);
            self::assertFalse((bool) $receipt->is_successful);
            self::assertSame($case === 'historical', json_decode($receipt->metadata, true, flags: JSON_THROW_ON_ERROR)['cost_available']);
            self::assertSame($case === 'historical' ? 'recorded' : 'unavailable',
                DB::table('estimate_generation_credit_attempts')->where('attempt_id', $attemptId)->value('status'));
            self::assertSame('running', DB::table('estimate_generation_credit_operations')
                ->where('operation_id', $request->operationId)->value('status'));
        }
    }

    public function test_an_approved_legacy_vision_ceiling_remains_scoped_and_admissible_after_configuration_changes(): void
    {
        [$organization, $actor, $base] = $this->fixture();
        $plan = ['roles' => ['vision' => ['max_calls' => 1, 'input_ceiling' => 32768,
            'output_ceiling' => 4096, 'maximum_cost_micro_rub' => 732672]]];
        $encoded = CanonicalPipelineJson::encode($plan);
        $request = new EstimatorCreditRequest($base->organizationId, $base->projectId, $base->sessionId, (int) $actor->id,
            $base->operationId, 'documents', 0, $base->inputHash, $base->sourceVersion, $base->manifestHash,
            hash('sha256', $encoded), 732672, 1, $base->supplierTariff);
        $credits = app(AICreditService::class);
        $credits->grant($organization, 10000, 'purchase', null, 'test:legacy-vision');
        $quote = $credits->quoteEstimator($organization, $actor, $request);
        $reservation = $credits->beginEstimator($organization, $actor, $quote['quote_id'], $request);
        DB::table('estimate_generation_credit_operations')->insert(['operation_id' => $request->operationId,
            'organization_id' => $request->organizationId, 'project_id' => $request->projectId, 'session_id' => $request->sessionId,
            'actor_user_id' => $actor->id, 'kind' => $request->kind, 'status' => 'reserved', 'approved_state_version' => 0,
            'input_hash' => $request->inputHash, 'source_version' => $request->sourceVersion, 'manifest_hash' => $request->manifestHash,
            'plan_hash' => $request->planHash, 'processing_plan' => $encoded,
            'credit_request' => CanonicalPipelineJson::encode($request->identity()), 'quote_id' => $reservation->ai_credit_quote_id,
            'reservation_id' => $reservation->id, 'maximum_provider_cost_micro_rub' => 732672,
            'maximum_calls' => 1, 'created_at' => now(), 'updated_at' => now()]);
        config()->set('estimate-generation.vision.primary_max_output_tokens', 16384);
        $context = new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext(
            (string) Str::uuid(), (string) Str::uuid(), $request->organizationId, $request->projectId, $request->sessionId,
            'understand_documents', 'vision', 1, creditOperationId: $request->operationId);
        self::assertSame(4096, \App\BusinessModules\Addons\EstimateGeneration\Settings\VisionOutputTokenPolicy::forOperation($context));
        self::assertSame($reservation->id, DB::transaction(fn () => app(EstimatorCreditAttemptAdmission::class)->admit(
            $request->operationId, $context->attemptId, $request->organizationId, $request->projectId, $request->sessionId,
            'vision', new AiCost('0.71884800', 'RUB', 'available'))));
        $foreign = new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext(
            $context->correlationId, $context->attemptId, $request->organizationId + 100000, $request->projectId, $request->sessionId,
            'understand_documents', 'vision', 1, creditOperationId: $request->operationId);
        try {
            \App\BusinessModules\Addons\EstimateGeneration\Settings\VisionOutputTokenPolicy::forOperation($foreign);
            self::fail('A foreign organization read the approved ceiling.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation $exception) {
            self::assertSame('vision_approved_plan_missing', $exception->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('estimate_generation_credit_operations')->where('operation_id', $request->operationId)
                ->update(['plan_hash' => str_repeat('0', 64)]));
            self::fail('The approved plan identity changed.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('credit_operation_identity_is_immutable', $exception->getMessage());
        }
        self::assertSame($request->planHash, DB::table('estimate_generation_credit_operations')->where('operation_id', $request->operationId)->value('plan_hash'));
    }

    public function test_a_physical_call_is_bound_to_one_reserved_operation_and_a_rollback_does_not_consume_its_slot(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $credits = app(AICreditService::class);
        $credits->grant($organization, 10000, 'purchase', null, 'test:physical-wallet');
        $quote = $credits->quoteEstimator($organization, $actor, $request);
        $reservation = $credits->beginEstimator($organization, $actor, $quote['quote_id'], $request);
        DB::table('estimate_generation_credit_operations')->insert(['operation_id' => $request->operationId,
            'organization_id' => $request->organizationId, 'project_id' => $request->projectId, 'session_id' => $request->sessionId,
            'actor_user_id' => $actor->id, 'kind' => $request->kind, 'status' => 'reserved', 'approved_state_version' => $request->stateVersion,
            'input_hash' => $request->inputHash, 'source_version' => $request->sourceVersion, 'manifest_hash' => $request->manifestHash,
            'plan_hash' => $request->planHash, 'processing_plan' => CanonicalPipelineJson::encode($this->plan()),
            'credit_request' => CanonicalPipelineJson::encode($request->identity()), 'quote_id' => $reservation->ai_credit_quote_id,
            'reservation_id' => $reservation->id, 'maximum_provider_cost_micro_rub' => $request->maximumProviderCostMicroRub,
            'maximum_calls' => $request->maximumCalls, 'created_at' => now(), 'updated_at' => now()]);
        $attemptId = (string) Str::uuid();
        $admission = app(EstimatorCreditAttemptAdmission::class);
        $arguments = [$request->operationId, $attemptId, $request->organizationId, $request->projectId, $request->sessionId,
            'estimate_composition', new AiCost('0.030000', 'RUB', 'available')];
        $overBudget = $arguments;
        $overBudget[6] = new AiCost('1.800001', 'RUB', 'available');
        try {
            DB::transaction(fn () => $admission->admit(...$overBudget));
            self::fail('A physical call exceeded the frozen role ceiling.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_credit_call_not_planned', $exception->getMessage());
        }
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $request->operationId)->count());
        try {
            DB::transaction(function () use ($admission, $arguments): void {
                $admission->admit(...$arguments);
                throw new \RuntimeException('forced_pre_wire_refusal');
            });
            self::fail('Expected the simulated pre-wire refusal.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced_pre_wire_refusal', $exception->getMessage());
        }
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $request->operationId)->count());
        $catalog = app(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog::class);
        $changedTariff = $request->supplierTariff;
        $changedTariff['version'] = 'changed-after-approval';
        $changedCatalog = new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog([
            'estimate_composition' => ['timeweb' => ['openai/gpt-6-luna' => [$changedTariff]]],
        ]);
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog::class, $changedCatalog);
        try {
            DB::transaction(fn () => $admission->admit(...$arguments));
            self::fail('A changed supplier tariff was sent without new approval.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_credit_supplier_tariff_changed', $exception->reason);
            self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $request->operationId)->count());
        } finally {
            $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog::class, $catalog);
        }
        self::assertSame((int) $reservation->id, DB::transaction(fn () => $admission->admit(...$arguments)));
        self::assertSame(1, DB::table('estimate_generation_credit_attempts')->where('operation_id', $request->operationId)->count());
        $usage = new AiUsageData(new AiOperationContext((string) Str::uuid(), $attemptId, $request->organizationId,
            $request->projectId, $request->sessionId, 'plan_work_items', 'estimate_composition', 1, creditOperationId: $request->operationId),
            'timeweb', 'openai/gpt-6-luna', 'succeeded', 10, reportedModel: 'openai/gpt-6-luna', usageStatus: 'measured', inputTokens: 1000, outputTokens: 100,
            reasoningTokens: 20, priceSnapshot: AiPriceSnapshot::fromArray($request->supplierTariff));
        $store = app(EloquentAiUsageStore::class);
        $store->record($usage);
        $store->record($usage);
        self::assertSame(1, DB::table('ai_credit_provider_usages')->where('usage_key', $attemptId)->count());
        self::assertSame(20250, (int) DB::table('ai_credit_provider_usages')->where('usage_key', $attemptId)->value('cost_micro_rub'));
        self::assertSame('recorded', DB::table('estimate_generation_credit_attempts')->where('attempt_id', $attemptId)->value('status'));
        self::assertSame(substr($usage->immutableFingerprint, 7), DB::table('estimate_generation_credit_attempts')->where('attempt_id', $attemptId)->value('usage_fingerprint'));
        $badContext = new AiOperationContext((string) Str::uuid(), (string) Str::uuid(), $request->organizationId,
            $request->projectId, $request->sessionId, 'plan_work_items', 'estimate_composition', 1, creditOperationId: $request->operationId);
        try {
            $store->record(new AiUsageData($badContext, 'timeweb', 'openai/gpt-6-luna', 'succeeded', 10,
                usageStatus: 'measured', inputTokens: 1, priceSnapshot: AiPriceSnapshot::fromArray($request->supplierTariff)));
            self::fail('A non-admitted physical call entered the shared wallet.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation) {
            self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('attempt_id', $badContext->attemptId)->count());
        }
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $settlement = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class);
        $knownItem = ['key' => 'work:known', 'quantity' => '3', 'quantity_evidence' => ['source_type' => 'user_input']];
        $draft = ['local_estimates' => [['sections' => [['work_items' => [$knownItem]]]]]];
        $draft['source_input_version'] = $request->sourceVersion;
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft);
        try {
            DB::transaction(function () use ($settlement, $session, $request, $draft): void {
                $session->update(['draft_payload' => $draft]);
                $settlement->publish($session, $request->operationId, $draft);
                throw new \RuntimeException('forced_publication_rollback');
            });
            self::fail('The simulated publication rollback was lost.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced_publication_rollback', $exception->getMessage());
        }
        self::assertNull($session->fresh()->draft_payload);
        self::assertSame('reserved', $reservation->fresh()->status);
        self::assertSame('running', DB::table('estimate_generation_credit_operations')->where('operation_id', $request->operationId)->value('status'));
        $receipt = DB::transaction(fn () => $settlement->publish($session->fresh(), $request->operationId, $draft));
        self::assertSame(50, $receipt['consumed_units_minor']);
        self::assertEquals($receipt, DB::transaction(fn () => $settlement->publish($session->fresh(), $request->operationId, $draft)));
        // A known settled receipt can be redelivered without creating another supplier expense.
        $store->record($usage);
        self::assertSame(1, DB::table('ai_credit_provider_usages')->where('usage_key', $attemptId)->count());
        $arguments[1] = (string) Str::uuid();
        $this->expectException(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached::class);
        DB::transaction(fn () => $admission->admit(...$arguments));
    }

    public function test_estimator_reserves_the_shared_wallet_even_when_assistant_enforcement_is_disabled(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $credits = app(AICreditService::class);
        $credits->grant($organization, 10000, 'purchase', null, 'test:shared-wallet');
        $quote = $credits->quoteEstimator($organization, $actor, $request);
        self::assertSame($quote, $credits->quoteEstimator($organization, $actor, $request));
        $reservation = $credits->beginEstimator($organization, $actor, $quote['quote_id'], $request);
        self::assertGreaterThan(0, $reservation->reserved_minor);
        self::assertSame($quote['max_units_minor'], $reservation->reserved_minor);
        self::assertSame($reservation->id, $credits->beginEstimator($organization, $actor, $quote['quote_id'], $request)->id);
        $credits->recordProviderCost($reservation, 6075, 'timeweb', 'openai/gpt-6-luna', 'estimate_composer', ['usage_key' => 'physical:1'], true);
        $credits->recordProviderCost($reservation, 6075, 'timeweb', 'openai/gpt-6-luna', 'estimate_composer', ['usage_key' => 'physical:1'], true);
        self::assertSame(50, $credits->finalize($reservation, useful: true));
        self::assertSame(50, $credits->finalize($reservation, useful: true));
        self::assertSame(9950, $credits->balance($organization)['available_minor']);
    }

    public function test_questions_only_do_not_charge_even_a_successful_provider_call(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $credits = app(AICreditService::class);
        $credits->grant($organization, 10000, 'purchase', null, 'test:question-wallet');
        $quote = $credits->quoteEstimator($organization, $actor, $request);
        $reservation = $credits->beginEstimator($organization, $actor, $quote['quote_id'], $request);
        $credits->recordProviderCost($reservation, 6075, 'timeweb', 'openai/gpt-6-luna', 'estimate_composer', ['usage_key' => 'physical:1'], true);
        self::assertSame(0, $credits->finalize($reservation, useful: false));
        self::assertSame(10000, $credits->balance($organization)['available_minor']);
    }

    public function test_quote_for_other_inputs_cannot_reserve_and_revoked_membership_is_checked_again(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $credits = app(AICreditService::class);
        $credits->grant($organization, 10000, 'purchase', null, 'test:identity-wallet');
        $quote = $credits->quoteEstimator($organization, $actor, $request);
        $changed = new EstimatorCreditRequest($request->organizationId, $request->projectId, $request->sessionId, $request->actorUserId,
            $request->operationId, $request->kind, $request->stateVersion, str_repeat('b', 64), $request->sourceVersion,
            $request->manifestHash, $request->planHash, $request->maximumProviderCostMicroRub, $request->maximumCalls, $request->supplierTariff);
        try {
            $credits->beginEstimator($organization, $actor, $quote['quote_id'], $changed);
            self::fail('A quote was used for different estimator inputs.');
        } catch (\DomainException) {
            self::assertSame(10000, $credits->balance($organization)['available_minor']);
        }
        $actor->organizations()->updateExistingPivot($organization->id, ['is_active' => false]);
        $this->expectException(\DomainException::class);
        $credits->beginEstimator($organization, $actor, $quote['quote_id'], $request);
    }

    public function test_section_rebuild_requires_its_own_approved_target_and_replays_without_another_reserve(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $draft = ['source_input_version' => 'sha256:'.app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session),
            'local_estimates' => [['key' => 'roof:level2', 'title' => 'Roof', 'sections' => []], ['key' => 'walls:level1', 'title' => 'Walls', 'sections' => []]]];
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft);
        $session->update(['status' => 'estimate_review_required', 'draft_payload' => (new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft)->seal($draft)]);
        $rebuild = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RebuildGeneratedSection::class);
        try {
            $rebuild->handle($session, 0, 'roof:level2', $actor);
            self::fail('Rebuild started without an approved quote.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        }
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:approved-rebuild');
        $id = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'rebuild', ['local_estimate_key' => 'roof:level2']);
        $approval = ['request_id' => $id, 'quote_id' => $quote['quote_id']];
        try {
            $rebuild->handle($session, 0, 'walls:level1', $actor, $approval);
            self::fail('A rebuild quote was accepted for another section.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
            self::assertSame(0, $session->fresh()->state_version);
        }
        $started = $rebuild->handle($session, 0, 'roof:level2', $actor, $approval);
        self::assertSame('generating', $started->status->value);
        self::assertSame($id, $started->input_payload['generation_credit_operation_id']);
        self::assertSame('roof:level2', $started->input_payload['rebuild_section_key']);
        $replayed = $rebuild->handle($session, 0, 'roof:level2', $actor, $approval);
        self::assertSame($started->state_version, $replayed->state_version);
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        \Illuminate\Support\Facades\Queue::assertPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class, 1);
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('organization_id', $organization->id)->count());
    }

    public function test_native_spreadsheet_dispatch_is_free_but_ocr_fallback_still_requires_an_approved_operation(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $source = 'sha256:'.hash('sha256', 'native-queue-fixture');
        $lineage = (string) Str::uuid();
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $actor->id,
            'filename' => 'quantities.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'storage_path' => 'fixture/private/native.xlsx', 'checksum_sha256' => substr($source, 7), 'source_version' => $source,
            'status' => 'queued', 'processing_control_status' => 'active', 'processing_control_source_version' => $source,
            'processing_control_attempt_id' => $lineage, 'meta' => ['processing_attempt_id' => $lineage]]);
        $base = ['organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id,
            'document_id' => $document->id, 'unit_type' => 'spreadsheet_sheet', 'source_version' => $source, 'status' => 'pending',
            'metadata' => ['processing_attempt_id' => $lineage]];
        $locator = ['source_kind' => 'spreadsheet', 'source_version' => $source, 'coordinate_space' => 'spreadsheet_cells',
            'artifact_path' => 'fixture/private/sheet.json', 'artifact_sha256' => 'sha256:'.hash('sha256', 'native-artifact'),
            'artifact_version_id' => 'fixture.v1', 'content_type' => 'application/json', 'artifact_kind' => 'spreadsheet_sheet', 'artifact_schema_version' => 1];
        $native = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create($base + ['unit_index' => 1, 'locator' => $locator]);
        $fallback = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create($base + ['unit_index' => 2,
            'locator' => array_replace($locator, ['content_type' => 'image/png', 'artifact_path' => 'fixture/private/sheet.png'])]);
        $binding = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCreditBinding::class);
        self::assertTrue($binding->canDispatch($native));
        self::assertFalse($binding->canDispatch($fallback));
        $store = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchStore::class);
        $due = $store->dueForDocument((int) $document->id, $source, new \DateTimeImmutable, 16);
        self::assertSame([(int) $native->id], array_map(static fn ($candidate): int => $candidate->unitId, $due));
        $dispatched = 0;
        self::assertTrue($store->dispatchIfAllowed($due[0], new \DateTimeImmutable, (new \DateTimeImmutable)->modify('+300 seconds'),
            static function () use (&$dispatched): void {
                $dispatched++;
            }));
        self::assertSame(1, $dispatched);
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_confirmed_free_input_can_start_one_approved_generation_and_only_clears_the_payment_planning_blocker(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $session->update(['status' => 'input_review_required', 'input_payload' => ['description' => 'Ремонт помещения: сначала подтвердить состав и размеры',
            'generation_mode' => 'ai_assisted', 'evaluation_mode' => 'universal', 'profile_id' => 'renovation', 'price_policy' => 'catalog',
            'selected_sections' => ['finishing'], 'planning_review' => ['status' => 'blocked', 'limitations' => ['planning_requires_paid_approval', 'unresolved_scope']]]]);
        $confirmed = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession::class)
            ->handle($session, 0, \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent::InputConfirmed, $actor);
        self::assertSame('ready_to_generate', $confirmed->status->value);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:confirmed-input');
        $id = (string) Str::uuid();
        $quote = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
            ->quote($actor, (int) $session->project_id, (int) $session->id, $confirmed->state_version, $id, 'generation');
        $result = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration::class)
            ->handle($confirmed, $confirmed->state_version, 'ai_assisted', $actor, creditApproval: ['request_id' => $id, 'quote_id' => $quote['quote_id']]);
        self::assertTrue($result->successful);
        self::assertSame('generating', $result->session->status->value);
        self::assertSame(['unresolved_scope'], $result->session->input_payload['planning_review']['limitations']);
        self::assertSame('blocked', $result->session->input_payload['planning_review']['status']);
        self::assertSame($id, $result->session->input_payload['generation_credit_operation_id']);
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        \Illuminate\Support\Facades\Queue::assertPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class, 1);
    }

    public function test_free_document_completion_stops_before_unapproved_project_planning(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $session->update(['status' => 'processing_documents']);
        $readiness = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService::class);
        $readiness->method('evaluate')->willReturn(['can_generate' => true, 'summary' => ['pending_count' => 0]]);
        $reconcile = new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReconcileEstimateGenerationDocuments(
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration::class), $readiness,
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningPipeline::class));
        $completed = DB::transaction(fn () => $reconcile->reconcile($session));
        self::assertSame('input_review_required', $completed->status->value);
        self::assertSame('project_planning_requires_approval', $completed->failure_code);
        self::assertSame(['planning_requires_paid_approval'], $completed->input_payload['planning_review']['limitations']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
    }

    public function test_documents_only_operation_reserves_one_manifest_replays_and_completes_without_charging_or_generating(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $source = 'sha256:'.hash('sha256', 'documents-only-fixture');
        $lineage = (string) Str::uuid();
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $actor->id,
            'filename' => 'room.png', 'mime_type' => 'image/png', 'storage_path' => 'fixture/private/room.png',
            'checksum_sha256' => substr($source, 7), 'source_version' => $source, 'page_count' => 1, 'status' => 'queued',
            'processing_control_status' => 'active', 'processing_control_source_version' => $source,
            'processing_control_attempt_id' => $lineage, 'meta' => ['processing_attempt_id' => $lineage]]);
        $unit = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id,
            'document_id' => $document->id, 'unit_type' => 'raster_image', 'unit_index' => 1, 'source_version' => $source, 'status' => 'pending',
            'locator' => ['source_kind' => 'image', 'source_version' => $source, 'coordinate_space' => 'image_pixels',
                'artifact_path' => 'fixture/private/room.png', 'artifact_sha256' => 'sha256:'.hash('sha256', 'room-artifact'),
                'artifact_version_id' => 'fixture.v1', 'content_type' => 'image/png'], 'metadata' => ['processing_attempt_id' => $lineage]]);
        $starter = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\StartEstimatorDocumentOperation::class);
        $ignored = $document->replicate();
        $ignored->fill(['filename' => 'ignored.png', 'source_version' => 'sha256:'.hash('sha256', 'ignored-document'),
            'checksum_sha256' => hash('sha256', 'ignored-document'), 'status' => 'ignored'])->save();
        try {
            $starter->handle($actor, (int) $session->project_id, (int) $session->id, ['state_version' => 0]);
            self::fail('An unapproved document operation was started');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        }
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:documents-only');
        $id = (string) Str::uuid();
        $quote = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
            ->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'documents');
        self::assertSame(0, $quote['min_units_minor']);
        self::assertGreaterThan(0, $quote['max_units_minor']);
        $cached = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
            ->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'documents');
        self::assertSame($quote['quote_id'], $cached['quote_id']);
        self::assertSame(0, $cached['min_units_minor']);
        $approval = ['request_id' => $id, 'quote_id' => $quote['quote_id'], 'state_version' => 0];
        $started = $starter->handle($actor, (int) $session->project_id, (int) $session->id, $approval);
        self::assertSame('processing_documents', $started->status->value);
        self::assertFalse($started->input_payload['generation_requested']);
        self::assertSame($id, $unit->fresh()->metadata['credit_operation_id']);
        $replayed = $starter->handle($actor, (int) $session->project_id, (int) $session->id, $approval);
        self::assertSame($started->state_version, $replayed->state_version);
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        $complete = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\CompleteEstimatorDocumentOperation::class);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\StopEstimateGenerationDocumentProcessing::class)
            ->handle($started, $ignored, $actor, $started->state_version, $ignored->source_version, (string) Str::uuid());
        self::assertSame('reserved', DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('status'));
        self::assertTrue(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCreditBinding::class)->canDispatch($unit->fresh()));
        self::assertTrue($complete->handle($started));
        self::assertSame('reserved', DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('status'));
        try {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
                ->quote($actor, (int) $session->project_id, (int) $session->id, $started->state_version, (string) Str::uuid(), 'generation');
            self::fail('A new paid operation raced the active document manifest');
        } catch (\DomainException $exception) {
            self::assertSame('estimator_credit_documents_processing_active', $exception->getMessage());
        }
        $unit->update(['status' => 'running', 'claim_token' => (string) Str::uuid(), 'attempt_count' => 1,
            'lease_expires_at' => now()->addMinutes(5), 'started_at' => now()]);
        config(['estimate-generation.ocr.timeweb.api_key' => 'fixture-not-real', 'estimate-generation.ocr.timeweb.base_uri' => 'https://fixture.invalid/v1']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['https://fixture.invalid/v1/chat/completions' => \Illuminate\Support\Facades\Http::response([
            'model' => 'openai/gpt-6-luna', 'choices' => [['message' => ['content' => '{"pages":[{"page_number":1,"text":"Нужны размеры"}]}']]],
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 50, 'total_tokens' => 250]], 200)]);
        $context = new AiOperationContext((string) Str::uuid(), (string) Str::uuid(), (int) $organization->id, (int) $session->project_id,
            (int) $session->id, 'understand_documents', 'ocr', 1, documentId: (int) $document->id, unitId: (int) $unit->id,
            processingLineageId: $lineage, creditOperationId: $id);
        app(\App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface::class)
            ->recognize(new \App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput(str_repeat('fixture-image', 12000), 'image/png', 'room.png', 1, $context));
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        self::assertSame(1, DB::table('estimate_generation_credit_attempts')->where('operation_id', $id)->count());
        $unit->update(['status' => 'completed', 'claim_token' => null, 'lease_expires_at' => null,
            'output_version' => hash('sha256', 'facts-only-output'), 'output_count' => 1, 'completed_at' => now()]);
        self::assertTrue($complete->handle($started->fresh()));
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        self::assertSame('settled', $operation->status);
        self::assertSame(0, (int) $operation->consumed_units_minor);
        self::assertSame(1, DB::table('ai_credit_provider_usages')->where('ai_credit_reservation_id', $operation->reservation_id)->count());
        self::assertSame(1000000, app(AICreditService::class)->balance($organization)['available_minor']);
        self::assertSame('input_review_required', $session->fresh()->status->value);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class);
        self::assertTrue($complete->handle($session->fresh()));
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documentCompletionDenials')]
    public function test_document_results_do_not_publish_or_charge_after_access_changes(string $change): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $source = 'sha256:'.hash('sha256', 'result-denial-'.$change);
        $lineage = (string) Str::uuid();
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $actor->id,
            'filename' => 'room.png', 'mime_type' => 'image/png', 'storage_path' => 'fixture/private/room.png',
            'checksum_sha256' => substr($source, 7), 'source_version' => $source, 'page_count' => 1, 'status' => 'queued',
            'processing_control_status' => 'active', 'processing_control_source_version' => $source,
            'processing_control_attempt_id' => $lineage, 'meta' => ['processing_attempt_id' => $lineage]]);
        $unit = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id,
            'document_id' => $document->id, 'unit_type' => 'raster_image', 'unit_index' => 1, 'source_version' => $source, 'status' => 'pending',
            'locator' => ['source_kind' => 'image', 'source_version' => $source, 'coordinate_space' => 'image_pixels',
                'artifact_path' => 'fixture/private/room.png', 'artifact_sha256' => 'sha256:'.hash('sha256', 'room-artifact'),
                'artifact_version_id' => 'fixture.v1', 'content_type' => 'image/png'], 'metadata' => ['processing_attempt_id' => $lineage]]);
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:document-result-'.$change);
        $id = (string) Str::uuid();
        $quote = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
            ->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'documents');
        $started = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\StartEstimatorDocumentOperation::class)
            ->handle($actor, (int) $session->project_id, (int) $session->id, ['request_id' => $id, 'quote_id' => $quote['quote_id'], 'state_version' => 0]);
        $token = (string) Str::uuid();
        $unit->update(['status' => 'running', 'claim_token' => $token, 'attempt_count' => 1,
            'lease_expires_at' => now()->addMinutes(5), 'started_at' => now()]);
        config(['estimate-generation.universal_enabled' => false, 'estimate-generation.credits.enabled' => false]);
        match ($change) {
            'actor' => $actor->update(['is_active' => false]),
            'membership' => $actor->organizations()->updateExistingPivot($organization->id, ['is_active' => false]),
            'product' => $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class,
                \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class)->allows('hasModuleAccess')->andReturn(false)->getMock()),
            'rollout' => $started->update(['input_payload' => [...$started->input_payload, 'evaluation_mode' => 'universal']]),
            'cancel' => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession::class)
                ->handle($started, $started->state_version, \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent::Cancelled, $actor),
        };
        $published = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore::class)->publish(
            new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaim((int) $unit->id,
                \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaimStatus::Acquired, $token,
                organizationId: (int) $organization->id, projectId: (int) $session->project_id, sessionId: (int) $session->id,
                documentId: (int) $document->id, sourceVersion: $source),
            new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitOutput(hash('sha256', 'late-output'), 'Never publish this'),
            now()->toDateTimeImmutable());
        self::assertFalse($published);
        self::assertTrue(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\CompleteEstimatorDocumentOperation::class)->handle($started->fresh()));
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        self::assertContains($operation->status, ['failed', 'cancelled']);
        self::assertSame(0, (int) $operation->consumed_units_minor);
        self::assertNull($unit->fresh()->output_version);
        self::assertSame(1000000, app(AICreditService::class)->balance($organization)['available_minor']);
        self::assertSame($change === 'cancel' ? 'cancelled' : 'processing_documents', $session->fresh()->status->value);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class);
    }

    public static function documentCompletionDenials(): array
    {
        return array_map(static fn (string $change): array => [$change], ['actor', 'membership', 'product', 'rollout', 'cancel']);
    }

    public function test_information_only_publication_returns_the_reserve_atomically_without_charging_or_a_revision(): void
    {
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['input_payload' => ['evaluation_mode' => 'universal', 'price_policy' => 'catalog',
            'profile_id' => 'renovation', 'selected_sections' => ['finishing']]]);
        $credits = app(AICreditService::class);
        $credits->grant($organization, 1000000, 'purchase', null, 'test:information-only-publication');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $id, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation'));
        $hash = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session);
        $session->update(['status' => 'generating', 'input_payload' => [...$session->input_payload,
            'generation_actor_type' => 'user', 'generation_actor_id' => $actor->id,
            'generation_attempt_id' => $id, 'generation_evaluation_input_hash' => $hash]]);
        $claim = \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim::acquired(
            new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext((int) $session->id,
                (int) $organization->id, (int) $session->project_id, 0, 'sha256:'.$hash, 'generating',
                generationAttemptId: $id, baseInputVersion: 'sha256:'.$hash, creditOperationId: $id),
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage::ValidateDraft, (string) Str::uuid());
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft)->seal(
            (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project([
                'generation_contract' => \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector::CONTRACT,
                'source_input_version' => 'sha256:'.$hash, 'source_info_only' => true, 'evaluation_result' => null,
                'local_estimates' => [], 'estimate_composition' => ['request_info' => [['index' => -1,
                    'reason' => 'estimate_composer_work_scope_unresolved', 'work_name' => null,
                    'next_action' => 'provide_work_scope', 'source_fact_ids' => []]]]]));
        $before = $credits->balance($organization)['available_minor'];
        self::assertLessThan(1000000, $before);
        try {
            DB::transaction(function () use ($claim, $draft): void {
                app(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\PublishUniversalEvaluation::class)->publish($claim, $draft);
                throw new \RuntimeException('rollback_information_receipt');
            });
            self::fail('The publication rollback did not occur.');
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback_information_receipt', $exception->getMessage());
        }
        self::assertSame($before, $credits->balance($organization)['available_minor']);
        self::assertSame('reserved', DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('status'));
        self::assertSame('generating', $session->fresh()->status->value);
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\PublishUniversalEvaluation::class)->publish($claim, $draft));
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        self::assertSame('settled', $operation->status);
        self::assertSame(0, (int) $operation->consumed_units_minor);
        self::assertNull($operation->result_revision_id);
        self::assertFalse(json_decode($operation->receipt, true, 64, JSON_THROW_ON_ERROR)['useful_result']);
        self::assertSame(1000000, $credits->balance($organization)['available_minor']);
        self::assertSame('input_review_required', $session->fresh()->status->value);
        self::assertSame(0, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        $receipt = DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)->publish($session->fresh(), $id, $draft));
        self::assertSame(0, $receipt['consumed_units_minor']);
        self::assertSame(1000000, $credits->balance($organization)['available_minor']);
    }

    private function fixture(): array
    {
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        config(['ai-assistant-credits.enforce' => false, 'estimate-generation.credits.enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => []]);
        $inputHash = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint)->fromSession($session);
        $request = new EstimatorCreditRequest((int) $organization->id, (int) $project->id, (int) $session->id, (int) $actor->id,
            (string) Str::uuid(), 'generation', 0, $inputHash, 'sha256:'.$inputHash, str_repeat('c', 64), hash('sha256', CanonicalPipelineJson::encode($this->plan())),
            1800000, 1, app(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog::class)
                ->resolve('estimate_composition', 'timeweb', 'openai/gpt-6-luna', new \DateTimeImmutable)->toArray());

        return [$organization, $actor, $request];
    }

    private function seedGlobalSettings(): void
    {
        if (DB::table('estimate_generation_setting_snapshots')->where('scope', 'global')->whereNull('organization_id')->exists()) {
            return;
        }
        $snapshot = \App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData::fromArray([
            'scope' => 'global', 'organization_id' => null, 'expected_version' => 0, 'idempotency_key' => (string) Str::ulid(),
            'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
            'retries' => ['vision' => 0, 'classification' => 0, 'normative_matching' => 0],
            'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
            'enabled_formats' => ['pdf', 'png'], 'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB']])->snapshot();
        $hash = \App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash::calculate($snapshot);
        $adminId = DB::table('system_admins')->insertGetId(['name' => 'Fixture', 'email' => Str::uuid().'@example.test',
            'password' => 'not-used', 'created_at' => now(), 'updated_at' => now()]);
        $snapshotId = DB::table('estimate_generation_setting_snapshots')->insertGetId(['scope' => 'global', 'organization_id' => null,
            'version' => 1, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_hash' => $hash,
            'daily_budget' => '250.00', 'monthly_budget' => '4000.00', 'currency' => 'RUB',
            'created_by_system_admin_id' => $adminId, 'created_at' => now()]);
        DB::table('estimate_generation_setting_snapshot_hashes')->insert(['setting_snapshot_id' => $snapshotId,
            'algorithm' => 'jcs-sha256-v1', 'snapshot_hash' => $hash, 'created_at' => now()]);
    }

    private function plan(): array
    {
        return ['roles' => ['estimate_composition' => ['max_calls' => 1, 'maximum_cost_micro_rub' => 1800000]]];
    }
}
