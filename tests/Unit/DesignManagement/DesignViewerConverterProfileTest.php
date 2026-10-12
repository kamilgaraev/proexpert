<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use Tests\TestCase;

final class DesignViewerConverterProfileTest extends TestCase
{
    public function test_viewer_converter_version_targets_property_preserving_derivatives(): void
    {
        $this->assertSame(6, DesignViewerConverter::version());
    }

    public function test_public_metadata_drops_coordinate_matrices_and_keeps_viewer_summary(): void
    {
        $metadata = DesignViewerConverter::publicMetadata([
            'converter_version' => 5,
            'indexed_element_count' => 4,
            'coordinate_transformations' => [[1, 0, 0, 0]],
            'geometry' => ['local_id_count' => 4],
            'ifc_metadata' => [
                'indexed_element_count' => 4,
                'transformations' => [[0, 1, 0, 0]],
            ],
        ]);

        $this->assertSame(5, $metadata['converter_version']);
        $this->assertSame(4, $metadata['geometry']['local_id_count']);
        $this->assertSame(4, $metadata['ifc_metadata']['indexed_element_count']);
        $this->assertArrayNotHasKey('coordinate_transformations', $metadata);
        $this->assertArrayNotHasKey('transformations', $metadata['ifc_metadata']);
    }

    public function test_node_converter_preserves_properties_and_project_coordinates(): void
    {
        $script = file_get_contents(base_path('resources/js/design-management/convert-ifc-to-frag.mjs'));
        $index = file_get_contents(base_path('resources/js/design-management/ifc-index-data.mjs'));
        $loader = file_get_contents(base_path('resources/js/design-management/ifc-reader.mjs'));
        $relationships = file_get_contents(base_path('resources/js/design-management/ifc-relationships.mjs'));
        $properties = file_get_contents(base_path('resources/js/design-management/ifc-element-properties.mjs'));

        $this->assertIsString($script);
        $this->assertIsString($index);
        $this->assertIsString($loader);
        $this->assertIsString($relationships);
        $this->assertIsString($properties);
        $this->assertStringContainsString('VIEWER_GEOMETRY_PROFILE', $script);
        $this->assertStringContainsString('importer.includeUniqueAttributes = true', $script);
        $this->assertStringContainsString('importer.includeRelationNames = true', $script);
        $this->assertStringContainsString('export const IFC_LOADER_SETTINGS = Object.freeze({', $loader);
        $this->assertStringContainsString('COORDINATE_TO_ORIGIN: false', $loader);
        $this->assertStringContainsString('import { openIfcSource, IFC_LOADER_SETTINGS } from "./ifc-reader.mjs";', $script);
        $this->assertStringContainsString('importer.webIfcSettings = IFC_LOADER_SETTINGS', $script);
        $this->assertStringContainsString('OpenModelFromCallback', $script);
        $this->assertStringContainsString('api.OpenModelFromCallback(source.read, IFC_LOADER_SETTINGS)', $script);
        $this->assertStringContainsString('getElementPropertySets', $script);
        $this->assertStringContainsString('createIfcIndexReader', $script);
        $this->assertStringContainsString('materialNames', $index);
        $this->assertStringContainsString('indexReader.materialNames', $script);
        $this->assertStringContainsString('export const createIfcIndexReader', $index);
        $this->assertStringContainsString('import { ifcValue as value, namedProperties, createIfcIndexReader } from "./ifc-index-data.mjs";', $script);
        $this->assertStringContainsString('const indexReader = createIfcIndexReader(api, modelID, propertyLookup)', $script);
        $this->assertStringContainsString('export const getElementPropertySets', $properties);
        $this->assertStringContainsString('export const createPropertySetReader', $properties);
        $this->assertStringContainsString('import { getElementPropertySets, createPropertySetReader } from "./ifc-element-properties.mjs";', $script);
        $this->assertStringContainsString('getElementPropertySets(api, modelID, expressID, readPropertySet, propertyLookup, types)', $script);
        $this->assertStringContainsString('export const createIfcPropertyLookup', $relationships);
        $this->assertStringContainsString('"IFCRELASSOCIATESMATERIAL", "RelatingMaterial"', $relationships);
        $this->assertStringContainsString('materialIds:', $relationships);
        $this->assertStringContainsString('import { createIfcPropertyLookup } from "./ifc-relationships.mjs";', $script);
        $this->assertStringContainsString('const propertyLookup = createIfcPropertyLookup(api, modelID)', $script);
        $this->assertStringContainsString('materialNames: (references)', $index);
        $this->assertStringContainsString('materials: indexReader.materialNames(propertyLookup.materialIds(expressID))', $script);
        $this->assertStringContainsString('classificationMap', $script);
        $this->assertStringContainsString('const classifications = classificationMap(api, modelID)', $script);
        $this->assertStringContainsString('classifications: classifications.get(expressID) ?? []', $script);
        $this->assertStringContainsString('coordination_matrix', $script);
        $this->assertStringContainsString('coordination_matrix: api.GetCoordinationMatrix(modelID)', $script);
    }
}
