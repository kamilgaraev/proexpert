<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

final class CadDocumentAdapter implements DocumentUnitAdapter
{
    private const EXTENSIONS = ['dwg', 'dxf'];

    public function supports(EstimateGenerationDocument $document): bool
    {
        $mime = strtolower((string) $document->mime_type);

        return in_array($this->extension($document), self::EXTENSIONS, true)
            || str_contains($mime, 'dwg')
            || str_contains($mime, 'dxf');
    }

    public function createUnits(EstimateGenerationDocument $document, string $sourceVersion): array
    {
        $type = DocumentUnitType::CadDrawing;

        return [new DocumentUnitData(
            $type,
            1,
            $sourceVersion,
            OriginalDocumentArtifactLocator::forUnit($document, $type, 1, $sourceVersion, ['drawing' => 1]),
        )];
    }

    public function representation(DocumentUnitData $unit): DocumentRepresentation
    {
        $native = is_array($unit->locator['native_capabilities'] ?? null)
            ? $unit->locator['native_capabilities']
            : [];
        $status = static fn (string $capability): string => ($native[$capability] ?? null) === 'available'
            ? 'available'
            : 'unavailable:cad_'.$capability.'_missing';

        return (new DocumentRepresentationBuilder)->build(
            'cad',
            $unit,
            ['native_structure_artifact_path' => $unit->locator['native_structure_artifact_path'] ?? null],
            [
                'layers' => $status('layers'),
                'blocks' => $status('blocks'),
                'polylines' => $status('polylines'),
                'dimensions' => $status('dimensions'),
                'texts' => $status('texts'),
                'sheet_render' => isset($unit->locator['visual_artifact_path'])
                    ? 'available'
                    : 'unavailable:cad_sheet_render_missing',
                'source_coordinates' => isset($unit->locator['source_bounds'])
                    ? 'available'
                    : 'unavailable:cad_source_bounds_missing',
            ],
        );
    }

    private function extension(EstimateGenerationDocument $document): string
    {
        return strtolower((string) pathinfo((string) $document->filename, PATHINFO_EXTENSION));
    }
}
