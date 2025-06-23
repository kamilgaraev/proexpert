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
        Log::info('ActReportService::createReport started', [
            'org_id' => $organizationId,
            'act_id' => $performanceActId,
            'format' => $format
        ]);
        
        $performanceAct = ContractPerformanceAct::with([
            'contract.project', 
            'contract.contractor',
            'contract.organization',
            'completedWorks.workType',
            'completedWorks.materials',
            'completedWorks.executor'
        ])->findOrFail($performanceActId);

        Log::info('ActReportService::createReport loaded performance act', [
            'act_id' => $performanceAct->id,
            'has_contract' => !!$performanceAct->contract
        ]);

        if (!$performanceAct->contract) {
            throw new Exception('Акт не связан с контрактом');
        }

        $reportTitle = $title ?: "Акт выполненных работ №{$performanceAct->act_document_number}";
        
        $actReport = ActReport::create([
            'organization_id' => $organizationId,
            'performance_act_id' => $performanceActId,
            'title' => $reportTitle,
            'format' => $format,
            'file_path' => '',
            'metadata' => [
                'contract_id' => $performanceAct->contract_id,
                'contract_number' => $performanceAct->contract->contract_number ?? '',
                'project_name' => $performanceAct->contract->project->name ?? '',
                'contractor_name' => $performanceAct->contract->contractor->name ?? '',
                'act_date' => $performanceAct->act_date ? $performanceAct->act_date->format('Y-m-d') : '',
                'act_amount' => $performanceAct->amount ?? 0,
                'works_count' => $performanceAct->completedWorks ? $performanceAct->completedWorks->count() : 0,
            ]
        ]);

        Log::info('ActReportService::createReport act report created', ['report_id' => $actReport->id]);

        try {
            if ($format === 'pdf') {
                Log::info('ActReportService generating PDF report');
                $this->generatePdfReport($actReport, $performanceAct);
            } else {
                Log::info('ActReportService generating Excel report');
                $this->generateExcelReport($actReport, $performanceAct);
            }
            Log::info('ActReportService::createReport generation completed');
        } catch (Exception $e) {
            Log::error('ActReportService::createReport generation failed', [
                'error' => $e->getMessage(),
                'report_id' => $actReport->id
            ]);
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
            $materials = '';
            if ($work->materials && $work->materials->isNotEmpty()) {
                $materials = $work->materials->map(function ($material) {
                    $quantity = $material->pivot->quantity ?? 0;
                    $unit = $material->unit ?? '';
                    return $material->name . ' (' . $quantity . ' ' . $unit . ')';
                })->join(', ');
            }

            $workTypeName = $work->workType ? $work->workType->name : 'Не указан';
            $executorName = $work->executor ? $work->executor->name : 'Не указан';
            $completionDate = $work->completion_date ? $work->completion_date->format('d.m.Y') : 'Не указана';

            $exportData[] = [
                $workTypeName,
                $work->unit ?? '',
                number_format($work->quantity ?? 0, 2, ',', ' '),
                number_format($work->unit_price ?? 0, 2, ',', ' '),
                number_format($work->total_amount ?? 0, 2, ',', ' '),
                $materials,
                $completionDate,
                $executorName
            ];
        }

        $filename = $this->generateFileName($actReport, 'xlsx');
        
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            $colIndex = 0;
            foreach ($headers as $header) {
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1) . '1';
                $sheet->setCellValue($cell, $header);
                $colIndex++;
            }
            
            $rowIndex = 2;
            foreach ($exportData as $row) {
                $colIndex = 0;
                foreach ($row as $value) {
                    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1) . $rowIndex;
                    $sheet->setCellValue($cell, $value);
                    $colIndex++;
                }
                $rowIndex++;
            }
            
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            
            $tempFile = tempnam(sys_get_temp_dir(), 'act_report_');
            $writer->save($tempFile);
            
            $content = file_get_contents($tempFile);
            unlink($tempFile);
            
            $this->uploadToS3($actReport, $filename, $content, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            
        } catch (Exception $e) {
            throw new Exception('Ошибка при генерации Excel отчета: ' . $e->getMessage());
        }
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
        try {
            $uploaded = Storage::disk('s3')->put($filename, $content, [
                'ContentType' => $mimeType
            ]);

            if (!$uploaded) {
                throw new Exception('Не удалось загрузить файл на S3');
            }

            $actReport->update([
                'file_path' => $filename,
                's3_key' => $filename,
                'file_size' => strlen($content)
            ]);

        } catch (Exception $e) {
            throw new Exception('Ошибка при загрузке файла на S3: ' . $e->getMessage());
        }
    }

    public function getReportsByOrganization(?int $organizationId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        if (!$organizationId) {
            return new \Illuminate\Database\Eloquent\Collection();
        }
        
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
            } catch (Exception $e) {
                continue;
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