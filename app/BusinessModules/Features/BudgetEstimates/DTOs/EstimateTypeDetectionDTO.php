<?php

namespace App\BusinessModules\Features\BudgetEstimates\DTOs;

/**
 * DTO для результата определения типа сметы
 */
class EstimateTypeDetectionDTO
{
    public function __construct(
        public string $detectedType,        // 'grandsmeta', 'rik', 'fer', 'smartsmeta', 'custom'
        public float $confidence,           // 0-100
        public array $indicators,           // ['title_grandsmeta', 'columns_match', ...]
        public array $candidates,           // Альтернативные типы с их confidence
        public array $metadata = []         // Дополнительные метаданные
    ) {}
    
    /**
     * Создать из массива результатов детектора
     */
    public static function fromDetectorResult(array $result): self
    {
        return new self(
            detectedType: $result['best']['type'],
            confidence: $result['best']['confidence'],
            indicators: $result['best']['indicators'],
            candidates: $result['all'] ?? [],
            metadata: $result['best']['metadata'] ?? []
        );
    }
    
    /**
     * Преобразовать в массив
     */
    public function toArray(): array
    {
        return [
            'detected_type' => $this->detectedType,
            'confidence' => $this->confidence,
            'indicators' => $this->indicators,
            'candidates' => $this->candidates,
            'metadata' => $this->metadata,
        ];
    }
    
    /**
     * Проверить, является ли confidence достаточно высоким для автоматического выбора
     */
    public function isHighConfidence(float $threshold = 60.0): bool
    {
        return $this->confidence >= $threshold;
    }
    
    /**
     * Получить описание детектированного типа
     */
    public function getTypeDescription(): string
    {
        return match($this->detectedType) {
            'grandsmeta' => 'ГрандСмета (экспорт из программы)',
            'rik' => 'РИК (Ресурсно-индексный метод)',
            'fer' => 'ФЕР/ГЭСН (Федеральные/Государственные расценки)',
            'smartsmeta' => 'SmartSmeta / Smeta.ru',
            'prohelper' => 'Смета Prohelper (с полными метаданными для импорта)',
            'xml_estimate' => 'XML Смета (ГрандСмета, GGE или совместимый формат)',
            'custom' => 'Произвольная таблица (без официальных кодов)',
            default => 'Неизвестный тип',
        };
    }
}

