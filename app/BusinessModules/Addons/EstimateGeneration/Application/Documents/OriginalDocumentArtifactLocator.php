<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

final class OriginalDocumentArtifactLocator
{
    /**
     * @param array<string, scalar|null> $locator
     * @return array<string, scalar|null>
     */
    public static function forUnit(
        EstimateGenerationDocument $document,
        DocumentUnitType $type,
        int $index,
        string $sourceVersion,
        array $locator,
    ): array {
        $meta = is_array($document->meta) ? $document->meta : [];
        $path = trim((string) $document->storage_path);
        $bytes = (int) $document->file_size_bytes;
        $versionId = $meta['storage_version_id'] ?? null;

        if ($path === '' || $bytes < 1 || ! is_string($versionId) || trim($versionId) === '') {
            throw new DocumentManifestNeedsReview('document_source_provenance_required');
        }

        return [
            ...$locator,
            'source_kind' => $type->sourceKind(),
            'source_version' => $sourceVersion,
            'coordinate_space' => $type->coordinateSpace(),
            'artifact_path' => $path,
            'artifact_bytes' => $bytes,
            'artifact_sha256' => $sourceVersion,
            'artifact_version_id' => $versionId,
            'artifact_source_version' => $sourceVersion,
            'content_type' => strtolower(trim((string) $document->mime_type)),
        ];
    }
}
