<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Fer;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel\CustomExcelHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\Models\ImportSession;

final class FerHandler extends CustomExcelHandler
{
    public function slug(): string
    {
        return 'fer';
    }

    public function label(): string
    {
        return 'ФЕР/ГЭСН';
    }

    public function detect(ImportSession $session, string $filePath): ImportDetectionResult
    {
        $text = $this->firstRowsText($filePath, 80);
        $hasMarker = str_contains($text, 'фер')
            || str_contains($text, 'гэсн')
            || str_contains($text, 'фссц')
            || str_contains($text, 'фсбц');

        if (!$hasMarker) {
            return new ImportDetectionResult('fer', $this->slug(), $this->label(), 0.0);
        }

        return new ImportDetectionResult(
            detectedType: 'fer',
            formatSlug: $this->slug(),
            label: $this->label(),
            confidence: 0.9,
            requiresConfirmation: true,
            indicators: ['fer_normative_marker'],
        );
    }
}
