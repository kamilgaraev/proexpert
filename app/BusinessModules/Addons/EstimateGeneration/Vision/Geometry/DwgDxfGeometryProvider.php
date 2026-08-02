<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\CadGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProvenance;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;
use App\Models\Organization;

final readonly class DwgDxfGeometryProvider implements CadGeometryProvider
{
    public function __construct(
        private BoundedVersionedS3ObjectReader $reader,
        private CadConversionRuntime $runtime,
        private int $maxInputBytes = 52_428_800,
        private string $workspaceRoot = '',
    ) {}

    public function extract(DocumentUnitProvenance $source, Organization $organization): VectorGeometryData
    {
        $storageKey = $source->artifactPath;
        $expectedPrefix = 'org-'.$organization->getKey().'/';
        if (! str_starts_with($storageKey, $expectedPrefix) || str_contains($storageKey, '..')) {
            throw new GeometryExtractionException('cad_storage_scope_invalid');
        }
        $extension = strtolower(pathinfo($storageKey, PATHINFO_EXTENSION));
        if (! in_array($extension, ['dwg', 'dxf'], true)) {
            throw new GeometryExtractionException('cad_extension_invalid');
        }
        $content = $this->reader->read(
            (int) $organization->getKey(),
            $storageKey,
            max(1, $this->maxInputBytes),
            $source->artifactBytes,
            $source->artifactSha256,
            $source->artifactVersionId,
        )->body;
        $root = $this->workspaceRoot !== '' ? $this->workspaceRoot : sys_get_temp_dir();
        $directory = $root.DIRECTORY_SEPARATOR.'most-cad-source-'.bin2hex(random_bytes(12));
        if (! @mkdir($directory, 0700)) {
            throw new GeometryExtractionException('cad_workspace_failed');
        }
        $path = $directory.DIRECTORY_SEPARATOR.'source.'.$extension;
        try {
            if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
                throw new GeometryExtractionException('cad_source_copy_failed');
            }

            return $this->runtime->extract($path);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }
}
