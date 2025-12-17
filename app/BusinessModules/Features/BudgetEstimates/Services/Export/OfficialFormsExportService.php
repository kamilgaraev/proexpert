<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Export;

use App\Models\Estimate;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Helpers\NumberToWordsHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
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

        $actNumber = $act->act_document_number ?? $act->id;
        $filename = "KS-2_{$actNumber}_{$contract->number}.xlsx";
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

        $actNumber = $act->act_document_number ?? $act->id;
        $filename = "KS-3_{$actNumber}_{$contract->number}.xlsx";
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
        
        $actNumber = $act->act_document_number ?? $act->id;
        $filename = "KS-2_{$actNumber}_{$contract->number}.pdf";
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
        
        $actNumber = $act->act_document_number ?? $act->id;
        $filename = "KS-3_{$actNumber}_{$contract->number}.pdf";
        $tempPath = storage_path("app/temp/{$filename}");

        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $pdf->save($tempPath);

        return $tempPath;
    }

    protected function setKS2Header($sheet, ContractPerformanceAct $act, Contract $contract): void
    {
        $row = 1;
        
        // Заголовок формы
        $sheet->setCellValue("A{$row}", 'Унифицированная форма № КС-2');
        $sheet->mergeCells("A{$row}:H{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Утверждена постановлением Госкомстата России от 11.11.99 № 100');
        $sheet->mergeCells("A{$row}:H{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Форма по ОКУД 322005');
        $sheet->mergeCells("A{$row}:H{$row}");
        $row += 2;
        
        // Секции сторон
        $customerOrg = $contract->project?->organization ?? $contract->organization;
        $contractor = $contract->contractor;
        
        // Инвестор (пусто)
        $sheet->setCellValue("A{$row}", 'Инвестор');
        $sheet->setCellValue("B{$row}", '');
        $sheet->mergeCells("B{$row}:H{$row}");
        $row++;
        
        // Заказчик
        $customerName = $customerOrg?->legal_name ?? $customerOrg?->name ?? '';
        $customerInn = $customerOrg?->tax_number ?? '';
        $customerAddress = $this->formatAddress($customerOrg);
        $sheet->setCellValue("A{$row}", 'Заказчик');
        $sheet->setCellValue("B{$row}", $customerName . ($customerInn ? ', ИНН ' . $customerInn : '') . ($customerAddress ? ', ' . $customerAddress : ''));
        $sheet->mergeCells("B{$row}:H{$row}");
        $row++;
        
        // Заказчик (Генподрядчик)
        $sheet->setCellValue("A{$row}", 'Заказчик (Генподрядчик)');
        $sheet->setCellValue("B{$row}", $customerName . ($customerInn ? ', ИНН ' . $customerInn : '') . ($customerAddress ? ', ' . $customerAddress : ''));
        $sheet->mergeCells("B{$row}:H{$row}");
        $row++;
        
        // Подрядчик (Субподрядчик)
        $contractorName = $contractor?->name ?? '';
        $contractorInn = $contractor?->inn ?? '';
        $contractorAddress = $contractor?->legal_address ?? '';
        $sheet->setCellValue("A{$row}", 'Подрядчик (Субподрядчик)');
        $sheet->setCellValue("B{$row}", $contractorName . ($contractorInn ? ', ИНН ' . $contractorInn : '') . ($contractorAddress ? ', ' . $contractorAddress : ''));
        $sheet->mergeCells("B{$row}:H{$row}");
        $row += 2;
        
        // Стройка и Объект
        $projectName = $contract->project?->name ?? '';
        $sheet->setCellValue("A{$row}", 'Стройка');
        $sheet->setCellValue("B{$row}", '');
        $sheet->mergeCells("B{$row}:H{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Объект');
        $sheet->setCellValue("B{$row}", $projectName);
        $sheet->mergeCells("B{$row}:H{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Вид деятельности по ОКВЭД');
        $sheet->setCellValue("B{$row}", '');
        $sheet->mergeCells("B{$row}:H{$row}");
        $row += 2;
        
        // Договор подряда
        $sheet->setCellValue("A{$row}", 'Договор подряда (контракт)');
        $row++;
        $sheet->setCellValue("A{$row}", 'номер');
        $sheet->setCellValue("B{$row}", $contract->number);
        $sheet->setCellValue("D{$row}", 'дата');
        $sheet->setCellValue("E{$row}", $contract->date->format('d.m.Y'));
        $row++;
        $sheet->setCellValue("A{$row}", 'Вид операции');
        $sheet->setCellValue("B{$row}", '');
        $sheet->mergeCells("B{$row}:H{$row}");
        $row += 2;
        
        // Отчетный период
        $periodStart = $act->act_date->copy()->startOfMonth();
        $periodEnd = $act->act_date->copy()->endOfMonth();
        $sheet->setCellValue("A{$row}", 'Отчетный период');
        $sheet->setCellValue("B{$row}", 'с ' . $periodStart->format('d.m.Y') . ' по ' . $periodEnd->format('d.m.Y'));
        $sheet->mergeCells("B{$row}:H{$row}");
        $row += 2;
        
        // Номер документа и дата составления
        $actNumber = $act->act_document_number ?? str_pad($act->id, 10, '0', STR_PAD_LEFT);
        $sheet->setCellValue("A{$row}", 'Номер документа');
        $sheet->setCellValue("B{$row}", $actNumber);
        $sheet->setCellValue("D{$row}", 'Дата составления');
        $sheet->setCellValue("E{$row}", $act->act_date->format('d.m.Y'));
        $row += 2;
        
        // Сметная стоимость
        $estimate = $contract->estimate ?? null;
        $contractAmount = $contract->is_fixed_amount ? ($contract->total_amount ?? 0) : ($estimate?->total_amount ?? 0);
        $sheet->setCellValue("A{$row}", 'Сметная (договорная) стоимость в соответствии с договором подряда (субподряда)');
        $sheet->setCellValue("B{$row}", number_format($contractAmount, 2, ',', ' ') . ' руб.');
        $sheet->mergeCells("B{$row}:H{$row}");
        $row += 2;
        
        // Заголовок таблицы
        $sheet->setCellValue("A{$row}", '№ п/п');
        $sheet->setCellValue("B{$row}", 'по смете');
        $sheet->setCellValue("C{$row}", 'Наименование работ');
        $sheet->setCellValue("D{$row}", 'Единица измерения');
        $sheet->setCellValue("E{$row}", 'Количество');
        $sheet->setCellValue("F{$row}", 'Цена за единицу, руб.');
        $sheet->setCellValue("G{$row}", 'Стоимость, руб.');
        $sheet->setCellValue("H{$row}", 'Примечание');
    }

    protected function setKS2Items($sheet, ContractPerformanceAct $act): void
    {
        $startRow = $sheet->getHighestRow() + 1;
        $row = $startRow;
        $totalAmount = 0;

        foreach ($act->completedWorks as $index => $work) {
            $includedQuantity = $work->pivot->included_quantity ?? $work->quantity ?? 0;
            $includedAmount = $work->pivot->included_amount ?? $work->total_amount ?? 0;
            $unitPrice = $includedQuantity > 0 ? ($includedAmount / $includedQuantity) : ($work->price ?? 0);
            
            $sheet->setCellValue("A{$row}", $index + 1);
            $sheet->setCellValue("B{$row}", ''); // по смете
            $sheet->setCellValue("C{$row}", $work->workType?->name ?? $work->description ?? '');
            $sheet->setCellValue("D{$row}", $work->workType?->measurementUnit?->short_name ?? '');
            $sheet->setCellValue("E{$row}", $includedQuantity);
            $sheet->setCellValue("F{$row}", $unitPrice);
            $sheet->setCellValue("G{$row}", $includedAmount);
            $sheet->setCellValue("H{$row}", $work->pivot->notes ?? $work->notes ?? '');

            $totalAmount += $includedAmount;
            $row++;
        }

        // Итого по расценкам
        $sheet->setCellValue("A{$row}", '');
        $sheet->setCellValue("B{$row}", '');
        $sheet->setCellValue("C{$row}", 'Итого по расценкам');
        $sheet->mergeCells("C{$row}:F{$row}");
        $sheet->setCellValue("G{$row}", $totalAmount);
        $row++;
        
        // НДС (20%)
        $vatAmount = round($totalAmount * 0.20, 2);
        $sheet->setCellValue("A{$row}", '');
        $sheet->setCellValue("B{$row}", '');
        $sheet->setCellValue("C{$row}", 'НДС');
        $sheet->mergeCells("C{$row}:F{$row}");
        $sheet->setCellValue("G{$row}", $vatAmount);
        $row++;
        
        // Всего по Акту
        $totalWithVat = $totalAmount; // В примере общая сумма без НДС, НДС отдельно
        $sheet->setCellValue("A{$row}", '');
        $sheet->setCellValue("B{$row}", '');
        $sheet->setCellValue("C{$row}", 'Всего по Акту');
        $sheet->mergeCells("C{$row}:F{$row}");
        $sheet->setCellValue("G{$row}", $totalAmount);
        $row += 2;
        
        // Сумма прописью
        $amountInWords = NumberToWordsHelper::amountToWords($totalAmount);
        $sheet->setCellValue("A{$row}", 'Сумма прописью по акту: ' . $amountInWords);
        $sheet->mergeCells("A{$row}:H{$row}");
    }

    protected function setKS2Footer($sheet, ContractPerformanceAct $act, Contract $contract): void
    {
        $lastRow = $sheet->getHighestRow();
        $row = $lastRow + 3;

        $sheet->setCellValue("A{$row}", 'Сдал');
        $row++;
        $sheet->setCellValue("A{$row}", 'Генеральный Директор');
        $sheet->setCellValue("B{$row}", '_____________');
        $sheet->setCellValue("C{$row}", '/');
        $sheet->setCellValue("D{$row}", '_______________');
        $sheet->setCellValue("E{$row}", '/');
        $row++;
        $sheet->setCellValue("B{$row}", '(подпись)');
        $sheet->setCellValue("D{$row}", '(расшифровка подписи)');
        $row += 2;

        $sheet->setCellValue("A{$row}", 'Принял');
        $row++;
        $sheet->setCellValue("A{$row}", 'Генеральный Директор');
        $sheet->setCellValue("B{$row}", '_____________');
        $sheet->setCellValue("C{$row}", '/');
        $sheet->setCellValue("D{$row}", '_______________');
        $sheet->setCellValue("E{$row}", '/');
        $row++;
        $sheet->setCellValue("B{$row}", '(подпись)');
        $sheet->setCellValue("D{$row}", '(расшифровка подписи)');
    }

    protected function setKS3Header($sheet, ContractPerformanceAct $act, Contract $contract): void
    {
        $row = 1;
        
        // Заголовок формы
        $sheet->setCellValue("A{$row}", 'Унифицированная форма № КС-3');
        $sheet->mergeCells("A{$row}:G{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Утверждена постановлением Госкомстата России от 11.11.99 № 100');
        $sheet->mergeCells("A{$row}:G{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Форма по ОКУД 322005');
        $sheet->mergeCells("A{$row}:G{$row}");
        $row += 2;
        
        // Секции сторон
        $customerOrg = $contract->project?->organization ?? $contract->organization;
        $contractor = $contract->contractor;
        
        // Инвестор (пусто)
        $sheet->setCellValue("A{$row}", 'Инвестор');
        $sheet->setCellValue("B{$row}", '');
        $sheet->mergeCells("B{$row}:G{$row}");
        $row++;
        
        // Заказчик
        $customerName = $customerOrg?->legal_name ?? $customerOrg?->name ?? '';
        $customerInn = $customerOrg?->tax_number ?? '';
        $customerAddress = $this->formatAddress($customerOrg);
        $sheet->setCellValue("A{$row}", 'Заказчик');
        $sheet->setCellValue("B{$row}", $customerName . ($customerInn ? ', ИНН ' . $customerInn : '') . ($customerAddress ? ', ' . $customerAddress : ''));
        $sheet->mergeCells("B{$row}:G{$row}");
        $row++;
        
        // Заказчик (Генподрядчик)
        $sheet->setCellValue("A{$row}", 'Заказчик (Генподрядчик)');
        $sheet->setCellValue("B{$row}", $customerName . ($customerInn ? ', ИНН ' . $customerInn : '') . ($customerAddress ? ', ' . $customerAddress : ''));
        $sheet->mergeCells("B{$row}:G{$row}");
        $row++;
        
        // Подрядчик (Субподрядчик)
        $contractorName = $contractor?->name ?? '';
        $contractorInn = $contractor?->inn ?? '';
        $contractorAddress = $contractor?->legal_address ?? '';
        $sheet->setCellValue("A{$row}", 'Подрядчик (Субподрядчик)');
        $sheet->setCellValue("B{$row}", $contractorName . ($contractorInn ? ', ИНН ' . $contractorInn : '') . ($contractorAddress ? ', ' . $contractorAddress : ''));
        $sheet->mergeCells("B{$row}:G{$row}");
        $row += 2;
        
        // Стройка и Объект
        $projectName = $contract->project?->name ?? '';
        $sheet->setCellValue("A{$row}", 'Стройка');
        $sheet->setCellValue("B{$row}", '');
        $sheet->mergeCells("B{$row}:G{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Объект');
        $sheet->setCellValue("B{$row}", $projectName);
        $sheet->mergeCells("B{$row}:G{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Вид деятельности по ОКВЭД');
        $sheet->setCellValue("B{$row}", '');
        $sheet->mergeCells("B{$row}:G{$row}");
        $row += 2;
        
        // Договор подряда
        $sheet->setCellValue("A{$row}", 'Договор подряда (контракт)');
        $row++;
        $sheet->setCellValue("A{$row}", 'номер');
        $sheet->setCellValue("B{$row}", $contract->number);
        $sheet->setCellValue("D{$row}", 'дата');
        $sheet->setCellValue("E{$row}", $contract->date->format('d.m.Y'));
        $row++;
        $sheet->setCellValue("A{$row}", 'Вид операции');
        $sheet->setCellValue("B{$row}", '');
        $sheet->mergeCells("B{$row}:G{$row}");
        $row += 2;
        
        // Отчетный период
        $periodStart = $act->act_date->copy()->startOfMonth();
        $periodEnd = $act->act_date->copy()->endOfMonth();
        $sheet->setCellValue("A{$row}", 'Отчетный период');
        $sheet->setCellValue("B{$row}", 'с ' . $periodStart->format('d.m.Y') . ' по ' . $periodEnd->format('d.m.Y'));
        $sheet->mergeCells("B{$row}:G{$row}");
        $row += 2;
        
        // Номер документа и дата составления
        $actNumber = $act->act_document_number ?? str_pad($act->id, 10, '0', STR_PAD_LEFT);
        $sheet->setCellValue("A{$row}", 'Номер документа');
        $sheet->setCellValue("B{$row}", $actNumber);
        $sheet->setCellValue("D{$row}", 'Дата составления');
        $sheet->setCellValue("E{$row}", $act->act_date->format('d.m.Y'));
        $row += 2;
        
        // Заголовок таблицы
        $sheet->setCellValue("A{$row}", '№ по порядку');
        $sheet->setCellValue("B{$row}", 'Наименование пусковых комплексов, объектов, видов работ, оборудования, затрат');
        $sheet->setCellValue("C{$row}", 'Код');
        $sheet->setCellValue("D{$row}", 'Стоимость выполненных работ и затрат, руб.');
        $row++;
        
        // Подзаголовки колонок стоимости
        $sheet->setCellValue("D{$row}", 'с начала проведения работ');
        $sheet->setCellValue("E{$row}", 'с начала года');
        $sheet->setCellValue("F{$row}", 'в том числе за отчетный период');
        $sheet->setCellValue("G{$row}", 'Примечание');
    }

    protected function setKS3Items($sheet, ContractPerformanceAct $act): void
    {
        $startRow = $sheet->getHighestRow() + 1;
        $row = $startRow;
        
        $contract = $act->contract;
        $estimate = $contract->estimate ?? null;
        $actAmount = (float) ($act->amount ?? 0);
        $estimateTotal = $estimate ? (float) ($estimate->total_amount ?? 0) : 0;
        
        // Сумма с начала года (сумма всех актов за текущий год)
        // Для мультипроектных контрактов фильтруем по project_id
        $yearStart = $act->act_date->copy()->startOfYear();
        $yearTotal = $contract->performanceActs()
            ->where('project_id', $act->project_id)
            ->where('act_date', '>=', $yearStart)
            ->where('act_date', '<=', $act->act_date)
            ->sum('amount');
        
        // Сумма с начала строительства (сумма всех актов этого проекта)
        $totalFromStart = $contract->performanceActs()
            ->where('project_id', $act->project_id)
            ->where('act_date', '<=', $act->act_date)
            ->sum('amount');
        
        // Всего работ и затрат
        $sheet->setCellValue("A{$row}", '1');
        $sheet->setCellValue("B{$row}", 'Всего работ и затрат, включаемых в стоимость работ');
        $sheet->setCellValue("C{$row}", '');
        $sheet->setCellValue("D{$row}", $totalFromStart);
        $sheet->setCellValue("E{$row}", $yearTotal);
        $sheet->setCellValue("F{$row}", $actAmount);
        $sheet->setCellValue("G{$row}", '');
        $row++;
        
        // в том числе:
        $sheet->setCellValue("B{$row}", 'в том числе:');
        $sheet->mergeCells("B{$row}:G{$row}");
        $row++;
        
        // Детализация по работам
        $workIndex = 2;
        foreach ($act->completedWorks as $work) {
            $includedAmount = $work->pivot->included_amount ?? $work->total_amount ?? 0;
            
            $sheet->setCellValue("A{$row}", $workIndex);
            $sheet->setCellValue("B{$row}", $work->workType?->name ?? $work->description ?? '');
            $sheet->setCellValue("C{$row}", $work->workType?->code ?? '');
            $sheet->setCellValue("D{$row}", $totalFromStart);
            $sheet->setCellValue("E{$row}", $yearTotal);
            $sheet->setCellValue("F{$row}", $includedAmount);
            $sheet->setCellValue("G{$row}", '');
            
            $workIndex++;
            $row++;
        }
        
        // Итого
        $sheet->setCellValue("A{$row}", '');
        $sheet->setCellValue("B{$row}", 'ИТОГО:');
        $sheet->setCellValue("C{$row}", '');
        $sheet->setCellValue("D{$row}", $totalFromStart);
        $sheet->setCellValue("E{$row}", $yearTotal);
        $sheet->setCellValue("F{$row}", $actAmount);
        $sheet->setCellValue("G{$row}", '');
        $row++;
        
        // Сумма НДС
        $vatAmount = round($actAmount * 0.20, 2);
        $sheet->setCellValue("A{$row}", '');
        $sheet->setCellValue("B{$row}", 'Сумма НДС');
        $sheet->setCellValue("C{$row}", '');
        $sheet->setCellValue("D{$row}", '');
        $sheet->setCellValue("E{$row}", '');
        $sheet->setCellValue("F{$row}", $vatAmount);
        $row++;
        
        // Всего с учетом НДС
        $totalWithVat = $actAmount; // В примере общая сумма без НДС
        $sheet->setCellValue("A{$row}", '');
        $sheet->setCellValue("B{$row}", 'Всего с учетом НДС');
        $sheet->setCellValue("C{$row}", '');
        $sheet->setCellValue("D{$row}", '');
        $sheet->setCellValue("E{$row}", '');
        $sheet->setCellValue("F{$row}", $totalWithVat);
    }

    protected function setKS3Footer($sheet, ContractPerformanceAct $act, Contract $contract): void
    {
        $lastRow = $sheet->getHighestRow();
        $row = $lastRow + 3;

        $actAmount = (float) ($act->amount ?? 0);
        $sheet->setCellValue("A{$row}", 'Итого стоимость выполненных работ: ' . number_format($actAmount, 2, ',', ' ') . ' руб.');
        $sheet->mergeCells("A{$row}:G{$row}");
        $row += 3;

        $sheet->setCellValue("A{$row}", 'Генеральный Директор');
        $sheet->setCellValue("B{$row}", '_____________');
        $sheet->setCellValue("C{$row}", '/');
        $sheet->setCellValue("D{$row}", '_______________');
        $sheet->setCellValue("E{$row}", '/');
        $row++;
        $sheet->setCellValue("B{$row}", '(подпись)');
        $sheet->setCellValue("D{$row}", '(расшифровка подписи)');
    }

    protected function applyKS2Styles($sheet): void
    {
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        
        // Заголовок формы
        $sheet->getStyle('A1:H1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A1:H1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Заголовок таблицы
        $headerRow = $this->findHeaderRow($sheet, '№ п/п');
        if ($headerRow) {
            $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E0E0E0');
        }
        
        // Границы для всех ячеек с данными
        $dataStartRow = $headerRow ? $headerRow + 1 : 11;
        for ($row = $dataStartRow; $row <= $highestRow; $row++) {
            $sheet->getStyle("A{$row}:H{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        
        // Выравнивание числовых колонок
        $sheet->getStyle("E{$dataStartRow}:G{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        
        // Ширина колонок
        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(40);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('F')->setWidth(18);
        $sheet->getColumnDimension('G')->setWidth(18);
        $sheet->getColumnDimension('H')->setWidth(20);
        
        // Перенос текста для длинных ячеек
        $sheet->getStyle("B1:H{$highestRow}")->getAlignment()->setWrapText(true);
    }

    protected function applyKS3Styles($sheet): void
    {
        $highestRow = $sheet->getHighestRow();
        
        // Заголовок формы
        $sheet->getStyle('A1:G1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Заголовок таблицы
        $headerRow = $this->findHeaderRow($sheet, '№ по порядку');
        if ($headerRow) {
            $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E0E0E0');
            
            // Подзаголовки на следующей строке
            $subHeaderRow = $headerRow + 1;
            $sheet->getStyle("D{$subHeaderRow}:G{$subHeaderRow}")->getFont()->setBold(true)->setSize(8);
            $sheet->getStyle("D{$subHeaderRow}:G{$subHeaderRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("D{$subHeaderRow}:G{$subHeaderRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        
        // Границы для всех ячеек с данными
        $dataStartRow = $headerRow ? $headerRow + 2 : 10;
        for ($row = $dataStartRow; $row <= $highestRow; $row++) {
            $sheet->getStyle("A{$row}:G{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        
        // Выравнивание числовых колонок
        $sheet->getStyle("D{$dataStartRow}:F{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        
        // Ширина колонок
        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(50);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(20);
        $sheet->getColumnDimension('G')->setWidth(15);
        
        // Перенос текста
        $sheet->getStyle("B{$dataStartRow}:G{$highestRow}")->getAlignment()->setWrapText(true);
    }

    protected function findHeaderRow($sheet, string $searchText): ?int
    {
        $highestRow = $sheet->getHighestRow();
        for ($row = 1; $row <= $highestRow; $row++) {
            $cellValue = $sheet->getCell("A{$row}")->getValue();
            if ($cellValue && strpos($cellValue, $searchText) !== false) {
                return $row;
            }
        }
        return null;
    }

    protected function formatAddress($organization): string
    {
        if (!$organization) {
            return '';
        }
        
        $parts = [];
        if ($organization->postal_code) {
            $parts[] = $organization->postal_code;
        }
        if ($organization->city) {
            $parts[] = $organization->city . ' г';
        }
        if ($organization->address) {
            $parts[] = $organization->address;
        }
        
        return implode(', ', $parts);
    }

    protected function prepareKS2Data(ContractPerformanceAct $act, Contract $contract): array
    {
        $act->loadMissing([
            'completedWorks.workType.measurementUnit',
            'contract.contractor',
            'contract.project.organization',
            'contract.organization',
            'contract.estimate'
        ]);
        
        $totalAmount = $act->completedWorks->sum(function($work) {
            return (float) ($work->pivot->included_amount ?? $work->total_amount ?? 0);
        });
        
        $totalAmount = $totalAmount > 0 ? $totalAmount : (float) ($act->amount ?? 0);
        $vatAmount = round($totalAmount * 0.20, 2);
        
        $customerOrg = $contract->project?->organization ?? $contract->organization;
        $contractor = $contract->contractor;
        $estimate = $contract->estimate;
        $contractAmount = $contract->is_fixed_amount ? ($contract->total_amount ?? 0) : ($estimate?->total_amount ?? 0);
        
        // Период отчета
        $actDate = $act->act_date;
        $periodStart = $actDate->copy()->startOfMonth();
        $periodEnd = $actDate->copy()->endOfMonth();
        
        return [
            'act' => $act,
            'contract' => $contract,
            'works' => $act->completedWorks,
            'total_amount' => $totalAmount,
            'vat_amount' => $vatAmount,
            'contract_amount' => $contractAmount,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'customer_org' => $customerOrg,
            'contractor' => $contractor,
            'project' => $contract->project,
        ];
    }

    protected function prepareKS3Data(ContractPerformanceAct $act, Contract $contract): array
    {
        $act->loadMissing([
            'completedWorks.workType',
            'contract.contractor',
            'contract.project.organization',
            'contract.organization',
            'contract.estimate',
            'contract.completedWorks.workType'
        ]);
        
        $estimate = $contract->estimate;
        $actAmount = (float) ($act->amount ?? 0);
        $estimateTotal = $estimate ? (float) ($estimate->total_amount ?? 0) : 0;
        $vatAmount = round($actAmount * 0.20, 2);
        
        // Сумма с начала года (сумма всех актов за текущий год)
        // Для мультипроектных контрактов фильтруем по project_id
        $yearStart = $act->act_date->copy()->startOfYear();
        $yearTotal = $contract->performanceActs()
            ->where('project_id', $act->project_id)
            ->where('act_date', '>=', $yearStart)
            ->where('act_date', '<=', $act->act_date)
            ->sum('amount');
        
        // Сумма с начала строительства (сумма всех актов этого проекта)
        $totalFromStart = $contract->performanceActs()
            ->where('project_id', $act->project_id)
            ->where('act_date', '<=', $act->act_date)
            ->sum('amount');
        
        // Период отчета
        $actDate = $act->act_date;
        $periodStart = $actDate->copy()->startOfMonth();
        $periodEnd = $actDate->copy()->endOfMonth();
        
        $customerOrg = $contract->project?->organization ?? $contract->organization;
        $contractor = $contract->contractor;
        
        return [
            'act' => $act,
            'contract' => $contract,
            'estimate' => $estimate,
            'works' => $contract->completedWorks ?? collect(),
            'total_amount' => $actAmount,
            'vat_amount' => $vatAmount,
            'year_total' => (float) $yearTotal,
            'total_from_start' => (float) $totalFromStart,
            'remaining_amount' => max(0, $estimateTotal - $totalFromStart),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'customer_org' => $customerOrg,
            'contractor' => $contractor,
            'project' => $contract->project,
        ];
    }

    // === EXPORT KS-6 (CONSTRUCTION JOURNAL) ===

    public function exportKS6ToExcel(\App\Models\ConstructionJournal $journal, \Carbon\Carbon $from, \Carbon\Carbon $to): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $this->setKS6Header($sheet, $journal, $from, $to);
        $this->setKS6Items($sheet, $journal, $from, $to);
        $this->setKS6Footer($sheet, $journal);
        $this->applyKS6Styles($sheet);

        $journalNumber = $journal->journal_number ?? $journal->id;
        $filename = "KS-6_{$journalNumber}_{$from->format('Ymd')}_{$to->format('Ymd')}.xlsx";
        $tempPath = storage_path("app/temp/{$filename}");

        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return $tempPath;
    }

    public function exportKS6ToPdf(\App\Models\ConstructionJournal $journal, \Carbon\Carbon $from, \Carbon\Carbon $to): string
    {
        $data = $this->prepareKS6Data($journal, $from, $to);
        
        $pdf = Pdf::loadView('estimates.exports.ks6', $data);
        
        $journalNumber = $journal->journal_number ?? $journal->id;
        $filename = "KS-6_{$journalNumber}_{$from->format('Ymd')}_{$to->format('Ymd')}.pdf";
        $tempPath = storage_path("app/temp/{$filename}");

        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $pdf->save($tempPath);

        return $tempPath;
    }

    public function exportDailyReportToPdf(\App\Models\ConstructionJournalEntry $entry): string
    {
        $data = [
            'entry' => $entry->load([
                'journal.project',
                'scheduleTask',
                'createdBy',
                'approvedBy',
                'workVolumes.estimateItem',
                'workVolumes.workType',
                'workers',
                'equipment',
                'materials'
            ]),
        ];
        
        $pdf = Pdf::loadView('estimates.exports.journal_daily_report', $data);
        
        $journalNumber = $entry->journal->journal_number ?? $entry->journal_id;
        $filename = "Daily_Report_{$journalNumber}_{$entry->entry_date->format('Ymd')}_{$entry->entry_number}.pdf";
        $tempPath = storage_path("app/temp/{$filename}");

        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $pdf->save($tempPath);

        return $tempPath;
    }

    public function exportExtendedReportToExcel(\App\Models\ConstructionJournal $journal, array $options): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $from = \Carbon\Carbon::parse($options['date_from']);
        $to = \Carbon\Carbon::parse($options['date_to']);

        $this->setExtendedReportHeader($sheet, $journal, $from, $to);
        $this->setExtendedReportData($sheet, $journal, $from, $to, $options);
        $this->applyExtendedReportStyles($sheet);

        $journalNumber = $journal->journal_number ?? $journal->id;
        $filename = "Extended_Report_{$journalNumber}_{$from->format('Ymd')}_{$to->format('Ymd')}.xlsx";
        $tempPath = storage_path("app/temp/{$filename}");

        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return $tempPath;
    }

    // === KS-6 HELPER METHODS ===

    protected function setKS6Header($sheet, \App\Models\ConstructionJournal $journal, \Carbon\Carbon $from, \Carbon\Carbon $to): void
    {
        $row = 1;
        
        $sheet->setCellValue("A{$row}", 'ОБЩИЙ ЖУРНАЛ РАБОТ (форма КС-6)');
        $sheet->mergeCells("A{$row}:H{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Утверждена постановлением Госкомстата России от 11.11.99 № 100');
        $sheet->mergeCells("A{$row}:H{$row}");
        $row += 2;
        
        $project = $journal->project;
        $sheet->setCellValue("A{$row}", 'Объект: ' . ($project->name ?? ''));
        $sheet->mergeCells("A{$row}:H{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Журнал № ' . ($journal->journal_number ?? $journal->id));
        $sheet->mergeCells("A{$row}:H{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", "Период: с {$from->format('d.m.Y')} по {$to->format('d.m.Y')}");
        $sheet->mergeCells("A{$row}:H{$row}");
        $row += 2;
        
        // Заголовки таблицы
        $sheet->setCellValue("A{$row}", '№ записи');
        $sheet->setCellValue("B{$row}", 'Дата');
        $sheet->setCellValue("C{$row}", 'Описание работ');
        $sheet->setCellValue("D{$row}", 'Объем работ');
        $sheet->setCellValue("E{$row}", 'Рабочие');
        $sheet->setCellValue("F{$row}", 'Оборудование');
        $sheet->setCellValue("G{$row}", 'Погодные условия');
        $sheet->setCellValue("H{$row}", 'Статус');
    }

    protected function setKS6Items($sheet, \App\Models\ConstructionJournal $journal, \Carbon\Carbon $from, \Carbon\Carbon $to): void
    {
        $startRow = $sheet->getHighestRow() + 1;
        $row = $startRow;

        $entries = $journal->entries()
            ->whereBetween('entry_date', [$from, $to])
            ->with(['workVolumes', 'workers', 'equipment', 'createdBy'])
            ->orderBy('entry_date')
            ->orderBy('entry_number')
            ->get();

        foreach ($entries as $entry) {
            $sheet->setCellValue("A{$row}", $entry->entry_number);
            $sheet->setCellValue("B{$row}", $entry->entry_date->format('d.m.Y'));
            $sheet->setCellValue("C{$row}", $entry->work_description);
            
            $volumesText = $entry->workVolumes->map(function ($v) {
                return $v->quantity . ' ' . ($v->measurementUnit?->short_name ?? '');
            })->implode(', ');
            $sheet->setCellValue("D{$row}", $volumesText);
            
            $workersText = $entry->workers->map(function ($w) {
                return $w->specialty . ': ' . $w->workers_count;
            })->implode(', ');
            $sheet->setCellValue("E{$row}", $workersText);
            
            $equipmentText = $entry->equipment->map(function ($e) {
                return $e->equipment_name;
            })->implode(', ');
            $sheet->setCellValue("F{$row}", $equipmentText);
            
            $weather = $entry->weather_conditions;
            $weatherText = $weather ? ($weather['temperature'] ?? '') . '°C, ' . ($weather['precipitation'] ?? '') : '';
            $sheet->setCellValue("G{$row}", $weatherText);
            
            $sheet->setCellValue("H{$row}", $entry->status->label());
            
            $row++;
        }
    }

    protected function setKS6Footer($sheet, \App\Models\ConstructionJournal $journal): void
    {
        $lastRow = $sheet->getHighestRow();
        $row = $lastRow + 2;

        $sheet->setCellValue("A{$row}", 'Ответственный за ведение журнала:');
        $row++;
        $sheet->setCellValue("A{$row}", $journal->createdBy?->name ?? '');
        $sheet->setCellValue("D{$row}", '_______________');
        $sheet->setCellValue("E{$row}", 'подпись');
    }

    protected function applyKS6Styles($sheet): void
    {
        $highestRow = $sheet->getHighestRow();
        
        // Заголовок
        $sheet->getStyle('A1:H1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1:H1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Заголовки таблицы
        $headerRow = 8;
        $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        
        // Данные
        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $sheet->getStyle("A{$row}:H{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("A{$row}:H{$row}")->getAlignment()->setWrapText(true);
        }
        
        // Ширина колонок
        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(40);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(20);
        $sheet->getColumnDimension('G')->setWidth(15);
        $sheet->getColumnDimension('H')->setWidth(15);
    }

    protected function prepareKS6Data(\App\Models\ConstructionJournal $journal, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        $entries = $journal->entries()
            ->whereBetween('entry_date', [$from, $to])
            ->with(['workVolumes.estimateItem', 'workers', 'equipment', 'materials', 'createdBy', 'approvedBy'])
            ->orderBy('entry_date')
            ->orderBy('entry_number')
            ->get();

        return [
            'journal' => $journal->load('project', 'contract', 'createdBy'),
            'entries' => $entries,
            'period_from' => $from,
            'period_to' => $to,
        ];
    }

    protected function setExtendedReportHeader($sheet, \App\Models\ConstructionJournal $journal, \Carbon\Carbon $from, \Carbon\Carbon $to): void
    {
        $row = 1;
        $sheet->setCellValue("A{$row}", 'РАСШИРЕННЫЙ ОТЧЕТ ПО ЖУРНАЛУ РАБОТ');
        $sheet->mergeCells("A{$row}:J{$row}");
        $row += 2;
        
        $sheet->setCellValue("A{$row}", 'Проект: ' . ($journal->project->name ?? ''));
        $sheet->mergeCells("A{$row}:J{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", "Период: с {$from->format('d.m.Y')} по {$to->format('d.m.Y')}");
        $sheet->mergeCells("A{$row}:J{$row}");
        $row += 2;
    }

    protected function setExtendedReportData($sheet, \App\Models\ConstructionJournal $journal, \Carbon\Carbon $from, \Carbon\Carbon $to, array $options): void
    {
        $row = $sheet->getHighestRow() + 1;
        
        $entries = $journal->entries()
            ->whereBetween('entry_date', [$from, $to])
            ->approved()
            ->with(['workVolumes', 'workers', 'equipment', 'materials'])
            ->get();

        // Сводная статистика
        $sheet->setCellValue("A{$row}", 'СВОДНАЯ СТАТИСТИКА');
        $sheet->mergeCells("A{$row}:J{$row}");
        $row += 2;
        
        $totalEntries = $entries->count();
        $totalWorkers = $entries->sum(function ($e) { return $e->workers->sum('workers_count'); });
        $totalWorkHours = $entries->sum(function ($e) { return $e->workers->sum('hours_worked'); });
        
        $sheet->setCellValue("A{$row}", 'Всего записей:');
        $sheet->setCellValue("B{$row}", $totalEntries);
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Всего рабочих:');
        $sheet->setCellValue("B{$row}", $totalWorkers);
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Всего человеко-часов:');
        $sheet->setCellValue("B{$row}", $totalWorkHours);
        $row += 2;
        
        // Детализация по объемам
        if ($options['include_materials'] ?? true) {
            $this->addMaterialsSummary($sheet, $row, $entries);
        }
    }

    protected function addMaterialsSummary($sheet, &$row, $entries): void
    {
        $sheet->setCellValue("A{$row}", 'ИСПОЛЬЗОВАННЫЕ МАТЕРИАЛЫ');
        $sheet->mergeCells("A{$row}:D{$row}");
        $row++;
        
        $sheet->setCellValue("A{$row}", 'Материал');
        $sheet->setCellValue("B{$row}", 'Количество');
        $sheet->setCellValue("C{$row}", 'Ед. изм.');
        $sheet->setCellValue("D{$row}", 'Записей');
        $row++;
        
        $materialsSummary = [];
        foreach ($entries as $entry) {
            foreach ($entry->materials as $material) {
                $key = $material->material_name . '_' . $material->measurement_unit;
                if (!isset($materialsSummary[$key])) {
                    $materialsSummary[$key] = [
                        'name' => $material->material_name,
                        'quantity' => 0,
                        'unit' => $material->measurement_unit,
                        'count' => 0,
                    ];
                }
                $materialsSummary[$key]['quantity'] += $material->quantity;
                $materialsSummary[$key]['count']++;
            }
        }
        
        foreach ($materialsSummary as $material) {
            $sheet->setCellValue("A{$row}", $material['name']);
            $sheet->setCellValue("B{$row}", $material['quantity']);
            $sheet->setCellValue("C{$row}", $material['unit']);
            $sheet->setCellValue("D{$row}", $material['count']);
            $row++;
        }
        
        $row += 2;
    }

    protected function applyExtendedReportStyles($sheet): void
    {
        $highestRow = $sheet->getHighestRow();
        
        $sheet->getStyle('A1:J1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1:J1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        for ($row = 1; $row <= $highestRow; $row++) {
            $sheet->getStyle("A{$row}:J{$row}")->getAlignment()->setWrapText(true);
        }
        
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(10);
    }
}
