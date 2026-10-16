<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorOrganizationExpenseBudget;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TextAiWireAttempt;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EstimatorOrganizationBudgetPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Http::preventStrayRequests();
        Http::fake();
        config(['estimate-generation.live_qa.enabled' => false, 'estimate-generation.credits.enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        try {
            DB::rollBack();
        } finally {
            parent::tearDown();
        }
    }

    public function test_historical_unknown_expenses_keep_their_original_period_without_becoming_zero_cost(): void
    {
        [$session] = $this->fixture();
        $this->seedBudget(null, '1.00', '2.00');
        $this->seedBudget((int) $session->organization_id, '0.50', '1.00');
        $unbounded = $this->historicalAttempt($session, now()->startOfMonth()->subSecond()->toAtomString());
        $bounded = $this->historicalAttempt($session, now()->subMonths(2)->toAtomString(), '11.05920000');
        app(AiUsageStore::class)->record($this->usage($session, $bounded, 'http_failed', null));

        $this->admit($session, 1);

        self::assertNull(DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $unbounded)->value('cost_reservation_amount'));
        self::assertSame('11.05920000', DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $bounded)->value('cost_reservation_amount'));
        self::assertSame('unavailable', DB::table('estimate_generation_ai_usage')->where('attempt_id', $bounded)->value('usage_status'));
        self::assertNull(DB::table('estimate_generation_ai_usage')->where('attempt_id', $bounded)->value('cost_amount'));
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_current_unknown_expense_without_a_reservation_still_blocks_admission(): void
    {
        [$session] = $this->fixture();
        $this->seedBudget(null, '1.00', '2.00');
        $this->historicalAttempt($session, now()->startOfDay()->toAtomString());

        $this->assertBudgetDenied($session, 1);
    }

    public function test_current_unknown_reservation_counts_towards_the_daily_limit(): void
    {
        [$session] = $this->fixture();
        $this->seedBudget(null, '1.00', '2.00');
        $this->historicalAttempt($session, now()->startOfDay()->toAtomString(), '0.60000000');
        $this->admit($session, 400000);
        $this->assertBudgetDenied($session, 400001);
    }

    public function test_yesterday_unknown_reservation_still_counts_towards_the_monthly_limit(): void
    {
        [$session] = $this->fixture();
        $this->seedBudget(null, '1.00', '2.00');
        $this->historicalAttempt($session, now()->subDay()->toAtomString(), '1.50000000');
        $this->admit($session, 500000);
        $this->assertBudgetDenied($session, 500001);
    }

    public function test_late_measured_cost_is_counted_in_its_current_booking_period(): void
    {
        [$session] = $this->fixture();
        $this->seedBudget(null, '1.00', '2.00');
        $attempt = $this->historicalAttempt($session, now()->subMonths(2)->toAtomString());
        $this->admit($session, 1);

        app(AiUsageStore::class)->record($this->usage($session, $attempt, 'succeeded', '0.60'));
        self::assertSame('available', DB::table('estimate_generation_ai_usage')->where('attempt_id', $attempt)->value('pricing_status'));
        self::assertSame('0.60000000', DB::table('estimate_generation_ai_usage')->where('attempt_id', $attempt)->value('cost_amount'));

        $this->admit($session, 400000);
        $this->assertBudgetDenied($session, 400001);
    }

    public function test_historical_period_correction_does_not_reset_or_bypass_the_500_unit_qa_budget(): void
    {
        [$session, $actor] = $this->fixture();
        $this->seedBudget(null, '500.00', '10000.00');
        $historicalSession = $session->replicate();
        $historicalSession->save();
        $this->historicalAttempt($historicalSession, now()->subMonths(2)->toAtomString());
        $runId = (string) Str::uuid();
        config(['estimate-generation.live_qa.enabled' => true, 'estimate-generation.live_qa.run_id' => $runId,
            'ai-assistant-credits.rub_per_unit' => '0.18', 'ai-assistant-credits.unit_minor' => 100]);

        $this->send($session, $actor, '89.95');
        try {
            $this->send($session, $actor, '0.000001');
            self::fail('The existing QA run budget was bypassed');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_live_qa_expense_limit_reached', $exception->reason);
        }

        self::assertSame(50000, (int) DB::table('estimate_generation_qa_expense_runs')->where('run_id', $runId)->value('maximum_units_minor'));
        self::assertSame(1, DB::table('estimate_generation_qa_expense_attempts')->where('run_id', $runId)->count());
    }

    public function test_fresh_global_and_organization_budgets_count_legacy_paid_expenses_before_wire(): void
    {
        config(['estimate-generation.live_qa.enabled' => false, 'estimate-generation.credits.enabled' => false]);
        [$session, $actor] = $this->fixture();
        $this->seedBudget(null, '2.00', '4.00');
        $this->seedBudget((int) $session->organization_id, '1.00', '3.00');
        $first = $this->send($session, $actor, '0.60');
        app(AiUsageStore::class)->record($this->usage($session, $first, 'succeeded', '0.60'));
        try {
            $this->send($session, $actor, '0.41');
            self::fail('The current organization budget was ignored');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_credit_organization_budget_reached', $exception->reason);
        }
        [$nextSession, $nextActor] = $this->fixture();
        $this->send($nextSession, $nextActor, '1.00');
        try {
            $this->send($nextSession, $nextActor, '0.41');
            self::fail('The global supplier budget ignored another organization');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_credit_organization_budget_reached', $exception->reason);
        }
        self::assertSame(1, DB::table('estimate_generation_vision_physical_attempts')->where('session_id', $session->id)->whereNotNull('wire_started_at')->count());
        self::assertSame(1, DB::table('estimate_generation_vision_physical_attempts')->where('session_id', $nextSession->id)->whereNotNull('wire_started_at')->count());
    }

    private function seedBudget(?int $organizationId, string $daily, string $monthly): void
    {
        $scope = $organizationId === null ? 'global' : 'organization';
        $snapshot = \App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData::fromArray([
            'scope' => $scope, 'organization_id' => $organizationId, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid(),
            'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
            'retries' => ['vision' => 0, 'classification' => 0, 'normative_matching' => 0],
            'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
            'enabled_formats' => ['pdf', 'png'], 'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => $daily, 'monthly' => $monthly, 'currency' => 'RUB']])->snapshot();
        $hash = \App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash::calculate($snapshot);
        $adminId = DB::table('system_admins')->insertGetId(['name' => 'Budget fixture', 'email' => Str::uuid().'@example.test',
            'password' => 'not-used', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('estimate_generation_setting_snapshots')->insertGetId(['scope' => $scope, 'organization_id' => $organizationId,
            'version' => 1, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_hash' => $hash,
            'daily_budget' => $daily, 'monthly_budget' => $monthly, 'currency' => 'RUB', 'created_by_system_admin_id' => $adminId, 'created_at' => now()]);
        DB::table('estimate_generation_setting_snapshot_hashes')->insert(['setting_snapshot_id' => $id, 'algorithm' => 'jcs-sha256-v1',
            'snapshot_hash' => $hash, 'created_at' => now()]);
    }

    private function historicalAttempt(EstimateGenerationSession $session, string $wireStartedAt, ?string $reservation = null): string
    {
        $attempt = (string) Str::uuid();
        DB::table('estimate_generation_vision_physical_attempts')->insert([
            'attempt_id' => $attempt, 'request_fingerprint' => hash('sha256', $attempt),
            'logical_request_fingerprint' => hash('sha256', $attempt), 'processing_lineage_id' => (string) Str::uuid(),
            'organization_id' => $session->organization_id, 'project_id' => $session->project_id, 'session_id' => $session->id,
            'state' => 'wire_started', 'owner_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinute(),
            'wire_started_at' => $wireStartedAt, 'cost_reservation_amount' => $reservation,
            'cost_reservation_currency' => $reservation === null ? null : 'RUB',
            'usage_recorded' => false, 'created_at' => $wireStartedAt, 'updated_at' => $wireStartedAt,
        ]);

        return $attempt;
    }

    private function admit(EstimateGenerationSession $session, int $maximum): void
    {
        DB::transaction(static fn () => app(EstimatorOrganizationExpenseBudget::class)->admit((int) $session->organization_id, $maximum));
    }

    private function assertBudgetDenied(EstimateGenerationSession $session, int $maximum): void
    {
        try {
            $this->admit($session, $maximum);
            self::fail('The current supplier budget was ignored');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_credit_organization_budget_reached', $exception->reason);
        }
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization::class,
            new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorizer($permissions));
        $product = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $product->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $product);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => []]);

        return [$session, $actor];
    }

    private function send(EstimateGenerationSession $session, User $actor, string $maximum): string
    {
        $id = (string) Str::uuid();
        app(SessionAiCostGuard::class)->authorize((int) $session->organization_id, (int) $session->project_id, (int) $session->id,
            new TextAiWireAttempt($id, hash('sha256', $id), new AiCost($maximum, 'RUB', 'available'), 60,
                stateVersion: 0, trustedActor: $actor, permission: 'estimate_generation.review'));

        return $id;
    }

    private function usage(EstimateGenerationSession $session, string $id, string $status, ?string $cost): AiUsageData
    {
        $price = AiPriceSnapshot::fromArray(['currency' => 'RUB', 'source' => 'fixture', 'version' => 'qa-fixture',
            'effective_at' => '2026-10-12T00:00:00+00:00', 'input_per_million' => $cost ?? '80',
            'cached_input_per_million' => $cost ?? '80', 'output_per_million' => '0']);

        return new AiUsageData(new AiOperationContext((string) Str::uuid(), $id, (int) $session->organization_id,
            (int) $session->project_id, (int) $session->id, 'plan_work_items', 'estimate_composition', 1),
            'timeweb', 'openai/gpt-6-luna', $status, 1, reportedModel: 'openai/gpt-6-luna', usageStatus: $cost === null ? 'unavailable' : 'measured',
            inputTokens: $cost === null ? 0 : 1000000, httpCode: $status === 'http_failed' ? 500 : ($status === 'ambiguous' ? null : 200), priceSnapshot: $price);
    }
}
