<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileIndexer;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseItemGallery;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectPhoto;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyMedicalExam;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Jobs\ProcessAssistantDocument;
use App\Models\File;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantOperationsNativeFileAdapterTest extends TestCase
{
    private string $body = 'Current clinical examination: fit for work.';
    private int $reads = 0;

    public function test_actual_medical_full_view_processes_without_invented_medical_or_hr_permission_and_revocation_precedes_storage(): void
    {
        [$fixture, $actor, $project] = $this->fixture('safety-management', 'safety-management.view');
        [$exam, $file] = $this->medical($fixture, $actor, $project);
        $adapter = $this->adapter();
        $document = $adapter->map($actor, $fixture->organization->id, 'safety_medical_exam', $exam->id);
        self::assertNull($document->file_id);
        self::assertSame((string) $file->id, $document->metadata['native_file_id']);
        self::assertSame((string) $exam->id, $document->parent_entity_id);
        self::assertTrue($adapter->isNativeFile($file));
        self::assertFalse(app(AuthorizationService::class)->canCurrent($actor, 'workforce.employees.basic', ['organization_id' => $fixture->organization->id]));
        $processed = app(AssistantDocumentService::class)->process($document->id);
        self::assertSame('ready', $processed->status);
        self::assertSame($this->body, trim($processed->extracted_text));
        self::assertGreaterThan(0, AIAssistantDocumentUnit::query()->where('document_id', $document->id)->count());
        self::assertSame($document->id, $adapter->mapForIndexing($fixture->organization->id, $exam->id, 'safety_medical_exam')?->id);
        $before = $this->reads;
        $this->role($actor)->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view']]]);
        $this->denied(fn () => $adapter->content($actor, $fixture->organization->id, $processed), 'ai_assistant_document_access_denied');
        $this->denied(fn () => $adapter->assertFileReadable($actor, $fixture->organization->id, $file), 'ai_assistant_document_access_denied');
        self::assertSame($before, $this->reads);
        $query = File::query()->whereKey($file->id);
        $adapter->applyFileScope($query, $actor, $fixture->organization->id, app(AssistantDataAccessPolicy::class));
        self::assertSame(0, $query->count());
    }

    public function test_native_document_sql_timeout_is_not_converted_to_missing_access(): void
    {
        [$fixture, $actor, $project] = $this->fixture('safety-management', 'safety-management.view');
        [$exam] = $this->medical($fixture, $actor, $project);
        $document = $this->adapter()->map($actor, $fixture->organization->id, 'safety_medical_exam', $exam->id);
        $failOnce = true;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, $connection) use (&$failOnce): void {
            if ($failOnce && str_contains($sql, 'safety_medical_exams')) {
                $failOnce = false;
                throw new \Illuminate\Database\QueryException($connection->getName(), $sql, $bindings, new \PDOException('fixture_read_timeout'));
            }
        });
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('fixture_read_timeout');
        app(AssistantDataAccessPolicy::class)->canReadSource($actor, $fixture->organization->id, [
            'source_type' => 'file_document', 'entity_type' => 'assistant_document', 'entity_id' => $document->id,
        ]);
    }

    public function test_medical_reverse_parent_and_current_private_employee_projects_filter_before_limit_and_detached_history_never_falls_through(): void
    {
        [$fixture, $actor, $visible] = $this->fixture('safety-management', 'safety-management.view');
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $hiddenFiles = [];
        for ($index = 0; $index < 22; $index++) { [, $hiddenFiles[]] = $this->medical($fixture, $actor, $hidden); }
        [$exam, $file] = $this->medical($fixture, $actor, $visible);
        $adapter = $this->adapter();
        $query = File::query()->where('organization_id', $fixture->organization->id);
        $adapter->applyFileScope($query, $actor, $fixture->organization->id, app(AssistantDataAccessPolicy::class));
        self::assertSame([$file->id], $query->orderBy('id')->limit(1)->pluck('id')->all());
        self::assertSame(0, $this->reads);
        $this->denied(fn () => $adapter->map($actor, $fixture->organization->id, 'safety_medical_exam', SafetyMedicalExam::query()->where('file_id', $hiddenFiles[0]->id)->value('id')), 'ai_assistant_document_access_denied');
        $document = $adapter->map($actor, $fixture->organization->id, 'safety_medical_exam', $exam->id);
        $before = $this->reads;
        $foreignEmployee = WorkforceEmployee::withoutEvents(fn () => WorkforceEmployee::query()->create([
            'organization_id' => $fixture->foreignOrganization->id, 'personnel_number' => (string) Str::uuid(),
            'last_name' => 'Петров', 'first_name' => 'Пётр', 'hire_date' => now()->subYear()->toDateString(), 'employment_status' => 'active']));
        DB::table('safety_medical_exams')->where('id', $exam->id)->update(['employee_id' => $foreignEmployee->id]);
        $this->denied(fn () => $adapter->content($actor, $fixture->organization->id, $document), 'ai_assistant_document_access_denied');
        self::assertSame(0, $adapter->sourceQueryForActor($actor, $fixture->organization->id, 'safety_medical_exam')->where('native_source.id', $exam->id)->limit(1)->count());
        self::assertSame($before, $this->reads);
        DB::table('safety_medical_exams')->where('id', $exam->id)->update(['employee_id' => $exam->employee_id]);
        DB::table('safety_medical_exams')->where('id', $exam->id)->update(['file_id' => null]);
        self::assertTrue($adapter->isNativeFile($file));
        $this->denied(fn () => $adapter->assertFileReadable($actor, $fixture->organization->id, $file), 'ai_assistant_document_access_denied');
        $query = AIAssistantDocument::query()->whereKey($document->id);
        $adapter->constrainDocuments($query);
        self::assertSame(0, $query->count());
        self::assertSame($before, $this->reads);
    }

    public function test_quality_photo_uses_actual_photo_identity_verified_private_path_and_sha_and_denies_unverified_url_before_storage(): void
    {
        [$fixture, $actor, $project] = $this->fixture('quality-control', 'quality-control.view');
        $defect = QualityDefect::withoutEvents(fn () => QualityDefect::query()->create(['organization_id' => $fixture->organization->id,
            'project_id' => $project->id, 'defect_number' => 'NATIVE-'.Str::uuid(), 'title' => 'Дефект', 'severity' => 'major', 'status' => 'open']));
        $photo = QualityDefectPhoto::withoutEvents(fn () => QualityDefectPhoto::query()->create(['organization_id' => $fixture->organization->id,
            'quality_defect_id' => $defect->id, 'uploaded_by' => $actor->id, 'type' => 'before',
            'url' => 'org-'.$fixture->organization->id.'/quality-control/defects/'.$defect->id.'/'.Str::uuid().'.png',
            'storage_identity_verified' => true, 'storage_etag' => hash('md5', $this->body),
            'storage_sha256' => hash('sha256', $this->body), 'size_bytes' => strlen($this->body), 'mime_type' => 'image/png']));
        $adapter = $this->adapter();
        $document = $adapter->map($actor, $fixture->organization->id, 'quality_defect_photo', $photo->id);
        self::assertNull($document->file_id);
        self::assertNull($document->metadata['native_file_id']);
        self::assertSame((string) $photo->id, $document->parent_entity_id);
        self::assertSame(hash('sha256', $this->body), $document->checksum);
        self::assertSame($document->id, $adapter->mapForIndexing($fixture->organization->id, $photo->id, 'quality_defect_photo')?->id);
        $this->body = str_replace('fit', 'bad', $this->body);
        $this->denied(fn () => $adapter->content($actor, $fixture->organization->id, $document), 'ai_assistant_document_checksum_changed');
        $before = $this->reads;
        $photo->withoutEvents(fn () => $photo->update(['storage_identity_verified' => false, 'url' => 'https://example.test/private.png',
            'storage_etag' => null, 'storage_sha256' => null, 'size_bytes' => null, 'mime_type' => null]));
        $this->denied(fn () => $adapter->mapForIndexing($fixture->organization->id, $photo->id, 'quality_defect_photo'), 'ai_assistant_document_native_source_invalid');
        self::assertSame($before, $this->reads);
        $query = AIAssistantDocument::query()->whereKey($document->id);
        $adapter->constrainDocuments($query);
        self::assertSame(0, $query->count());
    }

    public function test_all_63_gallery_files_keep_distinct_real_file_identity_queue_after_commit_and_deleted_file_reconciles_durably(): void
    {
        [$fixture, $actor, $project] = $this->fixture('basic-warehouse', 'warehouse.view');
        [$gallery, $files] = $this->gallery($fixture, $actor, $project, 63);
        $adapter = $this->adapter();
        $indexer = new AssistantOperationsNativeFileIndexer($adapter);
        DB::beginTransaction();
        $indexer->prepare($fixture->organization->id, null, 'warehouse_item_gallery', $gallery->id);
        Queue::assertNotPushed(ProcessAssistantDocument::class);
        self::assertSame(63, AIAssistantDocument::query()->where('parent_entity_type', 'warehouse_item_gallery')->count());
        DB::rollBack();
        self::assertSame(0, AIAssistantDocument::query()->where('parent_entity_type', 'warehouse_item_gallery')->count());
        DB::beginTransaction();
        $indexer->prepare($fixture->organization->id, null, 'warehouse_item_gallery', $gallery->id);
        Queue::assertNotPushed(ProcessAssistantDocument::class);
        DB::commit();
        Queue::assertPushed(ProcessAssistantDocument::class, 63);
        $documents = AIAssistantDocument::query()->where('parent_entity_type', 'warehouse_item_gallery')->get();
        self::assertCount(63, $documents);
        self::assertCount(63, $documents->pluck('metadata.native_source_id')->unique());
        self::assertSame([(string) $gallery->id], $documents->pluck('parent_entity_id')->unique()->values()->all());
        self::assertSame(0, $documents->whereNotNull('file_id')->count());
        $first = $documents->firstWhere('metadata.native_source_id', (string) $files[0]->id);
        self::assertNotNull($first);
        $indexer->prepare($fixture->organization->id, null, 'warehouse_item_gallery', $gallery->id);
        self::assertSame(63, AIAssistantDocument::query()->where('parent_entity_type', 'warehouse_item_gallery')->count());
        File::withoutEvents(fn () => $files[0]->delete());
        $before = $this->reads;
        $indexer->prepare($fixture->organization->id, null, 'file', $files[0]->id);
        self::assertSame($before, $this->reads);
        self::assertSame('stale', $first->fresh()->coverage_status);
        self::assertSame('failed', $first->fresh()->status);
        self::assertTrue(RagIndexRun::query()->where('organization_id', $fixture->organization->id)->where('source_type', 'file_document')
            ->where('entity_type', 'assistant_document')->where('entity_id', (string) $first->id)->exists());
        $hiddenProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $hiddenWarehouse = OrganizationWarehouse::withoutEvents(fn () => OrganizationWarehouse::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $hiddenProject->id,
            'name' => 'Закрытый склад', 'code' => (string) Str::uuid(), 'warehouse_type' => 'project', 'is_active' => true]));
        DB::table('warehouse_item_galleries')->where('id', $gallery->id)->update(['warehouse_id' => $hiddenWarehouse->id]);
        $before = $this->reads;
        self::assertSame(0, $adapter->sourceQueryForActor($actor, $fixture->organization->id, 'warehouse_item_gallery')->limit(1)->count());
        $this->denied(fn () => $adapter->assertFileReadable($actor, $fixture->organization->id, $files[1]), 'ai_assistant_document_access_denied');
        self::assertSame($before, $this->reads);
        DB::table('warehouse_item_galleries')->where('id', $gallery->id)->update(['warehouse_id' => $gallery->warehouse_id]);
        self::assertSame(62, $adapter->sourceQueryForActor($actor, $fixture->organization->id, 'warehouse_item_gallery')->count());
        $this->role($actor)->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view']]]);
        $this->denied(fn () => $adapter->assertFileReadable($actor, $fixture->organization->id, $files[1]), 'ai_assistant_document_access_denied');
        self::assertSame($before, $this->reads);
    }

    public function test_initial_index_actor_is_fresh_uploader_or_explicit_approver_and_same_path_body_change_invalidates_previous_document(): void
    {
        [$fixture, $actor, $project] = $this->fixture('safety-management', 'safety-management.view');
        [$exam] = $this->medical($fixture, $actor, $project);
        $adapter = $this->adapter();
        $document = $adapter->mapForIndexing($fixture->organization->id, $exam->id, 'safety_medical_exam');
        self::assertNotNull($document);
        $this->body = str_replace('fit', 'bad', $this->body);
        $this->denied(fn () => $adapter->content($actor, $fixture->organization->id, $document), 'ai_assistant_document_checksum_changed');
        $next = $adapter->mapForIndexing($fixture->organization->id, $exam->id, 'safety_medical_exam');
        self::assertNotNull($next);
        self::assertNotSame($document->id, $next->id);
        self::assertSame('stale', $document->fresh()->coverage_status);
        self::assertSame(hash('sha256', $this->body), $next->checksum);
        UserRoleAssignment::query()->where('user_id', $actor->id)->update(['is_active' => false]);
        $before = $this->reads;
        self::assertNull($adapter->mapForIndexing($fixture->organization->id, $exam->id, 'safety_medical_exam'));
        (new AssistantOperationsNativeFileIndexer($adapter))->prepare($fixture->organization->id, null, 'safety_medical_exam', $exam->id);
        self::assertSame('needs_access_review', $next->fresh()->coverage_status);
        self::assertSame($before, $this->reads);
        AssistantDocumentSettings::query()->updateOrCreate(['organization_id' => $fixture->organization->id], ['approved_by' => $fixture->owner->id]);
        $approved = $adapter->mapForIndexing($fixture->organization->id, $exam->id, 'safety_medical_exam');
        self::assertNotNull($approved);
        self::assertSame($next->id, $approved->id);
        self::assertSame($fixture->owner->id, $approved->metadata['native_actor_user_id']);
        self::assertSame('pending', $approved->coverage_status);
    }

    private function fixture(string $module, string $permission): array
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        Cache::clearResolvedInstances();
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $actor = $fixture->addMember([$module => [$permission], 'ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view']]);
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $actor->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        return [$fixture, $actor, $project];
    }

    private function adapter(): AssistantOperationsNativeFileAdapter
    {
        $storage = $this->createMock(FileService::class);
        $storage->method('readCurrentBounded')->willReturnCallback(function () {
            $this->reads++;
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) { throw new RuntimeException('fixture_stream_unavailable'); }
            fwrite($stream, $this->body);
            rewind($stream);
            return $stream;
        });
        $this->app->instance(FileService::class, $storage);
        $adapter = new AssistantOperationsNativeFileAdapter(app(AssistantDataAccessPolicy::class), app(AuthorizationService::class), $storage);
        $this->app->instance(AssistantOperationsNativeFileAdapter::class, $adapter);
        return $adapter;
    }

    private function medical(AssistantRealAuthorizationFixture $fixture, User $actor, Project $project): array
    {
        return Model::withoutEvents(function () use ($fixture, $actor, $project): array {
            $code = (string) Str::uuid();
            $employee = WorkforceEmployee::query()->create(['organization_id' => $fixture->organization->id, 'personnel_number' => $code,
                'last_name' => 'Иванов', 'first_name' => 'Иван', 'hire_date' => now()->subYear()->toDateString(), 'employment_status' => 'active']);
            $department = DB::table('workforce_departments')->insertGetId(['organization_id' => $fixture->organization->id, 'code' => $code, 'name' => 'Участок', 'created_at' => now(), 'updated_at' => now()]);
            $position = DB::table('workforce_positions')->insertGetId(['organization_id' => $fixture->organization->id, 'code' => $code, 'name' => 'Монтажник', 'created_at' => now(), 'updated_at' => now()]);
            $unit = DB::table('workforce_staff_units')->insertGetId(['organization_id' => $fixture->organization->id, 'department_id' => $department,
                'position_id' => $position, 'code' => $code, 'valid_from' => now()->subYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('workforce_employee_assignments')->insert(['organization_id' => $fixture->organization->id, 'employee_id' => $employee->id,
                'staff_unit_id' => $unit, 'department_id' => $department, 'position_id' => $position, 'project_id' => $project->id,
                'status' => 'active', 'valid_from' => now()->subMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            $file = File::query()->create(['organization_id' => $fixture->organization->id, 'user_id' => $actor->id,
                'fileable_type' => $employee->getMorphClass(), 'fileable_id' => $employee->id, 'name' => 'exam.txt', 'original_name' => 'exam.txt',
                'path' => 'org-'.$fixture->organization->id.'/safety/medical/'.Str::uuid().'.txt', 'mime_type' => 'text/plain', 'size' => strlen($this->body), 'disk' => 'local', 'type' => 'document']);
            $exam = SafetyMedicalExam::query()->create(['organization_id' => $fixture->organization->id, 'employee_id' => $employee->id,
                'exam_type' => 'periodic', 'completed_at' => now()->subMonth()->toDateString(), 'valid_until' => now()->addMonths(11)->toDateString(),
                'result' => 'fit', 'restrictions' => 'Clinical personal notes', 'file_id' => $file->id]);
            return [$exam, $file];
        });
    }

    private function gallery(AssistantRealAuthorizationFixture $fixture, User $actor, Project $project, int $count): array
    {
        return Model::withoutEvents(function () use ($fixture, $actor, $project, $count): array {
            $warehouse = OrganizationWarehouse::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id,
                'name' => 'Склад', 'code' => (string) Str::uuid(), 'warehouse_type' => 'project', 'is_active' => true]);
            $material = Material::query()->create(['organization_id' => $fixture->organization->id, 'name' => 'Кабель', 'is_active' => true]);
            $gallery = WarehouseItemGallery::query()->create(['organization_id' => $fixture->organization->id, 'warehouse_id' => $warehouse->id, 'material_id' => $material->id]);
            $files = [];
            for ($index = 0; $index < $count; $index++) {
                $files[] = File::query()->create(['organization_id' => $fixture->organization->id, 'user_id' => $actor->id,
                    'fileable_type' => $gallery->getMorphClass(), 'fileable_id' => $gallery->id, 'name' => 'photo.png', 'original_name' => 'photo.png',
                    'path' => 'org-'.$fixture->organization->id.'/warehouse/balances/warehouse-'.$warehouse->id.'/material-'.$material->id.'/'.Str::uuid().'.png',
                    'mime_type' => 'image/png', 'size' => strlen($this->body), 'disk' => 'local', 'type' => 'photo']);
            }
            return [$gallery, $files];
        });
    }

    private function role(User $actor): OrganizationCustomRole
    {
        return OrganizationCustomRole::query()->where('slug', UserRoleAssignment::query()->where('user_id', $actor->id)->value('role_slug'))->firstOrFail();
    }

    private function denied(callable $operation, string $message): void
    {
        try { $operation(); self::fail('Current source access must be denied.'); }
        catch (RuntimeException $exception) { self::assertSame($message, $exception->getMessage()); }
    }
}
