<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Сервис экспорта платежных данных в Excel и PDF
 */
class PaymentExportService
{
    /**
     * Экспорт списка документов в Excel
     */
    public function exportDocumentsToExcel(Collection $documents, string $title = 'Платежные документы'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($title, 0, 31)); // Excel limit

        // Заголовок
        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells('A1:L1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Дата формирования
        $sheet->setCellValue('A2', 'Дата формирования: ' . now()->format('d.m.Y H:i'));
        $sheet->mergeCells('A2:L2');

        // Заголовки столбцов
        $row = 4;
        $headers = [
            'A' => '№ документа',
            'B' => 'Дата',
            'C' => 'Тип',
            'D' => 'Статус',
            'E' => 'Плательщик',
            'F' => 'Получатель',
            'G' => 'Сумма',
            'H' => 'Валюта',
            'I' => 'Оплачено',
            'J' => 'Остаток',
            'K' => 'Срок оплаты',
            'L' => 'Просрочка (дн)',
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue($col . $row, $header);
        }

        // Стиль заголовков
        $headerStyle = $sheet->getStyle('A' . $row . ':L' . $row);
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
        $headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Данные
        $row++;
        foreach ($documents as $document) {
            $sheet->setCellValue('A' . $row, $document->document_number);
            $sheet->setCellValue('B' . $row, $document->document_date->format('d.m.Y'));
            $sheet->setCellValue('C' . $row, $document->document_type->label());
            $sheet->setCellValue('D' . $row, $document->status->label());
            $sheet->setCellValue('E' . $row, $document->getPayerName());
            $sheet->setCellValue('F' . $row, $document->getPayeeName());
            $sheet->setCellValue('G' . $row, $document->amount);
            $sheet->setCellValue('H' . $row, $document->currency);
            $sheet->setCellValue('I' . $row, $document->paid_amount);
            $sheet->setCellValue('J' . $row, $document->remaining_amount);
            $sheet->setCellValue('K' . $row, $document->due_date?->format('d.m.Y') ?? '-');
            $sheet->setCellValue('L' . $row, $document->isOverdue() ? $document->getOverdueDays() : 0);

            // Подсветка просроченных
            if ($document->isOverdue()) {
                $sheet->getStyle('A' . $row . ':L' . $row)
                    ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC7CE');
            }

            $row++;
        }

        // Итоги
        $row++;
        $sheet->setCellValue('A' . $row, 'ИТОГО:');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $sheet->setCellValue('G' . $row, $documents->sum('amount'));
        $sheet->setCellValue('I' . $row, $documents->sum('paid_amount'));
        $sheet->setCellValue('J' . $row, $documents->sum('remaining_amount'));
        $sheet->getStyle('G' . $row . ':J' . $row)->getFont()->setBold(true);

        // Авто-размер колонок
        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Форматирование чисел
        $sheet->getStyle('G5:J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

        // Сохранение
        $writer = new Xlsx($spreadsheet);
        $filename = storage_path('app/temp/payments_export_' . time() . '.xlsx');
        
        if (!file_exists(dirname($filename))) {
            mkdir(dirname($filename), 0755, true);
        }

        $writer->save($filename);

        return $filename;
    }

    /**
     * Экспорт отчета Cash Flow в Excel
     */
    public function exportCashFlowToExcel(array $reportData): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cash Flow');

        // Заголовок
        $sheet->setCellValue('A1', 'Отчет Cash Flow (Движение денежных средств)');
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        
        $sheet->setCellValue('A2', 'Период: ' . $reportData['period']['from'] . ' - ' . $reportData['period']['to']);
        $sheet->mergeCells('A2:F2');

        // Сводка
        $row = 4;
        $sheet->setCellValue('A' . $row, 'Итоги за период:');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        
        $row++;
        $sheet->setCellValue('A' . $row, 'Поступления:');
        $sheet->setCellValue('B' . $row, $reportData['summary']['total_inflow']);
        $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        
        $row++;
        $sheet->setCellValue('A' . $row, 'Расходы:');
        $sheet->setCellValue('B' . $row, $reportData['summary']['total_outflow']);
        $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        
        $row++;
        $sheet->setCellValue('A' . $row, 'Чистый денежный поток:');
        $sheet->setCellValue('B' . $row, $reportData['summary']['net_cash_flow']);
        $sheet->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);
        $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

        // Ежедневный поток
        $row += 3;
        $sheet->setCellValue('A' . $row, 'Ежедневный поток:');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        
        $row++;
        $headers = ['Дата', 'День недели', 'Поступления', 'Расходы', 'Чистый поток', 'Баланс'];
        foreach ($headers as $index => $header) {
            $col = chr(65 + $index); // A, B, C...
            $sheet->setCellValue($col . $row, $header);
        }
        $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);
        
        $row++;
        foreach ($reportData['daily'] as $day) {
            $sheet->setCellValue('A' . $row, $day['date']);
            $sheet->setCellValue('B' . $row, $day['day_of_week']);
            $sheet->setCellValue('C' . $row, $day['inflow']);
            $sheet->setCellValue('D' . $row, $day['outflow']);
            $sheet->setCellValue('E' . $row, $day['net_flow']);
            $sheet->setCellValue('F' . $row, $day['running_balance']);
            $row++;
        }

        // Авто-размер
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = storage_path('app/temp/cash_flow_' . time() . '.xlsx');
        $writer->save($filename);

        return $filename;
    }

    /**
     * Экспорт платежного поручения в PDF
     */
    public function exportPaymentOrderToPdf(PaymentDocument $document): string
    {
        $html = view('payments.payment_order_pdf', ['document' => $document])->render();
        
        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('A4', 'portrait');
        
        $filename = storage_path('app/temp/payment_order_' . $document->document_number . '_' . time() . '.pdf');
        
        if (!file_exists(dirname($filename))) {
            mkdir(dirname($filename), 0755, true);
        }
        
        $pdf->save($filename);

        return $filename;
    }

    /**
     * Экспорт реестра платежей в формате 1С
     */
    public function exportPaymentRegistry1C(Collection $documents): string
    {
        $content = $this->generatePaymentRegistry1CFormat($documents);
        
        $filename = storage_path('app/temp/payment_registry_1c_' . time() . '.txt');
        
        if (!file_exists(dirname($filename))) {
            mkdir(dirname($filename), 0755, true);
        }
        
        file_put_contents($filename, $content);

        return $filename;
    }

    /**
     * Генерация формата 1С
     */
    private function generatePaymentRegistry1CFormat(Collection $documents): string
    {
        $lines = [];
        $lines[] = "1CClientBankExchange";
        $lines[] = "ВерсияФормата=1.03";
        $lines[] = "Кодировка=Windows";
        $lines[] = "Отправитель=ProHelper";
        $lines[] = "ДатаСоздания=" . now()->format('d.m.Y');
        $lines[] = "ВремяСоздания=" . now()->format('H:i:s');
        $lines[] = "";

        foreach ($documents as $document) {
            $lines[] = "СекцияДокумент=Платежное поручение";
            $lines[] = "Номер=" . $document->document_number;
            $lines[] = "Дата=" . $document->document_date->format('d.m.Y');
            $lines[] = "Сумма=" . number_format($document->amount, 2, '.', '');
            $lines[] = "ПлательщикСчет=" . ($document->bank_account ?? '');
            $lines[] = "ПлательщикБИК=" . ($document->bank_bik ?? '');
            $lines[] = "Получатель=" . $document->getPayeeName();
            $lines[] = "НазначениеПлатежа=" . $document->payment_purpose;
            $lines[] = "КонецДокумента";
            $lines[] = "";
        }

        return implode("\r\n", $lines);
    }
}

