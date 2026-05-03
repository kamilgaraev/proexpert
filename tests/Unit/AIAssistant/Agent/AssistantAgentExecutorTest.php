<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Agent;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantArtifactNormalizer;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\Models\Organization;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssistantAgentExecutorTest extends TestCase
{
    public function test_success_returns_artifact_url_when_tool_returns_storage_evidence(): void
    {
        $tool = $this->makeTool('generate_project_timelines_report', [
            'status' => 'success',
            'pdf_url' => 'https://storage.example.test/org-15/reports/timeline.pdf',
            'filename' => 'timeline.pdf',
            'storage_disk' => 's3',
            'storage_path' => 'org-15/reports/timeline.pdf',
        ]);

        $executor = $this->makeExecutor($tool, true);

        $result = $executor->execute(
            'generate_project_timelines_report',
            ['period' => '2026-05'],
            new User,
            new Organization
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('generate_project_timelines_report', $result['tool_name']);
        $this->assertSame(['period' => '2026-05'], $result['arguments']);
        $this->assertSame('https://storage.example.test/org-15/reports/timeline.pdf', $result['artifacts'][0]['url']);
        $this->assertSame('https://storage.example.test/org-15/reports/timeline.pdf', $result['evidence'][0]['url']);
    }

    public function test_denies_permission_safely(): void
    {
        $executor = $this->makeExecutor($this->makeTool('generate_project_timelines_report', []), false);

        $result = $executor->execute(
            'generate_project_timelines_report',
            ['period' => '2026-05'],
            new User,
            new Organization
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame([], $result['artifacts']);
        $this->assertSame([], $result['evidence']);
        $this->assertSame('generate_project_timelines_report', $result['tool_name']);
    }

    public function test_missing_tool_fails_safely(): void
    {
        $executor = $this->makeExecutor(null, true);

        $result = $executor->execute('missing_tool', [], new User, new Organization);

        $this->assertSame('error', $result['status']);
        $this->assertSame([], $result['artifacts']);
        $this->assertSame([], $result['evidence']);
        $this->assertSame('missing_tool', $result['tool_name']);
    }

    public function test_tool_exception_fails_safely(): void
    {
        $executor = $this->makeExecutor($this->makeThrowingTool('generate_project_timelines_report'), true);

        $result = $executor->execute(
            'generate_project_timelines_report',
            [],
            new User,
            new Organization
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame([], $result['artifacts']);
        $this->assertSame([], $result['evidence']);
    }

    public function test_tool_error_status_is_preserved(): void
    {
        $executor = $this->makeExecutor($this->makeTool('generate_project_timelines_report', [
            'status' => 'error',
            'message' => 'SQLSTATE[08006] connection failed.',
            'pdf_url' => 'https://storage.example.test/org-15/reports/timeline.pdf',
            'storage_disk' => 's3',
            'storage_path' => 'org-15/reports/timeline.pdf',
        ]), true);

        $result = $executor->execute(
            'generate_project_timelines_report',
            [],
            new User,
            new Organization
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('Не удалось выполнить инструмент.', $result['raw']['message']);
        $this->assertSame([], $result['artifacts']);
        $this->assertSame([], $result['evidence']);
    }

    public function test_null_user_fails_safely(): void
    {
        $executor = $this->makeExecutor($this->makeTool('generate_project_timelines_report', []), true);

        $result = $executor->execute('generate_project_timelines_report', [], null, new Organization);

        $this->assertSame('error', $result['status']);
        $this->assertSame('Недостаточно прав для выполнения инструмента.', $result['raw']['message']);
    }

    private function makeExecutor(?AIToolInterface $tool, bool $canExecute): AssistantAgentExecutor
    {
        $registry = new AIToolRegistry;
        if ($tool instanceof AIToolInterface) {
            $registry->registerTool($tool);
        }

        $permissionChecker = $this->createMock(AIPermissionChecker::class);
        $permissionChecker
            ->method('canExecuteTool')
            ->willReturn($canExecute);

        return new AssistantAgentExecutor(
            $registry,
            $permissionChecker,
            new AssistantArtifactNormalizer
        );
    }

    private function makeTool(string $name, array|string $result): AIToolInterface
    {
        return new class($name, $result) implements AIToolInterface
        {
            public function __construct(
                private readonly string $name,
                private readonly array|string $result
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return 'Test tool';
            }

            public function getParametersSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $arguments, ?User $user, Organization $organization): array|string
            {
                return $this->result;
            }
        };
    }

    private function makeThrowingTool(string $name): AIToolInterface
    {
        return new class($name) implements AIToolInterface
        {
            public function __construct(
                private readonly string $name
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return 'Test tool';
            }

            public function getParametersSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $arguments, ?User $user, Organization $organization): array|string
            {
                throw new RuntimeException('Tool failed.');
            }
        };
    }
}
