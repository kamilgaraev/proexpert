<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkImmutableObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObject;

final readonly class SharedVersionedBenchmarkObjectStore implements BenchmarkImmutableObjectStore
{
    public function __construct(private string $directory) {}

    public function seedObject(string $path, string $body, string $version = 'source-version'): void
    {
        $this->withState($path, function (array &$state) use ($body, $version): void {
            $state = ['version' => $version, 'body' => base64_encode($body), 'content_type' => 'application/json'];
        });
    }

    public function read(string $path, int $maxBytes): string
    {
        return $this->describe($path, $maxBytes)->body;
    }

    public function describe(string $path, int $maxBytes): BenchmarkPrivateObject
    {
        $object = $this->withState($path, function (array &$state) use ($path, $maxBytes): BenchmarkPrivateObject {
            $this->log('HEAD', $path, (string) ($state['version'] ?? '-'));
            $body = isset($state['body']) ? base64_decode((string) $state['body'], true) : false;
            if (! is_string($body) || strlen($body) > $maxBytes) {
                throw new BenchmarkContractException('private_object_unavailable');
            }
            $version = (string) $state['version'];
            $this->log('GET', $path, $version);

            return new BenchmarkPrivateObject($path, $body, strlen($body), hash('sha256', $body), 'fake-etag', $version, (string) $state['content_type']);
        });

        if (getenv('BENCHMARK_FAKE_DELETE_AFTER_DESCRIBE') === '1') {
            $this->withState($path, function (array &$state) use ($path, $object): void {
                if (($state['version'] ?? null) === $object->versionId) {
                    $state = [];
                    $this->log('DELETE_EXTERNAL', $path, (string) $object->versionId);
                }
            });
        }

        return $object;
    }

    public function putImmutable(string $path, string $body, string $contentType): BenchmarkPrivateObject
    {
        $object = $this->withState($path, function (array &$state) use ($path, $body, $contentType): BenchmarkPrivateObject {
            $created = ! isset($state['version']);
            if ($created) {
                $state = ['version' => 'created-'.bin2hex(random_bytes(8)), 'body' => base64_encode($body), 'content_type' => $contentType];
            }
            $stored = isset($state['body']) ? base64_decode((string) $state['body'], true) : false;
            if (! is_string($stored) || ! hash_equals(hash('sha256', $body), hash('sha256', $stored))) {
                throw new BenchmarkContractException('private_object_immutable_conflict');
            }
            $version = (string) $state['version'];
            $this->log($created ? 'CREATE' : 'ADOPT', $path, $version);

            return new BenchmarkPrivateObject($path, $stored, strlen($stored), hash('sha256', $stored), 'fake-etag', $version, (string) $state['content_type'], $created);
        });

        if (getenv('BENCHMARK_FAKE_PAUSE_AFTER_PUT') === '1') {
            $deadline = microtime(true) + 10;
            while (! is_file($this->directory.'/continue') && microtime(true) < $deadline) {
                usleep(10_000);
            }
            if (! is_file($this->directory.'/continue')) {
                throw new \RuntimeException('fake_coordination_timeout');
            }
        }

        return $object;
    }

    public function removeCreated(BenchmarkPrivateObject $object): void
    {
        if (! $object->created || $object->versionId === null || trim($object->versionId) === '') {
            return;
        }
        $this->withState($object->path, function (array &$state) use ($object): void {
            if (($state['version'] ?? null) === $object->versionId) {
                $state = [];
                $this->log('DELETE', $object->path, (string) $object->versionId);
            }
        });
    }

    public function objectExists(string $path, string $version): bool
    {
        return $this->withState($path, static fn (array &$state): bool => ($state['version'] ?? null) === $version);
    }

    private function withState(string $path, callable $callback): mixed
    {
        $file = $this->directory.'/'.hash('sha256', $path).'.state';
        $handle = fopen($file, 'c+');
        if (! is_resource($handle) || ! flock($handle, LOCK_EX)) {
            throw new \RuntimeException('fake_state_lock_failed');
        }
        try {
            rewind($handle);
            $json = stream_get_contents($handle);
            $state = is_string($json) && $json !== '' ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : [];
            $result = $callback($state);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function log(string $event, string $path, string $version): void
    {
        file_put_contents($this->directory.'/events.log', $event.' '.$path.' '.$version."\n", FILE_APPEND | LOCK_EX);
    }
}
