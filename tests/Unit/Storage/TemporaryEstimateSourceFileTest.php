<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Storage\TemporaryEstimateSourceFile;
use PHPUnit\Framework\TestCase;

final class TemporaryEstimateSourceFileTest extends TestCase
{
    public function test_creates_extension_aware_file_without_leaking_tempnam_base_file(): void
    {
        $path = TemporaryEstimateSourceFile::fromContents('spreadsheet-bytes', 'xlsx', 'most-test-source-');

        try {
            self::assertStringEndsWith('.xlsx', $path);
            self::assertSame('spreadsheet-bytes', file_get_contents($path));
            self::assertFileDoesNotExist(substr($path, 0, -5));
        } finally {
            @unlink($path);
        }
    }

    public function test_copies_complete_stream_and_closes_source(): void
    {
        $source = fopen('php://temp', 'w+b');
        self::assertIsResource($source);
        fwrite($source, 'streamed-spreadsheet');
        rewind($source);

        $path = TemporaryEstimateSourceFile::fromStream($source, 'xlsx', 'most-test-stream-');

        try {
            self::assertSame('streamed-spreadsheet', file_get_contents($path));
            self::assertFalse(is_resource($source));
        } finally {
            @unlink($path);
        }
    }

    public function test_normalizes_supported_uppercase_extension(): void
    {
        $path = TemporaryEstimateSourceFile::fromContents('spreadsheet-bytes', 'XLSX', 'most-test-uppercase-');

        try {
            self::assertStringEndsWith('.xlsx', $path);
            self::assertSame('spreadsheet-bytes', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}
