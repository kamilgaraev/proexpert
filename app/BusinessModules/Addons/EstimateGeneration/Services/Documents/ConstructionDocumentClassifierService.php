<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Documents;

final class ConstructionDocumentClassifierService
{
    public function classify(string $filename, ?string $mimeType, int $pageCount, string $text): array
    {
        $value = mb_strtolower($filename . "\n" . $mimeType . "\n" . $text);
        $reasons = [];
        $type = 'unknown';
        $score = 0.2;

        if ($this->containsAny($value, ['.xlsx', '.xls', 'spreadsheet'])) {
            $reasons[] = 'spreadsheet_extension';
            $score += 0.35;
        }

        if ($this->containsAny($value, ['спецификация', 'ведомость оборудования', 'поз.', 'количество', 'ед.'])) {
            $type = 'specification';
            $reasons[] = 'specification_marker';
            $score += 0.35;
        }

        if ($this->containsAny($value, ['локальная смета', 'гранд-смет', 'фер ', 'гэсн ', 'фсбц ', 'обоснование'])) {
            $type = 'estimate';
            $reasons[] = 'estimate_marker';
            $score += 0.45;
        }

        $isStructuredDocument = in_array($type, ['specification', 'estimate'], true);

        if (! $isStructuredDocument && $this->containsAny($value, ['ар-', 'лист ар', 'архитектур', 'план этажа', 'экспликация помещений'])) {
            $type = 'drawing_architecture';
            $reasons[] = 'architectural_marker';
            $score += 0.55;
        }

        if (! $isStructuredDocument && $this->containsAny($value, ['кр-', 'лист кр', 'конструктив', 'армирование', 'монолит', 'фундамент'])) {
            $type = 'drawing_structural';
            $reasons[] = 'structural_marker';
            $score += 0.55;
        }

        if (! $isStructuredDocument && $this->containsAny($value, [' ов ', 'ов-', 'лист ов', 'вентиляция', 'воздуховод', 'отопление', 'теплоснабжение'])) {
            $type = 'drawing_engineering_hvac';
            $reasons[] = 'hvac_marker';
            $score += 0.45;
        }

        if (! $isStructuredDocument && $this->containsAny($value, [' вк ', 'вк-', 'лист вк', 'водоснабжение', 'канализация', 'трубопровод', 'санузел'])) {
            $type = 'drawing_engineering_water';
            $reasons[] = 'water_marker';
            $score += 0.45;
        }

        if (! $isStructuredDocument && $this->containsAny($value, ['эом', ' эо ', 'эо-', 'освещение', 'кабель', 'щит', 'щр-', 'электроснабжение'])) {
            $type = 'drawing_engineering_electrical';
            $reasons[] = 'electrical_marker';
            $score += 0.55;
        }

        if ($type === 'unknown' && $this->containsAny($value, ['масштаб', 'м 1:', 'план', 'разрез', 'фасад'])) {
            $type = 'drawing_architecture';
            $reasons[] = 'drawing_marker';
            $score += 0.35;
        }

        if ($pageCount > 1 && str_starts_with($type, 'drawing_')) {
            $reasons[] = 'multi_page_project_document';
            $score += 0.05;
        }

        return [
            'type' => $type,
            'confidence' => min(round($score, 2), 0.99),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (mb_stripos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
