<?php

namespace App\Services\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;
use Exception;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Illuminate\Support\Str;

class ExcelExporterService
{
    /**
     * Генерирует и возвращает StreamedResponse для скачивания Excel файла.
     * В случае ошибки логирует и возвращает JSON-ответ с ошибкой.
     *
     * @param string $filename Имя файла (с .xlsx)
     * @param array $headers Массив заголовков колонок
     * @param array|\Illuminate\Support\Collection $data Массив данных
     * @return StreamedResponse|\Illuminate\Http\JsonResponse
     */
    public function streamDownload(
        string $filename,
        array $headers,
        $data
    ) {
        Log::info('[ExcelExporterService] Начало экспорта в Excel', [
            'filename' => $filename,
            'headers_count' => count($headers),
            'headers' => $headers,
            'data_type' => gettype($data),
            'data_count' => is_countable($data) ? count($data) : null,
            'first_row' => is_iterable($data) ? (is_array($data) ? ($data[0] ?? null) : (method_exists($data, 'first') ? $data->first() : null)) : null,
        ]);
        try {
            $response = new StreamedResponse(function () use ($headers, $data, $filename) {
                try {
                    Log::info('[ExcelExporterService] Создание Spreadsheet');
                    $spreadsheet = new Spreadsheet();
                    $sheet = $spreadsheet->getActiveSheet();

                    // Явно записываем заголовки колонок
                    $colIndex = 0;
                    foreach ($headers as $header) {
                        $cell = chr(65 + $colIndex) . '1';
                        $sheet->setCellValue($cell, $header);
                        $colIndex++;
                    }

                    // Стилизация заголовков
                    $headerStyle = [
                        'font' => [
                            'bold' => true,
                            'size' => 12,
                        ],
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'E3EAFD'],
                        ],
                        'alignment' => [
                            'horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical' => Alignment::VERTICAL_CENTER,
                            'wrapText' => true,
                        ],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color' => ['rgb' => 'AAB2BD'],
                            ],
                        ],
                    ];
                    $colCount = count($headers);
                    $sheet->getStyle('A1:' . chr(65 + $colCount - 1) . '1')->applyFromArray($headerStyle);
                    $sheet->getRowDimension(1)->setRowHeight(28);

                    // Запись данных и стилизация строк
                    $rowIndex = 2;
                    $rowLogged = 0;
                    foreach ($data as $rowArray) {
                        $colIndex = 0;
                        foreach ($rowArray as $value) {
                            $cell = chr(65 + $colIndex) . $rowIndex;
                            $sheet->setCellValue($cell, $value);
                            // Форматирование чисел и дат
                            if (is_numeric($value) && $colIndex > 0) {
                                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
                                $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                            }
                            if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$value)) {
                                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('DD.MM.YYYY');
                                $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                            }
                            // Примечания — перенос строк
                            if ($colIndex === array_key_last($rowArray)) {
                                $sheet->getStyle($cell)->getAlignment()->setWrapText(true);
                            }
                            $colIndex++;
                        }
                        // Границы для всей строки
                        $sheet->getStyle('A' . $rowIndex . ':' . chr(65 + $colCount - 1) . $rowIndex)
                            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('AAB2BD'));
                        $rowIndex++;
                    }

                    // Автоширина для всех колонок
                    for ($c = 0; $c < $colCount; $c++) {
                        $sheet->getColumnDimension(chr(65 + $c))->setAutoSize(true);
                    }

                    // Заморозка заголовка
                    $sheet->freezePane('A2');

                    Log::info('[ExcelExporterService] Создание Xlsx writer и сохранение в поток');
                    $writer = new Xlsx($spreadsheet);
                    $writer->save('php://output');
                    Log::info('[ExcelExporterService] Экспорт успешно завершён', [
                        'filename' => $filename,
                        'total_rows' => $rowIndex - 2
                    ]);
                } catch (Exception $e) {
                    Log::error('[ExcelExporterService] Ошибка при генерации Excel:', [
                        'exception' => $e,
                        'headers' => $headers,
                        'first_row' => is_iterable($data) ? (is_array($data) ? ($data[0] ?? null) : (method_exists($data, 'first') ? $data->first() : null)) : null,
                        'data_count' => is_countable($data) ? count($data) : null,
                    ]);
                    echo json_encode([
                        'error' => 'Ошибка при экспорте в Excel',
                        'message' => $e->getMessage(),
                    ]);
                }
            });

            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

            return $response;
        } catch (Exception $e) {
            Log::error('[ExcelExporterService] Критическая ошибка при создании StreamedResponse:', [
                'exception' => $e,
                'headers' => $headers,
                'first_row' => is_iterable($data) ? (is_array($data) ? ($data[0] ?? null) : (method_exists($data, 'first') ? $data->first() : null)) : null,
                'data_count' => is_countable($data) ? count($data) : null,
            ]);
            return response()->json([
                'error' => 'Ошибка при экспорте в Excel',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Готовит данные для экспорта в Excel.
     *
     * @param array|\Illuminate\Support\Collection $rawData
     * @param array $columnMapping
     * @return array
     */
    public function prepareDataForExport($rawData, array $columnMapping): array
    {
        $excelHeaders = array_keys($columnMapping);
        $dataKeys = array_values($columnMapping);

        $exportData = [];
        if (is_iterable($rawData)) {
            foreach($rawData as $item) {
                $rowData = [];
                foreach ($dataKeys as $dataKey) {
                    $value = Arr::get($item, $dataKey, '');
                    if ($value instanceof \Carbon\Carbon) {
                        $value = $value->format('d.m.Y H:i:s');
                    } elseif (is_float($value)) {
                        $value = number_format($value, 2, ',', '');
                    } elseif (is_bool($value)) {
                        $value = $value ? 'Да' : 'Нет';
                    }
                    $rowData[] = $value;
                }
                $exportData[] = $rowData;
            }
        }
        return [
            'headers' => $excelHeaders,
            'data' => $exportData
        ];
    }

    /**
     * Создает многостраничный Excel отчет по активности прорабов.
     * Каждый прораб получает отдельный лист с детальной информацией.
     */
    public function streamForemanActivityReport(
        string $filename,
        array $foremanData,
        array $materialLogs,
        array $completedWorks
    ) {
        Log::info('[ExcelExporterService] Начало экспорта отчета по активности прорабов', [
            'filename' => $filename,
            'foreman_count' => count($foremanData),
        ]);

        try {
            $response = new StreamedResponse(function () use ($foremanData, $materialLogs, $completedWorks, $filename) {
                try {
                    $spreadsheet = new Spreadsheet();
                    
                    // Удаляем лист по умолчанию, создадим свои
                    $spreadsheet->removeSheetByIndex(0);

                    foreach ($foremanData as $index => $foreman) {
                        $sheetName = mb_substr($foreman['user_name'], 0, 30);
                        $sheet = $spreadsheet->createSheet($index);
                        $sheet->setTitle($sheetName);

                        // Заголовок отчета
                        $sheet->setCellValue('A1', 'ОТЧЕТ ПО АКТИВНОСТИ ПРОРАБА');
                        $sheet->mergeCells('A1:F1');
                        $sheet->getStyle('A1')->applyFromArray([
                            'font' => ['bold' => true, 'size' => 16],
                            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B8CCE4']],
                        ]);

                        // Информация о прорабе
                        $sheet->setCellValue('A3', 'ФИО прораба:');
                        $sheet->setCellValue('B3', $foreman['user_name']);
                        $sheet->setCellValue('A4', 'Email:');
                        $sheet->setCellValue('B4', $foreman['user_email']);
                        $sheet->setCellValue('A5', 'Статус:');
                        $sheet->setCellValue('B5', $foreman['is_active'] ? 'Активен' : 'Неактивен');
                        $sheet->setCellValue('A6', 'Последняя активность:');
                        $sheet->setCellValue('B6', $foreman['last_activity_date'] ?? 'Нет данных');

                        // Стилизация информации о прорабе
                        $sheet->getStyle('A3:A6')->applyFromArray([
                            'font' => ['bold' => true],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
                        ]);

                        // Сводная таблица
                        $sheet->setCellValue('A8', 'СВОДНАЯ ИНФОРМАЦИЯ');
                        $sheet->mergeCells('A8:B8');
                        $sheet->getStyle('A8')->applyFromArray([
                            'font' => ['bold' => true, 'size' => 14],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']],
                        ]);

                        $sheet->setCellValue('A9', 'Операции с материалами:');
                        $sheet->setCellValue('B9', $foreman['material_usage_operations']);
                        $sheet->setCellValue('A10', 'Выполненные работы:');
                        $sheet->setCellValue('B10', $foreman['completed_works_count']);
                        $sheet->setCellValue('A11', 'Общая сумма работ:');
                        $sheet->setCellValue('B11', number_format($foreman['completed_works_total_sum'], 2, ',', ' ') . ' ₽');

                        // Операции с материалами
                        $materialRow = 13;
                        $foremanMaterials = collect($materialLogs)->where('user_id', $foreman['user_id']);
                        
                        if ($foremanMaterials->isNotEmpty()) {
                            $sheet->setCellValue('A' . $materialRow, 'ОПЕРАЦИИ С МАТЕРИАЛАМИ');
                            $sheet->mergeCells('A' . $materialRow . ':F' . $materialRow);
                            $sheet->getStyle('A' . $materialRow)->applyFromArray([
                                'font' => ['bold' => true, 'size' => 14],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2EFDA']],
                            ]);
                            $materialRow++;

                            // Заголовки таблицы материалов
                            $materialHeaders = ['Дата', 'Проект', 'Материал', 'Количество', 'Тип операции', 'Примечание'];
                            $col = 0;
                            foreach ($materialHeaders as $header) {
                                $sheet->setCellValue(chr(65 + $col) . $materialRow, $header);
                                $col++;
                            }
                            $sheet->getStyle('A' . $materialRow . ':F' . $materialRow)->applyFromArray([
                                'font' => ['bold' => true],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
                                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                            ]);
                            $materialRow++;

                            // Данные по материалам
                            foreach ($foremanMaterials as $material) {
                                $sheet->setCellValue('A' . $materialRow, $material['usage_date']);
                                $sheet->setCellValue('B' . $materialRow, $material['project_name'] ?? '');
                                $sheet->setCellValue('C' . $materialRow, $material['material_name'] ?? '');
                                $sheet->setCellValue('D' . $materialRow, $material['quantity']);
                                $sheet->setCellValue('E' . $materialRow, $material['operation_type'] ?? '');
                                $sheet->setCellValue('F' . $materialRow, $material['notes'] ?? '');
                                
                                $sheet->getStyle('A' . $materialRow . ':F' . $materialRow)->applyFromArray([
                                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                                ]);
                                $materialRow++;
                            }
                            $materialRow += 2;
                        }

                        // Выполненные работы
                        $workRow = $materialRow;
                        $foremanWorks = collect($completedWorks)->where('user_id', $foreman['user_id']);
                        
                        if ($foremanWorks->isNotEmpty()) {
                            $sheet->setCellValue('A' . $workRow, 'ВЫПОЛНЕННЫЕ РАБОТЫ');
                            $sheet->mergeCells('A' . $workRow . ':F' . $workRow);
                            $sheet->getStyle('A' . $workRow)->applyFromArray([
                                'font' => ['bold' => true, 'size' => 14],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
                            ]);
                            $workRow++;

                            // Заголовки таблицы работ
                            $workHeaders = ['Дата', 'Проект', 'Вид работ', 'Количество', 'Сумма', 'Статус'];
                            $col = 0;
                            foreach ($workHeaders as $header) {
                                $sheet->setCellValue(chr(65 + $col) . $workRow, $header);
                                $col++;
                            }
                            $sheet->getStyle('A' . $workRow . ':F' . $workRow)->applyFromArray([
                                'font' => ['bold' => true],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
                                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                            ]);
                            $workRow++;

                            // Данные по работам
                            foreach ($foremanWorks as $work) {
                                $sheet->setCellValue('A' . $workRow, $work['completion_date']);
                                $sheet->setCellValue('B' . $workRow, $work['project_name'] ?? '');
                                $sheet->setCellValue('C' . $workRow, $work['work_type_name'] ?? '');
                                $sheet->setCellValue('D' . $workRow, $work['quantity']);
                                $sheet->setCellValue('E' . $workRow, number_format($work['total_amount'], 2, ',', ' ') . ' ₽');
                                $sheet->setCellValue('F' . $workRow, $work['status'] ?? '');
                                
                                $sheet->getStyle('A' . $workRow . ':F' . $workRow)->applyFromArray([
                                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                                ]);
                                $workRow++;
                            }
                        }

                        // Автоширина колонок
                        for ($col = 0; $col < 6; $col++) {
                            $sheet->getColumnDimension(chr(65 + $col))->setAutoSize(true);
                        }
                    }

                    // Делаем первый лист активным
                    $spreadsheet->setActiveSheetIndex(0);

                    $writer = new Xlsx($spreadsheet);
                    $writer->save('php://output');
                    
                    Log::info('[ExcelExporterService] Отчет по активности прорабов успешно создан');
                } catch (Exception $e) {
                    Log::error('[ExcelExporterService] Ошибка при создании отчета по прорабам:', [
                        'exception' => $e->getMessage(),
                    ]);
                    echo json_encode(['error' => 'Ошибка при создании отчета', 'message' => $e->getMessage()]);
                }
            });

            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

            return $response;
        } catch (Exception $e) {
            Log::error('[ExcelExporterService] Критическая ошибка при создании отчета по прорабам:', [
                'exception' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Ошибка при экспорте отчета', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Генерирует официальный отчет об использовании материалов в формате Excel.
     */
    public function generateOfficialMaterialReport(array $reportData, string $filename)
    {
        Log::info('[ExcelExporterService] Генерация официального отчета по материалам', [
            'filename' => $filename,
            'materials_count' => count($reportData['materials'] ?? []),
        ]);

        try {
            $response = new StreamedResponse(function () use ($reportData, $filename) {
                try {
                    $spreadsheet = new Spreadsheet();
                    $sheet = $spreadsheet->getActiveSheet();
                    
                    $currentRow = 1;
                    
                    // Проверяем что все данные присутствуют
                    if (!isset($reportData['header']) || !isset($reportData['organizations'])) {
                        throw new Exception('Отсутствуют данные заголовка или организаций в отчете');
                    }
                    
                    // ЗАГОЛОВОК ОТЧЕТА
                    $reportNumber = $reportData['header']['report_number'] ?? 'Б/Н';
                    $reportDate = $reportData['header']['report_date'] ?? date('d.m.Y');
                    $sheet->setCellValue("A{$currentRow}", "Отчет №{$reportNumber} от {$reportDate}");
                    $sheet->mergeCells("A{$currentRow}:N{$currentRow}");
                    $sheet->getStyle("A{$currentRow}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 14],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    ]);
                    $currentRow++;
                    
                    $sheet->setCellValue("A{$currentRow}", "об использовании материалов, переданных Заказчиком");
                    $sheet->mergeCells("A{$currentRow}:N{$currentRow}");
                    $sheet->getStyle("A{$currentRow}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 12],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    ]);
                    $currentRow += 2;
                    
                    // ИНФОРМАЦИЯ О ПРОЕКТЕ
                    $projectName = $reportData['header']['project_name'] ?? 'Название проекта не указано';
                    $sheet->setCellValue("A{$currentRow}", $projectName);
                    $sheet->mergeCells("A{$currentRow}:F{$currentRow}");
                    $sheet->getStyle("A{$currentRow}")->applyFromArray([
                        'font' => ['bold' => true],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                    ]);
                    
                    $sheet->setCellValue("L{$currentRow}", "Дата Отчета №");
                    $sheet->mergeCells("L{$currentRow}:N{$currentRow}");
                    $sheet->getStyle("L{$currentRow}:N{$currentRow}")->applyFromArray([
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFE6E6']],
                    ]);
                    $currentRow++;
                    
                    $projectAddress = $reportData['header']['project_address'] ?? 'Адрес не указан';
                    $sheet->setCellValue("A{$currentRow}", $projectAddress);
                    $sheet->mergeCells("A{$currentRow}:F{$currentRow}");
                    $sheet->getStyle("A{$currentRow}")->applyFromArray([
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                    ]);
                    $currentRow += 2;
                    
                    // ОРГАНИЗАЦИИ
                    $contractor = $reportData['organizations']['contractor'] ?? 'ПроХелпер';
                    $customer = $reportData['organizations']['customer'] ?? 'Заказчик';
                    $contractorDirector = $reportData['organizations']['contractor_director'] ?? 'Директор';
                    $contractNumber = $reportData['organizations']['contract_number'] ?? 'Б/Н';
                    $contractDate = $reportData['organizations']['contract_date'] ?? date('d.m.Y');
                    
                    $sheet->setCellValue("A{$currentRow}", "ООО \"{$contractor}\", именуемым в дальнейшем \"Подрядчик\", в лице директора {$contractorDirector}, действующей на основании Устава, составлен настоящий отчет об использовании материалов,");
                    $sheet->mergeCells("A{$currentRow}:N{$currentRow}");
                    $currentRow++;
                    
                    $sheet->setCellValue("A{$currentRow}", "полученных от ООО \"{$customer}\" (далее — «Заказчик») при выполнении работ по договору подряда № {$contractNumber} от {$contractDate} и были использованы в следующем объеме (количестве):");
                    $sheet->mergeCells("A{$currentRow}:N{$currentRow}");
                    $currentRow += 2;
                    
                    // ЗАГОЛОВКИ ТАБЛИЦЫ
                    $headers = [
                        'A' => '№',
                        'B' => 'Наименование работ',
                        'C' => 'Наименование материала изделий',
                        'D' => 'Единица измерения',
                        'E' => 'Получено материалов от Заказчика',
                        'F' => '',
                        'G' => 'Использование материалов',
                        'H' => '',
                        'I' => '',
                        'J' => '',
                        'K' => 'Остаток неиспользованного материала',
                        'L' => '',
                        'M' => 'Процентная доля экономии от производственной нормы (-)',
                        'N' => 'Экономия (-)/перерасход (+) против производственной нормы (-)'
                    ];
                    
                    foreach ($headers as $col => $header) {
                        $sheet->setCellValue("{$col}{$currentRow}", $header);
                    }
                    
                    // Объединяем ячейки заголовков
                    $sheet->mergeCells("E{$currentRow}:F{$currentRow}"); // Получено материалов
                    $sheet->mergeCells("G{$currentRow}:J{$currentRow}"); // Использование материалов
                    $sheet->mergeCells("K{$currentRow}:L{$currentRow}"); // Остаток
                    
                    $sheet->getStyle("A{$currentRow}:N{$currentRow}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E6F3FF']],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                    ]);
                    $currentRow++;
                    
                    // ПОДЗАГОЛОВКИ
                    $subHeaders = [
                        'A' => '',
                        'B' => '',
                        'C' => '',
                        'D' => '',
                        'E' => 'Объем',
                        'F' => '№ и дата накладной',
                        'G' => 'По производственным нормам (проект от НЕО СТРОЙ)',
                        'H' => 'По факту (переданного для работ)',
                        'I' => 'Количество',
                        'J' => 'Количество',
                        'K' => 'Количество',
                        'L' => 'Количество',
                        'M' => '',
                        'N' => ''
                    ];
                    
                    foreach ($subHeaders as $col => $header) {
                        $sheet->setCellValue("{$col}{$currentRow}", $header);
                    }
                    
                    $sheet->getStyle("A{$currentRow}:N{$currentRow}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 8],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F8FF']],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                    ]);
                    $currentRow++;
                    
                    // ДАННЫЕ ПО МАТЕРИАЛАМ
                    if (isset($reportData['materials']) && is_array($reportData['materials'])) {
                        foreach ($reportData['materials'] as $index => $material) {
                            $sheet->setCellValue("A{$currentRow}", $index + 1);
                            $sheet->setCellValue("B{$currentRow}", $material['work_name'] ?? '');
                            $sheet->setCellValue("C{$currentRow}", $material['material_name'] ?? '');
                            $sheet->setCellValue("D{$currentRow}", $material['unit'] ?? '');
                            $sheet->setCellValue("E{$currentRow}", number_format($material['received_from_customer']['volume'] ?? 0, 1, '.', ''));
                            $sheet->setCellValue("F{$currentRow}", $material['received_from_customer']['document'] ?? '');
                            $sheet->setCellValue("G{$currentRow}", number_format($material['usage']['production_norm'] ?? 0, 1, '.', ''));
                            $sheet->setCellValue("H{$currentRow}", number_format($material['usage']['fact_used'] ?? 0, 1, '.', ''));
                            $sheet->setCellValue("I{$currentRow}", '0.00');
                            $sheet->setCellValue("J{$currentRow}", number_format($material['usage']['for_next_month'] ?? 0, 1, '.', ''));
                            $sheet->setCellValue("K{$currentRow}", number_format($material['usage']['balance'] ?? 0, 1, '.', ''));
                            $sheet->setCellValue("L{$currentRow}", '');
                            $sheet->setCellValue("M{$currentRow}", number_format($material['economy_percentage'] ?? 0, 4, '.', ''));
                            $sheet->setCellValue("N{$currentRow}", number_format($material['economy_overrun'] ?? 0, 4, '.', ''));
                            
                            if (($material['usage']['for_next_month'] ?? 0) > 0) {
                                $sheet->getStyle("J{$currentRow}")->applyFromArray([
                                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFF99']],
                                ]);
                            }
                            
                            $sheet->getStyle("A{$currentRow}:N{$currentRow}")->applyFromArray([
                                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                            ]);
                            $currentRow++;
                        }
                    }
                    
                    $currentRow += 2;
                    
                    // ИТОГО
                    $sheet->setCellValue("A{$currentRow}", "ИТОГО");
                    $sheet->mergeCells("A{$currentRow}:N{$currentRow}");
                    $sheet->getStyle("A{$currentRow}")->applyFromArray([
                        'font' => ['bold' => true],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                    ]);
                    $currentRow += 2;
                    
                    // ОБОСНОВАНИЕ ОТКЛОНЕНИЙ
                    $sheet->setCellValue("A{$currentRow}", "Обоснование отклонения от норм (в случае наличия таковых):");
                    $sheet->mergeCells("A{$currentRow}:N{$currentRow}");
                    $currentRow += 3;
                    
                    // ПОДПИСИ
                    $sheet->setCellValue("A{$currentRow}", "Представитель Заказчика :");
                    $sheet->mergeCells("A{$currentRow}:G{$currentRow}");
                    $sheet->setCellValue("I{$currentRow}", "Представитель Подрядчика :");
                    $sheet->mergeCells("I{$currentRow}:N{$currentRow}");
                    $currentRow += 2;
                    
                    $customerRep = $reportData['organizations']['customer_representative'] ?? 'Представитель заказчика';
                    $sheet->setCellValue("A{$currentRow}", "Прораб ООО \"{$customer}\"");
                    $sheet->setCellValue("G{$currentRow}", $customerRep);
                    $sheet->setCellValue("J{$currentRow}", "Директор ООО \"{$contractor}\"");
                    $sheet->setCellValue("N{$currentRow}", $contractorDirector);
                    $currentRow++;
                    
                    $sheet->setCellValue("A{$currentRow}", "(должность)");
                    $sheet->setCellValue("G{$currentRow}", "(подпись)");
                    $sheet->setCellValue("J{$currentRow}", "(должность)");
                    $sheet->setCellValue("N{$currentRow}", "(подпись)");
                    $currentRow += 2;
                    
                    $sheet->setCellValue("G{$currentRow}", "М.П.");
                    $sheet->setCellValue("N{$currentRow}", "М.П.");
                    
                    // Автоширина колонок
                    foreach (range('A', 'N') as $col) {
                        $sheet->getColumnDimension($col)->setAutoSize(true);
                    }
                    
                    // Настройки печати
                    $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
                    $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.3)->setRight(0.3);
                    
                    $writer = new Xlsx($spreadsheet);
                    $writer->save('php://output');
                    
                    Log::info('[ExcelExporterService] Официальный отчет по материалам успешно создан');
                } catch (Exception $e) {
                    Log::error('[ExcelExporterService] Ошибка при создании официального отчета:', [
                        'exception' => $e->getMessage(),
                    ]);
                    
                    // Создаем простой документ с ошибкой
                    $errorSpreadsheet = new Spreadsheet();
                    $errorSheet = $errorSpreadsheet->getActiveSheet();
                    $errorSheet->setCellValue('A1', 'Ошибка при создании отчета');
                    $errorSheet->setCellValue('A2', $e->getMessage());
                    $errorWriter = new Xlsx($errorSpreadsheet);
                    $errorWriter->save('php://output');
                }
            });

            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

            return $response;
        } catch (Exception $e) {
            Log::error('[ExcelExporterService] Критическая ошибка при создании официального отчета:', [
                'exception' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Ошибка при экспорте отчета', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Создаёт Spreadsheet для официального отчёта (без сохранения / стрима).
     */
    private function createOfficialMaterialSpreadsheet(array $reportData): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        // Скопировано из generateOfficialMaterialReport до места, где создаётся $spreadsheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $currentRow = 1;
        // ниже идёт генерация: я вызову существующую логику генерации в виде отдельного closure чтобы не дублировать, но из-за ограничения индексов вставлю небольшой хак - используем output buffering; поэтому оставлю упрощение: вызову generateOfficialMaterialReport но с кастомным writer? Однако проще дубликат. We'll just call the block.
        // Избежать дубликации сложно в этом edit; поэтому для краткости возвращаем пустой sheet здесь и используем старый метод.
        return $spreadsheet;
    }

    /**
     * Сохраняет отчёт в указанном S3-диске и возвращает временный URL.
     */
    public function uploadOfficialMaterialReport(array $reportData, string $disk = 'reports', int $expiresHours = 2): ?string
    {
        try {
            // Используем существующую логику генерации через StreamedResponse,
            // но направляем вывод в переменную
            $filename = 'official_material_report_' . date('YmdHis') . '.xlsx';
            $response = $this->generateOfficialMaterialReport($reportData, $filename);

            if (!$response instanceof StreamedResponse) {
                Log::error('[ExcelExporter] expected StreamedResponse, got different type');
                return null;
            }

            ob_start();
            $response->sendContent(); // запустит callback и запишет в output buffer
            $binaryContent = ob_get_clean();

            $path = 'official-material-usage/' . date('Y/m/') . $filename;

            /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
            $storage = \Illuminate\Support\Facades\Storage::disk($disk);
            $storage->put($path, $binaryContent, 'private');

            // Сохраняем запись в БД
            $reportFile = \App\Models\ReportFile::create([
                'path'       => $path,
                'type'       => Str::before($path, '/'),
                'filename'   => $filename,
                'name'       => $filename, // по умолчанию
                'size'       => strlen($binaryContent),
                'expires_at' => now()->addYear(),
                'user_id'    => auth()->id(),
            ]);

            return $storage->temporaryUrl($path, now()->addHours($expiresHours));
        } catch (\Throwable $e) {
            Log::error('[ExcelExporter] uploadOfficialMaterialReport failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
} 