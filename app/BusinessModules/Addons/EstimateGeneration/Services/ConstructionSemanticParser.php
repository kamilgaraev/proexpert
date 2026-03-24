<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class ConstructionSemanticParser
{
    public function parse(array $input, array $documents): array
    {
        $description = (string) ($input['description'] ?? '');
        $documentsPayload = array_map(function (array $document): array {
            $text = (string) ($document['extracted_text'] ?? '');

            return [
                'filename' => $document['filename'] ?? 'document',
                'text' => $text,
                'source_refs' => $this->extractSourceRefs($text),
                'scopes' => $this->extractScopes($text, false),
            ];
        }, $documents);

        $combinedText = trim($description . "\n" . implode("\n", array_column($documentsPayload, 'text')));
        $buildingType = (string) ($input['building_type'] ?? $this->detectBuildingType($combinedText) ?? 'custom');
        $sourceRefs = $this->extractSourceRefs($combinedText);
        $explicitScopes = $this->mergeScopes(
            $this->extractScopes($description, true),
            ...array_map(
                static fn (array $document): array => $document['scopes'],
                $documentsPayload
            )
        );

        $scopes = $explicitScopes['items'] !== []
            ? $explicitScopes
            : $this->inferDefaultScopes($buildingType, $sourceRefs, $description);

        return [
            'object' => [
                'description' => $description,
                'building_type' => $buildingType,
                'region' => $input['region'] ?? null,
                'area' => $input['area'] ?? null,
            ],
            'source_documents' => $documentsPayload,
            'detected_structure' => [
                'floors' => array_values(array_unique($sourceRefs['floors'])),
                'elevations' => array_values(array_unique($sourceRefs['elevations'])),
                'sheets' => array_values(array_unique($sourceRefs['sheets'])),
                'zones' => array_values(array_unique($scopes['zones'])),
                'constructives' => array_values(array_unique($scopes['constructives'])),
                'scopes' => $scopes['items'],
            ],
        ];
    }

    protected function extractScopes(string $text, bool $strictMode): array
    {
        $lines = preg_split('/\r\n|\r|\n/u', $text) ?: [];
        $items = [];
        $zones = [];
        $constructives = [];

        $keywords = [
            'foundation' => ['фундамент', 'основание под', 'бетонная подготовка', 'ростверк', 'свая'],
            'walls' => ['кладоч', 'стен', 'перегород', 'несущие стены', 'наружные стены'],
            'slabs' => ['перекрыт', 'плита перекрытия', 'монолитная плита'],
            'roof' => ['кровл', 'стропил', 'крыша'],
            'facade' => ['фасад', 'утеплен', 'облицовк'],
            'engineering' => ['венткамера', 'чиллер', 'котельная', 'топочная', 'инженерное оборудование', 'вентиляц', 'отоплен', 'водоснабжен', 'канализац', 'электроснабжен'],
            'finishing' => ['отделк', 'штукатур', 'окраск', 'облицовка'],
            'site' => ['благоустрой', 'наружн', 'землян', 'отмостк'],
        ];

        foreach ($lines as $line) {
            $normalizedLine = trim(mb_strtolower($line));
            if ($normalizedLine === '') {
                continue;
            }

            if ($strictMode && !$this->isScopeCandidateLine($normalizedLine)) {
                continue;
            }

            foreach ($keywords as $scopeType => $patterns) {
                foreach ($patterns as $pattern) {
                    if (str_contains($normalizedLine, $pattern)) {
                        $items[] = [
                            'title' => $this->normalizeScopeTitle($line, $scopeType),
                            'scope_type' => $scopeType,
                            'source_refs' => $this->extractSourceRefs($line),
                        ];
                        $zones[] = $this->extractZoneTitle($line);
                        $constructives[] = $scopeType;
                        break 2;
                    }
                }
            }
        }

        return [
            'items' => $this->uniqueScopeItems($items),
            'zones' => array_values(array_filter(array_unique($zones))),
            'constructives' => array_values(array_unique($constructives)),
        ];
    }

    protected function isScopeCandidateLine(string $normalizedLine): bool
    {
        if (mb_strlen($normalizedLine) <= 80) {
            return true;
        }

        if (preg_match('/\b\d+\s*этаж\b/u', $normalizedLine) === 1) {
            return true;
        }

        if (preg_match('/л\.?\s*\d+/u', $normalizedLine) === 1) {
            return true;
        }

        if (preg_match('/отм\.?\s*[+\-]?\d/u', $normalizedLine) === 1) {
            return true;
        }

        return false;
    }

    protected function inferDefaultScopes(string $buildingType, array $sourceRefs, string $description): array
    {
        $normalizedBuildingType = mb_strtolower($buildingType);
        $floorsCount = count($sourceRefs['floors'] ?? []);
        $isResidentialLike = in_array($normalizedBuildingType, ['ижс', 'residential', 'custom'], true)
            || str_contains(mb_strtolower($description), 'жил')
            || str_contains(mb_strtolower($description), 'спальн')
            || str_contains(mb_strtolower($description), 'гостиная');

        $defaults = $isResidentialLike
            ? [
                ['title' => 'Фундамент', 'scope_type' => 'foundation'],
                ['title' => 'Стены и перегородки', 'scope_type' => 'walls'],
                ['title' => 'Перекрытия', 'scope_type' => 'slabs'],
                ['title' => 'Кровля', 'scope_type' => 'roof'],
                ['title' => 'Фасад', 'scope_type' => 'facade'],
                ['title' => 'Инженерные системы', 'scope_type' => 'engineering'],
            ]
            : [
                ['title' => 'Основные строительные работы', 'scope_type' => 'custom'],
                ['title' => 'Инженерные системы', 'scope_type' => 'engineering'],
            ];

        $items = array_map(function (array $scope) use ($sourceRefs): array {
            return [
                'title' => $scope['title'],
                'scope_type' => $scope['scope_type'],
                'source_refs' => $sourceRefs,
            ];
        }, $defaults);

        return [
            'items' => $items,
            'zones' => [],
            'constructives' => array_values(array_unique(array_column($items, 'scope_type'))),
        ];
    }

    protected function mergeScopes(array ...$scopeGroups): array
    {
        $items = [];
        $zones = [];
        $constructives = [];

        foreach ($scopeGroups as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $items[] = $item;
            }

            foreach ($group['zones'] ?? [] as $zone) {
                $zones[] = $zone;
            }

            foreach ($group['constructives'] ?? [] as $constructive) {
                $constructives[] = $constructive;
            }
        }

        return [
            'items' => $this->uniqueScopeItems($items),
            'zones' => array_values(array_filter(array_unique($zones))),
            'constructives' => array_values(array_unique($constructives)),
        ];
    }

    protected function uniqueScopeItems(array $items): array
    {
        $unique = [];

        foreach ($items as $item) {
            $key = mb_strtolower(($item['scope_type'] ?? 'custom') . '|' . ($item['title'] ?? ''));
            $unique[$key] = $item;
        }

        return array_values($unique);
    }

    protected function normalizeScopeTitle(string $line, string $scopeType): string
    {
        $title = trim($line);

        return match ($scopeType) {
            'foundation' => $title !== '' ? $title : 'Фундамент',
            'walls' => $title !== '' ? $title : 'Стены и перегородки',
            'slabs' => $title !== '' ? $title : 'Перекрытия',
            'roof' => $title !== '' ? $title : 'Кровля',
            'facade' => $title !== '' ? $title : 'Фасад',
            'engineering' => $title !== '' ? $title : 'Инженерные системы',
            'finishing' => $title !== '' ? $title : 'Отделка',
            'site' => $title !== '' ? $title : 'Внешние работы',
            default => $title !== '' ? $title : 'Строительные работы',
        };
    }

    protected function extractSourceRefs(string $text): array
    {
        preg_match_all('/л\.?\s*\d+(?:\s*и\s*\d+)?/ui', $text, $sheetMatches);
        preg_match_all('/отм\.?\s*[+\-]?\d+(?:[.,]\d+)?(?:\s*до\s*[+\-]?\d+(?:[.,]\d+)?)?/ui', $text, $elevationMatches);

        return [
            'sheets' => array_values(array_unique($sheetMatches[0] ?? [])),
            'elevations' => array_values(array_unique($elevationMatches[0] ?? [])),
            'floors' => $this->extractFloors($text),
        ];
    }

    protected function extractFloors(string $text): array
    {
        preg_match_all('/(\d+)\s*этаж/iu', $text, $matches);

        return array_values(array_unique(array_map(static fn (string $value): string => $value . ' этаж', $matches[1] ?? [])));
    }

    protected function detectBuildingType(string $text): ?string
    {
        $normalized = mb_strtolower($text);

        return match (true) {
            str_contains($normalized, 'ижс') => 'ижс',
            str_contains($normalized, 'жил') => 'residential',
            str_contains($normalized, 'торгов') || str_contains($normalized, 'офис') => 'commercial',
            str_contains($normalized, 'цех') || str_contains($normalized, 'производ') => 'industrial',
            default => null,
        };
    }

    protected function extractZoneTitle(string $line): string
    {
        $clean = preg_replace('/\s*\(.+\)\s*$/u', '', trim($line));

        return $clean ?: trim($line);
    }
}
