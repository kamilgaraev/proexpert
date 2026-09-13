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
