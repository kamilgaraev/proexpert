<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAuditEvent;
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
            'status' => 'created',
            'processing_stage' => 'created',
            'processing_progress' => 0,
            'input_payload' => [
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
}
