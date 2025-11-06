<?php

namespace App\BusinessModules\Features\BudgetEstimates\DTOs;

use App\Models\NormativeRate;

/**
 * DTO для результатов поиска норматива по коду
 */
class NormativeMatchResultDTO
{
    public function __construct(
        public readonly string $originalCode,
        public readonly ?string $normalizedCode,
        public readonly ?NormativeRate $normativeRate,
        public readonly int $confidence,
        public readonly string $matchMethod,
        public readonly ?string $matchedVariation = null,
        public readonly array $autoFilled = [],
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * Создать DTO для успешного совпадения
     */
    public static function success(
        string $originalCode,
        string $normalizedCode,
        NormativeRate $normativeRate,
        int $confidence,
        string $matchMethod,
        ?string $matchedVariation = null,
        array $autoFilled = []
    ): self {
        return new self(
            originalCode: $originalCode,
            normalizedCode: $normalizedCode,
            normativeRate: $normativeRate,
            confidence: $confidence,
            matchMethod: $matchMethod,
            matchedVariation: $matchedVariation,
            autoFilled: $autoFilled,
            errorMessage: null,
        );
    }

    /**
     * Создать DTO для несовпадения
     */
    public static function notFound(
        string $originalCode,
        ?string $normalizedCode = null,
        ?string $errorMessage = null
    ): self {
        return new self(
            originalCode: $originalCode,
            normalizedCode: $normalizedCode,
            normativeRate: null,
            confidence: 0,
            matchMethod: 'not_found',
            matchedVariation: null,
            autoFilled: [],
            errorMessage: $errorMessage ?? 'Норматив не найден в справочнике',
        );
    }

    /**
     * Проверить, найден ли норматив
     */
    public function isFound(): bool
    {
        return $this->normativeRate !== null;
    }

    /**
     * Проверить, точное ли это совпадение
     */
    public function isExactMatch(): bool
    {
        return $this->confidence === 100 && $this->matchMethod === 'exact_code';
    }

    /**
     * Проверить, найдено ли с вариациями кода
     */
    public function isFuzzyMatch(): bool
    {
        return $this->matchMethod === 'fuzzy_code' || $this->matchMethod === 'normalized_code';
    }

    /**
     * Проверить, найдено ли по названию (fallback)
     */
    public function isNameMatch(): bool
    {
        return $this->matchMethod === 'name_match';
    }

    /**
     * Получить описание метода поиска
     */
    public function getMethodDescription(): string
    {
        return match($this->matchMethod) {
            'exact_code' => 'Точное совпадение кода',
            'fuzzy_code' => 'Найдено с вариациями кода',
            'normalized_code' => 'Найдено по нормализованному коду',
            'name_match' => 'Найдено по названию',
            'not_found' => 'Не найдено',
            default => 'Неизвестный метод',
        };
    }

    /**
     * Получить цветовой индикатор для UI
     */
    public function getConfidenceColor(): string
    {
        return match(true) {
            $this->confidence >= 95 => 'green',
            $this->confidence >= 80 => 'yellow',
            $this->confidence >= 60 => 'orange',
            default => 'red',
        };
    }

    /**
     * Преобразовать в массив для JSON
     */
    public function toArray(): array
    {
        return [
            'original_code' => $this->originalCode,
            'normalized_code' => $this->normalizedCode,
            'is_found' => $this->isFound(),
            'confidence' => $this->confidence,
            'confidence_color' => $this->getConfidenceColor(),
            'match_method' => $this->matchMethod,
            'match_method_description' => $this->getMethodDescription(),
            'matched_variation' => $this->matchedVariation,
            'normative_rate' => $this->normativeRate ? [
                'id' => $this->normativeRate->id,
                'code' => $this->normativeRate->code,
                'name' => $this->normativeRate->name,
                'description' => $this->normativeRate->description,
                'measurement_unit' => $this->normativeRate->measurement_unit,
                'base_price' => (float) $this->normativeRate->base_price,
                'collection' => [
                    'id' => $this->normativeRate->collection->id ?? null,
                    'code' => $this->normativeRate->collection->code ?? null,
                    'name' => $this->normativeRate->collection->name ?? null,
                ],
            ] : null,
            'auto_filled' => $this->autoFilled,
            'error_message' => $this->errorMessage,
        ];
    }

    /**
     * Преобразовать для краткого отображения
     */
    public function toSummary(): array
    {
        return [
            'code' => $this->originalCode,
            'found' => $this->isFound(),
            'confidence' => $this->confidence,
            'method' => $this->matchMethod,
            'normative_name' => $this->normativeRate?->name,
        ];
    }

    /**
     * Получить данные для логирования
     */
    public function toLogData(): array
    {
        return [
            'original_code' => $this->originalCode,
            'normalized_code' => $this->normalizedCode,
            'found' => $this->isFound(),
            'confidence' => $this->confidence,
            'method' => $this->matchMethod,
            'normative_id' => $this->normativeRate?->id,
            'normative_code' => $this->normativeRate?->code,
            'error' => $this->errorMessage,
        ];
    }
}

