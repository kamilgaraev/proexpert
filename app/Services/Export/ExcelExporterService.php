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
} 