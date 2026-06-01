<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use Tests\TestCase;

final class DesignViewerConverterProfileTest extends TestCase
{
    public function test_viewer_converter_version_targets_geometry_first_derivatives(): void
    {
        $this->assertSame(4, DesignViewerConverter::version());
    }

    public function test_node_converter_uses_geometry_first_profile_for_stage_one_viewer(): void
    {
        $script = file_get_contents(base_path('resources/js/design-management/convert-ifc-to-frag.mjs'));

        $this->assertIsString($script);
        $this->assertStringContainsString('VIEWER_GEOMETRY_PROFILE', $script);
        $this->assertStringContainsString('importer.classes.abstract.clear()', $script);
        $this->assertStringContainsString('importer.classes.elements.clear()', $script);
        $this->assertStringContainsString('importer.relations.clear()', $script);
    }
}
