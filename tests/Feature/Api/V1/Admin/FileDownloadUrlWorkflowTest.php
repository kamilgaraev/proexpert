<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Module;
use App\Models\Organization;
use App\Models\PersonalFile;
use App\Models\Project;
use App\Models\ReportFile;
use App\Models\User;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Storage\FileService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class FileDownloadUrlWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private array $moduleSlugs = ['file-management'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModuleSlugs')->andReturnUsing(fn (): array => $this->moduleSlugs);
        $entitlements->shouldReceive('getEffectiveModules')->andReturnUsing(fn () => collect($this->moduleSlugs)->map(static fn (string $slug): Module => new Module(['slug' => $slug])));
        $this->app->instance(OrganizationEntitlementService::class, $entitlements);
    }

    public function test_personal_metadata_list_skips_report_access_without_changing_rows(): void
    {
        $context = AdminApiTestContext::create();
        $file = $this->personalReport($context);
        $this->forbidStorageUrls();
        $reads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$reads): void {
            if (str_contains($query->sql, 'ai_assistant_report_access')) {
                $reads++;
            }
        });

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/personal-files?folder=reports%2Fassistant&include_download_urls=0');

        $response->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $file->id)
            ->assertJsonPath('data.0.path', 'reports/assistant/report.pdf')
            ->assertJsonPath('data.0.download_url', null);
        self::assertArrayNotHasKey('storage_key', $response->json('data.0'));
        self::assertSame(0, $reads, 'Metadata listing must not resolve report access.');
    }

    public function test_report_metadata_list_skips_classification_and_access_without_changing_rows(): void
    {
        $context = AdminApiTestContext::create();
        $personalFile = $this->personalReport($context);
        $file = $this->reportFile($context, $personalFile);
        $this->forbidStorageUrls();
        $reads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$reads): void {
            if (str_contains($query->sql, 'ai_assistant_report_access')) {
                $reads++;
            }
        });

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/report-files?include_download_urls=0');

        $response->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $file->id)
            ->assertJsonPath('data.0.filename', 'report.pdf')
            ->assertJsonPath('data.0.download_url', null);
        self::assertSame(0, $reads, 'Metadata listing must not classify or resolve report access.');
    }

    public function test_list_download_url_flag_rejects_invalid_values(): void
    {
        $context = AdminApiTestContext::create();
        foreach (['personal-files', 'report-files'] as $route) {
            $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/'.$route.'?include_download_urls=invalid')
                ->assertUnprocessable()->assertJsonValidationErrors('include_download_urls');
        }
    }

    public function test_personal_legacy_and_explicit_url_lists_keep_signing_while_click_resolves_again(): void
    {
        $context = AdminApiTestContext::create();
        $file = $this->personalReport($context, ['directory' => 'docs']);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('temporaryDownloadUrl')->times(4)->with($file->storage_key, 300, $file->original_name)
            ->andReturn('https://download.example.test/legacy', 'https://download.example.test/explicit', 'https://download.example.test/click-one', 'https://download.example.test/click-two');
        $this->app->instance(FileService::class, $files);

        foreach (['' => 'legacy', '&include_download_urls=1' => 'explicit'] as $query => $suffix) {
            $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/personal-files?folder=docs'.$query)
                ->assertOk()->assertJsonPath('data.0.download_url', 'https://download.example.test/'.$suffix);
        }
        foreach (['click-one', 'click-two'] as $suffix) {
            $response = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/personal-files/'.$file->id.'/download-url');
            $response->assertOk()->assertJsonPath('data.download_url', 'https://download.example.test/'.$suffix);
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_report_legacy_owner_download_url_preserves_storage_checks_and_explicit_list_mode(): void
    {
        $context = AdminApiTestContext::create();
        $personalFile = $this->personalReport($context, ['directory' => 'docs']);
        $file = $this->reportFile($context, $personalFile, ['organization_id' => null]);
        Storage::disk('s3')->put($file->path, 'report');
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn (string $path): string => 'https://download.example.test/'.rawurlencode($path));
        $expected = 'https://download.example.test/'.rawurlencode($file->path);

        foreach (['', '?include_download_urls=1'] as $query) {
            $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/report-files'.$query)
                ->assertOk()->assertJsonPath('data.0.download_url', $expected);
        }
        $response = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/report-files/'.$file->id.'/download-url');
        $response->assertOk()->assertJsonPath('data.download_url', $expected);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        Storage::disk('s3')->delete($file->path);
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/report-files/'.$file->id.'/download-url')->assertNotFound();
    }

    public function test_download_url_lookup_rejects_foreign_owners_organizations_and_folders(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->create();
        $colleague = User::factory()->create();
        $this->forbidStorageUrls();
        foreach ([['organization_id' => $foreignOrganization->id], ['user_id' => $colleague->id]] as $attributes) {
            $personal = $this->personalReport($context, $attributes);
            $report = $this->reportFile($context, $personal, $attributes);
            $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/personal-files/'.$personal->id.'/download-url')->assertNotFound();
            $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/report-files/'.$report->id.'/download-url')->assertNotFound();
        }
        $folder = $this->personalReport($context, ['storage_key' => null, 'directory' => '', 'original_name' => 'folder', 'is_folder' => true]);
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/personal-files/'.$folder->id.'/download-url')->assertNotFound();
        $foreignLegacy = $this->reportFile($context, $this->personalReport($context), ['organization_id' => null, 'user_id' => $colleague->id]);
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/report-files/'.$foreignLegacy->id.'/download-url')->assertNotFound();
    }

    public function test_assistant_download_urls_and_existing_stream_recheck_project_module_and_expiry(): void
    {
        $context = AdminApiTestContext::create(['is_active' => true]);
        $this->moduleSlugs = ['file-management', 'ai-assistant', 'reports', 'project-management', 'payments'];
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $context->organization->id, 'name' => 'Download test', 'slug' => 'download_'.Str::lower(Str::random(16)),
            'system_permissions' => [], 'module_permissions' => ['ai-assistant' => ['ai_assistant.chat']],
            'interface_access' => ['admin'], 'is_active' => true, 'created_by' => $context->user->id,
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $context->user->id, 'role_slug' => $role->slug, 'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'custom_role_id' => $role->id, 'context_id' => AuthorizationContext::getOrganizationContext($context->organization->id)->id,
            'assigned_by' => $context->user->id, 'is_active' => true,
        ]);
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'assigned_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $personal = $this->personalReport($context);
        $report = $this->reportFile($context, $personal);
        $grant = app(AssistantReportAccessService::class)->register($personal->storage_key, $context->organization, $context->user,
            [['entity_type' => 'project', 'entity_id' => (string) $project->id]], ['projects']);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('disk')->once()->andReturn(Storage::disk('s3'));
        $files->shouldNotReceive('temporaryDownloadUrl');
        $files->shouldReceive('readCurrent')->twice()->with($personal->storage_key)->andReturnUsing(static function () {
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, 'report');
            rewind($stream);

            return $stream;
        });
        $this->app->instance(FileService::class, $files);
        foreach (['/api/v1/admin/personal-files?folder=reports%2Fassistant', '/api/v1/admin/report-files?include_download_urls=1'] as $route) {
            $legacy = $this->withHeaders($context->authHeaders())->getJson($route)->assertOk();
            self::assertStringStartsWith('/api/v1/ai-assistant/reports/', $legacy->json('data.0.download_url'));
        }
        $routes = ['/api/v1/admin/personal-files/'.$personal->id.'/download-url', '/api/v1/admin/report-files/'.$report->id.'/download-url'];
        $downloadUrls = [];
        foreach ($routes as $route) {
            $response = $this->withHeaders($context->authHeaders())->getJson($route)->assertOk();
            $url = $response->json('data.download_url');
            self::assertIsString($url);
            self::assertStringStartsWith('/api/v1/admin/ai-assistant/reports/', $url);
            $downloadUrls[] = $url;
        }
        foreach ($downloadUrls as $url) {
            $download = $this->withHeaders($context->authHeaders())->get($url)->assertOk();
            self::assertSame('report', $download->streamedContent());
        }

        $context->user->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        foreach ([...$routes, ...$downloadUrls] as $route) {
            $this->withHeaders($context->authHeaders())->getJson($route)->assertForbidden();
        }
        $context->user->assignedProjects()->updateExistingPivot($project->id, ['is_active' => true]);
        $this->moduleSlugs = array_values(array_diff($this->moduleSlugs, ['reports']));
        foreach ([...$routes, ...$downloadUrls] as $route) {
            $this->withHeaders($context->authHeaders())->getJson($route)->assertForbidden();
        }
        DB::table('ai_assistant_report_access')->where('id', $grant['report_access_id'])->update(['expires_at' => now()->subSecond()]);
        foreach ($routes as $route) {
            $this->withHeaders($context->authHeaders())->getJson($route)->assertGone();
        }
    }

    private function forbidStorageUrls(): void
    {
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('disk');
        $files->shouldNotReceive('temporaryDownloadUrl');
        $files->shouldNotReceive('readCurrent');
        $this->app->instance(FileService::class, $files);
    }

    private function reportFile(AdminApiTestContext $context, PersonalFile $personal, array $attributes = []): ReportFile
    {
        return ReportFile::query()->create(array_replace([
            'organization_id' => $context->organization->id, 'user_id' => $context->user->id,
            'path' => $personal->storage_key, 'filename' => 'report.pdf', 'name' => 'report.pdf', 'type' => 'pdf',
            'size' => 10, 'expires_at' => now()->addYear(),
        ], $attributes));
    }

    private function personalReport(AdminApiTestContext $context, array $attributes = []): PersonalFile
    {
        return PersonalFile::query()->create(array_replace([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'storage_key' => 'org-'.$context->organization->id.'/reports/assistant/'.Str::uuid().'.pdf',
            'directory' => 'reports/assistant',
            'original_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'sha256' => str_repeat('a', 64),
            'size' => 10,
            'is_folder' => false,
        ], $attributes));
    }
}
