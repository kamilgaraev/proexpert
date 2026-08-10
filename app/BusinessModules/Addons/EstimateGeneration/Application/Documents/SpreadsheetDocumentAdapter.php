<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Documents\Spreadsheet\SpreadsheetStructureExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;

final readonly class SpreadsheetDocumentAdapter implements DocumentUnitAdapter
{
    private const EXTENSIONS = ['xlsx', 'xls', 'ods', 'csv'];

    public function __construct(
        private DocumentSourceManifestStorage $storage,
        private SpreadsheetDocumentExtractor $extractor,
        private SpreadsheetStructureExtractor $structureExtractor = new SpreadsheetStructureExtractor,
    ) {}

    public function supports(EstimateGenerationDocument $document): bool
    {
        $mime = strtolower((string) $document->mime_type);

        return in_array($this->extension($document), self::EXTENSIONS, true)
            || str_contains($mime, 'spreadsheet')
            || str_contains($mime, 'excel')
            || $mime === 'text/csv';
    }

    public function createUnits(EstimateGenerationDocument $document, string $sourceVersion): array
    {
        $source = $this->storage->open($document, $sourceVersion);

        try {
            $recognition = $this->extractor->extractFile($document, $source->path());
            $units = [];

            foreach ($recognition->pages as $page) {
                $artifact = $this->storage->put(
                    $document,
                    $sourceVersion,
                    DocumentUnitType::SpreadsheetSheet,
                    $page->pageNumber,
                    json_encode($this->structureExtractor->extract($page), JSON_THROW_ON_ERROR),
                    'application/json',
                );
                $units[] = new DocumentUnitData(
                    DocumentUnitType::SpreadsheetSheet,
                    $page->pageNumber,
                    $sourceVersion,
                    [
                        ...$artifact->locator(),
                        'source_kind' => DocumentUnitType::SpreadsheetSheet->sourceKind(),
                        'source_version' => $sourceVersion,
                        'coordinate_space' => DocumentUnitType::SpreadsheetSheet->coordinateSpace(),
                        'artifact_source_version' => $artifact->sha256,
                        'artifact_kind' => 'spreadsheet_sheet',
                        'artifact_schema_version' => 1,
                        'sheet' => $page->pageNumber,
                    ],
                );
            }

            if ($units === []) {
                throw new DocumentManifestNeedsReview('spreadsheet_units_empty');
            }

            return DocumentUnitData::normalize($units);
        } finally {
            $source->close();
        }
    }

    public function representation(DocumentUnitData $unit): DocumentRepresentation
    {
        $provenance = $unit->provenance();

        return new DocumentRepresentation(
            DocumentSourceVersion::fromString($unit->sourceVersion),
            [
                'artifact_kind' => $unit->locator['artifact_kind'] ?? null,
                'artifact_schema_version' => $unit->locator['artifact_schema_version'] ?? null,
            ],
            $provenance->artifactPath,
            $provenance->coordinateSpace,
            ['cells' => 'available', 'formulas' => 'available', 'headings' => 'available'],
        );
    }

    private function extension(EstimateGenerationDocument $document): string
    {
        return strtolower((string) pathinfo((string) $document->filename, PATHINFO_EXTENSION));
    }
}
