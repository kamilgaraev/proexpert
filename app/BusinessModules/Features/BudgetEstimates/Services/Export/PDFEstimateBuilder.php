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
     * @return array ['content' => binary content, 'filename' => filename]
     */
    public function build(Estimate $estimate, array $data, array $options): array
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
        $pdf->setOption('margin-top', '12mm');
        $pdf->setOption('margin-bottom', '12mm');
        $pdf->setOption('margin-left', '15mm');
        $pdf->setOption('margin-right', '15mm');

        // Generate content in memory
        $content = $pdf->output();
        $filename = $this->generateFilename($estimate);

        return [
            'content' => $content,
            'filename' => $filename,
        ];
    }

    /**
     * Сгенерировать имя файла
     */
    protected function generateFilename(Estimate $estimate): string
    {
        $number = $estimate->number ?? $estimate->id;
        $date = now()->format('d.m.Y');
        return "Смета_{$number}_{$date}.pdf";
    }
}
