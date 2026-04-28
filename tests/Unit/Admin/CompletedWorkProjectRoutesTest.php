<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

class CompletedWorkProjectRoutesTest extends TestCase
{
    public function test_project_work_routes_use_completed_work_binding_parameter(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 3) . '/routes/api/v1/admin/project-based.php');

        $this->assertIsString($routes);
        $this->assertSame(5, substr_count($routes, '{completed_work}'));
        $this->assertSame(0, substr_count($routes, '{completedWork}'));
    }
}
