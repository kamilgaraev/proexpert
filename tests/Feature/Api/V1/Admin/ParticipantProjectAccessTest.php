<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Enums\ProjectOrganizationRole;
use App\Enums\UserProjectAccessMode;
use App\Models\ConstructionJournal;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ParticipantProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_project_filter_accepts_active_participant_project(): void
    {
        [$context, $project] = $this->participantProjectContext();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/dashboard/summary?' . http_build_query([
                'project_id' => $project->id,
            ]));

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_project_pulse_accepts_active_participant_project(): void
    {
        [$context, $project] = $this->participantProjectContext();

        ProjectPulseReport::create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'scope_type' => 'project',
            'report_date' => now()->toDateString(),
            'period_preset' => 'today',
            'period_from' => now()->startOfDay(),
            'period_to' => now()->endOfDay(),
            'status' => 'good',
            'ai_status' => 'rules_only',
            'summary' => [
                'title' => 'Работы идут по плану',
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
            'created_by_user_id' => $context->user->id,
            'generated_at' => now(),
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/ai-assistant/project-pulse/current?' . http_build_query([
                'project_id' => $project->id,
            ]));

        $response->assertOk();
        $response->assertJsonPath('data.scope.organization_id', $context->organization->id);
        $response->assertJsonPath('data.scope.project_id', $project->id);
    }

    public function test_schedule_index_uses_participant_organization_permission_context(): void
    {
        [$context, $project] = $this->participantProjectContext();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/schedules");

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_construction_journal_index_uses_participant_organization_permission_context(): void
    {
        [$context, $project, $ownerOrganization] = $this->participantProjectContext();

        $journal = ConstructionJournal::query()->create([
            'organization_id' => $ownerOrganization->id,
            'project_id' => $project->id,
            'name' => 'Общий журнал работ',
            'journal_number' => 'J-001',
            'start_date' => '2026-06-01',
            'status' => JournalStatusEnum::ACTIVE,
            'created_by_user_id' => $context->user->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/construction-journals?per_page=20");

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $journalIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($journal->id, $journalIds);
    }

    private function participantProjectContext(): array
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_admin');
        $ownerOrganization = Organization::factory()->verified()->create();
        $project = Project::factory()->create([
            'organization_id' => $ownerOrganization->id,
            'name' => 'Проект с участником',
            'is_archived' => false,
        ]);

        $project->organizations()->attach($context->organization->id, [
            'role' => ProjectOrganizationRole::CONTRACTOR->value,
            'role_new' => ProjectOrganizationRole::CONTRACTOR->value,
            'is_active' => true,
            'invited_at' => now(),
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_user')
            ->where('organization_id', $context->organization->id)
            ->where('user_id', $context->user->id)
            ->update(['project_access_mode' => UserProjectAccessMode::ALL_PROJECTS->value]);

        $this->activateModules($context->organization->id, [
            'ai-assistant',
            'budget-estimates',
            'schedule-management',
        ]);

        return [$context, $project, $ownerOrganization];
    }

    private function activateModules(int $organizationId, array $slugs): void
    {
        foreach ($slugs as $index => $slug) {
            $module = Module::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $slug,
                    'version' => '1.0.0',
                    'type' => $slug === 'ai-assistant' ? 'addon' : 'feature',
                    'billing_model' => 'free',
                    'category' => 'test',
                    'permissions' => ['*'],
                    'is_active' => true,
                    'can_deactivate' => true,
                    'display_order' => $index + 1,
                ]
            );

            OrganizationModuleActivation::query()->updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'module_id' => $module->id,
                ],
                [
                    'status' => 'active',
                    'activated_at' => now(),
                    'expires_at' => null,
                ]
            );
        }
    }
}
