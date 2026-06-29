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

        $isCadDocument = $this->containsAny($value, ['.dwg', '.dxf', 'autocad', 'drawing/cad']);

        if ($isCadDocument) {
            $type = 'drawing_cad';
            $reasons[] = 'cad_extension';
            $score += 0.45;
        }

        if (! $isCadDocument && $this->isWorkVolumeStatement($value)) {
            $type = 'work_volume_statement';
            $reasons[] = 'work_volume_statement_marker';
            $score += 0.45;
        }

        if (! $isCadDocument && $this->containsAny($value, ['спецификация', 'ведомость оборудования', 'поз.', 'количество', 'ед.'])) {
            if ($type !== 'work_volume_statement') {
                $type = 'specification';
                $reasons[] = 'specification_marker';
                $score += 0.35;
            } else {
                $reasons[] = 'quantity_table_marker';
                $score += 0.12;
            }
        }

        $hasStrongEstimateMarker = $this->containsAny($value, ['локальная смета', 'гранд-смет', 'итого по смете']);
        $hasNormativeMarker = $this->containsAny($value, ['фер ', 'гэсн ', 'фсбц ', 'обоснование']);

        if (! $isCadDocument && ($hasStrongEstimateMarker || $hasNormativeMarker)) {
            $reasons[] = 'estimate_marker';
            $reasons[] = $hasStrongEstimateMarker ? 'strong_estimate_marker' : 'normative_estimate_marker';
            $score += $hasStrongEstimateMarker ? 0.45 : 0.15;

            if ($hasStrongEstimateMarker || !in_array($type, ['work_volume_statement', 'specification'], true)) {
                $type = 'estimate';
            }
        }

        $isStructuredDocument = in_array($type, ['work_volume_statement', 'specification', 'estimate', 'drawing_cad'], true);

        if (! $isStructuredDocument && $this->hasFloorPlanGeometryDensity($value)) {
            $type = 'floor_plan';
            $isStructuredDocument = true;
            $reasons[] = 'floor_plan_geometry_marker';
            $score += 0.55;
        }

        if (! $isStructuredDocument && $this->hasFloorPlanLayoutSignals($value)) {
            $type = 'floor_plan';
            $isStructuredDocument = true;
            $reasons[] = 'floor_plan_layout_marker';
            $score += 0.55;
        }

        if (! $isStructuredDocument && $this->containsAny($value, ['ар-', 'лист ар', 'архитектур', 'план этажа', 'экспликация помещений'])) {
            $type = 'drawing_architecture';
            $isStructuredDocument = true;
            $reasons[] = 'architectural_marker';
            $score += 0.55;
        }

        if (! $isStructuredDocument && $this->containsAny($value, ['кр-', 'лист кр', 'конструктив', 'армирование', 'монолит', 'фундамент'])) {
            $type = 'drawing_structural';
            $isStructuredDocument = true;
            $reasons[] = 'structural_marker';
            $score += 0.55;
        }

        if (! $isStructuredDocument && $this->containsAny($value, [' ов ', 'ов-', 'лист ов', 'вентиляция', 'воздуховод', 'отопление', 'теплоснабжение'])) {
            $type = 'drawing_engineering_hvac';
            $isStructuredDocument = true;
            $reasons[] = 'hvac_marker';
            $score += 0.45;
        }

        if (! $isStructuredDocument && $this->containsAny($value, [' вк ', 'вк-', 'лист вк', 'водоснабжение', 'канализация', 'трубопровод', 'санузел'])) {
            $type = 'drawing_engineering_water';
            $isStructuredDocument = true;
            $reasons[] = 'water_marker';
            $score += 0.45;
        }

        if (! $isStructuredDocument && $this->containsAny($value, ['эом', ' эо ', 'эо-', 'освещение', 'кабель', 'щит', 'щр-', 'электроснабжение'])) {
            $type = 'drawing_engineering_electrical';
            $isStructuredDocument = true;
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

    private function isWorkVolumeStatement(string $value): bool
    {
        return preg_match('/ведомость\s+(?:объемов|объёмов|работ)|объемы?\s+работ|объёмы?\s+работ|(?:^|[^\p{L}\p{N}])вор(?:$|[^\p{L}\p{N}])/u', $value) === 1;
    }

    private function hasFloorPlanGeometryDensity(string $value): bool
    {
        if (! $this->isVisualSource($value)) {
            return false;
        }

        $stats = $this->floorPlanGeometryStats($value);

        return $stats['area_count'] >= 3 && $stats['dimension_count'] >= 4;
    }

    private function hasFloorPlanLayoutSignals(string $value): bool
    {
        if (! $this->isVisualSource($value)) {
            return false;
        }

        if (! $this->containsAny($value, ['планировка', 'план квартиры', 'план дома', 'экспликация помещений'])) {
            return false;
        }

        $stats = $this->floorPlanGeometryStats($value);

        return $stats['area_count'] >= 2 && $stats['dimension_count'] >= 1;
    }

    /**
     * @return array{area_count: int, dimension_count: int}
     */
    private function floorPlanGeometryStats(string $value): array
    {
        preg_match_all('/\d+(?:[,.]\d+)?\s*(?:м\s*2|м2|м²|m\s*2|m2|m²|кв\.\s*м)/u', $value, $areaMatches);
        preg_match_all('/(?<![\d,.])\d{3,5}(?![\d,.])/', $value, $dimensionMatches);

        return [
            'area_count' => count(array_unique($areaMatches[0] ?? [])),
            'dimension_count' => count(array_unique($dimensionMatches[0] ?? [])),
        ];
    }

    private function isVisualSource(string $value): bool
    {
        return $this->containsAny($value, [
            '.png',
            '.jpg',
            '.jpeg',
            '.webp',
            '.tif',
            '.tiff',
            '.bmp',
            'image/',
            '.pdf',
            'application/pdf',
        ]);
    }
}
