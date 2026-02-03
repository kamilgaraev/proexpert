<?php

namespace App\BusinessModules\Features\NormativeReferences\Services;

use App\BusinessModules\Features\NormativeReferences\Models\NormativeResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shuchkin\SimpleXLSX;

class NormativeResourceImportService
{
    /**
     * Импорт справочника КСР из Excel файла.
     * 
     * @param string $filePath Путь к файлу
     * @param string $source Источник (по умолчанию 'KSR')
     * @return array Статистика импорта [processed, inserted, updated, errors]
     */
    public function importKsr(string $filePath, string $source = 'KSR'): array
    {
        if (!file_exists($filePath)) {
            throw new \Exception("Файл не найден: {$filePath}");
        }

        $xlsx = SimpleXLSX::parse($filePath);
        if (!$xlsx) {
            throw new \Exception("Ошибка парсинга XLSX: " . SimpleXLSX::parseError());
        }

        $stats = [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'errors' => 0,
        ];

        $batch = [];
        $batchSize = 1000;
        $now = now();

        foreach ($xlsx->readRows() as $index => $row) {
            // Пропускаем заголовок (если это не код в первой колонке)
            $code = trim($row[0] ?? '');
            $name = trim($row[1] ?? '');
            $unit = trim($row[2] ?? '');
            
            // 1. Проверка на пустоту
            if (empty($code)) {
                continue;
            }

            // 2. Код ресурса ОБЯЗАН начинаться с цифры.
            // Заголовки типа "Книга 01...", "Раздел..." начинаются с букв.
            if (!preg_match('/^\d/', $code)) {
                continue;
            }

            // 3. Дополнительная защита: код должен содержать точки или дефисы (структура КСР)
            // Пример: 01.1.01.01-0002
            if (!str_contains($code, '.') && !str_contains($code, '-')) {
                continue;
            }

            // 4. Игнорируем строки, где есть код, но нет имени (битые строки)
            if (empty($name)) {
                continue;
            }

            $type = $this->determineType($code);

            $batch[] = [
                'code' => $code,
                'name' => $name,
                'unit' => $unit,
                'type' => $type,
                'source' => $source,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $stats['processed']++;

            if (count($batch) >= $batchSize) {
                $this->upsertBatch($batch, $stats);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->upsertBatch($batch, $stats);
        }

        return $stats;
    }

    private function upsertBatch(array $batch, array &$stats): void
    {
        try {
            DB::table('normative_resources')->upsert(
                $batch,
                ['code', 'source'], // Unique keys
                ['name', 'unit', 'type', 'updated_at'] // Columns to update
            );
            $stats['inserted'] += count($batch);
        } catch (\Exception $e) {
            // Если массовая вставка упала, пробуем по одной, чтобы найти ошибку
            // И чтобы не потерять всю пачку
            foreach ($batch as $item) {
                try {
                    DB::table('normative_resources')->upsert(
                        [$item],
                        ['code', 'source'],
                        ['name', 'unit', 'type', 'updated_at']
                    );
                    $stats['inserted']++;
                } catch (\Exception $innerE) {
                    $stats['errors']++;
                    // Выведем первую ошибку в консоль/лог
                    if ($stats['errors'] <= 1) {
                        $msg = "Ошибка на коде {$item['code']}: " . $innerE->getMessage();
                        Log::error($msg);
                        echo "\n[ERROR] " . $msg . "\n";
                    }
                }
            }
        }
    }

    private function determineType(string $code): string
    {
        // Логика определения типа по коду КСР
        // Книги:
        // 01-29 - Материалы
        // 61-69 - Оборудование
        // 91 - Машины и механизмы
        
        // Очистим код от лишнего
        $cleanCode = preg_replace('/[^0-9\.]/', '', $code);
        
        if (str_starts_with($cleanCode, '91')) {
            return NormativeResource::TYPE_MACHINE;
        }

        // Проверка на оборудование (книги 61-69, и иногда 08 в старых базах)
        if (preg_match('/^(6\d|08)\./', $cleanCode)) {
            return NormativeResource::TYPE_EQUIPMENT;
        }

        // Все остальное по умолчанию материалы
        return NormativeResource::TYPE_MATERIAL;
    }
}
