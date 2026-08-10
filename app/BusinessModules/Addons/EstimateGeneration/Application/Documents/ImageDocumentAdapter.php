<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

final class ImageDocumentAdapter implements DocumentUnitAdapter
{
    private const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'tif', 'tiff'];

    public function supports(EstimateGenerationDocument $document): bool
    {
        return str_starts_with(strtolower((string) $document->mime_type), 'image/')
            || in_array($this->extension($document), self::EXTENSIONS, true);
    }

    public function createUnits(EstimateGenerationDocument $document, string $sourceVersion): array
    {
        $meta = is_array($document->meta) ? $document->meta : [];
        $type = ($meta['is_sketch'] ?? false) === true ? DocumentUnitType::Sketch : DocumentUnitType::RasterImage;
        $count = min(max(1, (int) ($meta['frame_count'] ?? 1)), DocumentUnitData::MAX_INDEX);
        $units = [];

        for ($index = 1; $index <= $count; $index++) {
            $units[] = new DocumentUnitData(
                $type,
                $index,
                $sourceVersion,
                OriginalDocumentArtifactLocator::forUnit($document, $type, $index, $sourceVersion, ['frame' => $index]),
            );
        }

        return $units;
    }

    public function representation(DocumentUnitData $unit): DocumentRepresentation
    {
        $provenance = $unit->provenance();

        return new DocumentRepresentation(
            DocumentSourceVersion::fromString($unit->sourceVersion),
            [],
            $provenance->artifactPath,
            $provenance->coordinateSpace,
            ['raster' => 'available', 'ocr' => 'available'],
        );
    }

    private function extension(EstimateGenerationDocument $document): string
    {
        return strtolower((string) pathinfo((string) $document->filename, PATHINFO_EXTENSION));
    }
}
