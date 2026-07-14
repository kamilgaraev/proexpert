<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Training;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkImmutableObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObject;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\DatasetImportCorpusOrchestrator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\DatasetImportCorpusPlan;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\DatasetImportUpload;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatasetImportCorpusOrchestratorTest extends TestCase
{
    private const BASE = 'org-7/estimate-generation/benchmarks/acceptance/';

    #[Test]
    public function invalid_corpora_fail_before_storage_or_persistence(): void
    {
        foreach ([
            [$this->uploads(expected: false), 'dataset_import_required_object_missing'],
            [$this->uploads(input: false), 'dataset_import_required_object_missing'],
            [[...$this->uploads(), $this->upload('unexpected')], 'dataset_import_unexpected_upload'],
        ] as [$uploads, $message]) {
            $store = new CorpusStore;
            $persisted = 0;
            try {
                $plan = DatasetImportCorpusPlan::fromManifest($this->manifest(), BenchmarkDatasetType::Acceptance, self::BASE, $uploads);
                (new DatasetImportCorpusOrchestrator($store))->execute('manifest-path', '{}', hash('sha256', '{}'), $plan, function () use (&$persisted): void {
                    $persisted++;
                });
                self::fail('Invalid corpus was accepted.');
            } catch (DomainException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
            self::assertSame([], $store->puts);
            self::assertSame(0, $persisted);
        }
    }

    #[Test]
    public function shared_hash_is_uploaded_once_and_staged_for_every_locator(): void
    {
        $plan = DatasetImportCorpusPlan::fromManifest($this->manifest(true), BenchmarkDatasetType::Acceptance, self::BASE, [$this->upload('shared')]);
        $store = new CorpusStore;
        $persisted = 0;
        (new DatasetImportCorpusOrchestrator($store))->execute('manifest-path', '{}', hash('sha256', '{}'), $plan, function ($manifest, array $receipts) use (&$persisted): void {
            $persisted++;
            self::assertNotSame('', $manifest->versionId);
            self::assertCount(2, $receipts);
        });
        self::assertSame(1, $persisted);
        self::assertCount(3, $store->puts);
    }

    #[Test]
    public function late_mismatch_compensates_only_created_objects_and_never_persists(): void
    {
        $store = new CorpusStore(3, 1);
        $persisted = 0;
        $plan = DatasetImportCorpusPlan::fromManifest($this->manifest(), BenchmarkDatasetType::Acceptance, self::BASE, $this->uploads());
        try {
            (new DatasetImportCorpusOrchestrator($store))->execute('manifest-path', '{}', hash('sha256', '{}'), $plan, function () use (&$persisted): void {
                $persisted++;
            });
            self::fail('Mismatching receipt was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('dataset_import_receipt_invalid', $exception->getMessage());
        }
        self::assertSame(0, $persisted);
        self::assertCount(2, $store->removed);
        self::assertNotContains('manifest-path', $store->removed);
    }

    #[Test]
    public function full_corpus_persists_and_retry_reuses_immutable_receipts(): void
    {
        $store = new CorpusStore;
        $plan = DatasetImportCorpusPlan::fromManifest($this->manifest(), BenchmarkDatasetType::Acceptance, self::BASE, $this->uploads());
        $orchestrator = new DatasetImportCorpusOrchestrator($store);
        $persisted = 0;
        $persist = function () use (&$persisted): int {
            return ++$persisted;
        };
        self::assertSame(1, $orchestrator->execute('manifest-path', '{}', hash('sha256', '{}'), $plan, $persist));
        self::assertSame(2, $orchestrator->execute('manifest-path', '{}', hash('sha256', '{}'), $plan, $persist));
        self::assertSame([], $store->removed);
    }

    #[Test]
    public function persistence_failure_compensates_every_new_operation_receipt(): void
    {
        $store = new CorpusStore;
        $plan = DatasetImportCorpusPlan::fromManifest($this->manifest(), BenchmarkDatasetType::Acceptance, self::BASE, $this->uploads());

        try {
            (new DatasetImportCorpusOrchestrator($store))->execute('manifest-path', '{}', hash('sha256', '{}'), $plan, static function (): never {
                throw new \RuntimeException('db_create_failed');
            });
            self::fail('Persistence failure was swallowed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('db_create_failed', $exception->getMessage());
        }

        self::assertCount(3, $store->removed);
    }

    /** @return list<DatasetImportUpload> */
    private function uploads(bool $input = true, bool $expected = true): array
    {
        return array_values(array_filter([$input ? $this->upload('input') : null, $expected ? $this->upload('expected') : null]));
    }

    private function upload(string $body): DatasetImportUpload
    {
        return new DatasetImportUpload('reference_estimate', $body.'.json', 'application/json', $body);
    }

    private function manifest(bool $shared = false): BenchmarkManifest
    {
        return BenchmarkManifest::fromArray([
            'schema_version' => 1, 'manifest_version' => 'dataset-import:v1',
            'cases' => [[
                'id' => 'case-one', 'dataset' => 'acceptance', 'source_type' => 'dxf',
                'input_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/input.json',
                'expected_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/case/expected.json',
                'input_sha256' => hash('sha256', $shared ? 'shared' : 'input'),
                'expected_sha256' => hash('sha256', $shared ? 'shared' : 'expected'),
                'license' => 'CC0-1.0', 'provenance' => 'synthetic:test', 'tags' => ['test'],
                'schema_version' => 1, 'expected_model_schema_version' => 'benchmark:v1',
                'allowed_capabilities' => ['geometry'],
            ]],
        ], __DIR__, hash('sha256', 'manifest'), false);
    }
}

final class CorpusStore implements BenchmarkImmutableObjectStore
{
    /** @var list<string> */
    public array $puts = [];

    /** @var list<string> */
    public array $removed = [];

    /** @var array<string, BenchmarkPrivateObject> */
    private array $objects = [];

    public function __construct(private readonly ?int $mismatchAt = null, private readonly ?int $preexistingAt = null) {}

    public function read(string $path, int $maxBytes): string
    {
        return $this->objects[$path]->body;
    }

    public function describe(string $path, int $maxBytes): BenchmarkPrivateObject
    {
        return $this->objects[$path];
    }

    public function putImmutable(string $path, string $body, string $contentType): BenchmarkPrivateObject
    {
        $this->puts[] = $path;
        $number = count($this->puts);
        if (isset($this->objects[$path])) {
            $old = $this->objects[$path];

            return new BenchmarkPrivateObject($path, $body, strlen($body), hash('sha256', $body), 'etag', $old->versionId, $contentType, false);
        }
        $created = $number !== $this->preexistingAt;
        $receiptPath = $number === $this->mismatchAt ? $path.'-wrong' : $path;
        $object = new BenchmarkPrivateObject($receiptPath, $body, strlen($body), hash('sha256', $body), 'etag', 'version-'.$number, $contentType, $created);
        $this->objects[$path] = $object;

        return $object;
    }

    public function removeCreated(BenchmarkPrivateObject $object): void
    {
        if ($object->created) {
            $this->removed[] = $object->path;
        }
    }
}
