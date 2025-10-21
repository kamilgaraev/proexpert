<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

interface HeaderDetectorInterface
{
    /**
     * Обнаруживает потенциальные строки заголовков в листе
     *
     * @param Worksheet $sheet
     * @return array Массив кандидатов с метаданными
     */
    public function detectCandidates(Worksheet $sheet): array;

    /**
     * Оценивает качество кандидата как заголовка
     *
     * @param array $candidate
     * @param array $context Дополнительный контекст (worksheet, другие кандидаты и т.д.)
     * @return float Оценка от 0.0 до 1.0
     */
    public function scoreCandidate(array $candidate, array $context = []): float;

    /**
     * Выбирает лучшего кандидата из массива
     *
     * @param array $candidates
     * @return array|null Лучший кандидат или null
     */
    public function selectBest(array $candidates): ?array;

    /**
     * Возвращает имя детектора
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Возвращает вес этого детектора при комбинировании результатов
     *
     * @return float
     */
    public function getWeight(): float;
}

