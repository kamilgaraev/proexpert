<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantActionService;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Models\User;
use App\Services\Logging\LoggingService;
use Illuminate\Container\Container;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AssistantActionServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('translator', new class {
            public function get(string $key, array $replace = [], ?string $locale = null): string
            {
                return "translated:{$key}";
            }

            public function choice(string $key, int|float $number, array $replace = [], ?string $locale = null): string
            {
                return "translated:{$key}";
            }
        });

        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_preview_returns_navigation_summary_for_safe_action(): void
    {
        $service = $this->makeService();
        $user = Mockery::mock(User::class);

        $result = $service->preview([
            'type' => 'navigate',
            'label' => 'Открыть проекты',
            'allowed' => true,
            'target' => [
                'route' => '/projects',
            ],
        ], 15, $user);

        $this->assertFalse($result['requires_confirmation']);
        $this->assertTrue($result['executable']);
        $this->assertSame('/projects', $result['navigation_target']['route']);
    }

    public function test_preview_marks_tool_action_as_unavailable_without_permissions(): void
    {
        $tool = Mockery::mock(AIToolInterface::class);
        $tool->shouldReceive('getDescription')->once()->andReturn('Создание задачи');

        $toolRegistry = Mockery::mock(AIToolRegistry::class);
        $toolRegistry->shouldReceive('getTool')->once()->with('create_schedule_task')->andReturn($tool);

        $permissionChecker = Mockery::mock(AIPermissionChecker::class);
        $permissionChecker
            ->shouldReceive('canExecuteTool')
            ->once()
            ->with(Mockery::type(User::class), 'create_schedule_task', ['project_id' => 77])
            ->andReturn(false);

        $conversationManager = Mockery::mock(ConversationManager::class);
        $logging = Mockery::mock(LoggingService::class);
        $service = new AssistantActionService($toolRegistry, $permissionChecker, $conversationManager, $logging);
        $user = Mockery::mock(User::class);

        $result = $service->preview([
            'type' => 'act',
            'label' => 'Создать задачу',
            'allowed' => true,
            'requires_confirmation' => true,
            'action_class' => 'confirm',
            'tool_name' => 'create_schedule_task',
            'arguments' => ['project_id' => 77],
        ], 15, $user);

        $this->assertTrue($result['requires_confirmation']);
        $this->assertFalse($result['executable']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertSame('project_id', strtolower(str_replace(' ', '_', $result['summary_items'][0]['label'])));
    }

    public function test_execute_requires_explicit_confirmation_for_mutation_action(): void
    {
        $service = $this->makeService();
        $user = Mockery::mock(User::class);

        $this->expectException(RuntimeException::class);

        $service->execute([
            'type' => 'act',
            'label' => 'Изменить статус',
            'requires_confirmation' => true,
            'tool_name' => 'update_task_status',
            'arguments' => ['task_id' => 10],
            'confirmed' => false,
        ], 15, $user);
    }

    private function makeService(): AssistantActionService
    {
        return new AssistantActionService(
            Mockery::mock(AIToolRegistry::class),
            Mockery::mock(AIPermissionChecker::class),
            Mockery::mock(ConversationManager::class),
            Mockery::mock(LoggingService::class)
        );
    }
}
