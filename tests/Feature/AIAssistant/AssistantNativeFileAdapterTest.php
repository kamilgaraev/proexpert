<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeFileMetadata;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\File;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantNativeFileAdapterTest extends TestCase
{
    use RefreshDatabase;

    private string $content = "employee,hours,amount\nИван,8,1200.50\n";
    private int $reads = 0;

    public function test_current_native_export_registers_idempotent_metadata_and_real_document(): void
    {
        [$fixture, $sourceId, $path] = $this->fixture();
        $adapter = $this->adapter();
        $file = $adapter->map($fixture->owner, $fixture->organization->id, AssistantNativeFileMetadata::ENTITY_TYPE, $sourceId);
        $repeat = $adapter->map($fixture->owner, $fixture->organization->id, AssistantNativeFileMetadata::ENTITY_TYPE, $sourceId);
        self::assertSame($file->id, $repeat->id);
        self::assertSame($file->id, $adapter->mapForIndexing($fixture->organization->id, $sourceId)?->id);
        DB::table('workforce_export_packages')->where('id', DB::table('workforce_export_package_files')->where('id', $sourceId)->value('export_package_id'))
            ->update(['created_by_user_id' => $fixture->member->id]);
        self::assertNull($adapter->mapForIndexing($fixture->organization->id, $sourceId));
        AssistantDocumentSettings::query()->create(['organization_id' => $fixture->organization->id, 'approved_by' => $fixture->owner->id, 'approved_at' => now()]);
        self::assertSame($file->id, $adapter->mapForIndexing($fixture->organization->id, $sourceId)?->id);
        self::assertSame(1, File::query()->where('additional_info->assistant_native_source', AssistantNativeFileMetadata::SOURCE)->count());
        self::assertSame(hash('sha256', $this->content), $file->additional_info['native_source_sha256']);
        $adapter->assertReadable($fixture->owner, $fixture->organization->id, $file);
        $query = File::query();
        $adapter->constrainMappings($query);
        self::assertSame($file->id, $query->firstOrFail()->id);
        $document = app(AssistantDocumentService::class)->register($fixture->owner, $fixture->organization->id,
            AssistantNativeFileMetadata::ENTITY_TYPE, $sourceId, $path, 'client-name.csv', 'text/csv');
        self::assertSame($file->id, $document->file_id);
        self::assertSame('payroll-source.csv', $document->filename);
        self::assertSame(hash('sha256', $this->content), $document->checksum);
    }

    public function test_same_path_binary_change_and_current_row_change_invalidate_previous_mapping(): void
    {
        [$fixture, $sourceId] = $this->fixture();
        $adapter = $this->adapter();
        $file = $adapter->map($fixture->owner, $fixture->organization->id, AssistantNativeFileMetadata::ENTITY_TYPE, $sourceId);
        $this->content = str_replace('1200.50', '1500.50', $this->content);
        try {
            $adapter->assertReadable($fixture->owner, $fixture->organization->id, $file);
            self::fail('Changed bytes must invalidate the previously indexed file.');
        } catch (RuntimeException $exception) {
            self::assertSame('ai_assistant_document_checksum_changed', $exception->getMessage());
        }
        $replacement = $adapter->map($fixture->owner, $fixture->organization->id, AssistantNativeFileMetadata::ENTITY_TYPE, $sourceId);
        self::assertNotSame($file->id, $replacement->id);
        self::assertTrue(File::withTrashed()->findOrFail($file->id)->trashed());
        self::assertSame(hash('sha256', $this->content), $replacement->additional_info['native_source_sha256']);
        DB::table('workforce_export_package_files')->where('id', $sourceId)->update(['size_bytes' => strlen($this->content) + 1]);
        $query = File::query();
        $adapter->constrainMappings($query);
        self::assertSame(0, $query->count());
        $before = $this->reads;
        try { $adapter->assertReadable($fixture->owner, $fixture->organization->id, $replacement); self::fail('Changed row must invalidate mapping.'); }
        catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_source_changed', $exception->getMessage()); }
        self::assertSame($before, $this->reads);
    }

    public function test_revoked_parent_foreign_organization_and_unsafe_path_deny_before_storage_or_metadata(): void
    {
        [$fixture, $sourceId] = $this->fixture();
        $adapter = $this->adapter();
        foreach ([[$fixture->member, $fixture->organization->id], [$fixture->foreignOwner, $fixture->organization->id]] as [$actor, $organizationId]) {
            try { $adapter->map($actor, $organizationId, AssistantNativeFileMetadata::ENTITY_TYPE, $sourceId); self::fail('Unauthorized native access.'); }
            catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_access_denied', $exception->getMessage()); }
        }
        self::assertSame(0, $this->reads);
        self::assertSame(0, File::query()->count());
        $file = $adapter->map($fixture->owner, $fixture->organization->id, AssistantNativeFileMetadata::ENTITY_TYPE, $sourceId);
        $before = $this->reads;
        $fixture->ownerAssignment->update(['is_active' => false]);
        self::assertTrue(app(AuthorizationService::class)->canCurrent($fixture->owner, 'finance.view', ['organization_id' => $fixture->organization->id]));
        self::assertFalse(app(AuthorizationService::class)->canCurrent($fixture->owner, 'workforce.view', ['organization_id' => $fixture->organization->id]));
        self::assertNull($adapter->mapForIndexing($fixture->organization->id, $sourceId));
        self::assertNull($adapter->mapForIndexing($fixture->foreignOrganization->id, $sourceId));
        try { $adapter->assertReadable($fixture->owner, $fixture->organization->id, $file); self::fail('Revoked role must deny.'); }
        catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_access_denied', $exception->getMessage()); }
        self::assertSame($before, $this->reads);
        $fixture->ownerAssignment->update(['is_active' => true]);
        DB::table('workforce_export_packages')->where('id', DB::table('workforce_export_package_files')->where('id', $sourceId)->value('export_package_id'))
            ->update(['organization_id' => $fixture->foreignOrganization->id]);
        try { $adapter->map($fixture->owner, $fixture->organization->id, AssistantNativeFileMetadata::ENTITY_TYPE, $sourceId); self::fail('Foreign parent must deny.'); }
        catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_access_denied', $exception->getMessage()); }
        self::assertSame($before, $this->reads);
        DB::table('workforce_export_packages')->where('id', DB::table('workforce_export_package_files')->where('id', $sourceId)->value('export_package_id'))
            ->update(['organization_id' => $fixture->organization->id]);
        DB::table('workforce_export_package_files')->where('id', $sourceId)->update(['storage_path' => 'https://external.example/credentials']);
        try { $adapter->map($fixture->owner, $fixture->organization->id, AssistantNativeFileMetadata::ENTITY_TYPE, $sourceId); self::fail('External path must deny.'); }
        catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_native_source_invalid', $exception->getMessage()); }
        self::assertSame($before, $this->reads);
    }

    private function adapter(): AssistantNativeFileAdapter
    {
        $storage = Mockery::mock(FileService::class);
        $storage->shouldReceive('readCurrentBounded')->andReturnUsing(function (): mixed {
            $this->reads++;
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $this->content);
            rewind($stream);
            return $stream;
        });
        $this->app->instance(FileService::class, $storage);
        $adapter = new AssistantNativeFileAdapter(app(AssistantDataAccessPolicy::class), app(AuthorizationService::class), $storage);
        $this->app->instance(AssistantNativeFileAdapter::class, $adapter);
        return $adapter;
    }

    private function fixture(): array
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'workforce-output']);
        $financialRole = OrganizationCustomRole::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Чтение источника расчёта',
            'slug' => 'assistant_native_finance_'.$fixture->organization->id, 'system_permissions' => ['finance.view'],
            'module_permissions' => [], 'interface_access' => ['admin', 'lk', 'mobile'], 'is_active' => true,
            'created_by' => $fixture->owner->id,
        ]);
        $fixture->owner->roleAssignments()->create([
            'role_slug' => $financialRole->slug, 'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'context_id' => $fixture->ownerAssignment->context_id, 'assigned_by' => $fixture->owner->id, 'is_active' => true,
        ]);
        foreach (AssistantNativeFileMetadata::PERMISSIONS as $permission) {
            self::assertTrue(app(AuthorizationService::class)->canCurrent($fixture->owner, $permission,
                ['organization_id' => $fixture->organization->id]), 'Fixture must explicitly grant '.$permission);
        }
        $periodId = DB::table('workforce_payroll_periods')->insertGetId(['organization_id' => $fixture->organization->id,
            'project_id' => null, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'status' => 'locked', 'created_at' => now(), 'updated_at' => now()]);
        $key = '20260929140000-0123456789abcdef';
        $packageId = DB::table('workforce_export_packages')->insertGetId(['organization_id' => $fixture->organization->id,
            'payroll_period_id' => $periodId, 'package_number' => 'WF-'.$periodId.'-'.$key, 'source_hash' => hash('sha256', 'payroll-source'),
            'status' => 'created', 'created_by_user_id' => $fixture->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $path = 'org-'.$fixture->organization->id.'/workforce/payroll-exports/period-'.$periodId.'/package-'.$key.'/payroll-source.csv';
        $id = DB::table('workforce_export_package_files')->insertGetId(['organization_id' => $fixture->organization->id,
            'export_package_id' => $packageId, 'file_type' => 'source_csv', 'file_name' => 'payroll-source.csv', 'storage_disk' => 's3',
            'storage_path' => $path, 'size_bytes' => strlen($this->content), 'created_at' => now(), 'updated_at' => now()]);
        return [$fixture, (int) $id, $path];
    }
}
