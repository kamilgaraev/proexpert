<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class ConstructionSemanticParser
{
    /**
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $documents
     * @return array<string, mixed>
     */
    public function parse(array $input, array $documents): array
    {
        $description = (string) ($input['description'] ?? '');
        $documentsPayload = array_map(function (array $document): array {
            $text = (string) ($document['extracted_text'] ?? '');
            $facts = is_array($document['facts'] ?? null) ? $document['facts'] : [];
            $factsSummary = is_array($document['facts_summary'] ?? null) ? $document['facts_summary'] : [];

            return [
                'id' => $document['id'] ?? null,
                'filename' => $document['filename'] ?? 'document',
                'status' => $document['status'] ?? null,
                'text' => $text,
                'facts' => $facts,
                'facts_summary' => $factsSummary,
                'quality' => is_array($document['quality'] ?? null) ? $document['quality'] : [],
                'source_refs' => $this->extractSourceRefs($text),
                'scopes' => $this->extractScopes($text, false),
            ];
        }, $documents);

        $documentContext = $this->buildDocumentContext($documentsPayload);
        $combinedText = trim($description . "\n" . ($documentContext['context_text'] ?? ''));
        $objectDescription = trim($description . "\n" . ($documentContext['context_text'] ?? ''));
        $buildingType = (string) ($input['building_type'] ?? $this->detectBuildingType($combinedText) ?? 'custom');
        $objectType = (string) ($input['object_type'] ?? $this->detectObjectType($objectDescription, $documentContext) ?? $buildingType);
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
            : $this->inferDefaultScopes($buildingType, $sourceRefs, $objectDescription !== '' ? $objectDescription : $description);
        $regionalContext = is_array($input['regional_context'] ?? null) ? $input['regional_context'] : [];
        $period = $this->detectPeriod($combinedText);

        return [
            'object' => [
                'manual_description' => $description,
                'description' => $objectDescription !== '' ? $objectDescription : $description,
                'object_type' => $objectType,
                'building_type' => $buildingType,
                'region' => $input['region'] ?? $regionalContext['region_name'] ?? $this->detectRegion($combinedText),
                'area' => $input['area'] ?? $documentContext['facts_summary']['total_area_m2'] ?? null,
                'floors' => $input['floors'] ?? $documentContext['facts_summary']['floor_count'] ?? null,
                'height' => $input['height'] ?? $documentContext['facts_summary']['height_m'] ?? null,
                'dimensions' => is_array($input['dimensions'] ?? null)
                    ? $input['dimensions']
                    : ($documentContext['facts_summary']['dimensions'] ?? []),
                'zones' => $documentContext['facts_summary']['zones'] ?? [],
                'engineering_systems' => array_column($documentContext['facts_summary']['engineering_systems'] ?? [], 'key'),
                'document_facts' => $documentContext['facts'],
                'year' => $regionalContext['year'] ?? $period['year'],
                'quarter' => $regionalContext['quarter'] ?? $period['quarter'],
                'contingency_percent' => $this->detectContingencyPercent($combinedText),
            ],
            'regional_context' => $regionalContext,
            'document_context' => $documentContext,
            'problem_flags' => $documentContext['problem_flags'] ?? [],
            'source_documents' => $documentsPayload,
            'detected_structure' => [
                'floors' => array_values(array_unique($sourceRefs['floors'])),
                'elevations' => array_values(array_unique($sourceRefs['elevations'])),
                'sheets' => array_values(array_unique($sourceRefs['sheets'])),
                'zones' => $this->normalizeDetectedZones($scopes['zones']),
                'constructives' => array_values(array_unique($scopes['constructives'])),
                'scopes' => $scopes['items'],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $documentsPayload
     * @return array<string, mixed>
     */
    private function buildDocumentContext(array $documentsPayload): array
    {
        $facts = [];
        $summary = [
            'total_area_m2' => null,
            'floor_count' => null,
            'height_m' => null,
            'dimensions' => [],
            'zones' => [],
            'engineering_systems' => [],
            'conflicts' => [],
        ];
        $contextLines = [];
        $sourceRefs = [];
        $trustedDocumentIds = [];
        $reviewRequiredDocuments = [];
        $problemFlags = [];

        foreach ($documentsPayload as $document) {
            if (!$this->isDocumentTrusted($document)) {
                $reviewRequiredDocuments[] = [
                    'id' => $document['id'] ?? null,
                    'filename' => $document['filename'] ?? 'document',
                    'status' => $document['status'] ?? null,
                    'quality' => $document['quality'] ?? [],
                ];
                $problemFlags[] = 'document_review_required';
                continue;
            }

            if (($document['id'] ?? null) !== null) {
                $trustedDocumentIds[] = (int) $document['id'];
            }

            foreach ($document['facts'] ?? [] as $fact) {
                if (!is_array($fact)) {
                    continue;
                }

                $facts[] = $fact;

                if (is_array($fact['source_ref'] ?? null)) {
                    $sourceRefs[] = $fact['source_ref'];
                }

                if (($summary['height_m'] ?? null) === null && ($fact['fact_type'] ?? null) === 'height' && isset($fact['value_number'])) {
                    $summary['height_m'] = (float) $fact['value_number'];
                }

                if (($fact['fact_type'] ?? null) === 'dimension' && is_array($fact['normalized_payload'] ?? null)) {
                    $payload = $fact['normalized_payload'];

                    if (isset($payload['length'], $payload['width'])) {
                        $summary['dimensions'] = [
                            'length' => (float) $payload['length'],
                            'width' => (float) $payload['width'],
                        ];
                    }
                }
            }

            $factsSummary = is_array($document['facts_summary'] ?? null) ? $document['facts_summary'] : [];

            if (($summary['total_area_m2'] ?? null) === null && isset($factsSummary['total_area_m2'])) {
                $summary['total_area_m2'] = $factsSummary['total_area_m2'];
            }

            if (($summary['floor_count'] ?? null) === null && isset($factsSummary['floor_count'])) {
                $summary['floor_count'] = $factsSummary['floor_count'];
            }

            foreach ($factsSummary['zones'] ?? [] as $zone) {
                if (is_array($zone)) {
                    $summary['zones'][] = $zone;
                }
            }

            foreach ($factsSummary['engineering_systems'] ?? [] as $system) {
                if (is_array($system)) {
                    $summary['engineering_systems'][] = $system;
                }
            }

            foreach ($factsSummary['conflicts'] ?? [] as $conflict) {
                if (is_array($conflict)) {
                    $summary['conflicts'][] = $conflict;
                    $problemFlags[] = 'document_fact_conflict';
                }
            }

            $text = trim((string) ($document['text'] ?? ''));

            if ($text !== '') {
                $contextLines[] = $text;
            }
        }

        return [
            'facts' => $facts,
            'facts_summary' => $summary,
            'context_text' => trim(implode("\n", $contextLines)),
            'source_refs' => $this->uniqueSourceRefs($sourceRefs),
            'trusted_document_ids' => array_values(array_unique($trustedDocumentIds)),
            'review_required_documents' => $reviewRequiredDocuments,
            'problem_flags' => array_values(array_unique($problemFlags)),
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function isDocumentTrusted(array $document): bool
    {
        if (($document['status'] ?? null) === 'ignored') {
            return false;
        }

        $quality = is_array($document['quality'] ?? null) ? $document['quality'] : [];
        $level = (string) ($quality['level'] ?? '');

        if ($level !== '' && !in_array($level, ['good', 'acceptable'], true)) {
            return false;
        }

        return in_array((string) ($document['status'] ?? 'ready'), ['ready', 'uploaded'], true);
    }

    /**
     * @param array<int, array<string, mixed>> $sourceRefs
     * @return array<int, array<string, mixed>>
     */
    private function uniqueSourceRefs(array $sourceRefs): array
    {
        $unique = [];

        foreach ($sourceRefs as $sourceRef) {
            $key = implode('|', [
                $sourceRef['type'] ?? 'document',
                $sourceRef['document_id'] ?? '',
                $sourceRef['page_number'] ?? '',
                $sourceRef['excerpt'] ?? '',
            ]);

            $unique[$key] = $sourceRef;
        }

        return array_values($unique);
    }

    /**
     * @param array<string, mixed> $documentContext
     */
    private function detectObjectType(string $description, array $documentContext): ?string
    {
        $normalized = mb_strtolower($description);
        $zones = is_array($documentContext['facts_summary']['zones'] ?? null)
            ? $documentContext['facts_summary']['zones']
            : [];
        $zoneText = mb_strtolower(implode(' ', array_map(
            static fn (array $zone): string => (string) ($zone['label'] ?? $zone['scope_key'] ?? ''),
            array_filter($zones, 'is_array')
        )));

        $hasWarehouse = str_contains($normalized, 'склад')
            || str_contains($normalized, 'warehouse')
            || str_contains($zoneText, 'склад')
            || str_contains($zoneText, 'warehouse');
        $hasOffice = str_contains($normalized, 'офис')
            || str_contains($normalized, 'office')
            || str_contains($zoneText, 'офис')
            || str_contains($zoneText, 'office');

        if ($hasWarehouse && $hasOffice) {
            return 'mixed_warehouse_office';
        }

        if ($hasWarehouse || str_contains($normalized, 'производ') || str_contains($normalized, 'цех')) {
            return 'warehouse';
        }

        return null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function scopeKeywords(): array
    {
        return [
            'rough_finishing' => ['черновая отделка', 'стяжка', 'штукатурка стен'],
            'finish_finishing' => ['чистовая отделка', 'ламинат', 'плитка', 'обои', 'покраска'],
            'foundation' => ['фундамент', 'основание под', 'бетонная подготовка', 'ростверк', 'свая'],
            'walls' => ['кладоч', 'стен', 'перегород', 'несущие стены', 'наружные стены', 'газобетон'],
            'slabs' => ['перекрыт', 'плита перекрытия', 'монолитная плита', 'бетонный пол', 'промышленным бетонным полом', 'промышленный пол'],
            'roof' => ['кровл', 'стропил', 'крыша', 'металлочерепиц', 'плоская кровля'],
            'openings' => ['окна', 'двери', 'стеклопакет', 'проем', 'проём', 'ворот', 'входная группа'],
            'electrical' => ['электрика', 'электромонтаж', 'щит', 'кабель', 'розет', 'светильник', 'свет', 'освещ'],
            'plumbing' => ['водопровод', 'водоснабжен', 'канализац', 'септик', 'скважин', 'трубы'],
            'heating' => ['отоплен', 'котел', 'котёл', 'радиатор', 'разводка отопления'],
            'ventilation' => ['вентиляц', 'приточн', 'клапан'],
            'fire_safety' => ['пожарн', 'сигнализац', 'оповещен', 'оповещён'],
            'stairs' => ['лестниц', 'лестничн'],
            'facade' => ['фасад', 'утеплен', 'утеплён', 'облицовк', 'сэндвич-панел', 'сендвич-панел'],
            'finishing' => ['отделк', 'штукатур', 'окраск', 'облицовка'],
            'site' => ['благоустрой', 'наружн', 'землян', 'отмостк', 'разгруз', 'площадк', 'подъезд', 'проезд'],
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, zones: array<int, string>, constructives: array<int, string>}
     */
    protected function extractScopes(string $text, bool $strictMode): array
    {
        $items = [];
        $zones = [];
        $constructives = [];
        $keywords = $this->scopeKeywords();

        foreach ($this->scopeCandidateLines($text, $keywords) as $line) {
            $normalizedLine = trim(mb_strtolower($this->cleanLine($line)));
            if ($normalizedLine === '') {
                continue;
            }

            $scopeType = $this->detectScopeType($normalizedLine, $keywords);
            if ($scopeType === null) {
                continue;
            }

            if ($strictMode && !$this->isScopeCandidateLine($normalizedLine, $scopeType)) {
                continue;
            }

            $items[] = [
                'title' => $this->normalizeScopeTitle($line, $scopeType),
                'scope_type' => $scopeType,
                'source_refs' => $this->extractSourceRefs($line),
            ];
            $zones[] = $this->extractZoneTitle($line);
            $constructives[] = $scopeType;
        }

        return [
            'items' => $this->uniqueScopeItems($items),
            'zones' => array_values(array_filter(array_unique($zones))),
            'constructives' => array_values(array_unique($constructives)),
        ];
    }

    /**
     * @param array<string, array<int, string>> $keywords
     */
    protected function detectScopeType(string $normalizedLine, array $keywords): ?string
    {
        foreach ($keywords as $scopeType => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($normalizedLine, $pattern)) {
                    return $scopeType;
                }
            }
        }

        return null;
    }

    protected function isScopeCandidateLine(string $normalizedLine, string $scopeType): bool
    {
        if (mb_strlen($normalizedLine) <= 120) {
            return true;
        }

        if ($scopeType !== '' && mb_strlen($normalizedLine) <= 220) {
            return true;
        }

        if (preg_match('/\b\d+\s*этаж\b/u', $normalizedLine) === 1) {
            return true;
        }

        if (preg_match('/(?<![\p{L}\p{N}])(?:л\.?|лист)\s*\d+/ui', $normalizedLine) === 1) {
            return true;
        }

        if (preg_match('/отм\.?\s*[+\-]?\d/u', $normalizedLine) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, zones: array<int, string>, constructives: array<int, string>}
     */
    protected function inferDefaultScopes(string $buildingType, array $sourceRefs, string $description): array
    {
        $normalizedBuildingType = mb_strtolower($buildingType);
        $normalizedDescription = mb_strtolower($description);
        $isResidentialLike = in_array($normalizedBuildingType, ['ижс', 'жилой', 'residential', 'custom'], true)
            || str_contains($normalizedDescription, 'жил')
            || str_contains($normalizedDescription, 'спальн')
            || str_contains($normalizedDescription, 'гостиная');
        $isWarehouseLike = str_contains($normalizedBuildingType, 'склад')
            || str_contains($normalizedBuildingType, 'производ')
            || str_contains($normalizedDescription, 'склад')
            || str_contains($normalizedDescription, 'производ')
            || str_contains($normalizedDescription, 'офисно-склад');

        if ($isWarehouseLike) {
            $defaults = [
                ['title' => 'Подготовка площадки', 'scope_type' => 'site'],
                ['title' => 'Земляные работы', 'scope_type' => 'foundation'],
                ['title' => 'Фундаменты', 'scope_type' => 'foundation'],
                ['title' => 'Промышленный пол', 'scope_type' => 'slabs'],
                ['title' => 'Металлокаркас', 'scope_type' => 'structural'],
                ['title' => 'Фасад и ограждающие конструкции', 'scope_type' => 'facade'],
                ['title' => 'Кровля', 'scope_type' => 'roof'],
                ['title' => 'Ворота и входная группа', 'scope_type' => 'openings'],
                ['title' => 'Электроснабжение', 'scope_type' => 'electrical'],
                ['title' => 'Освещение', 'scope_type' => 'electrical'],
                ['title' => 'Отопление', 'scope_type' => 'heating'],
                ['title' => 'Вентиляция', 'scope_type' => 'ventilation'],
                ['title' => 'Пожарная безопасность', 'scope_type' => 'fire_safety'],
                ['title' => 'Водоснабжение и канализация', 'scope_type' => 'plumbing'],
                ['title' => 'Наружные площадки и подъезды', 'scope_type' => 'site'],
            ];
        } elseif ($isResidentialLike) {
            $defaults = [
                ['title' => 'Фундамент', 'scope_type' => 'foundation'],
                ['title' => 'Стены и перегородки', 'scope_type' => 'walls'],
                ['title' => 'Перекрытия', 'scope_type' => 'slabs'],
                ['title' => 'Кровля', 'scope_type' => 'roof'],
                ['title' => 'Окна и двери', 'scope_type' => 'openings'],
                ['title' => 'Электрика', 'scope_type' => 'electrical'],
                ['title' => 'Водопровод и канализация', 'scope_type' => 'plumbing'],
                ['title' => 'Отопление', 'scope_type' => 'heating'],
                ['title' => 'Вентиляция', 'scope_type' => 'ventilation'],
                ['title' => 'Черновая отделка', 'scope_type' => 'rough_finishing'],
                ['title' => 'Чистовая отделка', 'scope_type' => 'finish_finishing'],
            ];
        } else {
            $defaults = [
                ['title' => 'Основные строительные работы', 'scope_type' => 'custom'],
                ['title' => 'Инженерные системы', 'scope_type' => 'engineering'],
            ];
        }

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

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
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
        $title = $this->extractZoneTitle($line);

        return match ($scopeType) {
            'foundation' => $title !== '' ? $title : 'Фундамент',
            'walls' => $title !== '' ? $title : 'Стены и перегородки',
            'slabs' => $title !== '' ? $title : 'Перекрытия',
            'roof' => $title !== '' ? $title : 'Кровля',
            'openings' => $title !== '' ? $title : 'Окна и двери',
            'electrical' => $title !== '' ? $title : 'Электрика',
            'plumbing' => $title !== '' ? $title : 'Водопровод и канализация',
            'heating' => $title !== '' ? $title : 'Отопление',
            'ventilation' => $title !== '' ? $title : 'Вентиляция',
            'rough_finishing' => $title !== '' ? $title : 'Черновая отделка',
            'finish_finishing' => $title !== '' ? $title : 'Чистовая отделка',
            'facade' => $title !== '' ? $title : 'Фасад',
            'finishing' => $title !== '' ? $title : 'Отделка',
            'site' => $title !== '' ? $title : 'Внешние работы',
            default => $title !== '' ? $title : 'Строительные работы',
        };
    }

    /**
     * @return array{sheets: array<int, string>, elevations: array<int, string>, floors: array<int, string>}
     */
    protected function extractSourceRefs(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}])(?:л\.?|лист)\s*\d+(?:\s*и\s*\d+)?/ui', $text, $sheetMatches);
        preg_match_all('/отм\.?\s*[+\-]?\d+(?:[.,]\d+)?(?:\s*до\s*[+\-]?\d+(?:[.,]\d+)?)?/ui', $text, $elevationMatches);

        return [
            'sheets' => array_values(array_unique($sheetMatches[0] ?? [])),
            'elevations' => array_values(array_unique($elevationMatches[0] ?? [])),
            'floors' => $this->extractFloors($text),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function extractFloors(string $text): array
    {
        preg_match_all('/(\d+)\s*этаж/iu', $text, $matches);

        $floors = array_map(static fn (string $value): string => $value . ' этаж', $matches[1] ?? []);
        $normalized = mb_strtolower($text);

        foreach ([
            1 => ['первом этаже', 'первый этаж', '1-м этаже', '1 этаже'],
            2 => ['втором этаже', 'второй этаж', '2-м этаже', '2 этаже'],
            3 => ['третьем этаже', 'третий этаж', '3-м этаже', '3 этаже'],
        ] as $number => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    $floors[] = $number . ' этаж';
                    break;
                }
            }
        }

        if (str_contains($normalized, 'двухэтаж') || str_contains($normalized, 'двух этаж')) {
            $floors[] = '1 этаж';
            $floors[] = '2 этаж';
        }

        if (str_contains($normalized, 'трехэтаж') || str_contains($normalized, 'трёхэтаж') || str_contains($normalized, 'три этажа')) {
            $floors[] = '1 этаж';
            $floors[] = '2 этаж';
            $floors[] = '3 этаж';
        }

        return array_values(array_unique($floors));
    }

    /**
     * @param array<string, array<int, string>> $keywords
     * @return array<int, string>
     */
    protected function scopeCandidateLines(string $text, array $keywords): array
    {
        $lines = preg_split('/\r\n|\r|\n/u', $text) ?: [];
        $candidates = [];

        foreach ($lines as $line) {
            foreach (preg_split('/[.;]\s*/u', $line) ?: [] as $sentence) {
                $sentence = trim($sentence);

                if ($sentence === '') {
                    continue;
                }

                $parts = preg_split('/,\s*/u', $sentence) ?: [];

                if (count($parts) === 1) {
                    $candidates[] = $sentence;
                    continue;
                }

                foreach ($parts as $part) {
                    $part = trim($part);

                    if ($part === '') {
                        continue;
                    }

                    if ($this->detectScopeType(mb_strtolower($this->cleanLine($part)), $keywords) !== null) {
                        foreach ($this->expandCompoundScopePart($part, $keywords) as $candidate) {
                            $candidates[] = $candidate;
                        }
                    }
                }
            }
        }

        return $candidates;
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

    protected function detectRegion(string $text): ?string
    {
        $normalized = mb_strtolower($text);

        return match (true) {
            str_contains($normalized, 'московская область') || str_contains($normalized, 'подмосков') => 'Московская область',
            str_contains($normalized, 'москва') => 'Москва',
            str_contains($normalized, 'республика татарстан') || str_contains($normalized, 'татарстан') => 'Республика Татарстан',
            default => null,
        };
    }

    /**
     * @return array{year: ?int, quarter: ?int}
     */
    protected function detectPeriod(string $text): array
    {
        $normalized = mb_strtolower($text);
        $year = null;
        $quarter = null;

        if (preg_match('/(20\d{2})/u', $normalized, $matches) === 1) {
            $year = (int) $matches[1];
        }

        if (preg_match('/(?<!\d)([1-4])\s*(?:кв|квартал)/u', $normalized, $matches) === 1) {
            $quarter = (int) $matches[1];
        } elseif (preg_match('/\b(i|ii|iii|iv)\s*(?:кв|квартал)/ui', $normalized, $matches) === 1) {
            $quarter = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4][mb_strtolower($matches[1])] ?? null;
        } elseif (str_contains($normalized, 'первый квартал')) {
            $quarter = 1;
        } elseif (str_contains($normalized, 'второй квартал')) {
            $quarter = 2;
        } elseif (str_contains($normalized, 'третий квартал')) {
            $quarter = 3;
        } elseif (str_contains($normalized, 'четвертый квартал') || str_contains($normalized, 'четвёртый квартал')) {
            $quarter = 4;
        }

        return ['year' => $year, 'quarter' => $quarter];
    }

    protected function detectContingencyPercent(string $text): ?float
    {
        if (preg_match('/(?:непредвид|резерв)[^%\d]{0,40}(\d{1,2}(?:[.,]\d+)?)\s*%/ui', $text, $matches) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    protected function extractZoneTitle(string $line): string
    {
        $clean = preg_replace('/\s*\(.+\)\s*$/u', '', trim($this->cleanLine($line)));
        $clean = (string) preg_replace('/^(?:еще|ещё)\s+/iu', '', $clean);
        $clean = (string) preg_replace('/^(?:нужна|нужно|нужны|требуется|предусмотреть|сделать)\s+/iu', '', $clean);
        $clean = trim($clean);
        $normalized = mb_strtolower($clean);

        $canonical = match (true) {
            str_contains($normalized, 'входная группа') => 'Входная группа',
            str_contains($normalized, 'пожар') && str_contains($normalized, 'сигнализац') => 'Пожарная сигнализация',
            str_contains($normalized, 'освещ') => 'Освещение',
            str_contains($normalized, 'водоснабжен') || str_contains($normalized, 'водопровод') || str_contains($normalized, 'канализац') => 'Водоснабжение и канализация',
            default => null,
        };

        if ($canonical !== null) {
            return $canonical;
        }

        return $clean ?: trim($this->cleanLine($line));
    }

    /**
     * @param array<int, string> $zones
     * @return array<int, string>
     */
    protected function normalizeDetectedZones(array $zones): array
    {
        $normalized = [];
        $hasPlumbing = false;

        foreach ($zones as $zone) {
            $zone = trim($zone);

            if ($zone === '') {
                continue;
            }

            if ($zone === 'Водоснабжение и канализация') {
                $hasPlumbing = true;
                continue;
            }

            $normalized[] = $zone;
        }

        if ($hasPlumbing) {
            $normalized[] = 'Водоснабжение и канализация';
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, array<int, string>> $keywords
     * @return array<int, string>
     */
    protected function expandCompoundScopePart(string $part, array $keywords): array
    {
        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => trim($segment),
            preg_split('/\s+и\s+/u', $part) ?: []
        )));

        if (count($segments) < 2) {
            return [$part];
        }

        $segmentTypes = [];

        foreach ($segments as $segment) {
            $type = $this->detectScopeType(mb_strtolower($this->cleanLine($segment)), $keywords);

            if ($type === null) {
                return [$part];
            }

            $segmentTypes[] = $type;
        }

        return count(array_unique($segmentTypes)) > 1 ? $segments : [$part];
    }

    protected function cleanLine(string $line): string
    {
        $line = trim($line);
        $line = trim($line, "* \t\n\r\0\x0B");

        return (string) preg_replace('/^\s*[-*•]+\s*/u', '', $line);
    }
}
