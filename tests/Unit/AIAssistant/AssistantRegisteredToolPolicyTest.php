<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use ReflectionClass;

final class AssistantRegisteredToolPolicyTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_every_registered_tool_has_an_explicit_permission_policy(): void
    {
        $checker = app(AIPermissionChecker::class);
        $factory = $this->app->getBindings()[AIToolRegistry::class]['concrete'];
        $registry = $factory(new class
        {
            public function make(string $class): object
            {
                return (new ReflectionClass($class))->newInstanceWithoutConstructor();
            }
        });
        $names = array_keys($registry->getTools());

        self::assertNotEmpty($names);
        foreach ($names as $name) {
            self::assertTrue($checker->hasExplicitToolPolicy($name), $name);
        }
        self::assertFalse($checker->hasExplicitToolPolicy('unknown_tool'));
    }

    public function test_provider_defers_tool_construction_until_a_tool_is_requested(): void
    {
        $factory = $this->app->getBindings()[AIToolRegistry::class]['concrete'];
        $container = new class
        {
            public array $resolved = [];

            public function make(string $class): object
            {
                $this->resolved[] = $class;

                return (new ReflectionClass($class))->newInstanceWithoutConstructor();
            }
        };

        $registry = $factory($container);

        self::assertSame([], $container->resolved);
        self::assertSame('search_projects', $registry->getTool('search_projects')?->getName());
        self::assertCount(1, $container->resolved);
        self::assertSame($registry->getTool('search_projects'), $registry->getTool('search_projects'));
        self::assertCount(1, $container->resolved);
    }
}
