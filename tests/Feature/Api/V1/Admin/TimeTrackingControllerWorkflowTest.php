<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Organization;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Export\PdfExporterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class TimeTrackingControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_time_tracking_contract_supports_registry_calendar_statistics_and_report(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $submittedEntry = TimeEntry::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'worker_type' => 'user',
            'project_id' => $project->id,
            'work_date' => '2026-05-12',
            'hours_worked' => 8,
            'title' => 'Монтаж опалубки',
            'status' => 'submitted',
            'is_billable' => true,
            'hourly_rate' => 500,
        ]);

        $approvedEntry = TimeEntry::query()->create([
            'organization_id' => $context->organization->id,
            'worker_type' => 'virtual',
            'worker_name' => 'Бригада монолитчиков',
            'project_id' => $project->id,
            'work_date' => '2026-05-13',
            'hours_worked' => 6,
            'title' => 'Армирование',
            'status' => 'approved',
            'is_billable' => false,
        ]);

        TimeEntry::query()->create([
            'organization_id' => $foreignOrganization->id,
            'worker_type' => 'virtual',
            'worker_name' => 'Чужая бригада',
            'project_id' => $foreignProject->id,
            'work_date' => '2026-05-12',
            'hours_worked' => 10,
            'title' => 'Чужие работы',
            'status' => 'approved',
            'is_billable' => true,
            'hourly_rate' => 900,
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/time-tracking?' . http_build_query([
                'project_id' => $project->id,
                'per_page' => 15,
            ]));

        $indexResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.project_id', $project->id);

        $statisticsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/time-tracking/statistics?' . http_build_query([
                'project_id' => $project->id,
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-31',
            ]));

        $statisticsResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_hours', 14)
            ->assertJsonPath('data.billable_hours', 8)
            ->assertJsonPath('data.entries_count', 2)
            ->assertJsonPath('data.pending_entries', 1)
            ->assertJsonPath('data.approved_entries', 1);

        $calendarResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/time-tracking/calendar?' . http_build_query([
                'project_id' => $project->id,
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-31',
            ]));

        $calendarResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.2026-05-12')
            ->assertJsonPath('data.2026-05-12.0.id', $submittedEntry->id)
            ->assertJsonPath('data.2026-05-13.0.id', $approvedEntry->id);

        $reportResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/time-tracking/reports?' . http_build_query([
                'project_id' => $project->id,
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-31',
                'group_by' => 'project',
            ]));

        $reportResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.project.id', $project->id)
            ->assertJsonPath('data.0.total_hours', 6);
    }

    public function test_reject_requires_business_reason_before_changing_submitted_entry(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $entry = TimeEntry::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'worker_type' => 'user',
            'project_id' => $project->id,
            'work_date' => '2026-05-12',
            'hours_worked' => 8,
            'title' => 'Монтаж опалубки',
            'status' => 'submitted',
            'is_billable' => true,
        ]);

        $withoutReasonResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/time-tracking/{$entry->id}/reject", []);

        $withoutReasonResponse->assertBadRequest()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Укажите причину отклонения.');
        $this->assertSame('submitted', $entry->fresh()->status);

        $rejectedResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/time-tracking/{$entry->id}/reject", [
                'reason' => 'Нужно уточнить объем работ',
            ]);

        $rejectedResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Нужно уточнить объем работ');
        $this->assertSame('rejected', $entry->fresh()->status);
    }

    public function test_pdf_export_uses_time_tracking_template_contract(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        TimeEntry::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'worker_type' => 'user',
            'project_id' => $project->id,
            'work_date' => '2026-05-12',
            'hours_worked' => 8,
            'title' => 'Billable work',
            'status' => 'approved',
            'is_billable' => true,
            'hourly_rate' => 500,
        ]);

        TimeEntry::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'worker_type' => 'user',
            'project_id' => $project->id,
            'work_date' => '2026-05-13',
            'hours_worked' => 4,
            'title' => 'Non-billable work',
            'status' => 'approved',
            'is_billable' => false,
            'hourly_rate' => 500,
        ]);

        $this->mock(PdfExporterService::class, function (MockInterface $mock) use ($project): void {
            $mock->shouldReceive('streamDownload')
                ->once()
                ->with(
                    'reports.time-tracking-pdf',
                    Mockery::on(static function (array $payload) use ($project): bool {
                        return isset($payload['data'], $payload['totals'], $payload['filters'], $payload['generated_at'])
                            && !isset($payload['entries'], $payload['summary'])
                            && $payload['data']->count() === 1
                            && $payload['data']->first()['project'] === $project->name
                            && $payload['data']->first()['title'] === 'Billable work'
                            && $payload['totals']['total_entries'] === 1
                            && (float) $payload['totals']['total_hours'] === 8.0
                            && (float) $payload['totals']['billable_hours'] === 8.0
                            && (float) $payload['totals']['approved_hours'] === 8.0
                            && $payload['filters']['date_from'] === '01.05.2026'
                            && $payload['filters']['date_to'] === '31.05.2026';
                    }),
                    'time_tracking_report.pdf'
                )
                ->andReturn(new StreamedResponse(static function (): void {
                    echo '%PDF';
                }, 200, ['Content-Type' => 'application/pdf']));
        });

        $response = $this->withHeaders($context->authHeaders())
            ->get('/api/v1/admin/time-tracking/export?' . http_build_query([
                'project_id' => $project->id,
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-31',
                'billable' => true,
                'format' => 'pdf',
            ]));

        $response->assertOk();
    }
}
