<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantBimIndexProjection;
use PHPUnit\Framework\TestCase;

final class AssistantBimIndexProjectionTest extends TestCase
{
    public function test_engineering_projection_preserves_nested_evidence_without_private_json(): void
    {
        $projection = new AssistantBimIndexProjection;
        $row = $projection->element(['name' => 'Блок', 'category' => 'IFCUNITARYEQUIPMENT',
            'properties' => ['private' => 'private-secret', 'Pset_UnitaryEquipmentTypeCommon' => ['PredefinedType' => 'AIRCONDITIONINGUNIT',
                'CoolingCapacity' => 3500, 'ContactEmail' => 'private@example.org'],
                'quantities' => ['Qto_UnitaryEquipmentBaseQuantities' => ['GrossWeight' => 15]], 'materials' => ['Сталь']],
            'classifications' => ['HVAC']]);
        self::assertSame('air_conditioner', $row['equipment_kind']);
        self::assertSame(3500, $row['properties']['Pset_UnitaryEquipmentTypeCommon.CoolingCapacity']);
        self::assertSame(['Сталь'], $row['materials']);
        self::assertStringNotContainsString('private', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function test_unitary_equipment_is_not_implicitly_an_air_conditioner(): void
    {
        $projection = new AssistantBimIndexProjection;
        self::assertSame('unknown', $projection->element(['category' => 'IFCUNITARYEQUIPMENT', 'name' => 'Блок'])['equipment_kind']);
        self::assertSame('fan', $projection->element(['category' => 'IFCFAN', 'name' => 'Вентилятор'])['equipment_kind']);
        self::assertSame('air_conditioner', $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY',
            'properties' => ['Pset_ManufacturerTypeInformation' => ['ModelReference' => 'Сплит-система']]])['equipment_kind']);
    }

    public function test_property_material_classification_and_storey_filters_have_independent_semantics(): void
    {
        $projection = new AssistantBimIndexProjection;
        $row = $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'name' => 'Блок',
            'properties' => ['Pset_Performance' => ['CoolingCapacity' => 3500], 'materials' => ['Сталь'],
                '_ifc' => ['storey' => ['name' => 'Этаж 2']]], 'classifications' => ['HVAC']]);
        self::assertTrue($projection->matches($row, ['property_name' => 'CoolingCapacity', 'property_value' => '3500']));
        self::assertTrue($projection->matches($row, ['material_query' => 'сталь', 'classification_query' => 'HVAC']));
        self::assertFalse($projection->matches($row, ['material_query' => 'бетон']));
        self::assertTrue($projection->matches($row, ['storey_query' => 'Этаж 2']));
    }

    public function test_quantity_units_do_not_mix_or_invent_missing_units(): void
    {
        $projection = new AssistantBimIndexProjection;
        $row = $projection->element(['properties' => ['quantities' => ['Qto_SlabBaseQuantities' => ['GrossVolume' => 2.5]]]]);
        $quantity = $projection->quantities($row, [['type' => 'IFCSIUNIT', 'unit_type' => 'VOLUMEUNIT', 'name' => 'CUBIC_METRE', 'prefix' => null]]);
        self::assertSame('CUBIC_METRE', $quantity[0]['unit']);
        self::assertSame(2.5, $quantity[0]['value']);
        self::assertNull($projection->quantities($row, [])[0]['unit']);
        self::assertNull($projection->quantities($row, [['unit_type' => 'VOLUMEUNIT', 'name' => 'CUBIC_METRE'],
            ['unit_type' => 'VOLUMEUNIT', 'name' => 'CUBIC_FOOT']])[0]['unit']);
    }

    public function test_diff_uses_global_identity_instead_of_version_local_express_ids(): void
    {
        $projection = new AssistantBimIndexProjection;
        $old = [['global_id' => 'stable', 'express_id' => 1, 'name' => 'Блок', 'properties' => ['CoolingCapacity' => 10]]];
        $new = [['global_id' => 'stable', 'express_id' => 99, 'name' => 'Блок', 'properties' => ['CoolingCapacity' => 20]],
            ['global_id' => 'added', 'express_id' => 2, 'name' => 'Новый']];
        $diff = $projection->diff($old, $new, 20);
        self::assertSame(['added' => 1, 'removed' => 0, 'changed' => 1, 'unchanged' => 0], $diff['counts']);
        self::assertSame('stable', $diff['changes'][0]['global_id']);
        self::assertFalse($projection->diff([['express_id' => 1]], [['express_id' => 1]], 20)['identity_complete']);
    }

    public function test_explicit_quantity_unit_overrides_project_unit_without_conversion(): void
    {
        $projection = new AssistantBimIndexProjection;
        $row = $projection->element(['properties' => ['quantities' => ['Qto_Slab' => ['Width' => 2000]],
            'quantity_units' => ['Qto_Slab.Width' => ['type' => 'IFCSIUNIT', 'unit_type' => 'LENGTHUNIT', 'name' => 'METRE', 'prefix' => 'MILLI']]]]);
        $quantity = $projection->quantities($row, [['type' => 'IFCSIUNIT', 'unit_type' => 'LENGTHUNIT', 'name' => 'METRE']])[0];
        self::assertSame('MILLI METRE', $quantity['unit']);
        self::assertSame(2000.0, $quantity['value']);
        $row['quantity_units']['Qto_Slab.Width'] = ['type' => 'IFCCONVERSIONBASEDUNIT', 'unit_type' => 'LENGTHUNIT', 'name' => 'FOOT'];
        self::assertNull($projection->quantities($row, [['type' => 'IFCSIUNIT', 'unit_type' => 'LENGTHUNIT', 'name' => 'METRE']])[0]['unit']);
    }

    public function test_own_and_type_ifc_attributes_preserve_explicit_equipment_evidence_and_storey(): void
    {
        $row = (new AssistantBimIndexProjection)->element(['category' => 'IFCUNITARYEQUIPMENT', 'properties' => ['_ifc' => [
            'type_predefined_type' => 'AIRCONDITIONINGUNIT', 'type_object_type' => 'Внутренний блок',
            'storey' => ['name' => 'Второй этаж', 'global_id' => 'storey-id'], 'owner_email' => 'private@example.org']]]);
        self::assertSame('air_conditioner', $row['equipment_kind']);
        self::assertSame('Второй этаж', $row['attributes']['storey']);
        self::assertArrayNotHasKey('owner_email', $row['attributes']);
    }

    public function test_legacy_or_string_schema_marker_does_not_assert_semantic_completeness(): void
    {
        $projection = new AssistantBimIndexProjection;
        self::assertSame('unproven', $projection->element(['properties' => ['Pset_Equipment' => ['PredefinedType' => 'AIRCONDITIONINGUNIT']]])['semantic_coverage']);
        self::assertSame('unproven', $projection->element(['properties' => ['_ifc' => ['schema_version' => '2']]])['semantic_coverage']);
        self::assertSame('schema2', $projection->element(['properties' => ['_ifc' => ['schema_version' => 2]]])['semantic_coverage']);
    }

    public function test_equipment_keywords_on_construction_ducts_types_and_referenced_proxy_names_are_not_equipment(): void
    {
        $projection = new AssistantBimIndexProjection;
        foreach ([['IFCWALL', 'Стена для кондиционера'], ['IFCSLAB', 'Плита под насос'],
            ['IFCDUCTSEGMENT', 'Воздуховод к вентилятору'], ['IFCFLOWFITTING', 'Отвод к кондиционеру'],
            ['IFCAIRTERMINAL', 'Кондиционер'], ['IFCUNITARYEQUIPMENTTYPE', 'Кондиционер'],
            ['IFCSYSTEM', 'Вентилятор'], ['IFCBUILDINGELEMENTPROXY', 'Подставка под кондиционер']] as [$category, $name]) {
            self::assertSame('unknown', $projection->element(['category' => $category, 'name' => $name])['equipment_kind'], $category.': '.$name);
        }
        self::assertSame('unknown', $projection->element(['category' => 'IFCWALL', 'properties' => ['_ifc' => ['predefined_type' => 'AIRCONDITIONINGUNIT']]])['equipment_kind']);
        self::assertSame('air_conditioner', $projection->element(['category' => 'IFCFLOWTERMINAL', 'name' => 'Кондиционер'])['equipment_kind']);
        self::assertSame('fan', $projection->element(['category' => 'IFCFLOWMOVINGDEVICE', 'name' => 'Вентилятор для кондиционера'])['equipment_kind']);
        self::assertSame('air_conditioner', $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'name' => 'Блок для кондиционера',
            'properties' => ['_ifc' => ['type_predefined_type' => 'AIRCONDITIONINGUNIT']]])['equipment_kind']);
        foreach (['Кронштейн кондиционера', 'Трубопровод кондиционера', 'Подставка кондиционера', 'Крепление кондиционера',
            'Отверстие кондиционера', 'Кронштейн наружного кондиционера'] as $name) {
            self::assertSame('unknown', $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'name' => $name,
                'properties' => ['_ifc' => ['schema_version' => 2]]])['equipment_kind'], $name);
        }
        self::assertSame('air_conditioner', $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'name' => 'Кондиционер KX02'])['equipment_kind']);
        self::assertSame('air_conditioner', $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'name' => 'Standalone air conditioner'])['equipment_kind']);
        self::assertSame('air_conditioner', $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'name' => 'Кронштейн кондиционера',
            'properties' => ['_ifc' => ['type_predefined_type' => 'AIRCONDITIONINGUNIT']]])['equipment_kind']);
    }

    public function test_real_engineering_label_aliases_identify_vrf_and_split_units_without_brand_or_material_inference(): void
    {
        $projection = new AssistantBimIndexProjection;
        foreach (['GP_Наименование', 'ADSK_Наименование', 'Наименование'] as $alias) {
            $row = $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'name' => '(ME)_HVAC_Family:TYPE-01:element',
                'properties' => ['_ifc' => ['schema_version' => 2, 'type_predefined_type' => 'NOTDEFINED'],
                    'Идентификация' => [$alias => 'Наружный блок мультизональной системы Qх=68.0Вт, Qт=75.0кВт,380-415/3/50'],
                    'Прочее' => ['Категория' => 'Оборудование'], 'materials' => ['Корпус кондиционера', 'Решетка кондиционера']]]);
            self::assertSame('air_conditioner', $row['equipment_kind'], $alias);
            self::assertArrayHasKey('Идентификация.'.$alias, $row['properties']);
        }
        $indoor = $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'name' => '(ME)_HVAC_Family:TYPE-02:element',
            'properties' => ['Идентификация' => ['GP_Наименование' => "Сплит-система Qх=5100Вт, Qт=5200Вт, U=220/1/50, N=1,6кВт в\r\nкомплекте пульт дистанционного управления"],
                'materials' => ['Белый пластик']]]);
        self::assertSame('air_conditioner', $indoor['equipment_kind']);
        foreach (['Внутренний блок VRF', 'Наружный блок VRV', 'Наружный блок мульти-сплит системы'] as $label) {
            self::assertSame('air_conditioner', $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY',
                'properties' => ['Идентификация' => ['Наименование' => $label]]])['equipment_kind']);
        }
    }

    public function test_hvac_descriptions_never_classify_room_or_accessories_and_materials_or_cooling_alone_are_insufficient(): void
    {
        $projection = new AssistantBimIndexProjection;
        foreach (['Кронштейн наружного блока мультизональной системы', 'Решетка внутреннего блока VRF', 'Трубопровод наружного блока VRV'] as $label) {
            self::assertSame('unknown', $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY',
                'properties' => ['Идентификация' => ['GP_Наименование' => $label]]])['equipment_kind'], $label);
        }
        foreach (['IFCSPACE', 'IFCWALL', 'IFCDUCTSEGMENT'] as $category) {
            self::assertSame('unknown', $projection->element(['category' => $category,
                'properties' => ['Идентификация' => ['GP_Наименование' => 'Наружный блок мультизональной системы']]])['equipment_kind']);
        }
        self::assertSame('unknown', $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY',
            'properties' => ['Прочее' => ['Категория' => 'Помещения'],
                'Идентификация' => ['GP_Наименование' => 'Помещение с кондиционером', 'CoolingCapacity' => 3500]]])['equipment_kind']);
        self::assertSame('unknown', $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'name' => 'Объект',
            'properties' => ['Идентификация' => ['CoolingCapacity' => 3500, 'HeatingCapacity' => 4000],
                'materials' => ['Корпус кондиционера', 'Решетка кондиционера']]])['equipment_kind']);
    }

    public function test_raw_room_category_remains_authoritative_after_the_hundred_display_properties(): void
    {
        $properties = [];
        for ($index = 0; $index < 110; $index++) {
            $properties['Pset_'.$index] = ['Width' => $index];
        }
        $properties['Прочее'] = ['Категория' => 'Помещения'];
        $row = (new AssistantBimIndexProjection)->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'name' => 'Кондиционер', 'properties' => $properties]);
        self::assertCount(100, $row['properties']);
        self::assertArrayNotHasKey('Прочее.Категория', $row['properties']);
        self::assertSame('unknown', $row['equipment_kind']);
    }

    public function test_raw_closed_identity_after_display_limit_still_classifies_without_exposing_extra_or_private_fields(): void
    {
        $properties = [];
        for ($index = 0; $index < 110; $index++) {
            $properties['Pset_'.$index] = ['Width' => $index];
        }
        $properties['Идентификация'] = ['GP_Наименование' => 'Наружный блок мультизональной системы', 'ContactEmail' => 'private@example.org'];
        $projection = new AssistantBimIndexProjection;
        $row = $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'name' => 'TYPE-01', 'properties' => $properties]);
        self::assertCount(100, $row['properties']);
        self::assertArrayNotHasKey('Идентификация.GP_Наименование', $row['properties']);
        self::assertSame('air_conditioner', $row['equipment_kind']);
        self::assertStringNotContainsString('private@example.org', json_encode($row, JSON_THROW_ON_ERROR));
        $properties['Идентификация']['GP_Наименование'] = 'Кронштейн наружного блока мультизональной системы';
        self::assertSame('unknown', $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'properties' => $properties])['equipment_kind']);
        $properties['Идентификация']['GP_Наименование'] = 'Кондиционер https://private.test';
        self::assertSame('unknown', $projection->element(['category' => 'IFCBUILDINGELEMENTPROXY', 'properties' => $properties])['equipment_kind']);
    }
}
