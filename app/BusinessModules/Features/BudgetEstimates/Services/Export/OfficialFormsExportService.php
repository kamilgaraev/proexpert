<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Export;

use App\Models\Estimate;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Barryvdh\DomPDF\Facade\Pdf;

class OfficialFormsExportService
{
    public function exportKS2ToExcel(ContractPerformanceAct $act, Contract $contract): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $this->setKS2Header($sheet, $act, $contract);
        $this->setKS2Items($sheet, $act);
        $this->setKS2Footer($sheet, $act, $contract);
        $this->applyKS2Styles($sheet);

        $filename = "KS-2_{$act->number}_{$contract->number}.xlsx";
        $tempPath = storage_path("app/temp/{$filename}");

        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return $tempPath;
    }

    public function exportKS3ToExcel(ContractPerformanceAct $act, Contract $contract): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $this->setKS3Header($sheet, $act, $contract);
        $this->setKS3Items($sheet, $act);
        $this->setKS3Footer($sheet, $act, $contract);
        $this->applyKS3Styles($sheet);

        $filename = "KS-3_{$act->number}_{$contract->number}.xlsx";
        $tempPath = storage_path("app/temp/{$filename}");

        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return $tempPath;
    }

    public function exportKS2ToPdf(ContractPerformanceAct $act, Contract $contract): string
    {
        $data = $this->prepareKS2Data($act, $contract);
        
        $pdf = Pdf::loadView('estimates.exports.ks2', $data);
        
        $filename = "KS-2_{$act->number}_{$contract->number}.pdf";
        $tempPath = storage_path("app/temp/{$filename}");

        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $pdf->save($tempPath);

        return $tempPath;
    }

    public function exportKS3ToPdf(ContractPerformanceAct $act, Contract $contract): string
    {
        $data = $this->prepareKS3Data($act, $contract);
        
        $pdf = Pdf::loadView('estimates.exports.ks3', $data);
        
        $filename = "KS-3_{$act->number}_{$contract->number}.pdf";
        $tempPath = storage_path("app/temp/{$filename}");

        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $pdf->save($tempPath);

        return $tempPath;
    }

    protected function setKS2Header($sheet, ContractPerformanceAct $act, Contract $contract): void
    {
        $sheet->setCellValue('A1', 'Унифицированная форма № КС-2');
        $sheet->mergeCells('A1:H1');
        
        $sheet->setCellValue('A2', 'АКТ № ' . $act->number);
        $sheet->setCellValue('F2', 'от ' . $act->act_date->format('d.m.Y'));
        $sheet->mergeCells('A2:E2');
        
        $sheet->setCellValue('A3', 'о приемке выполненных работ');
        $sheet->mergeCells('A3:H3');

        $sheet->setCellValue('A5', 'Заказчик: ' . ($contract->customer_organization ?? ''));
        $sheet->mergeCells('A5:H5');
        
        $sheet->setCellValue('A6', 'Подрядчик: ' . ($contract->contractor->full_name ?? ''));
        $sheet->mergeCells('A6:H6');
        
        $sheet->setCellValue('A7', 'Договор: № ' . $contract->number . ' от ' . $contract->contract_date->format('d.m.Y'));
        $sheet->mergeCells('A7:H7');
        
        $sheet->setCellValue('A8', 'Объект: ' . ($contract->project->name ?? ''));
        $sheet->mergeCells('A8:H8');

        $row = 10;
        $sheet->setCellValue("A{$row}", '№ п/п');
        $sheet->setCellValue("B{$row}", 'Наименование работ');
        $sheet->setCellValue("C{$row}", 'Номер расценки');
        $sheet->setCellValue("D{$row}", 'Ед. изм.');
        $sheet->setCellValue("E{$row}", 'Количество');
        $sheet->setCellValue("F{$row}", 'Цена за ед., руб.');
        $sheet->setCellValue("G{$row}", 'Стоимость, руб.');
        $sheet->setCellValue("H{$row}", 'Примечание');
    }

    protected function setKS2Items($sheet, ContractPerformanceAct $act): void
    {
        $row = 11;
        $totalAmount = 0;

        foreach ($act->completedWorks as $index => $work) {
            $sheet->setCellValue("A{$row}", $index + 1);
            $sheet->setCellValue("B{$row}", $work->work_type->name ?? $work->description);
            $sheet->setCellValue("C{$row}", ''); // Номер расценки можно взять из estimate_item если есть
            $sheet->setCellValue("D{$row}", $work->measurementUnit->short_name ?? '');
            $sheet->setCellValue("E{$row}", $work->quantity);
            $sheet->setCellValue("F{$row}", $work->unit_price);
            $sheet->setCellValue("G{$row}", $work->total_cost);
            $sheet->setCellValue("H{$row}", $work->notes ?? '');

            $totalAmount += $work->total_cost;
            $row++;
        }

        $sheet->setCellValue("A{$row}", '');
        $sheet->setCellValue("B{$row}", 'ИТОГО:');
        $sheet->mergeCells("B{$row}:F{$row}");
        $sheet->setCellValue("G{$row}", $totalAmount);
    }

    protected function setKS2Footer($sheet, ContractPerformanceAct $act, Contract $contract): void
    {
        $lastRow = $sheet->getHighestRow();
        $row = $lastRow + 2;

        $sheet->setCellValue("A{$row}", 'Заказчик:');
        $row++;
        $sheet->setCellValue("A{$row}", '_____________ / _______________ /');
        $sheet->setCellValue("E{$row}", '"___" _________ ' . date('Y') . ' г.');

        $row += 2;
        $sheet->setCellValue("A{$row}", 'Подрядчик:');
        $row++;
        $sheet->setCellValue("A{$row}", '_____________ / _______________ /');
        $sheet->setCellValue("E{$row}", '"___" _________ ' . date('Y') . ' г.');
    }

    protected function setKS3Header($sheet, ContractPerformanceAct $act, Contract $contract): void
    {
        $sheet->setCellValue('A1', 'Унифицированная форма № КС-3');
        $sheet->mergeCells('A1:G1');
        
        $sheet->setCellValue('A2', 'СПРАВКА № ' . $act->number);
        $sheet->setCellValue('F2', 'от ' . $act->act_date->format('d.m.Y'));
        $sheet->mergeCells('A2:E2');
        
        $sheet->setCellValue('A3', 'о стоимости выполненных работ и затрат');
        $sheet->mergeCells('A3:G3');

        $sheet->setCellValue('A5', 'Заказчик: ' . ($contract->customer_organization ?? ''));
        $sheet->mergeCells('A5:G5');
        
        $sheet->setCellValue('A6', 'Подрядчик: ' . ($contract->contractor->full_name ?? ''));
        $sheet->mergeCells('A6:G6');
        
        $sheet->setCellValue('A7', 'Договор: № ' . $contract->number . ' от ' . $contract->contract_date->format('d.m.Y'));
        $sheet->mergeCells('A7:G7');

        $row = 9;
        $sheet->setCellValue("A{$row}", '№ п/п');
        $sheet->setCellValue("B{$row}", 'Наименование работ и затрат');
        $sheet->setCellValue("C{$row}", 'Стоимость выполненных работ с начала года, руб.');
        $sheet->setCellValue("D{$row}", 'в том числе за отчетный период');
        $sheet->setCellValue("E{$row}", 'Выполнено работ с начала строительства, руб.');
        $sheet->setCellValue("F{$row}", 'Остаток по смете, руб.');
        $sheet->setCellValue("G{$row}", 'Примечание');
    }

    protected function setKS3Items($sheet, ContractPerformanceAct $act): void
    {
        $row = 10;
        $totalAmount = 0;
        $totalPeriod = 0;

        $estimate = $act->contract->estimate ?? null;

        $sheet->setCellValue("A{$row}", '1');
        $sheet->setCellValue("B{$row}", 'Строительные работы');
        $sheet->setCellValue("C{$row}", $act->total_amount);
        $sheet->setCellValue("D{$row}", $act->total_amount);
        $sheet->setCellValue("E{$row}", $act->total_amount);
        $sheet->setCellValue("F{$row}", $estimate ? ($estimate->total_amount - $act->total_amount) : 0);
        $sheet->setCellValue("G{$row}", '');

        $row++;
        $sheet->setCellValue("B{$row}", 'ИТОГО:');
        $sheet->mergeCells("B{$row}:B{$row}");
        $sheet->setCellValue("C{$row}", $act->total_amount);
        $sheet->setCellValue("D{$row}", $act->total_amount);
        $sheet->setCellValue("E{$row}", $act->total_amount);
        $sheet->setCellValue("F{$row}", $estimate ? ($estimate->total_amount - $act->total_amount) : 0);
    }

    protected function setKS3Footer($sheet, ContractPerformanceAct $act, Contract $contract): void
    {
        $lastRow = $sheet->getHighestRow();
        $row = $lastRow + 2;

        $sheet->setCellValue("A{$row}", 'Итого стоимость выполненных работ: ' . number_format($act->total_amount, 2, ',', ' ') . ' руб.');
        $sheet->mergeCells("A{$row}:G{$row}");

        $row += 2;
        $sheet->setCellValue("A{$row}", 'Заказчик:');
        $row++;
        $sheet->setCellValue("A{$row}", '_____________ / _______________ /');

        $row += 2;
        $sheet->setCellValue("A{$row}", 'Подрядчик:');
        $row++;
        $sheet->setCellValue("A{$row}", '_____________ / _______________ /');
    }

    protected function applyKS2Styles($sheet): void
    {
        $sheet->getStyle('A1:H1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1:H1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->getStyle('A2:H2')->getFont()->setBold(true)->setSize(12);
        
        $sheet->getStyle('A10:H10')->getFont()->setBold(true);
        $sheet->getStyle('A10:H10')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A10:H10')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(10);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(15);
        $sheet->getColumnDimension('H')->setWidth(20);
    }

    protected function applyKS3Styles($sheet): void
    {
        $sheet->getStyle('A1:G1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->getStyle('A2:G2')->getFont()->setBold(true)->setSize(12);
        
        $sheet->getStyle('A9:G9')->getFont()->setBold(true);
        $sheet->getStyle('A9:G9')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A9:G9')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(35);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(15);
        $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(15);
    }

    protected function prepareKS2Data(ContractPerformanceAct $act, Contract $contract): array
    {
        return [
            'act' => $act,
            'contract' => $contract,
            'works' => $act->completedWorks,
            'total_amount' => $act->total_amount,
        ];
    }

    protected function prepareKS3Data(ContractPerformanceAct $act, Contract $contract): array
    {
        $estimate = $contract->estimate ?? null;
        
        return [
            'act' => $act,
            'contract' => $contract,
            'estimate' => $estimate,
            'total_amount' => $act->total_amount,
            'remaining_amount' => $estimate ? ($estimate->total_amount - $act->total_amount) : 0,
        ];
    }
}
