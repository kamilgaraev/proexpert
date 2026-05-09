<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\ObjectProfileData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\PackagePlanData;

class PackagePlannerService
{
    public function plan(ObjectProfileData $profile): PackagePlanData
    {
        $packages = $this->isWarehouse($profile)
            ? $this->warehousePackages()
            : $this->housePackages();

        return new PackagePlanData(
            packages: array_map(fn (array $package, int $index): array => $this->packagePayload($package, $index), $packages, array_keys($packages)),
            assumptions: $profile->assumptions,
        );
    }

    /**
     * @param array<string, mixed> $analysis
     */
    public function profileFromAnalysis(array $analysis): ObjectProfileData
    {
        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];
        $regionalContext = is_array($analysis['regional_context'] ?? null) ? $analysis['regional_context'] : [];
        $type = mb_strtolower((string) ($object['object_type'] ?? $object['building_type'] ?? 'custom'));

        if (str_contains($type, 'склад') || str_contains($type, 'warehouse')) {
            $type = 'warehouse';
        } elseif (str_contains($type, 'жил') || str_contains($type, 'ижс') || str_contains($type, 'house')) {
            $type = 'house';
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
        );
    }

    private function isWarehouse(ObjectProfileData $profile): bool
    {
        $type = mb_strtolower($profile->objectType);

        return str_contains($type, 'warehouse')
            || str_contains($type, 'склад')
            || str_contains($type, 'industrial');
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
            $this->package('stairs', 'Лестницы', 'slabs', 8, 18),
            $this->package('roof', 'Кровля', 'roof', 24, 45),
            $this->package('openings', 'Окна и двери', 'openings', 14, 28),
            $this->package('facade', 'Фасад', 'facade', 18, 36),
            $this->package('electrical', 'Электрика', 'engineering', 24, 50),
            $this->package('plumbing', 'Водоснабжение', 'engineering', 16, 34),
            $this->package('sewerage', 'Канализация', 'engineering', 12, 26),
            $this->package('heating', 'Отопление', 'engineering', 18, 40),
            $this->package('ventilation', 'Вентиляция', 'engineering', 8, 20),
            $this->package('rough_finishing', 'Черновая отделка', 'finishing', 24, 52),
            $this->package('finish_finishing', 'Чистовая отделка', 'finishing', 28, 60),
            $this->package('external_networks', 'Наружные сети', 'site', 14, 32),
            $this->package('siteworks', 'Благоустройство', 'site', 14, 32),
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
            $this->package('power_supply', 'Электроснабжение', 'engineering', 55, 120),
            $this->package('lighting', 'Освещение', 'engineering', 35, 80),
            $this->package('ventilation', 'Вентиляция', 'engineering', 45, 100),
            $this->package('heating', 'Отопление', 'engineering', 35, 80),
            $this->package('fire_safety', 'Пожарная безопасность', 'engineering', 50, 110),
            $this->package('water_sewerage', 'Водоснабжение и канализация', 'engineering', 35, 80),
            $this->package('low_current', 'Слаботочные системы', 'engineering', 25, 60),
            $this->package('external_networks', 'Наружные сети', 'site', 40, 90),
            $this->package('roads', 'Дороги и площадки', 'site', 35, 80),
        ];
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
