<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Export;

use App\Models\Estimate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelEstimateBuilder
{
    /**
     * Построить Excel файл сметы
     *
     * @param Estimate $estimate
     * @param array $data
     * @param array $options
     * @return string Path to generated file
     */
    public function build(Estimate $estimate, array $data, array $options): string
    {
        $spreadsheet = new Spreadsheet();

        // Create main estimate sheet
        $mainSheet = $spreadsheet->getActiveSheet();
        $mainSheet->setTitle('Смета');
        $this->buildMainSheet($mainSheet, $data, $options);

        // Create hidden metadata sheet
        $metadataSheet = $spreadsheet->createSheet();
        $metadataSheet->setTitle('_METADATA_');
        $this->buildMetadataSheet($metadataSheet, $data);
        $metadataSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        // Save to file
        $filename = $this->generateFilename($estimate);
        $tempPath = storage_path("app/temp/{$filename}");

        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return $tempPath;
    }

    /**
     * Построить основной лист сметы
     */
    protected function buildMainSheet(Worksheet $sheet, array $data, array $options): void
    {
        $row = 1;

        // Header with Prohelper branding
        $row = $this->buildHeader($sheet, $row, $data);

        // Estimate info section
        $row = $this->buildEstimateInfo($sheet, $row, $data);

        // Main table with items
        $row = $this->buildItemsTable($sheet, $row, $data, $options);

        // Totals section
        $row = $this->buildTotals($sheet, $row, $data);

        // Signature fields
        $row = $this->buildSignatures($sheet, $row, $options);

        // Apply column widths
        $this->applyColumnWidths($sheet);
    }

    /**
     * Построить шапку с брендингом Prohelper
     */
    protected function buildHeader(Worksheet $sheet, int $row, array $data): int
    {
        // Prohelper branding
        $sheet->setCellValue("A{$row}", 'Prohelper');
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 16,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4A90E2'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(30);
        $row++;

        $row++; // Empty row

        return $row;
    }

    /**
     * Построить секцию информации о смете
     */
    protected function buildEstimateInfo(Worksheet $sheet, int $row, array $data): int
    {
        $estimate = $data['estimate'];

        // Estimate title
        $sheet->setCellValue("A{$row}", 'ЛОКАЛЬНЫЙ СМЕТНЫЙ РАСЧЕТ');
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $row++;

        $row++; // Empty row

        // Estimate details
        $info = [
            ['Номер сметы:', $estimate['number'] ?? 'Не указан'],
            ['Наименование:', $estimate['name'] ?? ''],
            ['Дата составления:', $estimate['estimate_date'] ?? ''],
            ['Организация:', $estimate['organization']['legal_name'] ?? $estimate['organization']['name'] ?? ''],
        ];

        if ($estimate['project']) {
            $info[] = ['Проект:', $estimate['project']['name']];
            $info[] = ['Адрес объекта:', $estimate['project']['address'] ?? ''];
        }

        if ($estimate['contract']) {
            $info[] = ['Договор:', $estimate['contract']['number'] . ' - ' . $estimate['contract']['name']];
        }

        $info[] = ['Статус:', $this->formatStatus($estimate['status'])];

        foreach ($info as $line) {
            $sheet->setCellValue("A{$row}", $line[0]);
            $sheet->setCellValue("B{$row}", $line[1]);
            $sheet->mergeCells("B{$row}:H{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }

        $row++; // Empty row

        return $row;
    }

    /**
     * Построить таблицу позиций
     */
    protected function buildItemsTable(Worksheet $sheet, int $row, array $data, array $options): int
    {
        // Table header
        $headerRow = $row;
        $headers = ['№', 'Код', 'Наименование', 'Ед.изм.', 'Кол-во'];

        if ($options['show_prices']) {
            $headers[] = 'Цена';
            $headers[] = 'Сумма';
        }

        $headers[] = 'Примечание';

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue("{$col}{$row}", $header);
            $col++;
        }

        // Style header
        $lastCol = chr(ord('A') + count($headers) - 1);
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E8E8E8'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $row++;

        // Add sections and items
        foreach ($data['sections'] as $section) {
            $row = $this->buildSection($sheet, $row, $section, $options, count($headers));
        }

        return $row;
    }

    /**
     * Построить раздел сметы
     */
    protected function buildSection(Worksheet $sheet, int $row, array $section, array $options, int $colCount): int
    {
        // Section header
        $sectionName = $section['full_section_number'] . '. ' . $section['name'];
        $sheet->setCellValue("A{$row}", $sectionName);
        $lastCol = chr(ord('A') + $colCount - 1);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F5F5F5'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);
        $row++;

        // Add items
        foreach ($section['items'] as $item) {
            $row = $this->buildItem($sheet, $row, $item, $options, 0);
        }

        // Section total
        if ($options['show_prices'] && $section['section_total_amount'] > 0) {
            $sheet->setCellValue("A{$row}", 'ИТОГО ПО РАЗДЕЛУ:');
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("G{$row}", number_format($section['section_total_amount'], 2, '.', ' '));
            $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFFACD'],
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ]);
            $sheet->getStyle("G{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $row++;
        }

        $row++; // Empty row after section

        return $row;
    }

    /**
     * Построить позицию сметы
     */
    protected function buildItem(Worksheet $sheet, int $row, array $item, array $options, int $indent = 0): int
    {
        $col = 0;

        // Position number
        $sheet->setCellValueByColumnAndRow(++$col, $row, $item['position_number']);

        // Code
        $code = $item['normative_rate_code'] ?? '';
        if ($item['is_not_accounted']) {
            $code .= ' (Н)';
        }
        $sheet->setCellValueByColumnAndRow(++$col, $row, $code);

        // Name with indent
        $name = str_repeat('  ', $indent) . $item['name'];
        $sheet->setCellValueByColumnAndRow(++$col, $row, $name);

        // Measurement unit
        $sheet->setCellValueByColumnAndRow(++$col, $row, $item['measurement_unit'] ?? '');

        // Quantity
        $sheet->setCellValueByColumnAndRow(++$col, $row, $item['quantity_total']);
        $sheet->getStyleByColumnAndRow($col, $row)->getNumberFormat()->setFormatCode('#,##0.0000');
        $sheet->getStyleByColumnAndRow($col, $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        if ($options['show_prices']) {
            // Unit price
            $sheet->setCellValueByColumnAndRow(++$col, $row, $item['unit_price']);
            $sheet->getStyleByColumnAndRow($col, $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyleByColumnAndRow($col, $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            // Total amount
            $sheet->setCellValueByColumnAndRow(++$col, $row, $item['total_amount']);
            $sheet->getStyleByColumnAndRow($col, $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyleByColumnAndRow($col, $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        // Notes
        $notes = [];
        if ($item['description']) {
            $notes[] = $item['description'];
        }
        if ($options['include_coefficients'] && !empty($item['applied_coefficients'])) {
            $coeffStr = 'К: ' . implode(', ', array_map(
                fn($k, $v) => "{$k}={$v}",
                array_keys($item['applied_coefficients']),
                $item['applied_coefficients']
            ));
            $notes[] = $coeffStr;
        }
        $sheet->setCellValueByColumnAndRow(++$col, $row, implode('; ', $notes));

        // Apply borders
        $lastCol = $col;
        $sheet->getStyle("A{$row}:" . chr(ord('A') + $lastCol - 1) . "{$row}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC'],
                ],
            ],
        ]);

        $row++;

        // Add child items (materials, machinery, labor)
        if (!empty($item['child_items'])) {
            foreach ($item['child_items'] as $childItem) {
                $row = $this->buildItem($sheet, $row, $childItem, $options, $indent + 1);
            }
        }

        return $row;
    }

    /**
     * Построить итоговую секцию
     */
    protected function buildTotals(Worksheet $sheet, int $row, array $data): int
    {
        $totals = $data['totals'];
        $row++; // Empty row

        $sheet->setCellValue("A{$row}", 'ИТОГОВАЯ СВОДКА');
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $row++;

        $row++; // Empty row

        $totalLines = [
            ['Прямые затраты:', $totals['total_direct_costs']],
            ['Накладные расходы (' . number_format($totals['overhead_rate'], 2) . '%):', $totals['total_overhead_costs']],
            ['Сметная прибыль (' . number_format($totals['profit_rate'], 2) . '%):', $totals['total_estimated_profit']],
            ['ИТОГО без НДС:', $totals['total_amount']],
            ['НДС (' . number_format($totals['vat_rate'], 0) . '%):', $totals['vat_amount']],
            ['ВСЕГО С НДС:', $totals['total_amount_with_vat']],
        ];

        foreach ($totalLines as $index => $line) {
            $isLast = $index === count($totalLines) - 1;

            $sheet->setCellValue("F{$row}", $line[0]);
            $sheet->setCellValue("G{$row}", number_format($line[1], 2, '.', ' '));
            $sheet->mergeCells("G{$row}:H{$row}");

            $style = [
                'font' => ['bold' => $isLast],
                'borders' => [
                    'top' => ['borderStyle' => Border::BORDER_THIN],
                    'bottom' => ['borderStyle' => $isLast ? Border::BORDER_DOUBLE : Border::BORDER_THIN],
                ],
            ];

            if ($isLast) {
                $style['fill'] = [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFFACD'],
                ];
                $style['font']['size'] = 12;
            }

            $sheet->getStyle("F{$row}:H{$row}")->applyFromArray($style);
            $sheet->getStyle("F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("G{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $row++;
        }

        return $row;
    }

    /**
     * Построить блок подписей
     */
    protected function buildSignatures(Worksheet $sheet, int $row, array $options): int
    {
        $row += 2; // Empty rows

        foreach ($options['signature_fields'] as $field) {
            $sheet->setCellValue("A{$row}", $field . ':');
            $sheet->setCellValue("C{$row}", '_____________________ (ФИО) "___"_____________ 20___ г.');
            $sheet->mergeCells("C{$row}:H{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }

        return $row;
    }

    /**
     * Построить скрытый лист с метаданными
     */
    protected function buildMetadataSheet(Worksheet $sheet, array $data): void
    {
        // Prepare full metadata with all sections and items
        $metadata = [
            'prohelper_export' => true,
            'version' => '2.0',
            'estimate_id' => $data['estimate']['id'],
            'organization_id' => $data['metadata']['organization_id'],
            'export_date' => $data['metadata']['export_date'],
            'calculation_settings' => $data['metadata']['calculation_settings'],
            'sections' => [],
            'items' => [],
        ];

        // Collect all sections
        foreach ($data['sections'] as $section) {
            $metadata['sections'][] = [
                'id' => $section['id'],
                'parent_section_id' => $section['parent_section_id'],
                'section_number' => $section['section_number'],
                'full_section_number' => $section['full_section_number'],
                'name' => $section['name'],
                'description' => $section['description'],
                'is_summary' => $section['is_summary'],
                'section_total_amount' => $section['section_total_amount'],
            ];

            // Collect items recursively
            $this->collectItemsForMetadata($section['items'], $metadata['items']);
        }

        // Write JSON to cell A1
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $sheet->setCellValue('A1', $json);
        $sheet->getColumnDimension('A')->setWidth(150);
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true);
    }

    /**
     * Собрать позиции для метаданных рекурсивно
     */
    protected function collectItemsForMetadata(array $items, array &$metadataItems): void
    {
        foreach ($items as $item) {
            $itemMeta = [
                'id' => $item['id'],
                'section_id' => $item['section_id'],
                'parent_work_id' => $item['parent_work_id'],
                'catalog_item_id' => $item['catalog_item_id'],
                'item_type' => $item['item_type'],
                'position_number' => $item['position_number'],
                'name' => $item['name'],
                'description' => $item['description'] ?? null,
                'normative_rate_code' => $item['normative_rate_code'] ?? null,
                'work_type' => $item['work_type'] ?? null,
                'measurement_unit' => $item['measurement_unit'] ?? null,
                'quantity' => $item['quantity'],
                'quantity_total' => $item['quantity_total'],
                'unit_price' => $item['unit_price'] ?? 0,
                'base_unit_price' => $item['base_unit_price'] ?? 0,
                'current_unit_price' => $item['current_unit_price'] ?? 0,
                'direct_costs' => $item['direct_costs'] ?? 0,
                'materials_cost' => $item['materials_cost'] ?? 0,
                'machinery_cost' => $item['machinery_cost'] ?? 0,
                'labor_cost' => $item['labor_cost'] ?? 0,
                'overhead_amount' => $item['overhead_amount'] ?? 0,
                'profit_amount' => $item['profit_amount'] ?? 0,
                'total_amount' => $item['total_amount'] ?? 0,
                'applied_coefficients' => $item['applied_coefficients'] ?? null,
                'coefficient_total' => $item['coefficient_total'] ?? null,
                'custom_resources' => $item['custom_resources'] ?? null,
                'metadata' => $item['metadata'] ?? null,
                'is_manual' => $item['is_manual'],
                'is_not_accounted' => $item['is_not_accounted'],
            ];

            $metadataItems[] = $itemMeta;

            // Recursively add child items
            if (!empty($item['child_items'])) {
                $this->collectItemsForMetadata($item['child_items'], $metadataItems);
            }
        }
    }

    /**
     * Применить ширину колонок
     */
    protected function applyColumnWidths(Worksheet $sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(8);  // №
        $sheet->getColumnDimension('B')->setWidth(15); // Код
        $sheet->getColumnDimension('C')->setWidth(50); // Наименование
        $sheet->getColumnDimension('D')->setWidth(10); // Ед.изм.
        $sheet->getColumnDimension('E')->setWidth(12); // Кол-во
        $sheet->getColumnDimension('F')->setWidth(15); // Цена
        $sheet->getColumnDimension('G')->setWidth(15); // Сумма
        $sheet->getColumnDimension('H')->setWidth(30); // Примечание
    }

    /**
     * Сгенерировать имя файла
     */
    protected function generateFilename(Estimate $estimate): string
    {
        $number = $estimate->number ?? $estimate->id;
        $date = now()->format('Ymd_His');
        return "Smeta_Prohelper_{$number}_{$date}.xlsx";
    }

    /**
     * Форматировать статус
     */
    protected function formatStatus(string $status): string
    {
        return match ($status) {
            'draft' => 'Черновик',
            'in_review' => 'На проверке',
            'approved' => 'Утверждена',
            'rejected' => 'Отклонена',
            'archived' => 'В архиве',
            default => $status,
        };
    }
}
