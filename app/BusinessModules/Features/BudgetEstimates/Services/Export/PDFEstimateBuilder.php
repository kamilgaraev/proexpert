<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Export;

use App\Models\Estimate;
use Barryvdh\DomPDF\Facade\Pdf;

class PDFEstimateBuilder
{
    /**
     * Построить PDF файл сметы
     *
     * @param Estimate $estimate
     * @param array $data
     * @param array $options
     * @return string Path to generated file
     */
    public function build(Estimate $estimate, array $data, array $options): string
    {
        // Prepare view data
        $viewData = [
            'estimate' => $data['estimate'],
            'sections' => $data['sections'],
            'totals' => $data['totals'],
            'options' => $options,
            'metadata' => $data['metadata'],
        ];

        // Generate PDF from Blade view
        $pdf = Pdf::loadView('estimates.exports.estimate', $viewData);

        // Configure PDF settings
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('margin-top', '15mm');
        $pdf->setOption('margin-bottom', '15mm');
        $pdf->setOption('margin-left', '10mm');
        $pdf->setOption('margin-right', '10mm');

        // Save to file
        $filename = $this->generateFilename($estimate);
        $tempPath = storage_path("app/temp/{$filename}");

        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $pdf->save($tempPath);

        return $tempPath;
    }

    /**
     * Сгенерировать имя файла
     */
    protected function generateFilename(Estimate $estimate): string
    {
        $number = $estimate->number ?? $estimate->id;
        $date = now()->format('Ymd_His');
        return "Smeta_Prohelper_{$number}_{$date}.pdf";
    }
}
