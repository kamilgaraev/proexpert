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
use Symfony\Component\Process\Process;

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

    public function test_controlled_server_self_binds_and_uses_authenticated_readiness(): void
    {
        $test = (string) file_get_contents(__FILE__);
        $helper = (string) file_get_contents(__DIR__.'/../../Support/onlyoffice_http_server.php');

        self::assertStringContainsString("stream_socket_server('tcp://127.0.0.1:0'", $helper);
        self::assertStringContainsString("'protocol' => READINESS_PROTOCOL", $helper);
        self::assertStringContainsString("'token' => \$token", $helper);
        self::assertStringContainsString("'pid' => getmypid()", $helper);
        self::assertStringContainsString('new Process(', $test);
        self::assertStringNotContainsString('f'.'sockopen', $test.$helper);
        self::assertStringNotContainsString('proc'.'_open', $test);
        self::assertStringNotContainsString('stream_set_'.'blocking', $test);
        self::assertStringNotContainsString('-'.'S', $test);
    }

    public function test_controlled_server_times_out_and_reaps_child_that_never_reports_readiness(): void
    {
        $startedAt = microtime(true);
        try {
            OnlyOfficeControlledHttpServer::start('never-ready', 0.25);
            self::fail('A child without readiness must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('readiness timeout', $error->getMessage());
        }

        self::assertLessThan(2.0, microtime(true) - $startedAt);
    }

    public function test_controlled_server_force_reaps_stalled_child_with_bounded_cleanup(): void
    {
        $server = OnlyOfficeControlledHttpServer::start('stall', 2.0);
        $startedAt = microtime(true);
        $server->stop();

        self::assertLessThan(2.0, microtime(true) - $startedAt);
        self::assertTrue($server->wasReaped());
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

    private const MAX_OUTPUT_BYTES = 8192;

    private function __construct(
        private readonly Process $process,
        private readonly int $port,
        private readonly bool $allowForcedStop,
        private bool $reaped = false,
    ) {}

    public static function start(string $mode = 'serve', float $readinessTimeout = 3.0): self
    {
        if (! in_array($mode, ['serve', 'never-ready', 'stall'], true)
            || $readinessTimeout < 0.05 || $readinessTimeout > 5.0) {
            throw new \InvalidArgumentException('Invalid controlled server options.');
        }
        $token = bin2hex(random_bytes(16));
        $helper = __DIR__.'/../../Support/onlyoffice_http_server.php';
        $process = new Process([PHP_BINARY, $helper, $token, $mode], dirname($helper), timeout: 15.0);
        $process->start();
        $expectedPid = $process->getPid();

        try {
            $ready = self::readReadiness($process, $readinessTimeout);
            if (! is_array($ready)
                || array_keys($ready) !== ['protocol', 'token', 'pid', 'port']
                || ($ready['protocol'] ?? null) !== self::READINESS_PROTOCOL
                || ($ready['token'] ?? null) !== $token
                || ! is_int($ready['pid'] ?? null)
                || $ready['pid'] < 1
                || (DIRECTORY_SEPARATOR !== '\\' && $ready['pid'] !== $expectedPid)
                || ! is_int($ready['port'] ?? null)
                || $ready['port'] < 1
                || $ready['port'] > 65535) {
                throw new \RuntimeException('Controlled local HTTP server returned invalid readiness data: '.json_encode([
                    'expected_pid' => $expectedPid,
                    'ready' => $ready,
                ], JSON_THROW_ON_ERROR));
            }

            return new self($process, $ready['port'], $mode === 'stall');
        } catch (\Throwable $error) {
            $result = self::shutdown($process);
            $details = trim($result['stderr']);
            if (! $result['reaped']) {
                throw new \RuntimeException('Controlled local HTTP server could not be reaped.', previous: $error);
            }
            if ($error instanceof ReadinessTimeoutException) {
                throw new \RuntimeException('Controlled local HTTP server readiness timeout'.($details === '' ? '' : ': '.$details), previous: $error);
            }

            throw $error;
        }
    }

    public function url(): string
    {
        return 'http://127.0.0.1:'.$this->port.'/document';
    }

    public function stop(): void
    {
        if ($this->reaped) {
            return;
        }
        $result = self::shutdown($this->process);
        $this->reaped = $result['reaped'];
        if (! $result['reaped']
            || (! $this->allowForcedStop && ($result['forced'] || $result['exit_code'] !== 0))
            || $result['stdout'] !== '' || $result['stderr'] !== '') {
            throw new \RuntimeException(sprintf(
                'Controlled local HTTP server failed: exit=%d forced=%d stdout=%s stderr=%s',
                $result['exit_code'],
                $result['forced'] ? 1 : 0,
                trim($result['stdout']),
                trim($result['stderr']),
            ));
        }
    }

    public function wasReaped(): bool
    {
        return $this->reaped;
    }

    /** @return array<string, mixed> */
    private static function readReadiness(Process $process, float $timeout): array
    {
        $output = '';
        $errors = '';
        $deadline = microtime(true) + $timeout;
        do {
            self::appendBounded($output, $process->getIncrementalOutput(), 512, 'readiness');
            self::appendBounded($errors, $process->getIncrementalErrorOutput(), self::MAX_OUTPUT_BYTES, 'error');
            if ($errors !== '') {
                throw new \RuntimeException('Controlled local HTTP server readiness failed: '.trim($errors));
            }
            if (str_contains($output, "\n")) {
                if (! str_ends_with($output, "\n") || substr_count($output, "\n") !== 1) {
                    throw new \RuntimeException('Controlled local HTTP server returned invalid readiness frame.');
                }

                return json_decode(substr($output, 0, -1), true, flags: JSON_THROW_ON_ERROR);
            }
            if (! $process->isRunning()) {
                throw new \RuntimeException('Controlled local HTTP server exited before readiness.');
            }
            usleep(5_000);
        } while (microtime(true) < $deadline);

        throw new ReadinessTimeoutException;
    }

    /** @return array{reaped: bool, forced: bool, exit_code: int, stdout: string, stderr: string} */
    private static function shutdown(Process $process): array
    {
        $standardOutput = '';
        $standardError = '';
        $forced = false;
        $naturalExitDeadline = microtime(true) + 0.5;
        while ($process->isRunning() && microtime(true) < $naturalExitDeadline) {
            self::appendBounded($standardOutput, $process->getIncrementalOutput(), self::MAX_OUTPUT_BYTES, 'output');
            self::appendBounded($standardError, $process->getIncrementalErrorOutput(), self::MAX_OUTPUT_BYTES, 'error');
            usleep(5_000);
        }
        if ($process->isRunning()) {
            $forced = $process->stop(0.15, 9) !== 0;
        }
        self::appendBounded($standardOutput, $process->getIncrementalOutput(), self::MAX_OUTPUT_BYTES, 'output');
        self::appendBounded($standardError, $process->getIncrementalErrorOutput(), self::MAX_OUTPUT_BYTES, 'error');
        $reaped = ! $process->isRunning() && $process->isTerminated();

        return [
            'reaped' => $reaped,
            'forced' => $forced,
            'exit_code' => $process->getExitCode() ?? -1,
            'stdout' => $standardOutput,
            'stderr' => $standardError,
        ];
    }

    private static function appendBounded(string &$buffer, string $chunk, int $maximum, string $stream): void
    {
        if (strlen($buffer) + strlen($chunk) > $maximum) {
            throw new \RuntimeException("Controlled local HTTP server {$stream} exceeded limit.");
        }
        $buffer .= $chunk;
    }
}

final class ReadinessTimeoutException extends \RuntimeException {}
