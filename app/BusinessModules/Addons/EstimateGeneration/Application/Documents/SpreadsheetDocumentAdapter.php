<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Documents\Spreadsheet\SpreadsheetDocumentAdapter as NativeSpreadsheetDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;

final readonly class SpreadsheetDocumentAdapter implements DocumentUnitAdapter
{
    private const EXTENSIONS = ['xlsx', 'xls', 'ods', 'csv'];

    public function __construct(
        private DocumentSourceManifestStorage $storage,
        private SpreadsheetDocumentExtractor $extractor,
        private NativeSpreadsheetDocumentAdapter $nativeAdapter = new NativeSpreadsheetDocumentAdapter,
    ) {}

    public function supports(EstimateGenerationDocument $document): bool
    {
        $mime = strtolower((string) $document->mime_type);

        return in_array($this->extension($document), self::EXTENSIONS, true)
            || str_contains($mime, 'spreadsheet')
            || str_contains($mime, 'excel')
            || $mime === 'text/csv';
    }

    public function detect(EstimateGenerationDocument $document, string $sourceVersion): array
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
                    json_encode($this->nativeAdapter->extract($page), JSON_THROW_ON_ERROR),
                    'application/vnd.most.spreadsheet-sheet+json',
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

    private function extension(EstimateGenerationDocument $document): string
    {
        return strtolower((string) pathinfo((string) $document->filename, PATHINFO_EXTENSION));
    }
}
