<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemplateService
{
    private const TEMPLATE_MARKER = 'PROHELPER_TEMPLATE_V1';

    public function generate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Импорт сметы');

        // Скрытый маркер в ячейке A1, сама ячейка будет заголовком, но мы можем использовать метаданные или скрытую строку
        // Давайте запишем маркер в метаданные документа для надежности
        $spreadsheet->getProperties()
            ->setCreator('Prohelper')
            ->setLastModifiedBy('Prohelper')
            ->setTitle('Estimate Import Template')
            ->setSubject('Estimate Import Template')
            ->setDescription(self::TEMPLATE_MARKER);

        $headers = [
            'A1' => 'Тип (Раздел/Позиция)*',
            'B1' => 'Номер*',
            'C1' => 'Наименование*',
            'D1' => 'Обоснование (Код)',
            'E1' => 'Ед. изм.',
            'F1' => 'Кол-во',
            'G1' => 'Цена (ед.)',
            'H1' => 'НР (%)',
            'I1' => 'СП (%)',
            'J1' => 'Комментарий'
        ];

        // Стилизация заголовка
        $headerRange = 'A1:J1';
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }

        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4F81BD'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        // Автоматическая ширина колонок
        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Пример данных (закомментирован или просто пустые строки)
        $sheet->setCellValue('A2', 'Раздел');
        $sheet->setCellValue('B2', '1');
        $sheet->setCellValue('C2', 'Земляные работы');

        $sheet->setCellValue('A3', 'Позиция');
        $sheet->setCellValue('B3', '1.1');
        $sheet->setCellValue('C3', 'Разработка грунта');
        $sheet->setCellValue('E3', 'м3');
        $sheet->setCellValue('F3', '100');
        $sheet->setCellValue('G3', '500');

        $writer = new Xlsx($spreadsheet);

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="prohelper_estimate_template.xlsx"',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
