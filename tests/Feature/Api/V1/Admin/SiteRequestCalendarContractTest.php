<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\SiteRequests\Enums\CalendarEventTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestCalendarEvent;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class SiteRequestCalendarContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
        });
    }

    public function test_filters_use_request_type_and_current_status_with_project_and_organization_scope(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $expected = $this->createEvent($context, $project, 'personnel_request', 'approved');
        $this->createEvent($context, $project, 'material_request', 'approved');
        $this->createEvent($context, $project, 'personnel_request', 'rejected');
        $this->createEvent($context, $project, 'personnel_request', 'draft');
        $this->createEvent($context, $otherProject, 'personnel_request', 'approved');
        $foreign = AdminApiTestContext::create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $this->createEvent($foreign, $foreignProject, 'personnel_request', 'approved');

        $response = $this->withHeaders($context->authHeaders())->getJson($this->url([
            'project_id' => $project->id, 'type' => 'personnel_request', 'status' => 'approved',
        ]))->assertOk()->assertJsonCount(1, 'data');

        $response->assertJsonPath('data.0.id', $expected->id)
            ->assertJsonPath('data.0.site_request_id', $expected->site_request_id)
            ->assertJsonPath('data.0.project.id', $project->id)
            ->assertJsonPath('data.0.request.status', 'approved')
            ->assertJsonPath('data.0.request.priority', 'high')
            ->assertJsonPath('data.0.request.request_type', 'personnel_request');

        $this->getJson($this->url(['project_id' => $project->id, 'status' => 'draft']))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url(['project_id' => $foreignProject->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    public function test_request_categories_sharing_deadline_event_type_remain_distinct(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $expected = $this->createEvent($context, $project, 'info_request', 'in_review');
        $this->createEvent($context, $project, 'other', 'in_review');
        $this->createEvent($context, $project, 'issue_report', 'in_review');

        $this->withHeaders($context->authHeaders())->getJson($this->url(['type' => 'info_request']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $expected->id);
    }

    public function test_multiple_statuses_and_legacy_event_type_are_combined(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $approved = $this->createEvent($context, $project, 'material_request', 'approved');
        $completed = $this->createEvent($context, $project, 'material_request', 'completed');
        $this->createEvent($context, $project, 'material_request', 'rejected');
        $this->createEvent($context, $project, 'personnel_request', 'approved');

        $response = $this->withHeaders($context->authHeaders())->getJson($this->url([
            'event_type' => 'material_delivery', 'status' => ['approved', 'completed'],
        ]))->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$approved->id, $completed->id], array_column($response->json('data'), 'id'));
    }

    public function test_export_applies_the_same_filters_as_the_calendar(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $expected = $this->createEvent($context, $project, 'equipment_request', 'approved');
        $excluded = $this->createEvent($context, $project, 'material_request', 'approved');
        $this->createEvent($context, $project, 'equipment_request', 'pending');

        $response = $this->withHeaders($context->authHeaders())->get($this->url([
            'project_id' => $project->id, 'type' => 'equipment_request', 'status' => 'approved',
        ], '/export'))->assertOk();
        $ical = $response->getContent();
        $this->assertSame(1, substr_count($ical, 'BEGIN:VEVENT'));
        $this->assertStringContainsString("UID:site-request-{$expected->id}@prohelper.ru", $ical);
        $this->assertStringNotContainsString("UID:site-request-{$excluded->id}@prohelper.ru", $ical);
    }

    public function test_unknown_filter_values_are_rejected_instead_of_silently_ignored(): void
    {
        $context = AdminApiTestContext::create();
        $this->withHeaders($context->authHeaders());
        foreach (['type' => 'material_delivery', 'event_type' => 'material_request', 'status' => 'unknown'] as $field => $value) {
            foreach (['', '/export'] as $suffix) {
                $this->getJson($this->url([$field => $value], $suffix))
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
    }

    private function url(array $filters = [], string $suffix = ''): string
    {
        return '/api/v1/admin/site-requests/calendar'.$suffix.'?'.http_build_query([
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31', ...$filters,
        ]);
    }

    private function createEvent(AdminApiTestContext $context, Project $project, string $type, string $status): SiteRequestCalendarEvent
    {
        $request = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Учебная заявка '.$type,
            'request_type' => $type,
            'status' => $status,
            'priority' => 'high',
        ]);

        return SiteRequestCalendarEvent::query()->create([
            'site_request_id' => $request->id,
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'event_type' => CalendarEventTypeEnum::fromRequestType($request->request_type),
            'title' => 'Учебное событие '.$request->id,
            'color' => '#4CAF50',
            'start_date' => '2026-10-22',
        ]);
    }
}
