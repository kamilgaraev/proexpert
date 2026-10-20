<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Module;
use App\Models\PersonalFile;
use App\Models\ReportFile;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Storage\FileService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class FileListPaginationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModuleSlugs')->andReturn(['file-management']);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([new Module(['slug' => 'file-management'])]));
        $this->app->instance(OrganizationEntitlementService::class, $entitlements);
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('disk');
        $files->shouldNotReceive('temporaryDownloadUrl');
        $this->app->instance(FileService::class, $files);
    }

    public function test_personal_pages_keep_tied_sort_values_stable_and_all_sixteen_rows_reachable(): void
    {
        $context = AdminApiTestContext::create(['is_active' => true]);
        $ids = [];
        for ($i = 1; $i <= 16; $i++) {
            $id = str_pad((string) $i, 26, '0', STR_PAD_LEFT);
            $file = new PersonalFile([
                'id' => $id, 'organization_id' => $context->organization->id, 'user_id' => $context->user->id,
                'storage_key' => 'org-'.$context->organization->id.'/reports/assistant/'.$id.'.pdf',
                'directory' => 'reports/assistant', 'original_name' => 'same.pdf', 'mime_type' => 'application/pdf',
                'size' => 10, 'is_folder' => false,
            ]);
            $file->forceFill(['created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00'])->save();
            $ids[] = $id;
        }
        $this->assertStablePages($context, '/api/v1/admin/personal-files?folder=reports%2Fassistant', $ids, 15);
    }

    public function test_report_pages_keep_tied_sort_values_stable_and_all_one_hundred_one_rows_reachable(): void
    {
        $context = AdminApiTestContext::create(['is_active' => true]);
        $ids = [];
        for ($i = 1; $i <= 101; $i++) {
            $id = str_pad((string) $i, 26, '0', STR_PAD_LEFT);
            $file = new ReportFile([
                'id' => $id, 'organization_id' => $context->organization->id, 'user_id' => $context->user->id,
                'path' => 'org-'.$context->organization->id.'/reports/'.($i === 1 ? 'tail-only/' : '').$id.'.pdf',
                'filename' => 'same.pdf', 'name' => 'same.pdf', 'type' => 'pdf', 'size' => 10,
            ]);
            $file->forceFill(['created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00'])->save();
            $ids[] = $id;
        }
        $this->assertStablePages($context, '/api/v1/admin/report-files?', $ids, 100);
    }

    public function test_file_lists_reject_a_reversed_date_range(): void
    {
        $context = $this->dateFilterContext();
        foreach (['report-files', 'act-files', 'personal-files'] as $route) {
            $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/'.$route.'?'.http_build_query([
                'date_from' => '2026-10-21', 'date_to' => '2026-10-20', 'include_download_urls' => 0,
            ]))->assertUnprocessable()->assertJsonValidationErrors('date_to')
                ->assertJsonPath('errors.date_to.0', trans_message('reports.validation.date_to_after_or_equal'));
        }
    }

    public function test_file_lists_accept_equal_ordered_and_one_sided_dates(): void
    {
        $context = $this->dateFilterContext();
        foreach (['report-files', 'act-files', 'personal-files'] as $route) {
            foreach ([
                ['date_from' => '2026-10-20', 'date_to' => '2026-10-20'],
                ['date_from' => '2026-10-19', 'date_to' => '2026-10-20'],
                ['date_from' => '2026-10-20'],
                ['date_to' => '2026-10-20'],
                ['date_from' => '', 'date_to' => '2026-10-20'],
                ['date_from' => '2026-10-20', 'date_to' => ''],
                ['date_from' => '', 'date_to' => ''],
            ] as $dates) {
                $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/'.$route.'?'.http_build_query([
                    ...$dates, 'include_download_urls' => 0,
                ]))->assertOk();
            }
        }
    }

    public function test_file_lists_compare_datetime_bounds_as_calendar_dates(): void
    {
        $context = $this->dateFilterContext();
        foreach (['report-files', 'act-files', 'personal-files'] as $route) {
            foreach ([
                ['date_from' => '2026-10-20 23:00', 'date_to' => '2026-10-20 01:00'],
                ['date_from' => '2026-10-20T23:00:00+03:00', 'date_to' => '2026-10-20T01:00:00+03:00'],
                ['date_from' => '2026-10-20 23:00', 'date_to' => '2026-10-20'],
            ] as $dates) {
                $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/'.$route.'?'.http_build_query([
                    ...$dates, 'include_download_urls' => 0,
                ]))->assertOk();
            }
            $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/'.$route.'?'.http_build_query([
                'date_from' => '2026-10-20T00:30:00+14:00', 'date_to' => '2026-10-19T23:30:00-12:00',
                'include_download_urls' => 0,
            ]))->assertUnprocessable()->assertJsonValidationErrors('date_to');
            foreach (['date_from', 'date_to'] as $invalidField) {
                $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/'.$route.'?'.http_build_query([
                    'date_from' => '2026-10-20', 'date_to' => '2026-10-20', $invalidField => 'not-a-date',
                    'include_download_urls' => 0,
                ]))->assertUnprocessable()->assertJsonValidationErrors($invalidField);
            }
        }
    }

    private function dateFilterContext(): AdminApiTestContext
    {
        $actModule = Module::query()->create([
            'name' => 'Управление актами', 'slug' => 'act-reporting', 'version' => '1.0.0',
            'type' => 'core', 'billing_model' => 'free',
            'class_name' => \App\BusinessModules\Addons\ActReporting\ActReportingModule::class,
            'is_active' => true, 'is_system_module' => true, 'can_deactivate' => false,
        ]);
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModuleSlugs')->andReturn(['file-management', 'act-reporting']);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([new Module(['slug' => 'file-management']), $actModule]));
        $this->app->instance(OrganizationEntitlementService::class, $entitlements);

        return AdminApiTestContext::create(['is_active' => true]);
    }

    private function assertStablePages(AdminApiTestContext $context, string $route, array $ids, int $perPage): void
    {
        $grantReads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$grantReads): void {
            if (str_contains($query->sql, 'ai_assistant_report_access')) {
                $grantReads++;
            }
        });
        foreach (['created_at', 'size', 'filename'] as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $expected = $direction === 'asc' ? $ids : array_reverse($ids);
                $actual = [];
                foreach ([1, 2] as $page) {
                    $response = $this->withHeaders($context->authHeaders())->getJson($route.'&'.http_build_query([
                        'page' => $page, 'per_page' => $perPage, 'sort_by' => $sort, 'sort_dir' => $direction,
                        'include_download_urls' => 0,
                    ]))->assertOk()->assertJsonPath('meta.total', count($ids))->assertJsonPath('meta.last_page', 2)
                        ->assertJsonPath('meta.current_page', $page);
                    $rows = $response->json('data');
                    self::assertSame(array_slice($expected, ($page - 1) * $perPage, $perPage), array_column($rows, 'id'));
                    self::assertSame([null], array_values(array_unique(array_column($rows, 'download_url'))));
                    $actual = [...$actual, ...array_column($rows, 'id')];
                }
                self::assertSame($expected, $actual);
                self::assertCount(count($ids), array_unique($actual));
            }
        }
        self::assertSame(0, $grantReads);
    }
}
