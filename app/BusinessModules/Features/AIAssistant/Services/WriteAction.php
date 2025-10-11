<?php

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\User;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\DB;

/**
 * Базовый класс для действий ИИ, изменяющих данные
 */
abstract class WriteAction
{
    protected LoggingService $logging;
    protected string $entity;
    protected string $operation;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }

    /**
     * Выполнить действие
     */
    abstract public function execute(int $organizationId, array $params, User $user): ActionResult;

    /**
     * Проверить разрешения пользователя
     */
    protected function validatePermissions(User $user, string $action, ?int $entityId = null): bool
    {
        // Базовая проверка - пользователь должен быть админом организации
        return $user->isOrganizationAdmin($user->current_organization_id);
    }

    /**
     * Залогировать действие
     */
    protected function logAction(array $data): void
    {
        $this->logging->business('ai.action.executed', [
            'entity' => $this->entity,
            'operation' => $this->operation,
            'user_id' => $data['user_id'] ?? null,
            'organization_id' => $data['organization_id'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'success' => $data['success'] ?? false,
            'error' => $data['error'] ?? null,
        ]);
    }

    /**
     * Выполнить действие в транзакции
     */
    protected function executeInTransaction(callable $callback): ActionResult
    {
        try {
            DB::beginTransaction();

            $result = $callback();

            if ($result->isSuccess()) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            return $result;
        } catch (\Exception $e) {
            DB::rollBack();

            $this->logging->technical('ai.action.error', [
                'entity' => $this->entity,
                'operation' => $this->operation,
                'error' => $e->getMessage(),
            ], 'error');

            return ActionResult::error("Ошибка выполнения действия: " . $e->getMessage());
        }
    }

    /**
     * Создать успешный результат
     */
    protected function success(mixed $data = null, array $metadata = []): ActionResult
    {
        $this->logAction([
            'success' => true,
            'data' => $data,
            'metadata' => $metadata
        ]);

        return ActionResult::success($data, $metadata);
    }

    /**
     * Создать результат с ошибкой
     */
    protected function error(string $message, array $metadata = []): ActionResult
    {
        $this->logAction([
            'success' => false,
            'error' => $message,
            'metadata' => $metadata
        ]);

        return ActionResult::error($message, $metadata);
    }
}
