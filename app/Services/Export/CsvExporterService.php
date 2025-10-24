<?php

namespace App\Services\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;

class CsvExporterService
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }

    /**
     * Генерирует и возвращает StreamedResponse для скачивания CSV файла.
     *
     * @param string $filename Имя файла (без .csv)
     * @param array $headers Массив заголовков колонок (простой массив строк)
     * @param \Illuminate\Support\Collection|array $data Коллекция или массив подготовленных строк данных (каждая строка - простой индексированный массив)
     * @param string $delimiter Разделитель полей
     * @param bool $applyBom Добавлять ли UTF-8 BOM
     * @return StreamedResponse
     */
    public function streamDownload(
        string $filename,
        array $headers,
        $data,
        string $delimiter = ';',
        bool $applyBom = true
    ): StreamedResponse {
        // BUSINESS: Начало CSV экспорта - важная функциональность для пользователей
        $this->logging->business('csv.export.started', [
            'filename' => $filename,
            'headers_count' => count($headers),
            'data_count' => is_countable($data) ? count($data) : null,
            'export_format' => 'csv',
            'delimiter' => $delimiter,
            'bom_enabled' => $applyBom,
            'user_id' => Auth::id(),
            'organization_id' => request()->attributes->get('current_organization_id')
        ]);

        $response = new StreamedResponse(function () use ($headers, $data, $delimiter, $applyBom, $filename) {
            try {
                $handle = fopen('php://output', 'w');

                if ($applyBom) {
                    fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM
                }

                // Записываем заголовки
                fputcsv($handle, $headers, $delimiter);

                // Записываем данные (уже подготовленные строки)
                $rowCount = 0;
                foreach ($data as $rowArray) {
                    fputcsv($handle, $rowArray, $delimiter);
                    $rowCount++;
                }

                fclose($handle);

                // BUSINESS: CSV экспорт успешно завершён
                $this->logging->business('csv.export.completed', [
                    'filename' => $filename,
                    'total_rows' => $rowCount,
                    'total_columns' => count($headers),
                    'export_format' => 'csv',
                    'user_id' => Auth::id()
                ]);

            } catch (\Exception $e) {
                // TECHNICAL: Ошибка при генерации CSV
                $this->logging->technical('csv.generation.exception', [
                    'filename' => $filename,
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'headers_count' => count($headers),
                    'data_count' => is_countable($data) ? count($data) : null
                ], 'error');

                // BUSINESS: Неудачный экспорт CSV
                $this->logging->business('csv.export.failed', [
                    'filename' => $filename,
                    'export_format' => 'csv',
                    'failure_reason' => 'generation_exception',
                    'error_message' => $e->getMessage(),
                    'user_id' => Auth::id()
                ], 'error');
                
                // Записываем ошибку в CSV
                fwrite($handle, "Error: " . $e->getMessage());
                fclose($handle);
            }
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));

        return $response;
    }

    /**
     * Форматирует значение поля для CSV.
     * Пока просто возвращает как есть, но можно добавить логику для дат, чисел и т.д.
     * @param mixed $value
     * @return mixed
     */
    protected function formatCsvField($value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }
        // Дальнейшее форматирование может быть применено в prepareDataForExport
        return $value;
    }

    /**
     * Готовит данные для экспорта: принимает коллекцию Eloquent моделей или массивов,
     * и массив соответствия 'Заголовок CSV' => 'ключ_в_модели_или_массиве'.
     * Возвращает массив заголовков и коллекцию подготовленных строк (массивов).
     *
     * @param \Illuminate\Support\Collection|array $rawData
     * @param array $columnMapping Ассоциативный массив ['Заголовок для CSV' => 'data_key_in_row']
     * @return array ['headers' => array, 'data' => \Illuminate\Support\Collection]
     */
    public function prepareDataForExport($rawData, array $columnMapping): array
    {
        $csvHeaders = array_keys($columnMapping);
        $dataKeys = array_values($columnMapping);
        
        $firstRawItem = null;
        if (is_array($rawData) && !empty($rawData)) {
            $firstRawItem = $rawData[0];
        } elseif ($rawData instanceof SupportCollection && $rawData->isNotEmpty()) {
            $firstRawItem = $rawData->first();
        }

        Log::debug('[CsvExporterService] Preparing data for export - Input', [
            'rawData_count' => count($rawData instanceof \Countable ? $rawData : iterator_to_array($rawData)),
            'first_raw_item_type' => is_object($firstRawItem) ? get_class($firstRawItem) : gettype($firstRawItem),
            'first_raw_item_content' => $firstRawItem,
            'columnMapping' => $columnMapping
        ]);

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
                    } elseif (is_int($value)) {
                        $value = $value;
                    } elseif (is_bool($value)) {
                        $value = $value ? 'Да' : 'Нет';
                    }
                    $rowData[] = $value;
                }
                $exportData[] = $rowData;
            }
        }
        
        Log::debug('[CsvExporterService] Prepared exportable data - Output', [
            'csvHeaders_count' => count($csvHeaders),
            'exportData_count' => count($exportData),
            'first_export_row' => !empty($exportData) ? $exportData[0] : null
        ]);

        return [
            'headers' => $csvHeaders,
            'data' => $exportData
        ];
    }

    /**
     * Сохраняет CSV файл на диск.
     *
     * @param array|\Illuminate\Support\Collection $data Массив данных
     * @param array $headers Массив заголовков колонок
     * @param string $filePath Путь к файлу для сохранения
     * @param string $delimiter Разделитель полей
     * @param bool $applyBom Добавлять ли UTF-8 BOM
     * @return void
     */
    public function saveToFile($data, array $headers, string $filePath, string $delimiter = ';', bool $applyBom = true): void
    {
        try {
            Log::info('[CsvExporterService] Сохранение CSV файла на диск', [
                'file_path' => $filePath,
                'headers_count' => count($headers),
                'data_count' => is_countable($data) ? count($data) : null,
            ]);

            $handle = fopen($filePath, 'w');

            if ($applyBom) {
                fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM
            }

            // Записываем заголовки
            fputcsv($handle, $headers, $delimiter);

            // Записываем данные
            foreach ($data as $rowArray) {
                fputcsv($handle, $rowArray, $delimiter);
            }

            fclose($handle);

            Log::info('[CsvExporterService] CSV файл успешно сохранен', [
                'file_path' => $filePath,
                'rows_count' => count($data),
            ]);

        } catch (\Exception $e) {
            Log::error('[CsvExporterService] Ошибка при сохранении CSV файла', [
                'file_path' => $filePath,
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}