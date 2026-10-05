<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Mockery;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

final class ProjectPulseCurrentAccessTest extends TestCase
{
    public function test_current_report_rechecks_reports_finance_and_project_after_an_outer_frame_was_warmed(): void
    {
        [$organization, $actor, $project, $policy, $flags, $context] = $this->fixture();
        $service = app(ProjectPulseService::class);
        self::assertNotNull($service->current($context));
        foreach (['reports.view', 'finance.view', 'projects.view'] as $denial) {
            $policy->withCurrentChecks($actor, $organization->id, function () use ($policy, $actor, $organization, $project, $flags, $denial, $service, $context): void {
                self::assertTrue($policy->canReadDomain($actor, $organization->id, 'reports'));
                self::assertTrue($policy->canReadDomain($actor, $organization->id, 'finance'));
                self::assertTrue($policy->canReadEntity($actor, $organization->id, 'project', $project->id));
                $flags->denied = [$denial];
                try {
                    $service->current($context);
                    self::fail('A revoked gate returned a report');
                } catch (AccessDeniedHttpException $exception) {
                    self::assertSame(403, $exception->getStatusCode());
                } finally {
                    $flags->denied = [];
                    $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => true]);
                }
            }, fresh: true);
        }
    }

    public function test_final_report_query_rechecks_a_report_gate_revoked_by_the_finance_callback(): void
    {
        [, , , , $flags, $context] = $this->fixture();
        $flags->revokeReportsOnFinance = true;
        $this->expectException(AccessDeniedHttpException::class);
        app(ProjectPulseService::class)->current($context);
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        ProjectPulseReport::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'scope_type' => 'project', 'report_date' => now()->toDateString(), 'period_preset' => 'today',
            'period_from' => now()->startOfDay(), 'period_to' => now()->endOfDay(), 'status' => 'good', 'ai_status' => 'rules_only',
            'summary' => [], 'metrics' => [], 'urgent_actions' => [], 'risk_groups' => [], 'finance' => [],
            'activity' => [], 'recommendations' => [], 'raw_facts' => [],
            'source_refs' => [['entity_type' => 'project', 'entity_id' => (string) $project->id]], 'required_domains' => ['reports', 'finance'],
            'created_by_user_id' => $actor->id, 'generated_at' => now()]);
        $flags = (object) ['denied' => [], 'revokeReportsOnFinance' => false];
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('canCurrent')->andReturnUsing(static function (User $user, string $permission) use ($flags): bool {
            if ($permission === 'finance.view' && $flags->revokeReportsOnFinance) { $flags->denied = ['reports.view']; }

            return in_array($permission, ['reports.view', 'finance.view', 'projects.view'], true) && ! in_array($permission, $flags->denied, true);
        });
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'reports'], (object) ['slug' => 'payments'], (object) ['slug' => 'project-management']]));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $context = ProjectPulseContext::fromValidated(['project_id' => $project->id, 'use_ai' => false], $organization->id, $actor->id);

        return [$organization, $actor, $project, $policy, $flags, $context];
    }
}
