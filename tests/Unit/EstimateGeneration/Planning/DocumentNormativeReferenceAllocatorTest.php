<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Planning\DocumentNormativeReferenceAllocator;
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

    public function test_does_not_relabel_work_when_its_package_is_outside_the_chosen_scope(): void
    {
        $allocation = (new DocumentNormativeReferenceAllocator)->allocate($this->analysis(['Чистовое покрытие пола']), [['key' => 'rough_finishing', 'scope_type' => 'finishing']]);
        self::assertSame([], $allocation['by_package']);
        self::assertCount(1, $allocation['unresolved']);
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
