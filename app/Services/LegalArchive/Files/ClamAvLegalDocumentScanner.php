<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use Illuminate\Http\UploadedFile;
use RuntimeException;

final class ClamAvLegalDocumentScanner implements LegalDocumentScanner
{
    private const CHUNK_BYTES = 1048576;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeoutSeconds,
    ) {}

    public function assertClean(UploadedFile $upload): void
    {
        $path = $upload->getRealPath();
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            throw new RuntimeException('legal_document_scan_source_unavailable');
        }

        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client(
                sprintf('tcp://%s:%d', $this->host, $this->port),
                $errorCode,
                $errorMessage,
                $this->timeoutSeconds,
                STREAM_CLIENT_CONNECT,
            );
        } finally {
            restore_error_handler();
        }
        if (! is_resource($socket)) {
            throw new RuntimeException('legal_document_scanner_unavailable');
        }

        $file = @fopen($path, 'rb');
        if (! is_resource($file)) {
            fclose($socket);

            throw new RuntimeException('legal_document_scan_source_unavailable');
        }

        try {
            stream_set_timeout($socket, max(1, (int) ceil($this->timeoutSeconds)));
            $this->writeAll($socket, "zINSTREAM\0");

            while (! feof($file)) {
                $chunk = fread($file, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('legal_document_scan_source_unreadable');
                }
                if ($chunk === '') {
                    continue;
                }

                $this->writeAll($socket, pack('N', strlen($chunk)));
                $this->writeAll($socket, $chunk);
            }

            $this->writeAll($socket, pack('N', 0));
            $response = $this->readResponse($socket);
            $this->assertSuccessfulResponse($response);
        } finally {
            fclose($file);
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function writeAll($socket, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = fwrite($socket, substr($payload, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('legal_document_scanner_unavailable');
            }

            $offset += $written;
        }
    }

    /** @param resource $socket */
    private function readResponse($socket): string
    {
        $response = '';
        while (! str_contains($response, "\0")) {
            $chunk = fread($socket, 1024);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('legal_document_scanner_invalid_response');
            }
            $response .= $chunk;
            if (strlen($response) > 4096) {
                throw new RuntimeException('legal_document_scanner_invalid_response');
            }
        }

        return substr($response, 0, (int) strpos($response, "\0"));
    }

    private function assertSuccessfulResponse(string $response): void
    {
        if (str_contains($response, ' FOUND')) {
            throw new RuntimeException('legal_document_malware_detected');
        }
        if (! str_contains($response, ': OK')) {
            throw new RuntimeException('legal_document_scanner_invalid_response');
        }
    }
}
