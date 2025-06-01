<?php

namespace App\Services\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;
use Exception;

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

                    Log::info('[ExcelExporterService] Запись заголовков', ['headers' => $headers]);
                    $colIndex = 0;
                    foreach ($headers as $header) {
                        $cell = chr(65 + $colIndex) . '1';
                        $sheet->setCellValue($cell, $header);
                        $colIndex++;
                    }

                    Log::info('[ExcelExporterService] Запись строк', ['rows_count' => is_countable($data) ? count($data) : null]);
                    $rowIndex = 2;
                    $rowLogged = 0;
                    foreach ($data as $rowArray) {
                        $colIndex = 0;
                        foreach ($rowArray as $value) {
                            $cell = chr(65 + $colIndex) . $rowIndex;
                            $sheet->setCellValue($cell, $value);
                            $colIndex++;
                        }
                        if ($rowLogged < 3) {
                            Log::info('[ExcelExporterService] Пример строки', ['rowIndex' => $rowIndex, 'row' => $rowArray]);
                            $rowLogged++;
                        }
                        $rowIndex++;
                    }

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