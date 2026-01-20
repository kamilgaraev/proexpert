<?php

namespace App\BusinessModules\Addons\AIEstimates\Services\FileProcessing;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class FileParserService
{
    public function __construct(
        protected YandexVisionClient $yandexVisionClient
    ) {}

    public function parseFiles(array $files): array
    {
        $extractedData = [];

        foreach ($files as $file) {
            try {
                $fileData = $this->parseFile($file);
                if ($fileData) {
                    $extractedData[] = $fileData;
                }
            } catch (\Exception $e) {
                Log::error('[FileParserService] Failed to parse file', [
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);
                
                // Продолжаем обработку остальных файлов
                continue;
            }
        }

        return $extractedData;
    }

    protected function parseFile(UploadedFile $file): ?array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match($extension) {
            'pdf', 'jpg', 'jpeg', 'png' => $this->parseImageOrPdf($file),
            'xlsx', 'xls' => $this->parseExcel($file),
            default => null,
        };
    }

    protected function parseImageOrPdf(UploadedFile $file): array
    {
        // Используем Yandex Vision API для распознавания
        $text = $this->yandexVisionClient->extractText($file);

        return [
            'type' => 'ocr',
            'filename' => $file->getClientOriginalName(),
            'text' => $text,
            'structured_data' => $this->parseStructuredData($text),
        ];
    }

    protected function parseExcel(UploadedFile $file): array
    {
        // Парсинг Excel (спецификации, сметы) через PhpSpreadsheet
        $spreadsheet = IOFactory::load($file->getRealPath());
        $data = [];
        
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $data[] = $sheet->toArray();
        }

        return [
            'type' => 'excel',
            'filename' => $file->getClientOriginalName(),
            'data' => $data,
            'structured_data' => $this->parseExcelStructuredData($data),
        ];
    }

    protected function parseStructuredData(string $text): array
    {
        $data = [];

        // Регулярки для поиска площадей
        if (preg_match_all('/(\d+(?:[\.,]\d+)?)\s*(?:м2|м²|кв\.?\s*м)/iu', $text, $matches)) {
            $data['areas'] = array_map(
                fn($v) => (float) str_replace(',', '.', $v), 
                $matches[1]
            );
        }

        // Поиск размеров (длина x ширина)
        if (preg_match_all('/(\d+(?:[\.,]\d+)?)\s*[xхXХ×]\s*(\d+(?:[\.,]\d+)?)/u', $text, $matches)) {
            $data['dimensions'] = [];
            foreach ($matches[0] as $i => $match) {
                $data['dimensions'][] = [
                    'length' => (float) str_replace(',', '.', $matches[1][$i]),
                    'width' => (float) str_replace(',', '.', $matches[2][$i]),
                ];
            }
        }

        // Поиск количеств с единицами измерения
        if (preg_match_all('/(\d+(?:[\.,]\d+)?)\s*(м³|м3|куб\.?\s*м|м\.п\.|м\.п|шт\.?|т\.?)/iu', $text, $matches)) {
            $data['quantities'] = [];
            foreach ($matches[0] as $i => $match) {
                $data['quantities'][] = [
                    'value' => (float) str_replace(',', '.', $matches[1][$i]),
                    'unit' => $this->normalizeUnit($matches[2][$i]),
                ];
            }
        }

        return $data;
    }

    protected function parseExcelStructuredData(array $excelData): array
    {
        $data = [
            'rows_count' => 0,
            'potential_estimates' => [],
        ];

        foreach ($excelData as $sheetIndex => $sheet) {
            $data['rows_count'] += count($sheet);

            // Пытаемся найти структуру сметы
            foreach ($sheet as $rowIndex => $row) {
                // Ищем строки с числовыми значениями (потенциальные позиции сметы)
                if (count($row) >= 3) {
                    $hasNumbers = false;
                    foreach ($row as $cell) {
                        if (is_numeric($cell)) {
                            $hasNumbers = true;
                            break;
                        }
                    }
                    
                    if ($hasNumbers) {
                        $data['potential_estimates'][] = [
                            'sheet' => $sheetIndex,
                            'row' => $rowIndex,
                            'data' => $row,
                        ];
                    }
                }
            }
        }

        return $data;
    }

    protected function normalizeUnit(string $unit): string
    {
        $unit = mb_strtolower(trim($unit));
        
        return match(true) {
            str_contains($unit, 'м3') || str_contains($unit, 'м³') || str_contains($unit, 'куб') => 'м³',
            str_contains($unit, 'м.п') || str_contains($unit, 'м\.п') => 'м.п.',
            str_contains($unit, 'шт') => 'шт',
            str_contains($unit, 'т') && !str_contains($unit, 'шт') => 'т',
            default => $unit,
        };
    }

    public function validateFile(UploadedFile $file): bool
    {
        $allowedExtensions = config('ai-estimates.allowed_file_types', ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'xls']);
        $maxSize = config('ai-estimates.max_file_size', 50) * 1024; // в KB

        $extension = strtolower($file->getClientOriginalExtension());
        
        if (!in_array($extension, $allowedExtensions)) {
            return false;
        }

        if ($file->getSize() > $maxSize * 1024) { // в bytes
            return false;
        }

        return true;
    }
}
