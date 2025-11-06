<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

/**
 * Сервис для парсинга и нормализации кодов нормативов
 * 
 * Поддерживает форматы:
 * - ГЭСН: ГЭСН01-01-012-20
 * - ФЕР: ФЕР01-01-012-1
 * - ТЕР: ТЕР01-01-012-1 или 01-01-012-1
 * - ФСБЦ: ФСБЦ-02.1.01.02-0003
 * - Региональные: 91.01.01-035, 01.01.001-01
 */
class NormativeCodeService
{
    /**
     * Типы нормативных баз
     */
    private const TYPE_GESN = 'GESN';
    private const TYPE_FER = 'FER';
    private const TYPE_TER = 'TER';
    private const TYPE_FSBC = 'FSBC';
    private const TYPE_REGIONAL = 'REGIONAL';
    private const TYPE_UNKNOWN = 'UNKNOWN';

    /**
     * Регулярные выражения для различных форматов кодов
     */
    private const PATTERNS = [
        // ГЭСН: ГЭСН01-01-012-20 или ГЭСН-01-01-012-20
        self::TYPE_GESN => '/^(ГЭСН|GESN)[-\s]?(\d{2}[-.]?\d{2}[-.]?\d{3}(?:[-.]?\d{2})?)/ui',
        
        // ФЕР: ФЕР01-01-012-1 или ФЕР-01-01-012-1
        self::TYPE_FER => '/^(ФЕР|FER)[-\s]?(\d{2}[-.]?\d{2}[-.]?\d{3}(?:[-.]?\d{1,2})?)/ui',
        
        // ТЕР: ТЕР01-01-012-1 или ТЕР-01-01-012-1
        self::TYPE_TER => '/^(ТЕР|TER)[-\s]?(\d{2}[-.]?\d{2}[-.]?\d{3}(?:[-.]?\d{1,2})?)/ui',
        
        // ФСБЦ: ФСБЦ-02.1.01.02-0003 или ФСБЦ02.1.01.02-0003
        self::TYPE_FSBC => '/^(ФСБЦ|FSBC|ФСБЦс)[-\s]?(\d{2}\.?\d+\.?\d+\.?\d+[-.]?\d{4})/ui',
        
        // Региональные/сокращенные: 91.01.01-035, 01.01.001-01, 01-01-001-01
        self::TYPE_REGIONAL => '/^(\d{2}[-.]?\d{2}[-.]?\d{3}(?:[-.]?\d{2,3})?)\s/u',
    ];

    /**
     * Извлечь код из строки текста
     * 
     * @param string $text Исходный текст (может содержать код + название)
     * @return array|null ['code' => string, 'type' => string, 'clean_text' => string] или null
     */
    public function extractCode(string $text): ?array
    {
        $text = trim($text);
        
        if (empty($text)) {
            return null;
        }

        // Пробуем каждый паттерн
        foreach (self::PATTERNS as $type => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $prefix = $matches[1] ?? '';
                $codeBody = $matches[2] ?? '';
                
                // Полный код с префиксом
                $fullCode = !empty($prefix) ? $prefix . $codeBody : $codeBody;
                
                // Убираем код из исходного текста, чтобы получить чистое название
                $cleanText = preg_replace($pattern, '', $text);
                $cleanText = trim($cleanText);
                
                return [
                    'code' => $fullCode,
                    'type' => $type,
                    'clean_text' => $cleanText,
                    'prefix' => $prefix,
                    'body' => $codeBody,
                ];
            }
        }

        return null;
    }

    /**
     * Нормализовать код к единому формату
     * 
     * @param string $code Исходный код
     * @return string Нормализованный код
     */
    public function normalizeCode(string $code): string
    {
        $code = mb_strtoupper(trim($code));
        
        // Убираем лишние пробелы
        $code = preg_replace('/\s+/', '', $code);
        
        // Нормализуем разделители (заменяем точки на дефисы для единообразия)
        // Кроме ФСБЦ, где точки - часть формата
        if (!str_contains($code, 'ФСБЦ') && !str_contains($code, 'FSBC')) {
            $code = str_replace('.', '-', $code);
        }
        
        return $code;
    }

    /**
     * Получить варианты написания кода для поиска
     * 
     * @param string $code Исходный код
     * @return array Массив вариантов кода
     */
    public function getCodeVariations(string $code): array
    {
        $normalized = $this->normalizeCode($code);
        $variations = [$normalized];
        
        $extracted = $this->extractCode($code);
        
        if ($extracted) {
            // Добавляем оригинальный код
            $variations[] = $extracted['code'];
            
            // Добавляем код без префикса
            if (!empty($extracted['body'])) {
                $variations[] = $extracted['body'];
                $variations[] = $this->normalizeCode($extracted['body']);
            }
            
            // Добавляем вариации с разными разделителями
            $body = $extracted['body'];
            
            // Вариант с дефисами
            $withDashes = str_replace('.', '-', $body);
            $variations[] = $withDashes;
            
            if (!empty($extracted['prefix'])) {
                $variations[] = $extracted['prefix'] . '-' . $withDashes;
                $variations[] = $extracted['prefix'] . $withDashes;
            }
            
            // Вариант с точками
            $withDots = str_replace('-', '.', $body);
            $variations[] = $withDots;
            
            if (!empty($extracted['prefix'])) {
                $variations[] = $extracted['prefix'] . '-' . $withDots;
                $variations[] = $extracted['prefix'] . '.' . $withDots;
            }
        }
        
        // Убираем дубликаты и пустые значения
        $variations = array_filter($variations);
        $variations = array_unique($variations);
        
        return array_values($variations);
    }

    /**
     * Определить тип норматива по коду
     * 
     * @param string $code Код норматива
     * @return string Тип норматива (GESN, FER, TER, FSBC, REGIONAL, UNKNOWN)
     */
    public function detectType(string $code): string
    {
        foreach (self::PATTERNS as $type => $pattern) {
            if (preg_match($pattern, $code)) {
                return $type;
            }
        }
        
        return self::TYPE_UNKNOWN;
    }

    /**
     * Проверить, является ли строка кодом норматива
     * 
     * @param string $text Текст для проверки
     * @return bool
     */
    public function isValidCode(string $text): bool
    {
        return $this->extractCode($text) !== null;
    }

    /**
     * Получить префикс кода (ГЭСН, ФЕР и т.д.)
     * 
     * @param string $code Код норматива
     * @return string|null Префикс или null
     */
    public function getPrefix(string $code): ?string
    {
        $extracted = $this->extractCode($code);
        return $extracted['prefix'] ?? null;
    }

    /**
     * Получить тело кода (без префикса)
     * 
     * @param string $code Код норматива
     * @return string|null Тело кода или null
     */
    public function getBody(string $code): ?string
    {
        $extracted = $this->extractCode($code);
        return $extracted['body'] ?? null;
    }

    /**
     * Сравнить два кода на эквивалентность
     * 
     * @param string $code1 Первый код
     * @param string $code2 Второй код
     * @return bool Эквивалентны ли коды
     */
    public function areEquivalent(string $code1, string $code2): bool
    {
        $normalized1 = $this->normalizeCode($code1);
        $normalized2 = $this->normalizeCode($code2);
        
        if ($normalized1 === $normalized2) {
            return true;
        }
        
        // Сравниваем без префиксов
        $body1 = $this->getBody($code1);
        $body2 = $this->getBody($code2);
        
        if ($body1 && $body2) {
            return $this->normalizeCode($body1) === $this->normalizeCode($body2);
        }
        
        return false;
    }

    /**
     * Получить читаемое описание типа норматива
     * 
     * @param string $type Тип норматива
     * @return string Описание
     */
    public function getTypeDescription(string $type): string
    {
        return match($type) {
            self::TYPE_GESN => 'ГЭСН (Государственные элементные сметные нормы)',
            self::TYPE_FER => 'ФЕР (Федеральные единичные расценки)',
            self::TYPE_TER => 'ТЕР (Территориальные единичные расценки)',
            self::TYPE_FSBC => 'ФСБЦ (Федеральная служба по ценообразованию)',
            self::TYPE_REGIONAL => 'Региональный норматив',
            default => 'Неизвестный тип норматива',
        };
    }

    /**
     * Разобрать код на компоненты
     * 
     * @param string $code Код норматива
     * @return array Компоненты кода
     */
    public function parseCodeComponents(string $code): array
    {
        $extracted = $this->extractCode($code);
        
        if (!$extracted) {
            return [
                'is_valid' => false,
                'original' => $code,
            ];
        }
        
        $body = $extracted['body'];
        
        // Пробуем разобрать структуру кода
        // Обычно: ХХ-YY-ZZZ-AA где XX-раздел, YY-подраздел, ZZZ-номер, AA-модификация
        $parts = preg_split('/[-.]/', $body);
        
        return [
            'is_valid' => true,
            'original' => $code,
            'normalized' => $this->normalizeCode($code),
            'type' => $extracted['type'],
            'type_description' => $this->getTypeDescription($extracted['type']),
            'prefix' => $extracted['prefix'],
            'body' => $body,
            'parts' => $parts,
            'section' => $parts[0] ?? null,
            'subsection' => $parts[1] ?? null,
            'number' => $parts[2] ?? null,
            'modification' => $parts[3] ?? null,
            'clean_text' => $extracted['clean_text'],
        ];
    }
}

