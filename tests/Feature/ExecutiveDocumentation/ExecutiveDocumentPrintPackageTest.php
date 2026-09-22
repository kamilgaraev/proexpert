<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentTransmittal;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentPrintPackageService;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentPrintPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_keeps_exact_manifest_version_even_when_new_version_exists(): void
    {
        [$context, $transmittal, $document, $version] = $this->fixture();
        $document->versions()->create([
            'organization_id' => $context->organization->id, 'uploaded_by' => $context->user->id,
            'version_number' => '2', 'file_url' => 'new-file.pdf', 'status' => 'draft',
        ]);
        $bytes = app(ExecutiveDocumentPrintPackageService::class)->build($transmittal->id, $context->user->id);
        $path = tempnam(sys_get_temp_dir(), 'itd-test-');
        try {
            file_put_contents($path, $bytes);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            self::assertSame('original-file', $zip->getFromName('documents/'.$version->id.'.pdf'));
            $manifest = json_decode($zip->getFromName('registry.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($version->id, $manifest['documents'][0]['version_id']);
            self::assertSame('1', $manifest['documents'][0]['version_number']);
            self::assertStringNotContainsString('org-', $zip->getFromName('registry.json'));
            self::assertStringContainsString('АОСР', $zip->getFromName('registry.html'));
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_tampered_original_is_not_included_in_package(): void
    {
        [$context, $transmittal, , $version] = $this->fixture();
        Storage::disk('s3')->put($version->file_url, 'changed');
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        app(ExecutiveDocumentPrintPackageService::class)->build($transmittal->id, $context->user->id);
    }

    public function test_foreign_actor_cannot_export_package(): void
    {
        [, $transmittal] = $this->fixture();
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        app(ExecutiveDocumentPrintPackageService::class)->build($transmittal->id, $foreign->user->id);
    }

    private function fixture(): array
    {
        Storage::fake('s3');
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->mock(\App\Domain\Authorization\Services\AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'set_number' => 'PKG', 'title' => 'Комплект', 'status' => 'draft',
        ]);
        $document = ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'document_set_id' => $set->id, 'created_by' => $context->user->id,
            'document_type' => 'hidden_work_act', 'title' => 'АОСР', 'status' => 'draft',
        ]);
        $path = 'org-'.$context->organization->id.'/executive-documentation/one.pdf';
        Storage::disk('s3')->put($path, 'original-file');
        $version = $document->versions()->create([
            'organization_id' => $context->organization->id, 'uploaded_by' => $context->user->id,
            'version_number' => '1', 'file_url' => $path, 'status' => 'draft', 'content_hash' => hash('sha256', 'original-file'),
        ]);
        $manifest = ['set' => ['title' => 'Комплект', 'set_number' => 'PKG'], 'documents' => [[
            'document_id' => $document->id, 'version_id' => $version->id, 'version_number' => '1',
            'file_url' => $path, 'content_hash' => $version->content_hash, 'title' => 'АОСР',
        ]]];
        $transmittal = ExecutiveDocumentTransmittal::query()->create([
            'organization_id' => $context->organization->id, 'document_set_id' => $set->id,
            'transmitted_by' => $context->user->id, 'transmittal_number' => 'PKG-1', 'status' => 'sent', 'transmitted_at' => now(),
            'manifest' => $manifest, 'manifest_hash' => hash('sha256', json_encode($manifest)),
        ]);
        return [$context, $transmittal, $document, $version];
    }
}
