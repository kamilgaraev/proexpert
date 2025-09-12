<?php

namespace App\Domain\Authorization\ValueObjects;

use App\Models\User;
use App\Domain\Authorization\Models\AuthorizationContext;

/**
 * Value Object для запроса на авторизацию
 */
class AuthorizationRequest
{
    private User $user;
    private string $permission;
    private ?AuthorizationContext $context;
    private array $additionalContext;
    private \DateTimeImmutable $requestTime;

    public function __construct(
        User $user,
        string $permission,
        ?AuthorizationContext $context = null,
        array $additionalContext = []
    ) {
        $this->user = $user;
        $this->permission = $permission;
        $this->context = $context;
        $this->additionalContext = $additionalContext;
        $this->requestTime = new \DateTimeImmutable();
    }

    /**
     * Создать запрос из массива
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['user'],
            $data['permission'],
            $data['context'] ?? null,
            $data['additional_context'] ?? []
        );
    }

    /**
     * Создать запрос на проверку роли
     */
    public static function forRole(
        User $user, 
        string $roleSlug, 
        ?AuthorizationContext $context = null
    ): self {
        return new self(
            $user,
            "role:$roleSlug",
            $context,
            ['type' => 'role_check']
        );
    }

    /**
     * Создать запрос на доступ к интерфейсу
     */
    public static function forInterface(
        User $user, 
        string $interface, 
        ?AuthorizationContext $context = null
    ): self {
        return new self(
            $user,
            "interface:$interface",
            $context,
            ['type' => 'interface_access']
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
    public function getContext(): ?AuthorizationContext
    {
        return $this->context;
    }

    /**
     * Получить дополнительный контекст
     */
    public function getAdditionalContext(): array
    {
        return $this->additionalContext;
    }

    /**
     * Получить время запроса
     */
    public function getRequestTime(): \DateTimeImmutable
    {
        return $this->requestTime;
    }

    /**
     * Получить ID организации из контекста
     */
    public function getOrganizationId(): ?int
    {
        if ($this->context?->type === 'organization') {
            return $this->context->resource_id;
        }

        if ($this->context?->type === 'project') {
            $parentContext = $this->context->parentContext;
            if ($parentContext?->type === 'organization') {
                return $parentContext->resource_id;
            }
        }

        return $this->additionalContext['organization_id'] ?? null;
    }

    /**
     * Получить ID проекта из контекста
     */
    public function getProjectId(): ?int
    {
        if ($this->context?->type === 'project') {
            return $this->context->resource_id;
        }

        return $this->additionalContext['project_id'] ?? null;
    }

    /**
     * Проверить, является ли запрос проверкой роли
     */
    public function isRoleCheck(): bool
    {
        return str_starts_with($this->permission, 'role:') || 
               ($this->additionalContext['type'] ?? null) === 'role_check';
    }

    /**
     * Проверить, является ли запрос проверкой интерфейса
     */
    public function isInterfaceCheck(): bool
    {
        return str_starts_with($this->permission, 'interface:') ||
               ($this->additionalContext['type'] ?? null) === 'interface_access';
    }

    /**
     * Получить тип права (модуль.действие или системное)
     */
    public function getPermissionType(): string
    {
        if (str_contains($this->permission, '.')) {
            return 'module';
        }

        if ($this->isRoleCheck()) {
            return 'role';
        }

        if ($this->isInterfaceCheck()) {
            return 'interface';
        }

        return 'system';
    }

    /**
     * Получить модуль из права
     */
    public function getModule(): ?string
    {
        $parts = explode('.', $this->permission, 2);
        return count($parts) === 2 ? $parts[0] : null;
    }

    /**
     * Получить действие из права
     */
    public function getAction(): ?string
    {
        $parts = explode('.', $this->permission, 2);
        return count($parts) === 2 ? $parts[1] : $this->permission;
    }

    /**
     * Добавить контекстную информацию
     */
    public function withContext(array $context): self
    {
        return new self(
            $this->user,
            $this->permission,
            $this->context,
            array_merge($this->additionalContext, $context)
        );
    }

    /**
     * Создать новый запрос с другим правом
     */
    public function withPermission(string $permission): self
    {
        return new self(
            $this->user,
            $permission,
            $this->context,
            $this->additionalContext
        );
    }

    /**
     * Создать новый запрос с другим контекстом
     */
    public function withAuthContext(AuthorizationContext $context): self
    {
        return new self(
            $this->user,
            $this->permission,
            $context,
            $this->additionalContext
        );
    }

    /**
     * Получить уникальный хэш запроса для кеширования
     */
    public function getHash(): string
    {
        $data = [
            'user_id' => $this->user->id,
            'permission' => $this->permission,
            'context_id' => $this->context?->id,
            'additional_context' => $this->additionalContext,
        ];

        return hash('xxh3', serialize($data));
    }

    /**
     * Преобразовать в массив
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->user->id,
            'permission' => $this->permission,
            'context' => $this->context ? [
                'id' => $this->context->id,
                'type' => $this->context->type,
                'resource_id' => $this->context->resource_id,
            ] : null,
            'additional_context' => $this->additionalContext,
            'request_time' => $this->requestTime->format('Y-m-d H:i:s'),
            'organization_id' => $this->getOrganizationId(),
            'project_id' => $this->getProjectId(),
            'permission_type' => $this->getPermissionType(),
            'module' => $this->getModule(),
            'action' => $this->getAction(),
        ];
    }

    /**
     * Преобразовать в JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Строковое представление
     */
    public function __toString(): string
    {
        $contextStr = $this->context ? " in {$this->context->type}({$this->context->resource_id})" : '';
        return "User({$this->user->id}) requesting '{$this->permission}'{$contextStr}";
    }
}
