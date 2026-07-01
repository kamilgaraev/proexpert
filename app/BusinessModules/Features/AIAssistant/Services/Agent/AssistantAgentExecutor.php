<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Agent;

use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportFileService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AssistantAgentExecutor
{
    public function __construct(
        private readonly AIToolRegistry $toolRegistry,
        private readonly AIPermissionChecker $permissionChecker,
        private readonly AssistantArtifactNormalizer $artifactNormalizer,
        private readonly ?AssistantReportFileService $reportFileService = null,
        private readonly ?AssistantToolEligibilityPolicy $toolEligibilityPolicy = null
    ) {}

    public function execute(
        string $toolName,
        array $arguments,
        ?User $user,
        Organization $organization,
        AssistantRequestUnderstanding|array|null $requestUnderstanding = null
    ): array {
        $startedAt = microtime(true);
        $tool = $this->toolRegistry->getTool($toolName);

        if ($tool === null) {
            return $this->errorResult($toolName, $arguments, [
                'status' => 'error',
                'message' => $this->assistantMessage('ai_assistant.tool_unavailable', 'Инструмент недоступен для выполнения.'),
            ]);
        }

        $understanding = $this->normalizeRequestUnderstanding($requestUnderstanding);
        if ($understanding instanceof AssistantRequestUnderstanding) {
            $eligibility = ($this->toolEligibilityPolicy ?? new AssistantToolEligibilityPolicy)
                ->canExecuteTool($toolName, $understanding);

            if (! $eligibility->allowed) {
                return $this->errorResult($toolName, $arguments, [
                    'status' => 'blocked_by_request_policy',
                    'message' => $this->assistantMessage(
                        'ai_assistant.tool_blocked_by_request_policy',
                        'Инструмент не выполнен, потому что текущий запрос ограничивает формат ответа или действия.'
                    ),
                    'reason' => $eligibility->reason,
                ]);
            }
        }

        if ($user === null || ! $this->permissionChecker->canExecuteTool($user, $toolName, $arguments)) {
            return $this->errorResult($toolName, $arguments, [
                'status' => 'error',
                'message' => $this->assistantMessage('ai_assistant.tool_access_denied_generic', 'Недостаточно прав для выполнения инструмента.'),
            ]);
        }

        try {
            $raw = $tool->execute($arguments, $user, $organization);
        } catch (Throwable $throwable) {
            $this->logReportGenerationFailed($toolName, $organization, $user, $startedAt, $throwable::class);

            return $this->errorResult($toolName, $arguments, [
                'status' => 'error',
                'message' => $this->assistantMessage('ai_assistant.tool_execute_failed', 'Не удалось выполнить инструмент.'),
            ]);
        }

        $status = $this->resolveStatus($raw);
        $artifacts = $status === 'error' ? [] : $this->artifactsFromToolResult(
            $toolName,
            $arguments,
            $raw,
            $organization,
            $user
        );

        if ($this->isReportTool($toolName)) {
            if ($status === 'error') {
                $this->logReportGenerationFailed($toolName, $organization, $user, $startedAt, 'tool_error_status');
            } elseif ($artifacts === []) {
                $this->logReportGenerationFailed($toolName, $organization, $user, $startedAt, 'missing_artifact_evidence');
            } else {
                $this->logReportGenerationCompleted($toolName, $organization, $user, $startedAt, $artifacts);
            }
        }

        return [
            'status' => $status,
            'raw' => $status === 'error' ? $this->sanitizeErrorRaw($raw) : $raw,
            'artifacts' => $artifacts,
            'evidence' => $this->buildEvidence($artifacts),
            'tool_name' => $toolName,
            'arguments' => $arguments,
        ];
    }

    private function errorResult(string $toolName, array $arguments, array $raw): array
    {
        return [
            'status' => 'error',
            'raw' => $raw,
            'artifacts' => [],
            'evidence' => [],
            'tool_name' => $toolName,
            'arguments' => $arguments,
        ];
    }

    private function resolveStatus(mixed $raw): string
    {
        if (is_array($raw) && isset($raw['status']) && is_string($raw['status']) && trim($raw['status']) !== '') {
            return $raw['status'];
        }

        return 'success';
    }

    private function artifactsFromToolResult(
        string $toolName,
        array $arguments,
        mixed $raw,
        Organization $organization,
        ?User $user
    ): array {
        if ($this->isReportTool($toolName) && $this->reportFileService instanceof AssistantReportFileService) {
            $artifacts = $this->reportFileService->artifactsFromToolResult($toolName, $raw, $organization, $user, $arguments);

            if ($artifacts !== []) {
                return $artifacts;
            }
        }

        return $this->artifactNormalizer->fromToolResult($toolName, $raw);
    }

    private function isReportTool(string $toolName): bool
    {
        return str_starts_with($toolName, 'generate_') && str_ends_with($toolName, '_report');
    }

    private function sanitizeErrorRaw(mixed $raw): array
    {
        $status = is_array($raw) && isset($raw['status']) && is_string($raw['status'])
            ? $raw['status']
            : 'error';

        return [
            'status' => $status,
            'message' => $this->assistantMessage('ai_assistant.tool_execute_failed', 'Не удалось выполнить инструмент.'),
        ];
    }

    private function normalizeRequestUnderstanding(AssistantRequestUnderstanding|array|null $requestUnderstanding): ?AssistantRequestUnderstanding
    {
        if ($requestUnderstanding instanceof AssistantRequestUnderstanding) {
            return $requestUnderstanding;
        }

        return is_array($requestUnderstanding)
            ? AssistantRequestUnderstanding::fromArray($requestUnderstanding)
            : null;
    }

    private function assistantMessage(string $key, string $fallback): string
    {
        try {
            return trans_message($key);
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function buildEvidence(array $artifacts): array
    {
        return array_values(array_map(
            static fn (array $artifact): array => [
                'type' => 'artifact',
                'url' => (string) ($artifact['url'] ?? ''),
                'filename' => (string) ($artifact['filename'] ?? ''),
                'storage_disk' => $artifact['storage_disk'] ?? null,
                'storage_path' => $artifact['storage_path'] ?? null,
                'source_tool' => $artifact['source_tool'] ?? null,
            ],
            $artifacts
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $artifacts
     */
    private function logReportGenerationCompleted(
        string $toolName,
        Organization $organization,
        ?User $user,
        float $startedAt,
        array $artifacts
    ): void {
        $firstArtifact = $artifacts[0] ?? [];

        $this->logInfo('report_generation_completed', [
            'organization_id' => $organization->id,
            'user_id' => $user?->id,
            'tool_name' => $toolName,
            'report_type' => $firstArtifact['report_type'] ?? null,
            'duration_ms' => $this->durationMs($startedAt),
            'artifact_count' => count($artifacts),
            'artifact_size' => $firstArtifact['size'] ?? null,
            'storage_disk' => $firstArtifact['storage_disk'] ?? null,
            'has_storage_path' => isset($firstArtifact['storage_path']),
        ]);
    }

    private function logReportGenerationFailed(
        string $toolName,
        Organization $organization,
        ?User $user,
        float $startedAt,
        string $failureClass
    ): void {
        if (! $this->isReportTool($toolName)) {
            return;
        }

        $this->logInfo('report_generation_failed', [
            'organization_id' => $organization->id,
            'user_id' => $user?->id,
            'tool_name' => $toolName,
            'duration_ms' => $this->durationMs($startedAt),
            'failure_class' => $failureClass,
        ]);
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logInfo(string $event, array $context): void
    {
        try {
            Log::info($event, array_filter(
                $context,
                static fn (mixed $value): bool => $value !== null && $value !== ''
            ));
        } catch (Throwable) {
        }
    }
}
