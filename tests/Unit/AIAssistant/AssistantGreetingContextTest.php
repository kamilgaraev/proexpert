<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Projects\GetProjectStatusAction;
use App\BusinessModules\Features\AIAssistant\Services\ContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\IntentRecognizer;
use App\Services\Logging\LoggingService;
use PHPUnit\Framework\TestCase;

final class AssistantGreetingContextTest extends TestCase
{
    public function test_greeting_does_not_read_project_status(): void
    {
        $builder = new class($this->createMock(IntentRecognizer::class), $this->createMock(LoggingService::class)) extends ContextBuilder {
            public function actionClass(string $intent): ?string
            {
                return $this->getActionClass($intent);
            }
        };

        $this->assertNull($builder->actionClass('greeting'));
        $this->assertSame(GetProjectStatusAction::class, $builder->actionClass('project_status'));
    }
}
