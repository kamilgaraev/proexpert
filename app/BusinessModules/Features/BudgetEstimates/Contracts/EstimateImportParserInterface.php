<?php

namespace App\BusinessModules\Features\BudgetEstimates\Contracts;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use Generator;

interface EstimateImportParserInterface
{
    /**
     * Parse the entire file and return a DTO (Legacy / Small files).
     */
    public function parse(string $filePath): EstimateImportDTO|Generator;

    /**
     * Get a generator that yields standardized row arrays or DTOs.
     * This is the preferred method for the new Pipeline architecture.
     * 
     * @param string $filePath
     * @param array $options Optional configuration (header_row, mapping, etc.)
     * @return Generator yielding EstimateImportRowDTO
     */
    public function getStream(string $filePath, array $options = []): Generator;
    
    /**
     * Get the first N rows for preview and structure detection.
     * 
     * @param string $filePath
     * @param int $limit
     * @param array $options
     * @return array of EstimateImportRowDTO or raw arrays
     */
    public function getPreview(string $filePath, int $limit = 20, array $options = []): array;
    
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
    
    /**
     * Читать содержимое файла для детекции типа сметы (без полного парсинга)
     */
    public function readContent(string $filePath, int $maxRows = 100);
}
