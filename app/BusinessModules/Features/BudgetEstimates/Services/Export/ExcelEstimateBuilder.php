<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Export;

use App\Models\Estimate;
use App\Services\Export\ExportValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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
        $spreadsheet = (new Spreadsheet())->setValueBinder(new ExportValueBinder());
        $spreadsheet->getProperties()->setDescription('MOST_ESTIMATE_EXPORT_V1');
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // Create main estimate sheet
        $mainSheet = $spreadsheet->getActiveSheet();
        $mainSheet->setTitle('Смета');
        $this->buildMainSheet($mainSheet, $data, $options);

        // Create hidden metadata sheet
        $metadataSheet = $spreadsheet->createSheet();
        $metadataSheet->setTitle('_METADATA_');
        $this->buildMetadataSheet($metadataSheet, $data, (bool) $options['show_prices']);
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

    protected function buildMainSheet(Worksheet $sheet, array $data, array $options): void
    {
        $sheet->setShowGridlines(false);
        $sheet->setPrintGridlines(false);
        $sheet->getDefaultRowDimension()->setRowHeight(16);
        $this->applyColumnWidths($sheet, (bool) $options['show_prices']);
        $row = $this->buildEstimateInfo($sheet, 1, $data);
        $row = $this->buildItemsTable($sheet, $row, $data, $options);
        $closingRow = $row;
        if ($options['show_prices']) {
            $row = $this->buildTotals($sheet, $row, $data);
        }
        $this->buildSignatures($sheet, $row, $options);
        if ($closingRow > 45) {
            $sheet->setBreak('A'.($closingRow - 1), Worksheet::BREAK_ROW);
        }
        $sheet->getPageMargins()->setTop(0.3937)->setBottom(0.3937)
            ->setLeft(0.3937)->setRight(0.3937)->setHeader(0.19685)->setFooter(0.19685);
        $number = str_replace('&', '&&', (string) ($data['estimate']['number'] ?? ''));
        $sheet->getHeaderFooter()->setOddFooter('&L&"Arial,Regular"&8Смета № '.$number.'&R&"Arial,Regular"&8Страница &P из &N');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToPage(true)
            ->setFitToWidth(1)
            ->setFitToHeight($this->fitsShortPrintPage($sheet) ? 1 : 0)
            ->setPrintArea('A1:H'.$sheet->getHighestRow());
    }

    protected function buildEstimateInfo(Worksheet $sheet, int $row, array $data): int
    {
        $estimate = $data['estimate'];
        $sheet->setCellValue("A{$row}", 'ЛОКАЛЬНЫЙ СМЕТНЫЙ РАСЧЕТ');
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $sheet->getRowDimension(++$row)->setRowHeight(6);
        $row++;
        $info = [
            ['Номер сметы:', $estimate['number'] ?? 'Не указан'],
            ['Наименование:', $estimate['name'] ?? ''],
            ['Дата составления:', $estimate['estimate_date'] ?? ''],
            ['Организация:', $estimate['organization']['legal_name'] ?? $estimate['organization']['name'] ?? ''],
        ];
        if ($estimate['project']) {
            $info[] = ['Проект:', $estimate['project']['name']];
            if (! empty($estimate['project']['address'])) {
                $info[] = ['Адрес объекта:', $estimate['project']['address']];
            }
        }
        if ($estimate['contract']) {
            $info[] = ['Договор:', $estimate['contract']['number'].' - '.$estimate['contract']['name']];
        }
        $info[] = ['Статус:', $this->formatStatus($estimate['status'])];
        foreach ($info as $line) {
            $sheet->setCellValue("A{$row}", $line[0]);
            $sheet->mergeCells("A{$row}:B{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->setCellValue("C{$row}", $line[1]);
            $sheet->mergeCells("C{$row}:H{$row}");
            $sheet->getStyle("A{$row}:H{$row}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(max(16, (int) ceil(mb_strwidth((string) $line[1], 'UTF-8') / 100) * 13));
            $row++;
        }
        $sheet->getRowDimension($row)->setRowHeight(6);

        return $row + 1;
    }

    protected function buildItemsTable(Worksheet $sheet, int $row, array $data, array $options): int
    {
        $headerRow = $row;
        $headers = ['№', 'Код', 'Наименование', 'Ед.изм.', 'Кол-во'];
        if ($options['show_prices']) {
            $headers[] = 'Цена';
            $headers[] = 'Сумма';
        }
        $headers[] = 'Примечание';
        $column = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue("{$column}{$row}", $header);
            $column++;
        }
        $lastColumn = chr(ord('A') + count($headers) - 1);
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '000000']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '808080']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(26);
        $sheet->freezePane('D'.($row + 1));
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $row++;
        foreach ($data['sections'] as $section) {
            $row = $this->buildSection($sheet, $row, $section, $options, count($headers));
        }

        return $row;
    }

    protected function buildSection(Worksheet $sheet, int $row, array $section, array $options, int $colCount): int
    {
        $name = $section['full_section_number'].'. '.$section['name'];
        $lastColumn = chr(ord('A') + $colCount - 1);
        $sheet->setCellValue("A{$row}", $name);
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '000000']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
            'alignment' => ['wrapText' => true],
            'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '808080']]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(max(18, (int) ceil(mb_strwidth($name, 'UTF-8') / 130) * 13));
        $row++;
        foreach ($section['items'] as $item) {
            $row = $this->buildItem($sheet, $row, $item, $options, 0);
        }
        if ($options['show_prices'] && $section['section_total_amount'] > 0) {
            $sheet->setCellValue("A{$row}", 'ИТОГО ПО РАЗДЕЛУ:');
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("G{$row}", $section['section_total_amount']);
            $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '000000']],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '808080']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            ]);
            $sheet->getStyle("G{$row}")->getAlignment()->setShrinkToFit(true);
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;
        }
        $sheet->getRowDimension($row)->setRowHeight(6);

        return $row + 1;
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
            $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode('#,##0.00##########');
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
            'alignment' => [
                'wrapText' => true,
                'vertical' => Alignment::VERTICAL_TOP,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC'],
                ],
            ],
        ]);

        $numericLastColumn = $options['show_prices'] ? 'G' : 'E';
        $sheet->getStyle("E{$row}:{$numericLastColumn}{$row}")->getAlignment()
            ->setWrapText(false)
            ->setShrinkToFit(true);
        $row++;

        // Add child items (materials, machinery, labor)
        if (!empty($item['child_items'])) {
            foreach ($item['child_items'] as $childItem) {
                $row = $this->buildItem($sheet, $row, $childItem, $options, $indent + 1);
            }
        }

        return $row;
    }

    protected function buildTotals(Worksheet $sheet, int $row, array $data): int
    {
        $totals = $data['totals'];
        $sheet->setCellValue("A{$row}", 'ИТОГОВАЯ СВОДКА');
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->getRowDimension($row)->setRowHeight(18);
        $row++;
        $lines = [
            ['Прямые затраты:', $totals['total_direct_costs']],
            ['Накладные расходы ('.number_format($totals['overhead_rate'], 2).'%):', $totals['total_overhead_costs']],
            ['Сметная прибыль ('.number_format($totals['profit_rate'], 2).'%):', $totals['total_estimated_profit']],
            ...((float) ($totals['contingency_amount'] ?? 0) === 0.0 ? [] : [['Резерв на непредвиденные расходы:', $totals['contingency_amount']]]),
            ['ИТОГО без НДС:', $totals['total_amount']],
            [($totals['vat_calculation_method'] ?? null) === \App\BusinessModules\Features\BudgetEstimates\Services\EstimateSourceVatCalculator::METHOD
                ? 'НДС по подтверждённым источникам:' : 'НДС ('.number_format($totals['vat_rate'], 0).'%):', $totals['vat_amount']],
            ['ВСЕГО С НДС:', $totals['total_amount_with_vat']],
        ];
        foreach ($lines as $index => $line) {
            $last = $index === count($lines) - 1;
            $sheet->setCellValue("A{$row}", $line[0]);
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("G{$row}", $line[1]);
            $sheet->mergeCells("G{$row}:H{$row}");
            $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                'font' => ['bold' => $last, 'size' => $last ? 11 : 10, 'color' => ['rgb' => '000000']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                'borders' => ['bottom' => ['borderStyle' => $last ? Border::BORDER_DOUBLE : Border::BORDER_THIN, 'color' => ['rgb' => 'B0B0B0']]],
            ]);
            $sheet->getStyle("G{$row}")->getAlignment()->setShrinkToFit(true);
            $sheet->getRowDimension($row)->setRowHeight($last ? 20 : 16);
            $row++;
        }

        return $row;
    }

    protected function buildSignatures(Worksheet $sheet, int $row, array $options): int
    {
        $sheet->getRowDimension($row)->setRowHeight(8);
        $row++;
        $sheet->setCellValue("A{$row}", 'ПОДПИСИ ОТВЕТСТВЕННЫХ ЛИЦ');
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->getRowDimension($row)->setRowHeight(18);
        $row++;
        foreach ($options['signature_fields'] as $field) {
            $sheet->setCellValue("A{$row}", $field.':');
            $sheet->mergeCells("A{$row}:B{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true);
            $sheet->setCellValue("C{$row}", '________________ (должность, ФИО) __________ (подпись) «___» __________ 20___ г.');
            $sheet->mergeCells("C{$row}:H{$row}");
            $sheet->getStyle("C{$row}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(max(22, (int) ceil(mb_strwidth($field.':', 'UTF-8') / 25) * 13));
            $row++;
        }

        return $row;
    }

    /**
     * Построить скрытый лист с метаданными
     */
    protected function buildMetadataSheet(Worksheet $sheet, array $data, bool $showPrices = true): void
    {
        // Prepare full metadata with all sections and items
        $metadata = [
            'prohelper_export' => true,
            'version' => '2.0',
            'estimate_id' => $data['estimate']['id'],
            'organization_id' => $data['metadata']['organization_id'],
            'export_date' => $data['metadata']['export_date'],
            'calculation_settings' => $showPrices ? $data['metadata']['calculation_settings'] : [],
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
                'section_total_amount' => $showPrices ? $section['section_total_amount'] : 0,
            ];

            // Collect items recursively
            $this->collectItemsForMetadata($section['items'], $metadata['items'], $showPrices);
        }

        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (mb_strlen($json, 'UTF-8') <= 32767 && strlen(mb_convert_encoding($json, 'UTF-16LE', 'UTF-8')) <= 65534) {
            $sheet->setCellValueExplicit('A1', $json, DataType::TYPE_STRING);
        } else {
            $offset = 0;
            $row = 2;
            $byteLength = strlen($json);
            while ($offset < $byteLength) {
                $chunk = mb_strcut($json, $offset, 30000, 'UTF-8');
                $sheet->setCellValueExplicit('A'.$row++, $chunk, DataType::TYPE_STRING);
                $offset += strlen($chunk);
            }
            $sheet->setCellValueExplicit('A1', json_encode([
                'prohelper_export' => true,
                'version' => '3.0',
                'encoding' => 'utf8-json-chunks',
                'chunk_count' => $row - 2,
                'byte_length' => $byteLength,
                'sha256' => hash('sha256', $json),
            ], JSON_THROW_ON_ERROR), DataType::TYPE_STRING);
        }
        $sheet->getColumnDimension('A')->setWidth(150);
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true);
    }

    /**
     * Собрать позиции для метаданных рекурсивно
     */
    protected function collectItemsForMetadata(array $items, array &$metadataItems, bool $showPrices = true): void
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

            if (! $showPrices) {
                foreach (['unit_price', 'base_unit_price', 'current_unit_price', 'direct_costs', 'materials_cost', 'machinery_cost', 'labor_cost', 'overhead_amount', 'profit_amount', 'total_amount'] as $field) {
                    $itemMeta[$field] = 0;
                }
                foreach (['applied_coefficients', 'coefficient_total', 'custom_resources', 'metadata'] as $field) {
                    $itemMeta[$field] = null;
                }
            }

            $metadataItems[] = $itemMeta;

            // Recursively add child items
            if (!empty($item['child_items'])) {
                $this->collectItemsForMetadata($item['child_items'], $metadataItems, $showPrices);
            }
        }
    }

    protected function applyColumnWidths(Worksheet $sheet, bool $showPrices = true): void
    {
        foreach (['A' => 5, 'B' => 20, 'C' => 50, 'D' => 8, 'E' => 12, 'F' => 16, 'G' => 18, 'H' => 26] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        if (! $showPrices) {
            $sheet->getColumnDimension('F')->setWidth(26);
            $sheet->getColumnDimension('G')->setVisible(false);
            $sheet->getColumnDimension('H')->setVisible(false);
        }
    }

    private function fitsShortPrintPage(Worksheet $sheet): bool
    {
        $lastRow = $sheet->getHighestRow();
        if ($lastRow > 45) {
            return false;
        }
        for ($row = 1; $row <= $lastRow; $row++) {
            foreach (['B', 'C', 'D', 'F', 'H'] as $column) {
                $value = (string) $sheet->getCell($column.$row)->getValue();
                if (mb_strwidth($value, 'UTF-8') > $sheet->getColumnDimension($column)->getWidth() * 2) {
                    return false;
                }
            }
        }

        return true;
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
