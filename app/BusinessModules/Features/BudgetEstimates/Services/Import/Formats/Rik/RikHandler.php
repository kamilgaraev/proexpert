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
        $text = $this->firstRowsText($filePath);
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
            confidence: 0.82,
            requiresConfirmation: true,
            indicators: ['rik_marker'],
        );
    }

    private function firstRowsText(string $filePath): string
    {
        $rows = $this->reader->readRows($filePath, 30);

        return mb_strtolower(implode(' ', array_map(
            static fn (array $row): string => implode(' ', array_map(static fn (mixed $value): string => (string) $value, $row)),
            $rows
        )));
    }
}
