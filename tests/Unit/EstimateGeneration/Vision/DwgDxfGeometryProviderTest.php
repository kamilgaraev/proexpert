<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadConversionRuntime;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\DwgDxfGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\BoundedStorageReader;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

final class DwgDxfGeometryProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function real_synthetic_dwg_is_decoded_by_libredwg_runtime(): void
    {
        $binary = getenv('LIBREDWG_DWGREAD_BINARY');
        if (! is_string($binary) || ! is_file($binary)) {
            self::fail('Required gate: задайте LIBREDWG_DWGREAD_BINARY с LibreDWG 0.13.4.');
        }
        $root = dirname(__DIR__, 4);
        $runtime = new CadConversionRuntime('python', $root.'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py', $binary);
        $result = $runtime->extract($root.'/tests/Fixtures/EstimateGeneration/Vision/simple-house.dwg');

        self::assertStringContainsString('libredwg:0.13.4', $result->runtimeVersion);
        self::assertNotEmpty($result->entities);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $result->sourceFingerprint);
    }

    #[Test]
    public function provider_reads_private_organization_scoped_s3_object(): void
    {
        $root = dirname(__DIR__, 4);
        $content = file_get_contents($root.'/tests/Fixtures/EstimateGeneration/Vision/simple-house.dxf');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('size')->once()->andReturn(strlen($content));
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);
        $disk->shouldReceive('readStream')->once()->andReturn($stream);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('disk')->once()->andReturn($disk);
        $organization = new Organization;
        $organization->id = 42;
        $runtime = new CadConversionRuntime('python', $root.'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py');
        $provider = new DwgDxfGeometryProvider($files, new BoundedStorageReader, $runtime);

        self::assertNotEmpty($provider->extract('org-42/drawings/house.dxf', $organization)->entities);
        $this->expectExceptionMessage('cad_storage_scope_invalid');
        $provider->extract('../org-42/drawings/house.dxf', $organization);
    }

    #[Test]
    #[WithoutErrorHandler]
    public function provider_reports_workspace_creation_failure(): void
    {
        $root = dirname(__DIR__, 4);
        $content = file_get_contents($root.'/tests/Fixtures/EstimateGeneration/Vision/simple-house.dxf');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('size')->once()->andReturn(strlen($content));
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);
        $disk->shouldReceive('readStream')->once()->andReturn($stream);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('disk')->once()->andReturn($disk);
        $organization = new Organization;
        $organization->id = 42;
        $invalidRoot = tempnam(sys_get_temp_dir(), 'cad-root-file-');
        try {
            $provider = new DwgDxfGeometryProvider(
                $files,
                new BoundedStorageReader,
                new CadConversionRuntime('python', $root.'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py'),
                workspaceRoot: $invalidRoot,
            );
            $this->expectExceptionMessage('cad_workspace_failed');
            $provider->extract('org-42/drawings/house.dxf', $organization);
        } finally {
            @unlink($invalidRoot);
        }
    }
}
