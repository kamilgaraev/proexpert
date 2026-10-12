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
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EstimatorEconomicsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
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

    private function record(EstimateGenerationSession $session, string $status): string
    {
        $id = (string) Str::uuid();
        $price = app(AiPricingCatalog::class)->resolve('estimate_composition', 'timeweb', 'openai/gpt-6-luna', new \DateTimeImmutable);
        app(AiUsageStore::class)->record(new AiUsageData(new AiOperationContext((string) Str::uuid(), $id,
            (int) $session->organization_id, (int) $session->project_id, (int) $session->id, 'plan_work_items', 'estimate_composition', 1),
            'timeweb', 'openai/gpt-6-luna', $status, 1, usageStatus: 'measured', inputTokens: 200, outputTokens: 50,
            httpCode: $status === 'http_failed' ? 500 : 200, priceSnapshot: $price));

        return $id;
    }
}
