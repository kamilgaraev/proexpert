<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRenderService;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_uses_frozen_project_and_profile_without_changing_registered_original(): void
    {
        [$context, $document] = $this->fixture();
        $version = app(ExecutiveDocumentationService::class)->addVersion($document, $context->user->id, [
            'version_number' => '1',
            'file' => UploadedFile::fake()->createWithContent('original.pdf', 'registered-original'),
        ]);
        $hash = $version->content_hash;
        $document->project->update(['name' => 'Изменённый объект']);
        $document->update(['profile_data' => ['presented_works' => 'Изменённые работы']]);
        $snapshot = app(ExecutiveDocumentRenderService::class)->snapshot($version->id, $context->user->id);
        self::assertSame('Первоначальный объект', $snapshot['project']['name']);
        self::assertSame('Армирование плиты', $snapshot['profile_data']['presented_works']);
        self::assertSame($version->id, $snapshot['source_version_id']);
        self::assertSame($hash, $version->fresh()->content_hash);
        self::assertSame('registered-original', Storage::disk('s3')->get($version->file_url));
    }

    public function test_foreign_actor_cannot_read_render_snapshot(): void
    {
        [$context, $document] = $this->fixture();
        $version = app(ExecutiveDocumentationService::class)->addVersion($document, $context->user->id, [
            'version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('a.pdf', 'original'),
        ]);
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        app(ExecutiveDocumentRenderService::class)->snapshot($version->id, $foreign->user->id);
    }

    public function test_old_version_without_project_snapshot_does_not_substitute_live_requisites(): void
    {
        [$context, $document] = $this->fixture();
        $version = $document->versions()->create([
            'organization_id' => $document->organization_id, 'uploaded_by' => $context->user->id,
            'version_number' => 'legacy', 'file_url' => 'legacy.pdf', 'status' => 'draft',
            'profile_snapshot' => [], 'basis_snapshot' => ['document' => ['document_type' => 'hidden_work_act']],
        ]);
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        app(ExecutiveDocumentRenderService::class)->snapshot($version->id, $context->user->id);
    }

    public function test_preparation_creates_unsigned_pdf_version_and_replay_keeps_original_bytes(): void
    {
        [$context, $document] = $this->fixture();
        $service = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentPreparationService::class);
        $payload = ['version_number' => '1', 'expected_version_id' => 0, 'expected_revision' => 0, 'operation_key' => 'prepare-1', 'template_version' => '344-369-v1'];
        $version = $service->prepare($document->id, $context->user->id, $payload);
        $bytes = Storage::disk('s3')->get($version->file_url);
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertSame(hash('sha256', $bytes), $version->content_hash);
        self::assertSame('draft', $version->status);
        self::assertSame('generated_preparation', $version->metadata['origin']);
        self::assertSame('344-369-v1', $version->metadata['template_version']);
        self::assertIsString($version->metadata['print_snapshot']['document']['document_date']);
        self::assertSame($version->id, $service->prepare($document->id, $context->user->id, $payload)->id);
        self::assertSame(1, $document->versions()->count());
        self::assertSame($bytes, Storage::disk('s3')->get($version->file_url));
    }

    private function fixture(): array
    {
        Storage::fake('s3');
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->mock(\App\Domain\Authorization\Services\AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $project = Project::factory()->create(['organization_id' => $context->organization->id, 'name' => 'Первоначальный объект']);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'set_number' => 'PRINT-'.uniqid(), 'title' => 'Комплект', 'status' => 'draft',
        ]);
        $document = ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'document_set_id' => $set->id,
            'created_by' => $context->user->id, 'document_type' => 'hidden_work_act', 'title' => 'АОСР', 'status' => 'draft', 'document_date' => '2026-09-03',
            'profile_data' => ['act_number' => '1', 'presented_works' => 'Армирование плиты', 'started_at' => '2026-09-01', 'finished_at' => '2026-09-02', 'next_works_permission' => 'Бетонирование'],
        ]);
        return [$context, $document];
    }
}
