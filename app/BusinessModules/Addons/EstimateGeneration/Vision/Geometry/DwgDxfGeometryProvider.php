<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\CadGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\BoundedStorageReader;
use App\Models\Organization;
use App\Services\Storage\FileService;

final readonly class DwgDxfGeometryProvider implements CadGeometryProvider
{
    public function __construct(
        private FileService $fileService,
        private BoundedStorageReader $reader,
        private CadConversionRuntime $runtime,
        private int $maxInputBytes = 52_428_800,
        private string $workspaceRoot = '',
    ) {}

    public function extract(string $storageKey, Organization $organization): VectorGeometryData
    {
        $expectedPrefix = 'org-'.$organization->getKey().'/';
        if (! str_starts_with($storageKey, $expectedPrefix) || str_contains($storageKey, '..')) {
            throw new GeometryExtractionException('cad_storage_scope_invalid');
        }
        $extension = strtolower(pathinfo($storageKey, PATHINFO_EXTENSION));
        if (! in_array($extension, ['dwg', 'dxf'], true)) {
            throw new GeometryExtractionException('cad_extension_invalid');
        }
        try {
            $content = $this->reader->read(
                $this->fileService->disk($organization),
                $storageKey,
                max(1, $this->maxInputBytes)
            );
        } catch (\Throwable) {
            throw new GeometryExtractionException('cad_source_read_failed', true);
        }
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
