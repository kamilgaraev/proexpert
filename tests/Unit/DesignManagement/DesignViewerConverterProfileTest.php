<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use Tests\TestCase;

final class DesignViewerConverterProfileTest extends TestCase
{
    public function test_viewer_converter_version_targets_property_preserving_derivatives(): void
    {
        $this->assertSame(5, DesignViewerConverter::version());
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

        $this->assertIsString($script);
        $this->assertStringContainsString('VIEWER_GEOMETRY_PROFILE', $script);
        $this->assertStringContainsString('importer.includeUniqueAttributes = true', $script);
        $this->assertStringContainsString('importer.includeRelationNames = true', $script);
        $this->assertStringContainsString('COORDINATE_TO_ORIGIN: false', $script);
        $this->assertStringContainsString('OpenModelFromCallback', $script);
        $this->assertStringContainsString('getPropertySets', $script);
        $this->assertStringContainsString('getMaterialsProperties', $script);
        $this->assertStringContainsString('classificationMap', $script);
        $this->assertStringContainsString('coordination_matrix', $script);
    }
}
