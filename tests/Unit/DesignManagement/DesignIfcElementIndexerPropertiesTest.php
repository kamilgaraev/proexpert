<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Services\DesignIfcElementIndexer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DesignIfcElementIndexerPropertiesTest extends TestCase
{
    public function test_preserves_ifc_attributes_and_explicit_quantity_units_in_persisted_properties(): void
    {
        $element = [
            'properties' => ['_ifc' => ['schema_version' => 2, 'object_type' => 'Acoustic', 'types' => [['name' => 'Panel']], 'storey' => ['name' => 'Level 2']]],
            'quantities' => ['BaseQuantities' => ['Volume' => 2.5]],
            'quantity_units' => ['BaseQuantities.Volume' => ['name' => 'CUBIC_METRE', 'source' => 'quantity_unit']],
            'materials' => ['Concrete'],
        ];

        $properties = $this->properties($element);

        self::assertSame($element['properties']['_ifc'], $properties['_ifc']);
        self::assertSame($element['quantity_units'], $properties['quantity_units']);
        self::assertSame($element['quantities'], $properties['quantities']);
        self::assertSame($element['materials'], $properties['materials']);
    }

    public function test_legacy_and_invalid_optional_fields_do_not_invent_ifc_attributes_or_units(): void
    {
        $properties = $this->properties(['properties' => ['Pset_WallCommon' => ['IsExternal' => true]], 'quantity_units' => 'invalid']);

        self::assertSame(['IsExternal' => true], $properties['Pset_WallCommon']);
        self::assertArrayNotHasKey('_ifc', $properties);
        self::assertSame([], $properties['quantity_units']);
        self::assertSame([], $properties['quantities']);
        self::assertSame([], $properties['materials']);
    }

    private function properties(array $element): array
    {
        return (new ReflectionMethod(DesignIfcElementIndexer::class, 'elementProperties'))->invoke(new DesignIfcElementIndexer(), $element);
    }
}
