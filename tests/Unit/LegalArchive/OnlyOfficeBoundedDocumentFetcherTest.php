<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Editor\OnlyOfficeBoundedDocumentFetcher;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OnlyOfficeBoundedDocumentFetcherTest extends TestCase
{
    public function test_default_transport_is_explicit_curl_and_performs_a_real_non_lazy_request(): void
    {
        $fetcher = new OnlyOfficeBoundedDocumentFetcher;
        $clientProperty = new ReflectionProperty($fetcher, 'client');
        $client = $clientProperty->getValue($fetcher);
        self::assertInstanceOf(CurlHttpClient::class, $client);

        $server = OnlyOfficeControlledHttpServer::start();
        try {
            $response = $client->request('GET', $server->url(), [
                'timeout' => 2.0,
                'max_duration' => 3.0,
            ]);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('most-curl-runtime', $response->getContent());
        } finally {
            $server->stop();
        }
    }

    public function test_transport_has_separate_connect_idle_and_total_duration_limits(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse('document', [
                'http_code' => 200,
                'primary_ip' => '2606:4700:4700:0000:0000:0000:0000:1111',
                'response_headers' => ['content-length: 8'],
            ]);
        });
        $fetcher = new OnlyOfficeBoundedDocumentFetcher([
            'allowed_hosts' => ['office.example.test'],
            'max_size_bytes' => 104857600,
            'max_redirects' => 0,
            'connect_timeout_seconds' => 7,
            'idle_timeout_seconds' => 31,
            'max_duration_seconds' => 480,
            'allowed_mime_types' => ['bin' => ['text/plain']],
        ], $client, static fn (string $host): array => ['2606:4700:4700::1111']);

        $download = $fetcher->fetch('https://office.example.test/document', 'bin');
        try {
            self::assertSame(7.0, $captured['timeout']);
            self::assertSame(480.0, $captured['max_duration']);
            self::assertSame('*', $captured['no_proxy']);
            self::assertArrayNotHasKey('proxy', $captured);
            self::assertSame(['office.example.test' => '2606:4700:4700::1111'], $captured['resolve']);
            $source = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Editor/OnlyOfficeBoundedDocumentFetcher.php');
            self::assertIsString($source);
            self::assertStringContainsString('stream($response, $idleTimeout)', $source);
        } finally {
            $download->cleanup();
        }
    }

    public function test_slow_stream_fails_on_idle_timeout(): void
    {
        $body = static function (): \Generator {
            yield 'part';
            yield '';
            yield 'never-read';
        };
        $response = new MockResponse($body(), [
            'http_code' => 200,
            'primary_ip' => '93.184.216.34',
        ]);
        $fetcher = new OnlyOfficeBoundedDocumentFetcher([
            'allowed_hosts' => ['office.example.test'],
            'max_size_bytes' => 104857600,
            'max_redirects' => 0,
            'connect_timeout_seconds' => 5,
            'idle_timeout_seconds' => 20,
            'max_duration_seconds' => 600,
        ], new MockHttpClient($response), static fn (string $host): array => ['93.184.216.34']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_document_editor_download_timeout');
        $fetcher->fetch('https://office.example.test/document', 'docx');
    }

    public function test_configured_size_cannot_exceed_absolute_one_hundred_mibibyte_cap(): void
    {
        $response = new MockResponse('not-downloaded', [
            'http_code' => 200,
            'primary_ip' => '93.184.216.34',
            'response_headers' => ['content-length: 104857601'],
        ]);
        $fetcher = new OnlyOfficeBoundedDocumentFetcher([
            'allowed_hosts' => ['office.example.test'],
            'max_size_bytes' => PHP_INT_MAX,
            'max_redirects' => 0,
        ], new MockHttpClient($response), static fn (string $host): array => ['93.184.216.34']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_document_editor_download_too_large');
        $fetcher->fetch('https://office.example.test/document', 'docx');
    }

    public function test_mixed_public_and_private_dns_answers_are_rejected(): void
    {
        $fetcher = new OnlyOfficeBoundedDocumentFetcher([
            'allowed_hosts' => ['office.example.test'],
        ], new MockHttpClient(new MockResponse('unused')), static fn (string $host): array => [
            '93.184.216.34',
            '127.0.0.1',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_document_editor_download_url_denied');
        $fetcher->fetch('https://office.example.test/document', 'docx');
    }

    public function test_connected_ip_must_equal_pinned_dns_answer(): void
    {
        $response = new MockResponse('unused', [
            'http_code' => 200,
            'primary_ip' => '93.184.216.35',
        ]);
        $fetcher = new OnlyOfficeBoundedDocumentFetcher([
            'allowed_hosts' => ['office.example.test'],
        ], new MockHttpClient($response), static fn (string $host): array => ['93.184.216.34']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_document_editor_download_url_denied');
        $fetcher->fetch('https://office.example.test/document', 'docx');
    }
}

final class OnlyOfficeControlledHttpServer
{
    /** @param resource $process */
    private function __construct(
        private readonly mixed $process,
        private readonly int $port,
    ) {}

    public static function start(): self
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            self::failToStart($errorCode, $errorMessage);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (! is_string($address) || preg_match('/:(\d+)$/', $address, $matches) !== 1) {
            throw new \RuntimeException('Unable to reserve a local HTTP port.');
        }
        $port = (int) $matches[1];
        $router = __DIR__.'/../../Support/onlyoffice_http_server.php';
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, $router],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            dirname($router),
        );
        if (! is_resource($process)) {
            throw new \RuntimeException('Unable to start the controlled local HTTP server.');
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $server = new self($process, $port);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.05);
            if (is_resource($probe)) {
                fclose($probe);

                return $server;
            }
            usleep(20_000);
        }
        $server->stop();

        throw new \RuntimeException('Controlled local HTTP server did not become ready.');
    }

    public function url(): string
    {
        return 'http://127.0.0.1:'.$this->port.'/document';
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }

    private static function failToStart(int $errorCode, string $errorMessage): never
    {
        throw new \RuntimeException(sprintf('Unable to reserve a local HTTP port: %d %s', $errorCode, $errorMessage));
    }
}
