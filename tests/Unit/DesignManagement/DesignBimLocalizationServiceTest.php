<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Services\DesignBimLocalizationService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class DesignBimLocalizationServiceTest extends TestCase
{
    private function service(): DesignBimLocalizationService
    {
        return new DesignBimLocalizationService(new Translator(new FileLoader(new Filesystem(), dirname(__DIR__, 3).'/lang'), 'ru'));
    }

    public function test_translates_ifc_and_tekla_without_changing_source_values(): void
    {
        $payload = [
            'global_id' => '1gl6sY0004b34tEJ0tCpKp', 'category' => 'IFCBEAM', 'name' => 'Прогон',
            'properties' => ['materials' => [], 'quantities' => [], 'Tekla Common' => [
                'Class' => 6, 'Phase' => null, 'Initial GUID' => 'NEW',
                'Top elevation' => '+13.913', 'Bottom elevation' => '+13.775',
            ]],
        ];
        $source = $payload;
        $display = $this->service()->present($payload);
        $fields = array_column($display['fields'], 'value', 'label');
        self::assertSame('ru', $display['locale']);
        self::assertSame('Балка', $fields['Категория']);
        self::assertSame('6', $fields['Свойства · Общие свойства Tekla · Класс']);
        self::assertSame('+13.913', $fields['Свойства · Общие свойства Tekla · Верхняя отметка']);
        self::assertSame('+13.775', $fields['Свойства · Общие свойства Tekla · Нижняя отметка']);
        self::assertSame('NEW', $fields['Свойства · Общие свойства Tekla · Исходный GUID']);
        self::assertSame('Не указано в модели', $fields['Свойства · Материалы']);
        self::assertSame($source, $payload);
    }

    public function test_preserves_zero_false_names_grades_and_unknown_properties(): void
    {
        $display = $this->service()->present(['name' => 'Steel Grade 345', 'properties' => [
            'Pset_WallCommon' => ['IsExternal' => false, 'LoadBearing' => true, 'NetVolume' => 0, 'net_volume' => '0.000'],
            'materials' => [['name' => 'Steel', 'Reference' => 'NEW', 'SteelGrade' => 'Steel Grade 345']],
            'CustomGroup' => ['CustomValue' => 'Custom English', 'PredefinedType' => 'PURLIN'],
        ]]);
        $fields = array_column($display['fields'], 'value', 'label');
        self::assertSame('Steel Grade 345', $fields['Название']);
        self::assertSame('Нет', $fields['Свойства · Общие свойства стены · Наружный элемент']);
        self::assertSame('Да', $fields['Свойства · Общие свойства стены · Несущий элемент']);
        self::assertSame('Сталь', $fields['Свойства · Материалы · 0 · Название']);
        self::assertSame('NEW', $fields['Свойства · Материалы · 0 · Обозначение']);
        self::assertSame('Steel Grade 345', $fields['Свойства · Материалы · 0 · Марка стали']);
        self::assertSame('Custom English', $fields['Свойства · CustomGroup · CustomValue']);
        self::assertSame('Прогон', $fields['Свойства · CustomGroup · Предопределённый тип']);
        self::assertContains('0', array_column($display['fields'], 'value'));
        self::assertContains('0.000', array_column($display['fields'], 'value'));
        self::assertNotSame($display['fields'][3]['path'], $display['fields'][4]['path']);
    }

    public function test_dictionary_matches_case_and_vendor_separators_and_preserves_unknown_categories(): void
    {
        $service = $this->service();
        self::assertSame('Балка', $service->categoryLabel('IfcBeam'));
        self::assertSame('Несущий каркас', $service->categoryLabel('Structural Framing'));
        self::assertSame('IFC_CUSTOM', $service->categoryLabel('IFC_CUSTOM'));
        self::assertSame('IFCBEAM®', $service->categoryLabel('IFCBEAM®'));
        self::assertNull($service->categoryLabel(null));
        self::assertSame('Верхняя отметка', $service->dictionary()['labels']['topelevation']);
        self::assertSame('Основные количества и объёмы балки', $service->dictionary()['labels']['qtobeambasequantities']);
    }

    public function test_preserves_custom_material_names_punctuation_and_unrelated_nested_fields(): void
    {
        $display = $this->service()->present(['properties' => ['materials' => [
            ['name' => 'Steel®', 'CustomValue' => 'NEW'],
            ['name' => 'Steel-А', 'Description' => 'concrete'],
            ['name' => 'Сталь Steel', 'CustomId' => 'steel'],
        ]]]);
        self::assertSame(['Steel®', 'NEW', 'Steel-А', 'concrete', 'Сталь Steel', 'steel'], array_column($display['fields'], 'value'));
    }
}
