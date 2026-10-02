<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Agent;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantArtifactNormalizer;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\Models\Organization;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssistantAgentExecutorTest extends TestCase
{
    use \Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;
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

    public function test_payment_only_domain_search_blocks_cross_domain_arguments(): void
    {
        $tool = new class implements AIToolInterface
        {
            public bool $executed = false;

            public function getName(): string
            {
                return 'assistant_domain_search';
            }

            public function getDescription(): string
            {
                return 'Test domain search';
            }

            public function getParametersSchema(): array
            {
                return ['type' => 'object', 'properties' => [
                    'domain' => ['type' => 'string'],
                    'entity_type' => ['type' => 'string'],
                ], 'required' => ['domain', 'entity_type'], 'additionalProperties' => false];
            }
            public function execute(array $arguments, ?User $user, Organization $organization): array|string
            {
                $this->executed = true;

                return ['status' => 'success'];
            }
        };
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $permissionChecker = $this->createMock(AIPermissionChecker::class);
        $permissionChecker->method('canExecuteTool')->willReturn(true);
        $executor = new AssistantAgentExecutor($registry, $permissionChecker, new AssistantArtifactNormalizer);
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve('Что с платежами?')->toArray();

        $crossDomain = $executor->execute('assistant_domain_search', [
            'domain' => 'contracts',
            'entity_type' => 'contract',
        ], new User, new Organization, $understanding);

        $this->assertSame('blocked_by_request_policy', $crossDomain['raw']['status']);
        $this->assertFalse($tool->executed);

        $paymentSearch = $executor->execute('assistant_domain_search', [
            'domain' => 'finance',
            'entity_type' => 'payment_document',
        ], new User, new Organization, $understanding);

        $this->assertSame('success', $paymentSearch['status']);
        $this->assertTrue($tool->executed);
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
                return ['type' => 'object', 'properties' => ['period' => ['type' => 'string']], 'additionalProperties' => false];
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
