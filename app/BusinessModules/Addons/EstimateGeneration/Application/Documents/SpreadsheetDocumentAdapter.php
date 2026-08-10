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
                $structure = $this->structureExtractor->extract($page);
                $artifact = $this->storage->put(
                    $document,
                    $sourceVersion,
                    DocumentUnitType::SpreadsheetSheet,
                    $page->pageNumber,
                    json_encode($structure, JSON_THROW_ON_ERROR),
                    'application/json',
                );
                $visual = $this->storage->put(
                    $document,
                    $sourceVersion,
                    DocumentUnitType::Sketch,
                    $page->pageNumber,
                    $this->tableRender($structure),
                    'image/svg+xml',
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
                        'native_structure_artifact_path' => $artifact->path,
                        'visual_artifact_path' => $visual->path,
                        'source_bounds' => [
                            0,
                            0,
                            max(1, (int) ($structure['native_structure']['columns'] ?? 1)),
                            max(1, (int) ($structure['native_structure']['rows'] ?? 1)),
                        ],
                        'object_count' => count($structure['native_structure']['cells'] ?? []),
                        'representation_bytes' => $artifact->bytes + $visual->bytes,
                        'representation_limitations' => is_array($structure['native_structure']['limitations'] ?? null)
                            ? $structure['native_structure']['limitations']
                            : [],
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
        $nativeAvailable = isset($unit->locator['native_structure_artifact_path']);
        $limitations = is_array($unit->locator['representation_limitations'] ?? null)
            ? $unit->locator['representation_limitations']
            : [];

        return (new DocumentRepresentationBuilder)->build(
            'xlsx',
            $unit,
            [
                'artifact_kind' => $unit->locator['artifact_kind'] ?? null,
                'artifact_schema_version' => $unit->locator['artifact_schema_version'] ?? null,
                'native_structure_artifact_path' => $unit->locator['native_structure_artifact_path'] ?? null,
            ],
            [
                'sheets' => $this->status($nativeAvailable, $limitations, ['xlsx_sheets_truncated'], 'xlsx_sheets_missing'),
                'cells' => $this->status($nativeAvailable, $limitations, [
                    'xlsx_rows_truncated', 'xlsx_columns_truncated', 'xlsx_cells_truncated',
                ], 'xlsx_cells_missing'),
                'formulas' => $this->status($nativeAvailable, $limitations, [
                    'xlsx_rows_truncated', 'xlsx_columns_truncated', 'xlsx_cells_truncated',
                ], 'xlsx_formulas_missing'),
                'merges' => $this->status($nativeAvailable, $limitations, [
                    'xlsx_rows_truncated', 'xlsx_columns_truncated', 'xlsx_cells_truncated', 'xlsx_merges_truncated',
                ], 'xlsx_merges_missing'),
                'table_render' => $this->status(
                    isset($unit->locator['visual_artifact_path']),
                    $limitations,
                    ['xlsx_render_truncated'],
                    'xlsx_table_render_missing',
                ),
                'source_coordinates' => isset($unit->locator['source_bounds'])
                    ? 'available'
                    : 'unavailable:xlsx_source_bounds_missing',
            ],
        );
    }

    /** @param list<mixed> $limitations @param list<string> $blocking */
    private function status(bool $available, array $limitations, array $blocking, string $missing): string
    {
        if (! $available) {
            return 'unavailable:'.$missing;
        }
        foreach ($blocking as $reason) {
            if (in_array($reason, $limitations, true)) {
                return 'unavailable:'.$reason;
            }
        }

        return 'available';
    }

    private function tableRender(array $structure): string
    {
        $cells = array_slice($structure['native_structure']['cells'] ?? [], 0, 400);
        $labels = [];
        foreach ($cells as $cell) {
            if (! is_array($cell)) {
                continue;
            }
            $address = htmlspecialchars((string) ($cell['address'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $value = htmlspecialchars((string) ($cell['value'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $labels[] = '<text x="10" y="'.(20 + (count($labels) * 18)).'">'.$address.' '.$value.'</text>';
        }
        $height = max(40, 30 + (count($labels) * 18));

        return '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="'.$height.'"><rect width="100%" height="100%" fill="white"/><g font-family="sans-serif" font-size="14">'.implode('', $labels).'</g></svg>';
    }

    private function extension(EstimateGenerationDocument $document): string
    {
        return strtolower((string) pathinfo((string) $document->filename, PATHINFO_EXTENSION));
    }
}
