<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Http\Controllers\Api\V1\Admin\CompletedWorkController;
use App\Models\CompletedWork;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CompletedWorkProjectRoutesTest extends TestCase
{
    public function test_project_work_routes_use_completed_work_binding_parameter(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 3) . '/routes/api/v1/admin/project-based.php');

        $this->assertIsString($routes);
        $this->assertSame(5, substr_count($routes, '{completed_work}'));
        $this->assertSame(0, substr_count($routes, '{completedWork}'));
    }

    public function test_controller_methods_match_completed_work_route_parameter_name(): void
    {
        $methods = [
            'show',
            'update',
            'destroy',
            'syncMaterials',
            'attachScheduleTask',
            'createScheduleTaskFromWork',
        ];

        foreach ($methods as $method) {
            $reflection = new ReflectionMethod(CompletedWorkController::class, $method);
            $completedWorkParameters = array_filter(
                $reflection->getParameters(),
                static fn ($parameter): bool => $parameter->getType()?->getName() === CompletedWork::class
            );

            $this->assertCount(1, $completedWorkParameters, $method);
            $parameter = reset($completedWorkParameters);

            $this->assertSame('completed_work', $parameter->getName(), $method);
        }
    }
}
