<?php

namespace App\Services\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExporterService
{
    /**
     * Генерирует и возвращает StreamedResponse для скачивания CSV файла.
     *
     * @param string $filename Имя файла (без .csv)
     * @param array $headers Массив заголовков колонок
     * @param \Illuminate\Support\Collection|array $data Коллекция или массив данных (массивы или объекты)
     * @param string $delimiter Разделитель полей
     * @param bool $applyBom Добавлять ли UTF-8 BOM (для лучшей совместимости с Excel на Windows)
     * @return StreamedResponse
     */
    public function streamDownload(
        string $filename,
        array $headers,
        $data,
        string $delimiter = ';',
        bool $applyBom = true
    ): StreamedResponse {
        $response = new StreamedResponse(function () use ($headers, $data, $delimiter, $applyBom) {
            $handle = fopen('php://output', 'w');

            if ($applyBom) {
                fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM
            }

            // Записываем заголовки
            fputcsv($handle, $headers, $delimiter);

            // Записываем данные
            foreach ($data as $row) {
                $rowData = [];
                if (is_object($row)) {
                    foreach ($headers as $key => $headerValue) {
                        // Пытаемся получить значение по ключу, соответствующему заголовку
                        // Предполагается, что ключи в $headers могут соответствовать свойствам объекта или ключам массива
                        // Это упрощенный вариант, возможно, потребуется более сложная логика маппинга
                        $fieldKey = array_search($headerValue, $headers, true); // Получаем оригинальный ключ по значению заголовка
                                                
                        // Если заголовок является просто числовым индексом, пытаемся взять значение по этому индексу
                        // или если ключи объекта/массива совпадают с заголовками (после приведения к snake_case например)
                        // Для простоты пока предполагаем, что ключи в $data должны быть подготовлены заранее
                        // или $headers содержит ключи, по которым можно извлечь данные из $row.
                        // Этот блок нужно будет доработать в зависимости от структуры $data.
                        // Пока сделаем простой вариант: если $headers - это ['field1', 'field2'],
                        // а $row - это объект, то мы ожидаем $row->field1, $row->field2.
                        // Если $headers - это ['Заголовок 1' => 'field1', 'Заголовок 2' => 'field2'],
                        // то ключи массива $headers - это то, что пишется в CSV, а значения - ключи для $data.
                        
                        // Предположим, что $headers содержит ассоциативный массив, где ключи - это заголовки для CSV,
                        // а значения - это ключи/свойства для извлечения данных из $row.
                        // Если $headers просто массив строк, то эти строки используются и как заголовки, и как ключи.
                        
                        $dataKey = is_string($key) ? $key : $headerValue; // Если ключ числовой, используем значение как ключ данных
                                                
                        if (isset($row->{$dataKey})) {
                            $rowData[] = $this->formatCsvField($row->{$dataKey});
                        } elseif (is_array($row) && isset($row[$dataKey])) {
                            $rowData[] = $this->formatCsvField($row[$dataKey]);
                        } else {
                            // Если $headers - простой массив, и ключи в $row отличаются,
                            // то этот подход не сработает. Нужен более умный маппинг.
                            // Для первого шага, будем ожидать, что $data содержит массивы,
                            // где порядок элементов соответствует порядку $headers (если $headers не ассоциативный)
                            // или ключи в $data (если $row - массив) соответствуют ключам/значениям $headers.
                            // В нашем случае $headers будет простым массивом заголовков,
                            // а $data - коллекцией массивов с ключами, совпадающими с заголовками (после подготовки)
                            
                            // Упрощенный вариант для начала: если $data это коллекция массивов,
                            // где ключи совпадают со значениями в $headers
                            if (is_array($row) && array_key_exists($headerValue, $row) ) {
                                 $rowData[] = $this->formatCsvField($row[$headerValue]);
                            } else {
                               $rowData[] = ''; // Пустое значение, если ключ не найден
                            }
                        }
                    }
                } elseif (is_array($row)) {
                     foreach ($headers as $key => $headerValue) {
                        $dataKey = is_string($key) ? $key : $headerValue;
                         if (array_key_exists($dataKey, $row) ) {
                             $rowData[] = $this->formatCsvField($row[$dataKey]);
                        } else {
                           $rowData[] = '';
                        }
                     }
                }
                fputcsv($handle, $rowData, $delimiter);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '.csv"');

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
        // TODO: Добавить форматирование дат и чисел согласно выбранным стандартам, если они не подготовлены заранее
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

        $exportData = collect($rawData)->map(function ($row) use ($dataKeys) {
            $rowData = [];
            foreach ($dataKeys as $dataKey) {
                $value = null;
                if (is_object($row) && isset($row->{$dataKey})) {
                    $value = $row->{$dataKey};
                } elseif (is_array($row) && isset($row[$dataKey])) {
                    $value = $row[$dataKey];
                } elseif (is_object($row) && strpos($dataKey, '.') !== false) { // Обработка вложенных данных
                    $value = data_get($row, $dataKey);
                } elseif (is_array($row) && strpos($dataKey, '.') !== false) {
                    $value = data_get($row, $dataKey);
                }
                // Применяем форматирование здесь, если нужно, или в formatCsvField
                if ($value instanceof \Carbon\Carbon) {
                    $rowData[] = $value->format('d.m.Y'); // Пример формата даты
                } elseif (is_numeric($value) && !is_int($value)) {
                     // Для чисел с плавающей точкой используем запятую как десятичный разделитель
                     // и задаем 2 знака после запятой (можно настроить)
                    $rowData[] = number_format($value, 2, ',', '');
                } else {
                    $rowData[] = $value;
                }
            }
            return $rowData; // Возвращаем простой массив значений в нужном порядке
        });
        
        // Важно: fputcsv ожидает, что данные будут в виде простого массива,
        // соответствующего порядку заголовков.
        // Метод prepareDataForExport теперь возвращает $exportData как коллекцию таких массивов.
        // Поэтому в streamDownload нужно передавать $csvHeaders и $exportData,
        // и fputcsv($handle, $row, $delimiter) будет работать корректно.

        return [
            'headers' => $csvHeaders,
            'data' => $exportData
        ];
    }
} 