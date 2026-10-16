<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRetentionService;
use App\Models\PersonalFile;
use App\Models\ReportFile;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Storage\FileService;
use App\Services\Storage\PersonalFileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class AssistantReportLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('s3');
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([new \App\Models\Module(['slug' => 'file-management'])]));
        app()->instance(OrganizationEntitlementService::class, $entitlements);
    }

    #[DataProvider('deletionPaths')]
    public function test_deleting_assistant_artifact_removes_both_registries_and_grants_once(string $entry): void
    {
        [$context, $personal, $report, $grant] = $this->artifact();
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('disk')->andReturn(Storage::disk('s3'));
        $files->shouldReceive('deleteCurrent')->once()->with($personal->storage_key)
            ->andReturnUsing(fn () => Storage::disk('s3')->delete($personal->storage_key));
        app()->instance(FileService::class, $files);
        if ($entry === 'report-api') {
            $this->withHeaders($context->authHeaders())->deleteJson('/api/v1/admin/report-files/'.$report->id)->assertOk();
        } elseif ($entry === 'personal-api') {
            $this->withHeaders($context->authHeaders())->deleteJson('/api/v1/admin/personal-files/'.$personal->id)->assertOk();
        } elseif ($entry === 'folder-api') {
            $folder = PersonalFile::query()->create(['organization_id' => $context->organization->id, 'user_id' => $context->user->id,
                'directory' => 'reports', 'original_name' => 'assistant', 'size' => 0, 'is_folder' => true]);
            $this->withHeaders($context->authHeaders())->deleteJson('/api/v1/admin/personal-files/'.$folder->id)->assertOk();
            $this->assertDatabaseMissing('personal_files', ['id' => $folder->id]);
        } else {
            $conversation = $this->conversation($context, $report);
            self::assertTrue((new AssistantRetentionService($files))->deleteConversation($conversation));
        }
        Storage::disk('s3')->assertMissing($personal->storage_key);
        $this->assertDatabaseMissing('personal_files', ['id' => $personal->id]);
        $this->assertDatabaseMissing('report_files', ['id' => $report->id]);
        $this->assertDatabaseMissing('ai_assistant_report_access', ['id' => $grant]);
    }

    public static function deletionPaths(): array
    {
        return array_map(static fn (string $entry): array => [$entry], ['report-api', 'personal-api', 'folder-api', 'retention']);
    }

    public function test_retention_preserves_artifact_until_last_referencing_conversation_is_deleted(): void
    {
        [$context, $personal, $report, $grant] = $this->artifact();
        $old = $this->conversation($context, $report);
        $active = $this->conversation($context, $report);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('deleteCurrent')->once()->with($personal->storage_key)
            ->andReturnUsing(fn () => Storage::disk('s3')->delete($personal->storage_key));
        $retention = new AssistantRetentionService($files);
        self::assertTrue($retention->deleteConversation($old));
        Storage::disk('s3')->assertExists($personal->storage_key);
        $this->assertDatabaseHas('personal_files', ['id' => $personal->id]);
        $this->assertDatabaseHas('report_files', ['id' => $report->id]);
        $this->assertDatabaseHas('ai_assistant_report_access', ['id' => $grant]);
        self::assertTrue($retention->deleteConversation($active));
        Storage::disk('s3')->assertMissing($personal->storage_key);
        $this->assertDatabaseMissing('personal_files', ['id' => $personal->id]);
        $this->assertDatabaseMissing('report_files', ['id' => $report->id]);
        $this->assertDatabaseMissing('ai_assistant_report_access', ['id' => $grant]);
    }

    public function test_storage_failure_preserves_both_registries_and_grants(): void
    {
        [$context, $personal, $report, $grant] = $this->artifact();
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('deleteCurrent')->once()->with($personal->storage_key)->andThrow(new RuntimeException('storage unavailable'));
        try {
            (new PersonalFileService($files))->delete($personal->id, $context->organization->id, $context->user->id);
            self::fail('Storage failure must propagate');
        } catch (RuntimeException $error) {
            self::assertSame('storage unavailable', $error->getMessage());
        }
        $this->assertDatabaseHas('personal_files', ['id' => $personal->id]);
        $this->assertDatabaseHas('report_files', ['id' => $report->id]);
        $this->assertDatabaseHas('ai_assistant_report_access', ['id' => $grant]);
    }

    public function test_other_owner_cannot_delete_either_registry_entry(): void
    {
        [$context, $personal, $report, $grant] = $this->artifact();
        $other = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($other->id, ['is_active' => true]);
        $personal->update(['user_id' => $other->id]);
        $report->update(['user_id' => $other->id]);
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('deleteCurrent');
        app()->instance(FileService::class, $files);
        $this->withHeaders($context->authHeaders())->deleteJson('/api/v1/admin/report-files/'.$report->id)->assertNotFound();
        $this->withHeaders($context->authHeaders())->deleteJson('/api/v1/admin/personal-files/'.$personal->id)->assertNotFound();
        $this->assertDatabaseHas('personal_files', ['id' => $personal->id]);
        $this->assertDatabaseHas('report_files', ['id' => $report->id]);
        $this->assertDatabaseHas('ai_assistant_report_access', ['id' => $grant]);
    }

    private function artifact(): array
    {
        $context = AdminApiTestContext::create();
        $path = 'org-'.$context->organization->id.'/personal-files/user-'.$context->user->id.'/'.Str::uuid().'.pdf';
        Storage::disk('s3')->put($path, '%PDF-fixture');
        $personal = PersonalFile::query()->create(['organization_id' => $context->organization->id, 'user_id' => $context->user->id,
            'storage_key' => $path, 'directory' => 'reports/assistant', 'original_name' => 'Отчёт.pdf', 'mime_type' => 'application/pdf',
            'sha256' => hash('sha256', '%PDF-fixture'), 'size' => 12, 'is_folder' => false]);
        $report = ReportFile::query()->create(['organization_id' => $context->organization->id, 'user_id' => $context->user->id,
            'path' => $path, 'filename' => 'Отчёт.pdf', 'name' => 'Отчёт.pdf', 'type' => 'reports', 'size' => 12]);
        $grant = (string) Str::uuid();
        DB::table('ai_assistant_report_access')->insert(['id' => $grant, 'organization_id' => $context->organization->id, 'user_id' => $context->user->id,
            'storage_path' => $path, 'source_refs' => '[]', 'required_domains' => '["reports"]', 'expires_at' => now()->addDays(90), 'created_at' => now(), 'updated_at' => now()]);

        return [$context, $personal, $report, $grant];
    }

    private function conversation(AdminApiTestContext $context, ReportFile $report): Conversation
    {
        $conversation = Conversation::query()->create(['organization_id' => $context->organization->id, 'user_id' => $context->user->id]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Файл',
            'metadata' => ['artifacts' => [['storage_path' => $report->path, 'report_file_id' => (string) $report->id]]]]);

        return $conversation;
    }
}
