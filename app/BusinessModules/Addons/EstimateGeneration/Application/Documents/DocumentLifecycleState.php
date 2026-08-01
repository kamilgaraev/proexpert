<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

final class DocumentLifecycleState
{
    /** @return array{stage: string, translation_key: string} */
    public static function forDocument(EstimateGenerationDocument $document): array
    {
        $stage = match (true) {
            in_array((string) $document->status, ['uploaded', 'queued'], true),
            in_array((string) $document->processing_stage, ['stored', 'preflight'], true) => 'preparing_file',
            (string) $document->status === 'processing',
            in_array((string) $document->processing_stage, [
                'pdf_text_layer', 'ocr_request', 'ocr_polling', 'spreadsheet_extraction',
            ], true) => 'reading_structure',
            in_array((string) $document->processing_stage, ['normalization', 'fact_extraction'], true) => 'understanding_content',
            default => 'checking_relationships',
        };

        return [
            'stage' => $stage,
            'translation_key' => 'estimate_generation.document_lifecycle_'.$stage,
        ];
    }
}
