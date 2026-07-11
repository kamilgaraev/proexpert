<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAuditEvent;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationAuditService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationOrchestrator;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Tests\TestCase;

final class EstimateGenerationAuditTest extends TestCase
{
    public function test_generation_records_normative_decision_audit_without_sensitive_prompt_text(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'generating',
            'processing_stage' => 'generating',
            'processing_progress' => 0,
            'input_payload' => [
                'generation_attempt_id' => 'audit-generation',
                'description' => 'SECRET_PROMPT_TOKEN частный дом 150 м2',
                'building_type' => 'Жилой',
                'area' => 150,
                'regional_context' => [
                    'region_name' => 'Республика Татарстан',
                    'year' => 2026,
                    'quarter' => 2,
                    'version_key' => '2026-q2-ru-ta',
                ],
            ],
            'problem_flags' => [],
        ]);

        $session = app(EstimateGenerationOrchestrator::class)->generate($session);
        $events = EstimateGenerationAuditEvent::query()
            ->where('session_id', $session->id)
            ->where('event_type', EstimateGenerationAuditService::EVENT_NORMATIVE_DECISION_SUMMARY)
            ->get();

        $this->assertGreaterThan(0, $events->count());
        $this->assertSame($session->packages()->count(), $events->count());
        $this->assertFalse($events->contains(static fn (EstimateGenerationAuditEvent $event): bool => $event->package_id === null));

        $payload = $events->first()->payload ?? [];

        $this->assertArrayHasKey('accepted', $payload);
        $this->assertArrayHasKey('review_priced', $payload);
        $this->assertArrayHasKey('candidate_only', $payload);
        $this->assertArrayHasKey('not_found', $payload);
        $this->assertArrayHasKey('unit_mismatch', $payload);
        $this->assertArrayHasKey('scope_mismatch', $payload);
        $this->assertArrayHasKey('max_line_total', $payload);
        $this->assertArrayNotHasKey('description', $payload);
        $this->assertArrayNotHasKey('prompt', $payload);
        $this->assertStringNotContainsString(
            'SECRET_PROMPT_TOKEN',
            json_encode($events->pluck('payload')->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_production_check_command_reports_normative_and_learning_sections(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'estimate_review_required',
            'processing_stage' => 'validation_and_normalization',
            'processing_progress' => 100,
            'input_payload' => [],
            'analysis_payload' => [],
            'draft_payload' => [],
            'problem_flags' => [],
        ]);

        EstimateGenerationAuditEvent::query()->create([
            'session_id' => $session->id,
            'package_id' => null,
            'user_id' => $user->id,
            'event_type' => EstimateGenerationAuditService::EVENT_NORMATIVE_DECISION_SUMMARY,
            'payload' => [
                'accepted' => 1,
                'review_priced' => 1,
                'candidate_only' => 2,
                'not_found' => 1,
                'unit_mismatch' => 1,
                'scope_mismatch' => 1,
                'max_line_total' => 12345.67,
            ],
        ]);

        $this->artisan('estimate-generation:production-check', [
            '--session_id' => $session->id,
        ])
            ->expectsOutputToContain('Подбор норм')
            ->expectsOutputToContain('Learning examples')
            ->expectsOutputToContain('estimate_generation_learning')
            ->assertExitCode(0);
    }

    public function test_production_check_strict_mode_fails_when_pricing_coverage_is_incomplete(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'estimate_review_required',
            'processing_stage' => 'validation_and_normalization',
            'processing_progress' => 100,
            'input_payload' => [],
            'analysis_payload' => [],
            'draft_payload' => [],
            'problem_flags' => [],
        ]);
        $package = EstimateGenerationPackage::query()->create([
            'session_id' => $session->id,
            'key' => 'foundation',
            'title' => 'Foundation',
            'scope_type' => 'foundation',
            'status' => 'ready',
            'target_items_min' => 1,
            'target_items_max' => 1,
            'actual_items_count' => 1,
            'totals' => ['items_count' => 1, 'priced_items_count' => 0],
            'quality_summary' => [],
            'assumptions' => [],
            'source_refs' => [],
            'metadata' => [],
            'sort_order' => 100,
        ]);
        EstimateGenerationPackageItem::query()->create([
            'package_id' => $package->id,
            'key' => 'foundation-work-1',
            'level' => 0,
            'item_type' => 'priced_work',
            'name' => 'Foundation work',
            'unit' => 'm3',
            'quantity' => 1,
            'quantity_basis' => [],
            'price_source' => null,
            'normative_status' => 'not_found',
            'unit_price' => 0,
            'direct_cost' => 0,
            'overhead_cost' => 0,
            'profit_cost' => 0,
            'total_cost' => 0,
            'resources' => [],
            'flags' => ['safe_norm_required', 'pricing_not_calculated'],
            'metadata' => ['pricing_status' => 'not_calculated'],
            'sort_order' => 100,
        ]);

        $this->artisan('estimate-generation:production-check', [
            '--session_id' => $session->id,
            '--require-full-pricing' => true,
        ])
            ->expectsOutputToContain('Покрытие расчетом')
            ->expectsOutputToContain('Есть позиции без полного нормативного расчета.')
            ->assertExitCode(1);
    }
}
