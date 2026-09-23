<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\SiteRequests\Enums\CalendarEventTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestCalendarEvent;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\UserProjectAccessMode;
use App\Models\Project;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class SiteRequestsMobileCalendarScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_calendar_without_project_filter_only_returns_accessible_projects(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $context->organization->users()->updateExistingPivot($context->user->id, [
            'project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value,
        ]);
        $allowedProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $hiddenProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->assignedProjects()->attach($allowedProject->id, [
            'is_active' => true,
            'role' => 'member',
            'assigned_by_user_id' => $context->user->id,
            'assigned_at' => now(),
        ]);
        $this->allowAccess();

        $allowedEvent = $this->createEvent($context, $allowedProject);
        $hiddenEvent = $this->createEvent($context, $hiddenProject);

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/site-requests/calendar?start_date='.now()->toDateString().'&end_date='.now()->addDay()->toDateString());

        $response->assertOk();
        $eventIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($allowedEvent->id, $eventIds);
        $this->assertNotContains($hiddenEvent->id, $eventIds);
    }

    public function test_mobile_calendar_rejects_explicit_project_outside_actor_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $context->organization->users()->updateExistingPivot($context->user->id, [
            'project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value,
        ]);
        $hiddenProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/site-requests/calendar?start_date='.now()->toDateString().'&end_date='.now()->addDay()->toDateString().'&project_id='.$hiddenProject->id)
            ->assertForbidden()
            ->assertJsonPath('message', trans_message('site_requests::mobile.calendar_access_denied'));
    }

    private function createEvent(AdminApiTestContext $context, Project $project): SiteRequestCalendarEvent
    {
        $siteRequest = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Материалы на площадку',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::PENDING->value,
            'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Бетон',
            'material_quantity' => 1,
            'material_unit' => 'м3',
        ]);

        return SiteRequestCalendarEvent::query()->create([
            'site_request_id' => $siteRequest->id,
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'event_type' => CalendarEventTypeEnum::MATERIAL_DELIVERY->value,
            'title' => 'Доставка бетона',
            'color' => '#4CAF50',
            'start_date' => now()->toDateString(),
        ]);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturnUsing(static function ($user, string $permission, ?array $scope): bool {
                if ($permission === 'site_requests.calendar.view') {
                    self::assertSame(true, $scope['strict_project_scope'] ?? false);
                    self::assertArrayHasKey('project_id', $scope);
                }

                return true;
            });
        });
    }
}
