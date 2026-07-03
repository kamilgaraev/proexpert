<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Most;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel\CustomExcelHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\Models\ImportSession;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class MostTemplateHandler extends CustomExcelHandler
{
    private const PREVIOUS_TEMPLATE_MARKER_PREFIX = 'PRO' . 'HELPER_TEMPLATE';

    public function slug(): string
    {
        return 'most_template';
    }

    public function label(): string
    {
        return 'Шаблон МОСТ';
    }

    public function detect(ImportSession $session, string $filePath): ImportDetectionResult
    {
        $spreadsheet = IOFactory::load($filePath);
        $description = (string) $spreadsheet->getProperties()->getDescription();
        $spreadsheet->disconnectWorksheets();

        if (str_contains($description, 'MOST_TEMPLATE') || str_contains($description, self::PREVIOUS_TEMPLATE_MARKER_PREFIX)) {
            return new ImportDetectionResult(
                detectedType: 'most_template',
                formatSlug: $this->slug(),
                label: $this->label(),
                confidence: 1.0,
                indicators: ['document_property_most_template'],
            );
        }

        $result = parent::detect($session, $filePath);

        return new ImportDetectionResult(
            detectedType: 'most_template',
            formatSlug: $this->slug(),
            label: $this->label(),
            confidence: min(0.4, $result->confidence),
            requiresConfirmation: true,
            indicators: $result->indicators,
            warnings: [trans_message('estimate.import_low_confidence')],
        );
    }
}
