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

        for ($attempt = 0; $attempt < 3; $attempt++) {
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
    }

    public function test_controlled_server_self_binds_and_uses_authenticated_readiness(): void
    {
        $test = (string) file_get_contents(__FILE__);
        $helper = (string) file_get_contents(__DIR__.'/../../Support/onlyoffice_http_server.php');

        self::assertStringContainsString("stream_socket_server('tcp://127.0.0.1:0'", $helper);
        self::assertStringContainsString("'protocol' => READINESS_PROTOCOL", $helper);
        self::assertStringContainsString("'token' => \$token", $helper);
        self::assertStringContainsString("'pid' => getmypid()", $helper);
        self::assertStringNotContainsString('f'.'sockopen', $test.$helper);
        self::assertStringNotContainsString('-'.'S', $test);
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
    private const READINESS_PROTOCOL = 'most-onlyoffice-http/1';

    /**
     * @param  resource  $process
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    private function __construct(
        private readonly mixed $process,
        private readonly mixed $stdout,
        private readonly mixed $stderr,
        private readonly int $port,
    ) {}

    public static function start(): self
    {
        $token = bin2hex(random_bytes(16));
        $router = __DIR__.'/../../Support/onlyoffice_http_server.php';
        $process = proc_open(
            [PHP_BINARY, $router, $token],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            dirname($router),
        );
        if (! is_resource($process)) {
            throw new \RuntimeException('Unable to start the controlled local HTTP server.');
        }
        fclose($pipes[0]);
        $status = proc_get_status($process);
        $expectedPid = (int) ($status['pid'] ?? 0);

        try {
            $line = self::readReadiness($process, $pipes[1], $pipes[2]);
            $ready = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($ready)
                || array_keys($ready) !== ['protocol', 'token', 'pid', 'port']
                || ($ready['protocol'] ?? null) !== self::READINESS_PROTOCOL
                || ($ready['token'] ?? null) !== $token
                || ($ready['pid'] ?? null) !== $expectedPid
                || ! is_int($ready['port'] ?? null)
                || $ready['port'] < 1
                || $ready['port'] > 65535) {
                throw new \RuntimeException('Controlled local HTTP server returned invalid readiness data.');
            }

            return new self($process, $pipes[1], $pipes[2], $ready['port']);
        } catch (\Throwable $error) {
            self::terminate($process, $pipes[1], $pipes[2]);

            throw $error;
        }
    }

    public function url(): string
    {
        return 'http://127.0.0.1:'.$this->port.'/document';
    }

    public function stop(): void
    {
        $deadline = microtime(true) + 3.0;
        $status = proc_get_status($this->process);
        while (($status['running'] ?? false) === true && microtime(true) < $deadline) {
            usleep(10_000);
            $status = proc_get_status($this->process);
        }
        if (($status['running'] ?? false) === true) {
            proc_terminate($this->process);
            usleep(50_000);
            $status = proc_get_status($this->process);
        }
        stream_set_blocking($this->stdout, true);
        stream_set_blocking($this->stderr, true);
        $unexpectedOutput = stream_get_contents($this->stdout);
        $errors = stream_get_contents($this->stderr);
        fclose($this->stdout);
        fclose($this->stderr);
        $closeCode = proc_close($this->process);
        $exitCode = (int) ($status['exitcode'] ?? $closeCode);
        if ($exitCode < 0) {
            $exitCode = $closeCode;
        }
        if ($exitCode !== 0 || $unexpectedOutput !== '' || $errors !== '') {
            throw new \RuntimeException(sprintf(
                'Controlled local HTTP server failed: exit=%d stdout=%s stderr=%s',
                $exitCode,
                trim((string) $unexpectedOutput),
                trim((string) $errors),
            ));
        }
    }

    /**
     * @param  resource  $process
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    private static function readReadiness(mixed $process, mixed $stdout, mixed $stderr): string
    {
        $line = fgets($stdout, 514);
        if (! is_string($line) || ! str_ends_with($line, "\n") || substr_count($line, "\n") !== 1) {
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === true) {
                proc_terminate($process);
            }
            stream_set_blocking($stderr, true);
            $errors = stream_get_contents($stderr);

            throw new \RuntimeException('Controlled local HTTP server readiness failed: '.trim((string) $errors));
        }

        return substr($line, 0, -1);
    }

    /**
     * @param  resource  $process
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    private static function terminate(mixed $process, mixed $stdout, mixed $stderr): void
    {
        $status = proc_get_status($process);
        if (($status['running'] ?? false) === true) {
            proc_terminate($process);
        }
        fclose($stdout);
        fclose($stderr);
        proc_close($process);
    }
}
