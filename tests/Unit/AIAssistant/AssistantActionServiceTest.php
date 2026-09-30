<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantActionService;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Models\User;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Facade;
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

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_execute_confirmation_error_uses_fallback_without_facade_root(): void
    {
        $service = $this->makeService();
        $user = Mockery::mock(User::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Для выполнения действия требуется подтверждение.');

        $service->execute([
            'type' => 'act',
            'label' => 'Изменить статус',
            'requires_confirmation' => true,
            'tool_name' => 'update_schedule_task_status',
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
