<?php

namespace App\Domain\Authorization\Exceptions;

use Exception;
use App\Models\User;
use App\Domain\Authorization\Models\AuthorizationContext;

/**
 * Исключение для случаев недостаточных прав
 */
class InsufficientPermissionsException extends Exception
{
    protected User $user;
    protected array $requiredPermissions;
    protected array $userPermissions;
    protected ?AuthorizationContext $context;

    public function __construct(
        User $user,
        array $requiredPermissions,
        array $userPermissions = [],
        ?AuthorizationContext $context = null,
        string $message = null,
        int $code = 403,
        Exception $previous = null
    ) {
        $this->user = $user;
        $this->requiredPermissions = $requiredPermissions;
        $this->userPermissions = $userPermissions;
        $this->context = $context;

        $message = $message ?: $this->generateMessage();
        
        parent::__construct($message, $code, $previous);
    }

    /**
     * Создать исключение для отсутствующих прав
     */
    public static function missingPermissions(
        User $user,
        array $requiredPermissions,
        array $userPermissions = [],
        ?AuthorizationContext $context = null
    ): self {
        $missing = array_diff($requiredPermissions, $userPermissions);
        $message = "Отсутствуют права: " . implode(', ', $missing);
        
        return new self($user, $requiredPermissions, $userPermissions, $context, $message);
    }

    /**
     * Создать исключение для управления пользователем
     */
    public static function cannotManageUser(User $manager, User $target, ?AuthorizationContext $context = null): self
    {
        return new self(
            $manager,
            ['users.manage'],
            [],
            $context,
            "Пользователь {$manager->id} не может управлять пользователем {$target->id}"
        );
    }

    /**
     * Создать исключение для создания ролей
     */
    public static function cannotCreateRoles(User $user, int $organizationId): self
    {
        return new self(
            $user,
            ['roles.create_custom'],
            [],
            null,
            "Пользователь не может создавать роли в организации $organizationId"
        );
    }

    /**
     * Создать исключение для управления модулями
     */
    public static function cannotManageModules(User $user, string $module, int $organizationId): self
    {
        return new self(
            $user,
            ['modules.manage', "$module.manage"],
            [],
            null,
            "Недостаточно прав для управления модулем '$module' в организации $organizationId"
        );
    }

    /**
     * Создать исключение для доступа к интерфейсу
     */
    public static function cannotAccessInterface(User $user, string $interface, ?AuthorizationContext $context = null): self
    {
        return new self(
            $user,
            ["interface.$interface"],
            [],
            $context,
            "Доступ к интерфейсу '$interface' запрещен"
        );
    }

    /**
     * Получить пользователя
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * Получить требуемые права
     */
    public function getRequiredPermissions(): array
    {
        return $this->requiredPermissions;
    }

    /**
     * Получить права пользователя
     */
    public function getUserPermissions(): array
    {
        return $this->userPermissions;
    }

    /**
     * Получить отсутствующие права
     */
    public function getMissingPermissions(): array
    {
        return array_diff($this->requiredPermissions, $this->userPermissions);
    }

    /**
     * Получить контекст
     */
    public function getContext(): ?AuthorizationContext
    {
        return $this->context;
    }

    /**
     * Получить данные для логирования
     */
    public function getLoggingData(): array
    {
        return [
            'exception' => static::class,
            'user_id' => $this->user->id,
            'required_permissions' => $this->requiredPermissions,
            'user_permissions' => $this->userPermissions,
            'missing_permissions' => $this->getMissingPermissions(),
            'context' => $this->context ? [
                'id' => $this->context->id,
                'type' => $this->context->type,
                'resource_id' => $this->context->resource_id,
            ] : null,
            'message' => $this->getMessage(),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Преобразовать в HTTP ответ
     */
    public function toHttpResponse(): array
    {
        return [
            'error' => 'Insufficient Permissions',
            'message' => $this->getMessage(),
            'required_permissions' => $this->requiredPermissions,
            'missing_permissions' => $this->getMissingPermissions(),
            'context' => $this->context ? [
                'id' => $this->context->id,
                'type' => $this->context->type,
                'resource_id' => $this->context->resource_id,
            ] : null,
            'code' => $this->getCode(),
        ];
    }

    /**
     * Сгенерировать сообщение об ошибке
     */
    protected function generateMessage(): string
    {
        $missing = $this->getMissingPermissions();
        
        if (empty($missing)) {
            return "Недостаточно прав для выполнения операции";
        }

        if (count($missing) === 1) {
            return "Отсутствует право: " . $missing[0];
        }

        return "Отсутствуют права: " . implode(', ', $missing);
    }
}
