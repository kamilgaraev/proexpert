<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentImportService;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_manifest_replay_returns_the_same_hundred_items(): void
    {
        [$actor, $set] = $this->fixture();
        $service = app(ExecutiveDocumentImportService::class);
        $files = [];
        for ($i = 1; $i <= 100; $i++) {
            $files[] = ['key' => 'file-'.$i, 'name' => 'document-'.$i.'.pdf', 'size' => 100, 'sha256' => hash('sha256', (string) $i)];
        }
        $batch = $service->create($set, $actor->user->id, 'batch-100', $files);
        self::assertCount(100, $batch->items);
        self::assertSame($batch->id, $service->create($set, $actor->user->id, 'batch-100', $files)->id);
        self::assertSame(0, $set->documents()->count());
        Queue::assertNothingPushed();
    }

    public function test_manifest_rejects_oversize_and_archive_paths_before_storing_anything(): void
    {
        [$actor, $set] = $this->fixture();
        $service = app(ExecutiveDocumentImportService::class);
        foreach ([['name' => '../document.pdf', 'size' => 10], ['name' => 'document.pdf', 'size' => 26 * 1024 * 1024], ['name' => 'archive.zip', 'size' => 10]] as $file) {
            try {
                $service->create($set, $actor->user->id, 'unsafe', [['key' => 'file-1', 'sha256' => str_repeat('a', 64), ...$file]]);
                self::fail('Unsafe manifest accepted');
            } catch (\Illuminate\Validation\ValidationException) {}
        }
        self::assertSame(0, $set->documents()->count());
    }

    public function test_foreign_actor_cannot_create_a_batch(): void
    {
        [$actor, $set] = $this->fixture();
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        app(ExecutiveDocumentImportService::class)->create($set, $foreign->user->id, 'foreign', [['key' => 'file-1', 'name' => 'document.pdf', 'size' => 10, 'sha256' => str_repeat('a', 64)]]);
    }

    public function test_interrupted_upload_can_be_repeated_without_a_second_staged_file(): void
    {
        [$actor, $set] = $this->fixture();
        $content = "%PDF-1.4\nimport-test";
        $service = app(ExecutiveDocumentImportService::class);
        $batch = $service->create($set, $actor->user->id, 'upload', [['key' => 'file-1', 'name' => 'document.pdf', 'size' => strlen($content), 'sha256' => hash('sha256', $content)]]);
        $item = $batch->items->first();
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('document.pdf', $content);
        $first = $service->upload($batch, $actor->user->id, $item->id, $file);
        $again = $service->upload($batch, $actor->user->id, $item->id, $file);
        self::assertSame('uploaded', $again->status);
        self::assertSame($first->staged_path, $again->staged_path);
        self::assertCount(1, Storage::disk('s3')->allFiles());
        self::assertArrayNotHasKey('staged_path', $again->toArray());
    }

    public function test_registration_replays_only_failed_items_and_does_not_duplicate_completed_documents(): void
    {
        [$actor, $set] = $this->fixture();
        $service = app(ExecutiveDocumentImportService::class);
        $content = "%PDF-1.4\nregistration";
        $batch = $service->create($set, $actor->user->id, 'register', [['key' => 'one', 'name' => 'passport.pdf', 'size' => strlen($content), 'sha256' => hash('sha256', $content)]]);
        $item = $batch->items->first();
        $service->upload($batch, $actor->user->id, $item->id, \Illuminate\Http\UploadedFile::fake()->createWithContent('passport.pdf', $content));
        $mapping = ['document_type' => 'quality_passport', 'title' => 'Паспорт бетона', 'document_date' => '2026-09-20', 'profile_data' => [
            'document_number' => 'П-1', 'quality_document_kind' => 'passport', 'material_name' => 'Бетон', 'manufacturer' => 'Завод',
            'quality_document_date' => '2026-09-20', 'quality_document_details' => 'Бетон В25',
        ]];
        $service->map($batch, $actor->user->id, [['id' => $item->id, 'mapping' => $mapping]]);
        $service->start($batch, $actor->user->id);
        $service->process($item->id, 1);
        $service->process($item->id, 1);
        self::assertSame(1, $set->documents()->count());
        self::assertSame('completed', $item->fresh()->status);
        $service->start($batch, $actor->user->id, true);
        Queue::assertPushed(\App\BusinessModules\Features\ExecutiveDocumentation\Jobs\RegisterExecutiveDocumentImportItem::class, 1);
        self::assertSame(1, $set->documents()->count());
    }

    public function test_hundred_file_batch_registers_once_and_retries_only_the_failed_position(): void
    {
        [$actor, $set] = $this->fixture();
        $service = app(ExecutiveDocumentImportService::class);
        $manifest = [];
        for ($i = 1; $i <= 100; $i++) {
            $content = "%PDF-1.4\nfile-".$i;
            $manifest[] = ['key' => (string) $i, 'name' => 'file-'.$i.'.pdf', 'size' => strlen($content), 'sha256' => hash('sha256', $content)];
        }
        $batch = $service->create($set, $actor->user->id, 'hundred-full', $manifest);
        $rows = [];
        foreach ($batch->items as $item) {
            $content = "%PDF-1.4\nfile-".$item->client_key;
            $service->upload($batch, $actor->user->id, $item->id, \Illuminate\Http\UploadedFile::fake()->createWithContent($item->original_name, $content));
            $rows[] = ['id' => $item->id, 'mapping' => ['document_type' => 'quality_passport', 'title' => 'Паспорт '.$item->client_key, 'profile_data' => [
                'document_number' => 'П-'.$item->client_key, 'quality_document_kind' => 'passport', 'material_name' => 'Бетон', 'manufacturer' => 'Завод',
                'quality_document_date' => '2026-09-20', 'quality_document_details' => 'Бетон В25',
            ]]];
        }
        $service->map($batch, $actor->user->id, $rows);
        $service->start($batch, $actor->user->id);
        Queue::assertPushed(\App\BusinessModules\Features\ExecutiveDocumentation\Jobs\RegisterExecutiveDocumentImportItem::class, 100);
        $failedId = $batch->items->first()->id;
        $service->fail($failedId, 1, new \RuntimeException('private-storage-debug-details'));
        self::assertStringNotContainsString('private-storage-debug-details', json_encode($batch->items->first()->fresh()->errors));
        foreach ($batch->items as $item) {
            if ($item->id !== $failedId) $service->process($item->id, 1);
        }
        self::assertSame(99, $set->documents()->count());
        $service->start($batch, $actor->user->id, true);
        Queue::assertPushed(\App\BusinessModules\Features\ExecutiveDocumentation\Jobs\RegisterExecutiveDocumentImportItem::class, 101);
        $service->process($failedId, 1);
        self::assertSame(99, $set->documents()->count());
        $service->process($failedId, 2);
        self::assertSame(100, $set->documents()->count());
        self::assertSame(100, $batch->items()->where('status', 'completed')->count());
    }

    public function test_import_http_manifest_and_readback_keep_actor_scope(): void
    {
        [$actor, $set] = $this->fixture();
        $url = '/api/v1/admin/executive-documentation/sets/'.$set->id.'/imports';
        $content = "%PDF-1.4\nhttp-import";
        $response = $this->withHeaders($actor->authHeaders())->postJson($url, ['operation_key' => 'http', 'files' => [['key' => 'one', 'name' => 'document.pdf', 'size' => strlen($content), 'sha256' => hash('sha256', $content)]]]);
        $response->assertOk()->assertJsonPath('data.items.0.status', 'awaiting_upload');
        $batchId = $response->json('data.id');
        $this->getJson('/api/v1/admin/executive-documentation/imports/'.$batchId)->assertOk()->assertJsonMissingPath('data.items.0.staged_path');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.items_count', 1);
        $itemId = $response->json('data.items.0.id');
        $base = '/api/v1/admin/executive-documentation/imports/'.$batchId;
        $this->post($base.'/items/'.$itemId.'/file', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('document.pdf', $content)])->assertOk()->assertJsonPath('data.status', 'uploaded');
        $mapping = ['document_type' => 'quality_passport', 'title' => 'Паспорт', 'profile_data' => ['document_number' => 'П-1', 'quality_document_kind' => 'passport', 'material_name' => 'Бетон', 'manufacturer' => 'Завод', 'quality_document_date' => '2026-09-20', 'quality_document_details' => 'Бетон В25']];
        $this->putJson($base.'/mapping', ['rows' => [['id' => $itemId, 'mapping' => $mapping]]])->assertOk()->assertJsonPath('data.items.0.status', 'ready');
        $this->postJson($base.'/start', ['retry_failed_only' => false])->assertOk()->assertJsonPath('data.items.0.status', 'queued');
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        auth()->forgetGuards();
        $this->withHeaders($foreign->authHeaders())->getJson('/api/v1/admin/executive-documentation/imports/'.$batchId)->assertNotFound();
    }

    public function test_single_document_route_accepts_ready_file_without_retyping_profile_fields(): void
    {
        [$actor, $set] = $this->fixture();
        $url = '/api/v1/admin/executive-documentation/sets/'.$set->id.'/documents';
        $payload = ['document_type' => 'quality_passport', 'title' => 'Паспорт', 'profile_data' => [], 'initial_version' => ['version_number' => '1', 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('passport.pdf', "%PDF-1.4\nsingle")]];
        $this->withHeaders($actor->authHeaders())->post($url, $payload)->assertCreated()->assertJsonPath('data.document_type', 'quality_passport');
        self::assertSame(1, $set->documents()->count());
        unset($payload['initial_version']);
        $this->post($url, $payload)->assertUnprocessable();
    }

    public function test_duplicate_content_and_changed_operation_payload_are_not_registered_twice(): void
    {
        [$actor, $set] = $this->fixture();
        $service = app(ExecutiveDocumentImportService::class);
        $file = ['key' => 'first', 'name' => 'document.pdf', 'size' => 10, 'sha256' => str_repeat('a', 64)];
        $batch = $service->create($set, $actor->user->id, 'duplicates', [$file, [...$file, 'key' => 'second', 'name' => 'copy.pdf']]);
        self::assertSame(['awaiting_upload', 'duplicate'], $batch->items->pluck('status')->all());
        try {
            $service->create($set, $actor->user->id, 'duplicates', [$file]);
            self::fail('Changed manifest accepted for the same operation');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        self::assertSame(0, $set->documents()->count());
    }

    public function test_preview_waits_for_the_file_without_requiring_retyped_delivery_details(): void
    {
        [$actor, $set] = $this->fixture();
        $service = app(ExecutiveDocumentImportService::class);
        $batch = $service->create($set, $actor->user->id, 'missing-delivery', [['key' => 'one', 'name' => 'control.pdf', 'size' => 10, 'sha256' => str_repeat('a', 64)]]);
        $mapped = $service->map($batch, $actor->user->id, [['id' => $batch->items->first()->id, 'mapping' => ['document_type' => 'incoming_batch_control', 'title' => 'Входной контроль', 'profile_data' => ['control_number' => 'ВК-1', 'received_at' => '2026-09-20', 'checked_at' => '2026-09-20', 'material_name' => 'Бетон', 'supplier' => 'Завод', 'batch_details' => 'Партия 1', 'quantity' => '10', 'control_result' => 'accepted']]]]);
        self::assertSame('awaiting_upload', $mapped->items->first()->status);
        self::assertNull($mapped->items->first()->errors);
        $service->start($batch, $actor->user->id);
        Queue::assertNothingPushed();
    }

    public function test_abandoned_staging_expires_without_deleting_documents_and_can_be_uploaded_again(): void
    {
        [$actor, $set] = $this->fixture();
        $service = app(ExecutiveDocumentImportService::class);
        $content = "%PDF-1.4\nexpiry";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('document.pdf', $content);
        $batch = $service->create($set, $actor->user->id, 'expiry', [['key' => 'one', 'name' => 'document.pdf', 'size' => strlen($content), 'sha256' => hash('sha256', $content)]]);
        $item = $service->upload($batch, $actor->user->id, $batch->items->first()->id, $file);
        $oldPath = $item->staged_path;
        $this->travel(8)->days();
        self::assertSame(1, $service->expireStagedFiles());
        self::assertSame(0, $service->expireStagedFiles());
        Storage::disk('s3')->assertMissing($oldPath);
        self::assertSame('expired', $item->fresh()->status);
        $resumed = $service->upload($batch, $actor->user->id, $item->id, $file);
        self::assertSame('uploaded', $resumed->status);
        Storage::disk('s3')->assertExists($resumed->staged_path);
        self::assertSame(0, $set->documents()->count());
        $retainedPath = $resumed->staged_path;
        $resumed->update(['status' => 'completed']);
        $outsidePath = 'org-'.$actor->organization->id.'/executive-documentation/retained.pdf';
        Storage::disk('s3')->put($outsidePath, 'retained');
        $batch->items()->create(['client_key' => 'unsafe', 'original_name' => 'unsafe.pdf', 'size' => 10, 'content_hash' => str_repeat('a', 64), 'status' => 'uploaded', 'staged_path' => 'org-'.$actor->organization->id.'/executive-documentation/import-'.$batch->id.'/../retained.pdf']);
        $this->travel(8)->days();
        self::assertSame(0, $service->expireStagedFiles());
        Storage::disk('s3')->assertExists($retainedPath);
        Storage::disk('s3')->assertExists($outsidePath);
        $this->travelBack();
    }

    private function fixture(): array
    {
        Storage::fake('s3');
        Queue::fake();
        foreach ([\App\Domain\Authorization\Services\ModulePermissionChecker::class, \App\Domain\Authorization\Services\PermissionResolver::class, \App\Domain\Authorization\Services\AuthorizationService::class] as $service) $this->app->forgetInstance($service);
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $actor = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $set = ExecutiveDocumentSet::query()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id, 'created_by' => $actor->user->id, 'set_number' => 'IMPORT-1', 'title' => 'Пакет документов', 'status' => 'draft']);
        return [$actor, $set];
    }
}
