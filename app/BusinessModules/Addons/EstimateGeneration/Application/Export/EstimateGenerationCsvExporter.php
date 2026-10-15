<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Export;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationFinalWorkItemGuard;
use App\Services\Export\CsvExportValue;
use RuntimeException;

final class EstimateGenerationCsvExporter
{
    public function __construct(private readonly EstimateGenerationFinalWorkItemGuard $finalWorkItemGuard) {}

    public function stream(array $draft): void
    {
        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            throw new RuntimeException('estimate_generation_csv_stream_unavailable');
        }

        try {
            fputcsv($handle, ['Локальная смета', 'Раздел', 'Работа', 'Ед.', 'Кол-во', 'Итого', 'Основание'], escape: '');
            foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
                foreach (is_array($localEstimate) ? ($localEstimate['sections'] ?? []) : [] as $section) {
                    foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $workItem) {
                        if (! is_array($workItem) || ! $this->finalWorkItemGuard->isFinalEstimateWorkItem($workItem)) {
                            continue;
                        }
                        $row = CsvExportValue::row([
                            $localEstimate['title'] ?? '', $section['title'] ?? '',
                            $workItem['name'] ?? '', $workItem['unit'] ?? '',
                            $workItem['quantity'] ?? '', $workItem['total_cost'] ?? '',
                            $workItem['quantity_basis'] ?? '',
                        ]);
                        if (fputcsv($handle, $row, escape: '') === false) {
                            throw new RuntimeException('estimate_generation_csv_write_failed');
                        }
                    }
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
