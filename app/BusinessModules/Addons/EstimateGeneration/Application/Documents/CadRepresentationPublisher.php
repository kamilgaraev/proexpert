<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Documents\Cad\CadStructureExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\Services\Storage\FileService;
use App\Services\Storage\OrganizationStoragePath;

final readonly class CadRepresentationPublisher
{
    public function __construct(
        private FileService $files,
        private CadStructureExtractor $structureExtractor = new CadStructureExtractor,
    ) {}

    public function publish(VectorGeometryData $geometry, DocumentUnitExecutionContext $context): DocumentRepresentation
    {
        $extracted = $this->structureExtractor->extract($geometry);
        $native = $extracted['native_structure'];
        $references = $this->nativeReferences($native);
        $native['native_reference_registry'] = $references;
        $nativePayload = json_encode([
            'schema_version' => 1,
            'source_kind' => 'cad',
            'native_structure' => $native,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nativeArtifact = $this->put($context, $nativePayload, 'application/json', 'json');
        $visual = $this->svg($geometry);
        $visualArtifact = $this->put($context, $visual, 'image/svg+xml', 'svg');
        $capabilities = is_array($native['capabilities'] ?? null) ? $native['capabilities'] : [];
        $unit = new DocumentUnitData(
            DocumentUnitType::CadDrawing,
            $context->index,
            $context->sourceVersion,
            [
                ...$context->locator,
                'native_structure_artifact_path' => $nativeArtifact['path'],
                'native_structure_artifact_sha256' => 'sha256:'.$nativeArtifact['sha256'],
                'native_reference_registry' => $references,
                'visual_artifact_path' => $visualArtifact['path'],
                'source_bounds' => $geometry->bounds,
                'native_capabilities' => $capabilities,
                'object_count' => count($references),
                'representation_bytes' => $nativeArtifact['size'] + $visualArtifact['size'],
            ],
        );

        return (new CadDocumentAdapter)->representation($unit);
    }

    /** @return array{path: string, size: int, sha256: string} */
    private function put(DocumentUnitExecutionContext $context, string $body, string $contentType, string $extension): array
    {
        $hash = hash('sha256', $body);
        $path = OrganizationStoragePath::forActor(
            $context->organizationId,
            'estimate-generation',
            $context->sessionId.'/document-representations/cad',
            null,
            $hash,
            $extension,
        );
        $stored = $this->files->putImmutable($path, $body, $contentType);
        if (($stored['size'] ?? null) !== strlen($body)
            || ! is_string($stored['sha256'] ?? null)
            || ! hash_equals($hash, $stored['sha256'])) {
            throw new DocumentUnitProcessingException('cad_representation_artifact_integrity_failed');
        }

        return ['path' => $path, 'size' => strlen($body), 'sha256' => $hash];
    }

    /** @param array<string, mixed> $native @return list<string> */
    private function nativeReferences(array $native): array
    {
        $references = [];
        foreach (['objects', 'texts', 'dimensions', 'blocks'] as $collection) {
            foreach (is_array($native[$collection] ?? null) ? $native[$collection] : [] as $object) {
                $handle = is_array($object) ? ($object['handle'] ?? $object['name'] ?? null) : null;
                if (is_string($handle) && $handle !== '' && strlen($handle) <= 180) {
                    $references[] = 'cad:object:'.$handle;
                }
            }
        }

        return array_values(array_unique($references));
    }

    private function svg(VectorGeometryData $geometry): string
    {
        [$minX, $minY, $maxX, $maxY] = $geometry->bounds;
        $width = max(0.000001, $maxX - $minX);
        $height = max(0.000001, $maxY - $minY);
        $paths = [];
        foreach (array_slice($geometry->entities, 0, 20_000) as $entity) {
            $points = is_array($entity['points'] ?? null) ? $entity['points'] : [];
            $normalized = [];
            foreach (array_slice($points, 0, 5_000) as $point) {
                if (! is_array($point) || ! is_numeric($point[0] ?? null) || ! is_numeric($point[1] ?? null)) {
                    continue;
                }
                $x = (($point[0] - $minX) / $width) * 1200;
                $y = 1200 - ((($point[1] - $minY) / $height) * 1200);
                $normalized[] = round($x, 3).','.round($y, 3);
            }
            if (count($normalized) >= 2) {
                $paths[] = '<polyline points="'.implode(' ', $normalized).'"/>';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="1200" viewBox="0 0 1200 1200"><rect width="1200" height="1200" fill="white"/><g fill="none" stroke="black" stroke-width="1">'.implode('', $paths).'</g></svg>';
    }
}
