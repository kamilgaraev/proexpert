<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf;

use Throwable;

final readonly class PdfEstimateTextExtractor
{
    public function __construct(
        private PdfEstimateOcrExtractor $ocrExtractor,
    ) {}

    /**
     * @return array{text: string, method: string, warnings: array<int, string>, metadata: array<string, mixed>}
     */
    public function extract(string $filePath): array
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            return $this->extractWithForcedOcr(
                $filePath,
                [trans_message('estimate.import_pdf_parser_unavailable')],
                'parser_unavailable'
            );
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $document = $parser->parseFile($filePath);
            $text = trim($document->getText());

            if ($text !== '') {
                return [
                    'text' => $text,
                    'method' => 'pdf_text_layer',
                    'warnings' => [],
                    'metadata' => ['text_layer_status' => 'used'],
                ];
            }

            return $this->extractWithForcedOcr(
                $filePath,
                [trans_message('estimate.import_pdf_text_layer_empty')],
                'empty'
            );
        } catch (Throwable) {
            return $this->extractWithForcedOcr(
                $filePath,
                [trans_message('estimate.import_pdf_text_extract_failed')],
                'failed'
            );
        }
    }

    /**
     * @param array<int, string> $textLayerWarnings
     * @return array{text: string, method: string, warnings: array<int, string>, metadata: array<string, mixed>}
     */
    public function extractWithForcedOcr(string $filePath, array $textLayerWarnings, string $textLayerStatus): array
    {
        $ocrExtraction = $this->ocrExtractor->extract($filePath);
        $ocrExtraction['metadata'] = array_merge(
            $ocrExtraction['metadata'],
            ['text_layer_status' => $textLayerStatus]
        );

        if (trim($ocrExtraction['text']) !== '') {
            return $ocrExtraction;
        }

        return [
            'text' => '',
            'method' => $ocrExtraction['method'],
            'warnings' => array_values(array_unique(array_merge($textLayerWarnings, $ocrExtraction['warnings']))),
            'metadata' => $ocrExtraction['metadata'],
        ];
    }
}
