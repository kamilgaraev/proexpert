<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditEconomicsMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EstimatorEconomicsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_post_wire_crash_is_visible_once_and_recovery_keeps_the_original_period(): void
    {
        $session = $this->fixture();
        $now = CarbonImmutable::now();
        $wire = $now->subHours(2);
        $attempt = (string) Str::uuid();
        $this->physical($session, $attempt, $wire);
        $this->physical($session, (string) Str::uuid(), null);
        $monitor = app(AICreditEconomicsMonitor::class);
        $before = $monitor->report($wire->subMinute(), $wire->addMinute());
        self::assertSame(0, $before['provider_call_count']);
        self::assertSame(1, $before['possible_provider_call_count']);
        self::assertSame(1, $before['unknown_cost_count']);
        self::assertSame(1, $before['pending_estimator_physical_attempt_count']);
        self::assertSame('repeatable_read', $before['snapshot_consistency']);
        self::assertSame('coverage_incomplete', $before['state']);
        self::assertFalse($before['automatic_paid_approval']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
        $this->record($session, 'succeeded', $attempt);
        $transferTime = $wire->addHour();
        AICreditProviderUsage::query()->create(['organization_id' => $session->organization_id, 'usage_key' => $attempt,
            'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'operation' => 'estimate_composition',
            'cost_micro_rub' => 6075, 'is_successful' => true, 'occurred_at' => $transferTime, 'metadata' => ['cost_available' => true]]);
        $after = $monitor->report($wire->subMinute(), $wire->addMinute());
        self::assertSame(1, $after['provider_call_count']);
        self::assertSame(1, $after['possible_provider_call_count']);
        self::assertSame(0, $after['unknown_cost_count']);
        self::assertSame(0, $after['pending_estimator_physical_attempt_count']);
        self::assertSame(6075, $after['external_cost_micro_rub']);
        self::assertFalse((bool) DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $attempt)->value('usage_recorded'));
        $later = $monitor->report($transferTime->subMinute(), $transferTime->addMinute());
        self::assertSame(0, $later['provider_call_count']);
        self::assertSame(0, $later['possible_provider_call_count']);
        self::assertSame(0, $later['pending_estimator_physical_attempt_count']);
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_database_report_deduplicates_estimator_source_and_shared_wallet_and_keeps_failed_costs_when_other_sources_are_missing(): void
    {
        $session = $this->fixture();
        $first = $this->record($session, 'succeeded');
        $this->record($session, 'http_failed');
        AICreditProviderUsage::query()->create(['organization_id' => $session->organization_id, 'usage_key' => $first,
            'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'operation' => 'estimate_composition',
            'cost_micro_rub' => 6075, 'is_successful' => true, 'occurred_at' => now(), 'metadata' => ['cost_available' => true]]);
        $monitor = app(AICreditEconomicsMonitor::class);
        $now = CarbonImmutable::now();
        $report = $monitor->report($now->subMinute(), $now->addMinute());
        self::assertSame(12150, $report['external_cost_micro_rub']);
        self::assertSame(2, $report['provider_call_count']);
        self::assertSame(1, $report['deduplicated_usage_count']);
        self::assertSame(0, $report['unknown_cost_count']);
        self::assertGreaterThanOrEqual(1, $report['missing_source_count']);
        self::assertSame('coverage_incomplete', $report['state']);
    }

    private function fixture(): EstimateGenerationSession
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);

        return EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => []]);
    }

    private function physical(EstimateGenerationSession $session, string $attempt, ?CarbonImmutable $wire): void
    {
        DB::table('estimate_generation_vision_physical_attempts')->insert([
            'attempt_id' => $attempt, 'request_fingerprint' => str_repeat('a', 64), 'logical_request_fingerprint' => str_repeat('a', 64),
            'processing_lineage_id' => (string) Str::uuid(), 'organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'session_id' => $session->id, 'state' => $wire === null ? 'pre_wire' : 'wire_started',
            'owner_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinute(), 'wire_started_at' => $wire,
            'usage_recorded' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function record(EstimateGenerationSession $session, string $status, ?string $id = null): string
    {
        $id ??= (string) Str::uuid();
        $price = app(AiPricingCatalog::class)->resolve('estimate_composition', 'timeweb', 'openai/gpt-6-luna', new \DateTimeImmutable);
        app(AiUsageStore::class)->record(new AiUsageData(new AiOperationContext((string) Str::uuid(), $id,
            (int) $session->organization_id, (int) $session->project_id, (int) $session->id, 'plan_work_items', 'estimate_composition', 1),
            'timeweb', 'openai/gpt-6-luna', $status, 1, usageStatus: 'measured', inputTokens: 200, outputTokens: 50,
            httpCode: $status === 'http_failed' ? 500 : 200, priceSnapshot: $price));

        return $id;
    }
}
