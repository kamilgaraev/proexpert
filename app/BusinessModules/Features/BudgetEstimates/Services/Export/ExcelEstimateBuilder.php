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
     * @return array ['content' => binary content, 'filename' => filename]
     */
    public function build(Estimate $estimate, array $data, array $options): array
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

        // ВАЖНО: Устанавливаем активным первый лист (Смета), а не метаданные
        $spreadsheet->setActiveSheetIndex(0);

        // Generate content in memory
        $writer = new Xlsx($spreadsheet);
        
        // Save to temporary stream
        $tempFile = tempnam(sys_get_temp_dir(), 'estimate_');
        $writer->save($tempFile);
        $content = file_get_contents($tempFile);
        unlink($tempFile);

        $filename = $this->generateFilename($estimate);

        return [
            'content' => $content,
            'filename' => $filename,
        ];
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
        // Prohelper branding - основной заголовок
        $sheet->setCellValue("A{$row}", 'PROHELPER');
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 20,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '667EEA'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(35);
        $row++;

        // Подзаголовок
        $sheet->setCellValue("A{$row}", 'Система управления проектами и смет');
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => [
                'italic' => true,
                'size' => 10,
                'color' => ['rgb' => '718096'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(18);
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
            'font' => [
                'bold' => true,
                'size' => 16,
                'color' => ['rgb' => '2D3748'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THICK,
                    'color' => ['rgb' => '667EEA'],
                ],
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(30);
        $row++;

        $row += 2; // Empty rows

        // Estimate details
        $info = [
            ['Номер сметы:', $estimate['number'] ?? 'Не указан'],
            ['Наименование:', $estimate['name'] ?? ''],
            ['Дата составления:', $estimate['estimate_date'] ?? ''],
            ['Организация:', $estimate['organization']['legal_name'] ?? $estimate['organization']['name'] ?? ''],
        ];

        if ($estimate['project']) {
            $info[] = ['Проект:', $estimate['project']['name']];
            if (!empty($estimate['project']['address'])) {
                $info[] = ['Адрес объекта:', $estimate['project']['address']];
            }
        }

        if ($estimate['contract']) {
            $info[] = ['Договор:', $estimate['contract']['number'] . ' - ' . $estimate['contract']['name']];
        }

        $info[] = ['Статус:', $this->formatStatus($estimate['status'])];

        // Apply background to info section
        $startRow = $row;
        foreach ($info as $index => $line) {
            $sheet->setCellValue("A{$row}", $line[0]);
            $sheet->setCellValue("B{$row}", $line[1]);
            $sheet->mergeCells("B{$row}:H{$row}");
            
            $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $index % 2 === 0 ? 'F7FAFC' : 'FFFFFF'],
                ],
                'borders' => [
                    'bottom' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'E2E8F0'],
                    ],
                ],
            ]);
            
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => '4A5568'],
                ],
            ]);
            
            $sheet->getStyle("B{$row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('2D3748'));
            
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;
        }

        // Add border around info section
        $endRow = $row - 1;
        $sheet->getStyle("A{$startRow}:H{$endRow}")->applyFromArray([
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => 'E2E8F0'],
                ],
            ],
        ]);

        $row += 2; // Empty rows

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
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4A5568'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '2D3748'],
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(25);
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
            'font' => [
                'bold' => true,
                'size' => 11,
                'color' => ['rgb' => '2D3748'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EDF2F7'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => 'CBD5E0'],
                ],
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
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
                'font' => [
                    'bold' => true,
                    'size' => 11,
                    'color' => ['rgb' => '744210'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FEF5E7'],
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_MEDIUM,
                        'color' => ['rgb' => 'F6AD55'],
                    ],
                ],
            ]);
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("G{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($row)->setRowHeight(20);
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
        $col = 'A';

        // Position number
        $sheet->setCellValue("{$col}{$row}", $item['position_number']);
        $col++;

        // Code
        $code = $item['normative_rate_code'] ?? '';
        if ($item['is_not_accounted']) {
            $code .= ' (Н)';
        }
        $sheet->setCellValue("{$col}{$row}", $code);
        $col++;

        // Name with indent
        $name = str_repeat('  ', $indent) . $item['name'];
        $sheet->setCellValue("{$col}{$row}", $name);
        $col++;

        // Measurement unit
        $sheet->setCellValue("{$col}{$row}", $item['measurement_unit'] ?? '');
        $col++;

        // Quantity
        $sheet->setCellValue("{$col}{$row}", $item['quantity_total']);
        $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode('#,##0.0000');
        $sheet->getStyle("{$col}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $col++;

        if ($options['show_prices']) {
            // Unit price
            $sheet->setCellValue("{$col}{$row}", $item['unit_price']);
            $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("{$col}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $col++;

            // Total amount
            $sheet->setCellValue("{$col}{$row}", $item['total_amount']);
            $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("{$col}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $col++;
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
        $sheet->setCellValue("{$col}{$row}", implode('; ', $notes));

        // Apply borders - determine last column
        $lastCol = $col;
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
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
        $row += 2; // Empty rows

        // Заголовок секции
        $sheet->setCellValue("A{$row}", 'ИТОГОВАЯ СВОДКА');
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 14,
                'color' => ['rgb' => '2D3748'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F7FAFC'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => 'E2E8F0'],
                ],
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(30);
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
                'font' => [
                    'bold' => $isLast,
                    'size' => $isLast ? 13 : 11,
                    'color' => ['rgb' => $isLast ? 'FFFFFF' : '4A5568'],
                ],
                'borders' => [
                    'top' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CBD5E0'],
                    ],
                    'bottom' => [
                        'borderStyle' => $isLast ? Border::BORDER_DOUBLE : Border::BORDER_THIN,
                        'color' => ['rgb' => $isLast ? '667EEA' : 'CBD5E0'],
                    ],
                ],
            ];

            if ($isLast) {
                $style['fill'] = [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '667EEA'],
                ];
            }

            $sheet->getStyle("F{$row}:H{$row}")->applyFromArray($style);
            $sheet->getStyle("F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("G{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            
            if ($isLast) {
                $sheet->getRowDimension($row)->setRowHeight(25);
            }

            $row++;
        }

        return $row;
    }

    /**
     * Построить блок подписей
     */
    protected function buildSignatures(Worksheet $sheet, int $row, array $options): int
    {
        $row += 3; // Empty rows

        // Заголовок блока подписей
        $sheet->setCellValue("A{$row}", 'ПОДПИСИ ОТВЕТСТВЕННЫХ ЛИЦ');
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => '4A5568'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F7FAFC'],
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => 'E2E8F0'],
                ],
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(25);
        $row++;

        $row++; // Empty row

        foreach ($options['signature_fields'] as $field) {
            $sheet->setCellValue("A{$row}", $field . ':');
            $sheet->setCellValue("C{$row}", '_____________________________ (ФИО) "____"_____________ 20___ г.');
            $sheet->mergeCells("C{$row}:H{$row}");
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => '4A5568'],
                ],
            ]);
            $sheet->getStyle("C{$row}")->applyFromArray([
                'borders' => [
                    'bottom' => [
                        'borderStyle' => Border::BORDER_DOTTED,
                        'color' => ['rgb' => 'CBD5E0'],
                    ],
                ],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(22);
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
        // Устанавливаем минимальную ширину для основных колонок
        $sheet->getColumnDimension('A')->setWidth(8);  // №
        $sheet->getColumnDimension('B')->setWidth(15); // Код
        $sheet->getColumnDimension('C')->setWidth(60); // Наименование - увеличено
        $sheet->getColumnDimension('D')->setWidth(12); // Ед.изм.
        $sheet->getColumnDimension('E')->setWidth(14); // Кол-во
        $sheet->getColumnDimension('F')->setWidth(18); // Цена
        $sheet->getColumnDimension('G')->setWidth(18); // Сумма
        $sheet->getColumnDimension('H')->setWidth(35); // Примечание
        
        // Включаем автоподбор ширины для колонок с динамическим контентом
        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Сгенерировать имя файла
     */
    protected function generateFilename(Estimate $estimate): string
    {
        $number = $estimate->number ?? $estimate->id;
        $date = now()->format('d.m.Y');
        return "Смета_{$number}_{$date}.xlsx";
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
