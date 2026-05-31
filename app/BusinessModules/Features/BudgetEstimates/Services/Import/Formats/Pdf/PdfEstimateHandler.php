<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Pdf;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf\PdfEstimateTableNormalizer;
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
    public function __construct(
        private PdfEstimateTextExtractor $extractor,
        private PdfEstimateTableNormalizer $normalizer,
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
        $extraction = $this->extract($session, $filePath);

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
            metadata: $this->metadata($extraction),
        );
    }

    public function preview(ImportSession $session, string $filePath, ImportStructureResult $structure): ImportPreviewResult
    {
        $sections = [];
        $items = [];
        $totalAmount = 0.0;

        foreach ($this->streamRows($session, $filePath, $structure) as $row) {
            $payload = $row->toArray();

            if ($row->isSection) {
                $sections[] = $payload;
                continue;
            }

            $items[] = $payload;
            $totalAmount += (float) ($row->currentTotalAmount ?? 0);
        }

        return new ImportPreviewResult(
            formatSlug: $this->slug(),
            sections: $sections,
            items: $items,
            totals: [
                'total_amount' => $totalAmount,
                'items_count' => count($items),
                'sections_count' => count($sections),
            ],
            validation: $this->validate($session, new ImportPreviewResult($this->slug(), $sections, $items))->toArray(),
            summary: ['rows_count' => count($sections) + count($items)],
            quality: [
                'requires_staging_confirmation' => true,
                'extraction_method' => $structure->metadata['extraction_method'] ?? null,
                'ocr_provider' => $structure->metadata['ocr_provider'] ?? null,
            ],
            metadata: [
                'handler' => $this->slug(),
                'requires_staging_confirmation' => true,
                'extraction' => $structure->metadata,
            ],
        );
    }

    public function validate(ImportSession $session, ImportPreviewResult $preview): ImportValidationResult
    {
        return new ImportValidationResult(
            errors: $preview->items === [] ? [trans_message('estimate.import_pdf_no_rows')] : [],
            warnings: [trans_message('estimate.import_pdf_requires_staging')],
            summary: ['items_count' => count($preview->items)],
        );
    }

    public function streamRows(ImportSession $session, string $filePath, ImportStructureResult $structure): Generator
    {
        $extraction = $this->extract($session, $filePath);

        foreach ($this->normalizer->normalize($extraction['text']) as $row) {
            yield $row;
        }
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
