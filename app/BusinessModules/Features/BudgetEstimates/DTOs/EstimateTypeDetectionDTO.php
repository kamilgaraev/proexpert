<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\DTOs;

/**
 * DTO для результата определения типа сметы
 */
class EstimateTypeDetectionDTO
{
    public function __construct(
        public string $detectedType = 'custom',
        public float $confidence = 0.0,
        public array $indicators = [],
        public array $candidates = [],
        public array $metadata = []
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
            'is_high_confidence' => $this->isHighConfidence(0.9),
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
        $confidencePercent = $this->confidence <= 1.0 ? $this->confidence * 100 : $this->confidence;
        $thresholdPercent = $threshold <= 1.0 ? $threshold * 100 : $threshold;

        return $confidencePercent >= $thresholdPercent;
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
            'prohelper_template' => 'Шаблон Prohelper',
            'xml_estimate' => 'XML Смета (ГрандСмета, GGE или совместимый формат)',
            'pdf_estimate' => 'PDF смета',
            'custom' => 'Произвольная таблица (без официальных кодов)',
            default => 'Неизвестный тип',
        };
    }
}

