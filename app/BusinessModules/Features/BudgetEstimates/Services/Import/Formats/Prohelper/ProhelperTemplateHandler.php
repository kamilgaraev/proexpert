<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Prohelper;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel\CustomExcelHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\Models\ImportSession;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class ProhelperTemplateHandler extends CustomExcelHandler
{
    public function slug(): string
    {
        return 'prohelper_template';
    }

    public function label(): string
    {
        return 'Шаблон ProHelper';
    }

    public function detect(ImportSession $session, string $filePath): ImportDetectionResult
    {
        $spreadsheet = IOFactory::load($filePath);
        $description = (string) $spreadsheet->getProperties()->getDescription();
        $spreadsheet->disconnectWorksheets();

        if (str_contains($description, 'PROHELPER_TEMPLATE')) {
            return new ImportDetectionResult(
                detectedType: 'prohelper_template',
                formatSlug: $this->slug(),
                label: $this->label(),
                confidence: 1.0,
                indicators: ['document_property_prohelper_template'],
            );
        }

        $result = parent::detect($session, $filePath);

        return new ImportDetectionResult(
            detectedType: 'prohelper_template',
            formatSlug: $this->slug(),
            label: $this->label(),
            confidence: min(0.4, $result->confidence),
            requiresConfirmation: true,
            indicators: $result->indicators,
            warnings: [trans_message('estimate.import_low_confidence')],
        );
    }
}
