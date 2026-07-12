<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\FileServiceBenchmarkPrivateObjectStore;
use App\Services\Storage\FileService;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class FileServiceBenchmarkPrivateObjectStoreTest extends TestCase
{
    private string $directory;

    private FilesystemAdapter $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/most-benchmark-store-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $adapter = new LocalFilesystemAdapter($this->directory);
        $this->disk = new FilesystemAdapter(new Filesystem($adapter), $adapter, []);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_it_reads_authoritative_metadata_and_preserves_immutable_content_addressed_object(): void
    {
        $body = json_encode([['case_id' => 'case-1']], JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $body);
        $path = 'org-7/estimate-generation/benchmarks/123e4567-e89b-12d3-a456-426614174000/'.$hash.'.json';
        $this->disk->put($path, $body, ['mimetype' => 'application/json']);
        $store = new FileServiceBenchmarkPrivateObjectStore($this->files());

        $object = $store->describe($path, 1_048_576);

        self::assertSame($body, $object->body);
        self::assertSame(strlen($body), $object->contentLength);
        self::assertSame($hash, $object->sha256);
        self::assertSame($hash, $store->putImmutable($path, $body, 'application/json')->sha256);
    }

    public function test_it_rejects_acceptance_corpus_and_non_content_addressed_paths(): void
    {
        $store = new FileServiceBenchmarkPrivateObjectStore($this->files());

        $this->expectException(BenchmarkContractException::class);
        $this->expectExceptionMessage('private_object_path_invalid');
        $store->read('org-7/estimate-generation/benchmarks/acceptance/manifest.json', 1024);
    }

    private function files(): FileService
    {
        return new class($this->disk) extends FileService
        {
            public function __construct(private readonly FilesystemAdapter $adapter) {}

            public function disk(?\App\Models\Organization $organization = null): FilesystemAdapter
            {
                return $this->adapter;
            }

            public function describeVersion(string $path, ?string $versionId, int $maxBytes = 64_000_000): array
            {
                $body = $this->adapter->get($path);

                return ['path' => $path, 'body' => $body, 'size' => strlen($body),
                    'sha256' => hash('sha256', $body), 'etag' => hash('md5', $body),
                    'version_id' => $versionId ?? 'test-version', 'content_type' => 'application/json'];
            }

            public function putImmutable(string $path, string $body, string $contentType): array
            {
                $created = ! $this->adapter->exists($path);
                if ($created) {
                    $this->adapter->put($path, $body);
                }

                return [...$this->describeVersion($path, null), 'created' => $created];
            }

            public function removeImmutable(string $path, ?string $versionId): void
            {
                $this->adapter->delete($path);
            }
        };
    }
}
