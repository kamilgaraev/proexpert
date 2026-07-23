<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

final readonly class RoomAreaAnnotationParser
{
    /** @return array{name: string, area_m2: float, included_in_floor_area: bool}|null */
    public function parse(?string $label): ?array
    {
        if ($label === null || preg_match('/[<>@\x00-\x08\x0B\x0C\x0E-\x1F]/u', $label) === 1) {
            return null;
        }
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $label));
        if (preg_match('/^(.+?)\s+([0-9]{1,3}[,.][0-9]{1,2})\s*(?:м(?:2|²))?$/ui', $normalized, $matches) !== 1) {
            return null;
        }
        $name = trim($matches[1]);
        if ($name === '' || preg_match('/\p{L}/u', $name) !== 1 || preg_match('/№\s*$/u', $name) === 1
            || preg_match('/^(?:ось|размер|высота|отметка|axis|scale)\b/ui', $name) === 1) {
            return null;
        }
        $area = (float) str_replace(',', '.', $matches[2]);
        if (! is_finite($area) || $area < 0.5 || $area > 500.0) {
            return null;
        }

        return [
            'name' => $name,
            'area_m2' => $area,
            'included_in_floor_area' => preg_match('/(?:веранд|террас|балкон|лоджи|крыльц|пандус|навес)/ui', $name) !== 1,
        ];
    }
}
