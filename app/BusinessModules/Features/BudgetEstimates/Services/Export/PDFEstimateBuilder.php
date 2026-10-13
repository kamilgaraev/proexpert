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
        $landscape = false;
        array_walk_recursive($data['sections'], static function ($value, $key) use (&$landscape): void {
            if (in_array($key, ['quantity_total', 'unit_price', 'total_amount'], true)) {
                $formatted = number_format((float) $value, $key === 'quantity_total' ? 4 : 2, '.', $key === 'quantity_total' ? '' : ' ');
                $landscape = $landscape || strlen($formatted) > 11;
            }
        });
        $pdf->setPaper('a4', $landscape ? 'landscape' : 'portrait');
        $pdf->setOption('margin-top', '12mm');
        $pdf->setOption('margin-bottom', '12mm');
        $pdf->setOption('margin-left', '15mm');
        $pdf->setOption('margin-right', '15mm');
        
        // Включаем поддержку Unicode для корректного отображения кириллицы
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'DejaVu Sans');

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
