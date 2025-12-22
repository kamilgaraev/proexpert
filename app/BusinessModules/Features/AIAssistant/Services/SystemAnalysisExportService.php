<?php

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\SystemAnalysisReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class SystemAnalysisExportService
{
    /**
     * Экспортировать отчет в PDF
     *
     * @param SystemAnalysisReport $report
     * @return string Путь к сгенерированному файлу
     */
    public function exportToPDF(SystemAnalysisReport $report): string
    {
        // Генерируем HTML
        $html = $this->generateHTML($report);

        // Создаем PDF
        $pdf = PDF::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont' => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
            ]);

        // Сохраняем во временный файл
        $fileName = 'analysis_report_' . $report->id . '_' . time() . '.pdf';
        $tempPath = storage_path('app/temp/' . $fileName);

        // Создаем директорию если не существует
        if (!file_exists(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $pdf->save($tempPath);

        return $tempPath;
    }

    /**
     * Генерировать HTML для отчета
     *
     * @param SystemAnalysisReport $report
     * @return string
     */
    public function generateHTML(SystemAnalysisReport $report): string
    {
        $data = [
            'report' => $report,
            'project' => $report->project,
            'sections' => $report->analysisSections,
            'generated_at' => now()->format('d.m.Y H:i'),
        ];

        return view('ai-assistant::analysis-report', $data)->render();
    }
}

