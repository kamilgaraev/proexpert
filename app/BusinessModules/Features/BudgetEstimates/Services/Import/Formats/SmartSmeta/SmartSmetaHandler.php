<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\SmartSmeta;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel\CustomExcelHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\Models\ImportSession;

final class SmartSmetaHandler extends CustomExcelHandler
{
    public function slug(): string
    {
        return 'smartsmeta';
    }

    public function label(): string
    {
        return 'SmartSmeta / Smeta.ru';
    }

    public function detect(ImportSession $session, string $filePath): ImportDetectionResult
    {
        $text = $this->firstRowsText($filePath);
        $hasMarker = str_contains($text, 'smartsmeta')
            || str_contains($text, 'smeta.ru')
            || str_contains($text, 'смартсмета');

        if (!$hasMarker) {
            return new ImportDetectionResult('smartsmeta', $this->slug(), $this->label(), 0.0);
        }

        return new ImportDetectionResult(
            detectedType: 'smartsmeta',
            formatSlug: $this->slug(),
            label: $this->label(),
            confidence: 0.82,
            requiresConfirmation: true,
            indicators: ['smartsmeta_marker'],
        );
    }

    private function firstRowsText(string $filePath): string
    {
        $rows = $this->reader->readRows($filePath, 40);

        return mb_strtolower(implode(' ', array_map(
            static fn (array $row): string => implode(' ', array_map(static fn (mixed $value): string => (string) $value, $row)),
            $rows
        )));
    }
}
