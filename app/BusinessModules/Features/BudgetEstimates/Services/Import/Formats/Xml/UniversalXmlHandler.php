<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Xml;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\UniversalXmlParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportPreviewResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportValidationResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\RuntimeImportFormatHandlerInterface;
use App\Models\ImportSession;
use Generator;

final readonly class UniversalXmlHandler implements RuntimeImportFormatHandlerInterface
{
    public function __construct(private UniversalXmlParser $parser) {}

    public function slug(): string
    {
        return 'universal_xml';
    }

    public function label(): string
    {
        return 'XML-смета';
    }

    public function supportedExtensions(): array
    {
        return ['xml'];
    }

    public function detect(ImportSession $session, string $filePath): ImportDetectionResult
    {
        $content = file_get_contents($filePath) ?: '';
        if (str_contains($content, 'Generator="GrandSmeta"') || str_contains($content, 'GrandSmetaSign=')) {
            return new ImportDetectionResult('unknown', $this->slug(), $this->label(), 0.0);
        }

        $hasEstimateMarkers = preg_match('/<(estimate|item|position|section|work|resource)\b/i', $content) === 1;

        return new ImportDetectionResult(
            detectedType: 'xml_estimate',
            formatSlug: $this->slug(),
            label: $this->label(),
            confidence: $hasEstimateMarkers ? 0.7 : 0.2,
            requiresConfirmation: true,
            indicators: $hasEstimateMarkers ? ['xml_estimate_markers'] : [],
        );
    }

    public function detectStructure(ImportSession $session, string $filePath): ImportStructureResult
    {
        $structure = $this->parser->detectStructure($filePath);

        return new ImportStructureResult(
            formatSlug: $this->slug(),
            headerRow: $structure['header_row'] ?? null,
            columnMapping: $structure['column_mapping'] ?? [],
            detectedColumns: $structure['detected_columns'] ?? [],
            rawHeaders: $structure['raw_headers'] ?? [],
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
            $totalAmount += (float) ($row->currentTotalAmount ?? (($row->quantity ?? 0) * ($row->unitPrice ?? 0)));
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
            metadata: ['handler' => $this->slug()],
        );
    }

    public function validate(ImportSession $session, ImportPreviewResult $preview): ImportValidationResult
    {
        return new ImportValidationResult(
            errors: $preview->items === [] ? [trans_message('estimate.import_empty_preview')] : [],
            summary: [
                'items_count' => count($preview->items),
                'sections_count' => count($preview->sections),
            ],
        );
    }

    public function streamRows(ImportSession $session, string $filePath, ImportStructureResult $structure): Generator
    {
        foreach ($this->parser->getStream($filePath, $structure->toArray()) as $row) {
            yield $row instanceof EstimateImportRowDTO ? $row : EstimateImportRowDTO::fromArray((array) $row);
        }
    }
}
