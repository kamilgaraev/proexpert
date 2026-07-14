<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\S3DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

final class S3DocumentSourceManifestStorageTest extends LaravelTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function reads_exact_declared_content_through_the_s3_stream(): void
    {
        config()->set('estimate-generation.ocr.max_sync_file_bytes', 4096);
        $content = str_repeat('a', 2048);
        $document = $this->document(strlen($content));
        Storage::fake('s3')->put($document->storage_path, $content);

        $result = (new S3DocumentSourceManifestStorage($this->app->make(FileService::class)))->read($document);

        self::assertSame($content, $result);
    }

    #[Test]
    public function rejects_an_object_larger_than_the_bound_even_when_metadata_understates_it(): void
    {
        config()->set('estimate-generation.ocr.max_sync_file_bytes', 1024);
        $document = $this->document(1024);
        Storage::fake('s3')->put($document->storage_path, str_repeat('b', 1025));

        try {
            (new S3DocumentSourceManifestStorage($this->app->make(FileService::class)))->read($document);
            self::fail('Expected bounded stream rejection.');
        } catch (TypedFailureException $exception) {
            self::assertSame('document_source_too_large', $exception->safeCode);
        }
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
        ]);
        $document->setRelation('session', $session);

        return $document;
    }
}
