<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

final class CadDocumentAdapter implements DocumentUnitAdapter
{
    private const EXTENSIONS = ['dwg', 'dxf', 'ifc'];

    public function supports(EstimateGenerationDocument $document): bool
    {
        $mime = strtolower((string) $document->mime_type);

        return in_array($this->extension($document), self::EXTENSIONS, true)
            || str_contains($mime, 'dwg')
            || str_contains($mime, 'dxf')
            || str_contains($mime, 'ifc');
    }

    public function detect(EstimateGenerationDocument $document, string $sourceVersion): array
    {
        $type = DocumentUnitType::CadDrawing;

        return [new DocumentUnitData(
            $type,
            1,
            $sourceVersion,
            OriginalDocumentArtifactLocator::forUnit($document, $type, 1, $sourceVersion, ['drawing' => 1]),
        )];
    }

    private function extension(EstimateGenerationDocument $document): string
    {
        return strtolower((string) pathinfo((string) $document->filename, PATHINFO_EXTENSION));
    }
}
