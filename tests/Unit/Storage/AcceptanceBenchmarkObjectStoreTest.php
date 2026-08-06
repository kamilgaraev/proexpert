<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\FileServiceAcceptanceBenchmarkObjectStore;
use App\Services\Storage\FileService;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AcceptanceBenchmarkObjectStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_reads_private_org_object_through_current_object_gateway_and_closes_stream(): void
    {
        $path = 'org-12/estimate-generation/benchmarks/acceptance/corpus/case.json';
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, '{"case":"ok"}');
        rewind($stream);

        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('readCurrent')->once()->with($path)->andReturn($stream);
        $store = new FileServiceAcceptanceBenchmarkObjectStore($files);

        self::assertInstanceOf(BenchmarkPrivateObjectStore::class, $store);
        self::assertSame('{"case":"ok"}', $store->read($path, 1024));
        self::assertFalse(is_resource($stream));
    }

    public function test_rejects_object_larger_than_contract_limit_and_closes_stream(): void
    {
        $path = 'org-12/estimate-generation/benchmarks/acceptance/corpus/case.json';
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'oversized');
        rewind($stream);

        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('readCurrent')->once()->with($path)->andReturn($stream);
        $store = new FileServiceAcceptanceBenchmarkObjectStore($files);

        try {
            $store->read($path, 4);
            self::fail('Oversized object must be rejected.');
        } catch (BenchmarkContractException $exception) {
            self::assertSame('private_object_too_large', $exception->getMessage());
        }

        self::assertFalse(is_resource($stream));
    }

    public function test_rejects_path_outside_acceptance_namespace_before_storage_read(): void
    {
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('readCurrent');
        $store = new FileServiceAcceptanceBenchmarkObjectStore($files);

        $this->expectException(BenchmarkContractException::class);
        $this->expectExceptionMessage('private_object_path_invalid');

        $store->read('org-12/estimate-generation/benchmarks/../secret.json', 1024);
    }

    public function test_maps_gateway_read_failure_to_stable_benchmark_error(): void
    {
        $path = 'org-12/estimate-generation/benchmarks/acceptance/corpus/missing.json';
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('readCurrent')
            ->once()
            ->with($path)
            ->andThrow(new RuntimeException('storage_object_read_failed'));
        $store = new FileServiceAcceptanceBenchmarkObjectStore($files);

        $this->expectException(BenchmarkContractException::class);
        $this->expectExceptionMessage('private_object_unavailable');

        $store->read($path, 1024);
    }
}
