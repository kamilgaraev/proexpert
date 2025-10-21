<?php

namespace App\BusinessModules\Features\BudgetEstimates\Contracts;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;

interface EstimateImportParserInterface
{
    public function parse(string $filePath): EstimateImportDTO;
    
    public function detectStructure(string $filePath): array;
    
    public function validateFile(string $filePath): bool;
    
    public function getSupportedExtensions(): array;
    
    /**
     * Возвращает всех кандидатов на роль заголовка (для выбора пользователем)
     */
    public function getHeaderCandidates(): array;
    
    /**
     * Определяет структуру файла из указанной строки заголовков
     */
    public function detectStructureFromRow(string $filePath, int $headerRow): array;
}

