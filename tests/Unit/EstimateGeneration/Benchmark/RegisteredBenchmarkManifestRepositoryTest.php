<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifestException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RegisteredBenchmarkManifestRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegisteredBenchmarkManifestRepositoryTest extends TestCase
{
    #[Test]
    public function registered_locator_and_reference_are_bound_to_the_exact_revision(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $hash = hash_file('sha256', $root.'/production-replay-manifest.json');
        $repository = new RegisteredBenchmarkManifestRepository($root, [
            'repository-production-replay:v1' => ['locator' => 'production-replay-manifest.json', 'sha256' => $hash],
        ]);

        self::assertSame($hash, $repository->byReference('repository-production-replay:v1')->manifestSha256);
        self::assertSame('repository-production-replay:v1', $repository->byLocator('production-replay-manifest.json')['reference']);

        foreach ([
            ['locator' => '../manifest.json', 'sha256' => $hash],
            ['locator' => 'production-replay-manifest.json', 'sha256' => str_repeat('0', 64)],
        ] as $descriptor) {
            try {
                (new RegisteredBenchmarkManifestRepository($root, ['repository-bad:v1' => $descriptor]))
                    ->byReference('repository-bad:v1');
                self::fail('Invalid registered descriptor was accepted.');
            } catch (BenchmarkManifestException) {
                self::addToAssertionCount(1);
            }
        }

        $link = $root.'/registered-manifest-link.json';
        if (@symlink($root.'/production-replay-manifest.json', $link)) {
            try {
                (new RegisteredBenchmarkManifestRepository($root, [
                    'repository-link:v1' => ['locator' => 'registered-manifest-link.json', 'sha256' => $hash],
                ]))->byReference('repository-link:v1');
                self::fail('Symlinked manifest was accepted.');
            } catch (BenchmarkManifestException) {
                self::addToAssertionCount(1);
            } finally {
                unlink($link);
            }
        }

        $this->expectException(BenchmarkManifestException::class);
        $repository->byLocator('manifest.json');
    }

    #[Test]
    public function two_registered_revisions_with_the_same_case_id_do_not_alias(): void
    {
        $sourceRoot = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $root = sys_get_temp_dir().'/most-registered-manifests-'.bin2hex(random_bytes(5));
        mkdir($root.'/regression/replay-vector-wall-opening-001', 0700, true);
        foreach (['input.dxf', 'expected.json'] as $file) {
            copy($sourceRoot.'/regression/replay-vector-wall-opening-001/'.$file,
                $root.'/regression/replay-vector-wall-opening-001/'.$file);
        }
        $source = json_decode((string) file_get_contents($sourceRoot.'/production-replay-manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $source['cases'] = [$source['cases'][0]];
        $source['manifest_version'] = 'registered-a:v1';
        file_put_contents($root.'/a.json', json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $source['manifest_version'] = 'registered-b:v1';
        $source['cases'][0]['provenance'] = 'synthetic:independent-revision-b';
        file_put_contents($root.'/b.json', json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $repository = new RegisteredBenchmarkManifestRepository($root, [
            'registered-a:v1' => ['locator' => 'a.json', 'sha256' => hash_file('sha256', $root.'/a.json')],
            'registered-b:v1' => ['locator' => 'b.json', 'sha256' => hash_file('sha256', $root.'/b.json')],
        ]);

        try {
            self::assertSame($repository->byReference('registered-a:v1')->case('reg-replay-vector-wall-opening-001')->id,
                $repository->byReference('registered-b:v1')->case('reg-replay-vector-wall-opening-001')->id);
            self::assertNotSame($repository->byReference('registered-a:v1')->manifestSha256,
                $repository->byReference('registered-b:v1')->manifestSha256);
        } finally {
            unlink($root.'/a.json');
            unlink($root.'/b.json');
            unlink($root.'/regression/replay-vector-wall-opening-001/input.dxf');
            unlink($root.'/regression/replay-vector-wall-opening-001/expected.json');
            rmdir($root.'/regression/replay-vector-wall-opening-001');
            rmdir($root.'/regression');
            rmdir($root);
        }
    }
}
