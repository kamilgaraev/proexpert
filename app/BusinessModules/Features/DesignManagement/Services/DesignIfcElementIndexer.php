<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\AIAssistant\Services\Rag\DesignRagMutationBridge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DesignIfcElementIndexer
{
    public function index(DesignArtifactVersion $version, DesignModelDerivative $derivative, string $indexPath, array $metadata): void
    {
        if ((int) $derivative->version_id !== (int) $version->id
            || (int) $derivative->organization_id !== (int) $version->organization_id
            || (int) $derivative->project_id !== (int) $version->project_id) {
            throw new \RuntimeException('IFC element index scope does not match its derivative.');
        }
        DB::transaction(fn () => $this->replaceIndex($version, $derivative, $indexPath, $metadata));
    }

    private function replaceIndex(DesignArtifactVersion $version, DesignModelDerivative $derivative, string $indexPath, array $metadata): void
    {
        if (!is_file($indexPath)) {
            throw new \RuntimeException('IFC element index is not available.');
        }
        $file = fopen($indexPath, 'rb');
        if ($file === false) {
            throw new \RuntimeException('IFC element index is not readable.');
        }
        $batch = [];
        $generation = (string) Str::uuid();
        $elements = 0;
        try {
            while (($line = fgets($file)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $element = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($element) || ! is_int($element['express_id'] ?? null) || $element['express_id'] <= 0) {
                    throw new \RuntimeException('IFC element index contains an invalid record.');
                }
                $properties = $this->elementProperties($element);
                $properties['_ifc_index_generation'] = $generation;
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
                $elements++;
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
        $scope = DesignIfcModelElement::query()->where('version_id', $version->id)
            ->where('organization_id', $version->organization_id)->where('project_id', $version->project_id);
        $actualCount = (clone $scope)->where('properties->_ifc_index_generation', $generation)->count();
        if ($actualCount !== $elements || (isset($metadata['indexed_element_count']) && (int) $metadata['indexed_element_count'] !== $elements)) {
            throw new \RuntimeException('IFC element index count does not match its metadata.');
        }
        $stale = $scope->where(fn ($query) => $query->whereNull('properties->_ifc_index_generation')
            ->orWhere('properties->_ifc_index_generation', '!=', $generation));
        app(DesignRagMutationBridge::class)->changedRows(DesignIfcModelElement::class, clone $stale);
        $stale->delete();
        $derivative->forceFill(['metadata' => array_merge($derivative->metadata ?? [], [
            'ifc_units' => $metadata['units'] ?? [],
            'ifc_index_schema_version' => $metadata['index_schema_version'] ?? 1,
            'coordination_matrix' => $metadata['coordination_matrix'] ?? [],
            'coordinate_transformations' => $metadata['transformations'] ?? [],
            'indexed_element_count' => $elements,
        ])])->save();
    }

    private function elementProperties(array $element): array
    {
        $properties = is_array($element['properties'] ?? null) ? $element['properties'] : [];
        $properties['quantities'] = is_array($element['quantities'] ?? null) ? $element['quantities'] : [];
        $properties['quantity_units'] = is_array($element['quantity_units'] ?? null) ? $element['quantity_units'] : [];
        $properties['materials'] = is_array($element['materials'] ?? null) ? $element['materials'] : [];

        return $properties;
    }

    private function upsert(array $rows): void
    {
        DB::transaction(function () use ($rows): void {
            app(DesignRagMutationBridge::class)->ifcRows($rows);
            DB::table('design_ifc_model_elements')->upsert(
                $rows,
                ['version_id', 'express_id'],
                ['derivative_id', 'global_id', 'category', 'name', 'properties', 'classifications', 'updated_at'],
            );
            app(DesignRagMutationBridge::class)->ifcRows($rows);
        });
    }
}
