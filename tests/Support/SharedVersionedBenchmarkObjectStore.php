<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkImmutableObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObject;

final readonly class SharedVersionedBenchmarkObjectStore implements BenchmarkImmutableObjectStore
{
    public function __construct(private string $directory) {}

    public function read(string $path, int $maxBytes): string
    {
        return $this->describe($path, $maxBytes)->body;
    }

    public function describe(string $path, int $maxBytes): BenchmarkPrivateObject
    {
        $body = file_get_contents($this->sourceFile());
        if (! is_string($body) || strlen($body) > $maxBytes) {
            throw new BenchmarkContractException('private_object_unavailable');
        }

        return new BenchmarkPrivateObject($path, $body, strlen($body), hash('sha256', $body), 'fake-etag', 'source-version', 'application/json');
    }

    public function putImmutable(string $path, string $body, string $contentType): BenchmarkPrivateObject
    {
        $version = 'sha256-'.hash('sha256', $body);
        $file = $this->objectFile($path, $version);
        $handle = @fopen($file, 'x');
        $created = is_resource($handle);
        if ($created) {
            fwrite($handle, $body);
            fclose($handle);
        }
        $stored = file_get_contents($file);
        if (! is_string($stored) || ! hash_equals(hash('sha256', $body), hash('sha256', $stored))) {
            throw new BenchmarkContractException('private_object_immutable_conflict');
        }
        file_put_contents($this->directory.'/events.log', ($created ? 'CREATE ' : 'ADOPT ').$path.' '.$version."\n", FILE_APPEND | LOCK_EX);
        if ($created && getenv('BENCHMARK_FAKE_PAUSE_AFTER_CREATE') === '1') {
            $deadline = microtime(true) + 10;
            while (! is_file($this->directory.'/continue') && microtime(true) < $deadline) {
                usleep(10_000);
            }
            if (! is_file($this->directory.'/continue')) {
                throw new \RuntimeException('fake_coordination_timeout');
            }
        }

        return new BenchmarkPrivateObject($path, $stored, strlen($stored), hash('sha256', $stored), 'fake-etag', $version, $contentType, $created);
    }

    public function removeCreated(BenchmarkPrivateObject $object): void
    {
        if (! $object->created) {
            return;
        }
        if ($object->versionId === null || trim($object->versionId) === '') {
            throw new \RuntimeException('version_required');
        }
        file_put_contents($this->directory.'/events.log', 'DELETE '.$object->path.' '.$object->versionId."\n", FILE_APPEND | LOCK_EX);
        @unlink($this->objectFile($object->path, $object->versionId));
    }

    public function objectExists(string $path, string $version): bool
    {
        return is_file($this->objectFile($path, $version));
    }

    private function sourceFile(): string
    {
        return $this->directory.'/source.json';
    }

    private function objectFile(string $path, string $version): string
    {
        return $this->directory.'/'.hash('sha256', $path).'--'.$version.'.object';
    }
}
