<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use DomainException;

final class DocumentLifecycleState
{
    /** @return array{stage: string, translation_key: string} */
    public static function forDocument(EstimateGenerationDocument $document): array
    {
        $stage = match ((string) $document->processing_stage) {
            'stored', 'preflight' => 'preparing_file',
            'pdf_text_layer', 'ocr_request', 'ocr_polling', 'spreadsheet_extraction' => 'reading_structure',
            'normalization', 'fact_extraction' => 'understanding_content',
            'quality_check', 'completed' => 'checking_relationships',
            default => throw new DomainException('estimate_generation_document_processing_stage_unknown'),
        };

        return [
            'stage' => $stage,
            'translation_key' => 'estimate_generation.document_lifecycle_'.$stage,
        ];
    }
}
