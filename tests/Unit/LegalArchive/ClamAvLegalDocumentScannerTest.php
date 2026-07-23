<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Files\ClamAvLegalDocumentScanner;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClamAvLegalDocumentScannerTest extends TestCase
{
    public function test_it_accepts_a_nul_terminated_clean_response_without_waiting_for_socket_close(): void
    {
        $scanner = new ClamAvLegalDocumentScanner('127.0.0.1', 1, 0.1);
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "stream: OK\0ignored");
        rewind($stream);

        $method = new \ReflectionMethod($scanner, 'readResponse');
        $method->setAccessible(true);

        self::assertSame('stream: OK', $method->invoke($scanner, $stream));
        fclose($stream);
    }

    public function test_it_rejects_an_infected_clamav_response(): void
    {
        $scanner = new ClamAvLegalDocumentScanner('127.0.0.1', 1, 0.1);
        $method = new \ReflectionMethod($scanner, 'assertSuccessfulResponse');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('legal_document_malware_detected');

        $method->invoke($scanner, 'stream: Eicar-Signature FOUND');
    }

    public function test_it_fails_closed_when_the_scanner_is_unavailable(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'legal-document-scan-');
        self::assertIsString($path);
        file_put_contents($path, 'safe fixture');

        try {
            $upload = new UploadedFile($path, 'fixture.pdf', 'application/pdf', null, true);
            $scanner = new ClamAvLegalDocumentScanner('127.0.0.1', 1, 0.1);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('legal_document_scanner_unavailable');

            $scanner->assertClean($upload);
        } finally {
            @unlink($path);
        }
    }
}
