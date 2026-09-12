<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use Illuminate\Support\Facades\DB;

final class DesignIfcElementIndexer
{
    public function index(DesignArtifactVersion $version, DesignModelDerivative $derivative, string $indexPath, array $metadata): void
    {
        if (!is_file($indexPath)) {
            throw new \RuntimeException('IFC element index is not available.');
        }
        $file = fopen($indexPath, 'rb');
        if ($file === false) {
            throw new \RuntimeException('IFC element index is not readable.');
        }
        $batch = [];
        try {
            while (($line = fgets($file)) !== false) {
                $element = json_decode($line, true);
                if (!is_array($element) || !isset($element['express_id']) || !is_int($element['express_id'])) {
                    continue;
                }
                $properties = is_array($element['properties'] ?? null) ? $element['properties'] : [];
                $properties['quantities'] = is_array($element['quantities'] ?? null) ? $element['quantities'] : [];
                $properties['materials'] = is_array($element['materials'] ?? null) ? $element['materials'] : [];
                $batch[] = [
                    'organization_id' => $version->organization_id,
                    'project_id' => $version->project_id,
                    'version_id' => $version->id,
                    'derivative_id' => $derivative->id,
                    'express_id' => $element['express_id'],
                    'global_id' => is_string($element['global_id'] ?? null) ? $element['global_id'] : null,
                    'category' => is_string($element['category'] ?? null) ? $element['category'] : null,
                    'name' => is_string($element['name'] ?? null) ? $element['name'] : null,
                    'properties' => json_encode($properties, JSON_THROW_ON_ERROR),
                'classifications' => json_encode(is_array($element['classifications'] ?? null) ? $element['classifications'] : [], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (count($batch) >= 500) {
                    $this->upsert($batch);
                    $batch = [];
                }
            }
        } finally {
            fclose($file);
        }
        if ($batch !== []) {
            $this->upsert($batch);
        }
        $derivative->forceFill(['metadata' => array_merge($derivative->metadata ?? [], [
            'ifc_units' => $metadata['units'] ?? [],
            'coordination_matrix' => $metadata['coordination_matrix'] ?? [],
            'coordinate_transformations' => $metadata['transformations'] ?? [],
            'indexed_element_count' => $metadata['indexed_element_count'] ?? 0,
        ])])->save();
    }

    private function upsert(array $rows): void
    {
        DB::table('design_ifc_model_elements')->upsert(
            $rows,
            ['version_id', 'express_id'],
            ['derivative_id', 'global_id', 'category', 'name', 'properties', 'classifications', 'updated_at'],
        );
    }
}
