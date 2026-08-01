<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\S3DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

final class S3DocumentSourceManifestStorageTest extends LaravelTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function reads_the_pinned_version_and_verified_hash(): void
    {
        config()->set('estimate-generation.ocr.max_sync_file_bytes', 4096);
        $content = str_repeat('a', 2048);
        $document = $this->document(strlen($content));
        $files = $this->filesFor($document, $content);

        $source = $this->storage($files)->open($document, $this->sourceVersion($content));

        try {
            self::assertSame(strlen($content), $source->bytes());
            self::assertSame($content, file_get_contents($source->path()));
        } finally {
            $source->close();
        }
    }

    #[Test]
    public function rejects_a_declared_source_larger_than_the_bound_before_reading_it(): void
    {
        config()->set('estimate-generation.ocr.max_sync_file_bytes', 1024);
        $document = $this->document(1025);
        $files = Mockery::mock(FileService::class);

        try {
            $this->storage($files)->open($document, $this->sourceVersion('unused'));
            self::fail('Expected bounded source rejection.');
        } catch (TypedFailureException $exception) {
            self::assertSame('document_source_too_large', $exception->safeCode);
        }
    }

    #[Test]
    public function rejects_a_pinned_source_when_its_hash_does_not_match(): void
    {
        config()->set('estimate-generation.ocr.max_sync_file_bytes', 4096);
        $document = $this->document(5);
        $files = $this->filesFor($document, 'other');

        try {
            $this->storage($files)->open($document, $this->sourceVersion('right'));
            self::fail('Expected pinned source integrity rejection.');
        } catch (TypedFailureException $exception) {
            self::assertSame('document_source_integrity_failed', $exception->safeCode);
        }
    }

    #[Test]
    public function stores_spreadsheet_manifests_as_supported_json_artifacts(): void
    {
        $document = $this->document(1);
        $content = json_encode([
            'schema_version' => 1,
            'source_kind' => 'spreadsheet',
            'text' => 'Смета',
            'native_structure' => ['status' => 'available'],
        ], JSON_THROW_ON_ERROR);
        $path = 'org-71/estimate-generation/sessions/17/documents/23/manifests/sha256-'.str_repeat('a', 64).'/spreadsheet_sheet-00001.json';
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putImmutable')->once()->with($path, $content, 'application/json')->andReturn([
            'path' => $path,
            'body' => $content,
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'etag' => null,
            'version_id' => 'artifact-v1',
            'content_type' => 'application/json',
            'created' => true,
        ]);

        $artifact = $this->storage($files)->put(
            $document,
            'sha256:'.str_repeat('a', 64),
            DocumentUnitType::SpreadsheetSheet,
            1,
            $content,
            'application/json',
        );

        self::assertSame($path, $artifact->path);
        self::assertSame('application/json', $artifact->contentType);
    }

    private function storage(FileService $files): S3DocumentSourceManifestStorage
    {
        return new S3DocumentSourceManifestStorage($files, new BoundedVersionedS3ObjectReader($files));
    }

    private function filesFor(EstimateGenerationDocument $document, string $content): FileService
    {
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('describeVersion')->once()->with(
            $document->storage_path,
            'source-v1',
            Mockery::type('int'),
        )->andReturn([
            'path' => $document->storage_path,
            'body' => $content,
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'etag' => null,
            'version_id' => 'source-v1',
            'content_type' => 'application/octet-stream',
        ]);

        return $files;
    }

    private function sourceVersion(string $content): string
    {
        return 'sha256:'.hash('sha256', $content);
    }

    private function document(int $declaredBytes): EstimateGenerationDocument
    {
        $organization = new Organization;
        $organization->forceFill(['id' => 71]);
        $session = new EstimateGenerationSession;
        $session->forceFill(['id' => 17, 'organization_id' => 71, 'project_id' => 9]);
        $session->setRelation('organization', $organization);
        $document = new EstimateGenerationDocument;
        $document->forceFill([
            'id' => 23,
            'session_id' => 17,
            'organization_id' => 71,
            'project_id' => 9,
            'storage_path' => 'org-71/estimate-generation/source.bin',
            'mime_type' => 'application/octet-stream',
            'file_size_bytes' => $declaredBytes,
            'meta' => ['storage_version_id' => 'source-v1'],
        ]);
        $document->setRelation('session', $session);

        return $document;
    }
}
