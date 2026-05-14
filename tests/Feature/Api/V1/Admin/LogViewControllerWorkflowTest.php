<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\MeasurementUnit;
use App\Models\Models\Log\WorkCompletionLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class LogViewControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $systemLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->systemLogPath = storage_path('logs/zzzz-codex-admin-system-workflow.log');
        if (is_file($this->systemLogPath)) {
            unlink($this->systemLogPath);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->systemLogPath) && is_file($this->systemLogPath)) {
            unlink($this->systemLogPath);
        }

        parent::tearDown();
    }

    public function test_material_usage_logs_return_deprecated_business_response(): void
    {
        $this->allowLogPermissions();
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/logs/material-usage');

        $response->assertStatus(410);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', trans_message('logs.material_usage_deprecated'));
    }

    public function test_system_logs_are_filtered_by_current_organization_and_request_filters(): void
    {
        $this->allowLogPermissions();
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();

        $this->writeSystemLog([
            'timestamp' => '2026-05-10T10:15:00+00:00',
            'category' => 'SECURITY',
            'level' => 'ERROR',
            'event' => 'auth.login.blocked',
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'message' => 'Own security event',
        ]);
        $this->writeSystemLog([
            'timestamp' => '2026-05-10T11:15:00+00:00',
            'category' => 'SECURITY',
            'level' => 'ERROR',
            'event' => 'auth.login.blocked',
            'organization_id' => $foreignOrganization->id,
            'user_id' => $context->user->id,
            'message' => 'Foreign security event',
        ]);
        $this->writeSystemLog([
            'timestamp' => '2026-05-10T12:15:00+00:00',
            'category' => 'BUSINESS',
            'level' => 'ERROR',
            'event' => 'auth.login.blocked',
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'message' => 'Wrong category event',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/logs/system?category=security&level=ERROR&event=auth.login&user_id=' . $context->user->id . '&date_from=2026-05-10&date_to=2026-05-10&per_page=10&page=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.per_page', 10);
        $response->assertJsonPath('meta.last_page', 1);
        $response->assertJsonPath('data.0.event', 'auth.login.blocked');
        $response->assertJsonPath('data.0.message', 'Own security event');

        $messages = collect($response->json('data'))->pluck('message')->all();
        $this->assertNotContains('Foreign security event', $messages);
        $this->assertNotContains('Wrong category event', $messages);
    }

    public function test_system_logs_keep_stable_pagination_when_result_is_empty_and_per_page_is_invalid(): void
    {
        $this->allowLogPermissions();
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/logs/system?category=security&event=absent-event&per_page=0&page=0');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.total', 0);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.per_page', 15);
        $response->assertJsonPath('meta.last_page', 1);
    }

    public function test_work_completion_logs_return_scoped_resource_payload_with_filters_and_sorting(): void
    {
        $this->allowLogPermissions();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Own project',
        ]);
        $foreignProject = Project::factory()->create([
            'organization_id' => Organization::factory()->verified()->create()->id,
            'name' => 'Foreign project',
        ]);
        $workType = $this->createWorkType($context->organization->id, [
            'name' => 'Concrete works',
        ]);
        $foreignWorkType = $this->createWorkType($foreignProject->organization_id, [
            'name' => 'Foreign works',
        ]);

        $visibleLog = WorkCompletionLog::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'work_type_id' => $workType->id,
            'user_id' => $context->user->id,
            'quantity' => 12.5,
            'unit_price' => 1000,
            'total_price' => 12500,
            'completion_date' => Carbon::parse('2026-05-09'),
            'performers_description' => 'Crew A',
            'notes' => 'Accepted by inspector',
        ]);
        WorkCompletionLog::query()->create([
            'organization_id' => $foreignProject->organization_id,
            'project_id' => $foreignProject->id,
            'work_type_id' => $foreignWorkType->id,
            'user_id' => User::factory()->create()->id,
            'quantity' => 4,
            'completion_date' => Carbon::parse('2026-05-09'),
            'notes' => 'Foreign log',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/logs/work-completion?project_id=' . $project->id . '&work_type_id=' . $workType->id . '&user_id=' . $context->user->id . '&date_from=2026-05-01&date_to=2026-05-31&sort_by=total_price&sort_direction=desc&per_page=10');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $visibleLog->id);
        $response->assertJsonPath('data.0.project_name', 'Own project');
        $response->assertJsonPath('data.0.work_type_name', 'Concrete works');
        $response->assertJsonPath('data.0.user_name', $context->user->name);
        $response->assertJsonPath('data.0.quantity', 12.5);
        $response->assertJsonPath('data.0.unit_price', 1000);
        $response->assertJsonPath('data.0.total_price', 12500);
        $response->assertJsonPath('data.0.performers_description', 'Crew A');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$visibleLog->id], $ids);
    }

    private function allowLogPermissions(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
        });
    }

    private function writeSystemLog(array $entry): void
    {
        if (!is_dir(dirname($this->systemLogPath))) {
            mkdir(dirname($this->systemLogPath), 0777, true);
        }

        file_put_contents(
            $this->systemLogPath,
            '[2026-05-10 10:00:00] testing.INFO: ' . json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );
    }

    private function createWorkType(int $organizationId, array $attributes = []): WorkType
    {
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Square meter',
            'short_name' => 'm2',
            'type' => 'work',
            'is_default' => false,
            'is_system' => false,
        ]);

        return WorkType::query()->create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Work type',
            'code' => 'WT-' . $organizationId . '-' . str()->random(6),
            'measurement_unit_id' => $unit->id,
            'category' => 'general',
            'default_price' => 1000,
            'is_active' => true,
        ], $attributes));
    }
}
