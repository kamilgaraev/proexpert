<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\ObjectProfileData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\PackagePlanData;
use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;

use function trans_message;

class PackagePlannerService
{
    public function plan(ObjectProfileData $profile): PackagePlanData
    {
        if ($this->isPlanOnlyGeometry($profile) && !$this->usesAiAssistedHouseExpansion($profile)) {
            $packages = $this->floorPlanEvidencePackages($profile);
        } elseif ($this->isDocumentEvidenceOnly($profile)) {
            $packages = $this->documentEvidencePackages($profile);
        } elseif ($this->isMixedWarehouseOffice($profile)) {
            $packages = $this->mixedWarehouseOfficePackages();
        } elseif ($this->isWarehouse($profile)) {
            $packages = $this->warehousePackages();
        } else {
            $packages = $this->housePackages();
        }

        $packages = $this->withReviewPackages($packages, $profile);
        $packages = $this->withOptionalSitePackages($packages, $profile);

        return new PackagePlanData(
            packages: array_map(fn (array $package, int $index): array => $this->packagePayload($package, $index), $packages, array_keys($packages)),
            assumptions: $profile->assumptions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function documentRequirements(ObjectProfileData $profile): array
    {
        $mode = $this->generationMode($profile);
        $isPlanOnlyGeometry = $this->isPlanOnlyGeometry($profile);
        $isHouse = $this->isHouse($profile);
        $missingForFullEstimate = $isHouse && $isPlanOnlyGeometry;

        return [
            'mode' => $mode->value,
            'coverage' => match (true) {
                $mode === EstimateGenerationMode::AiAssisted && $missingForFullEstimate => 'assumption_expanded',
                $mode === EstimateGenerationMode::StrictDocuments && $missingForFullEstimate => 'document_limited',
                default => 'document_backed',
            },
            'missing_for_full_estimate' => $missingForFullEstimate,
            'items' => $missingForFullEstimate ? $this->fullHouseRequiredDocuments() : [],
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     */
    public function profileFromAnalysis(array $analysis): ObjectProfileData
    {
        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];
        $regionalContext = is_array($analysis['regional_context'] ?? null) ? $analysis['regional_context'] : [];
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $objectType = mb_strtolower((string) ($object['object_type'] ?? ''));
        $buildingType = mb_strtolower((string) ($object['building_type'] ?? ''));
        $type = $objectType !== '' ? $objectType : ($buildingType !== '' ? $buildingType : 'custom');
        $zones = is_array($object['zones'] ?? null) ? $object['zones'] : [];
        $zoneText = implode(' ', array_map(
            static fn (array $zone): string => (string) ($zone['label'] ?? $zone['scope_key'] ?? ''),
            array_filter($zones, 'is_array')
        ));
        $description = mb_strtolower(implode("\n", array_filter([
            (string) ($object['description'] ?? ''),
            (string) ($documentContext['context_text'] ?? ''),
            $zoneText,
        ])));
        $hasWarehouse = $this->containsWarehouseObjectSignal($type)
            || $this->containsWarehouseObjectSignal($description);
        $hasIndustrial = $this->containsIndustrialObjectSignal($type)
            || $this->containsIndustrialObjectSignal($description);
        $hasOffice = $this->containsOfficeSignal($type)
            || $this->containsOfficeSignal($description);
        $planningSignals = $this->planningSignalsFromAnalysis($analysis, $description);
        $planningSignals['generation_mode'] = $this->generationModeFromAnalysis($analysis)->value;

        $hasExplicitObjectType = $objectType !== '' && $objectType !== 'custom';
        $hasExplicitBuildingType = $buildingType !== '' && $buildingType !== 'custom';

        if ($this->containsResidentialObjectSignal($buildingType)) {
            $type = 'house';
        } elseif (str_contains($type, 'mixed_warehouse_office') || (($hasWarehouse || $hasIndustrial) && $hasOffice)) {
            $type = 'mixed_warehouse_office';
        } elseif ($hasWarehouse || $hasIndustrial || str_contains($type, 'industrial')) {
            $type = 'warehouse';
        } elseif ($this->containsResidentialObjectSignal($type)) {
            $type = 'house';
        } elseif (($planningSignals['plan_only_geometry'] ?? false) === true) {
            $type = 'floor_plan_geometry';
        } elseif (!$hasExplicitObjectType && !$hasExplicitBuildingType && $this->hasDocumentQuantityEvidence($planningSignals)) {
            $type = 'document_evidence';
        }

        return new ObjectProfileData(
            objectType: $type,
            area: isset($object['area']) ? (float) $object['area'] : null,
            floors: $this->nullableInt($object['floors'] ?? null),
            rooms: $this->nullableInt($object['rooms'] ?? null),
            regionCode: isset($regionalContext['region_code']) ? (string) $regionalContext['region_code'] : null,
            regionalPriceVersionId: $this->nullableInt($regionalContext['estimate_regional_price_version_id'] ?? null),
            quarterKey: $this->quarterKey($regionalContext),
            dimensions: is_array($object['dimensions'] ?? null) ? $object['dimensions'] : [],
            finishLevels: is_array($object['finish_levels'] ?? null) ? array_values($object['finish_levels']) : [],
            engineeringSystems: is_array($object['engineering_systems'] ?? null) ? array_values($object['engineering_systems']) : [],
            assumptions: is_array($analysis['assumptions'] ?? null) ? array_values($analysis['assumptions']) : [],
            missingInputs: is_array($analysis['missing_inputs'] ?? null) ? array_values($analysis['missing_inputs']) : [],
            confidence: isset($analysis['confidence']) ? (float) $analysis['confidence'] : 0.65,
            planningSignals: $planningSignals,
        );
    }

    private function isWarehouse(ObjectProfileData $profile): bool
    {
        $type = mb_strtolower($profile->objectType);

        return str_contains($type, 'warehouse')
            || str_contains($type, 'склад')
            || str_contains($type, 'industrial')
            || str_contains($type, 'производ');
    }

    private function isMixedWarehouseOffice(ObjectProfileData $profile): bool
    {
        return mb_strtolower($profile->objectType) === 'mixed_warehouse_office';
    }

    private function isPlanOnlyGeometry(ObjectProfileData $profile): bool
    {
        return mb_strtolower($profile->objectType) === 'floor_plan_geometry'
            || $this->hasPlanningSignal($profile, 'plan_only_geometry');
    }

    private function isDocumentEvidenceOnly(ObjectProfileData $profile): bool
    {
        return mb_strtolower($profile->objectType) === 'document_evidence';
    }

    private function isHouse(ObjectProfileData $profile): bool
    {
        return mb_strtolower($profile->objectType) === 'house'
            || $this->containsResidentialObjectSignal(mb_strtolower($profile->objectType));
    }

    private function usesAiAssistedHouseExpansion(ObjectProfileData $profile): bool
    {
        return $this->generationMode($profile) === EstimateGenerationMode::AiAssisted
            && $this->isHouse($profile);
    }

    private function generationMode(ObjectProfileData $profile): EstimateGenerationMode
    {
        return EstimateGenerationMode::fromInput($profile->planningSignals['generation_mode'] ?? null);
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function generationModeFromAnalysis(array $analysis): EstimateGenerationMode
    {
        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];

        return EstimateGenerationMode::fromInput($object['generation_mode'] ?? $analysis['generation_mode'] ?? null);
    }

    /**
     * @param array<string, mixed> $planningSignals
     */
    private function hasDocumentQuantityEvidence(array $planningSignals): bool
    {
        return ($planningSignals['document_quantity_evidence'] ?? false) === true
            || (is_array($planningSignals['quantity_keys'] ?? null) && $planningSignals['quantity_keys'] !== []);
    }

    private function containsResidentialObjectSignal(string $text): bool
    {
        return preg_match('/(?:^|[^\p{L}\p{N}])(?:ижс|жил\p{L}*|дом|house|residential)(?=$|[^\p{L}\p{N}])/u', $text) === 1;
    }

    private function containsWarehouseObjectSignal(string $text): bool
    {
        $text = preg_replace('/(?:^|[^\p{L}\p{N}])складирован\p{L}*(?=$|[^\p{L}\p{N}])/u', ' ', $text) ?? $text;

        return preg_match('/(?:^|[^\p{L}\p{N}])(?:офисно-склад\p{L}*|склад(?:ск\p{L}*|[а-я]{0,3})?|warehouse)(?=$|[^\p{L}\p{N}])/u', $text) === 1;
    }

    private function containsIndustrialObjectSignal(string $text): bool
    {
        return preg_match('/(?:^|[^\p{L}\p{N}])(?:производствен\p{L}*|цех\p{L}*|industrial)(?=$|[^\p{L}\p{N}])/u', $text) === 1;
    }

    private function containsOfficeSignal(string $text): bool
    {
        return preg_match('/(?:^|[^\p{L}\p{N}])(?:офис\p{L}*|office)(?=$|[^\p{L}\p{N}])/u', $text) === 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function housePackages(): array
    {
        return [
            $this->package('preconstruction', 'Подготовительные работы', 'site', 12, 24),
            $this->package('earthworks', 'Земляные работы', 'foundation', 18, 36),
            $this->package('foundation', 'Фундамент', 'foundation', 30, 55),
            $this->package('walls', 'Стены и перегородки', 'walls', 28, 52),
            $this->package('slabs', 'Перекрытия', 'slabs', 18, 36),
            $this->package('stairs', 'Лестницы', 'stairs', 8, 18),
            $this->package('roof', 'Кровля', 'roof', 24, 45),
            $this->package('openings', 'Окна и двери', 'openings', 14, 28),
            $this->package('facade', 'Фасад', 'facade', 18, 36),
            $this->package('electrical', 'Электрика', 'electrical', 24, 50),
            $this->package('plumbing', 'Водоснабжение', 'plumbing', 16, 34),
            $this->package('sewerage', 'Канализация', 'sewerage', 12, 26),
            $this->package('heating', 'Отопление', 'heating', 18, 40),
            $this->package('ventilation', 'Вентиляция', 'ventilation', 8, 20),
            $this->package('rough_finishing', 'Черновая отделка', 'finishing', 24, 52),
            $this->package('finish_finishing', 'Чистовая отделка', 'finishing', 28, 60),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function warehousePackages(): array
    {
        return [
            $this->package('site_preparation', 'Подготовка площадки', 'site', 30, 60),
            $this->package('earthworks', 'Земляные работы', 'foundation', 45, 90),
            $this->package('foundations', 'Фундаменты', 'foundation', 60, 120),
            $this->package('industrial_floor', 'Промышленный пол', 'slabs', 60, 130),
            $this->package('metal_frame', 'Металлокаркас', 'structural', 70, 150),
            $this->package('envelope', 'Ограждающие конструкции', 'facade', 55, 120),
            $this->package('roof', 'Кровля', 'roof', 45, 100),
            $this->package('gates', 'Ворота и погрузочные узлы', 'openings', 25, 60),
            $this->package('power_supply', 'Электроснабжение', 'electrical', 55, 120),
            $this->package('lighting', 'Освещение', 'electrical', 35, 80),
            $this->package('ventilation', 'Вентиляция', 'ventilation', 45, 100),
            $this->package('heating', 'Отопление', 'heating', 35, 80),
            $this->package('fire_safety', 'Пожарная безопасность', 'engineering', 50, 110),
            $this->package('water_sewerage', 'Водоснабжение и канализация', 'plumbing', 35, 80),
            $this->package('low_current', 'Слаботочные системы', 'electrical', 25, 60),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mixedWarehouseOfficePackages(): array
    {
        return [
            $this->package('site_preparation', 'Подготовка площадки', 'site', 30, 60),
            $this->package('earthworks', 'Земляные работы', 'foundation', 45, 90),
            $this->package('foundations', 'Фундаменты', 'foundation', 60, 120),
            $this->package('industrial_floor', 'Промышленный пол склада', 'slabs', 60, 130),
            $this->package('metal_frame', 'Металлокаркас', 'structural', 70, 150),
            $this->package('envelope', 'Ограждающие конструкции', 'facade', 55, 120),
            $this->package('roof', 'Плоская кровля', 'roof', 45, 100),
            $this->package('gates', 'Ворота и погрузочные узлы', 'openings', 25, 60),
            $this->package('entrance_group', 'Входная группа', 'openings', 16, 36),
            $this->package('office_partitions', 'Офисные перегородки', 'walls', 28, 60),
            $this->package('office_finishing', 'Офисная отделка', 'finishing', 40, 90),
            $this->package('sanitary_rooms', 'Санузлы', 'plumbing', 30, 70),
            $this->package('server_room', 'Серверная и связь', 'electrical', 25, 60),
            $this->package('power_supply', 'Электроснабжение', 'electrical', 55, 120),
            $this->package('lighting', 'Освещение', 'electrical', 35, 80),
            $this->package('ventilation', 'Вентиляция', 'ventilation', 45, 100),
            $this->package('heating', 'Отопление', 'heating', 35, 80),
            $this->package('fire_safety', 'Пожарная безопасность', 'engineering', 50, 110),
            $this->package('water_sewerage', 'Водоснабжение и канализация', 'plumbing', 35, 80),
            $this->package('low_current', 'Слаботочные системы', 'electrical', 25, 60),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $packages
     * @return array<int, array<string, mixed>>
     */
    private function withOptionalSitePackages(array $packages, ObjectProfileData $profile): array
    {
        if ($this->isPlanOnlyGeometry($profile) && !$this->usesAiAssistedHouseExpansion($profile)) {
            return $packages;
        }

        $largeObject = $this->isWarehouse($profile) || $this->isMixedWarehouseOffice($profile);

        if ($this->hasPlanningSignal($profile, 'external_networks')) {
            $packages[] = $this->package('external_networks', 'Наружные сети', 'site', $largeObject ? 40 : 14, $largeObject ? 90 : 32);
        }

        if ($this->hasPlanningSignal($profile, 'siteworks')) {
            $packages[] = $this->package('siteworks', 'Благоустройство', 'site', $largeObject ? 35 : 14, $largeObject ? 80 : 32);
        }

        if ($this->hasPlanningSignal($profile, 'roads')) {
            $packages[] = $this->package('roads', 'Дороги и площадки', 'site', $largeObject ? 35 : 14, $largeObject ? 80 : 32);
        }

        return $packages;
    }

    /**
     * @param array<int, array<string, mixed>> $packages
     * @return array<int, array<string, mixed>>
     */
    private function withReviewPackages(array $packages, ObjectProfileData $profile): array
    {
        if (!$this->hasPlanningSignal($profile, 'unmapped_quantity_rows')) {
            return $packages;
        }

        $packages[] = $this->package('unmapped_quantity_rows', 'Позиции для разбора', 'custom', 1, 20);

        return $packages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documentEvidencePackages(ObjectProfileData $profile): array
    {
        $quantityKeys = is_array($profile->planningSignals['quantity_keys'] ?? null)
            ? array_values(array_map('strval', $profile->planningSignals['quantity_keys']))
            : [];
        $packages = [];

        foreach ($quantityKeys as $quantityKey) {
            $package = $this->documentEvidencePackageForQuantityKey($quantityKey);

            if ($package !== null) {
                $packages[$package['key']] = $package;
            }
        }

        return array_values($packages);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentEvidencePackageForQuantityKey(string $quantityKey): ?array
    {
        if ($quantityKey === '' || str_starts_with($quantityKey, 'unmapped.')) {
            return null;
        }

        return match (true) {
            str_starts_with($quantityKey, 'earth.') => $this->package('custom-earthworks', 'Земляные работы', 'earthworks', 1, 20),
            str_starts_with($quantityKey, 'foundation.') => $this->package('custom-foundation', 'Фундамент', 'foundation', 1, 20),
            str_starts_with($quantityKey, 'walls.')
                || str_starts_with($quantityKey, 'office.partitions') => $this->package('custom-walls', 'Стены и перегородки', 'walls', 1, 20),
            str_starts_with($quantityKey, 'roof.') => $this->package('custom-roof', 'Кровля', 'roof', 1, 20),
            str_starts_with($quantityKey, 'openings.')
                || str_starts_with($quantityKey, 'warehouse.gates') => $this->package('custom-openings', 'Окна, двери и проемы', 'openings', 1, 20),
            str_starts_with($quantityKey, 'rough.')
                || str_starts_with($quantityKey, 'finish.')
                || str_starts_with($quantityKey, 'office.ceiling')
                || str_starts_with($quantityKey, 'sanitary.tile') => $this->package('custom-finishing', 'Отделочные работы', 'finishing', 1, 20),
            str_starts_with($quantityKey, 'electrical.')
                || str_starts_with($quantityKey, 'warehouse.lighting')
                || str_starts_with($quantityKey, 'warehouse.low_current') => $this->package('custom-electrical', 'Электромонтажные работы', 'electrical', 1, 20),
            str_starts_with($quantityKey, 'plumbing.')
                || str_starts_with($quantityKey, 'sanitary.') => $this->package('custom-plumbing', 'Водоснабжение', 'plumbing', 1, 20),
            str_starts_with($quantityKey, 'sewerage.') => $this->package('custom-sewerage', 'Канализация', 'sewerage', 1, 20),
            str_starts_with($quantityKey, 'heating.') => $this->package('custom-heating', 'Отопление', 'heating', 1, 20),
            str_starts_with($quantityKey, 'ventilation.') => $this->package('custom-ventilation', 'Вентиляция', 'ventilation', 1, 20),
            str_starts_with($quantityKey, 'siteworks.') => $this->package('siteworks', 'Благоустройство', 'site', 1, 20),
            str_starts_with($quantityKey, 'networks.') => $this->package('external_networks', 'Наружные сети', 'site', 1, 20),
            str_starts_with($quantityKey, 'warehouse.roads') => $this->package('roads', 'Дороги и площадки', 'site', 1, 20),
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function floorPlanEvidencePackages(ObjectProfileData $profile): array
    {
        $packages = [];

        if ($this->hasPlanningSignal($profile, 'floor_plan_finishing')) {
            $packages[] = $this->package('rough_finishing', 'Черновая отделка по планировке', 'finishing', 8, 20);
            $packages[] = $this->package('finish_finishing', 'Чистовая отделка по планировке', 'finishing', 8, 24);
        }

        if ($this->hasPlanningSignal($profile, 'floor_plan_openings')) {
            $packages[] = $this->package('openings', 'Окна и двери по планировке', 'openings', 4, 14);
        }

        if ($this->hasPlanningSignal($profile, 'floor_plan_electrical')) {
            $packages[] = $this->package('electrical', 'Электрика по спецификации', 'electrical', 6, 18);
        }

        if ($this->hasPlanningSignal($profile, 'floor_plan_plumbing')) {
            $packages[] = $this->package('plumbing', 'Водоснабжение по спецификации', 'plumbing', 6, 18);
        }

        if ($this->hasPlanningSignal($profile, 'floor_plan_sewerage')) {
            $packages[] = $this->package('sewerage', 'Канализация по спецификации', 'sewerage', 4, 14);
        }

        if ($this->hasPlanningSignal($profile, 'floor_plan_heating')) {
            $packages[] = $this->package('heating', 'Отопление по спецификации', 'heating', 6, 18);
        }

        if ($this->hasPlanningSignal($profile, 'floor_plan_ventilation')) {
            $packages[] = $this->package('ventilation', 'Вентиляция по спецификации', 'ventilation', 4, 14);
        }

        return $packages;
    }

    private function hasPlanningSignal(ObjectProfileData $profile, string $key): bool
    {
        return ($profile->planningSignals[$key] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    private function planningSignalsFromAnalysis(array $analysis, string $description): array
    {
        $detectedStructure = is_array($analysis['detected_structure'] ?? null) ? $analysis['detected_structure'] : [];
        $quantityKeys = $this->quantityKeysFromAnalysis($analysis);
        $fragments = [$description];

        foreach (['zones', 'constructives'] as $key) {
            foreach (($detectedStructure[$key] ?? []) as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $fragments[] = $value;
                }
            }
        }

        foreach (($detectedStructure['scopes'] ?? []) as $scope) {
            if (!is_array($scope)) {
                continue;
            }

            foreach (['title', 'scope_type'] as $field) {
                if (is_string($scope[$field] ?? null) && trim($scope[$field]) !== '') {
                    $fragments[] = $scope[$field];
                }
            }
        }

        $haystack = mb_strtolower(implode(' ', $fragments));

        return [
            'external_networks' => $this->containsExternalNetworkSignal($haystack),
            'siteworks' => $this->containsSiteworksSignal($haystack),
            'roads' => $this->containsRoadsSignal($haystack),
            'plan_only_geometry' => $this->hasPlanOnlyGeometryEvidence($quantityKeys, $haystack),
            'floor_plan_finishing' => $this->hasAnyQuantityKey($quantityKeys, [
                'rough.floor',
                'finish.floor',
                'rough.walls',
                'finish.paint',
                'finish.baseboard',
                'office.ceiling',
            ]),
            'floor_plan_openings' => $this->hasAnyQuantityKey($quantityKeys, [
                'openings.doors',
                'openings.windows',
                'openings.gates',
                'warehouse.gates',
            ]),
            'floor_plan_electrical' => $this->hasQuantityPrefix($quantityKeys, 'electrical.')
                || $this->hasAnyQuantityKey($quantityKeys, ['warehouse.lighting']),
            'floor_plan_plumbing' => $this->hasQuantityPrefix($quantityKeys, 'plumbing.')
                || $this->hasQuantityPrefix($quantityKeys, 'sanitary.'),
            'floor_plan_sewerage' => $this->hasQuantityPrefix($quantityKeys, 'sewerage.'),
            'floor_plan_heating' => $this->hasQuantityPrefix($quantityKeys, 'heating.'),
            'floor_plan_ventilation' => $this->hasQuantityPrefix($quantityKeys, 'ventilation.'),
            'unmapped_quantity_rows' => $this->hasQuantityPrefix($quantityKeys, 'unmapped.'),
            'document_quantity_evidence' => $quantityKeys !== [],
            'quantity_keys' => $quantityKeys,
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<int, string>
     */
    private function quantityKeysFromAnalysis(array $analysis): array
    {
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $keys = [];

        foreach ($documentContext['quantity_takeoffs'] ?? [] as $takeoff) {
            if (!is_array($takeoff)) {
                continue;
            }

            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $key = (string) ($payload['quantity_key'] ?? $takeoff['quantity_key'] ?? $this->quantityKeyFromTakeoffScope((string) ($takeoff['scope_key'] ?? '')));

            if ($key !== '') {
                $keys[] = $key;
            }
        }

        foreach ($documentContext['scope_inferences'] ?? [] as $inference) {
            if (!is_array($inference)) {
                continue;
            }

            $payload = is_array($inference['normalized_payload'] ?? null) ? $inference['normalized_payload'] : [];
            $key = (string) ($payload['quantity_key'] ?? '');

            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<int, string> $quantityKeys
     */
    private function hasPlanOnlyGeometryEvidence(array $quantityKeys, string $haystack): bool
    {
        if (!$this->hasAnyQuantityKey($quantityKeys, ['rough.floor', 'finish.floor', 'rough.walls', 'finish.baseboard', 'office.ceiling'])) {
            return false;
        }

        return preg_match('/(?:планировк|план\s|экспликац|room_area|floor_plan)/u', $haystack) === 1
            || $this->hasAnyQuantityKey($quantityKeys, ['rough.walls', 'office.ceiling']);
    }

    /**
     * @param array<int, string> $quantityKeys
     * @param array<int, string> $needles
     */
    private function hasAnyQuantityKey(array $quantityKeys, array $needles): bool
    {
        return array_intersect($quantityKeys, $needles) !== [];
    }

    /**
     * @param array<int, string> $quantityKeys
     */
    private function hasQuantityPrefix(array $quantityKeys, string $prefix): bool
    {
        foreach ($quantityKeys as $quantityKey) {
            if (str_starts_with($quantityKey, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function quantityKeyFromTakeoffScope(string $scopeKey): string
    {
        return match ($scopeKey) {
            'room_area' => 'finish.floor',
            'floor_finish_area' => 'finish.floor',
            'rough_floor_area' => 'rough.floor',
            'ceiling_finish_area' => 'office.ceiling',
            'wall_finish_area' => 'rough.walls',
            'paint_area' => 'finish.paint',
            'wet_zone_tile_area' => 'sanitary.tile',
            'skirting_length' => 'finish.baseboard',
            'opening_count' => 'openings.doors',
            'door_count' => 'openings.doors',
            'window_count' => 'openings.windows',
            'engineering_route_length' => 'plumbing.pipe',
            default => '',
        };
    }

    private function containsExternalNetworkSignal(string $text): bool
    {
        return preg_match('/(?:external\s+networks?|utilities|utility|наружн\p{L}*\s+(?:сет|инженер)|внешн\p{L}*\s+сет|подключен\p{L}*\s+к\s+сет)/u', $text) === 1;
    }

    private function containsSiteworksSignal(string $text): bool
    {
        return preg_match('/(?:landscap\p{L}*|благоустрой\p{L}*|озелен\p{L}*|отмостк\p{L}*|тротуар\p{L}*|наружн\p{L}*\s+площадк\p{L}*)/u', $text) === 1;
    }

    private function containsRoadsSignal(string $text): bool
    {
        return preg_match('/(?:roads?|driveway|parking|дорог\p{L}*|проезд\p{L}*|подъезд\p{L}*|парковк\p{L}*|площадк\p{L}*\s+для\s+(?:транспорт|авто))/u', $text) === 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fullHouseRequiredDocuments(): array
    {
        return [
            [
                'key' => 'architectural_plans',
                'title' => $this->requirementTitle('estimate_generation.document_requirement_architectural_plans'),
                'required_for' => ['walls', 'openings', 'finishing'],
            ],
            [
                'key' => 'structural_foundation',
                'title' => $this->requirementTitle('estimate_generation.document_requirement_structural_foundation'),
                'required_for' => ['earthworks', 'foundation', 'slabs'],
            ],
            [
                'key' => 'elevations_sections_facade',
                'title' => $this->requirementTitle('estimate_generation.document_requirement_elevations_sections_facade'),
                'required_for' => ['facade', 'roof', 'walls'],
            ],
            [
                'key' => 'roof_plan',
                'title' => $this->requirementTitle('estimate_generation.document_requirement_roof_plan'),
                'required_for' => ['roof'],
            ],
            [
                'key' => 'openings_schedule',
                'title' => $this->requirementTitle('estimate_generation.document_requirement_openings_schedule'),
                'required_for' => ['openings'],
            ],
            [
                'key' => 'engineering_plans',
                'title' => $this->requirementTitle('estimate_generation.document_requirement_engineering_plans'),
                'required_for' => ['electrical', 'plumbing', 'sewerage', 'heating', 'ventilation'],
            ],
            [
                'key' => 'work_volume_statement',
                'title' => $this->requirementTitle('estimate_generation.document_requirement_work_volume_statement'),
                'required_for' => ['all'],
            ],
        ];
    }

    private function requirementTitle(string $translationKey): string
    {
        try {
            return trans_message($translationKey);
        } catch (\Throwable) {
            return $translationKey;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function package(string $key, string $title, string $scopeType, int $targetMin, int $targetMax): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'scope_type' => $scopeType,
            'target_items_min' => $targetMin,
            'target_items_max' => $targetMax,
        ];
    }

    /**
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    private function packagePayload(array $package, int $index): array
    {
        return [
            ...$package,
            'sort_order' => ($index + 1) * 100,
            'status' => 'planned',
            'generation_stage' => null,
            'generation_progress' => 0,
            'actual_items_count' => 0,
            'totals' => [
                'total_cost' => 0,
                'items_count' => 0,
                'priced_items_count' => 0,
                'operation_items_count' => 0,
            ],
            'quality_summary' => [
                'level' => 'planned',
                'critical_flags' => [],
                'warning_flags' => [],
            ],
            'assumptions' => [],
            'source_refs' => [],
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $regionalContext
     */
    private function quarterKey(array $regionalContext): ?string
    {
        if (($regionalContext['version_key'] ?? null) !== null) {
            return (string) $regionalContext['version_key'];
        }

        if (($regionalContext['year'] ?? null) !== null && ($regionalContext['quarter'] ?? null) !== null) {
            return (int) $regionalContext['year'] . '-q' . (int) $regionalContext['quarter'];
        }

        return null;
    }
}
