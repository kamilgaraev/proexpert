<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Services\DesignDocumentMetadataExtractor;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class DesignDocumentMetadataExtractorTest extends TestCase
{
    public function test_inspect_returns_stable_file_metadata_and_sha256(): void
    {
        $file = UploadedFile::fake()->createWithContent('drawing.dwg', 'DWG binary');
        $metadata = (new DesignDocumentMetadataExtractor())->inspect($file);

        self::assertSame('dwg', $metadata['file_format']);
        self::assertSame('drawing.dwg', $metadata['original_name']);
        self::assertSame(hash('sha256', 'DWG binary'), $metadata['sha256']);
    }

    public function test_format_from_name_accepts_rf_document_package_formats(): void
    {
        $extractor = new DesignDocumentMetadataExtractor();

        self::assertSame('pdf', $extractor->formatFromName('volume.pdf'));
        self::assertSame('xlsx', $extractor->formatFromName('spec.xlsx'));
        self::assertSame('ifc', $extractor->formatFromName('model.ifc'));
        self::assertSame('zip', $extractor->formatFromName('archive.unknown'));
    }
}
