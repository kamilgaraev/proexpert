<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Documents\Cad;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;

final class CadDocumentAdapter
{
    /**
     * @return array{native_structure: array{status: string, capabilities: array<string, string>, source_fingerprint: string, source_unit: ?string, unit_status: string, layers: array<int, array<string, mixed>>, blocks: array<int, array<string, mixed>>, polylines: array<int, array<string, mixed>>, texts: array<int, array<string, mixed>>, dimensions: array<int, array<string, mixed>>}}
     */
    public function extract(VectorGeometryData $geometry): array
    {
        $capabilities = $this->capabilities($geometry);

        return [
            'native_structure' => [
                'status' => in_array('unavailable', $capabilities, true) ? 'partial' : 'available',
                'capabilities' => $capabilities,
                'source_fingerprint' => $geometry->sourceFingerprint,
                'source_unit' => $geometry->sourceUnit,
                'unit_status' => $geometry->unitStatus,
                'layers' => $geometry->layers,
                'blocks' => $geometry->blocks,
                'polylines' => array_values(array_filter(
                    $geometry->entities,
                    static fn (array $entity): bool => in_array($entity['type'] ?? null, ['lwpolyline', 'polyline'], true),
                )),
                'texts' => $geometry->texts,
                'dimensions' => $geometry->dimensions,
            ],
        ];
    }

    /** @return array<string, string> */
    private function capabilities(VectorGeometryData $geometry): array
    {
        $isDwg = str_contains($geometry->runtimeVersion, ';libredwg:');

        return [
            'layers' => 'available',
            'blocks' => $isDwg ? 'unavailable' : 'available',
            'polylines' => 'available',
            'texts' => 'available',
            'dimensions' => $isDwg ? 'unavailable' : 'available',
        ];
    }
}
