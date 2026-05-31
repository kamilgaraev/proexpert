<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Pdf;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf\PdfEstimateTableNormalizer;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf\PdfEstimateTableQualityAnalyzer;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf\PdfEstimateTextExtractor;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportPreviewResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportValidationResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\RuntimeImportFormatHandlerInterface;
use App\Models\ImportSession;
use Generator;
use Illuminate\Support\Facades\Cache;

final readonly class PdfEstimateHandler implements RuntimeImportFormatHandlerInterface
{
    private const MIN_TABLE_QUALITY_SCORE = 0.75;

    public function __construct(
        private PdfEstimateTextExtractor $extractor,
        private PdfEstimateTableNormalizer $normalizer,
        private PdfEstimateTableQualityAnalyzer $qualityAnalyzer,
    ) {}

    public function slug(): string
    {
        return 'pdf_estimate';
    }

    public function label(): string
    {
        return 'PDF-смета';
    }

    public function supportedExtensions(): array
    {
        return ['pdf'];
    }

    public function detect(ImportSession $session, string $filePath): ImportDetectionResult
    {
        $extraction = $this->extract($session, $filePath);
        $hasText = trim($extraction['text']) !== '';
        $method = $extraction['method'];

        return new ImportDetectionResult(
            detectedType: 'pdf_estimate',
            formatSlug: $this->slug(),
            label: $this->label(),
            confidence: $hasText ? $this->confidenceForMethod($method) : 0.25,
            requiresConfirmation: true,
            indicators: [$method],
            metadata: $this->metadata($extraction),
            warnings: $extraction['warnings'],
        );
    }

    public function detectStructure(ImportSession $session, string $filePath): ImportStructureResult
    {
        $table = $this->extractTable($session, $filePath);
        $extraction = $table['extraction'];
        $quality = $table['quality'];

        return new ImportStructureResult(
            formatSlug: $this->slug(),
            columnMapping: [
                'position_number' => 'number',
                'name' => 'name',
                'unit' => 'unit',
                'quantity' => 'quantity',
                'unit_price' => 'unit_price',
                'total_price' => 'total',
            ],
            sampleRows: array_slice(preg_split('/\R/u', $extraction['text']) ?: [], 0, 10),
            warnings: array_merge($extraction['warnings'], [trans_message('estimate.import_pdf_requires_staging')]),
            metadata: $this->metadata($extraction) + [
                'table_quality' => $quality,
            ],
        );
    }

    public function preview(ImportSession $session, string $filePath, ImportStructureResult $structure): ImportPreviewResult
    {
        $table = $this->extractTable($session, $filePath);
        $sections = [];
        $items = [];
        $totalAmount = 0.0;

        foreach ($table['rows'] as $row) {
            $payload = $row->toArray();

            if ($row->isSection) {
                $sections[] = $payload;
                continue;
            }

            $items[] = $payload;
            $totalAmount += (float) ($row->currentTotalAmount ?? 0);
        }

        $quality = [
            'requires_staging_confirmation' => true,
            'extraction_method' => $table['extraction']['method'],
            'ocr_provider' => $table['extraction']['metadata']['ocr_provider'] ?? null,
            'table_quality' => $table['quality'],
        ];
        $metadata = [
            'handler' => $this->slug(),
            'requires_staging_confirmation' => true,
            'extraction' => $table['extraction']['metadata'],
        ];

        $preview = new ImportPreviewResult(
            formatSlug: $this->slug(),
            sections: $sections,
            items: $items,
            totals: [
                'total_amount' => $totalAmount,
                'items_count' => count($items),
                'sections_count' => count($sections),
            ],
            summary: ['rows_count' => count($sections) + count($items)],
            quality: $quality,
            metadata: $metadata,
        );

        return new ImportPreviewResult(
            formatSlug: $preview->formatSlug,
            sections: $preview->sections,
            items: $preview->items,
            totals: $preview->totals,
            validation: $this->validate($session, $preview)->toArray(),
            summary: $preview->summary,
            quality: $preview->quality,
            metadata: $preview->metadata,
        );
    }

    public function validate(ImportSession $session, ImportPreviewResult $preview): ImportValidationResult
    {
        $errors = [];
        $quality = $preview->quality['table_quality']
            ?? $this->qualityAnalyzer->assessItems($preview->items, self::MIN_TABLE_QUALITY_SCORE);

        if ($preview->items === []) {
            $errors[] = trans_message('estimate.import_pdf_no_rows');
        } elseif (($quality['score'] ?? 0.0) < self::MIN_TABLE_QUALITY_SCORE) {
            $errors[] = trans_message('estimate.import_pdf_table_quality_failed');
        }

        return new ImportValidationResult(
            errors: $errors,
            warnings: [trans_message('estimate.import_pdf_requires_staging')],
            summary: [
                'items_count' => count($preview->items),
                'table_quality' => $quality,
            ],
        );
    }

    public function streamRows(ImportSession $session, string $filePath, ImportStructureResult $structure): Generator
    {
        $table = $this->extractTable($session, $filePath);

        foreach ($table['rows'] as $row) {
            yield $row;
        }
    }

    /**
     * @return array{
     *     extraction: array{text: string, method: string, warnings: array<int, string>, metadata: array<string, mixed>},
     *     rows: array<int, EstimateImportRowDTO>,
     *     quality: array<string, mixed>
     * }
     */
    private function extractTable(ImportSession $session, string $filePath): array
    {
        $cached = Cache::remember(
            'estimate-import:pdf-table:' . $this->cacheIdentity($session, $filePath),
            now()->addMinutes(30),
            fn (): array => $this->buildExtractedTable($session, $filePath)
        );

        if (!is_array($cached)) {
            return $this->buildExtractedTable($session, $filePath);
        }

        return $cached;
    }

    /**
     * @return array{
     *     extraction: array{text: string, method: string, warnings: array<int, string>, metadata: array<string, mixed>},
     *     rows: array<int, EstimateImportRowDTO>,
     *     quality: array<string, mixed>
     * }
     */
    private function buildExtractedTable(ImportSession $session, string $filePath): array
    {
        $table = $this->tableFromExtraction($this->extract($session, $filePath));

        if (
            $table['extraction']['method'] === 'pdf_text_layer'
            && ($table['quality']['score'] ?? 0.0) < self::MIN_TABLE_QUALITY_SCORE
        ) {
            $ocrTable = $this->tableFromExtraction($this->extractor->extractWithForcedOcr(
                $filePath,
                [trans_message('estimate.import_pdf_text_layer_low_quality')],
                'poor_quality'
            ));

            $table['extraction']['warnings'][] = trans_message('estimate.import_pdf_text_layer_low_quality');
            $table['extraction']['metadata']['ocr_table_quality'] = $ocrTable['quality'];

            if (($ocrTable['quality']['score'] ?? 0.0) > ($table['quality']['score'] ?? 0.0)) {
                $ocrTable['extraction']['warnings'] = array_values(array_unique(array_merge(
                    $table['extraction']['warnings'],
                    $ocrTable['extraction']['warnings']
                )));
                $ocrTable['extraction']['metadata']['text_layer_table_quality'] = $table['quality'];

                return $ocrTable;
            }
        }

        return $table;
    }

    /**
     * @param array{text?: string, method?: string, warnings?: array<int, string>, metadata?: array<string, mixed>} $extraction
     * @return array{
     *     extraction: array{text: string, method: string, warnings: array<int, string>, metadata: array<string, mixed>},
     *     rows: array<int, EstimateImportRowDTO>,
     *     quality: array<string, mixed>
     * }
     */
    private function tableFromExtraction(array $extraction): array
    {
        $extraction = $this->normalizeExtraction($extraction);
        $rows = $this->normalizer->normalize($extraction['text']);
        $quality = $this->qualityAnalyzer->assessRows($rows, self::MIN_TABLE_QUALITY_SCORE);
        $extraction['metadata']['table_quality'] = $quality;

        return [
            'extraction' => $extraction,
            'rows' => $rows,
            'quality' => $quality,
        ];
    }

    /**
     * @return array{text: string, method: string, warnings: array<int, string>, metadata: array<string, mixed>}
     */
    private function extract(ImportSession $session, string $filePath): array
    {
        $cached = Cache::remember(
            'estimate-import:pdf-extraction:' . $this->cacheIdentity($session, $filePath),
            now()->addMinutes(30),
            fn (): array => $this->extractor->extract($filePath)
        );

        return $this->normalizeExtraction(is_array($cached) ? $cached : []);
    }

    private function cacheIdentity(ImportSession $session, string $filePath): string
    {
        $fileStamp = is_file($filePath)
            ? ((string) filesize($filePath) . ':' . (string) filemtime($filePath))
            : 'missing';

        return sha1((string) ($session->getKey() ?? spl_object_id($session)) . '|' . $filePath . '|' . $fileStamp);
    }

    /**
     * @param array<string, mixed> $extraction
     * @return array{text: string, method: string, warnings: array<int, string>, metadata: array<string, mixed>}
     */
    private function normalizeExtraction(array $extraction): array
    {
        $warnings = array_filter((array) ($extraction['warnings'] ?? []), is_string(...));
        $metadata = $extraction['metadata'] ?? [];

        return [
            'text' => (string) ($extraction['text'] ?? ''),
            'method' => (string) ($extraction['method'] ?? 'unknown'),
            'warnings' => array_values($warnings),
            'metadata' => is_array($metadata) ? $metadata : [],
        ];
    }

    /**
     * @param array{text: string, method: string, warnings: array<int, string>, metadata: array<string, mixed>} $extraction
     * @return array<string, mixed>
     */
    private function metadata(array $extraction): array
    {
        return array_merge(
            ['extraction_method' => $extraction['method']],
            $extraction['metadata']
        );
    }

    private function confidenceForMethod(string $method): float
    {
        return str_starts_with($method, 'ocr_') ? 0.55 : 0.65;
    }
}
