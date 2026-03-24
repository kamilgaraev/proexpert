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
                'scopes' => $this->extractScopes($text),
            ];
        }, $documents);

        $combinedText = trim($description . "\n" . implode("\n", array_column($documentsPayload, 'text')));
        $scopes = $this->extractScopes($combinedText);
        $sourceRefs = $this->extractSourceRefs($combinedText);
        $buildingType = $input['building_type'] ?? $this->detectBuildingType($combinedText);

        return [
            'object' => [
                'description' => $description,
                'building_type' => $buildingType ?: 'custom',
                'region' => $input['region'] ?? null,
                'area' => $input['area'] ?? null,
            ],
            'source_documents' => $documentsPayload,
            'detected_structure' => [
                'floors' => array_values(array_unique($this->extractFloors($combinedText))),
                'elevations' => array_values(array_unique($sourceRefs['elevations'])),
                'sheets' => array_values(array_unique($sourceRefs['sheets'])),
                'zones' => array_values(array_unique($scopes['zones'])),
                'constructives' => array_values(array_unique($scopes['constructives'])),
                'scopes' => $scopes['items'],
            ],
        ];
    }

    protected function extractScopes(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/u', $text) ?: [];
        $items = [];
        $zones = [];
        $constructives = [];
        $keywords = [
            'foundation' => ['фундамент', 'основание', 'подготовк'],
            'walls' => ['кладоч', 'стен', 'перегород'],
            'slabs' => ['перекрыт', 'плит'],
            'roof' => ['кровл', 'стропил'],
            'facade' => ['фасад', 'утеплен'],
            'engineering' => ['венткам', 'чиллер', 'инженер'],
            'finishing' => ['отделк', 'штукатур', 'окраск'],
            'site' => ['благоустрой', 'наружн', 'землян'],
        ];

        foreach ($lines as $line) {
            $normalizedLine = trim(mb_strtolower($line));
            if ($normalizedLine === '') {
                continue;
            }

            foreach ($keywords as $scopeType => $patterns) {
                foreach ($patterns as $pattern) {
                    if (str_contains($normalizedLine, $pattern)) {
                        $items[] = [
                            'title' => trim($line),
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

        if ($items === []) {
            $items[] = [
                'title' => 'Общая смета по объекту',
                'scope_type' => 'custom',
                'source_refs' => $this->extractSourceRefs($text),
            ];
        }

        return [
            'items' => $items,
            'zones' => array_filter($zones),
            'constructives' => $constructives,
        ];
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
        preg_match_all('/(\d+)\s*этаж/ui', $text, $matches);

        return array_values(array_unique(array_map(static fn (string $value): string => $value . ' этаж', $matches[1] ?? [])));
    }

    protected function detectBuildingType(string $text): ?string
    {
        $normalized = mb_strtolower($text);

        return match (true) {
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
