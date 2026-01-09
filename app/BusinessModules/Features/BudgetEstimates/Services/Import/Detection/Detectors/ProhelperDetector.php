<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\EstimateTypeDetectorInterface;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Детектор формата Prohelper
 * 
 * Определяет файлы, экспортированные из системы Prohelper
 * по наличию скрытого листа _METADATA_ с JSON метаданными
 */
class ProhelperDetector implements EstimateTypeDetectorInterface
{
    public function detect($content): array
    {
        $indicators = [];
        $confidence = 0;

        if (!($content instanceof \PhpOffice\PhpSpreadsheet\Spreadsheet)) {
            return ['confidence' => 0, 'indicators' => ['Не является Excel файлом']];
        }

        // Check for _METADATA_ sheet
        $hasMetadataSheet = false;
        $metadataSheetName = null;
        
        foreach ($content->getSheetNames() as $sheetName) {
            if ($sheetName === '_METADATA_') {
                $hasMetadataSheet = true;
                $metadataSheetName = $sheetName;
                break;
            }
        }

        if (!$hasMetadataSheet) {
            return ['confidence' => 0, 'indicators' => ['Отсутствует лист _METADATA_']];
        }

        $indicators[] = 'Найден скрытый лист _METADATA_';
        $confidence += 40;

        // Try to parse metadata
        $metadataSheet = $content->getSheetByName($metadataSheetName);
        $metadataJson = $metadataSheet->getCell('A1')->getValue();

        if (!$metadataJson || !is_string($metadataJson)) {
            $indicators[] = 'Лист _METADATA_ пустой или некорректный';
            return ['confidence' => $confidence, 'indicators' => $indicators];
        }

        $indicators[] = 'Найдены JSON метаданные';
        $confidence += 20;

        // Try to decode JSON
        $metadata = json_decode($metadataJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $indicators[] = 'Метаданные не являются валидным JSON';
            return ['confidence' => $confidence, 'indicators' => $indicators];
        }

        $indicators[] = 'JSON метаданные успешно распарсены';
        $confidence += 10;

        // Check for prohelper_export flag
        if (isset($metadata['prohelper_export']) && $metadata['prohelper_export'] === true) {
            $indicators[] = 'Найден флаг prohelper_export = true';
            $confidence += 20;
        } else {
            $indicators[] = 'Отсутствует флаг prohelper_export';
            return ['confidence' => $confidence, 'indicators' => $indicators];
        }

        // Check version
        if (isset($metadata['version'])) {
            $indicators[] = 'Версия экспорта: ' . $metadata['version'];
            $confidence += 5;
        }

        // Check essential fields
        if (isset($metadata['estimate_id'])) {
            $indicators[] = 'Найден estimate_id: ' . $metadata['estimate_id'];
            $confidence += 5;
        }

        if (isset($metadata['sections']) && is_array($metadata['sections'])) {
            $sectionsCount = count($metadata['sections']);
            $indicators[] = "Найдено разделов: {$sectionsCount}";
            $confidence += 5;
        }

        if (isset($metadata['items']) && is_array($metadata['items'])) {
            $itemsCount = count($metadata['items']);
            $indicators[] = "Найдено позиций: {$itemsCount}";
            $confidence += 5;
        }

        // Check main sheet has Prohelper branding
        $mainSheet = $content->getSheet(0);
        $cellA1 = $mainSheet->getCell('A1')->getValue();
        
        if ($cellA1 && stripos((string)$cellA1, 'prohelper') !== false) {
            $indicators[] = 'Найден брендинг Prohelper в шапке';
            $confidence += 10;
        }

        // Confidence should be 100 if all checks passed
        return [
            'confidence' => min(100, $confidence),
            'indicators' => $indicators,
        ];
    }

    public function getType(): string
    {
        return 'prohelper';
    }

    public function getDescription(): string
    {
        return 'Смета Prohelper (с полными метаданными для импорта)';
    }
}
