<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Agent;

use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\Models\Organization;
use App\Models\User;
use Throwable;

final class AssistantAgentExecutor
{
    public function __construct(
        private readonly AIToolRegistry $toolRegistry,
        private readonly AIPermissionChecker $permissionChecker,
        private readonly AssistantArtifactNormalizer $artifactNormalizer
    ) {}

    public function execute(string $toolName, array $arguments, ?User $user, Organization $organization): array
    {
        $tool = $this->toolRegistry->getTool($toolName);

        if ($tool === null) {
            return $this->errorResult($toolName, $arguments, [
                'status' => 'error',
                'message' => 'Инструмент недоступен для выполнения.',
            ]);
        }

        if ($user === null || ! $this->permissionChecker->canExecuteTool($user, $toolName, $arguments)) {
            return $this->errorResult($toolName, $arguments, [
                'status' => 'error',
                'message' => 'Недостаточно прав для выполнения инструмента.',
            ]);
        }

        try {
            $raw = $tool->execute($arguments, $user, $organization);
        } catch (Throwable) {
            return $this->errorResult($toolName, $arguments, [
                'status' => 'error',
                'message' => 'Не удалось выполнить инструмент.',
            ]);
        }

        $status = $this->resolveStatus($raw);
        $artifacts = $status === 'error'
            ? []
            : $this->artifactNormalizer->fromToolResult($toolName, $raw);

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

    private function sanitizeErrorRaw(mixed $raw): array
    {
        $status = is_array($raw) && isset($raw['status']) && is_string($raw['status'])
            ? $raw['status']
            : 'error';

        return [
            'status' => $status,
            'message' => 'Не удалось выполнить инструмент.',
        ];
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
}
