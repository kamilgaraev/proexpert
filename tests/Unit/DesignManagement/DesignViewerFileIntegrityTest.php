<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Support\DesignViewerFileIntegrity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DesignViewerFileIntegrityTest extends TestCase
{
    public function test_integrity_streams_multiple_chunks_and_hashes_exact_bytes(): void
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        $chunk = str_repeat('properties ', 200000);
        fwrite($stream, $chunk);
        fwrite($stream, "\n");
        rewind($stream);

        try {
            $this->assertSame([
                'sha256' => hash('sha256', $chunk."\n"),
                'size' => strlen($chunk) + 1,
            ], DesignViewerFileIntegrity::forStream($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_empty_sidecar_cannot_be_published(): void
    {
        $stream = fopen('php://temp', 'w+b');
        try {
            $this->expectException(RuntimeException::class);
            DesignViewerFileIntegrity::forStream($stream);
        } finally {
            fclose($stream);
        }
    }
}
