<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Exceptions\BusinessLogicException;
use App\Models\ContractPerformanceAct;
use App\Models\Organization;
use App\Services\Export\ExcelExporterService;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

use function trans_message;

class ActReportExportService
{
    public function __construct(
        private readonly OfficialFormsExportService $officialExportService,
        private readonly FileService $fileService,
        private readonly ActReportWorkflowService $workflowService,
        private readonly ActReportAccessService $accessService,
        private readonly ExcelExporterService $excelExporter
    ) {
    }

    public function exportPdf(ContractPerformanceAct $act): array
    {
        $act = $this->workflowService->recalculatePricedLines($act);
        $path = $this->officialExportService->exportKS2ToPdf($act, $act->contract);

        return ['url' => $this->fileService->temporaryUrl($path, 15)];
    }

    public function exportExcel(ContractPerformanceAct $act): array
    {
        $act = $this->workflowService->recalculatePricedLines($act);
        $path = $this->officialExportService->exportKS2ToExcel($act, $act->contract);

        return ['url' => $this->fileService->temporaryUrl($path, 15)];
    }

    public function exportKS3Excel(ContractPerformanceAct $act): array
    {
        $act = $this->workflowService->recalculatePricedLines($act);
        $path = $this->officialExportService->exportKS3ToExcel($act, $act->contract);

        return ['url' => $this->fileService->temporaryUrl($path, 15)];
    }

    public function exportKS3Pdf(ContractPerformanceAct $act): array
    {
        $act = $this->workflowService->recalculatePricedLines($act);
        $path = $this->officialExportService->exportKS3ToPdf($act, $act->contract);

        return ['url' => $this->fileService->temporaryUrl($path, 15)];
    }

    public function bulkExportExcel(int $organizationId, array $actIds): array
    {
        $acts = ContractPerformanceAct::query()
            ->with([
                'contract.project',
                'contract.contractor',
                'completedWorks.workType.measurementUnit',
            ])
            ->whereHas('contract', function (Builder $query) use ($organizationId): void {
                $query->where(function (Builder $scope) use ($organizationId): void {
                    $scope->where('contracts.organization_id', $organizationId)
                        ->orWhereHas('contractor', static function (Builder $contractorQuery) use ($organizationId): void {
                            $contractorQuery->where('source_organization_id', $organizationId);
                        });
                });
            })
            ->whereIn('id', $actIds)
            ->get();

        $organization = Organization::query()->find($organizationId);

        if (!$organization) {
            throw new BusinessLogicException(trans_message('act_reports.organization_not_found'), 400);
        }

        $headers = [
            'Номер акта',
            'Контракт',
            'Проект',
            'Подрядчик',
            'Дата акта',
            'Сумма',
            'Статус',
            'Наименование работы',
            'Единица измерения',
            'Количество',
            'Цена за единицу',
            'Сумма работы',
        ];

        $exportData = [];
        foreach ($acts as $act) {
            foreach ($act->completedWorks as $work) {
                $includedQuantity = (float) ($work->pivot?->included_quantity ?? $work->quantity ?? 0);
                $includedAmount = (float) ($work->pivot?->included_amount ?? $work->total_amount ?? 0);

                $exportData[] = [
                    $act->act_document_number,
                    $act->contract->number ?? '',
                    $act->contract->project->name ?? '',
                    $act->contract->contractor->name ?? '',
                    $act->act_date ? $act->act_date->format('d.m.Y') : '',
                    number_format((float) $act->amount, 2, '.', ''),
                    $act->is_approved
                        ? trans_message('act_reports.statuses.approved')
                        : trans_message('act_reports.statuses.draft'),
                    $work->workType?->name ?? $work->description,
                    $work->workType?->measurementUnit?->short_name ?? '',
                    $includedQuantity,
                    $includedQuantity > 0 ? round($includedAmount / $includedQuantity, 2) : 0,
                    $includedAmount,
                ];
            }
        }

        $filename = 'bulk_acts_' . now()->format('Ymd_His') . '.xlsx';
        $spreadsheet = $this->excelExporter->createSpreadsheet($headers, $exportData);
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        if ($content === false) {
            throw new BusinessLogicException(trans_message('act_reports.export_failed'), 500);
        }

        $path = "org-{$organizationId}/exports/bulk_acts/{$filename}";
        $this->fileService->disk($organization)->put($path, $content);

        return ['url' => $this->fileService->temporaryUrl($path, 15)];
    }
}
