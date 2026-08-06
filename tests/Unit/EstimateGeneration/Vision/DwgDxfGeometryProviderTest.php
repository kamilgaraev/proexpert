<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProvenance;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectLocatorException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadConversionRuntime;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\DwgDxfGeometryProvider;
use App\Models\Organization;
use App\Services\Storage\FileService;
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
        $files = Mockery::mock(FileService::class);
        $this->expectPinnedRead($files, 'org-42/drawings/house.dxf', $content);
        $organization = new Organization;
        $organization->id = 42;
        $runtime = new CadConversionRuntime('python', $root.'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py');
        $provider = new DwgDxfGeometryProvider(new BoundedVersionedS3ObjectReader($files), $runtime);

        self::assertNotEmpty($provider->extract($this->source('org-42/drawings/house.dxf', $content), $organization)->entities);
        $this->expectExceptionMessage('cad_storage_scope_invalid');
        $provider->extract($this->source('../org-42/drawings/house.dxf', $content), $organization);
    }

    #[Test]
    #[WithoutErrorHandler]
    public function provider_reports_workspace_creation_failure(): void
    {
        $root = dirname(__DIR__, 4);
        $content = file_get_contents($root.'/tests/Fixtures/EstimateGeneration/Vision/simple-house.dxf');
        $files = Mockery::mock(FileService::class);
        $this->expectPinnedRead($files, 'org-42/drawings/house.dxf', $content);
        $organization = new Organization;
        $organization->id = 42;
        $invalidRoot = tempnam(sys_get_temp_dir(), 'cad-root-file-');
        try {
            $provider = new DwgDxfGeometryProvider(
                new BoundedVersionedS3ObjectReader($files),
                new CadConversionRuntime('python', $root.'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py'),
                workspaceRoot: $invalidRoot,
            );
            $this->expectExceptionMessage('cad_workspace_failed');
            $provider->extract($this->source('org-42/drawings/house.dxf', $content), $organization);
        } finally {
            @unlink($invalidRoot);
        }
    }

    #[Test]
    public function provider_rejects_a_source_that_does_not_match_its_pinned_hash(): void
    {
        $root = dirname(__DIR__, 4);
        $content = file_get_contents($root.'/tests/Fixtures/EstimateGeneration/Vision/simple-house.dxf');
        $files = Mockery::mock(FileService::class);
        $this->expectPinnedRead($files, 'org-42/drawings/house.dxf', $content);
        $organization = new Organization;
        $organization->id = 42;
        $provider = new DwgDxfGeometryProvider(
            new BoundedVersionedS3ObjectReader($files),
            new CadConversionRuntime('python', $root.'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py'),
        );
        $source = DocumentUnitProvenance::fromLocator(DocumentUnitType::CadDrawing, 'sha256:'.str_repeat('a', 64), [
            'source_kind' => 'cad',
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'coordinate_space' => 'cad_model',
            'artifact_path' => 'org-42/drawings/house.dxf',
            'artifact_bytes' => strlen($content),
            'artifact_sha256' => 'sha256:'.str_repeat('a', 64),
        ]);

        $this->expectException(S3ObjectLocatorException::class);
        $provider->extract($source, $organization);
    }

    private function expectPinnedRead(FileService $files, string $path, string $content): void
    {
        $files->shouldReceive('describeCurrent')->once()->with(
            $path,
            Mockery::type('int'),
        )->andReturn([
            'path' => $path,
            'body' => $content,
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'etag' => null,
            'content_type' => 'application/dxf',
        ]);
    }

    private function source(string $path, string $content): DocumentUnitProvenance
    {
        $sha256 = 'sha256:'.hash('sha256', $content);

        return DocumentUnitProvenance::fromLocator(DocumentUnitType::CadDrawing, $sha256, [
            'source_kind' => 'cad',
            'source_version' => $sha256,
            'coordinate_space' => 'cad_model',
            'artifact_path' => $path,
            'artifact_bytes' => strlen($content),
            'artifact_sha256' => $sha256,
        ]);
    }
}
