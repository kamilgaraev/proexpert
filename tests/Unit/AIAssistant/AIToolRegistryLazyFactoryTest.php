<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\Models\Organization;
use App\Models\User;
use PHPUnit\Framework\TestCase;

final class AIToolRegistryLazyFactoryTest extends TestCase
{
    public function test_factory_is_resolved_only_on_request_and_cached(): void
    {
        $calls = ['selected' => 0, 'unused' => 0];
        $registry = new AIToolRegistry;
        $registry->registerFactory('selected_tool', function () use (&$calls): AIToolInterface {
            $calls['selected']++;

            return new RegistryFixtureTool('selected_tool');
        });
        $registry->registerFactory('unused_tool', function () use (&$calls): AIToolInterface {
            $calls['unused']++;

            return new RegistryFixtureTool('unused_tool');
        });

        self::assertSame(['selected' => 0, 'unused' => 0], $calls);
        $tool = $registry->getTool('selected_tool');

        self::assertInstanceOf(AIToolInterface::class, $tool);
        self::assertSame($tool, $registry->getTool('selected_tool'));
        self::assertSame(['selected' => 1, 'unused' => 0], $calls);
    }

    public function test_filtered_definitions_resolve_only_selected_factories(): void
    {
        $calls = ['selected' => 0, 'unused' => 0];
        $registry = new AIToolRegistry;
        $registry->registerFactory('selected_tool', function () use (&$calls): AIToolInterface {
            $calls['selected']++;

            return new RegistryFixtureTool('selected_tool');
        });
        $registry->registerFactory('unused_tool', function () use (&$calls): AIToolInterface {
            $calls['unused']++;

            return new RegistryFixtureTool('unused_tool');
        });

        $definitions = $registry->getToolsDefinitions(['selected_tool']);

        self::assertSame(['selected' => 1, 'unused' => 0], $calls);
        self::assertSame(['selected_tool'], array_column(array_column($definitions, 'function'), 'name'));
    }

    public function test_deferred_canonical_factory_is_reachable_through_legacy_alias(): void
    {
        $calls = 0;
        $registry = new AIToolRegistry;
        $registry->registerFactory('update_schedule_task_status', function () use (&$calls): AIToolInterface {
            $calls++;

            return new RegistryFixtureTool('update_schedule_task_status');
        });

        $tool = $registry->getTool('update_task_status');

        self::assertInstanceOf(AIToolInterface::class, $tool);
        self::assertSame('update_schedule_task_status', $tool->getName());
        self::assertSame(1, $calls);
    }
}

final readonly class RegistryFixtureTool implements AIToolInterface
{
    public function __construct(private string $name) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'Fixture tool';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        return [];
    }
}
