<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class TimeEntryImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_preview_is_scoped_and_commit_is_replayable(): void
    {
        $context = $this->timeTrackingContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $path = $this->workbook([['project', 'worker', 'date', 'hours', 'title'], [$project->id, 'Бригада', '2026-01-31', 2.5, 'Монтаж']]);
        try {
            $preview = $this->withHeaders($context->authHeaders())->post('/api/v1/admin/time-tracking/import/preview', [
                'file' => new UploadedFile($path, 'hours.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
                'mapping' => ['project_id' => 'project', 'worker_name' => 'worker', 'work_date' => 'date', 'hours_worked' => 'hours', 'title' => 'title'],
            ])->assertOk();
            // Default user worker requires user_id; correcting the mapping is a real preview operation.
            $preview->assertJsonPath('data.can_commit', false)->assertJsonPath('data.valid_rows', 0);
            self::assertDatabaseCount('time_entries', 0);
        } finally {
            unlink($path);
        }
        $path = $this->workbook([['project', 'worker', 'type', 'date', 'hours', 'title'], [$project->id, 'Бригада', 'virtual', '2026-01-31', 2.5, 'Монтаж']]);
        try {
            $preview = $this->withHeaders($context->authHeaders())->post('/api/v1/admin/time-tracking/import/preview', [
                'file' => new UploadedFile($path, 'hours.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
                'mapping' => ['project_id' => 'project', 'worker_name' => 'worker', 'worker_type' => 'type', 'work_date' => 'date', 'hours_worked' => 'hours', 'title' => 'title'],
            ])->assertOk()->assertJsonPath('data.can_commit', true)->assertJsonPath('data.valid_rows', 1);
            $token = $preview->json('data.preview_token');
            $first = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/time-tracking/import/commit', ['preview_token' => $token])->assertOk()->assertJsonPath('data.created_count', 1)->json('data.entry_ids');
            $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/time-tracking/import/commit', ['preview_token' => $token])->assertOk()->assertJsonPath('data.entry_ids', $first);
            self::assertSame(1, TimeEntry::query()->where('organization_id', $context->organization->id)->count());
            $foreign = $this->timeTrackingContext();
            $this->withHeaders($foreign->authHeaders())->postJson('/api/v1/admin/time-tracking/import/commit', ['preview_token' => $token])->assertStatus(422);
        } finally {
            unlink($path);
        }
    }

    public function test_foreign_project_rows_cannot_be_committed(): void
    {
        $context = $this->timeTrackingContext();
        $foreign = $this->timeTrackingContext();
        $project = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $row = [$project->id, 'virtual', 'Бригада', '2026-01-31', 2, 'Работа'];
        $path = $this->workbook([['project', 'type', 'worker', 'date', 'hours', 'title'], $row, $row]);
        try {
            $preview = $this->withHeaders($context->authHeaders())->post('/api/v1/admin/time-tracking/import/preview', [
                'file' => new UploadedFile($path, 'hours.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
                'mapping' => ['project_id' => 'project', 'worker_type' => 'type', 'worker_name' => 'worker', 'work_date' => 'date', 'hours_worked' => 'hours', 'title' => 'title'],
            ])->assertOk()->assertJsonPath('data.can_commit', false)->assertJsonPath('data.valid_rows', 0);
            self::assertNotEmpty($preview->json('data.rows.0.errors.project_id'));
            $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/time-tracking/import/commit', ['preview_token' => $preview->json('data.preview_token')])->assertStatus(422);
            self::assertDatabaseCount('time_entries', 0);
        } finally {
            unlink($path);
        }
    }

    public function test_duplicate_excel_rows_have_row_errors_and_prevent_commit(): void
    {
        $context = $this->timeTrackingContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $row = [$project->id, 'virtual', 'Бригада', '2026-01-31', 2, 'Работа'];
        $path = $this->workbook([['project', 'type', 'worker', 'date', 'hours', 'title'], $row, $row]);
        try {
            $preview = $this->withHeaders($context->authHeaders())->post('/api/v1/admin/time-tracking/import/preview', [
                'file' => new UploadedFile($path, 'hours.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
                'mapping' => ['project_id' => 'project', 'worker_type' => 'type', 'worker_name' => 'worker', 'work_date' => 'date', 'hours_worked' => 'hours', 'title' => 'title'],
            ])->assertOk()->assertJsonPath('data.can_commit', false)->assertJsonPath('data.valid_rows', 1);
            self::assertNotEmpty($preview->json('data.rows.1.errors.row'));
            $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/time-tracking/import/commit', ['preview_token' => $preview->json('data.preview_token')])->assertStatus(422);
        } finally {
            unlink($path);
        }
    }

    public function test_excel_row_matching_a_manually_created_entry_is_rejected(): void
    {
        $context = $this->timeTrackingContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        TimeEntry::create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'worker_type' => 'virtual', 'worker_name' => 'Бригада', 'work_date' => '2026-01-31',
            'hours_worked' => 2, 'title' => 'Работа', 'status' => 'draft', 'is_billable' => true,
        ]);
        $path = $this->workbook([['project', 'type', 'worker', 'date', 'hours', 'title'], [$project->id, 'virtual', 'Бригада', '2026-01-31', 2, 'Работа']]);
        try {
            $preview = $this->withHeaders($context->authHeaders())->post('/api/v1/admin/time-tracking/import/preview', [
                'file' => new UploadedFile($path, 'hours.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
                'mapping' => ['project_id' => 'project', 'worker_type' => 'type', 'worker_name' => 'worker', 'work_date' => 'date', 'hours_worked' => 'hours', 'title' => 'title'],
            ])->assertOk()->assertJsonPath('data.can_commit', false)->assertJsonPath('data.valid_rows', 0);
            self::assertNotEmpty($preview->json('data.rows.0.errors.row'));
            self::assertDatabaseCount('time_entries', 1);
        } finally {
            unlink($path);
        }
    }

    public function test_import_does_not_duplicate_legacy_interval_because_its_hours_were_rounded(): void
    {
        $context = $this->timeTrackingContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        TimeEntry::create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'worker_type' => 'virtual', 'worker_name' => 'Бригада', 'work_date' => '2026-01-31',
            'start_time' => '08:00', 'end_time' => '08:01', 'hours_worked' => 0.02,
            'title' => 'Работа', 'status' => 'draft', 'is_billable' => true,
        ]);
        $path = $this->workbook([['project', 'type', 'worker', 'date', 'start', 'end', 'title'], [$project->id, 'virtual', 'Бригада', '2026-01-31', '08:00', '08:01', 'Работа']]);
        try {
            $preview = $this->withHeaders($context->authHeaders())->post('/api/v1/admin/time-tracking/import/preview', [
                'file' => new UploadedFile($path, 'hours.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
                'mapping' => ['project_id' => 'project', 'worker_type' => 'type', 'worker_name' => 'worker', 'work_date' => 'date', 'start_time' => 'start', 'end_time' => 'end', 'title' => 'title'],
            ])->assertOk()->assertJsonPath('data.can_commit', false)->assertJsonPath('data.valid_rows', 0);
            self::assertNotEmpty($preview->json('data.rows.0.errors.row'));
            self::assertDatabaseCount('time_entries', 1);
        } finally {
            unlink($path);
        }
    }

    private function workbook(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'time-import-test-');
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
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
