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
} 