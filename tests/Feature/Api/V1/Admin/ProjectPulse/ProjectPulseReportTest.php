<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProjectPulseReportTest extends TestCase
{
    public function test_owner_can_load_current_project_pulse_report(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;
        $user = $context->user;
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'ЖК Северный',
        ]);
        ProjectPulseReport::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'scope_type' => 'project',
            'report_date' => now()->toDateString(),
            'period_preset' => 'today',
            'period_from' => now()->startOfDay(),
            'period_to' => now()->endOfDay(),
            'status' => 'good',
            'ai_status' => 'rules_only',
            'summary' => [
                'title' => 'Ситуация стабильная',
                'text' => 'Критичных событий за выбранный период не найдено.',
            ],
            'metrics' => [],
            'urgent_actions' => [],
            'risk_groups' => [],
            'finance' => [
                'performed_amount' => 0,
                'paid_amount' => 0,
                'pending_acts_amount' => 0,
                'deviation_items' => [],
            ],
            'activity' => [],
            'recommendations' => [],
            'raw_facts' => [],
            'created_by_user_id' => $user->id,
            'generated_at' => now(),
        ]);

        $response = $this
            ->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/ai-assistant/project-pulse/current?project_id=' . $project->id);

        $response->assertOk();
        $response->assertJsonPath('data.scope.organization_id', $organization->id);
        $response->assertJsonPath('data.scope.project_id', $project->id);
        $response->assertJsonStructure([
            'data' => [
                'report_date',
                'period' => ['preset', 'from', 'to'],
                'scope' => ['type', 'organization_id', 'project_id'],
                'status',
                'ai_mode' => ['status', 'provider', 'message'],
                'summary' => ['title', 'text'],
                'metrics',
                'urgent_actions',
                'risk_groups',
                'finance',
                'activity',
                'recommendations',
                'generated_at',
            ],
        ]);
    }

    public function test_owner_can_open_project_pulse_report_from_history(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;
        $user = $context->user;
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'ЖК Северный',
        ]);
        $report = ProjectPulseReport::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'scope_type' => 'project',
            'report_date' => now()->toDateString(),
            'period_preset' => 'today',
            'period_from' => now()->startOfDay(),
            'period_to' => now()->endOfDay(),
            'status' => 'warning',
            'ai_status' => 'rules_only',
            'summary' => [
                'title' => 'Есть вопросы для контроля',
                'text' => 'Система нашла события, которые стоит проверить в рабочем порядке.',
            ],
            'metrics' => [],
            'urgent_actions' => [],
            'risk_groups' => [],
            'finance' => [
                'performed_amount' => 0,
                'paid_amount' => 0,
                'pending_acts_amount' => 0,
                'deviation_items' => [],
            ],
            'activity' => [],
            'recommendations' => [],
            'raw_facts' => [],
            'created_by_user_id' => $user->id,
            'generated_at' => now(),
        ]);

        $response = $this
            ->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/ai-assistant/project-pulse/reports/' . $report->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $report->id);
        $response->assertJsonPath('data.scope.organization_id', $organization->id);
        $response->assertJsonPath('data.scope.project_id', $project->id);
    }

    public function test_generate_stores_project_pulse_report(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;

        $response = $this
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/project-pulse/generate', [
                'period' => 'today',
                'use_ai' => false,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.ai_mode.status', 'rules_only');
        $this->assertDatabaseHas('project_pulse_reports', [
            'organization_id' => $organization->id,
            'scope_type' => 'organization',
        ]);
    }

    public function test_project_pulse_contains_approved_purchase_request_without_order(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;

        $purchaseRequestId = DB::table('purchase_requests')->insertGetId([
            'organization_id' => $organization->id,
            'request_number' => '33-202604-0001',
            'status' => 'approved',
            'budget_amount' => 35000,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $response = $this
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/project-pulse/generate', [
                'period' => 'today',
                'use_ai' => false,
            ]);

        $response->assertOk();
        $fact = collect($response->json('data.facts'))
            ->firstWhere('id', 'purchase_request:' . $purchaseRequestId . ':no_order');

        self::assertNotNull($fact);
        self::assertSame('procurement', $fact['category']);
        self::assertSame('/procurement/purchase-requests/' . $purchaseRequestId, $fact['related_entity']['route']);
        self::assertSame('/procurement/purchase-requests/' . $purchaseRequestId, $fact['primary_action']['route']);
        self::assertStringContainsString('33-202604-0001', $fact['text']);
    }

    public function test_project_pulse_does_not_mix_unlinked_purchase_requests_into_project_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Строительство склада Литер А',
            'status' => 'active',
        ]);

        $purchaseRequestId = DB::table('purchase_requests')->insertGetId([
            'organization_id' => $organization->id,
            'request_number' => '33-202604-0002',
            'status' => 'approved',
            'budget_amount' => 35000,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $response = $this
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/project-pulse/generate', [
                'project_id' => $project->id,
                'period' => 'today',
                'use_ai' => false,
            ]);

        $response->assertOk();
        $fact = collect($response->json('data.facts'))
            ->firstWhere('id', 'purchase_request:' . $purchaseRequestId . ':no_order');

        self::assertNull($fact);
    }
}
