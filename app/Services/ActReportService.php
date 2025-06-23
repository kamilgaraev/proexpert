<?php

namespace App\Services;

use App\Models\ActReport;
use App\Models\ContractPerformanceAct;
use App\Services\Export\ExcelExporterService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Exception;

class ActReportService
{
    protected ExcelExporterService $excelExporter;

    public function __construct(ExcelExporterService $excelExporter)
    {
        $this->excelExporter = $excelExporter;
    }

    public function createReport(int $organizationId, int $performanceActId, string $format, string $title = null): ActReport
    {
        $performanceAct = ContractPerformanceAct::with([
            'contract.project', 
            'contract.contractor',
            'completedWorks.workType',
            'completedWorks.materials'
        ])->findOrFail($performanceActId);

        $reportTitle = $title ?: "Акт выполненных работ №{$performanceAct->act_document_number}";
        
        $actReport = ActReport::create([
            'organization_id' => $organizationId,
            'performance_act_id' => $performanceActId,
            'title' => $reportTitle,
            'format' => $format,
            'file_path' => '',
            'metadata' => [
                'contract_id' => $performanceAct->contract_id,
                'contract_number' => $performanceAct->contract->contract_number,
                'project_name' => $performanceAct->contract->project->name,
                'contractor_name' => $performanceAct->contract->contractor->name,
                'act_date' => $performanceAct->act_date->format('Y-m-d'),
                'act_amount' => $performanceAct->amount,
                'works_count' => $performanceAct->completedWorks->count(),
            ]
        ]);

        try {
            if ($format === 'pdf') {
                $this->generatePdfReport($actReport, $performanceAct);
            } else {
                $this->generateExcelReport($actReport, $performanceAct);
            }
        } catch (Exception $e) {
            $actReport->delete();
            throw $e;
        }

        return $actReport;
    }

    protected function generatePdfReport(ActReport $actReport, ContractPerformanceAct $performanceAct): void
    {
        $data = $this->prepareReportData($performanceAct);
        
        $pdf = Pdf::loadView('reports.act-report-pdf', $data);
        $pdf->setPaper('A4', 'portrait');
        
        $filename = $this->generateFileName($actReport, 'pdf');
        $content = $pdf->output();
        
        $this->uploadToS3($actReport, $filename, $content, 'application/pdf');
    }

    protected function generateExcelReport(ActReport $actReport, ContractPerformanceAct $performanceAct): void
    {
        $data = $this->prepareReportData($performanceAct);
        
        $headers = [
            'Наименование работы',
            'Единица измерения', 
            'Количество',
            'Цена за единицу',
            'Сумма',
            'Материалы',
            'Дата выполнения',
            'Исполнитель'
        ];

        $exportData = [];
        foreach ($data['works'] as $work) {
            $materials = $work['materials']->map(function ($material) {
                return $material['name'] . ' (' . $material['pivot']['quantity'] . ' ' . $material['unit'] . ')';
            })->join(', ');

            $exportData[] = [
                $work['work_type']['name'],
                $work['unit'],
                $work['quantity'],
                number_format($work['unit_price'], 2, ',', ' '),
                number_format($work['total_amount'], 2, ',', ' '),
                $materials,
                $work['completion_date']->format('d.m.Y'),
                $work['executor']['name'] ?? 'Не указан'
            ];
        }

        $filename = $this->generateFileName($actReport, 'xlsx');
        $tempFile = tempnam(sys_get_temp_dir(), 'act_report_');
        
        $response = $this->excelExporter->streamDownload(
            basename($tempFile) . '.xlsx',
            $headers,
            $exportData
        );
        
        if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            ob_start();
            $response->sendContent();
            $content = ob_get_contents();
            ob_end_clean();
        } else {
            $content = json_encode(['error' => 'Failed to generate Excel']);
        }

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
        
        $this->uploadToS3($actReport, $filename, $content, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    protected function prepareReportData(ContractPerformanceAct $performanceAct): array
    {
        return [
            'act' => $performanceAct,
            'contract' => $performanceAct->contract,
            'project' => $performanceAct->contract->project,
            'contractor' => $performanceAct->contract->contractor,
            'works' => $performanceAct->completedWorks,
            'total_amount' => $performanceAct->amount,
            'generated_at' => now()->format('d.m.Y H:i')
        ];
    }

    protected function generateFileName(ActReport $actReport, string $extension): string
    {
        $safeTitle = Str::slug($actReport->title);
        $timestamp = now()->format('Y-m-d_H-i-s');
        
        return "act_reports/{$actReport->organization_id}/{$actReport->report_number}_{$safeTitle}_{$timestamp}.{$extension}";
    }

    protected function uploadToS3(ActReport $actReport, string $filename, string $content, string $mimeType): void
    {
        $uploaded = Storage::disk('s3')->put($filename, $content, [
            'ContentType' => $mimeType,
            'Expires' => $actReport->expires_at->toDateTimeString()
        ]);

        if (!$uploaded) {
            throw new Exception('Не удалось загрузить файл на S3');
        }

        $actReport->update([
            'file_path' => $filename,
            's3_key' => $filename,
            'file_size' => strlen($content)
        ]);

        Log::info('Отчет акта успешно создан и загружен на S3', [
            'report_id' => $actReport->id,
            'filename' => $filename,
            'file_size' => strlen($content)
        ]);
    }

    public function getReportsByOrganization(int $organizationId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = ActReport::with(['performanceAct.contract.project', 'performanceAct.contract.contractor'])
            ->where('organization_id', $organizationId);

        if (!empty($filters['performance_act_id'])) {
            $query->where('performance_act_id', $filters['performance_act_id']);
        }

        if (!empty($filters['format'])) {
            $query->where('format', $filters['format']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function deleteExpiredReports(): int
    {
        $expiredReports = ActReport::where('expires_at', '<', now())->get();
        $deletedCount = 0;

        foreach ($expiredReports as $report) {
            try {
                $report->delete();
                $deletedCount++;
                Log::info('Удален просроченный отчет акта', ['report_id' => $report->id]);
            } catch (Exception $e) {
                Log::error('Ошибка при удалении просроченного отчета акта', [
                    'report_id' => $report->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $deletedCount;
    }

    public function regenerateReport(ActReport $actReport): ActReport
    {
        $performanceAct = $actReport->performanceAct;
        
        $actReport->deleteFile();
        
        if ($actReport->format === 'pdf') {
            $this->generatePdfReport($actReport, $performanceAct);
        } else {
            $this->generateExcelReport($actReport, $performanceAct);
        }

        $actReport->touch();
        
        return $actReport;
    }
} 