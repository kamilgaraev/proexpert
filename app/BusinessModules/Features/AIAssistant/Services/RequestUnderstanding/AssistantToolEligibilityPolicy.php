<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding;

use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;
use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantToolEligibility;

final class AssistantToolEligibilityPolicy
{
    public function canExposeTool(string $toolName, AssistantRequestUnderstanding $understanding): AssistantToolEligibility
    {
        return $this->toolEligibility($toolName, $understanding, false);
    }

    public function canExecuteTool(string $toolName, AssistantRequestUnderstanding $understanding): AssistantToolEligibility
    {
        return $this->toolEligibility($toolName, $understanding, true);
    }

    public function canExposeAction(array $action, AssistantRequestUnderstanding $understanding): AssistantToolEligibility
    {
        $actionType = (string) ($action['type'] ?? '');

        if ($actionType === 'navigate') {
            if ($understanding->blocksNavigation()) {
                return AssistantToolEligibility::block(
                    'navigation',
                    'Навигация отключена текущей политикой запроса.'
                );
            }

            return AssistantToolEligibility::allow('navigation');
        }

        if ($actionType === 'act' || (bool) ($action['requires_confirmation'] ?? false)) {
            if ($understanding->actionPolicy === 'requires_confirmation' && ! $understanding->hasConstraint('no_actions')) {
                return AssistantToolEligibility::allow('mutation', true);
            }

            if ($understanding->blocksActions()) {
                return AssistantToolEligibility::block(
                    'mutation',
                    'Действия отключены текущей политикой запроса.'
                );
            }
        }

        return AssistantToolEligibility::allow($actionType === '' ? 'unknown' : $actionType);
    }

    private function toolEligibility(
        string $toolName,
        AssistantRequestUnderstanding $understanding,
        bool $execute
    ): AssistantToolEligibility {
        $category = $this->toolCategory($toolName);

        if ($category === 'report' && $understanding->blocksFileGeneration()) {
            return AssistantToolEligibility::block(
                $category,
                'Инструмент недоступен: формат ответа запрещает формирование файлов или отчетов.'
            );
        }

        if ($category === 'file' && $understanding->blocksFileGeneration()) {
            return AssistantToolEligibility::block(
                $category,
                'Инструмент недоступен: формат ответа запрещает формирование файлов.'
            );
        }

        if ($category === 'mutation') {
            if ($understanding->actionPolicy === 'requires_confirmation' && ! $understanding->hasConstraint('no_actions')) {
                return $execute
                    ? AssistantToolEligibility::block(
                        $category,
                        'Изменяющее действие требует отдельного подтверждения пользователя.',
                        true
                    )
                    : AssistantToolEligibility::allow($category, true);
            }

            if ($understanding->blocksActions()) {
                return AssistantToolEligibility::block(
                    $category,
                    'Инструмент недоступен: пользователь запретил действия или изменения.'
                );
            }
        }

        return AssistantToolEligibility::allow($category);
    }

    private function toolCategory(string $toolName): string
    {
        if ($this->isReportTool($toolName)) {
            return 'report';
        }

        if (str_contains($toolName, 'pdf') || str_contains($toolName, 'file')) {
            return 'file';
        }

        if ($this->isMutationTool($toolName)) {
            return 'mutation';
        }

        if (str_starts_with($toolName, 'get_') || str_starts_with($toolName, 'search_')) {
            return 'read';
        }

        return 'unknown';
    }

    private function isReportTool(string $toolName): bool
    {
        return str_starts_with($toolName, 'generate_') && str_ends_with($toolName, '_report');
    }

    private function isMutationTool(string $toolName): bool
    {
        foreach (['create_', 'update_', 'delete_', 'approve_', 'send_'] as $prefix) {
            if (str_starts_with($toolName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
