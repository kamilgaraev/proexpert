<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Planning\DocumentNormativeReferenceAllocator;
use App\BusinessModules\Addons\EstimateGeneration\Planning\EvaluationSectionMap;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DocumentNormativeReferenceAllocatorTest extends TestCase
{
    public function test_routes_specific_work_before_compatible_packages_and_keeps_unknown_work_unassigned(): void
    {
        $allocation = (new DocumentNormativeReferenceAllocator)->allocate($this->analysis([
            'Бетонирование фундаментов', 'Кладка стен', 'Прокладка электрического кабеля',
            'Монтаж светильников', 'Устройство стяжки пола', 'Штукатурка стен', 'Чистовое покрытие пола',
            'Неопределённая специальная работа', 'Утепление стен', 'Утепление', 'Монтаж подвесного потолка',
        ]), $this->packages());
        $packages = array_keys($allocation['by_package']);
        self::assertSame(['foundation', 'walls', 'electrical', 'lighting', 'rough_finishing', 'finish_finishing'], $packages);
        self::assertCount(2, $allocation['by_package']['rough_finishing']);
        self::assertCount(2, $allocation['by_package']['walls']);
        self::assertCount(2, $allocation['by_package']['finish_finishing']);
        self::assertCount(2, $allocation['unresolved']);
        self::assertSame('Неопределённая специальная работа', $allocation['unresolved'][0]['name']);
        self::assertSame(11, array_sum(array_map('count', $allocation['by_package'])) + count($allocation['unresolved']));
    }

    public function test_global_ceiling_counts_different_categories_before_any_package_is_built(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_work_items_exceed_approved_plan');
        (new DocumentNormativeReferenceAllocator)->allocate($this->analysis(['Кладка стен', 'Кладка стен', 'Монтаж светильников']), $this->packages(), 2);
    }

    public function test_routes_explicit_window_quantity_to_openings(): void
    {
        self::assertSame('structures', EvaluationSectionMap::forPackage('openings'));
        $analysis = $this->analysis([]);
        $analysis['source_documents'][0]['text'] = 'ФЕР 08-02-001-01 Монтаж окон 10 шт';
        $allocation = (new DocumentNormativeReferenceAllocator)->allocate($analysis, [['key' => 'openings', 'scope_type' => 'openings']]);
        self::assertSame([], $allocation['unresolved']);
        self::assertCount(1, $allocation['by_package']['openings']);
        self::assertSame(10.0, $allocation['by_package']['openings'][0]['quantity']);
        self::assertSame('шт', $allocation['by_package']['openings'][0]['unit']);
    }

    public function test_does_not_relabel_work_when_its_package_is_outside_the_chosen_scope(): void
    {
        $allocation = (new DocumentNormativeReferenceAllocator)->allocate($this->analysis(['Чистовое покрытие пола']), [['key' => 'rough_finishing', 'scope_type' => 'finishing']]);
        self::assertSame([], $allocation['by_package']);
        self::assertCount(1, $allocation['unresolved']);
    }

    public function test_routes_electrical_rows_to_real_warehouse_industrial_and_mixed_profiles(): void
    {
        $planner = new PackagePlannerService;
        foreach (['warehouse', 'industrial', 'mixed_warehouse_office'] as $type) {
            $profile = $planner->profileFromAnalysis(['object' => ['object_type' => $type]]);
            $packages = $planner->plan($profile)->packages;
            $allocation = (new DocumentNormativeReferenceAllocator)->allocate($this->analysis([
                'Прокладка электрического кабеля', 'Монтаж светильников', 'Неопределённая специальная работа',
            ]), $packages);
            self::assertSame(['power_supply', 'lighting'], array_keys($allocation['by_package']), $type);
            self::assertCount(1, $allocation['by_package']['power_supply']);
            self::assertCount(1, $allocation['by_package']['lighting']);
            self::assertCount(1, $allocation['unresolved']);
        }
    }

    public function test_routes_document_evidence_rows_to_real_custom_packages(): void
    {
        $planner = new PackagePlannerService;
        $quantityKeys = ['earth.trench', 'foundation.concrete', 'walls.external_volume', 'roof.area',
            'openings.windows', 'rough.floor', 'finish.floor', 'electrical.power_lines', 'warehouse.lighting'];
        $profile = $planner->profileFromAnalysis(['object' => ['object_type' => 'custom'],
            'document_context' => ['scope_inferences' => array_map(static fn (string $key): array => [
                'normalized_payload' => ['quantity_key' => $key],
            ], $quantityKeys)]]);
        self::assertSame('document_evidence', $profile->objectType);
        $allocation = (new DocumentNormativeReferenceAllocator)->allocate($this->analysis([
            'Разработка грунта', 'Бетонирование фундаментов', 'Кладка наружных стен', 'Устройство кровли',
            'Монтаж окон', 'Устройство стяжки пола', 'Чистовое покрытие пола', 'Монтаж подвесного потолка',
            'Прокладка электрического кабеля', 'Монтаж светильников', 'Неопределённая специальная работа',
        ]), $planner->plan($profile)->packages);
        self::assertSame(['custom-earthworks', 'custom-foundation', 'custom-walls', 'custom-roof',
            'custom-openings', 'custom-finishing', 'custom-electrical'], array_keys($allocation['by_package']));
        self::assertCount(3, $allocation['by_package']['custom-finishing']);
        self::assertCount(2, $allocation['by_package']['custom-electrical']);
        self::assertCount(1, $allocation['unresolved']);
        self::assertSame(11, array_sum(array_map('count', $allocation['by_package'])) + count($allocation['unresolved']));
    }

    public function test_does_not_route_cable_or_windows_to_other_specialized_packages(): void
    {
        $allocation = (new DocumentNormativeReferenceAllocator)->allocate($this->analysis([
            'Прокладка электрического кабеля', 'Монтаж окон', 'Чистовое покрытие пола',
        ]), [['key' => 'automation', 'scope_type' => 'electrical'], ['key' => 'low_current', 'scope_type' => 'electrical'],
            ['key' => 'gates', 'scope_type' => 'openings'], ['key' => 'rough_finishing', 'scope_type' => 'finishing']]);
        self::assertSame([], $allocation['by_package']);
        self::assertCount(3, $allocation['unresolved']);
    }

    public function test_mixed_profile_routes_windows_and_doors_to_the_entrance_and_gates_to_their_own_package(): void
    {
        $planner = new PackagePlannerService;
        $packages = $planner->plan($planner->profileFromAnalysis(['object' => ['object_type' => 'mixed_warehouse_office']]))->packages;
        $allocation = (new DocumentNormativeReferenceAllocator)->allocate($this->analysis([
            'Монтаж окон', 'Монтаж дверей', 'Монтаж ворот',
        ]), $packages);
        self::assertCount(2, $allocation['by_package']['entrance_group'] ?? []);
        self::assertCount(1, $allocation['by_package']['gates'] ?? []);
        self::assertSame([], $allocation['unresolved']);
    }

    public function test_document_packages_use_the_existing_evaluation_section_taxonomy(): void
    {
        foreach (['custom-electrical' => 'electricity', 'custom-walls' => 'structures', 'custom-openings' => 'structures',
            'custom-foundation' => 'structures', 'custom-earthworks' => 'earthworks', 'custom-roof' => 'roofing',
            'custom-finishing' => 'finishing', 'custom-plumbing' => 'water_supply', 'custom-sewerage' => 'sewerage',
            'custom-heating' => 'heating', 'custom-ventilation' => 'ventilation'] as $package => $section) {
            self::assertSame($section, EvaluationSectionMap::forPackage($package));
        }
    }

    public function test_engineering_quantity_keys_select_their_real_profile_packages(): void
    {
        $planner = new PackagePlannerService;
        foreach (['house', 'warehouse', 'industrial', 'custom'] as $type) {
            $analysis = ['object' => ['object_type' => $type], 'document_context' => ['scope_inferences' => array_map(
                static fn (string $key): array => ['normalized_payload' => ['quantity_key' => $key]],
                ['sewerage.pipe', 'plumbing.pipe', 'heating.pipe', 'ventilation.air_exchange'],
            )]];
            $plan = $planner->plan($planner->profileFromAnalysis($analysis));
            $allocation = (new DocumentNormativeReferenceAllocator)->allocate($this->analysis([
                'Прокладка труб канализации', 'Прокладка труб водоснабжения',
                'Прокладка труб отопления', 'Монтаж вентиляционных воздуховодов',
            ]), $plan->packages);
            $expected = match ($type) {
                'house' => ['sewerage', 'plumbing', 'heating', 'ventilation'],
                'custom' => ['custom-sewerage', 'custom-plumbing', 'custom-heating', 'custom-ventilation'],
                default => ['water_sewerage', 'heating', 'ventilation'],
            };
            self::assertSame($expected, array_keys($allocation['by_package']), $type);
            self::assertSame([], $allocation['unresolved'], $type);
            self::assertSame(4, array_sum(array_map('count', $allocation['by_package'])), $type);
        }
    }

    public function test_selected_water_supply_does_not_absorb_known_sewerage_or_heating(): void
    {
        $allocation = (new DocumentNormativeReferenceAllocator)->allocate($this->analysis([
            'Прокладка труб канализации', 'Прокладка труб отопления',
        ]), [['key' => 'plumbing', 'scope_type' => 'plumbing']]);
        self::assertSame([], $allocation['by_package']);
        self::assertCount(2, $allocation['unresolved']);
    }

    public function test_unknown_work_is_not_classified_by_unrelated_word_fragments(): void
    {
        $planner = new PackagePlannerService;
        $packages = $planner->plan($planner->profileFromAnalysis(['object' => ['object_type' => 'house']]))->packages;
        $allocation = (new DocumentNormativeReferenceAllocator)->allocate($this->analysis([
            'Прокладка неизвестной специальной сети', 'Укладка неизвестного материала', 'Монтаж технологического трубопровода',
            'Защита существующих конструкций', 'Монтаж испытательного стенда', 'Инвентаризация существующих сетей', 'Устройство кладовой',
        ]), $packages);
        self::assertSame([], $allocation['by_package']);
        self::assertCount(7, $allocation['unresolved']);
    }

    public function test_routes_facade_to_the_real_warehouse_and_industrial_envelope(): void
    {
        $planner = new PackagePlannerService;
        foreach (['warehouse', 'industrial'] as $type) {
            $packages = $planner->plan($planner->profileFromAnalysis(['object' => ['object_type' => $type]]))->packages;
            $allocation = (new DocumentNormativeReferenceAllocator)->allocate($this->analysis(['Монтаж фасадных панелей']), $packages);
            self::assertSame(['envelope'], array_keys($allocation['by_package']));
            self::assertSame([], $allocation['unresolved']);
        }
    }

    public function test_word_boundaries_preserve_real_shield_wall_and_ventilation_work(): void
    {
        $planner = new PackagePlannerService;
        $packages = $planner->plan($planner->profileFromAnalysis(['object' => ['object_type' => 'house']]))->packages;
        $allocation = (new DocumentNormativeReferenceAllocator)->allocate($this->analysis([
            'Монтаж распределительного щита', 'Устройство стеновых панелей', 'Монтаж вентиляционной установки',
        ]), $packages);
        self::assertSame(['electrical', 'walls', 'ventilation'], array_keys($allocation['by_package']));
        self::assertSame([], $allocation['unresolved']);
    }

    private function analysis(array $names): array
    {
        return ['source_documents' => [['id' => 82, 'source_version' => 'v2', 'status' => 'ready', 'quality' => ['level' => 'good'],
            'document_understanding' => ['role_for_estimation' => 'quantity_source'],
            'text' => implode("\n", array_map(static fn (string $name): string => 'ФЕР 08-02-001-01 '.$name.' 10 м2', $names))]]];
    }

    private function packages(): array
    {
        return array_map(static fn (array $row): array => ['key' => $row[0], 'scope_type' => $row[1]], [
            ['preconstruction', 'site'], ['earthworks', 'foundation'], ['foundation', 'foundation'],
            ['walls', 'walls'], ['electrical', 'electrical'], ['lighting', 'electrical'],
            ['rough_finishing', 'finishing'], ['finish_finishing', 'finishing'],
        ]);
    }
}
