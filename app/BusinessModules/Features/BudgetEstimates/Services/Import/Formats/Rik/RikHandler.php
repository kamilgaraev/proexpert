<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Rik;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel\CustomExcelHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\Models\ImportSession;

final class RikHandler extends CustomExcelHandler
{
    public function slug(): string
    {
        return 'rik';
    }

    public function label(): string
    {
        return 'РИК';
    }

    public function detect(ImportSession $session, string $filePath): ImportDetectionResult
    {
        $text = $this->firstRowsText($filePath, 30);
        $hasMarker = str_contains($text, 'рик')
            || str_contains($text, 'ресурсно-индекс')
            || str_contains($text, 'winрик');

        if (!$hasMarker) {
            return new ImportDetectionResult('rik', $this->slug(), $this->label(), 0.0);
        }

        return new ImportDetectionResult(
            detectedType: 'rik',
            formatSlug: $this->slug(),
            label: $this->label(),
            confidence: 0.93,
            requiresConfirmation: true,
            indicators: ['rik_marker'],
        );
    }
}
