<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final class DocumentRepresentationBuilder
{
    public function build(
        string $format,
        DocumentUnitData $unit,
        array $nativeStructure,
        array $capabilities,
    ): DocumentRepresentation {
        $provenance = $unit->provenance();
        $visualArtifactPath = $unit->locator['visual_artifact_path'] ?? $provenance->artifactPath;
        $bounds = $unit->locator['source_bounds'] ?? [
            0,
            0,
            max(1, (int) ($unit->locator['width'] ?? 1)),
            max(1, (int) ($unit->locator['height'] ?? 1)),
        ];
        $usage = [
            'pages' => max(1, (int) ($unit->locator['page_count'] ?? 1)),
            'objects' => max(0, (int) ($unit->locator['object_count'] ?? 0)),
            'bytes' => max(0, (int) ($unit->locator['representation_bytes'] ?? $provenance->artifactBytes)),
            'peak_memory_bytes' => max(0, (int) ($unit->locator['peak_memory_bytes'] ?? 0)),
            'duration_ms' => max(0, (int) ($unit->locator['duration_ms'] ?? 0)),
        ];

        return new DocumentRepresentation(
            DocumentSourceVersion::fromString($unit->sourceVersion),
            $nativeStructure,
            is_string($visualArtifactPath) ? $visualArtifactPath : '',
            $provenance->coordinateSpace,
            DocumentRepresentationCapabilities::fromArray($format, $capabilities),
            DocumentCoordinateTransform::fromBounds($bounds),
            $usage,
        );
    }
}
