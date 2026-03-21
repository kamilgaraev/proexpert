<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

class GeocodingRoutesTest extends TestCase
{
    public function test_admin_api_registers_geocoding_routes_before_projects_routes(): void
    {
        $apiRoutes = file_get_contents(dirname(__DIR__, 3) . '/routes/api.php');

        $this->assertIsString($apiRoutes);
        $this->assertNotFalse(strpos($apiRoutes, "require __DIR__ . '/api/v1/admin/geocoding.php';"));
        $this->assertNotFalse(strpos($apiRoutes, "require __DIR__ . '/api/v1/admin/projects.php';"));
        $this->assertLessThan(
            strpos($apiRoutes, "require __DIR__ . '/api/v1/admin/projects.php';"),
            strpos($apiRoutes, "require __DIR__ . '/api/v1/admin/geocoding.php';")
        );
    }

    public function test_project_detail_routes_are_limited_to_numeric_identifiers(): void
    {
        $projectRoutes = file_get_contents(dirname(__DIR__, 3) . '/routes/api/v1/admin/projects.php');

        $this->assertIsString($projectRoutes);
        $this->assertSame(4, substr_count($projectRoutes, "->whereNumber('project')"));
    }
}
