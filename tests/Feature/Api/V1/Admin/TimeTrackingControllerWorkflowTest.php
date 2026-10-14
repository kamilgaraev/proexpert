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

    public function test_statistics_sum_authoritative_minutes_before_rounding(): void
    {
        $context = $this->timeTrackingContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        for ($index = 0; $index < 60; $index++) {
            TimeEntry::query()->create([
                'organization_id' => $context->organization->id, 'user_id' => $context->user->id,
                'worker_type' => 'user', 'project_id' => $project->id, 'work_date' => '2026-01-31',
                'hours_worked' => 0.02, 'net_minutes' => 1, 'title' => 'Minute '.$index,
                'status' => 'draft', 'is_billable' => true,
            ]);
        }
        $response = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/time-tracking/statistics?project_id='.$project->id)->assertOk();
        self::assertSame(1.0, (float) $response->json('data.total_hours'));
        self::assertSame(1.0, (float) $response->json('data.billable_hours'));
        self::assertSame(60, $response->json('data.entries_count'));
    }

    public function test_statistics_aggregate_microseconds_without_per_entry_minute_rounding(): void
    {
        $context = $this->timeTrackingContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        for ($index = 0; $index < 120; $index++) {
            TimeEntry::query()->create([
                'organization_id' => $context->organization->id, 'user_id' => $context->user->id,
                'worker_type' => 'user', 'project_id' => $project->id, 'work_date' => '2026-01-31',
                'hours_worked' => 0, 'net_minutes' => 0.0083333333, 'net_microseconds' => 500_000,
                'title' => 'Half second '.$index, 'status' => 'draft', 'is_billable' => true,
            ]);
        }
        $response = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/time-tracking/statistics?project_id='.$project->id)->assertOk();
        self::assertSame(0.02, (float) $response->json('data.total_hours'));
        self::assertSame(0.02, (float) $response->json('data.billable_hours'));
        self::assertSame(120, $response->json('data.entries_count'));
    }

    public function test_admin_time_tracking_contract_supports_registry_calendar_statistics_and_report(): void
    {
        $context = $this->timeTrackingContext();
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
            ->getJson('/api/v1/admin/time-tracking?'.http_build_query([
                'project_id' => $project->id,
                'per_page' => 15,
            ]));

        $indexResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.project_id', $project->id);

        $statisticsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/time-tracking/statistics?'.http_build_query([
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
            ->getJson('/api/v1/admin/time-tracking/calendar?'.http_build_query([
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
            ->getJson('/api/v1/admin/time-tracking/reports?'.http_build_query([
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
        $context = $this->timeTrackingContext();
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
        $context = $this->timeTrackingContext();
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
                            && ! isset($payload['entries'], $payload['summary'])
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
            ->get('/api/v1/admin/time-tracking/export?'.http_build_query([
                'project_id' => $project->id,
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-31',
                'billable' => true,
                'format' => 'pdf',
            ]));

        $response->assertOk();
    }

    private function timeTrackingContext(): AdminApiTestContext
    {
        $context = AdminApiTestContext::create();
        $definition = json_decode((string) file_get_contents(config_path('ModuleList/features/time-tracking.json')), true, flags: JSON_THROW_ON_ERROR);
        \App\Models\Module::query()->updateOrCreate(['slug' => 'time-tracking'], [
            'name' => $definition['name'], 'version' => $definition['version'], 'type' => $definition['type'],
            'billing_model' => $definition['billing_model'], 'category' => $definition['category'],
            'permissions' => $definition['permissions'], 'is_active' => true, 'is_system_module' => false, 'can_deactivate' => true,
        ]);
        $account = \App\Models\OrganizationCommercialAccount::query()->create([
            'organization_id' => $context->organization->id, 'responsible_user_id' => $context->user->id,
            'status' => 'active', 'offer_type' => 'packages', 'quote_version' => 1,
            'current_period_start_at' => now(), 'current_period_end_at' => now()->addDays(30),
        ]);
        \App\Models\OrganizationPackageSubscription::query()->create([
            'organization_id' => $context->organization->id, 'commercial_account_id' => $account->id,
            'package_slug' => 'workforce-output', 'status' => 'active', 'access_source' => 'paid_package', 'price_paid' => 7900,
            'current_period_start_at' => now(), 'current_period_end_at' => now()->addDays(30),
        ]);
        app(\App\Modules\Core\AccessController::class)->clearAccessCache((int) $context->organization->id);
        \Illuminate\Support\Facades\Cache::flush();

        return $context;
    }
}
