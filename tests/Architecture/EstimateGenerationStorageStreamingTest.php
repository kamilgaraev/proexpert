<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationStorageStreamingTest extends TestCase
{
    #[Test]
    public function uploaded_document_checksum_is_streamed_without_loading_the_file_into_memory(): void
    {
        $source = $this->source('Services/Ocr/OcrDocumentStorageService.php');

        self::assertStringNotContainsString('file_get_contents(', $source);
        self::assertStringContainsString("hash_file('sha256'", $source);
        self::assertStringContainsString("'document_read_failed'", $source);
    }

    #[Test]
    public function s3_document_reads_are_pinned_and_verified_by_the_document_contract(): void
    {
        $source = $this->source('Application/Documents/S3DocumentSourceManifestStorage.php');
        $contract = $this->source('Application/Documents/DocumentSourceManifestStorage.php');
        $pdfAdapter = $this->source('Application/Documents/PdfDocumentAdapter.php');
        $spreadsheetAdapter = $this->source('Application/Documents/SpreadsheetDocumentAdapter.php');

        self::assertStringContainsString('BoundedVersionedS3ObjectReader', $source);
        self::assertStringContainsString('$this->reader->read(', $source);
        self::assertStringNotContainsString('$this->files->disk(', $source);
        self::assertStringContainsString('maxReadableBytes(', $source);
        self::assertStringContainsString('string $sourceVersion', $contract);
        self::assertStringContainsString('): SeekableDocumentSource;', $contract);
        self::assertStringNotContainsString('read(EstimateGenerationDocument $document): string', $contract);
        self::assertStringContainsString('$this->storage->open($document, $sourceVersion)', $pdfAdapter);
        self::assertStringContainsString('$this->storage->open($document, $sourceVersion)', $spreadsheetAdapter);
    }

    private function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration/'.$relative);
        self::assertIsString($source);

        return $source;
    }
}
