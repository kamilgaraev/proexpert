<?php

namespace App\Domain\Authorization\Exceptions;

use Exception;
use App\Models\User;

/**
 * Исключение для неавторизованного доступа
 */
class UnauthorizedException extends Exception
{
    protected User $user;
    protected string $permission;
    protected ?array $context;

    public function __construct(
        User $user,
        string $permission,
        ?array $context = null,
        string $message = null,
        int $code = 403,
        Exception $previous = null
    ) {
        $this->user = $user;
        $this->permission = $permission;
        $this->context = $context;

        $message = $message ?: "Пользователь {$user->id} не имеет права '{$permission}'";
        
        parent::__construct($message, $code, $previous);
    }

    /**
     * Создать исключение для отсутствующего права
     */
    public static function missingPermission(User $user, string $permission, ?array $context = null): self
    {
        return new self(
            $user,
            $permission,
            $context,
            "Недостаточно прав для выполнения операции '{$permission}'"
        );
    }

    /**
     * Создать исключение для истекшей роли
     */
    public static function expiredRole(User $user, string $roleSlug, ?array $context = null): self
    {
        return new self(
            $user,
            "role:$roleSlug",
            $context,
            "Срок действия роли '{$roleSlug}' истек"
        );
    }

    /**
     * Создать исключение для заблокированного пользователя
     */
    public static function userBlocked(User $user, string $reason = null): self
    {
        $message = "Пользователь заблокирован" . ($reason ? ": $reason" : "");
        
        return new self($user, '', null, $message, 403);
    }

    /**
     * Создать исключение для неактивированного модуля
     */
    public static function moduleNotActive(User $user, string $module, int $organizationId): self
    {
        return new self(
            $user,
            "$module.*",
            ['organization_id' => $organizationId],
            "Модуль '$module' не активирован для организации"
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
     * Получить право
     */
    public function getPermission(): string
    {
        return $this->permission;
    }

    /**
     * Получить контекст
     */
    public function getContext(): ?array
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
            'permission' => $this->permission,
            'context' => $this->context,
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
            'error' => 'Unauthorized',
            'message' => $this->getMessage(),
            'permission' => $this->permission,
            'context' => $this->context,
            'code' => $this->getCode(),
        ];
    }
}
